/* Staff waiting map: your bus and the riders waiting for it, with distance (same idea as the
   commuter pick up page). Operator sees own buses, admin sees all. */
(() => {
    'use strict';
    const root = document.getElementById('pickupStaff');
    if (!root) return;

    const pickupEndpoint = root.dataset.endpoint;
    const liveEndpoint = root.dataset.liveEndpoint || '../api/live_tracking.php';
    const FRESH_MS = 120000;
    const el = id => document.getElementById(id);
    const list = el('staffList'), empty = el('staffEmpty'), filter = el('staffSchedule');

    let requests = [], schedules = [], locations = [], csrf = '', ready = false;
    let map = null, layer = null, line = null, selected = null, fittedOnce = false;
    let busy = false, refreshing = null;

    const num = v => { const n = Number(v); return Number.isFinite(n) ? n : null; };
    const validPt = (a, b) => num(a) !== null && num(b) !== null && Math.abs(a) <= 90 && Math.abs(b) <= 180;
    const node = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
    const ago = v => { const t = Date.parse(v); if (!Number.isFinite(t)) return ''; const s = Math.max(0, (Date.now() - t) / 1000); return s < 45 ? 'just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : Math.round(s / 3600) + ' h ago'; };
    function km(a, b) {
        const R = 6371, r = d => d * Math.PI / 180, dLa = r(b.lat - a.lat), dLo = r(b.lng - a.lng);
        const h = Math.sin(dLa / 2) ** 2 + Math.cos(r(a.lat)) * Math.cos(r(b.lat)) * Math.sin(dLo / 2) ** 2;
        return 2 * R * Math.asin(Math.sqrt(h));
    }
    const dist = d => d < 1 ? Math.max(10, Math.round(d * 100) * 10) + ' m' : d.toFixed(1) + ' km';

    function say(text, soft) {
        const box = el('staffNotice');
        box.textContent = text || ''; box.hidden = !text;
        box.className = 'sw-notice' + (soft ? ' sw-notice-ok' : '');
        if (text && soft) setTimeout(() => { if (box.textContent === text) box.hidden = true; }, 4000);
    }
    function sizeSheet() {
        const sheet = document.querySelector('.sw-sheet');
        document.documentElement.style.setProperty('--sw-sheet-h', (sheet ? sheet.offsetHeight : 0) + 'px');
    }

    /* ---------- map ---------- */
    function initMap() {
        if (!window.L) { el('staffMapError').hidden = false; return; }
        try {
            map = L.map('staffMap', { zoomControl: false }).setView([14.678, 120.266], 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' })
                .addTo(map).on('tileerror', () => { el('staffMapError').hidden = false; });
            layer = L.layerGroup().addTo(map);
            map.on('click', () => { if (selected) { selected = null; render(); } });
        } catch (e) { map = null; el('staffMapError').hidden = false; }
    }
    const busIcon = (no, stale) => L.divIcon({ className: '', iconSize: [84, 28], iconAnchor: [42, 14],
        html: '<span class="sw-bus' + (stale ? ' is-stale' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i>' + String(no || 'Bus').replace(/[<>&"]/g, '') + '</span>' });
    const personIcon = (name, on) => L.divIcon({ className: '', iconSize: [34, 34], iconAnchor: [17, 17],
        html: '<span class="sw-person' + (on ? ' is-on' : '') + '">' + String(name || '?').trim().charAt(0).toUpperCase().replace(/[<>&"]/g, '') + '</span>' });

    function fit(points) {
        if (!map || !points.length) return;
        if (points.length === 1) { map.setView(points[0], 16, { animate: true }); return; }
        map.fitBounds(points, { padding: [60, 60], maxZoom: 16, paddingBottomRight: [60, (document.querySelector('.sw-sheet') || {}).offsetHeight + 80 || 300], paddingTopLeft: [60, 100] });
    }

    /* ---------- render ---------- */
    function busFor(scheduleId) { return locations.find(l => Number(l.schedule_id) === Number(scheduleId) && validPt(l.latitude, l.longitude)); }

    function render() {
        const sel = filter.value;
        const reqs = requests.filter(r => !sel || String(r.schedule_id) === sel);
        const buses = locations.filter(l => (!sel || String(l.schedule_id) === sel) && validPt(l.latitude, l.longitude));

        // distance from each rider to the bus of their trip
        reqs.forEach(r => {
            const b = busFor(r.schedule_id), ok = validPt(r.latitude, r.longitude);
            r._km = b && ok ? km({ lat: +r.latitude, lng: +r.longitude }, { lat: +b.latitude, lng: +b.longitude }) : null;
        });
        reqs.sort((a, b) => (a._km === null) - (b._km === null) || a._km - b._km);

        el('staffCount').textContent = String(reqs.length);
        el('busLabel').textContent = buses.length ? buses.length + (buses.length === 1 ? ' bus live' : ' buses live') : 'No bus location yet';
        empty.hidden = reqs.length > 0 || !ready;
        empty.textContent = ready ? 'No one is waiting right now.' : 'Waiting is not available yet.';

        if (layer) layer.clearLayers();
        if (line && map) { map.removeLayer(line); line = null; }
        const pts = [];
        buses.forEach(b => {
            const stale = Date.now() - Date.parse(b.updated_at) > FRESH_MS, p = [+b.latitude, +b.longitude];
            pts.push(p);
            if (layer) L.marker(p, { icon: busIcon(b.bus_number, stale), title: 'Bus ' + b.bus_number, zIndexOffset: 500 }).addTo(layer);
        });
        list.replaceChildren();
        reqs.forEach(r => {
            const on = selected === r.request_id;
            const ok = validPt(r.latitude, r.longitude), p = ok ? [+r.latitude, +r.longitude] : null;
            if (p && map) {
                pts.push(p);
                const m = L.marker(p, { icon: personIcon(r.full_name, on), title: r.full_name || 'Commuter' }).addTo(layer);
                m.on('click', () => choose(r));
                if (on) {
                    const b = busFor(r.schedule_id);
                    if (b) line = L.polyline([p, [+b.latitude, +b.longitude]], { color: '#1e90ff', weight: 3, dashArray: '2 8', opacity: .85 }).addTo(map);
                }
            }
            const li = node('li', undefined, 'sw-row' + (on ? ' is-on' : ''));
            const open = node('button', undefined, 'sw-row-main'); open.type = 'button';
            open.append(node('span', String(r.full_name || '?').trim().charAt(0).toUpperCase(), 'sw-avatar'));
            const txt = node('span', undefined, 'sw-row-text');
            txt.append(node('strong', r.full_name || 'Commuter'),
                node('small', (r.pickup_label || 'Pinned spot') + (r.bus_number ? ' · ' + r.bus_number : '') + ' · ' + ago(r.updated_at)));
            open.append(txt);
            if (r._km !== null) open.append(node('span', dist(r._km), 'sw-dist'));
            open.addEventListener('click', () => choose(r));
            const done = node('button', 'Picked up', 'sw-done'); done.type = 'button';
            done.disabled = !ready || !csrf || busy;
            done.addEventListener('click', () => pickedUp(r, done));
            li.append(open, done);
            list.append(li);
        });

        if (!fittedOnce && pts.length) { fit(pts); fittedOnce = true; }
        if (map) requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        sizeSheet();
    }

    function choose(r) {
        selected = selected === r.request_id ? null : r.request_id;
        render();
        if (selected) {
            const b = busFor(r.schedule_id), pts = [];
            if (validPt(r.latitude, r.longitude)) pts.push([+r.latitude, +r.longitude]);
            if (b) pts.push([+b.latitude, +b.longitude]);
            fit(pts);
        }
    }

    function updateFilter() {
        let cur = filter.value;
        filter.replaceChildren(new Option('All buses', ''));
        const seen = new Set();
        const add = (id, label) => { id = String(id); if (!seen.has(id)) { seen.add(id); filter.append(new Option(label, id)); } };
        schedules.forEach(s => add(s.schedule_id, 'Bus ' + s.bus_number));
        locations.forEach(b => add(b.schedule_id, 'Bus ' + b.bus_number));
        if (root.dataset.role === 'operator' && !cur && seen.size === 1) cur = Array.from(seen)[0];
        if ([...filter.options].some(o => o.value === cur)) filter.value = cur;
        filter.parentElement.hidden = seen.size < 2 && root.dataset.role === 'operator';
    }

    /* ---------- network ---------- */
    async function get(url) {
        const c = new AbortController(), t = setTimeout(() => c.abort(), 12000);
        try {
            const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: c.signal });
            let d; try { d = await res.json(); } catch (e) { throw new Error('Unreadable response.'); }
            if (!res.ok || d.ok !== true) throw new Error(d.message || 'Service unavailable.');
            return d;
        } finally { clearTimeout(t); }
    }
    function refresh() {
        if (refreshing) return refreshing;
        el('staffRefresh').disabled = true;
        refreshing = (async () => {
            try {
                const [p, l] = await Promise.allSettled([get(pickupEndpoint), get(liveEndpoint)]);
                if (p.status === 'fulfilled') {
                    ready = p.value.ready === true; csrf = p.value.csrf_token || '';
                    requests = Array.isArray(p.value.requests) ? p.value.requests : [];
                    schedules = Array.isArray(p.value.schedules) ? p.value.schedules : [];
                    if (!ready) say(p.value.message || 'Waiting is not set up yet.');
                } else { ready = false; requests = []; say(p.reason && p.reason.message || 'Could not load waiting riders.'); }
                locations = l.status === 'fulfilled' && Array.isArray(l.value.locations) ? l.value.locations : [];
                if (selected && !requests.some(r => r.request_id === selected)) selected = null;
                updateFilter(); render();
                el('staffUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } finally { refreshing = null; el('staffRefresh').disabled = false; }
        })();
        return refreshing;
    }
    async function pickedUp(r, btn) {
        if (busy) return;
        busy = true; btn.disabled = true;
        try {
            const res = await fetch(pickupEndpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ csrf_token: csrf, action: 'picked_up', request_id: String(r.request_id) }) });
            const d = await res.json();
            if (!res.ok || d.ok !== true) throw new Error(d.message || 'Could not mark as picked up.');
            say((r.full_name || 'Rider') + ' picked up.', true);
            requests = requests.filter(x => x.request_id !== r.request_id);
            if (selected === r.request_id) selected = null;
            render(); busy = false; await refresh();
        } catch (e) { say(e.message); }
        busy = false; btn.disabled = false;
    }

    filter.addEventListener('change', () => { selected = null; fittedOnce = false; render(); });
    el('staffRefresh').addEventListener('click', refresh);
    window.addEventListener('resize', () => { sizeSheet(); if (map) map.invalidateSize(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    initMap();
    refresh();
    const timer = setInterval(() => { if (!document.hidden && !busy) refresh(); }, 8000);
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
})();
