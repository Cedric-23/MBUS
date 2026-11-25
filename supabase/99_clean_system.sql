-- MBUS clean slate: wipes all data, keeps the admin account(s), routes and settings.
-- Does NOT drop tables, columns or functions, so the app keeps working as-is.
-- Run in Supabase SQL Editor. It is one transaction: if anything errors, nothing changes.
--
-- KEEPS : users where user_type = admin, routes (+ stops/coordinates), app_settings
-- WIPES : every other account, reservations, payments, GCash/PayMongo records, ID/medical/NBI/
--         license images (they live in these tables), driver/conductor applications, ID renewals,
--         buses, schedules, trip history, live tracking, pickups, notifications, announcements,
--         feedback, emergency reports, activity logs, rate-limit cache, sessions.
-- A full copy is saved first in schema mbus_backup_<date>, so you can undo this (see restore file).
-- Heads up: sessions are wiped, so you (and everyone) will be logged out once.

BEGIN;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users WHERE LOWER(user_type) = 'admin') THEN
    RAISE EXCEPTION 'No admin account found - aborting so you do not lock yourself out.';
  END IF;
END $$;

-- 0) BACKUP: copy every table into a schema named mbus_backup_<date> before touching anything.
--    Nothing in it is deleted by this script. See the restore file to put it back.
DO $$
DECLARE t text; sch text := 'mbus_backup_' || to_char(now(), 'YYYYMMDD_HH24MI');
BEGIN
  EXECUTE format('CREATE SCHEMA %I', sch);
  FOREACH t IN ARRAY ARRAY[
    'users','reservation','payment','incoming_payments','paymongo_checkout',
    'pickup_requests','bus_live_tracking','emergency_report','feedback',
    'notification','activity_logs','commuter_verification','id_renewal',
    'operator_application','conductor_application',
    'operator_bus_assignments','conductor_bus_assignments','operator_trip_archives',
    'announcement','registration_rate_limit_events','mbus_sessions',
    'schedule','buses'
  ] LOOP
    IF to_regclass('public.' || t) IS NOT NULL THEN
      EXECUTE format('CREATE TABLE %I.%I AS TABLE public.%I', sch, t, t);
    END IF;
  END LOOP;
  RAISE NOTICE 'Backup saved in schema %', sch;
END $$;

-- 1) Wipe data tables (skips any table that does not exist on your DB).
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'reservation','payment','incoming_payments','paymongo_checkout',
    'pickup_requests','bus_live_tracking','emergency_report','feedback',
    'notification','activity_logs','commuter_verification','id_renewal',
    'operator_application','conductor_application',
    'operator_bus_assignments','conductor_bus_assignments','operator_trip_archives',
    'announcement','registration_rate_limit_events','mbus_sessions',
    'schedule','buses'
  ] LOOP
    IF to_regclass('public.' || t) IS NOT NULL THEN
      EXECUTE format('TRUNCATE TABLE public.%I RESTART IDENTITY CASCADE', t);
    END IF;
  END LOOP;
END $$;

-- 2) Delete every account except admins.
DELETE FROM users WHERE LOWER(user_type) <> 'admin';

-- 3) Clear leftover verification / reset codes on the admin row(s).
UPDATE users
   SET registration_code = NULL,
       registration_code_expiry = NULL
 WHERE LOWER(user_type) = 'admin';

-- 4) Quick check: should show only admin accounts and your routes.
SELECT user_id, email, user_type FROM users ORDER BY user_id;
SELECT COUNT(*) AS routes_kept FROM routes;

COMMIT;
