<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit();
}

if (strtolower(trim((string)($_SESSION['user_type'] ?? ''))) !== 'commuter') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';
$userId = (int)$_SESSION['user_id'];
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit();
}

$query = mbus_db_prepare($conn, '
    SELECT DISTINCT schedule.schedule_id, schedule.trip_status, schedule.schedule_status
    FROM reservation
    JOIN schedule ON reservation.schedule_id = schedule.schedule_id
    WHERE reservation.user_id = ?
      AND reservation.status = \'Boarded\'
', [$userId]);

if ($query === false) {
    error_log('Passenger trip status lookup failed: ' . mbus_db_error($conn));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Trip status is temporarily unavailable']);
    exit();
}

$trips = [];
while ($row = mbus_db_fetch_assoc($query)) {
    $completed = (string)($row['trip_status'] ?? '') === 'Arrived'
        || (string)($row['schedule_status'] ?? '') === 'Completed';
    $trips[(string)(int)$row['schedule_id']] = $completed ? 'Completed' : 'In progress';
}

echo json_encode(['ok' => true, 'trips' => (object)$trips], JSON_UNESCAPED_SLASHES);
