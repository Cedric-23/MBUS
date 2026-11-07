/* Pick up: pin yourself, see the bus live, mark as waiting (like waiting for a ride-hailing car). */
(function () {
    'use strict';
    const cfg = window.mbusPickup || {};
    const ENDPOINT = '../api/live_tracking.php';
    const FRESH_MS = 120000;
    const el = id => document.getElementById(id);
    const sheet = document.querySelector('.pickup-sheet');

    let map = null, busMarker = null, youMarker = null, line = null;
    let me = null;                 // { lat, lng }
    let waiting = false;
    let busLoc = null;
    let csrf = cfg.csrfToken || '';
    let serverNow = Date.now();
    let fitKey = 0, timer = null, busy = false;

    const num = v => { const n = Number(v); return Number.isFinite(n) ? n : null; };
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function toast(text) {
        const box = el('pickupToast');
        box.hidden = !text;
        box.textContent = text || '';
        if (text) setTimeout(() => { if (box.textContent === text) box.hidden = true; }, 5000);
    }
    function sizeSheet() { document.documentElement.style.setProperty('--sheet-h', sheet.offsetHeight + 'px'); }

    function km(a, b) {
        const R = 6371, rad = d => d * Math.PI / 180;
        const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
        return 2 * R * Math.asin(Math.sqrt(h));
    }
    function dist(d) { return d < 1 ? Math.max(10, Math.round(d * 1000 / 10) * 10) + ' m' : d.toFixed(1) + ' km'; }

    /* ---------- map ---------- */
    function initMap() {
        if (typeof L === 'undefined') { toast('The map could not load. You can still mark yourself as waiting.'); return; }
        map = L.map('pickupMap', { zoomControl: false }).setView([14.678, 120.266], 15);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    }
    const busIcon = stale => L.divIcon({
        className: '', iconSize: [84, 28], iconAnchor: [42, 14],
        html: '<span class="pk-bus' + (stale ? ' is-stale' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i>' + esc(cfg.bus) + '</span>'
    });
    const youIcon = () => L.divIcon({ className: '', iconSize: [22, 22], iconAnchor: [11, 11], html: '<span class="pk-you' + (waiting ? ' is-waiting' : '') + '"></span>' });

    function setMe(lat, lng, pan) {
        me = { lat, lng };
        if (!map) { refresh(); return; }
        if (!youMarker) {
            youMarker = L.marker([lat, lng], { icon: youIcon(), draggable: !waiting, title: 'You' }).addTo(map);
            youMarker.on('dragend', () => { const p = youMarker.getLatLng(); me = { lat: p.lat, lng: p.lng }; refresh(); });
        } else {
            youMarker.setLatLng([lat, lng]);
        }
        if (pan) fit(true);
        refresh();
    }
    function fit(force) {
        // Refit when a new point first appears (you, then the bus) so both are always in view.
        const key = (me ? 1 : 0) + (busLoc ? 2 : 0);
        if (!map || (key <= fitKey && !force)) return;
        const pts = [];
        if (me) pts.push([me.lat, me.lng]);
        if (busLoc) pts.push([busLoc.lat, busLoc.lng]);
        if (!pts.length) return;
        if (pts.length === 1) map.setView(pts[0], 16, { animate: false });
        else map.fitBounds(pts, { padding: [70, 70], maxZoom: 16, paddingBottomRight: [70, (sheet.offsetHeight || 260) + 40] });
        fitKey = key;
    }

    /* ---------- state -> UI ---------- */
    function refresh() {
        const fresh = busLoc && busLoc.age <= FRESH_MS;
        const eta = el('pickupEta'), hint = el('pickupHint');
        if (map) {
            if (busLoc) {
                if (!busMarker) busMarker = L.marker([busLoc.lat, busLoc.lng], { icon: busIcon(!fresh), title: 'Bus ' + cfg.bus }).addTo(map);
                else { busMarker.setLatLng([busLoc.lat, busLoc.lng]); busMarker.setIcon(busIcon(!fresh)); }
            } else if (busMarker) { map.removeLayer(busMarker); busMarker = null; }
            if (line) { map.removeLayer(line); line = null; }
            if (busLoc && me) line = L.polyline([[me.lat, me.lng], [busLoc.lat, busLoc.lng]], { color: '#1e90ff', weight: 3, dashArray: '2 8', opacity: .8 }).addTo(map);
            if (youMarker) { youMarker.setIcon(youIcon()); if (youMarker.dragging) waiting ? youMarker.dragging.disable() : youMarker.dragging.enable(); }
        }

        if (busLoc && me) {
            const d = km(me, busLoc), mins = Math.max(1, Math.round(d / 25 * 60));
            eta.innerHTML = fresh ? dist(d) + ' away <small>about ' + mins + ' min</small>' : 'Bus last seen ' + dist(d) + ' away';
        } else if (busLoc) {
            eta.textContent = 'Bus is live';
        } else {
            eta.textContent = cfg.status === 'inactive' ? 'Bus is not in service yet' : 'Waiting for the bus location';
        }

        const canMark = !!me && cfg.status !== 'inactive';
        el('pickupWait').hidden = waiting;
        el('pickupWait').disabled = !canMark || busy;
        el('pickupCancel').hidden = !waiting;
        el('pickupLabelWrap').hidden = waiting;
        hint.textContent = waiting
            ? "You're marked as waiting. The driver can see your spot."
            : cfg.status === 'inactive'
                ? 'You can mark yourself as waiting once this bus starts boarding (' + cfg.departure + ').'
                : me ? 'Drag the blue pin to where you will wait.' : 'Allow location, or tap the target button, to place your pin.';
        sizeSheet();
    }

    /* ---------- network ---------- */
    async function api(options, url) {
        const res = await fetch(url || ENDPOINT, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
        let data;
        try { data = await res.json(); } catch (_) { throw new Error('Unreadable response.'); }
        if (data && data.csrf_token) csrf = data.csrf_token;
        if (!res.ok || !data.ok) throw new Error(data.message || 'Something went wrong.');
        return data;
    }
    const post = fields => api({
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams(Object.assign({ csrf_token: csrf, schedule_id: String(cfg.scheduleId) }, fields))
    });

    async function poll() {
        clearTimeout(timer);
        if (!document.hidden) {
            try {
                const data = await api(null, ENDPOINT + '?schedule_id=' + cfg.scheduleId);
                serverNow = Date.parse(data.server_time) || Date.now();
                const active = (data.schedules || []).some(t => Number(t.schedule_id) === cfg.scheduleId);
                cfg.status = active ? 'active' : 'inactive';
                const loc = (data.locations || []).find(l => Number(l.schedule_id) === cfg.scheduleId);
                const lat = loc && num(loc.latitude), lng = loc && num(loc.longitude);
                busLoc = lat !== null && lng !== null ? { lat, lng, age: Math.max(0, serverNow - (Date.parse(loc.updated_at) || 0)) } : null;
                const mine = data.my_request;
                waiting = !!(mine && mine.status === 'Waiting' && Number(mine.schedule_id) === cfg.scheduleId);
                if (waiting && mine && !pinMoved) {
                    const mlat = num(mine.latitude), mlng = num(mine.longitude);
                    if (mlat !== null && mlng !== null) { me = { lat: mlat, lng: mlng }; if (youMarker) youMarker.setLatLng([mlat, mlng]); else setMe(mlat, mlng, false); }
                }
                fit(false);
                refresh();
            } catch (e) { toast(e.message); }
        }
        timer = setTimeout(poll, 5000);
    }
    let pinMoved = false;

    /* ---------- actions ---------- */
    function locate(pan) {
        if (!navigator.geolocation) { toast('This browser cannot share location. Tap the map to set your pin.'); return; }
        navigator.geolocation.getCurrentPosition(p => { pinMoved = true; setMe(p.coords.latitude, p.coords.longitude, pan !== false); },
            e => toast(e && e.code === 1 ? 'Location is blocked. Tap the map to place your pin.' : 'Could not get your location. Tap the map to place your pin.'),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    }

    el('pickupLocate').addEventListener('click', () => locate(true));
    el('pickupWait').addEventListener('click', async () => {
        if (!me || busy) return;
        busy = true; refresh();
        try {
            await post({ action: 'mark_waiting', latitude: String(me.lat), longitude: String(me.lng), pickup_label: el('pickupLabel').value.trim() });
            waiting = true; pinMoved = true; toast("You're waiting. The driver can see you.");
        } catch (e) { toast(e.message); }
        busy = false; refresh();
    });
    el('pickupCancel').addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        try { await post({ action: 'cancel_waiting' }); waiting = false; toast('Waiting cancelled.'); }
        catch (e) { toast(e.message); }
        busy = false; refresh();
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.addEventListener('resize', () => { sizeSheet(); if (map) map.invalidateSize(); });

    el('pickupBack').addEventListener('click', e => {
        if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) { e.preventDefault(); history.back(); }
    });

    initMap();
    if (map) map.on('click', e => { if (!waiting) { pinMoved = true; setMe(e.latlng.lat, e.latlng.lng, false); } });
    cfg.status = 'active';
    refresh();
    locate(true);
    poll();
})();
