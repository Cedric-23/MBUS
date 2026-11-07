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
assert.doesNotMatch(html, /Waiting for a bus/);
assert.equal((html.match(/class="bottom-nav-item/g) || []).length, 5, 'expected five tabs');

// live script: polls, selects a bus that is actually sharing, no sharing controls
assert.match(source, /No bus is boarding or on the road/);
assert.match(source, /Last seen/);
assert.match(html, /id="busList"/);
// same bus-number pins as the operator board, one per live bus
assert.match(source, /board-bus-pin/);
assert.match(source, /L\.divIcon/);
assert.match(source, /map\.setView\(focus, 16/);
assert.match(source, /routeViewMode = 'route'/);
assert.match(source, /defaultTrip = trips\.find/);
assert.match(source, /manualChoice = true/);
assert.match(source, /MBUSLiveSelection\.chooseSchedule/);
assert.match(source, /data\.locations/);
assert.match(source, /cancel_waiting/);
assert.match(css, /\.commuter-live-page \.board-list-card/);
assert.match(css, /\.bus-row-route \{[^}]*text-overflow: ellipsis/s);
assert.doesNotMatch(source, /mark_waiting/);

console.log('PASS commuter Live is tracking-only: live bus default, truthful no-GPS state, no waiting controls');
