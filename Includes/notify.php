<?php
/** In-app notifications for commuters (bell on every commuter page). Best effort: never blocks the main action. */
function mbus_notify($conn, int $user_id, string $message): void
{
    if ($user_id <= 0 || $message === '') return;
    try {
        mbus_db_prepare($conn, "INSERT INTO notification (user_id, message, status) VALUES (?, ?, 'Unread')", [$user_id, mb_substr($message, 0, 300)]);
        // Keep only the newest 50 per user so notifications never stack up forever.
        mbus_db_prepare($conn, "DELETE FROM notification WHERE user_id = ? AND notification_id NOT IN (SELECT notification_id FROM notification WHERE user_id = ? ORDER BY notification_date DESC, notification_id DESC LIMIT 50)", [$user_id, $user_id]);
    } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
}

/** Notify every rider with a Paid/Boarded seat on a schedule that the bus has left. */
function mbus_notify_departure($conn, int $schedule_id): void
{
    try {
        // The table is `buses` (it was written as `bus`, so this query always failed and nobody was ever notified).
        $q = mbus_db_prepare($conn, "SELECT DISTINCT r.user_id, b.bus_number FROM reservation r JOIN schedule s ON s.schedule_id = r.schedule_id JOIN buses b ON b.bus_id = s.bus_id WHERE r.schedule_id = ? AND r.status IN ('Paid','Boarded')", [$schedule_id]);
        while ($q !== false && ($row = mbus_db_fetch_assoc($q))) {
            mbus_notify($conn, (int)$row['user_id'], 'Your bus (' . $row['bus_number'] . ') has departed. Track it on the Live page.');
        }
    } catch (Throwable $e) { error_log('notify departure failed: ' . $e->getMessage()); }
}

