# Commuter live pickup and ticket update

- [x] Downloaded PDF tickets include the same signed, scannable boarding QR code as the on-screen ticket, with a readable ticket code and enough space to avoid clipping.
- [x] Commuter bottom navigation is Home / Schedule / Live / Trips / Alerts, with Live centered next to Schedule and linked to the map/pickup screen.
- [x] Reserve seat becomes Pick up when a trip has departed, is inside the existing 30-minute reservation cutoff, or no reservation seats remain. The server enforces the same rules; completed, arrived, cancelled and stale trips are unavailable. Pickup does not promise a reserved seat.
- [x] Pickup provides a map UI where commuters can choose a trip, mark a waiting location (GPS, map pin, or manual coordinates), choose a destination and confirm waiting. They can update or cancel their own request and see its status.
- [x] Operators see active waiting commuters only for their assigned buses, and admins see all active waiting commuters, including map locations, destination and trip. Staff can mark requests picked up; expired requests and terminal trips stop appearing as active waiting.
- [x] The changes include an additive database migration, local-only tests and setup instructions. Changes are ready to commit and push to branch ash. Applying the migration to Supabase remains a manual deployment prerequisite; it has not been run against production.
