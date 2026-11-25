<?php
// Serves an operator application document (license, vehicle registration, photos, 2x2, medical, NBI) to admins only.
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { http_response_code(403); exit; }
include "../config/db_connect.php";
$id = (int)($_GET['id'] ?? 0);
$docMap = ['license' => 'license_document', 'vehicle' => 'vehicle_document', 'bus' => 'bus_photo', 'interior' => 'interior_photo',
           'license2' => 'license_document_2', 'license3' => 'license_document_3', 'vehicle2' => 'vehicle_document_2', 'vehicle3' => 'vehicle_document_3',
           'photo' => 'id_photo', 'medical' => 'medical_document', 'nbi' => 'nbi_document'];
$docKey = (string)($_GET['doc'] ?? 'license');
$doc = $docMap[$docKey] ?? 'license_document';
$q = mbus_db_prepare($conn, "SELECT {$doc}_b64 AS b64, {$doc}_mime AS mime FROM operator_application WHERE application_id = ?", [$id]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$bin = $r && !empty($r['b64']) ? base64_decode($r['b64'], true) : false;
if ($bin === false || $bin === '') { http_response_code(404); echo 'No document on file.'; exit; }
$mime = in_array($r['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) ? $r['mime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $doc . '-' . $id . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $bin;
