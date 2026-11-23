<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

header('Content-Type: application/json; charset=UTF-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('{"error":"login_required"}'); }
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!in_array($role, ['driver', 'operator', 'conductor'], true)) { http_response_code(403); exit('{"error":"forbidden"}'); }
$uid = (int)$_SESSION['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_validate_token()) { http_response_code(403); exit('{"error":"csrf"}'); }
    mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE user_id = ? AND status = 'Unread'", [$uid]);
    exit('{"ok":true}');
}

$q = mbus_db_prepare($conn, "SELECT notification_id, message, status, notification_date FROM notification WHERE user_id = ? ORDER BY notification_date DESC, notification_id DESC LIMIT 20", [$uid]);
$items = []; $unread = 0;
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) {
    $isNew = $r['status'] === 'Unread';
    if ($isNew) $unread++;
    $items[] = ['id' => (int)$r['notification_id'], 'message' => $r['message'], 'unread' => $isNew, 'at' => date('c', strtotime($r['notification_date']))];
}
echo json_encode(['unread' => $unread, 'items' => $items]);
