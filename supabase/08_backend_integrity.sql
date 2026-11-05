-- Backend integrity constraints for reservation concurrency and operator ownership.
-- Run after 01_schema.sql and after cleaning any pre-existing duplicate rows.

CREATE UNIQUE INDEX IF NOT EXISTS uq_active_reservation_seat
    ON reservation(schedule_id, seat_number)
    WHERE status IN ('Pending', 'Paid', 'Confirmed', 'Boarded');

CREATE UNIQUE INDEX IF NOT EXISTS uq_operator_bus_assignment
    ON operator_bus_assignments(operator_id, bus_id);

CREATE INDEX IF NOT EXISTS idx_notification_user_date
    ON notification(user_id, notification_date DESC);
