<?php
// Serves a driver's renewed ID photo to admins and to the driver who sent it.
session_start();
include "../config/db_connect.php";
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$q = mbus_db_prepare($conn, "SELECT user_id, document_b64, document_mime FROM id_renewal WHERE renewal_id = ?", [(int)($_GET['id'] ?? 0)]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!$r || !($role === 'admin' || (int)$r['user_id'] === (int)$_SESSION['user_id'])) { http_response_code(404); exit; }
$bin = !empty($r['document_b64']) ? base64_decode($r['document_b64'], true) : false;
if ($bin === false || $bin === '') { http_response_code(404); exit; }
$mime = in_array($r['document_mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) ? $r['document_mime'] : 'image/jpeg';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Cache-Control: private, max-age=300');
echo $bin;
