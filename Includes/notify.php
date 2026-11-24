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