/** Notify riders and assigned bus staff when an Admin changes a schedule. */
function mbus_notify_schedule_change($conn, int $schedule_id, array $before): void
{
    if ($schedule_id <= 0) return;
    try {
        $q = mbus_db_prepare($conn, "SELECT s.departure_time, s.arrival_time, b.bus_number,
                COALESCE(ro.origin || ' - ' || ro.destination, 'your route') AS route_name
            FROM schedule s
            LEFT JOIN buses b ON b.bus_id = s.bus_id
            LEFT JOIN routes ro ON ro.route_id = s.route_id
            WHERE s.schedule_id = ?", [$schedule_id]);
        $after = $q === false ? false : mbus_db_fetch_assoc($q);
        if (!$after) return;

        $newDeparture = date('M j, Y g:i A', strtotime((string)$after['departure_time']));
        $newArrival = date('M j, Y g:i A', strtotime((string)$after['arrival_time']));
        $message = 'MBUS schedule changed: ' . $after['route_name'] . '. Departure: ' . $newDeparture . '; arrival: ' . $newArrival . '. Please check your trip details.';
        if ((string)($before['bus_number'] ?? '') !== (string)($after['bus_number'] ?? '') && $after['bus_number'] !== null) {
            $message .= ' Bus: ' . $after['bus_number'] . '.';
        }

        $recipients = mbus_db_prepare($conn, "
            SELECT DISTINCT user_id FROM (
                SELECT r.user_id FROM reservation r
                    WHERE r.schedule_id = ? AND r.user_id IS NOT NULL AND r.status IN ('Pending','Paid','Confirmed','Boarded')
                UNION ALL
                SELECT b.driver_id FROM schedule s JOIN buses b ON b.bus_id = s.bus_id
                    WHERE s.schedule_id = ? AND b.driver_id IS NOT NULL
                UNION ALL
                SELECT oba.operator_id FROM schedule s JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
                    WHERE s.schedule_id = ? AND oba.operator_id IS NOT NULL
                UNION ALL
                SELECT cba.conductor_id FROM schedule s JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
                    WHERE s.schedule_id = ? AND cba.conductor_id IS NOT NULL
            ) affected", [$schedule_id, $schedule_id, $schedule_id, $schedule_id]);
        while ($recipients !== false && ($row = mbus_db_fetch_assoc($recipients))) {
            mbus_notify($conn, (int)$row['user_id'], $message);
        }
    } catch (Throwable $e) { error_log('notify schedule change failed: ' . $e->getMessage()); }
}

/** Tell every admin about something a commuter did (new reservation, payment, ID request). Best effort. */
function mbus_notify_admins($conn, string $message): void
{
    if ($message === '') return;
    try {
        $q = mbus_db_prepare($conn, "SELECT user_id FROM users WHERE LOWER(user_type) = 'admin'", []);
        while ($q !== false && ($row = mbus_db_fetch_assoc($q))) {
            mbus_notify($conn, (int)$row['user_id'], $message);
        }
    } catch (Throwable $e) { error_log('notify admins failed: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------
 * Schedule change notifications (Rank 5)
 * ------------------------------------------------------------------ */

/** Snapshot of a schedule row with route and bus labels, used to describe what changed. Null if not found. */
function mbus_schedule_snapshot($conn, int $schedule_id): ?array
{
    if ($schedule_id <= 0) return null;
    try {
        $q = mbus_db_prepare($conn, "SELECT s.schedule_id, s.bus_id, s.route_id, s.departure_time, s.arrival_time, s.schedule_status, r.origin, r.destination, b.bus_number FROM schedule s LEFT JOIN routes r ON r.route_id = s.route_id LEFT JOIN buses b ON b.bus_id = s.bus_id WHERE s.schedule_id = ?", [$schedule_id]);
        $row = $q !== false ? mbus_db_fetch_assoc($q) : false;
        return $row ? $row : null;
    } catch (Throwable $e) { error_log('schedule snapshot failed: ' . $e->getMessage()); return null; }
}

/** Riders who still hold a seat (Pending/Paid/Confirmed) on a schedule. Call this BEFORE deleting a schedule. */
function mbus_schedule_rider_ids($conn, int $schedule_id): array
{
    $ids = [];
    try {
        $q = mbus_db_prepare($conn, "SELECT DISTINCT user_id FROM reservation WHERE schedule_id = ? AND status IN ('Pending','Paid','Confirmed')", [$schedule_id]);
        while ($q !== false && ($row = mbus_db_fetch_assoc($q))) $ids[] = (int)$row['user_id'];
    } catch (Throwable $e) { error_log('schedule riders failed: ' . $e->getMessage()); }
    return array_values(array_unique(array_filter($ids)));
}

/** Operator, driver and conductor user ids tied to any of the given buses. */
function mbus_schedule_staff_ids($conn, array $bus_ids): array
{
    $bus_ids = array_values(array_unique(array_filter(array_map('intval', $bus_ids))));
    if (!$bus_ids) return [];
    $in = implode(',', array_fill(0, count($bus_ids), '?'));
    $queries = [
        "SELECT operator_id AS uid FROM operator_bus_assignments WHERE bus_id IN ($in)",
        "SELECT driver_id AS uid FROM buses WHERE bus_id IN ($in) AND driver_id IS NOT NULL",
        "SELECT conductor_id AS uid FROM conductor_bus_assignments WHERE bus_id IN ($in)",
    ];
    $ids = [];
    // One query each so a missing optional table (for example conductor assignments) cannot hide the others.
    foreach ($queries as $sql) {
        try {
            $q = mbus_db_prepare($conn, $sql, $bus_ids);
            while ($q !== false && ($row = mbus_db_fetch_assoc($q))) $ids[] = (int)$row['uid'];
        } catch (Throwable $e) { error_log('schedule staff lookup failed: ' . $e->getMessage()); }
    }
    return array_values(array_unique(array_filter($ids)));
}

function mbus_schedule_when($value): string
{
    $t = strtotime((string)$value);
    return $t ? date('M j, g:i A', $t) : '';
}

function mbus_schedule_route_label(array $s): string
{
    return trim((string)($s['origin'] ?? '')) . ' to ' . trim((string)($s['destination'] ?? ''));
}

/** Plain-language summary of what changed. Empty string when nothing a passenger cares about changed. */
function mbus_schedule_change_message(array $before, ?array $after): string
{
    $route = mbus_schedule_route_label($before);
    $when = mbus_schedule_when($before['departure_time'] ?? '');
    if ($after === null) {
        return "Trip removed: the $route trip on $when is no longer on the schedule. Please check Schedule for other trips.";
    }
    $was = (string)($before['schedule_status'] ?? '');
    $now = (string)($after['schedule_status'] ?? '');
    if ($now === 'Cancelled' && $was !== 'Cancelled') {
        return "Trip cancelled: the $route trip on $when has been cancelled. Please check Schedule for other trips.";
    }
    $changes = [];
    if (strtotime((string)$before['departure_time']) !== strtotime((string)$after['departure_time'])) {
        $changes[] = 'departure moved to ' . mbus_schedule_when($after['departure_time']);
    }
    if (strtotime((string)$before['arrival_time']) !== strtotime((string)$after['arrival_time'])) {
        $changes[] = 'arrival is now ' . mbus_schedule_when($after['arrival_time']);
    }
    if ((int)$before['bus_id'] !== (int)$after['bus_id']) {
        $changes[] = 'bus changed to ' . ($after['bus_number'] ?? '');
    }
    if ((int)$before['route_id'] !== (int)$after['route_id']) {
        $changes[] = 'route changed to ' . mbus_schedule_route_label($after);
    }
    if ($now !== $was) {
        $changes[] = 'status is now ' . $now;
    }
    if (!$changes) return '';
    return "Schedule change: the $route trip on $when - " . implode('; ', $changes) . '.';
}

/**
 * Notify riders and the bus's staff that a schedule changed. $after is null when the schedule was deleted.
 * Pass $rider_ids collected before a delete, because reservations cascade-delete with the schedule.
 * Best effort: never blocks the admin action.
 */
function mbus_notify_schedule_change($conn, array $before, ?array $after, array $rider_ids = []): void
{
    try {
        $message = mbus_schedule_change_message($before, $after);
        if ($message === '') return;
        $bus_ids = [(int)($before['bus_id'] ?? 0)];
        if ($after !== null) $bus_ids[] = (int)($after['bus_id'] ?? 0);
        $recipients = array_unique(array_merge($rider_ids, mbus_schedule_staff_ids($conn, $bus_ids)));
        foreach ($recipients as $uid) mbus_notify($conn, (int)$uid, $message);
    } catch (Throwable $e) { error_log('notify schedule change failed: ' . $e->getMessage()); }
}
