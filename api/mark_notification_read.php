<?php
session_start();
header('Content-Type: application/json');
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$notification_id = isset($_POST['notification_id']) && is_scalar($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
if($notification_id <= 0){
    http_response_code(400);
    echo json_encode(['error' => 'Missing notification_id']);
    exit();
}

$result = mbus_db_prepare($conn,
    "UPDATE notification SET status = 'Read' WHERE notification_id = ? AND user_id = ?",
    [$notification_id, $_SESSION['user_id']]
);

if($result === false){
    http_response_code(500);
    echo json_encode(['error' => 'Database update failed']);
    exit();
}

echo json_encode(['success' => true]);
