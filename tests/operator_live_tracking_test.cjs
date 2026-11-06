/* Operator location sharing: auto-start, depart auto-pin, no waiting UI on riders.
 * Run: node tests/operator_live_tracking_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const manage = read('Operator/manage_trips.php');
const riders = read('Operator/passengers.php');
const dashboard = read('Operator/operator_dashboard.php');
const script = read('Assets/js/pickup_staff.js');
const waitingBoard = read('Includes/pickup_staff_view.php');
const waitingBoardScript = read('Assets/js/admin_waiting.js');

// trip page keeps status updates and focuses the bus that just departed
assert.match(manage, /data-trip-status=/);
assert.match(manage, /OPERATOR_TRACK_SCHEDULE_ID/);
assert.match(manage, /\$departed_now = \(\$trip_status === 'Departed'\)/);
assert.match(manage, /\$track_focus \?: \$open_schedule/);
assert.match(manage, /id="departingBanner"/);
assert.match(manage, /gps_status\.css/);
assert.match(manage, /pickup_staff\.js/);
assert.match(manage, /csrf_get_token\(\)/);

// dashboard shares automatically once the location gate allowed it
assert.match(dashboard, /Includes\/csrf\.php/);
assert.match(dashboard, /window\.MBUS_GPS_ENABLED = true/);
assert.match(dashboard, /class="tracking-sr-only"/);
assert.match(dashboard, /id="operatorTrackingStatus"/);
assert.doesNotMatch(dashboard, /operatorGpsMap|operatorTrackingBadge|leaflet@1\.9\.4|Bus location/);
assert.match(dashboard, /gps_status\.css/);
assert.doesNotMatch(dashboard, /Enable GPS for bus tracking/);

// script: immediate fix then watch, foreground only, short status copy
assert.match(script, /getCurrentPosition/);
assert.match(script, /watchPosition/);
assert.match(script, /clearWatch/);
assert.match(script, /document\.hidden/);
assert.match(script, /action: 'update_location'/);
for (const field of ['csrf_token', 'schedule_id', 'latitude', 'longitude', 'accuracy']) {
    assert.match(script, new RegExp(field));
}
assert.match(script, /MBUS_GPS_ENABLED/);
assert.match(script, /setInterval\(poll, 10000\)/);
assert.match(script, /operatorTrackingBadge/);
assert.doesNotMatch(script, /riderFeed/);
assert.match(waitingBoard, /data-role="<\?php echo \$pickupStaffRole; \?>"/);
assert.match(waitingBoardScript, /const operatorView = root\.dataset\.role === 'operator'/);
assert.match(waitingBoardScript, /map\.setView\(\[Number\(selectedBus\.latitude\), Number\(selectedBus\.longitude\)\], 16/);
assert.match(waitingBoardScript, /if \(operatorView && !selected && locations\.length\)/);

// riders page is a seat map only
assert.doesNotMatch(riders, /riderLive|liveMap|waitingList|pickup_staff\.js|leaflet/i);
assert.match(riders, /operator_riders\.js/);

console.log('PASS operator sharing auto-starts, departs pin location, riders page stays seat-map only');
