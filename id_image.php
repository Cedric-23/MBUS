<?php
// Serves a ticket's discount ID photo to admins, operators and the commuter who uploaded it.
session_start();
include "config/db_connect.php";
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$rid = (int)($_GET['r'] ?? 0);
$q = mbus_db_prepare($conn, "SELECT user_id, id_image_b64, id_image_mime FROM reservation WHERE reservation_id = ?", [$rid]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!$r || !($role === 'admin' || $role === 'operator' || (int)$r['user_id'] === (int)$_SESSION['user_id'])) { http_response_code(404); exit; }
$bin = !empty($r['id_image_b64']) ? base64_decode($r['id_image_b64'], true) : false;
if ($bin === false || $bin === '') { http_response_code(404); exit; }
$mime = in_array($r['id_image_mime'], ['image/jpeg','image/png','image/webp'], true) ? $r['id_image_mime'] : 'image/jpeg';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $bin;
