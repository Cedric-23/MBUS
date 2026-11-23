-- Deploy after 01_schema.sql. A bus has at most one assigned conductor;
-- a conductor may support more than one bus.
BEGIN;
CREATE TABLE IF NOT EXISTS public.conductor_bus_assignments (
    assignment_id SERIAL PRIMARY KEY,
    conductor_id INTEGER NOT NULL REFERENCES public.users(user_id) ON DELETE CASCADE,
    bus_id INTEGER NOT NULL UNIQUE REFERENCES public.buses(bus_id) ON DELETE CASCADE,
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX IF NOT EXISTS idx_conductor_bus_assignments_conductor
    ON public.conductor_bus_assignments(conductor_id, bus_id);
ALTER TABLE public.conductor_bus_assignments ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.conductor_bus_assignments FROM PUBLIC, anon, authenticated;
COMMIT;
