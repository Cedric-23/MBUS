# Live bus tracking, waiting riders and ticket QR

## Database deployment

After the existing MBUS schema, apply both additive migrations in order: `supabase/10_live_pickup.sql` and `supabase/11_bus_live_tracking.sql`. Migration 10 stores the latest commuter waiting signal; migration 11 stores the latest operator GPS point for each trip. They preserve existing schedules, reservations, accounts and payments. Reapplying is safe. A Git push does not run migrations, and these migrations have not been applied to production from this task.

Both tables use the existing server-side PHP database connection. Row-level security is enabled and direct `PUBLIC`, `anon`, and `authenticated` table access is revoked. Do not expose these location tables through the browser Supabase client. A custom non-owner database role needs deliberately configured server-only permissions.

## Rider experience

Commuter navigation is Home / Schedule / Live / Trips / Alerts. Schedule remains the place to reserve a seat or choose **Pick up** when reservations have closed. The Live page is for tracking, not for arranging a pickup. It lists current-day buses whose status is **Boarding** or **Departed**, including trips for riders who do not hold a reservation. The commuter chooses a bus and sees its route and most recently shared operator GPS point. No point means no bus marker; old points are labelled stale. Updates poll while the page is visible. The map does not simulate a bus route or claim a fake live location.

A commuter on the chosen trip can select their waiting location with GPS or a map pin and explicitly confirm **Mark waiting**. Only the chosen point and optional landmark are shared with operators for that trip; a map tap or GPS lookup alone does not send it. The rider can cancel the waiting signal. It expires after 30 minutes if not renewed. Live locations require browser location permission, an HTTPS site (localhost for development), and network access to Leaflet/OpenStreetMap tiles. These are foreground browser updates, not OS-level background tracking.

## Operator experience and limitations

When an operator opens Manage Trips with an assigned trip marked **Boarding** or **Departed**, the page requests browser location permission and shares GPS updates for an eligible trip while the page is visible. Updates pause when it is hidden and stop when it closes or the trip is no longer active. The browser prompt may require the operator to allow location access. In Android Chrome, keep the operator's Manage Trips page open in the foreground for reliable updates; this implementation does not provide native/background location tracking or a separate vehicle GPS device.

Operator waiting-rider details are shown inside **Riders**, alongside its seat map. The selected trip's bus position and waiting rider pins share that map. The operator's own Riders page only requests details for buses assigned to that operator. Old links to the separate operator waiting page redirect to Riders. The Admin waiting workspace remains available separately.

## Downloaded ticket QR

Downloaded PDF tickets contain the same signed `mbus_ticket_token(schedule_id, user_id)` as the on-screen ticket, encoded locally and rendered as vector modules. No ticket token is sent to an external QR service. The bundled MIT-licensed QR encoder is attributed under `Includes/vendor/`. The operator scanner continues to verify the existing HMAC token.

## Checks

Local-only tests and PHP/JavaScript lint checks:

```bash
php scripts/test_schedule_booking.php
php scripts/test_pickup_policy.php
php tests/ticket_qr_pdf_test.php
NODE_PATH=/tmp/mbus-test-tools/node_modules node tests/commuter_live_tracking_test.cjs
node tests/operator_live_tracking_test.cjs
php -l api/live_tracking.php
php -l Includes/bus_tracking.php
node --check Assets/js/commuter_live.js
node --check Assets/js/pickup_staff.js
node --check Assets/js/admin_waiting.js
```

For isolated PostgreSQL integration tests, apply migrations `01_schema.sql`, `10_live_pickup.sql` and `11_bus_live_tracking.sql` to a disposable local database named `mbus_pickup_test`, then run:

```bash
MBUS_PICKUP_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/pickup_integration.php
MBUS_TRACKING_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/tracking_integration.php
```

These scripts explicitly refuse to run against any other database. Never adapt the target check to production.
