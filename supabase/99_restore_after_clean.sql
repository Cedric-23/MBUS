-- MBUS restore: puts back everything saved by mbus_clean_system.sql.
-- 1) Change the schema name below to yours (Table Editor -> schema dropdown shows mbus_backup_...).
-- 2) Run in Supabase SQL Editor. One transaction.
BEGIN;
DO $$
DECLARE
  sch text := 'mbus_backup_YYYYMMDD_HHMI';   -- <-- EDIT THIS
  t text;
  c record;
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = sch) THEN
    RAISE EXCEPTION 'Schema % not found - edit the name above', sch;
  END IF;
  -- parents first so foreign keys are satisfied
  FOREACH t IN ARRAY ARRAY[
    'users','buses','schedule','reservation','payment','incoming_payments','paymongo_checkout',
    'pickup_requests','bus_live_tracking','emergency_report','feedback','notification',
    'activity_logs','commuter_verification','id_renewal','operator_application',
    'conductor_application','operator_bus_assignments','conductor_bus_assignments',
    'operator_trip_archives','announcement','registration_rate_limit_events','mbus_sessions'
  ] LOOP
    IF to_regclass(format('%I.%I', sch, t)) IS NOT NULL THEN
      IF t = 'users' THEN
        -- admins were kept, so only add back the rows that are missing
        EXECUTE format('INSERT INTO public.users SELECT * FROM %I.users b WHERE NOT EXISTS (SELECT 1 FROM public.users u WHERE u.user_id = b.user_id)', sch);
      ELSE
        EXECUTE format('INSERT INTO public.%I SELECT * FROM %I.%I', t, sch, t);
      END IF;
      -- keep each id counter ahead of the restored rows
      FOR c IN SELECT column_name FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = t
                 AND pg_get_serial_sequence(format('public.%I', t), column_name) IS NOT NULL LOOP
        EXECUTE format('SELECT setval(%L, COALESCE((SELECT MAX(%I) FROM public.%I), 1))',
                       pg_get_serial_sequence(format('public.%I', t), c.column_name), c.column_name, t);
      END LOOP;
    END IF;
  END LOOP;
END $$;
COMMIT;
-- Afterwards (optional): DROP SCHEMA mbus_backup_YYYYMMDD_HHMI CASCADE;
