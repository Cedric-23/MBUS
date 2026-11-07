<?php
session_start();
require_once '../config/db_connect.php';
require_once '../config/mysqli_compat.php';
require_once '../Includes/csrf.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator') { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate_token()) { http_response_code(403); echo json_encode(['error'=>'Invalid request']); exit; }
$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$schedule = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { http_response_code(422); echo json_encode(['error'=>'Invalid coordinates']); exit; }
$operator = (int)$_SESSION['user_id'];
$sql = "INSERT INTO bus_live_locations(bus_id, operator_id, schedule_id, latitude, longitude, updated_at)
SELECT oba.bus_id, ?, ?, ?, ?, CURRENT_TIMESTAMP
FROM operator_bus_assignments oba
WHERE oba.operator_id = ?
ORDER BY oba.assignment_id ASC LIMIT 1
ON CONFLICT (bus_id) DO UPDATE SET operator_id=EXCLUDED.operator_id, schedule_id=EXCLUDED.schedule_id, latitude=EXCLUDED.latitude, longitude=EXCLUDED.longitude, updated_at=CURRENT_TIMESTAMP";
$result = mbus_db_prepare($conn, $sql, [$operator, $schedule ?: null, $lat, $lng, $operator]);
if ($result === false) { http_response_code(500); echo json_encode(['error'=>'Location could not be saved']); exit; }
echo json_encode(['ok'=>true]);
