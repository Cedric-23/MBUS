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
assert.match(route, /Only this route’s fare can be changed/,
    'the interface should explain the fare-only edit scope');

const db = read('config/db_connect.php');
for (const column of ['origin_lat DECIMAL(10, 8)', 'origin_lng DECIMAL(11, 8)', 'destination_lat DECIMAL(10, 8)', 'destination_lng DECIMAL(11, 8)']) {
    assert.ok(db.includes(column), `runtime schema upgrade must add ${column}`);
}
assert.match(db, /schemaVersion\s*=\s*'2026-11-23a'/,
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
assert.match(setupJs, /total > 100/,
    'the client must enforce the maximum seating capacity');

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
