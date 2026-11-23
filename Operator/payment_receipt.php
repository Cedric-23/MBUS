<?php
// Shows a commuter's GCash screenshot only to the Conductor assigned to that bus.
session_start();
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor') { http_response_code(403); exit; }
include "../config/db_connect.php";
$q = mbus_db_prepare($conn, "SELECT c.receipt_b64 AS b64, c.receipt_mime AS mime FROM paymongo_checkout c
    WHERE c.session_id = ? AND c.session_id LIKE 'manual\\_%'
      AND EXISTS (SELECT 1 FROM schedule s JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
          WHERE s.schedule_id = c.schedule_id AND cba.conductor_id = ?)", [(string)($_GET['id'] ?? ''), (int)$_SESSION['user_id']]);
$r = $q === false ? false : mbus_db_fetch_assoc($q);
$bin = $r && !empty($r['b64']) ? base64_decode($r['b64'], true) : false;
if (!$bin) { http_response_code(404); echo 'No screenshot.'; exit; }
header('Content-Type: ' . (in_array($r['mime'], ['image/jpeg', 'image/png'], true) ? $r['mime'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
echo $bin;
