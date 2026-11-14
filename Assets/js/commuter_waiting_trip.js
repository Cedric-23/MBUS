/* Waiting page: always-on map for the commuter's reserved trip.
   Before departure it says so; once the bus is on the road it shows the bus.
   "Notify as waiting" works in both states and the operator confirms the pickup. */
(() => {
    'use strict';
    const root = document.getElementById('commuterWaitingBoard');
    if (!root) return;
    const el = id => document.getElementById(id);
    const liveEndpoint = root.dataset.live;
    const MORONG = [14.6798552, 120.2728757];
    let map = null, layer = null, lastFitKey = '', state = { request: null, trip: null, last: null, schedules: [] }, locations = [], busy = false;

    const busName = n => /^bus/i.test(String(n)) ? String(n) : 'Bus ' + n;
    const num = v => { const n = Number(v); return Number.isFinite(n) ? n : null; };
    const fmtTime = iso => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); };
    const dot = cls => L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10], html: '<span class="cwt-pin ' + cls + '"></span>' });
    const busIcon = n => L.divIcon({ className: 'cwb-bus-icon', iconSize: [114, 34], iconAnchor: [57, 17],
        html: '<span class="cwb-bus-pin"><i class="fa fa-bus" aria-hidden="true"></i> ' + String(n || 'Bus').replace(/[<>&"]/g, '') + '</span>' });

    function say(text) { const b = el('cwbNotice'); b.textContent = text || ''; b.hidden = !text; if (text) setTimeout(() => { if (b.textContent === text) b.hidden = true; }, 5000); }
    function initMap() {
        if (!window.L) { el('cwbMapError').hidden = false; return; }
        try {
            map = L.map('cwbMap', { zoomControl: false, attributionControl: true }).setView(MORONG, 11);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' })
                .addTo(map).on('tileerror', () => { el('cwbMapError').hidden = false; });
            layer = L.layerGroup().addTo(map);
        } catch (_) { map = null; el('cwbMapError').hidden = false; }
    }
    function tripSchedule() {
        const id = state.request ? Number(state.request.schedule_id) : state.trip ? Number(state.trip.schedule_id) : null;
        return id ? state.schedules.find(s => Number(s.schedule_id) === id) || null : null;
    }
    function drawMap() {
        if (!map) return;
        layer.clearLayers();
        const pts = [], sch = tripSchedule();
        if (sch) {
            const a = [num(sch.origin_lat), num(sch.origin_lng)], z = [num(sch.destination_lat), num(sch.destination_lng)];
            if (a[0] !== null && z[0] !== null) {
                L.polyline([a, z], { color: '#1f6fb2', weight: 4, opacity: .55, dashArray: '8 8' }).addTo(layer);
                L.marker(a, { icon: dot(''), title: sch.origin }).addTo(layer);
                L.marker(z, { icon: dot('is-end'), title: sch.destination }).addTo(layer);
                pts.push(a, z);
            }
            const bus = locations.find(l => Number(l.schedule_id) === Number(sch.schedule_id));
            if (bus) { L.marker([bus.latitude, bus.longitude], { icon: busIcon(bus.bus_number), zIndexOffset: 500 }).addTo(layer); pts.push([bus.latitude, bus.longitude]); }
        }
        if (state.request && num(state.request.latitude) !== null) {
            const me = [Number(state.request.latitude), Number(state.request.longitude)];
            L.marker(me, { icon: dot('is-me'), title: 'You', zIndexOffset: 800 }).addTo(layer); pts.push(me);
        }
        const key = pts.map(p => p.join(',')).join('|');
        if (pts.length && key !== lastFitKey) { lastFitKey = key; map.fitBounds(L.latLngBounds(pts), { padding: [60, 60], maxZoom: 15, paddingBottomRight: [60, 260] }); }
    }
    function chips(list) { const m = el('cwtMeta'); m.textContent = ''; list.forEach(([t, c]) => { const s = document.createElement('span'); s.className = 'cwt-chip' + (c ? ' ' + c : ''); s.textContent = t; m.appendChild(s); }); }
    function pill(text) { el('cwbLocationPill').hidden = !text; el('cwbLocationText').textContent = text || ''; }
    function render() {
        const r = state.request, t = state.trip;
        const title = el('cwtTitle'), sub = el('cwtSub');
        const notify = el('cwtNotify'), cancel = el('cwtCancel'), link = el('cwtLink');
        notify.hidden = cancel.hidden = link.hidden = true;
        if (r) {
            const mins = Math.max(0, Math.round((Date.parse(r.expires_at) - Date.now()) / 60000));
            title.textContent = 'You’re marked as waiting';
            sub.textContent = 'The operator of ' + busName(r.bus_number) + ' can see your spot and will confirm your pickup.';
            chips([[busName(r.bus_number)], ['Active for ' + mins + ' min', 'is-ok']]);
            cancel.hidden = false;
            pill(t && !t.departed ? 'Trip not yet departed' : '');
        } else if (t) {
            const dep = fmtTime(t.departure_time);
            if (!t.departed) {
                title.textContent = 'Your trip hasn’t departed yet';
                sub.textContent = busName(t.bus_number) + ' leaves ' + t.origin + ' at ' + dep + '. You can already tell the operator you’re waiting.';
                chips([[busName(t.bus_number)], [t.origin + ' → ' + t.destination], ['Departs ' + dep, 'is-warn']]);
                pill('Trip not yet departed');
            } else {
                title.textContent = busName(t.bus_number) + ' is on the road';
                sub.textContent = 'Tap below so the operator knows you’re waiting along the route.';
                chips([[busName(t.bus_number)], [t.origin + ' → ' + t.destination], ['Departed', 'is-ok']]);
                pill('');
            }
            notify.hidden = false;
        } else if (state.last && state.last.status === 'Picked up') {
            title.textContent = 'You’ve been picked up';
            sub.textContent = 'Enjoy the ride. Check Trips for your ticket.';
            chips([]); link.textContent = 'View trips'; link.href = 'my_reservations.php'; link.hidden = false; pill('');
        } else {
            title.textContent = 'No trip to track yet';
            sub.textContent = 'Reserve and pay for a seat, then come back here to follow your bus and tell the operator you’re waiting.';
            chips([]); link.textContent = 'Find a trip'; link.href = 'schedule.php'; link.hidden = false; pill('');
        }
        el('cwbUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        drawMap();
    }
    async function load() {
        try {
            const [s, l] = await Promise.allSettled([MbusPickup.load(), fetch(liveEndpoint, { credentials: 'same-origin', cache: 'no-store' }).then(r => r.json())]);
            if (s.status === 'fulfilled') state = s.value; else say(s.reason.message);
            locations = l.status === 'fulfilled' && Array.isArray(l.value.locations) ? l.value.locations : [];
        } catch (e) { say(e.message); }
        render();
    }
    async function act(fn, btn, busyText) {
        if (busy) return; busy = true; const label = btn.textContent; btn.disabled = true; btn.textContent = busyText;
        try { const d = await fn(); say(d.message); await load(); } catch (e) { say(e.message); }
        btn.textContent = label; btn.disabled = false; busy = false;
    }
    el('cwtNotify').addEventListener('click', e => act(() => MbusPickup.notify(), e.currentTarget, 'Locating…'));
    el('cwtCancel').addEventListener('click', e => act(() => MbusPickup.cancel(state.request.request_id), e.currentTarget, 'Cancelling…'));
    el('cwbRefresh').addEventListener('click', load);
    initMap(); load();
    setInterval(() => { if (!document.hidden && !busy) load(); }, 8000);
})();
