# Commuter live bus tracking and ticket update

- [x] Downloaded PDF tickets include the same signed, scannable boarding QR code as the on-screen ticket, with a readable ticket code and enough space to avoid clipping.
- [x] Commuter bottom navigation is Home / Schedule / Live / Trips / Alerts, with Live centered next to Schedule and linked to the bus-tracking map.
- [x] Reserve seat becomes Pick up on the schedule when a trip has departed, is inside the existing 30-minute reservation cutoff, or no reservation seats remain. The Live page does not arrange pickups.
- [x] Live is a bus-tracking map, not a pickup-arrangement page. Commuters can choose a current Boarding/Departed trip, view its route and latest operator GPS point, and explicitly share their own waiting location for that trip. Operators can see active waiting commuters within the Riders page only.
- [x] When an operator opens Manage Trips with an assigned trip in Boarding or Departed status, the page asks the browser for location permission and shares GPS updates while visible. Location tracking pauses in the background and stops when the page closes. Riders may wait without an existing reservation. Admin waiting remains in its admin workspace.
- [x] The changes include an additive database migration, local-only tests and setup instructions. Applying migrations 10 and 11 to Supabase remains a manual deployment prerequisite; they have not been run against production.
