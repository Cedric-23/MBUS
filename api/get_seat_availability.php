<?php
session_start();
header('Content-Type: application/json');
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if(!isset($_GET['schedule_id']) || !is_scalar($_GET['schedule_id'])){
    http_response_code(400);
    echo json_encode(['error' => 'Missing schedule_id']);
    exit();
}

$schedule_id = (int)$_GET['schedule_id'];
if($schedule_id <= 0){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid schedule_id']);
    exit();
}

// Free seats held by unpaid reservations older than 10 minutes (the unique seat index would block re-booking otherwise)
mbus_db_prepare($conn, "UPDATE reservation SET status='Cancelled' WHERE schedule_id = ? AND status='Pending' AND created_at <= NOW()-INTERVAL '10 minutes'", [$schedule_id]);

// Get seat availability
$seat_sql = "SELECT b.capacity, COUNT(r.reservation_id) AS total_reserved
    FROM schedule s
    JOIN buses b ON b.bus_id = s.bus_id
    LEFT JOIN reservation r ON r.schedule_id = s.schedule_id
        AND (r.status='Paid' OR r.status='Confirmed' OR r.status='Boarded' OR (r.status='Pending' AND r.created_at > NOW()-INTERVAL '10 minutes'))
    WHERE s.schedule_id = ?
    GROUP BY b.capacity";

$seat_result = mbus_db_prepare($conn, $seat_sql, [$schedule_id]);
if($seat_result === false){
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit();
}
$seat_data = mbus_db_fetch_assoc($seat_result);
if($seat_data === false){
    http_response_code(404);
    echo json_encode(['error' => 'Schedule not found']);
    exit();
}
$reserved = (int)$seat_data['total_reserved'];
$total = (int)$seat_data['capacity'];
$available = max(0, $total - $reserved);

// Which seats are locked right now, and which of them belong to this user
$taken = []; $mine = [];
$tq = mbus_db_prepare($conn, "SELECT seat_number, user_id, status FROM reservation
    WHERE schedule_id = ? AND (status IN ('Paid','Confirmed','Boarded') OR (status='Pending' AND created_at > NOW()-INTERVAL '10 minutes'))", [$schedule_id]);
while($tq !== false && ($tr = mbus_db_fetch_assoc($tq))){
    $n = (int)$tr['seat_number'];
    $taken[] = $n;
    if((int)$tr['user_id'] === (int)$_SESSION['user_id']){ $mine[] = ['seat' => $n, 'status' => $tr['status']]; }
}

echo json_encode([
    'available' => $available,
    'reserved' => $reserved,
    'total' => $total,
    'taken' => $taken,
    'mine' => $mine
]);
