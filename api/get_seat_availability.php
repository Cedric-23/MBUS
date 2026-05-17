<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if(!isset($_GET['schedule_id'])){
    http_response_code(400);
    echo json_encode(['error' => 'Missing schedule_id']);
    exit();
}

$schedule_id = mbus_db_escape($conn, $_GET['schedule_id']);

// Get seat availability
$seat_sql = "SELECT COUNT(*) AS total_reserved FROM reservation
WHERE schedule_id='$schedule_id'
AND(status='Paid' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL 10 MINUTE))";

$seat_result = mbus_db_query($conn, $seat_sql);
$seat_data = mbus_db_fetch_assoc($seat_result);

$reserved = $seat_data['total_reserved'];
$available = 28 - $reserved;

echo json_encode([
    'available' => $available,
    'reserved' => $reserved,
    'total' => 28
]);
