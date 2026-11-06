-- Operator trip history archive
-- Run this migration in Supabase SQL Editor after 08_backend_integrity.sql.
-- Archiving hides terminal trips from an operator's workspace; it does not delete
-- schedules, reservations, payments, or passenger records.

CREATE TABLE IF NOT EXISTS operator_trip_archives (
    operator_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    schedule_id INT NOT NULL REFERENCES schedule(schedule_id) ON DELETE CASCADE,
    archived_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (operator_id, schedule_id)
);

CREATE INDEX IF NOT EXISTS idx_operator_trip_archives_operator
    ON operator_trip_archives(operator_id, archived_at DESC);
