/* Regression coverage for live route creation and operator bus setup submissions. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const route = read('Admin/manage_routes.php');
assert.match(route, /isset\(\$_POST\['update_fare'\]\)/,
    'the route page should expose only the fare-update action');
assert.match(route, /UPDATE routes SET fare = \? WHERE route_id = \?/,
    'the update query must change only fare for the selected route');
assert.match(route, /SELECT \* FROM routes ORDER BY route_id ASC/,
    'the page should preserve and display the existing route records');
assert.match(route, /name="fare"/,
    'existing route fares should be editable');
assert.doesNotMatch(route, /INSERT INTO routes|DELETE FROM routes|\$_POST\['add_route'\]|\$_POST\['delete_route'\]|<h2>Add Route<\/h2>/,
    'route creation and deletion must not be available from this page');
assert.doesNotMatch(route, /name="origin"|name="destination"|routeMap|routeEstimate/,
    'route endpoints and map editing must be removed from the fare-only screen');
const schedules = read('Admin/manage_schedules.php');
assert.match(schedules, /departure->format\('Y-m-d'\) < date\('Y-m-d'\)/,
    'Admin must reject schedule departures before today');
assert.match(schedules, /Past departure dates are not allowed/,
    'Admin must explain why a past departure is rejected');
assert.match(schedules, /name="departure_time" min="<\?php echo date\('Y-m-d'\); \?>T00:00"/,
    'the schedule form must prevent selecting a past departure date');
assert.match(route, /Only this route’s fare can be changed/,
    'the interface should explain the fare-only edit scope');

const db = read('config/db_connect.php');
for (const column of ['origin_lat DECIMAL(10, 8)', 'origin_lng DECIMAL(11, 8)', 'destination_lat DECIMAL(10, 8)', 'destination_lng DECIMAL(11, 8)']) {
    assert.ok(db.includes(column), `runtime schema upgrade must add ${column}`);
}
assert.match(db, /schemaVersion\s*=\s*'2026-11-(2[3-9]|30)[a-z]'/,
    'the runtime schema version must advance so existing deployments run the new upgrades');

const registration = read('register.php');
for (const field of ['name="bus_photo"', 'name="interior_photo"']) {
    assert.ok(registration.includes(field), `registration should include ${field}`);
}
assert.ok(!registration.includes('name="seat-layout"'), 'seat layout now lives on the My Bus page');
const myBus = read('Operator/my_bus.php');
for (const field of ['name="seat-layout"', 'data-seat-builder']) {
    assert.ok(myBus.includes(field), `My Bus page should include ${field}`);
}

const setupJs = read('Assets/js/driver_bus_setup.js');
assert.match(setupJs, /data-seat-add-row/);
assert.match(setupJs, /data-seat-reset/);
assert.match(setupJs, /JSON\.stringify\(layout\)/,
    'seat-map changes must be serialized into the application form');
assert.match(setupJs, /total > MAX_SEATS/,
    'the client must enforce the maximum seating capacity');
assert.match(setupJs, /const MAX_SEATS = 40, MAX_DOORS = 1/,
    'the builder must use the 40-seat and one-door limits');
assert.match(setupJs, /wouldCreateAdditionalDoor/,
    'the builder must block creating an additional door before changing the seat');
assert.match(setupJs, /Maximum of 1 door reached/,
    'the builder must show an inline one-door warning');
assert.match(registration, /\$maxPassengerSeats = MBUS_MAX_SEATS - 2/,
    'registration must leave two layout positions for later door conversion');
assert.match(registration, /mbus_seat_layout_with_door_room\(\$seats\)/,
    'registration must store two additional layout seats');
const myBusPage = read('Operator/my_bus.php');
assert.match(myBusPage, /mbus_bus_schedule_locked/,
    'My Bus must check whether its bus is on an active schedule');
assert.match(myBusPage, /cannot be changed until that schedule is completed/,
    'My Bus must explain the schedule layout lock inline');
/* The driver's cell is part of the bus design: the builder never deletes front-row seats to make room for it,
   and every seat map (My Bus, Riders, commuter) shows the same driver and door tiles. */
const layoutHelper = read('Includes/seat_layout.php');
assert.match(layoutHelper, /function mbus_seat_rows_driver_inside/,
    'seat maps must be able to tell whether the driver tile fits inside the front row');
assert.match(layoutHelper, /\$row = \[false, false, false, false\]/,
    'a plain layout must keep the front-left pair free for the driver');
assert.match(setupJs, /function driverInside\(\)/,
    'the builder must detect a front row that still holds seats instead of clearing them');
assert.doesNotMatch(setupJs, /lockDriver/,
    'the builder must not delete front-row seats to place the driver');
assert.match(setupJs, /classList\.add\('seat-front'\)|className = 'seat-row seat-front'/,
    'the builder must draw a driver row above a bus that keeps its front seats');
assert.match(setupJs, /seat-back-center/,
    'the five-seat rear bench must keep the aisle and place its fifth seat in the centered rear position');
assert.match(read('Assets/css/main/driver_bus_setup.css'), /seat-row\.seat-back.*grid-template-columns:repeat\(2,48px\) 24px repeat\(2,48px\)/,
    'the rear bench must retain two seats, a center aisle, and two seats');
assert.match(read('Operator/passengers.php'), /seat-row seat-front/,
    'the Riders map must show the driver tile for every bus design');
assert.match(read('Commuter/reserve.php'), /bm-front-row/,
    'the commuter map must show the driver tile for every bus design');

const applications = read('Admin/operator_applications.php');
for (const field of ['has_bus_photo', 'has_interior_photo', 'seat_layout', "['bus',      'Bus exterior'", "['interior', 'Bus interior'"]) {
    assert.ok(applications.includes(field), `Admin review should expose ${field}`);
}
assert.match(applications, /Submitted seat layout/);
assert.doesNotMatch(applications, /Create an admin account|add_admin/i,
    'Operator Applications must not retain the extra Admin creation path');

const fileEndpoint = read('Admin/application_file.php');
assert.match(fileEndpoint, /'bus'\s*=>\s*'bus_photo'/);
assert.match(fileEndpoint, /'interior'\s*=>\s*'interior_photo'/);
assert.match(fileEndpoint, /\$_SESSION\['user_type'\].*admin/,
    'application photo access must remain Admin-only');

console.log('PASS fare-only fixed-route editing, runtime route schema, bus photo submissions, custom seat layouts, and Admin review');
