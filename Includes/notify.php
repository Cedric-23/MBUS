<?php
/** In-app notifications for commuters (bell on the dashboard). Best effort: never blocks the main action. */
function mbus_notify($conn, int $user_id, string $message): void
{
    if ($user_id <= 0 || $message === '') return;
    try {
        mbus_db_prepare($conn, "INSERT INTO notification (user_id, message, status) VALUES (?, ?, 'Unread')", [$user_id, mb_substr($message, 0, 300)]);
    } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
}

/** Notify every rider with a Paid/Boarded seat on a schedule that the bus has left. */
function mbus_notify_departure($conn, int $schedule_id): void
{
    try {
        $q = mbus_db_prepare($conn, "SELECT DISTINCT r.user_id, b.bus_number FROM reservation r JOIN schedule s ON s.schedule_id = r.schedule_id JOIN bus b ON b.bus_id = s.bus_id WHERE r.schedule_id = ? AND r.status IN ('Paid','Boarded')", [$schedule_id]);
        while ($q !== false && ($row = mbus_db_fetch_assoc($q))) {
            mbus_notify($conn, (int)$row['user_id'], 'Your bus (' . $row['bus_number'] . ') has departed. Track it on the Live page.');
        }
    } catch (Throwable $e) { error_log('notify departure failed: ' . $e->getMessage()); }
}
