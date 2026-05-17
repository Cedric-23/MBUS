<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if(!isset($_POST['notification_id'])){
    http_response_code(400);
    echo json_encode(['error' => 'Missing notification_id']);
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = mbus_db_escape($conn, $_POST['notification_id']);

// Mark as read
mbus_db_query($conn,"
    UPDATE notification
    SET status = 'Read'
    WHERE notification_id = '$notification_id' AND user_id = '$user_id'
");

echo json_encode(['success' => true]);
