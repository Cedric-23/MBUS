# Commuter live bus tracking and ticket update

- [x] Downloaded PDF tickets include the same signed, scannable boarding QR code as the on-screen ticket, with a readable ticket code and enough space to avoid clipping.
- [x] Commuter bottom navigation is Home / Schedule / Live / Trips / Alerts, with Live centered next to Schedule and linked to the bus-tracking map.
- [x] Reserve seat becomes Pick up on the schedule when a trip has departed, is inside the existing 30-minute reservation cutoff, or no reservation seats remain. The Live page does not arrange pickups.
- [x] Live is tracking-only. Commuters can choose a current Boarding/Departed bus and view its route and latest operator GPS point. The waiting-location picker and sharing action are in Alerts; operators see active waiting riders within the Riders page only.
- [x] After login, operators explicitly enable GPS and grant browser location permission on the Dashboard. Their GPS pin is visible there, but it is uploaded only for an assigned Boarding/Departed trip. Sharing pauses in the background and stops when the page closes. Admin waiting remains in its admin workspace.
- [x] The changes include an additive database migration, local-only tests and setup instructions. Applying migrations 10 and 11 to Supabase remains a manual deployment prerequisite; they have not been run against production.
