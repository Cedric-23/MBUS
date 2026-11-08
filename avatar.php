<?php
// Serves the signed-in user's own profile picture (any role) from the database.
session_start();
include "config/db_connect.php";
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$uid = (int)$_SESSION['user_id'];
// Operators and admins may view a rider's photo (?u=ID); everyone else only sees their own.
if (isset($_GET['u']) && in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['admin', 'operator'], true)) { $uid = (int)$_GET['u']; }
$q = mbus_db_prepare($conn, "SELECT profile_picture_b64, profile_picture_mime FROM users WHERE user_id = ?", [$uid]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$bin = $r && !empty($r['profile_picture_b64']) ? base64_decode($r['profile_picture_b64'], true) : false;
if ($bin === false || $bin === '') { http_response_code(404); exit; }
$mime = in_array($r['profile_picture_mime'], ['image/jpeg','image/png','image/webp'], true) ? $r['profile_picture_mime'] : 'image/jpeg';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: "' . md5($bin) . '"');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"' . md5($bin) . '"') { http_response_code(304); exit; }
echo $bin;
