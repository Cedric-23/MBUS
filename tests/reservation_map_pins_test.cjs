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

assert.doesNotMatch(reservePhp, /id="reservationMap"/); // map pin picker removed from the reserve page
assert.match(reservePhp, /name="stop_pins"|stop_pins/);
assert.match(reservePhp, /pickup_latitude,pickup_longitude,destination_latitude,destination_longitude/);
assert.match(reservePhp, /Set both map pins or clear both pins/);
assert.match(reserveJs, /const seatPins = \{\}/);
assert.match(reserveJs, /reservationMap\.on\("click"/);
assert.match(reserveJs, /Boolean\(seatPins\[seat\]\?\.pickup\) !== Boolean\(seatPins\[seat\]\?\.destination\)/);
assert.match(reserveJs, /formData\.append\("stop_pins", JSON\.stringify\(seatPins\)\)/);
assert.match(feed, /AS seat_details/);
assert.match(feed, /pickup_latitude/);
assert.match(staffJs, /reservationSeatPoint/);
assert.match(staffJs, /hiddenReservationKeys/);
assert.match(staffJs, /sw-res-map-toggle/);
assert.match(staffJs, /sw-seat-list/);
for (const column of ['pickup_latitude','pickup_longitude','destination_latitude','destination_longitude']) {
    assert.match(schema, new RegExp(column));
    assert.match(migration, new RegExp(column));
    assert.match(db, new RegExp(column));
}
console.log('PASS reservation map pins: per-seat input/storage, staff feed/map, account visibility, and schema upgrade');
