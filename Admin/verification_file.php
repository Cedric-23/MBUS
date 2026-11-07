<?php
session_start();
if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != "admin"){
    http_response_code(403);
    exit();
}
include "../config/db_connect.php";
$q = mbus_db_prepare($conn, "SELECT document_b64, document_mime FROM commuter_verification WHERE verification_id = ?", [(int)($_GET['id'] ?? 0)]);
$row = $q === false ? false : mbus_db_fetch_assoc($q);
if(!$row || empty($row['document_b64'])){
    http_response_code(404);
    exit();
}
header('Content-Type: ' . $row['document_mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo base64_decode($row['document_b64']);
