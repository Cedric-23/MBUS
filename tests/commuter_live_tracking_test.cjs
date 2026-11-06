/* Commuter Live is tracking-only: no waiting controls, Waiting nav, live bus lookup.
 * Run: node tests/commuter_live_tracking_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const html = read('Commuter/live.php');
const source = read('Assets/js/commuter_live.js');

// tracking-only page
for (const control of ['pickupForm', 'waitingCard', 'markWaitingPanel', 'waitBtn', 'locateBtn']) {
    assert.doesNotMatch(html, new RegExp(`id="${control}"`), `${control} should not be on Live`);
}
assert.match(html, /id="liveSchedule"/);
assert.match(html, /id="trackingMap"/);
assert.match(html, /id="mapMessage"/);
assert.match(html, /href="waiting\.php"/);
assert.match(html, /<span>Waiting<\/span>/);
assert.doesNotMatch(html, />Alerts</);
assert.equal((html.match(/class="bottom-nav-item/g) || []).length, 5, 'expected five tabs');

// live script: polls, selects a bus that is actually sharing, no sharing controls
assert.match(source, /No active Boarding\/Departed buses/);
assert.match(source, /Stale location/);
assert.match(source, /liveIds/);
assert.match(source, /data\.locations/);
assert.doesNotMatch(source, /mark_waiting|cancel_waiting/);

console.log('PASS commuter Live is tracking-only: live bus default, truthful no-GPS state, no waiting controls');
