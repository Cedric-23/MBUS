<?php
session_start();
header('Content-Type: application/json');

include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Mark all notifications as read for this user
    $stmt = mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE user_id = ? AND status = 'Unread'", [$user_id]);
    mbus_db_execute($stmt);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
