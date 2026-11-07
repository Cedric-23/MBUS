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
const script = read('Assets/js/operator_tracker.js');
const waitingBoard = read('Includes/pickup_staff_view.php');
const waitingBoardScript = read('Assets/js/staff_waiting.js');

// Trip page keeps status updates and focuses the bus that just departed.
assert.match(manage, /data-trip-status=/);
assert.match(manage, /OPERATOR_TRACK_SCHEDULE_ID/);
assert.match(manage, /\$departed_now = \(\$trip_status === 'Departed'\)/);
assert.match(manage, /\$track_focus \?: \$open_schedule/);
assert.match(manage, /id="departingBanner"/);
assert.match(manage, /gps_status\.css/);
assert.match(manage, /operator_tracker\.js/);
assert.match(manage, /csrf_get_token\(\)/);

// Unified tracker shares the selected active trip silently and survives page changes.
assert.match(dashboard, /Includes\/csrf\.php/);
assert.match(dashboard, /window\.MBUS_GPS_ENABLED = true/);
assert.match(dashboard, /class="tracking-sr-only"/);
assert.match(dashboard, /id="operatorTrackingStatus"/);
assert.doesNotMatch(dashboard, /operatorGpsMap|operatorTrackingBadge|leaflet@1\.9\.4|Bus location/);
assert.match(dashboard, /gps_status\.css/);
assert.doesNotMatch(dashboard, /Enable GPS for bus tracking/);
assert.match(script, /getCurrentPosition/);
assert.match(script, /watchPosition/);
assert.match(script, /clearWatch/);
assert.match(script, /document\.hidden/);
assert.match(script, /action: 'update_location'/);
for (const field of ['csrf_token', 'schedule_id', 'latitude', 'longitude', 'accuracy']) {
    assert.match(script, new RegExp(field));
}
assert.match(script, /SEND_EVERY/);
assert.match(script, /setInterval\(\(\) => \{ if \(!document\.hidden\) poll\(\); \}, POLL_EVERY\)/);
assert.match(script, /operatorTrackingBadge/);
assert.doesNotMatch(script, /riderFeed/);

// Staff Waiting has live buses, rider photos, road estimates and a view toggle.
assert.match(waitingBoard, /data-role="<\?php echo \$pickupStaffRole; \?>"/);
assert.match(waitingBoard, /id="staffRouteView"/);
assert.match(waitingBoardScript, /routeViewMode/);
assert.match(waitingBoardScript, /toggleStaffRoute/);
assert.match(waitingBoardScript, /L\.marker\(p/);
assert.match(waitingBoardScript, /remainingOnRoute/);

// Riders page remains a seat map only.
assert.doesNotMatch(riders, /riderLive|liveMap|waitingList|pickup_staff\.js|live_tracking\.php|leaflet/i);
assert.match(riders, /operator_riders\.js/);

console.log('PASS operator GPS shares the active/departed bus in the background; Waiting map shows route context; Riders stays seat-map only');
