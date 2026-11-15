<?php
/** Free GCash flow: the commuter pays the admin's GCash QR and uploads the receipt; an admin verifies it before the ticket is issued. */

function mbus_setting($conn, string $key): string {
    $q = mbus_db_prepare($conn, "SELECT value FROM app_settings WHERE key = ?", [$key]);
    $r = $q === false ? false : mbus_db_fetch_assoc($q);
    return $r ? (string)$r['value'] : '';
}
function mbus_set_setting($conn, string $key, string $value): void {
    $conn->pdo->prepare("INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([$key, $value]);
}

/** Server-side checkout payload (ids, per-seat price lines, total) shared by the PayMongo and receipt flows. */
function mbus_checkout_payload(array $reservations, array $defaultTypes): array {
    $types = []; $covered = [];
    foreach ($reservations as $i => $r) {
        $rid = $r['reservation_id'];
        $types[$rid] = $defaultTypes[$rid];
        $covered[$rid] = ($types[$rid] !== 'Regular' && $i === 0);
        if ($types[$rid] !== 'Regular' && !$covered[$rid] && empty($r['id_image_b64'])) {
            return ['error' => "Seat {$r['seat_number']} is a {$types[$rid]} ticket without an ID photo. Please reserve again."];
        }
    }
    $q = mbus_quote_lines($reservations, $types);
    return [
        'error' => '', 'total' => $q['total'], 'quote' => $q,
        'idsJson' => json_encode(array_map(fn($r) => (int)$r['reservation_id'], $reservations)),
        'linesJson' => json_encode(array_map(fn($l) => $l + ['covered' => !empty($covered[$l['reservation_id']])], $q['lines'])),
    ];
}

/** Validate an uploaded receipt (JPG/PNG by file signature, up to 3 MB). Returns [b64, mime] or sets $error. */
function mbus_receipt_upload(string $field, &$error): ?array {
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Attach a screenshot of your GCash receipt.'; return null; }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 3 * 1024 * 1024) { $error = 'The receipt must be an image up to 3 MB.'; return null; }
    $d = file_get_contents($f['tmp_name']);
    $mime = str_starts_with($d, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($d, "\x89PNG\r\n\x1a\n") ? 'image/png' : '');
    if ($mime === '') { $error = 'The receipt must be a JPG or PNG image.'; return null; }
    return ['b64' => base64_encode($d), 'mime' => $mime];
}

/** Pull amount + reference out of a GCash "You have received ..." message. Returns null for anything else (e.g. money you sent). */
function mbus_parse_gcash_sms(string $text): ?array {
    if (!preg_match('/received/i', $text)) return null;
    if (!preg_match('/(?:PHP|PhP|₱)\s*([\d,]+(?:\.\d{1,2})?)/u', $text, $a)) return null;
    if (!preg_match('/Ref(?:erence)?\.?\s*(?:No\.?|Number|#)?\s*:?\s*(\d{10,20})/i', $text, $r) && !preg_match('/\b(\d{13})\b/', $text, $r)) return null;
    $sender = preg_match('/from\s+(.+?)(?:\s+with|\.\s|\s+Ref|\s+on\s)/i', $text, $f) ? trim($f[1]) : '';
    return ['amount' => (float)str_replace(',', '', $a[1]), 'reference' => $r[1], 'sender' => substr($sender, 0, 120)];
}

/** Close open payments older than 30 minutes so their centavo amounts can be reused. */
function mbus_sweep_open_payments($conn): void {
    $conn->pdo->exec("UPDATE paymongo_checkout SET status = 'Expired' WHERE status = 'Submitted' AND session_id LIKE 'manual\\_%' AND submitted_at < NOW() - INTERVAL '30 minutes'");
}

/**
 * Open a GCash payment for the seats. The payable amount is the fare minus a unique number of centavos (1-99),
 * so the received-money SMS identifies this booking with no reference number to type. Returns the session id.
 */
function mbus_open_gcash_payment($conn, int $userId, int $scheduleId, array $payload): ?string {
    $pdo = $conn->pdo;
    mbus_sweep_open_payments($conn);
    $start = random_int(1, 99);
    for ($i = 0; $i < 99; $i++) {
        $cents = (($start + $i - 1) % 99) + 1;
        $amount = round((float)$payload['total'] - $cents / 100, 2);
        $sid = 'manual_' . bin2hex(random_bytes(8));
        try {
            $pdo->prepare("INSERT INTO paymongo_checkout (session_id, user_id, schedule_id, reservation_ids, lines, amount, status, submitted_at)
                VALUES (?,?,?,?,?,?,'Submitted',NOW())")->execute([$sid, $userId, $scheduleId, $payload['idsJson'], $payload['linesJson'], $amount]);
            return $sid;
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23505') throw $e; // amount taken by another open payment: try the next one
        }
    }
    return null;
}

/** Match an open payment to a received GCash SMS with the same amount and, if found, issue the ticket. */
function mbus_try_autoapprove($conn, string $sessionId): array {
    $pdo = $conn->pdo;
    $st = $pdo->prepare("SELECT amount, submitted_at FROM paymongo_checkout WHERE session_id = ? AND status = 'Submitted'");
    $st->execute([$sessionId]);
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if (!$co) return ['matched' => false];
    // Claim one unused incoming payment atomically so it can only ever pay for one booking.
    $claim = $pdo->prepare("UPDATE incoming_payments SET used_by = ? WHERE id = (
            SELECT id FROM incoming_payments WHERE used_by IS NULL AND ABS(amount - ?) < 0.005 AND received_at >= ?::timestamp - INTERVAL '2 minutes'
            ORDER BY received_at LIMIT 1 FOR UPDATE SKIP LOCKED) RETURNING id, reference_number");
    $claim->execute([$sessionId, $co['amount'], $co['submitted_at']]);
    $in = $claim->fetch(PDO::FETCH_ASSOC);
    if (!$in) return ['matched' => false];
    $pdo->prepare("UPDATE paymongo_checkout SET reference_number = ? WHERE session_id = ?")->execute([$in['reference_number'], $sessionId]);
    $res = mbus_finalize_payment($conn, $sessionId, (string)$in['reference_number']);
    if (empty($res['ok'])) {
        $pdo->prepare("UPDATE incoming_payments SET used_by = NULL WHERE id = ?")->execute([$in['id']]);
        return ['matched' => false, 'message' => $res['message'] ?? ''];
    }
    return ['matched' => true, 'ticket' => $res['ticket'] ?? ''];
}

/** Shared secret the phone's SMS forwarder must send. Created on first use. */
function mbus_sms_token($conn): string {
    $t = mbus_setting($conn, 'sms_token');
    if ($t === '') { $t = bin2hex(random_bytes(16)); mbus_set_setting($conn, 'sms_token', $t); }
    return $t;
}
