-- Deploy manually after 01_schema.sql and 10_live_pickup.sql.
-- One latest operator GPS point per schedule; no client-side table access.
BEGIN;
CREATE TABLE IF NOT EXISTS public.bus_live_tracking (
    schedule_id INTEGER PRIMARY KEY REFERENCES public.schedule(schedule_id) ON DELETE CASCADE,
    bus_id INTEGER NOT NULL REFERENCES public.buses(bus_id) ON DELETE CASCADE,
    operator_id INTEGER NOT NULL REFERENCES public.users(user_id) ON DELETE CASCADE,
    latitude DOUBLE PRECISION NOT NULL CHECK (latitude BETWEEN -90 AND 90),
    longitude DOUBLE PRECISION NOT NULL CHECK (longitude BETWEEN -180 AND 180),
    accuracy DOUBLE PRECISION NOT NULL CHECK (accuracy BETWEEN 0 AND 1000),
    captured_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE public.bus_live_tracking ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.bus_live_tracking FROM PUBLIC, anon, authenticated;
COMMIT;
