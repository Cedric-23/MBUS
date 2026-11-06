<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../Includes/schedule_booking.php';
$now = strtotime('2026-11-07 10:00:00');
$base = ['schedule_status' => 'Active', 'trip_status' => 'Scheduled', 'departure_time' => '2026-11-07 11:00:00', 'arrival_time' => '2026-11-07 12:00:00'];
$cases = [
    ['reservation open', [], 10, 'reserve'],
    ['exactly thirty minutes', ['departure_time' => '2026-11-07 10:30:00'], 10, 'reserve'],
    ['inside cutoff', ['departure_time' => '2026-11-07 10:29:59'], 10, 'pickup'],
    ['departed', ['trip_status' => 'Departed', 'departure_time' => '2026-11-07 09:30:00'], 10, 'pickup'],
    ['fully booked', [], 0, 'pickup'],
    ['boarding', ['trip_status' => 'Boarding'], 10, 'reserve'],
    ['arrived', ['trip_status' => 'Arrived'], 10, 'unavailable'],
    ['trip cancelled', ['trip_status' => 'Cancelled'], 10, 'unavailable'],
    ['schedule cancelled', ['schedule_status' => 'Cancelled'], 10, 'unavailable'],
    ['schedule completed', ['schedule_status' => 'Completed'], 10, 'unavailable'],
    ['old departed trip', ['trip_status' => 'Departed', 'departure_time' => '2026-11-06 23:30:00'], 10, 'unavailable'],
    ['late scheduled trip in progress', ['departure_time' => '2026-11-07 09:30:00'], 10, 'pickup'],
    ['past estimated arrival without departure', ['departure_time' => '2026-11-07 08:00:00', 'arrival_time' => '2026-11-07 09:00:00'], 10, 'unavailable'],
    ['invalid timestamp', ['departure_time' => 'not a date'], 10, 'unavailable'],
    ['unknown trip status', ['trip_status' => 'Unknown'], 10, 'unavailable'],
];
foreach ($cases as [$label, $changes, $seats, $expected]) {
    $actual = mbus_schedule_action(array_replace($base, $changes), $seats, $now);
    if ($actual !== $expected) throw new RuntimeException("$label: expected $expected, got $actual");
    echo "PASS $label\n";
}
echo count($cases) . " schedule policy checks passed.\n";
