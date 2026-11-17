/* Waiting UX: pin flow, status page, staff board and operator-only GPS gate.
 * Run: node tests/waiting_ux_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const commuterWaiting = read('Commuter/waiting.php');
const waitingBoardJs = read('Assets/js/commuter_waiting_board.js');
const waitingBoardCss = read('Assets/css/commuter_waiting_board.css');
const bottomNavCss = read('Assets/css/bottom-nav.css');
const pickupPage = read('Commuter/pickup.php');
const pickupJs = read('Assets/js/commuter_pickup.js');
const pickupCss = read('Assets/css/commuter_pickup.css');
const operatorWaiting = read('Operator/waiting.php');
const adminWaiting = read('Admin/waiting.php');
const staffView = read('Includes/pickup_staff_view.php');
const unifiedCss = read('Assets/css/unify.css');
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
assert.equal((commuterWaiting.match(/<nav class="bottom-nav"/g) || []).length, 1, 'commuter Waiting page must render one bottom navigation');
assert.equal((commuterWaiting.match(/class="profile-menu-wrap"/g) || []).length, 1, 'commuter Waiting page must render one account control');
assert.doesNotMatch(commuterWaiting, /pickupMap|Mark me as waiting|Update my spot/);
assert.match(bottomNavCss, /--bottom-nav-height:\s*58px/);
assert.match(bottomNavCss, /bottom:\s*calc\(10px \+ env\(safe-area-inset-bottom\)\)/);
assert.match(waitingBoardCss, /\.cwb-map\s*\{[^}]*inset:\s*0 0 calc\(var\(--cwb-nav-h\) \+ 10px \+ env\(safe-area-inset-bottom\)\)/s,
    'the Waiting map must stop at the top of the floating nav, not continue behind it');
assert.match(waitingBoardCss, /body\.commuter-waiting-board \.bottom-nav\s*\{[^}]*background:\s*#fff;[^}]*backdrop-filter:\s*none/s,
    'the Waiting nav should use the same solid white background as the bus sheet');
assert.match(waitingBoardCss, /\.cwb-map-credit\s*\{[^}]*border-top:\s*1px solid #edf0f4/,
    'map credits should sit in a quiet footer inside the Waiting sheet');
assert.match(waitingBoardCss, /\.cwb-list\s*\{[^}]*flex:\s*0 0 auto;[^}]*max-height:\s*60px;[^}]*overflow-y:\s*auto/s,
    'the bus row should stay usable while the sheet preserves space for its credits');
assert.match(commuterWaiting, /class="cwb-map-credit"[^>]*>[\s\S]*OpenStreetMap[\s\S]*Routes by[\s\S]*OSRM/,
    'the Waiting sheet must keep visible map-source and routing credits');
assert.match(commuterWaiting, /href="https:\/\/www\.openstreetmap\.org\/copyright"/,
    'the OpenStreetMap credit should link to its licence information');
assert.match(waitingBoardCss, /@media \(min-width: 769px\) \{\s*\.cwb-map\s*\{\s*inset:\s*0;/s,
    'desktop map should still use the full available viewport when the mobile dock is hidden');
assert.match(waitingBoardCss, /\.cwb-sheet\s*\{[^}]*bottom:\s*calc\(var\(--cwb-nav-h\) \+ 10px \+ env\(safe-area-inset-bottom\)\)/s,
    'the Waiting sheet must meet the top edge of the floating dock after its 10px bottom inset and safe-area offset');
assert.match(waitingBoardCss, /\.cwb-sheet\s*\{[^}]*padding:\s*9px 14px 8px/s,
    'the sheet should not add redundant safe-area padding inside the dock-connected edge');
assert.match(waitingBoardCss, /@media \(min-width: 769px\) \{[\s\S]*?\.cwb-sheet\s*\{[^}]*bottom:\s*16px/s,
    'desktop Waiting sheet should use a simple edge margin when the mobile dock is hidden');
assert.match(commuterWaiting, /id="cwbDetailsToggle"[^>]*aria-expanded="false"[^>]*aria-controls="cwbRouteControls"/,
    'route details must start collapsed and have an accessible toggle');
assert.match(commuterWaiting, /id="cwbRouteControls"[^>]*hidden/,
    'route details should not cover the map until requested');
assert.match(waitingBoardCss, /\.cwb-route-controls\[hidden\]\s*\{\s*display:\s*none/,
    'the collapsed details must actually be hidden despite their flex layout');
assert.match(waitingBoardCss, /\.cwb-route-controls\s*\{[^}]*min-height:\s*0;[^}]*overflow-y:\s*auto/s,
    'long route messages must scroll without covering the bus row');
assert.match(waitingBoardCss, /\.cwb-details-toggle\s*\{[^}]*min-height:\s*44px/s,
    'the details toggle should remain easy to tap');
assert.match(waitingBoardCss, /\.cwb-cancel\s*\{[^}]*min-height:\s*44px/s,
    'the separate cancel action should remain easy to tap');
assert.match(waitingBoardJs, /function toggleDetails\(\)[\s\S]*?details\.hidden = !expanded;[\s\S]*?setAttribute\('aria-expanded', String\(expanded\)\)/,
    'the toggle must reveal details and update its expanded state');
assert.match(waitingBoardJs, /expanded \? 'Hide route details' : 'Show route details'/);
assert.match(waitingBoardJs, /el\('cwbDetailsToggle'\)\.addEventListener\('click', toggleDetails\)/);
assert.match(waitingBoardJs, /Math\.max\(120, \(el\('cwbSheet'\)\.offsetHeight \|\| 120\) \+ 24\)/,
    'map fitting should use the current compact or expanded sheet height');
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
assert.match(waitingBoardJs, /function toggleTracking\(\)/);
assert.match(waitingBoardJs, /select\.addEventListener\('click', toggleTracking\)/);
assert.match(waitingBoardJs, /busMarker\.on\('click', toggleTracking\)/);
assert.match(waitingBoardJs, /attributionControl\.setPrefix\(false\)/);
assert.match(waitingBoardJs, /attributionControl\.remove\(\)/);
assert.match(waitingBoardJs, /OpenStreetMap[\s\S]*routing by OSRM/,
    'removing the floating control must retain the map-layer attribution source');
assert.match(waitingBoardJs, /Tracking paused/);
assert.match(waitingBoardJs, /function fitFullRoute\(trip\)/);
assert.match(waitingBoardJs, /window\.addEventListener\('resize',[\s\S]*?if \(!trackSelected\) return;[\s\S]*?routeViewMode === 'route' && routeData[\s\S]*?fitFullRoute\(trip\)/);
assert.match(read('Assets/css/commuter_waiting_board.css'), /\.cwb-bus-pin\.is-untracked/);
assert.doesNotMatch(waitingBoardJs, /Pick up/);
const visibleCopy = commuterWaiting.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim();
assert.ok(visibleCopy.split(' ').length < 120, `waiting status page copy too long: ${visibleCopy.split(' ').length} words`);

assert.match(pickupPage, /id="pickupMap"/);
assert.match(pickupPage, /id="pickupLocate"/);
assert.match(pickupPage, /id="pickupWait"/);
assert.match(pickupPage, /profileImage/);
assert.match(pickupPage, /\$trip\['bus_number'\]/);
assert.match(pickupPage, /commuter_pickup\.css\?v=.*filemtime/);
assert.match(pickupCss, /\.pickup-map\s*\{[^}]*position:\s*fixed;[^}]*height:\s*100vh;[^}]*height:\s*100dvh/s);
assert.match(pickupCss, /\.pickup-sheet\s*\{[^}]*position:\s*fixed;[^}]*overflow-y:\s*auto/s);
assert.match(pickupCss, /\.pickup-locate\s*\{[^}]*bottom:\s*calc\(var\(--sheet-h/);
assert.match(pickupCss, /\.pickup-btn-primary\s*\{[^}]*background:/s);
assert.match(pickupJs, /navigator\.geolocation\.getCurrentPosition/);
assert.match(pickupJs, /action: 'mark_waiting'/);
assert.match(pickupJs, /action: 'cancel_waiting'/);
assert.match(pickupJs, /draggable: !waiting/);
assert.match(pickupJs, /You\'re marked as waiting/);
assert.match(pickupJs, /pickupWait/);
assert.match(pickupJs, /waitingElsewhere\(\)/);
assert.match(pickupJs, /Cancel current waiting/);
assert.match(pickupJs, /window\.location\.href = 'waiting\.php'/);

// The commuter Waiting page does not embed a second pickup picker.
assert.doesNotMatch(commuterWaiting, /waitingSchedule|waitBtn|commuter_waiting_alerts\.js|live_pickup\.css/);

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
assert.equal((staffView.match(/<nav class="bottom-nav"/g) || []).length, 1, 'staff Waiting page must render one bottom navigation');
assert.equal((staffView.match(/class="profile-menu-wrap"/g) || []).length, 1, 'staff Waiting page must render one account control');
assert.match(unifiedCss, /\.staff-wait-page \.sw-bar \.profile-menu-wrap\{[^}]*position:relative!important;[^}]*top:auto!important;[^}]*right:auto!important;/);
assert.match(unifiedCss, /\.staff-wait-page \.sw-bar \.profile-trigger\{[^}]*width:42px!important;[^}]*height:42px!important;/);
assert.match(staffView, /unify\.css\?v=.*filemtime/);
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
