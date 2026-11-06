# Live pickup and ticket QR update

## Enable pickup on the deployed app

Apply `supabase/10_live_pickup.sql` in the Supabase SQL Editor against the existing MBUS database before deploying this version. This additive migration creates `pickup_requests`; it does not reset schedules, reservations, users or payments. It can be run again safely. Pushing the code does not apply the migration. If it is missing, the screens show a setup message and refuse pickup submissions.

The new table is accessible only through the existing PHP server-side database connection. Anonymous and authenticated Supabase browser roles have no direct table access. The existing `postgres` connection works; a different non-owner backend role needs appropriate table/sequence grants and server-only RLS policies before use. Never enable public browser access to rider locations.

## Rider and staff behavior

Commuter navigation is Home / Schedule / Live / Trips / Alerts. `Commuter/live.php` shows the map and available routes. A schedule switches from Reserve seat to Pick up inside the existing 30-minute-before-departure cutoff, when the operator marks it departed, or when no reservation seats remain. Exactly 30 minutes before departure remains reservable. Arrived, cancelled, completed and previous-day stale trips are unavailable. Server checks use the same policy as the cards and recheck the trip under a row lock when saving.

A commuter chooses a bus, pins a safe waiting spot using GPS, a map click/drag or manual coordinates, chooses a destination, and presses “I’m waiting here.” Choosing a pin alone does not share it. One latest request per commuter is retained; an explicit update replaces the previous request and renews the 30-minute expiry. Requests can be cancelled by their owner. Polling does not renew expiry or write to the database.

Operators open `Operator/waiting.php` from their dashboard, trip operations or riders page; they see only their assigned buses. Admins open `Admin/waiting.php` from their dashboard or schedules page and see all active waiting commuters. Both views show names, waiting pins, landmarks, destinations and trip details. “Mark picked up” removes the request from the waiting queue without creating or modifying a reservation, ticket or payment. Expired or terminal-trip requests stop appearing as active waiting requests.

Pickup is not a guaranteed seat or a prepaid ticket. Maps show shared waiting points and optional route endpoints, not live bus GPS or estimated bus arrival. GPS needs browser location permission and HTTPS outside localhost. Leaflet is pinned to stable 1.9.4; OpenStreetMap tiles require network access and display attribution. Map-library or tile failures leave a coordinates-based fallback for riders and a readable staff list.

## Downloaded ticket QR

Downloaded PDF tickets include the same signed `mbus_ticket_token(schedule_id, user_id)` as the on-screen ticket, generated locally and drawn as vector modules. Ticket tokens are not sent to a QR service. The bundled MIT-licensed QR encoder is attributed under `Includes/vendor/`. The boarding scanner continues to verify the existing HMAC token. No new QR secret is required beyond the existing configuration.

## Checks

Run PHP syntax checks and the focused no-database policy checks:

```bash
find Commuter Includes Operator Admin api tests scripts -name '*.php' -not -path '*/fpdf/*' -print0 | xargs -0 -n1 php -l
php scripts/test_schedule_booking.php
php scripts/test_pickup_policy.php
php tests/ticket_qr_pdf_test.php
node --check Assets/js/commuter_live.js
node --check Assets/js/pickup_staff.js
```

The commuter DOM test uses a mocked API and a development-only jsdom installation, not production data:

```bash
npm install --prefix /tmp/mbus-test-tools --no-audit --no-fund jsdom@26
NODE_PATH=/tmp/mbus-test-tools/node_modules node tests/commuter_live_test.cjs
```

`tests/pickup_integration.php` accepts only the Unix socket DSN for a local database named `mbus_pickup_test`. It refuses other targets. Set up that disposable PostgreSQL database with `supabase/01_schema.sql` and `supabase/10_live_pickup.sql`, with local roles `ubuntu`, `anon` and `authenticated`, then run:

```bash
MBUS_PICKUP_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/pickup_integration.php
```

This integration test resets its isolated local fixtures and exercises the cutoff, ownership, operator assignment scope, expiry, cancellation, completion, malformed forms and unavailable migration. Never adapt it to a production database.
