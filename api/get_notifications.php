<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get notifications
$query = mbus_db_query($conn,"
    SELECT notification_id, message, notification_date, status
    FROM notification
    WHERE user_id = '$user_id'
    ORDER BY notification_date DESC
    LIMIT 20
");

$notifications = [];
while($row = mbus_db_fetch_assoc($query)){
    $notifications[] = [
        'id' => $row['notification_id'],
        'message' => $row['message'],
        'date' => $row['notification_date'],
        'status' => $row['status'],
        'time_ago' => time_ago($row['notification_date'])
    ];
}

// Get unread count
$count_query = mbus_db_query($conn,"
    SELECT COUNT(*) as unread_count
    FROM notification
    WHERE user_id = '$user_id' AND status = 'Unread'
");

$count_data = mbus_db_fetch_assoc($count_query);

echo json_encode([
    'notifications' => $notifications,
    'unread_count' => $count_data['unread_count']
]);

function time_ago($datetime){
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if($diff < 60){
        return 'Just now';
    } elseif($diff < 3600){
        return floor($diff/60) . ' min ago';
    } elseif($diff < 86400){
        return floor($diff/3600) . ' hours ago';
    } elseif($diff < 604800){
        return floor($diff/86400) . ' days ago';
    } else {
        return date('M d, Y', $time);
    }
}
