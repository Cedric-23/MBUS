<?php
session_start();
require_once "../config/db_connect.php";
$role = strtolower($_SESSION['user_type'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['operator', 'admin'], true)) { http_response_code(403); exit; }
$bus_id = (int)($_GET['bus_id'] ?? 0);
$col = ($_GET['type'] ?? '') === 'interior' ? 'interior_photo' : 'bus_photo';
if ($role === 'operator') {
    $ok = mbus_db_prepare($conn, "SELECT 1 FROM operator_bus_assignments WHERE bus_id = ? AND operator_id = ?", [$bus_id, (int)$_SESSION['user_id']]);
    if ($ok === false || mbus_db_num_rows($ok) < 1) { http_response_code(404); exit; }
}
$q = mbus_db_prepare($conn, "SELECT {$col}_b64 AS b64, {$col}_mime AS mime FROM buses WHERE bus_id = ?", [$bus_id]);
$r = $q !== false ? mbus_db_fetch_assoc($q) : false;
if (!$r || empty($r['b64'])) { http_response_code(404); exit; }
header('Content-Type: ' . ($r['mime'] === 'image/png' ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo base64_decode($r['b64']);
