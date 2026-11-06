<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
include "../config/db_connect.php";
require_once __DIR__ . '/../Includes/schedule_booking.php';

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
session_write_close();

$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
if(!is_string($start) || !is_string($end)){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date range']);
    exit();
}
$parsed_dates=[];
foreach([$start, $end] as $date_value){
    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $date_value)
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $date_value)
        ?: DateTimeImmutable::createFromFormat('!Y-m-d', $date_value);
    $date_errors = DateTimeImmutable::getLastErrors();
    if($parsed === false || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))){
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range']);
        exit();
    }
    $parsed_dates[]=$parsed->setTimezone(new DateTimeZone(date_default_timezone_get()));
}
if($parsed_dates[0] > $parsed_dates[1]){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date range']);
    exit();
}

// Include today's in-progress trips, not just future departures. The helper
// remains authoritative for whether each event is reserve/pickup/unavailable.
$sql = "SELECT schedule.schedule_id, buses.bus_number, buses.capacity, routes.origin, routes.destination,
        schedule.departure_time, schedule.arrival_time, schedule.schedule_status, schedule.trip_status,
        (SELECT COUNT(*) FROM reservation
         WHERE reservation.schedule_id = schedule.schedule_id
         AND (reservation.status = 'Paid' OR reservation.status = 'Confirmed' OR reservation.status = 'Boarded'
              OR (reservation.status = 'Pending' AND reservation.created_at > NOW() - INTERVAL '10 minutes'))) as reserved
        FROM schedule
        JOIN buses ON schedule.bus_id = buses.bus_id
        JOIN routes ON schedule.route_id = routes.route_id
        WHERE schedule.departure_time >= ?
        AND schedule.departure_time < ?
        AND schedule.schedule_status = 'Active'
        AND schedule.trip_status IN ('Scheduled', 'Boarding', 'Departed')
        AND schedule.departure_time >= ?
        ORDER BY schedule.departure_time ASC";

$today_start = date('Y-m-d 00:00:00');
$result = mbus_db_prepare($conn, $sql, [
    $parsed_dates[0]->format('Y-m-d H:i:s'),
    $parsed_dates[1]->format('Y-m-d H:i:s'),
    $today_start
]);
if($result === false){
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit();
}

$events = [];
$server_time = time();
while($row = mbus_db_fetch_assoc($result)){
    $available = max(0, (int)$row['capacity'] - (int)$row['reserved']);
    $action = mbus_schedule_action($row, $available, $server_time);
    $departure = strtotime($row['departure_time']);
    $arrival = strtotime($row['arrival_time'] ?? '') ?: 0;
    $unavailable_at = strtotime(date('Y-m-d', $departure) . ' +1 day');
    if($row['trip_status'] !== 'Departed'){
        $unavailable_at = min($unavailable_at, max($departure, $arrival));
    }
    $color = $action === 'unavailable' ? '#8395a7' : ($action === 'pickup' ? '#e67e22' : '#1e90ff');
    $action_url = $action === 'unavailable' ? null
        : '../Commuter/' . ($action === 'pickup' ? 'live.php' : 'reserve.php') . '?schedule_id=' . (int)$row['schedule_id'];

    $events[] = [
        'id' => $row['schedule_id'],
        'title' => "{$row['bus_number']} - {$row['origin']} → {$row['destination']}",
        'start' => $row['departure_time'],
        'end' => $row['arrival_time'],
        'backgroundColor' => $color,
        'borderColor' => $color,
        'extendedProps' => [
            'bus_number' => $row['bus_number'],
            'capacity' => (int)$row['capacity'],
            'origin' => $row['origin'],
            'destination' => $row['destination'],
            'departure_time' => $row['departure_time'],
            'arrival_time' => $row['arrival_time'],
            'schedule_status' => $row['schedule_status'],
            'trip_status' => $row['trip_status'],
            'available_seats' => $available,
            'reserved_seats' => (int)$row['reserved'],
            'action' => $action,
            'action_url' => $action_url,
            'server_time' => $server_time,
            'reserve_cutoff' => $departure - 30 * 60,
            'unavailable_at' => $unavailable_at
        ]
    ];
}

echo json_encode($events);
