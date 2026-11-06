/* Waiting rename, commuter UX, staff placement and location gate.
 * Run: node tests/waiting_ux_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const commuterWaiting = read('Commuter/waiting.php');
const waitingJs = read('Assets/js/commuter_waiting.js');
const notifications = read('Commuter/notifications.php');
const operatorWaiting = read('Operator/waiting.php');
const adminWaiting = read('Admin/waiting.php');
const staffView = read('Includes/pickup_staff_view.php');
const boardJs = read('Assets/js/admin_waiting.js');
const riders = read('Operator/passengers.php');
const adminDash = read('Admin/admin_dashboard.php');
const adminSchedules = read('Admin/manage_schedules.php');
const tracking = read('Includes/bus_tracking.php');
const gate = read('gps.php');
const gateInclude = read('Includes/gps_gate.php');

// --- 1. Alerts became Waiting everywhere
const navPages = [
    'Admin/admin_dashboard.php', 'Admin/manage_buses.php', 'Admin/manage_operators.php',
    'Commuter/commuter_dashboard.php', 'Commuter/live.php', 'Commuter/my_reservations.php',
    'Commuter/schedule.php', 'Operator/operator_dashboard.php', 'Operator/manage_trips.php',
    'Operator/scan.php', 'Operator/passengers.php',
];
for (const page of navPages) {
    const html = read(page);
    assert.doesNotMatch(html, />Alerts</, `${page} still labels the tab Alerts`);
    assert.match(html, /href="waiting\.php"[^>]*class="bottom-nav-item|class="bottom-nav-item"[^>]*href="waiting\.php"/, `${page} has no Waiting tab`);
    assert.match(html, /<span>Waiting<\/span>/, `${page} Waiting tab label missing`);
}

// --- 2. Commuter waiting page is status-first and brief
assert.match(commuterWaiting, /id="waitState"/);
assert.match(commuterWaiting, /id="waitStateTitle"/);
assert.match(commuterWaiting, /id="waitShare"/);
assert.match(commuterWaiting, /id="waitCancel"/);
assert.match(commuterWaiting, /id="waitPin"/);
assert.match(commuterWaiting, /Pin your pickup spot/);
assert.doesNotMatch(commuterWaiting, /waitUpdate|Update my spot/);
assert.match(commuterWaiting, /id="waitTrips"/);
assert.match(commuterWaiting, /<h1>Waiting<\/h1>/);
assert.match(commuterWaiting, /aria-current="page"/);
// no leftover long explanatory wall of text
assert.doesNotMatch(commuterWaiting, /30.?minute window|Share your waiting point with its operator/i);
const visibleCopy = commuterWaiting.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim();
assert.ok(visibleCopy.split(' ').length < 120, `waiting page copy too long: ${visibleCopy.split(' ').length} words`);

// sharing only happens on an explicit tap, then the state card shows the request
assert.match(waitingJs, /getCurrentPosition/);
assert.match(waitingJs, /action: 'mark_waiting'/);
assert.match(waitingJs, /action: 'cancel_waiting'/);
assert.match(waitingJs, /Mark me as waiting/);
assert.match(waitingJs, /if \(!state\.selected\) \{ flash\('Choose a bus first\.'/);
assert.match(waitingJs, /if \(!point\) throw new Error\('Pin your pickup spot first\.'\)/);
assert.doesNotMatch(waitingJs, /resituate|waitUpdate|Spot updated/);
assert.match(waitingJs, /Your previous wait expired/);
assert.match(waitingJs, /data\.my_request/);
assert.match(waitingJs, /waitShare'\)\.addEventListener\('click', share\)/);
assert.match(waitingJs, /renderState\(\)/);
assert.match(waitingJs, /15000/);

// the old waiting picker is gone from the notifications page
assert.doesNotMatch(notifications, /waitingSchedule|waitBtn|commuter_waiting_alerts\.js|live_pickup\.css/);
assert.match(notifications, /<h1>Updates<\/h1>/);

// --- 3. Operator riders page no longer hosts waiting features
assert.doesNotMatch(riders, /riderLive|liveMap|waitingList|pickup_staff\.js|live_tracking\.php|leaflet/i);
assert.match(riders, /Waiting/);

// --- 4. Operator and admin waiting live in their own navigation page
assert.match(operatorWaiting, /\$pickupStaffRole = 'operator'/);
assert.match(adminWaiting, /\$pickupStaffRole = 'admin'/);
assert.match(staffView, /<h1>Waiting<\/h1>/);
assert.match(staffView, /id="staffMap"/);
assert.match(staffView, /id="staffList"/);
assert.match(staffView, /banda|Live map/);
assert.match(staffView, /class="bottom-nav-item active" aria-current="page"/);
assert.doesNotMatch(adminDash, /Waiting pickups/);
assert.doesNotMatch(adminSchedules, /Waiting pickups/);

// board shows operator GPS positions and rider pins
assert.match(boardJs, /data-live-endpoint|liveEndpoint/);
assert.match(boardJs, /locations/);
assert.match(boardJs, /circleMarker/);
assert.match(boardJs, /marker\(point/);

// --- 5. GPS gate before the dashboards
assert.match(gate, /Turn on location/);
assert.match(gate, /id="gateEnable"/);
assert.match(gate, /navigator\.geolocation\.getCurrentPosition/);
assert.match(gateInclude, /mbus_gps/);
assert.match(gateInclude, /header\('Location: ' \. \(\$base \?: ''\) \. '\/gps\.php\?next='/);
assert.doesNotMatch(read('Commuter/commuter_dashboard.php'), /mbus_require_gps|gps_gate/);
assert.doesNotMatch(read('Admin/admin_dashboard.php'), /mbus_require_gps|gps_gate/);
assert.match(read('Operator/operator_dashboard.php'), /mbus_require_gps\(/);
assert.match(gate, /if \(\$role !== 'operator'\)/);
assert.match(gate, /So commuters can see your bus/);
const gateWords = gate.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim().split(' ').length;
assert.ok(gateWords < 60, `gate copy too long: ${gateWords} words`);

// --- 6. Tracking API exposes every live bus and the rider's own request
assert.match(tracking, /'locations' => \$locations/);
assert.match(tracking, /'my_request' => \$myRequest/);
assert.match(tracking, /public\.bus_live_tracking/);
assert.match(read('Commuter/schedule.php'), /action === 'pickup' \? 'waiting\.php' : 'reserve\.php'/);
assert.match(read('Commuter/reserve.php'), /Location: waiting\.php\?schedule_id=/);
assert.doesNotMatch(read('Commuter/live.php'), /Waiting for a bus/);

console.log('PASS waiting rename, brief commuter UX, staff waiting page, operator-only GPS gate and live bus payload');
