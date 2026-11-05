<?php
session_start();
header('Content-Type: application/json');
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+30 days'));
if(!is_string($start) || !is_string($end)){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date range']);
    exit();
}
$parsed_dates=[];
foreach([$start, $end] as $date_value){
    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $date_value)
        ?: DateTimeImmutable::createFromFormat('Y-m-d', $date_value);
    $date_errors = DateTimeImmutable::getLastErrors();
    if($parsed === false || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))){
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range']);
        exit();
    }
    $parsed_dates[]=$parsed;
}
if($parsed_dates[0] > $parsed_dates[1]){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date range']);
    exit();
}

// Get schedules
$sql = "SELECT schedule.schedule_id, buses.bus_number, buses.capacity, routes.origin, routes.destination,
        schedule.departure_time, schedule.arrival_time, schedule.schedule_status,
        (SELECT COUNT(*) FROM reservation
         WHERE reservation.schedule_id = schedule.schedule_id
         AND (reservation.status = 'Paid' OR reservation.status = 'Confirmed' OR reservation.status = 'Boarded'
              OR (reservation.status = 'Pending' AND reservation.created_at > NOW() - INTERVAL '10 minutes'))) as reserved
        FROM schedule
        JOIN buses ON schedule.bus_id = buses.bus_id
        JOIN routes ON schedule.route_id = routes.route_id
        WHERE schedule.departure_time >= ?
        AND schedule.departure_time <= ?
        AND schedule.schedule_status = 'Active'
        AND schedule.departure_time >= NOW()
        ORDER BY schedule.departure_time ASC";

$result = mbus_db_prepare($conn, $sql, [$start, $end]);
if($result === false){
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit();
}

$events = [];
while($row = mbus_db_fetch_assoc($result)){
    $available = max(0, (int)$row['capacity'] - (int)$row['reserved']);
    $title = "{$row['bus_number']} - {$row['origin']} → {$row['destination']}";

    $events[] = [
        'id' => $row['schedule_id'],
        'title' => $title,
        'start' => $row['departure_time'],
        'end' => $row['arrival_time'],
        'backgroundColor' => $available <= 0 ? '#ff4757' : '#1e90ff',
        'borderColor' => $available <= 0 ? '#ff4757' : '#1e90ff',
        'extendedProps' => [
            'bus_number' => $row['bus_number'],
            'capacity' => (int)$row['capacity'],
            'origin' => $row['origin'],
            'destination' => $row['destination'],
            'departure_time' => $row['departure_time'],
            'arrival_time' => $row['arrival_time'],
            'available_seats' => $available,
            'reserved_seats' => $row['reserved']
        ]
    ];
}

echo json_encode($events);
