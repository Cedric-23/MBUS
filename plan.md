# MBUS commuter Waiting board redesign

## Goal

Redesign only the commuter **Waiting** navigation page to mirror the operator Waiting map/list in reverse. Keep **Live** limited to live bus markers and the bus's road path; do not add a commuter pickup marker, cancellation action, or waiting-specific UI to Live.

## Product behavior

- Commuter Waiting uses a phone-first full-map layout with a compact header and bottom sheet, following the supplied operator-board screenshot's visual structure.
- The map and bus row appear only after the commuter has marked themselves as waiting for a bus. Before that, Waiting shows a concise empty state and a single path back to the schedule/pickup flow; it does not list buses or pre-open the tracking board.
- The map displays the selected bus marker and, when the commuter is already waiting for that trip, the commuter's saved pickup/profile-photo marker. A dashed connection and clearly labeled direct distance show pickup-to-bus proximity.
- Selecting/tapping the waiting bus row or map pin toggles tracking on and off, like the operator's Waiting selection toggle. When off, keep a muted bus pin available to reselect, hide its road-path emphasis, and stop recentering the map; when on, show the bus and pickup together and follow the selected bus. A compact route estimate uses the existing OSRM road route and route-progress utilities. **Full route / Focus bus** toggles map bounds while tracking is on.
- The Waiting board contains only the bus for the commuter's active waiting request. The board's request row offers **Cancel**. Choosing a bus and pinning happens in the existing schedule/pickup flow before the board is shown; another bus cannot replace a still-valid request without canceling first. Cancellation remains available for a valid request if its trip is no longer active.
- Operator bottom navigation is **Home / Trips / Scan / Riders / Waiting**, with **Scan** in the center and no **Live** tab. Retire the legacy operator Live-map route from navigation and redirect its old URL to Scan.
- Keep the existing role, CSRF, polling, expiry and map-access rules. Use the authenticated live-tracking feed and existing pickup mutations; add no new GPS sharing or external tracking behavior.

## Implementation approach and project structure

- `Commuter/waiting.php`: replace the status-only card with Waiting-specific map, status pill, route estimate, route-view toggle and bus list; retain commuter navigation and account menu.
- `Assets/js/commuter_waiting_board.js`: track/untrack selection, live-feed polling, bus/pickup markers, route geometry/ETA, list actions and cancellation.
- `Assets/css/commuter_waiting_board.css`: responsive full-map / bottom-sheet treatment and compact map/list markers.
- Reuse `Assets/js/route_utils.js`, `api/live_tracking.php`, `api/route.php`, `Commuter/pickup.php`, and `Includes/bus_tracking.php` rather than duplicating tracking or pickup logic.
- Restore only the unintended commuter Waiting extras from the last Live-specific change so `Commuter/live.php`, `Assets/js/commuter_live.js`, `Assets/css/commuter_live.css`, and `Assets/js/live_schedule_selection.js` retain their preceding bus-only route-tracking behavior.
- Restore the canonical operator navigation in all bottom-nav pages and retire `Operator/live_map.php` as a Live destination.
- Update focused regressions and the user-facing README/LIVE_PICKUP documentation to describe the Waiting-only reverse board and operator Scan placement.

## Design direction

Use the supplied MBUS operator Waiting screen as the approved visual reference: neutral map canvas, a white elevated bottom sheet, restrained navy/blue controls, green live-status indicator, compact avatar/bus marker pair, and clear one-tap route-view control. Keep tap targets comfortable on phones, avoid crowded explanatory copy, and let the map remain the primary surface.

## Constraints

The existing PHP/PostgreSQL implementation and Leaflet map are retained. OSRM road geometry and estimates depend on the existing authenticated route proxy; pickup-to-bus proximity is a straight-line distance and must be labeled as such. Do not alter the operator/admin Waiting boards or commuter Live tracking beyond keeping Live bus-only.
