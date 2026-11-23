<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/paymongo.php";

header('Content-Type: text/plain; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('method_not_allowed');
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('login_required');
}
if (!csrf_validate_token()) {
    http_response_code(403);
    exit('csrf_error');
}

$userId = (int)$_SESSION['user_id'];
$reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$reservationId && !$scheduleId) {
    http_response_code(400);
    exit('no_id');
}

$pdo = $conn->pdo;
$remoteSessions = [];
try {
    $pdo->beginTransaction();

    if ($reservationId) {
        // Read the schedule first without a row lock. Payment finalization locks
        // the checkout before reservations, so cancellation must keep that order.
        $st = $pdo->prepare("SELECT schedule_id FROM reservation WHERE reservation_id = ? AND user_id = ?");
        $st->execute([$reservationId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            http_response_code(409);
            exit('reservation_not_pending');
        }
        $scheduleId = (int)$row['schedule_id'];
    }
    $reservationIds = [];

    // A checkout can contain several seats, but a reservation cancellation is
    // still scoped to the selected reservation. Close the shared checkout so
    // it cannot later pay a cancelled seat; the remaining pending seats stay
    // available and can be paid for in a new checkout.
    $checkoutQuery = $pdo->prepare("SELECT session_id, status, reservation_ids
        FROM paymongo_checkout
        WHERE user_id = ? AND schedule_id = ? AND status IN ('Created', 'Submitted')
        ORDER BY created_at DESC FOR UPDATE");
    $checkoutQuery->execute([$userId, $scheduleId]);
    foreach ($checkoutQuery->fetchAll(PDO::FETCH_ASSOC) as $checkout) {
        $linked = json_decode((string)$checkout['reservation_ids'], true);
        if (!is_array($linked)) {
            continue;
        }
        $linked = array_values(array_unique(array_filter(array_map('intval', $linked), static fn($id) => $id > 0)));
        if ($reservationId) {
            if (!in_array((int)$reservationId, $linked, true)) {
                continue;
            }
            if ($checkout['status'] === 'Created' && strpos((string)$checkout['session_id'], 'manual_') !== 0) {
                $remoteSessions[] = (string)$checkout['session_id'];
            }
            $pdo->prepare("UPDATE paymongo_checkout SET status = 'Cancelled'
                WHERE session_id = ? AND status IN ('Created', 'Submitted')")
                ->execute([$checkout['session_id']]);
        } else {
            $reservationIds = array_merge($reservationIds, $linked);
            if ($checkout['status'] === 'Created' && strpos((string)$checkout['session_id'], 'manual_') !== 0) {
                $remoteSessions[] = (string)$checkout['session_id'];
            }
            $pdo->prepare("UPDATE paymongo_checkout SET status = 'Cancelled'
                WHERE session_id = ? AND status IN ('Created', 'Submitted')")
                ->execute([$checkout['session_id']]);
        }
    }

    // Recheck and lock pending rows only after checkout locks are held.
    if ($reservationId) {
        $pending = $pdo->prepare("SELECT reservation_id FROM reservation
            WHERE reservation_id = ? AND user_id = ? AND schedule_id = ? AND status = 'Pending' FOR UPDATE");
        $pending->execute([$reservationId, $userId, $scheduleId]);
        if (!$pending->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            exit('reservation_not_pending');
        }
        $reservationIds[] = (int)$reservationId;
    } else {
        $pending = $pdo->prepare("SELECT reservation_id FROM reservation
            WHERE schedule_id = ? AND user_id = ? AND status = 'Pending'
            ORDER BY reservation_id FOR UPDATE");
        $pending->execute([$scheduleId, $userId]);
        $reservationIds = array_merge($reservationIds, array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN)));
        if (!$reservationIds) {
            $pdo->rollBack();
            http_response_code(409);
            exit('reservation_not_pending');
        }
    }
    $reservationIds = array_values(array_unique(array_filter(array_map('intval', $reservationIds), static fn($id) => $id > 0)));
    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
    $params = array_merge([$userId, $scheduleId], $reservationIds);
    $update = $pdo->prepare("UPDATE reservation SET status = 'Cancelled'
        WHERE user_id = ? AND schedule_id = ? AND reservation_id IN ($placeholders) AND status = 'Pending'
        RETURNING reservation_id");
    $update->execute($params);
    if (!$update->fetchAll(PDO::FETCH_COLUMN)) {
        $pdo->rollBack();
        http_response_code(409);
        exit('reservation_not_pending');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reservation cancellation failed: ' . $e->getMessage());
    http_response_code(500);
    exit('cancel_failed');
}

foreach (array_unique($remoteSessions) as $sessionId) {
    try {
        paymongo_expire_session($sessionId);
    } catch (Throwable $e) {
        error_log('Could not expire PayMongo checkout after cancellation: ' . $e->getMessage());
    }
}

echo 'ok';
