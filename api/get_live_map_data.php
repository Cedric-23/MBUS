<?php
session_start();
require_once '../config/db_connect.php';
require_once '../config/mysqli_compat.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
$role = strtolower($_SESSION['user_type'] ?? '');
if (!in_array($role, ['admin','operator','regular','commuter'], true)) { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
$operator = (int)$_SESSION['user_id'];
$busWhere = '';
$params = [];
if ($role === 'operator') { $busWhere = ' AND oba.operator_id = ?'; $params[] = $operator; }
$sql = "SELECT b.bus_id, b.bus_number, b.bus_type, bl.latitude, bl.longitude, bl.updated_at,
               s.schedule_id, s.trip_status, ro.origin, ro.destination,
               COALESCE((SELECT COUNT(*) FROM pickup_requests pr WHERE pr.schedule_id=s.schedule_id AND pr.status='Waiting'),0) AS waiting_count,
               COALESCE((SELECT COUNT(*) FROM reservation rr WHERE rr.schedule_id=s.schedule_id AND rr.status='Boarded'),0) AS onboard_count
        FROM bus_live_locations bl
        JOIN buses b ON b.bus_id=bl.bus_id
        JOIN operator_bus_assignments oba ON oba.bus_id=b.bus_id
        LEFT JOIN schedule s ON s.schedule_id=bl.schedule_id
        LEFT JOIN routes ro ON ro.route_id=s.route_id
        WHERE bl.updated_at >= CURRENT_TIMESTAMP - INTERVAL '15 minutes' $busWhere
        ORDER BY b.bus_number";
$rows = mbus_db_prepare($conn, $sql, $params);
if ($rows === false) { http_response_code(500); echo json_encode(['error'=>'Live map data unavailable']); exit; }
$buses=[]; while($row=mbus_db_fetch_assoc($rows)) $buses[]=$row;
$pickups=[];
if ($role === 'admin' || $role === 'operator') {
  $pickupSql = "SELECT pr.pickup_request_id, pr.schedule_id, pr.pickup_location, pr.latitude, pr.longitude, pr.status, pr.created_at, u.full_name, b.bus_number
                FROM pickup_requests pr JOIN users u ON u.user_id=pr.user_id
                JOIN schedule s ON s.schedule_id=pr.schedule_id JOIN buses b ON b.bus_id=s.bus_id
                JOIN operator_bus_assignments oba ON oba.bus_id=b.bus_id
                WHERE pr.status='Waiting' $busWhere ORDER BY pr.created_at ASC";
  $p=mbus_db_prepare($conn,$pickupSql,$params); if($p!==false) while($row=mbus_db_fetch_assoc($p)) $pickups[]=$row;
}
echo json_encode(['buses'=>$buses,'pickups'=>$pickups,'server_time'=>date('c')]);
