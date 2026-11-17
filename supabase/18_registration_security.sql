-- Harden commuter email verification and bound signup mail volume. Safe to re-run.
ALTER TABLE users ALTER COLUMN email TYPE VARCHAR(254);
ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code_attempts SMALLINT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS registration_rate_limit_events (
    event_id BIGSERIAL PRIMARY KEY,
    bucket_hash CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

CREATE INDEX IF NOT EXISTS registration_rate_limit_events_bucket_idx
    ON registration_rate_limit_events (bucket_hash, created_at);
CREATE INDEX IF NOT EXISTS registration_rate_limit_events_created_idx
    ON registration_rate_limit_events (created_at);
