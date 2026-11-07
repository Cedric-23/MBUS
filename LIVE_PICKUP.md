# Waiting riders, live bus location and the location gate

After the existing MBUS schema, apply the additive migrations `supabase/10_live_pickup.sql` and `supabase/11_bus_live_tracking.sql` in order. Migration 10 stores the latest commuter waiting signal; migration 11 stores the latest operator GPS point for each trip. Both preserve schedules, reservations, accounts and payments, and reapplying is safe. Apply `supabase/13_route_terminal_coordinates.sql` as well to persist the canonical Morong and Harbor Point route pins. A Git push does not run database migrations.

## One Waiting tab, three roles

Every bottom navigation bar now has **Waiting** where **Alerts** used to be. Nothing else in the app offers a waiting control.

| Role | Page | Behaviour |
|------|------|-----------|
| Commuter | `Commuter/waiting.php` | Choose a bus first, then pin the pickup spot using current GPS or by tapping/dragging the visible map pin. Confirm **Mark me as waiting** to share the point. The status card shows the bus, stop, shared time and expiry; **Track the bus** opens that trip in Live, with its road path shown. **Cancel waiting** removes the signal. There is no update action. |
| Operator | `Operator/waiting.php` | Waiting riders for the operator's own buses, plus a live map of those buses. The Riders page is a seat map only. |
| Admin | `Admin/waiting.php` | The same board for every bus and every waiting rider. Admin dashboard and schedule pages no longer link to separate waiting screens. |

Commuter navigation is Home / Schedule / Live / Trips / Waiting. Live is tracking only: it lists current-day **Boarding** or **Departed** buses, selects a live bus by default, and shows its full road path with **Focus bus** available for a closer view. If a Waiting deep-link refers to a trip that has ended, Live says so instead of silently switching to another bus; commuters can deliberately choose another bus. Stale points are labeled instead of appearing current. Schedule **Pick up** actions open Waiting with that bus preselected. Notifications are reached from the profile menu as **Updates**.

## Location gate

`gps.php` is shown only to operators before the operator dashboard. It uses the MBUS light surface, blue primary action and brief copy; **Enable location** invokes the browser permission prompt, stores `mbus_gps=1`, and returns to the requested operator page (`?next=` is validated to in-app paths). Commuters and admins do not need to grant location and never see this gate. Browsers only expose location over HTTPS or `localhost`.

## Operator side

Once the gate has passed, `Assets/js/pickup_staff.js` starts sharing automatically on the operator dashboard without a visible map, badge, location card or trip selector. Status is available to assistive technology only; the app picks the active trip automatically and posts at most every 8.5 seconds. On `Operator/manage_trips.php` the script sends one immediate fix before watching, so changing a trip to **Departed** pins the bus location right away; the page also shows a short "Departed — pinning your location" banner. Sharing pauses when the tab is hidden and resumes when it returns.

## Road route and destination estimate

Commuter Schedule/Live and the Operator/Admin Waiting map draw OSRM-compatible road geometry over OpenStreetMap tiles. The canonical Morong endpoint is **Morong Bus Terminal** (`14.6798552, 120.2728757`). The SBMA endpoint is the public-facing **SBMA E-bus stop — in front of National Bookstore, Harbor Point** (`14.8243652, 120.2801434`). The Harbor Point pin identifies the mapped storefront/terminal frontage; it is not a surveyed curb coordinate.

The road router supplies route geometry, road distance and estimated driving duration. For the researched endpoint pair, the current public OSRM demo response is about **31.0 km** and **31 minutes**. Live destination estimates project the latest bus pin onto that road route and scale the route estimate by remaining distance. These are road-network estimates, not live-traffic or scheduled bus-arrival predictions; traffic, stops, closures, boarding time and detours can change the actual arrival. The moving operator GPS point is not sent to the routing provider. The default public OSRM demo is best-effort only; set `MBUS_ROUTING_ENDPOINT` to a managed or self-hosted HTTPS OSRM-compatible endpoint for sustained production use. See [terminal coordinates, source notes and routing limitations](docs/route-terminal-research.md).

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
node tests/route_accuracy_test.cjs
MBUS_TRACKING_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/tracking_integration.php
MBUS_PICKUP_TEST_DSN='pgsql:host=/var/run/postgresql;dbname=mbus_pickup_test' php tests/pickup_integration.php
```

The two PHP suites refuse to run unless the database is the dedicated local `mbus_pickup_test`.
