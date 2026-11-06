# Waiting riders, live bus location and the location gate

After the existing MBUS schema, apply both additive migrations in order: `supabase/10_live_pickup.sql` and `supabase/11_bus_live_tracking.sql`. Migration 10 stores the latest commuter waiting signal; migration 11 stores the latest operator GPS point for each trip. Both preserve schedules, reservations, accounts and payments, and reapplying is safe. A Git push does not run migrations.

## One Waiting tab, three roles

Every bottom navigation bar now has **Waiting** where **Alerts** used to be. Nothing else in the app offers a waiting control.

| Role | Page | Behaviour |
|------|------|-----------|
| Commuter | `Commuter/waiting.php` | Pick the bus, tap **Share my location**, then see a status card with the bus, stop, shared time, expiry and the distance to the bus. **Update my spot** re-sends the current GPS fix; **Cancel** removes the signal. A map, coordinates and stop name sit behind **Adjust location** so the first screen stays short. |
| Operator | `Operator/waiting.php` | Waiting riders for the operator's own buses, plus a live map of those buses. The Riders page is a seat map only. |
| Admin | `Admin/waiting.php` | The same board for every bus and every waiting rider. Admin dashboard and schedule pages no longer link to separate waiting screens. |

Commuter navigation is Home / Schedule / Live / Trips / Waiting. Live is tracking only: it lists current-day **Boarding** or **Departed** buses, defaults to a bus that is actually sharing GPS, and labels stale points instead of pretending they are current. Notifications are reached from the profile menu as **Updates**.

## Location gate

`gps.php` is shown only to operators before the operator dashboard. It uses the MBUS light surface, blue primary action and brief copy; **Enable location** invokes the browser permission prompt, stores `mbus_gps=1`, and returns to the requested operator page (`?next=` is validated to in-app paths). Commuters and admins do not need to grant location and never see this gate. Browsers only expose location over HTTPS or `localhost`.

## Operator side

Once the gate has passed, `Assets/js/pickup_staff.js` starts sharing automatically on the operator dashboard without a visible map, badge, location card or trip selector. Status is available to assistive technology only; the app picks the active trip automatically and posts at most every 8.5 seconds. On `Operator/manage_trips.php` the script sends one immediate fix before watching, so changing a trip to **Departed** pins the bus location right away; the page also shows a short "Departed — pinning your location" banner. Sharing pauses when the tab is hidden and resumes when it returns.

## API

`api/live_tracking.php` reads `mbus_tracking_feed()` and writes through `mbus_tracking_mutate()` in `Includes/bus_tracking.php`.

- `GET` returns `schedules`, `locations` (every active trip's latest bus point, scoped to the operator's buses when the caller is an operator), `location` for the selected trip, and `requests` for staff.
- For commuters it also returns `my_request`, the rider's own waiting signal regardless of which bus is selected, plus `waiting` for the selected trip.
- `POST` actions are `update_location`, `mark_waiting`, `cancel_waiting` and `picked_up`, all CSRF-protected.

A waiting signal expires 30 minutes after it was shared and is scoped to one trip. Only the chosen point, optional stop name and destination are visible to staff.

## Checks

```bash
node tests/waiting_ux_test.cjs
node tests/operator_live_tracking_test.cjs
node tests/commuter_live_tracking_test.cjs
MBUS_TRACKING_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/tracking_integration.php
MBUS_PICKUP_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/pickup_integration.php
```

The two PHP suites refuse to run unless the database is the dedicated local `mbus_pickup_test`.
