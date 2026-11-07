/* Waiting UX: pin flow, status page, staff board and operator-only GPS gate.
 * Run: node tests/waiting_ux_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const commuterWaiting = read('Commuter/waiting.php');
const waitingBoardJs = read('Assets/js/commuter_waiting_board.js');
const pickupPage = read('Commuter/pickup.php');
const pickupJs = read('Assets/js/commuter_pickup.js');
const notifications = read('Commuter/notifications.php');
const operatorWaiting = read('Operator/waiting.php');
const adminWaiting = read('Admin/waiting.php');
const staffView = read('Includes/pickup_staff_view.php');
const boardJs = read('Assets/js/staff_waiting.js');
const commuterLive = read('Assets/js/commuter_live.js');
const commuterLivePage = read('Commuter/live.php');
const riders = read('Operator/passengers.php');
const adminDash = read('Admin/admin_dashboard.php');
const adminSchedules = read('Admin/manage_schedules.php');
const tracking = read('Includes/bus_tracking.php');
const gate = read('gps.php');
const gateInclude = read('Includes/gps_gate.php');

// --- 1. Alerts became Waiting navigation everywhere
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

// --- 2. Waiting is empty until the commuter has marked themselves waiting; pickup pinning stays separate
assert.match(commuterWaiting, /id="cwbEmpty"/);
assert.match(commuterWaiting, /id="cwbMap"[^>]*hidden/);
assert.match(commuterWaiting, /id="cwbSheet"[^>]*hidden/);
assert.match(commuterWaiting, /<h1>Waiting<\/h1>/);
assert.match(commuterWaiting, /aria-current="page"/);
assert.doesNotMatch(commuterWaiting, /pickupMap|Mark me as waiting|Update my spot/);
assert.match(waitingBoardJs, /myRequest\.status !== 'Waiting'/);
assert.match(waitingBoardJs, /showEmpty\(\)/);
assert.match(waitingBoardJs, /personIcon/);
assert.match(waitingBoardJs, /busIcon/);
assert.match(waitingBoardJs, /getDrivingRoute/);
assert.match(waitingBoardJs, /remainingOnRoute/);
assert.match(waitingBoardJs, /toggleRoute/);
assert.match(waitingBoardJs, /Cancel/);
assert.match(waitingBoardJs, /Straight-line distance to the bus/);
assert.match(waitingBoardJs, /Request timed out/);
assert.match(waitingBoardJs, /selectedBusLocation && selectedBusLocation\.fresh/);
assert.match(waitingBoardJs, /busMarker\.on\('click',[\s\S]*?updateRouteButton\(\)/);
assert.doesNotMatch(waitingBoardJs, /Pick up/);
const visibleCopy = commuterWaiting.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim();
assert.ok(visibleCopy.split(' ').length < 120, `waiting status page copy too long: ${visibleCopy.split(' ').length} words`);

assert.match(pickupPage, /id="pickupMap"/);
assert.match(pickupPage, /id="pickupLocate"/);
assert.match(pickupPage, /id="pickupWait"/);
assert.match(pickupPage, /profileImage/);
assert.match(pickupPage, /\$trip\['bus_number'\]/);
assert.match(pickupJs, /navigator\.geolocation\.getCurrentPosition/);
assert.match(pickupJs, /action: 'mark_waiting'/);
assert.match(pickupJs, /action: 'cancel_waiting'/);
assert.match(pickupJs, /draggable: !waiting/);
assert.match(pickupJs, /You\'re marked as waiting/);
assert.match(pickupJs, /pickupWait/);
assert.match(pickupJs, /waitingElsewhere\(\)/);
assert.match(pickupJs, /Cancel current waiting/);
assert.match(pickupJs, /window\.location\.href = 'waiting\.php'/);

// The Updates page no longer embeds a second waiting picker.
assert.doesNotMatch(notifications, /waitingSchedule|waitBtn|commuter_waiting_alerts\.js|live_pickup\.css/);
assert.match(notifications, /<h1>Updates<\/h1>/);

// --- 3. Operator riders page is only the seat map; Waiting is its separate navigation page
assert.doesNotMatch(riders, /riderLive|liveMap|waitingList|pickup_staff\.js|live_tracking\.php|leaflet/i);
assert.match(riders, /Waiting/);
assert.match(operatorWaiting, /\$pickupStaffRole = 'operator'/);
assert.match(adminWaiting, /\$pickupStaffRole = 'admin'/);
assert.match(staffView, /<h1>Waiting<\/h1>/);
assert.match(staffView, /id="staffMap"/);
assert.match(staffView, /id="staffList"/);
assert.match(staffView, /id="staffRouteEstimate"/);
assert.match(staffView, /id="staffRouteView"/);
assert.match(staffView, /route_utils\.js/);
assert.match(staffView, /class="bottom-nav-item active" aria-current="page"/);
assert.doesNotMatch(adminDash, /Waiting pickups/);
assert.doesNotMatch(adminSchedules, /Waiting pickups/);
assert.match(boardJs, /liveEndpoint/);
assert.match(boardJs, /locations/);
assert.match(boardJs, /personIcon/);
assert.match(boardJs, /toggleStaffRoute/);
assert.match(boardJs, /remainingOnRoute/);
assert.doesNotMatch(commuterLive, /pickupAction|cancel_waiting|commuterLayer|Your waiting pickup|pickup\.php|Pick up/);
assert.doesNotMatch(commuterLivePage, /dot-commuter|Pickup distance|profileImage/);
assert.match(tracking, /A commuter must still be able to clear their waiting state after the trip/);

// --- 4. GPS gate is operator-only and precedes the operator dashboard
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

// --- 5. The shared API carries bus locations and a commuter's own Waiting request
assert.match(tracking, /'locations' => \$locations/);
assert.match(tracking, /'my_request' => \$myRequest/);
assert.match(tracking, /public\.bus_live_tracking/);
assert.match(read('Commuter/schedule.php'), /\$action === 'pickup' \? 'pickup\.php' : 'reserve\.php'/);
assert.match(read('Commuter/reserve.php'), /Location: pickup\.php\?schedule_id=/);
assert.doesNotMatch(read('Commuter/live.php'), /Waiting for a bus/);

console.log('PASS commuter Waiting-only map, separate pickup pin flow, staff board and operator-only GPS gate');
