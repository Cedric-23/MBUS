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
