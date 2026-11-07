/* Commuter live buses: same map and bus pins as the operator board, buses only. */
(function () {
    'use strict';
    const el = id => document.getElementById(id);
    const select = el('liveSchedule');
    if (!select) return;

    const FRESH_MS = 120000;
    let trips = [], locations = [], serverNow = Date.now();
    let chosen = String((window.mbusLive || {}).scheduleId || '');
    let map = null, markerLayer = null, pollTimer = null, loading = false, version = 0, fitted = false;
    const visible = () => !document.hidden;

    function number(v) { const n = Number(v); return Number.isFinite(n) ? n : null; }
    function valid(lat, lng) {
        const a = number(lat), b = number(lng);
        return a !== null && b !== null && Math.abs(a) <= 90 && Math.abs(b) <= 180;
    }
    function escapeHtml(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function ageOf(location) {
        const t = location && Date.parse(location.updated_at);
        return Number.isFinite(t) ? Math.max(0, serverNow - t) : Infinity;
    }
    function ago(ms) {
        if (!Number.isFinite(ms)) return '';
        const s = Math.floor(ms / 1000);
        if (s < 60) return s + ' sec ago';
        const m = Math.floor(s / 60);
        return m < 60 ? m + ' min ago' : Math.floor(m / 60) + ' hr ago';
    }
    function notify(text) {
        const box = el('liveMessage');
        box.hidden = !text;
        box.textContent = text || '';
    }
    function routeOf(t) { return (t.origin || 'Origin') + ' to ' + (t.destination || t.route_destination || 'Endpoint'); }

    function initMap() {
        const box = el('trackingMap');
        if (typeof L === 'undefined') {
            box.hidden = true;
            el('mapMessage').hidden = false;
            el('mapMessage').textContent = 'The map could not load. The bus list below still shows who is on the road.';
            return;
        }
        try {
            map = L.map('trackingMap', { zoomControl: true }).setView([14.678, 120.266], 15);
            const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
            tiles.on('tileerror', () => { el('mapMessage').hidden = false; el('mapMessage').textContent = 'Map tiles could not load. Bus status below is still current.'; });
            markerLayer = L.layerGroup().addTo(map);
        } catch (e) {
            map = null;
            box.hidden = true;
        }
    }

    function busIcon(busNo, stale, picked) {
        return L.divIcon({
            className: 'board-bus-marker',
            html: '<span class="board-bus-pin' + (stale ? ' is-stale' : '') + (picked ? ' is-picked' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i>' + escapeHtml(busNo || 'Bus') + '</span>',
            iconSize: [74, 28],
            iconAnchor: [37, 14]
        });
    }

    function stateOf(trip) {
        const loc = locations.find(l => String(l.schedule_id) === String(trip.schedule_id));
        if (!loc || !valid(loc.latitude, loc.longitude)) return { kind: 'none', loc: null, label: 'No GPS yet' };
        const age = ageOf(loc);
        return age <= FRESH_MS ? { kind: 'live', loc, label: 'Live ' + ago(age) } : { kind: 'stale', loc, label: 'Last seen ' + ago(age) };
    }

    function pick(id) {
        chosen = id ? String(id) : '';
        select.value = chosen;
        fitted = false;
        render();
    }

    function render() {
        const states = trips.map(t => ({ trip: t, st: stateOf(t) }));
        const liveCount = states.filter(x => x.st.kind === 'live').length;
        el('busCount').textContent = String(liveCount);
        el('busLabel').textContent = liveCount === 1 ? 'bus live' : 'buses live';
        el('trackingStatus').textContent = String(trips.length);

        // filter select, same shape as the operator board
        select.replaceChildren(new Option('All buses', ''));
        trips.forEach(t => select.add(new Option((t.bus_number || 'Bus') + ' · ' + routeOf(t), String(t.schedule_id))));
        if (chosen && !trips.some(t => String(t.schedule_id) === chosen)) chosen = '';
        select.value = chosen;
        select.disabled = !trips.length;

        // list
        const list = el('busList');
        list.replaceChildren();
        el('busEmpty').hidden = trips.length > 0;
        if (!trips.length) el('busEmpty').textContent = 'No bus is boarding or on the road right now. Check again when your bus is in service.';
        states.forEach(({ trip, st }) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'bus-row is-' + st.kind + (String(trip.schedule_id) === chosen ? ' is-picked' : '');
            row.disabled = st.kind === 'none';
            const icon = document.createElement('span'); icon.className = 'bus-row-icon'; icon.innerHTML = '<i class="fa fa-bus" aria-hidden="true"></i>';
            const main = document.createElement('span'); main.className = 'bus-row-main';
            const name = document.createElement('span'); name.className = 'bus-row-name'; name.textContent = trip.bus_number || 'Bus';
            const route = document.createElement('span'); route.className = 'bus-row-route'; route.textContent = routeOf(trip);
            main.append(name, route);
            const state = document.createElement('span'); state.className = 'bus-row-state'; state.textContent = st.label;
            row.append(icon, main, state);
            row.addEventListener('click', () => pick(String(trip.schedule_id) === chosen ? '' : trip.schedule_id));
            const wrap = document.createElement('div'); wrap.className = 'bus-row-wrap';
            const go = document.createElement('a'); go.className = 'bus-row-pick'; go.href = 'pickup.php?schedule_id=' + encodeURIComponent(trip.schedule_id); go.textContent = 'Pick up';
            go.setAttribute('aria-label', 'Pick up at bus ' + (trip.bus_number || ''));
            wrap.append(row, go);
            list.append(wrap);
        });

        // map pins
        if (map && markerLayer) {
            markerLayer.clearLayers();
            const bounds = [];
            let focus = null;
            states.forEach(({ trip, st }) => {
                if (!st.loc) return;
                const picked = String(trip.schedule_id) === chosen;
                if (chosen && !picked) return;
                const point = [Number(st.loc.latitude), Number(st.loc.longitude)];
                bounds.push(point);
                if (picked) focus = point;
                const popup = document.createElement('div');
                const strong = document.createElement('strong'); strong.textContent = 'Bus ' + (trip.bus_number || '');
                const popGo = document.createElement('a'); popGo.className = 'bus-pop-pick'; popGo.href = 'pickup.php?schedule_id=' + encodeURIComponent(trip.schedule_id); popGo.textContent = 'Pick up';
                popup.append(strong, document.createElement('br'), document.createTextNode(st.label), document.createElement('br'), popGo);
                L.marker(point, { icon: busIcon(trip.bus_number, st.kind === 'stale', false), title: 'Bus ' + (trip.bus_number || '') })
                    .addTo(markerLayer).bindPopup(popup);
            });
            if (!fitted && bounds.length) {
                if (focus) map.setView(focus, 16, { animate: false });
                else if (bounds.length === 1) map.setView(bounds[0], 16, { animate: false });
                else map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
                fitted = true;
            }
            window.requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        }

        const mapNote = el('mapMessage');
        if (map && trips.length && !states.some(x => x.st.loc)) {
            mapNote.hidden = false;
            mapNote.textContent = 'No bus is sharing its location yet. A pin appears as soon as the driver starts.';
        } else if (map && !mapNote.textContent.startsWith('Map tiles')) {
            mapNote.hidden = true;
        }
    }

    async function request(url) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Live tracking returned an unreadable response.'); }
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load live buses.');
            return data;
        } finally { clearTimeout(timeout); }
    }

    async function load() {
        if (loading || !visible()) return;
        loading = true;
        const thisVersion = ++version;
        try {
            const data = await request('../api/live_tracking.php');
            if (thisVersion !== version) return;
            serverNow = Date.parse(data.server_time) || Date.now();
            trips = (Array.isArray(data.schedules) ? data.schedules : []).filter(t => t && t.schedule_id != null);
            locations = Array.isArray(data.locations) ? data.locations : [];
            notify('');
            render();
        } catch (e) {
            if (thisVersion === version) notify(e.message);
        } finally {
            loading = false;
            if (thisVersion === version && visible()) pollTimer = setTimeout(load, 9000);
        }
    }

    select.addEventListener('change', () => pick(select.value));
    document.addEventListener('visibilitychange', () => { if (!visible()) clearTimeout(pollTimer); else load(); });
    initMap();
    load();
})();
