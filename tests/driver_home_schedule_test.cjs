/* Driver home: bus + conductor, today's schedule highlighted, every upcoming schedule listed.
 * Run: node tests/driver_home_schedule_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const dashboard = read('Operator/operator_dashboard.php');
const helper = read('Includes/driver_home.php');
const css = read('Assets/css/driver_home.css');

// The dashboard renders all three blocks from the shared helper and loads its stylesheet.
assert.match(dashboard, /Includes\/driver_home\.php/);
assert.match(dashboard, /mbus_driver_home_today_html\(/);
assert.match(dashboard, /mbus_driver_home_buses_html\(/);
assert.match(dashboard, /mbus_driver_home_schedule_html\(/);
assert.match(dashboard, /driver_home\.css/);

// Scope: only the signed-in driver's buses, and the conductor comes from the bus assignment (no schema change).
assert.match(helper, /oba\.operator_id = \?/);
assert.match(helper, /LEFT JOIN conductor_bus_assignments cba ON cba\.bus_id = b\.bus_id/);
assert.match(helper, /schedule_status = 'Active'/);
assert.match(helper, /s\.departure_time >= \?/);

// Output is escaped, and today's rows are visually highlighted.
assert.doesNotMatch(helper, /echo\s+\$/);
assert.match(helper, /function mbus_dh_h\(/);
assert.match(helper, /Scheduled today/);
assert.match(helper, /No conductor yet/);
assert.match(css, /\.dh-day\.is-today/);

// Existing dashboard guarantees stay in place.
assert.match(dashboard, /mbus_require_gps\(/);
assert.match(dashboard, /class="tracking-sr-only"/);
console.log('driver_home_schedule_test: ok');
