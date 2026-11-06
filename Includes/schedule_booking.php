<?php
/** Shared booking policy; schedule timestamps are in the configured app timezone. */
function mbus_schedule_action(array $schedule, ?int $availableSeats = null, ?int $now = null): string
{
    $now = $now ?? time();
    $departure = strtotime((string)($schedule['departure_time'] ?? ''));
    $arrival = strtotime((string)($schedule['arrival_time'] ?? ''));
    $status = $schedule['trip_status'] ?? 'Scheduled';
    if (($schedule['schedule_status'] ?? '') !== 'Active' || !$departure
        || !in_array($status, ['Scheduled', 'Boarding', 'Departed'], true)) {
        return 'unavailable';
    }
    // Never keep abandoned departed trips from previous days in the pickup feed.
    if ($departure < strtotime(date('Y-m-d 00:00:00', $now))) {
        return 'unavailable';
    }
    if ($status === 'Departed') {
        return 'pickup';
    }
    if ($departure <= $now && (!$arrival || $arrival <= $now)) {
        return 'unavailable';
    }
    if ($departure - $now < 30 * 60 || ($availableSeats !== null && $availableSeats <= 0)) {
        return 'pickup';
    }
    return 'reserve';
}
