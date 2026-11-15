<?php
// Shows a submitted GCash receipt image to admins only.
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { http_response_code(403); exit; }
include "../config/db_connect.php";
$q = mbus_db_prepare($conn, "SELECT receipt_b64 AS b64, receipt_mime AS mime FROM paymongo_checkout WHERE session_id = ?", [(string)($_GET['id'] ?? '')]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$bin = $r && !empty($r['b64']) ? base64_decode($r['b64'], true) : false;
if (!$bin) { http_response_code(404); echo 'No receipt.'; exit; }
header('Content-Type: ' . (in_array($r['mime'], ['image/jpeg', 'image/png'], true) ? $r['mime'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $bin;
