<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+30 days'));

// Get schedules
$sql = "SELECT schedule.schedule_id, buses.bus_number, routes.origin, routes.destination,
        schedule.departure_time, schedule.arrival_time, schedule.schedule_status,
        (SELECT COUNT(*) FROM reservation 
         WHERE reservation.schedule_id = schedule.schedule_id 
         AND (reservation.status = 'Paid' OR reservation.status = 'Boarded' 
              OR (reservation.status = 'Pending' AND reservation.created_at > NOW() - INTERVAL 10 MINUTE))) as reserved
        FROM schedule
        JOIN buses ON schedule.bus_id = buses.bus_id
        JOIN routes ON schedule.route_id = routes.route_id
        WHERE schedule.departure_time >= '$start' 
        AND schedule.departure_time <= '$end'
        AND schedule.schedule_status = 'Active'
        AND schedule.departure_time >= NOW()
        ORDER BY schedule.departure_time ASC";

$result = mbus_db_query($conn, $sql);

$events = [];
while($row = mbus_db_fetch_assoc($result)){
    $available = 28 - $row['reserved'];
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
