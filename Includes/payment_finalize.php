<?php
/** Marks a paid PayMongo checkout's reservations as Paid and records the payment. Safe to call twice (webhook + return page). */
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/notify.php';

function mbus_finalize_payment($conn, string $sessionId, string $paymentRef): array
{
    $pdo = $conn->pdo;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM paymongo_checkout WHERE session_id = ? FOR UPDATE');
        $st->execute([$sessionId]);
        $co = $st->fetch(PDO::FETCH_ASSOC);
        if (!$co) { $pdo->rollBack(); return ['ok' => false, 'message' => 'Unknown checkout session.']; }
        $result = ['ok' => true, 'schedule_id' => (int)$co['schedule_id'], 'user_id' => (int)$co['user_id']];
        if ($co['status'] === 'Paid') { $pdo->commit(); return $result + ['already' => true, 'ticket' => $co['ticket_code']]; }

        $lines = json_decode((string)$co['lines'], true) ?: [];
        $first = null;
        foreach ($lines as $ln) {
            $rid = (int)$ln['reservation_id'];
            $first = $first ?? $rid;
            $discountStatus = $ln['type'] === 'Regular' ? 'None' : (!empty($ln['covered']) ? 'Approved' : 'Submitted');
            // Pending is the normal case; Cancelled covers a payment that finished after the 10-minute hold lapsed
            // (the seat comes back only if nobody else took it; the unique seat index decides).
            $up = $pdo->prepare("UPDATE reservation SET status = 'Paid', passenger_type = ?, discount_status = ?, fare_amount = ?, discount_amount = ?
                WHERE reservation_id = ? AND user_id = ? AND status IN ('Pending', 'Cancelled')");
            $up->execute([$ln['type'], $discountStatus, $ln['base'], $ln['discount'], $rid, (int)$co['user_id']]);
        }
        if ($first === null) throw new RuntimeException('Checkout has no seats.');

        $ticket = '';
        for ($i = 0; $i < 8; $i++) {
            $cand = 'MB-' . random_int(1000, 9999);
            $dup = $pdo->prepare('SELECT 1 FROM payment WHERE ticket_code = ?'); $dup->execute([$cand]);
            if (!$dup->fetchColumn()) { $ticket = $cand; break; }
        }
        if ($ticket === '') $ticket = 'MB-' . random_int(100000, 999999);
        $pdo->prepare("INSERT INTO payment (reservation_id, amount, payment_method, payment_reference, payment_status, ticket_code, payment_date)
            VALUES (?, ?, 'GCash', ?, 'Paid', ?, NOW())")->execute([$first, $co['amount'], $paymentRef, $ticket]);
        $pdo->prepare("UPDATE paymongo_checkout SET status = 'Paid', payment_ref = ?, ticket_code = ?, paid_at = NOW() WHERE session_id = ?")
            ->execute([$paymentRef, $ticket, $sessionId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A seat taken by someone else while the payer was in GCash: keep the money record visible for a refund.
        if ($e instanceof PDOException && (string)$e->getCode() === '23505') {
            $pdo->prepare("UPDATE paymongo_checkout SET status = 'Needs refund', payment_ref = ? WHERE session_id = ? AND status <> 'Paid'")->execute([$paymentRef, $sessionId]);
            error_log("PayMongo $sessionId paid but a seat was taken; flagged for refund.");
            return ['ok' => false, 'refund' => true, 'message' => 'Your payment arrived after the seat hold expired and a seat was taken. It will be refunded.'];
        }
        error_log('Finalize payment failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not confirm the payment yet.'];
    }

    // Notifications are best effort and never block the ticket.
    try {
        log_activity($conn, (int)$co['user_id'], ACTION_PAYMENT_SUCCESS, "GCash payment {$paymentRef} for schedule {$co['schedule_id']}, ticket $ticket, total {$co['amount']}");
        mbus_notify($conn, (int)$co['user_id'], "Payment approved. Your ticket $ticket is ready.");
        mbus_notify_admins($conn, "GCash payment received: ticket $ticket, PHP " . number_format((float)$co['amount'], 2) . ".");
        $u = mbus_db_prepare($conn, 'SELECT email FROM users WHERE user_id = ?', [(int)$co['user_id']]);
        $ur = $u === false ? false : mbus_db_fetch_assoc($u);
        if ($ur && !empty($ur['email'])) {
            @send_email_notification($ur['email'], "Payment Confirmed - Ticket: $ticket",
                "<p>Your GCash payment of ₱" . number_format((float)$co['amount'], 2) . " is confirmed.</p><p><strong>Ticket:</strong> $ticket</p><p>Thank you for using MBUS!</p>");
        }
    } catch (Throwable $e) { error_log('Payment notification failed: ' . $e->getMessage()); }
    return $result + ['ticket' => $ticket];
}
