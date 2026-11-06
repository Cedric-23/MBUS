-- DEPLOY PREREQUISITE: apply manually in Supabase SQL Editor after 01_schema.sql.
-- This repository change does NOT run a migration or write to production.
-- Pickup requests are independent of reservations: no seats or payments change.
-- The PHP backend uses the existing server-side PostgreSQL connection/session auth.
-- No direct anonymous/authenticated Supabase client access is granted.

BEGIN;

CREATE TABLE IF NOT EXISTS public.pickup_requests (
    request_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES public.users(user_id) ON DELETE CASCADE,
    schedule_id INTEGER NOT NULL REFERENCES public.schedule(schedule_id) ON DELETE CASCADE,
    status VARCHAR(16) NOT NULL DEFAULT 'Waiting'
        CHECK (status IN ('Waiting', 'Cancelled', 'Picked up', 'Expired')),
    latitude DOUBLE PRECISION NOT NULL CHECK (latitude >= -90 AND latitude <= 90),
    longitude DOUBLE PRECISION NOT NULL CHECK (longitude >= -180 AND longitude <= 180),
    pickup_label VARCHAR(100) NOT NULL CHECK (char_length(btrim(pickup_label)) BETWEEN 1 AND 100),
    destination VARCHAR(100) NOT NULL CHECK (char_length(btrim(destination)) BETWEEN 1 AND 100),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL DEFAULT (NOW() + INTERVAL '30 minutes'),
    CHECK (expires_at > created_at)
);

-- One row per commuter retains the latest signal/final status, not a travel history.
-- Explicit wait/update renews expiry and gets a fresh request_id so stale staff or
-- rider actions cannot accidentally act on a replacement signal.
CREATE INDEX IF NOT EXISTS idx_pickup_requests_waiting_schedule
    ON public.pickup_requests (schedule_id, expires_at) WHERE status = 'Waiting';

ALTER TABLE public.pickup_requests ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.pickup_requests FROM PUBLIC, anon, authenticated;
REVOKE ALL ON SEQUENCE public.pickup_requests_request_id_seq FROM PUBLIC, anon, authenticated;
-- Deliberately no browser-client RLS policies. Existing postgres server role bypasses
-- RLS; a custom non-owner backend role needs explicit restricted server-side grants.

COMMIT;
