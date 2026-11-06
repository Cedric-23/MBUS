/* Run with NODE_PATH=/tmp/mbus-test-tools/node_modules node tests/commuter_alert_waiting_test.cjs */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..');
const stripPhp = s => s.replace(/<\?php[\s\S]*?\?>/g, '');
const pause = () => new Promise(r => setTimeout(r, 30));
function leaflet(w) {
  const layer = { addTo(){ return this; }, on(){ return this; }, clearLayers(){ return this; }, bindPopup(){ return this; }, setLatLng(){ return this; }, getLatLng(){ return {lat:14.5,lng:120.5}; } };
  const map = { setView(){ return this; }, on(){ return this; }, fitBounds(){ return this; }, removeLayer(){} };
  w.L = { map(){ return map; }, tileLayer(){ return layer; }, layerGroup(){ return layer; }, marker(){ return Object.assign({}, layer); }, circleMarker(){ return Object.assign({}, layer); } };
}
async function alertsTest() {
  const html = stripPhp(fs.readFileSync(path.join(root, 'Commuter/notifications.php'), 'utf8'));
  const script = fs.readFileSync(path.join(root, 'Assets/js/commuter_waiting_alerts.js'), 'utf8');
  const dom = new JSDOM(html, { url:'https://mbus.test/Commuter/notifications.php', runScripts:'outside-only', pretendToBeVisual:true });
  const w=dom.window,d=w.document,el=id=>d.getElementById(id); let posts=[], gets=0;
  leaflet(w); w.mbusWaitingConfig={csrfToken:'token'};
  const trip={schedule_id:42,bus_number:'B42',origin:'North',destination:'South',status:'Boarding'};
  w.fetch=async (url,options={})=>{
    if(options.method==='POST'){posts.push({url,fields:Object.fromEntries(new URLSearchParams(options.body))});return {ok:true,json:async()=>({ok:true,message:'Saved'})};}
    gets++; return {ok:true,json:async()=>({ok:true,schedules:[trip],schedule:trip,requests:[],csrf_token:'fresh'})};
  };
  w.eval(script); await pause();
  assert.ok(el('waitingSchedule')); assert.ok(el('waitingMap')); assert.ok(el('shareWaitingBtn'));
  assert.equal(el('waitingSchedule').value,'42'); assert.equal(posts.length,0,'initial load never shares location');
  // Map selection is only a draft; click/drag must not post.
  // Use manual inputs for deterministic coordinate selection.
  el('waitingLatitude').value='14.5'; el('waitingLongitude').value='120.5';
  el('waitingLatitude').dispatchEvent(new w.Event('input')); el('waitingLongitude').dispatchEvent(new w.Event('input'));
  assert.equal(posts.length,0,'selecting coordinates does not share');
  el('waitingLatitude').dispatchEvent(new w.Event('change')); el('waitingLongitude').dispatchEvent(new w.Event('change'));
  // Change the selected trip, then enter a new coordinate draft.
  el('waitingSchedule').dispatchEvent(new w.Event('change',{bubbles:true})); await pause();
  // Re-enter after trip selection reset and explicitly dispatch the coordinate panel change.
  el('waitingLatitude').value='14.5'; el('waitingLongitude').value='120.5';
  el('waitingLatitude').dispatchEvent(new w.Event('change')); el('waitingLongitude').dispatchEvent(new w.Event('change'));
  assert.equal(posts.length,0,'coordinate selection does not post');
  el('waitingLabel').value='Main gate'; el('shareWaitingBtn').click(); await pause();
  assert.equal(posts.length,1); assert.match(posts[0].url,/live_tracking\.php$/);
  assert.equal(posts[0].fields.action,'mark_waiting'); assert.equal(posts[0].fields.schedule_id,'42');
  assert.equal(posts[0].fields.latitude,'14.5'); assert.equal(posts[0].fields.longitude,'120.5');
  assert.equal(posts[0].fields.pickup_label,'Main gate'); assert.equal(posts[0].fields.csrf_token,'fresh'); assert.ok(gets>=1);
  dom.window.close();
}
async function liveTest() {
  const html=stripPhp(fs.readFileSync(path.join(root,'Commuter/live.php'),'utf8'));
  const source=fs.readFileSync(path.join(root,'Assets/js/commuter_live.js'),'utf8');
  const dom=new JSDOM(html,{url:'https://mbus.test/Commuter/live.php',runScripts:'outside-only',pretendToBeVisual:true});
  const w=dom.window,d=w.document,el=id=>d.getElementById(id); leaflet(w); w.mbusLive={scheduleId:42};
  w.fetch=async()=>({ok:true,json:async()=>({ok:true,active:[{schedule_id:42,bus_number:'B42'}],location:null,requests:[{status:'waiting'}]})});
  w.eval(source); await pause();
  assert.equal(el('waitingCard'),null,'Live runtime has no waiting status/cancel UI');
  assert.equal(el('markWaitingPanel'),null,'Live runtime has no waiting location picker');
  assert.equal(el('waitBtn'),null,'Live runtime has no share action');
  dom.window.close();
}
(async()=>{await alertsTest();await liveTest();console.log('PASS commuter Alerts waiting picker and Live tracking-only behavior');})().catch(e=>{console.error(e);process.exit(1);});
