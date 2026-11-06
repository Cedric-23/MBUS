# Live bus tracking, waiting riders and ticket QR

## Database deployment

After the existing MBUS schema, apply both additive migrations in order: `supabase/10_live_pickup.sql` and `supabase/11_bus_live_tracking.sql`. Migration 10 stores the latest commuter waiting signal; migration 11 stores the latest operator GPS point for each trip. They preserve existing schedules, reservations, accounts and payments. Reapplying is safe. A Git push does not run migrations, and these migrations have not been applied to production from this task.

Both tables use the existing server-side PHP database connection. Row-level security is enabled and direct `PUBLIC`, `anon`, and `authenticated` table access is revoked. Do not expose these location tables through the browser Supabase client. A custom non-owner database role needs deliberately configured server-only permissions.

## Rider experience

Commuter navigation is Home / Schedule / Live / Trips / Alerts. Schedule remains the place to reserve a seat or choose **Pick up** when reservations have closed. The Live page is for tracking, not for arranging a pickup. It lists current-day buses whose status is **Boarding** or **Departed**, including trips for riders who do not hold a reservation. The commuter chooses a bus and sees its route and most recently shared operator GPS point. No point means no bus marker; old points are labelled stale. Updates poll while the page is visible. The map does not simulate a bus route or claim a fake live location.

A commuter selects their waiting location in the **Alerts** tab with GPS, a draggable/map-tap pin, or manual coordinates, then explicitly confirms **Share waiting location**. Only the chosen point and optional landmark are shared with the assigned operator for that trip; selecting a map point or reading GPS alone does not send it. The rider can cancel the waiting signal. It expires after 30 minutes if not renewed. The Live tab is bus tracking only. Maps require browser location permission when GPS is used, an HTTPS site (localhost for development), and network access to Leaflet/OpenStreetMap tiles.

## Operator experience and limitations

After login, the operator explicitly turns on GPS from the Dashboard and allows browser location access. Their current GPS pin appears on the dashboard map. The dashboard polls assigned trips and uploads that position only once an assigned trip is **Boarding** or **Departed**. The operator can select among multiple active trips. Location sharing pauses when the dashboard is hidden and stops when it closes; keep it open in the foreground on Android Chrome for reliable updates. Browser permission usually requires HTTPS (localhost for development). This implementation does not provide native/background location tracking or a separate vehicle GPS device.

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
