const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const reservePhp = read('Commuter/reserve.php');
const reserveJs = read('Assets/js/commuter_reserve.js');
const staffJs = read('Assets/js/staff_waiting.js');
const feed = read('Includes/pickup.php');
const db = read('config/db_connect.php');
const schema = read('supabase/01_schema.sql');
const migration = read('supabase/19_reservation_stop_coordinates.sql');

// Reservations keep named pickup and destination stops, but no longer support drop-off map pins.
assert.doesNotMatch(reserveJs, /reservationMap|seatPins|stop_pins|Drop-off/);
assert.doesNotMatch(reservePhp, /stop_pins|destination_latitude|destination_longitude|drop-off map pins/);
assert.match(feed, /AS seat_details/);
assert.match(feed, /pickup_latitude/);
assert.doesNotMatch(feed, /destination_latitude|destination_longitude/);
assert.match(staffJs, /reservationSeatPoint/);
assert.doesNotMatch(staffJs, /drop-off|kind === 'drop'|\['pick','drop'\]/i);
assert.match(staffJs, /hiddenReservationKeys/);
assert.match(staffJs, /sw-res-map-toggle/);
assert.match(staffJs, /trackPin/);
for (const column of ['pickup_latitude','pickup_longitude','destination_latitude','destination_longitude']) {
    assert.match(schema, new RegExp(column));
    assert.match(migration, new RegExp(column));
    assert.match(db, new RegExp(column));
}
console.log('PASS reservation map pins: per-seat input/storage, staff feed/map, account visibility, and schema upgrade');
