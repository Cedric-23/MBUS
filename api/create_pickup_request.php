<?php
session_start();
require_once '../config/db_connect.php';
require_once '../config/mysqli_compat.php';
require_once '../Includes/csrf.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'commuter') { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate_token()) { http_response_code(403); echo json_encode(['error'=>'Invalid request']); exit; }
$schedule = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$location = trim((string)($_POST['pickup_location'] ?? ''));
$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($schedule === false || $location === '' || strlen($location) > 100 || ($lat !== false && ($lat < -90 || $lat > 90)) || ($lng !== false && ($lng < -180 || $lng > 180))) { http_response_code(422); echo json_encode(['error'=>'Enter a valid waiting location']); exit; }
$q=mbus_db_prepare($conn,"SELECT departure_time, trip_status, schedule_status FROM schedule WHERE schedule_id=?",[$schedule]);
$row=$q!==false?mbus_db_fetch_assoc($q):false;
if(!$row){http_response_code(404);echo json_encode(['error'=>'Schedule not found']);exit;}
$departure=strtotime($row['departure_time']);
if(in_array($row['trip_status'],['Arrived','Cancelled'],true) || in_array($row['schedule_status'],['Completed','Cancelled'],true) || $departure < time()-86400){http_response_code(409);echo json_encode(['error'=>'Pickup is no longer available for this trip']);exit;}
if($departure-time() > 1800){http_response_code(409);echo json_encode(['error'=>'Seat reservations are still open for this trip']);exit;}
$user=(int)$_SESSION['user_id'];
$r=mbus_db_prepare($conn,"INSERT INTO pickup_requests(user_id,schedule_id,pickup_location,latitude,longitude,status,updated_at) VALUES(?,?,?,?,?,'Waiting',CURRENT_TIMESTAMP) ON CONFLICT (user_id,schedule_id) WHERE status='Waiting' DO UPDATE SET pickup_location=EXCLUDED.pickup_location, latitude=EXCLUDED.latitude, longitude=EXCLUDED.longitude, updated_at=CURRENT_TIMESTAMP",[$user,$schedule,$location,$lat===false?null:$lat,$lng===false?null:$lng]);
if($r===false){http_response_code(500);echo json_encode(['error'=>'Could not save pickup request']);exit;}
echo json_encode(['ok'=>true,'message'=>'You are now marked as waiting']);
