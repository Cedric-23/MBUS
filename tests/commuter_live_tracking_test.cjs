/* Run with NODE_PATH=/tmp/mbus-test-tools/node_modules node tests/commuter_live_tracking_test.cjs */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'Commuter/live.php'), 'utf8').replace(/<\?php[\s\S]*?\?>/g, '');
const source = fs.readFileSync(path.join(root, 'Assets/js/commuter_live.js'), 'utf8');
const pause = () => new Promise(r => setTimeout(r, 25));
async function main() {
 const dom = new JSDOM(html, { url: 'https://mbus.test/Commuter/live.php', runScripts: 'outside-only', pretendToBeVisual: true });
 const w = dom.window, d = w.document, el = id => d.getElementById(id); let calls = 0;
 const layer = { addTo(){return this;}, on(){return this;}, clearLayers(){return this;}, bindPopup(){return this;} };
 const mapObj = {setView(){return this;}, on(){return this;}, fitBounds(){return this;}, removeLayer(){}};
 w.L = { map(){return mapObj;}, tileLayer(){return layer;}, layerGroup(){return layer;}, marker(){return Object.assign({}, layer);}, circleMarker(){return Object.assign({}, layer);} };
 const trip = { schedule_id: 42, bus_number: 'B42', origin: 'North', destination: 'South', origin_lat: 14, origin_lng: 120, destination_lat: 15, destination_lng: 121, trip_status:'Departed' };
 w.mbusLive = { scheduleId: 42, csrfToken: 'token' };
 w.fetch = async () => { calls++; return {ok:true,json:async()=>({ok:true,active:true,schedules:[trip],schedule:trip,location:null,waiting:null,requests:[],server_time:new Date().toISOString()})}; };
 w.eval(source); await pause();
 assert.match(el('liveSchedule').textContent, /B42/);
 assert.equal(el('liveSchedule').value, '42');
 assert.match(el('mapMessage').textContent, /has not shared.*GPS|no bus GPS/i);
 assert.equal(el('pickupForm'), null);
 assert.equal(el('waitingCard'), null);
 assert.equal(el('markWaitingPanel'), null);
 assert.equal(el('waitBtn'), null);
 assert.equal(el('locateBtn'), null);
 assert.match(d.body.textContent, /Alerts/);
 assert.equal(d.querySelectorAll('.bottom-nav-item').length, 5);
 assert.ok(calls >= 1, 'Live should poll bus location');
 assert.match(source, /No active Boarding\/Departed buses/);
 assert.match(source, /Stale location/);
 dom.window.close();
 console.log('PASS commuter Live is tracking-only: active bus selector, truthful no-GPS state, no waiting/location-sharing controls, foreground API polling');
}
main().catch(e=>{console.error(e);process.exit(1);});
