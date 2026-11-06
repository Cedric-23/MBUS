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
 const w = dom.window, d = w.document, el = id => d.getElementById(id); let posts = [], calls = 0;
 const layer = { addTo(){return this;}, on(){return this;}, clearLayers(){return this;}, bindPopup(){return this;}, setLatLng(){return this;}, getLatLng(){return {lat:14.5,lng:120.5};} };
 let tap; const mapObj = {setView(){return this;}, on(name, cb){if(name==='click')tap=cb;return this;}, fitBounds(){return this;}, removeLayer(){}};
 w.L = { map(){return mapObj;}, tileLayer(){return layer;}, layerGroup(){return layer;}, marker(){return Object.assign({}, layer);}, circleMarker(){return Object.assign({}, layer);} };
 const trip = { schedule_id: 42, bus_number: 'B42', origin: 'North', destination: 'South', origin_lat: 14, origin_lng: 120, destination_lat: 15, destination_lng: 121 };
 w.mbusLive = { scheduleId: 42, csrfToken: 'token' };
 w.fetch = async (url, options={}) => {
   if (options.method === 'POST') { posts.push({url, fields:Object.fromEntries(new URLSearchParams(options.body))}); return {ok:true,json:async()=>({ok:true,message:'Saved'})}; }
   calls++;
   return {ok:true,json:async()=>({ok:true,active:[trip],schedule:trip,location:null,waiting:null,requests:[],csrf_token:'fresh',server_time:new Date().toISOString()})};
 };
 w.eval(source); await pause();
 assert.match(el('liveSchedule').textContent, /B42/);
 assert.equal(el('liveSchedule').value, '42');
 assert.match(el('mapMessage').textContent, /has not shared.*GPS|no bus GPS/i);
 assert.equal(posts.length, 0, 'loading must not share location');
 assert.equal(el('pickupForm'), null, 'legacy pickup arrangement form removed');
 assert.equal(d.querySelectorAll('.bottom-nav-item').length, 5);
 // Tapping the map creates a draft point only; the explicit button posts it.
 tap({latlng:{lat:14.5,lng:120.5}});
 assert.equal(posts.length, 0, 'selecting a map point must not share it');
 el('pickupLabel').value = 'Main gate';
 el('waitBtn').click(); await pause();
 assert.equal(posts.length, 1);
 assert.match(posts[0].url, /live_tracking\.php$/);
 assert.equal(posts[0].fields.action, 'mark_waiting');
 assert.equal(posts[0].fields.schedule_id, '42');
 assert.equal(posts[0].fields.latitude, '14.5');
 assert.equal(posts[0].fields.longitude, '120.5');
 assert.equal(posts[0].fields.pickup_label, 'Main gate');
 assert.equal(posts[0].fields.csrf_token, 'fresh');
 assert.match(source, /cancel_waiting/);
 assert.match(source, /setTimeout\(load, 9000\)/);
 assert.match(source, /Stale location/);
 assert.ok(calls >= 1);
 dom.window.close();
 console.log('PASS commuter live tracking DOM: own active trip selector, truthful no-GPS state, no pickup form, privacy/confirmation, API polling and mutation contract');
}
main().catch(e=>{console.error(e);process.exit(1);});
