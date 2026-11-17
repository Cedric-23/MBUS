/* Commuter Live is tracking-only: no waiting controls, Waiting nav, live bus lookup.
 * Run: node tests/commuter_live_tracking_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const html = read('Commuter/live.php');
const source = read('Assets/js/commuter_live.js');
const css = read('Assets/css/commuter_live.css');

// tracking-only page
for (const control of ['pickupForm', 'waitingCard', 'markWaitingPanel', 'waitBtn', 'locateBtn']) {
    assert.doesNotMatch(html, new RegExp(`id="${control}"`), `${control} should not be on Live`);
}
assert.match(html, /id="liveSchedule"/);
assert.match(html, /id="trackingMap"/);
assert.match(html, /id="mapMessage"/);
assert.match(html, /route-line-key/);
assert.match(html, /id="routeViewButton"[^>]*>Focus bus</);
assert.match(html, /href="waiting\.php"/);
assert.match(html, /<span>Waiting<\/span>/);
assert.doesNotMatch(html, />Alerts</);
assert.doesNotMatch(html, /Waiting for a bus|Pick up|lvPickBtn|commuter_live_pickup|mbus_quick_pickup/,
    'Live stays focused on bus tracking with no pickup or waiting action');
assert.equal((html.match(/class="bottom-nav-item/g) || []).length, 5, 'expected five tabs');
assert.match(html, /class="profile-trigger"/);
assert.match(html, /class="lv-brand"/);
assert.match(html, /class="lv-visually-hidden">Live buses<\/h1>/);
assert.doesNotMatch(html, /<h1>Live buses<\/h1>/, 'do not show the redundant Live buses title at the top');
assert.match(html, /class="lv-map"/);
assert.match(html, /class="lv-bus-panel"/);
assert.ok(html.indexOf('id="busCount"') > html.indexOf('class="lv-bus-panel"'), 'live count belongs in the Active buses card, away from the avatar');
assert.match(html, /class="lv-legend"/);

// live script: polls, selects a bus that is actually sharing, no sharing controls
assert.match(source, /No bus is boarding or on the road/);
assert.match(source, /Last seen/);
assert.match(html, /id="busList"/);
// same bus-number pins as the operator board, one per live bus
assert.match(source, /board-bus-marker/);
assert.match(source, /L\.divIcon/);
assert.match(source, /map\.flyTo\(focus, 16/);
assert.match(source, /zoomControl: false/);
assert.doesNotMatch(source, /L\.control\.zoom/, 'do not add visible zoom buttons');
assert.match(source, /routeViewMode = 'route'/);
assert.match(source, /defaultTrip = trips\.find/);
assert.match(source, /manualChoice = true/);
assert.match(source, /MBUSLiveSelection\.chooseSchedule/);
assert.match(source, /data\.locations/);
assert.doesNotMatch(source, /cancel_waiting|commuterLayer|Your waiting pickup|pickup\.php|Pick up|bus-row-pick|bus-pop-pick/);
assert.doesNotMatch(html, /profileImage|dot-commuter|Pickup distance/);
assert.match(css, /\.lv-map \{[^}]*position: relative/s);
assert.match(css, /\.lv-bus-panel/);
assert.doesNotMatch(css, /\.lv-map \{[^}]*position: fixed/s);
assert.doesNotMatch(css, /\.lv-sheet/);
assert.doesNotMatch(css, /\.commuter-live-page \{[^}]*overflow:\s*hidden/s);
assert.doesNotMatch(css, /\.lv-header \{[^}]*position:\s*fixed/s);
assert.match(css, /grid-template-areas: "buses" "map"/);
assert.match(css, /height: clamp\(205px, 29dvh, 255px\)/);
assert.match(css, /\.lv-header \.profile-avatar img \{[^}]*max-width: 100%/s);
assert.match(css, /\.commuter-live-page \.lv-header \.profile-menu-wrap \{[^}]*position: relative !important;[^}]*right: auto !important;/s,
    'override the shared fixed profile position on Live so the avatar stays in the header flow');
assert.doesNotMatch(css, /leaflet-control-zoom/);
assert.match(css, /calc\(var\(--bottom-nav-height\) \+ 24px \+ env\(safe-area-inset-bottom\)\)/);
assert.match(css, /\.bus-row-route \{[^}]*text-overflow: ellipsis/s);
assert.match(css, /\.commuter-live-page \.leaflet-control-attribution/);
assert.doesNotMatch(css, /leaflet-control-attribution[^}]*display:\s*none/,
    'keep map-source attribution visible while styling it discreetly');
assert.match(source, /attributionControl\.setPrefix\(false\)/);
assert.match(source, /attributionControl\.setPosition\('bottomright'\)/);
assert.match(source, /OpenStreetMap[\s\S]*project-osrm\.org/);
assert.match(source, /animate: !reducedMotion/);
assert.doesNotMatch(source, /mark_waiting/);

console.log('PASS commuter Live is bus-only: live bus and road path, no commuter Waiting controls or markers');
