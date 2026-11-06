/* Run with: NODE_PATH=/tmp/mbus-test-tools/node_modules node tests/commuter_live_test.cjs
 * Only mocked API data is used. jsdom@26 is a development-only test dependency.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..');
const template = fs.readFileSync(path.join(root, 'Commuter/live.php'), 'utf8').replace(/<\?php[\s\S]*?\?>/g, '');
const source = fs.readFileSync(path.join(root, 'Assets/js/commuter_live.js'), 'utf8');
const delay = () => new Promise(resolve => setTimeout(resolve, 20));

async function main() {
    const dom = new JSDOM(template, { url: 'https://mbus.test/Commuter/live.php', runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    const el = id => w.document.getElementById(id);
    let shared = [];
    let requests = [];
    let ready = true;
    let failNext = false;
    const schedules = [
        { schedule_id: 17, bus_number: 'B17', origin: 'MORONG TERMINAL', destination: 'SBMA', trip_status: 'Departed', action: 'pickup', stops: ['SABANG', 'MAIN GATE'] },
        { schedule_id: 18, bus_number: 'B18', origin: 'MORONG TERMINAL', destination: 'SBMA', trip_status: 'Scheduled', action: 'reserve', stops: ['SABANG', 'MAIN GATE'] }
    ];
    w.mbusLive = { scheduleId: 17, csrfToken: 'old-token', timezoneOffset: '+08:00' };
    w.fetch = async (_url, options) => {
        if (options.method === 'POST') {
            const fields = Object.fromEntries(options.body.entries());
            shared.push(fields);
            if (failNext) { failNext = false; return { ok: false, json: async () => ({ ok: false, message: 'Trip is no longer available.' }) }; }
            if (fields.action === 'wait') requests = [{ request_id: 9, schedule_id: 17, status: 'Waiting', latitude: Number(fields.latitude), longitude: Number(fields.longitude), pickup_label: fields.pickup_label, destination: fields.destination, bus_number: 'B17', origin: 'MORONG TERMINAL', route_destination: 'SBMA', expires_at: new Date(Date.now() + 1800000).toISOString() }];
            if (fields.action === 'cancel') requests[0].status = 'Cancelled';
            return { ok: true, json: async () => ({ ok: true, message: fields.action === 'wait' ? 'Waiting request saved.' : 'Waiting request cancelled.' }) };
        }
        return { ok: true, json: async () => ({ ok: true, ready, schedules, requests, csrf_token: 'fresh-token', server_time: new Date().toISOString() }) };
    };
    w.eval(source);
    await delay();
    assert.equal(el('liveSchedule').value, '17');
    assert.equal(shared.length, 0, 'loading does not share rider location');
    assert.equal(el('waitBtn').disabled, true, 'waiting requires a pin and details');
    el('manualLatitude').value = '14.676';
    el('manualLongitude').value = '120.27';
    el('manualPinBtn').click();
    el('pickupLabel').value = 'Main Gate stop';
    el('pickupLabel').dispatchEvent(new w.Event('input'));
    el('liveDestination').value = 'MAIN GATE';
    el('liveDestination').dispatchEvent(new w.Event('change'));
    assert.equal(el('waitBtn').disabled, false);
    assert.equal(shared.length, 0, 'marking a draft pin is not a submission');
    el('pickupForm').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
    await delay();
    assert.equal(shared.length, 1);
    assert.equal(shared[0].csrf_token, 'fresh-token');
    assert.equal(shared[0].action, 'wait');
    assert.equal(shared[0].schedule_id, '17');
    assert.equal(shared[0].destination, 'MAIN GATE');
    assert.equal(el('waitingCard').hidden, false);
    assert.equal(el('waitingStatus').textContent, 'Waiting');
    assert.match(el('liveMessage').textContent, /saved/);
    assert.equal(el('cancelWaitingBtn').hidden, false);
    el('cancelWaitingBtn').click();
    await delay();
    assert.equal(shared[1].action, 'cancel');
    assert.equal(el('waitingStatus').textContent, 'Cancelled');
    assert.equal(el('cancelWaitingBtn').hidden, true);
    el('liveSchedule').value = '18';
    el('liveSchedule').dispatchEvent(new w.Event('change'));
    assert.equal(el('reserveInstead').hidden, false);
    assert.equal(el('waitBtn').disabled, true, 'reservation-open trip cannot create pickup');
    el('liveSchedule').value = '17';
    el('liveSchedule').dispatchEvent(new w.Event('change'));
    failNext = true;
    el('pickupForm').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
    await delay();
    assert.match(el('liveMessage').textContent, /no longer available/, 'mutation error survives queue refresh');
    ready = false;
    w.document.dispatchEvent(new w.Event('visibilitychange'));
    await delay();
    assert.equal(el('waitBtn').disabled, true, 'missing migration blocks waiting');
    dom.window.close();
    console.log('PASS commuter live DOM: explicit sharing, CSRF refresh, waiting, cancel, reservation fallback, error visibility, unavailable service');
}
main().catch(error => { console.error(error); process.exit(1); });
