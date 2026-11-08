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
    let map = null, layer = null, line = null, routeLayer = null, selected = null, fittedOnce = false;
    let routeKey = '', routeData = null, routeFitKey = '', routeRequest = 0, routeFailure = null, routeViewMode = 'focus';
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
            map = L.map('staffMap', { zoomControl: false }).setView([14.6798552, 120.2728757], 13);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · routing by OSRM' })
                .addTo(map).on('tileerror', () => { el('staffMapError').hidden = false; });
            routeLayer = L.layerGroup().addTo(map);
            layer = L.layerGroup().addTo(map);
            map.on('click', () => { if (selected) { selected = null; render(); } });
        } catch (e) { map = null; el('staffMapError').hidden = false; }
    }
    const FALLBACK_AVATAR = '../Assets/images/mbus_icon.png';
    const busIcon = (no, stale) => L.divIcon({ className: '', iconSize: [84, 28], iconAnchor: [42, 14],
        html: '<span class="sw-bus' + (stale ? ' is-stale' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i>' + String(no || 'Bus').replace(/[<>&"]/g, '') + '</span>' });
    const isAdmin = root.dataset.role === 'admin';
    const photoOf = r => r && r.has_pic ? '../avatar.php?u=' + encodeURIComponent(r.user_id) + '&v=' + encodeURIComponent(r.pic_v || '') : avatarUrl(r && r.profile_picture);
    const avatarUrl = picture => {
        const filename = String(picture || '').replace(/\\/g, '/').split('/').pop();
        return filename && filename.toLowerCase() !== 'default.png' && /^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|gif|webp)$/i.test(filename)
            ? '../Assets/profile_pictures/' + encodeURIComponent(filename)
            : FALLBACK_AVATAR;
    };
    function bindAvatarFallback(img) {
        if (!img) return;
        img.addEventListener('error', () => {
            if (img.src !== new URL(FALLBACK_AVATAR, location.href).href) img.src = FALLBACK_AVATAR;
        }, { once: true });
    }
    const personIcon = (name, on, r) => L.divIcon({ className: '', iconSize: [38, 38], iconAnchor: [19, 19],
        html: '<span class="sw-person' + (on ? ' is-on' : '') + '"><img src="' + photoOf(r) + '" alt=""></span>' });

    function fit(points) {
        if (!map || !points.length) return;
        if (points.length === 1) { map.setView(points[0], 16, { animate: true }); return; }
        map.fitBounds(points, { padding: [60, 60], maxZoom: 16, paddingBottomRight: [60, (document.querySelector('.sw-sheet') || {}).offsetHeight + 80 || 300], paddingTopLeft: [60, 100] });
    }

    /* ---------- render ---------- */
    function busFor(scheduleId) { return locations.find(l => Number(l.schedule_id) === Number(scheduleId) && validPt(l.latitude, l.longitude)); }

    function updateRouteButton() {
        const button = el('staffRouteView');
        if (!button) return;
        button.disabled = !routeData;
        button.textContent = routeViewMode === 'route' ? 'Focus bus' : 'Full route';
        button.setAttribute('aria-pressed', routeViewMode === 'route' ? 'true' : 'false');
    }

    function toggleStaffRoute() {
        if (!map || !routeData) return;
        const selectedId = filter.value || (schedules.length === 1 ? String(schedules[0].schedule_id) : '');
        const trip = schedules.find(s => String(s.schedule_id) === selectedId);
        if (!trip) return;
        if (routeViewMode === 'focus') {
            routeViewMode = 'route';
            const bounds = L.latLngBounds(routeData.points);
            const bus = busFor(trip.schedule_id);
            const rider = requests.find(r => r.request_id === selected && String(r.schedule_id) === selectedId);
            if (bus) bounds.extend([Number(bus.latitude), Number(bus.longitude)]);
            if (rider && validPt(rider.latitude, rider.longitude)) bounds.extend([Number(rider.latitude), Number(rider.longitude)]);
            map.fitBounds(bounds.pad(0.08), { paddingTopLeft: [24, 105], paddingBottomRight: [24, 240], maxZoom: 12, animate: false });
        } else {
            routeViewMode = 'focus';
            const bus = busFor(trip.schedule_id);
            const rider = requests.find(r => r.request_id === selected && String(r.schedule_id) === selectedId);
            const points = [];
            if (rider && validPt(rider.latitude, rider.longitude)) points.push([Number(rider.latitude), Number(rider.longitude)]);
            if (bus) points.push([Number(bus.latitude), Number(bus.longitude)]);
            if (points.length) fit(points);
            else map.setView([Number(trip.origin_lat), Number(trip.origin_lng)], 14, { animate: false });
        }
        updateRouteButton();
    }

    function updateStaffRoute() {
        const box = el('staffRouteEstimate');
        if (!box) return;
        const selectedId = filter.value || (schedules.length === 1 ? String(schedules[0].schedule_id) : '');
        const trip = schedules.find(s => String(s.schedule_id) === selectedId);
        if (!trip) {
            routeRequest++;
            routeKey = ''; routeData = null; routeFailure = null;
            if (routeLayer) routeLayer.clearLayers();
            box.textContent = schedules.length > 1 ? 'Choose a bus to see its route and destination estimate.' : 'No active route is available.';
            updateRouteButton();
            return;
        }
        const rawPoints = [trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng];
        if (rawPoints.some(value => value === null || value === undefined || String(value).trim() === '')) {
            routeRequest++;
            routeKey = ''; routeData = null; routeFailure = null;
            box.textContent = 'This bus route needs terminal coordinates before an estimate is available.';
            if (routeLayer) routeLayer.clearLayers();
            updateRouteButton();
            return;
        }
        const origin = { lat: Number(trip.origin_lat), lng: Number(trip.origin_lng) };
        const destination = { lat: Number(trip.destination_lat), lng: Number(trip.destination_lng) };
        if (![origin.lat, origin.lng, destination.lat, destination.lng].every(Number.isFinite)) {
            routeRequest++;
            routeKey = ''; routeData = null; routeFailure = null;
            box.textContent = 'This bus route needs valid terminal coordinates before an estimate is available.';
            if (routeLayer) routeLayer.clearLayers();
            updateRouteButton();
            return;
        }
        const key = String(trip.schedule_id) + ':' + [origin.lat, origin.lng, destination.lat, destination.lng].join(',');
        if (routeKey !== key) {
            routeKey = key; routeData = null; routeFailure = null;
            const thisRequest = ++routeRequest;
            if (routeLayer) routeLayer.clearLayers();
            box.textContent = 'Loading the road route to ' + (trip.destination_label || trip.destination || 'the destination') + '…';
            updateRouteButton();
            if (!window.MBUSRouting || !map) { box.textContent = 'Road directions are unavailable; live bus and rider pins remain visible.'; return; }
            window.MBUSRouting.getDrivingRoute(origin, destination).then(route => {
                if (thisRequest !== routeRequest || routeKey !== key) return;
                routeData = route;
                routeFailure = null;
                routeLayer.clearLayers();
                L.polyline(route.points, { color: '#1e90ff', weight: 5, opacity: 0.7 }).addTo(routeLayer);
                L.circleMarker(route.points[0], { radius: 5, color: '#fff', weight: 2, fillColor: '#1e90ff', fillOpacity: 1 }).addTo(routeLayer);
                L.circleMarker(route.points[route.points.length - 1], { radius: 5, color: '#fff', weight: 2, fillColor: '#18365d', fillOpacity: 1 }).addTo(routeLayer);
                routeFitKey = key;
                if (routeViewMode === 'route') map.fitBounds(L.latLngBounds(route.points).pad(0.08), { maxZoom: 12, animate: false });
                updateRouteButton();
                updateStaffRoute();
            }).catch(error => {
                if (thisRequest !== routeRequest || routeKey !== key) return;
                routeFailure = { key, until: Date.now() + 30000 };
                updateRouteButton();
                box.textContent = (error.message || 'Road directions are unavailable right now.') + ' Live bus and rider pins remain visible.';
            });
            return;
        }
        if (!routeData) {
            if (routeFailure && routeFailure.key === key && Date.now() < routeFailure.until) return;
            routeKey = '';
            updateStaffRoute();
            return;
        }
        const destinationName = trip.destination_label || (window.MBUSRouting ? window.MBUSRouting.displayName(trip.destination) : trip.destination) || 'destination';
        const bus = busFor(trip.schedule_id);
        if (!bus) {
            box.textContent = 'To ' + destinationName + ': ' + window.MBUSRouting.formatDistance(routeData.distanceMeters) + ' road route · ' + window.MBUSRouting.formatDuration(routeData.durationSeconds) + ' driving estimate. Waiting for the first live bus pin.';
            return;
        }
        const age = Date.now() - Date.parse(bus.updated_at);
        if (!Number.isFinite(age) || age > FRESH_MS) {
            box.textContent = 'Last bus pin ' + ago(bus.updated_at) + '. Live destination estimate returns when GPS is fresh.';
            return;
        }
        const remaining = window.MBUSRouting.remainingOnRoute(routeData, [Number(bus.latitude), Number(bus.longitude)], 2500);
        if (!remaining || !remaining.onRoute) {
            box.textContent = 'Bus location is away from the mapped route. Destination ETA is unavailable until it returns to the route.';
            return;
        }
        box.textContent = 'To ' + destinationName + ': ' + window.MBUSRouting.formatDistance(remaining.distanceMeters) + ' on the road · ' + window.MBUSRouting.formatDuration(remaining.durationSeconds) + '. Estimate excludes traffic, stops and detours.';
    }

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
                const m = L.marker(p, { icon: personIcon(r.full_name, on, r), title: r.full_name || 'Commuter' }).addTo(layer);
                bindAvatarFallback(m.getElement() && m.getElement().querySelector('.sw-person img'));
                m.on('click', () => { choose(r); if (isAdmin) showDetails(r); });
                if (on) {
                    const b = busFor(r.schedule_id);
                    if (b) line = L.polyline([p, [+b.latitude, +b.longitude]], { color: '#1e90ff', weight: 3, dashArray: '2 8', opacity: .85 }).addTo(map);
                }
            }
            const li = node('li', undefined, 'sw-row' + (on ? ' is-on' : ''));
            const open = node('button', undefined, 'sw-row-main'); open.type = 'button';
            const avatar = node('img', undefined, 'sw-avatar');
            avatar.alt = '';
            bindAvatarFallback(avatar);
            avatar.src = photoOf(r);
            open.append(avatar);
            const txt = node('span', undefined, 'sw-row-text');
            txt.append(node('strong', r.full_name || 'Commuter'),
                node('small', (r.pickup_label || 'Pinned spot') + (r.bus_number ? ' · ' + r.bus_number : '') + ' · ' + ago(r.updated_at)));
            open.append(txt);
            if (r._km !== null) open.append(node('span', dist(r._km), 'sw-dist'));
            open.addEventListener('click', () => choose(r));
            if (isAdmin) {
                const more = node('button', 'Details', 'sw-done sw-details'); more.type = 'button';
                more.addEventListener('click', () => showDetails(r));
                li.append(open, more);
            } else {
                const done = node('button', 'Picked up', 'sw-done'); done.type = 'button';
                done.disabled = !ready || !csrf || busy;
                done.addEventListener('click', () => pickedUp(r, done));
                li.append(open, done);
            }
            list.append(li);
        });

        if (!fittedOnce && pts.length) { fit(pts); fittedOnce = true; }
        updateStaffRoute();
        if (map) requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        sizeSheet();
    }

    function showDetails(r) {
        const old = document.getElementById('swCard'); if (old) old.remove();
        const b = busFor(r.schedule_id);
        const d = (b && validPt(r.latitude, r.longitude)) ? km({ lat: +r.latitude, lng: +r.longitude }, { lat: +b.latitude, lng: +b.longitude }) : null;
        const back = node('div', undefined, 'sw-card-back'); back.id = 'swCard';
        const card = node('div', undefined, 'sw-card'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'Waiting passenger details');
        const head = node('div', undefined, 'sw-card-head');
        const img = node('img', undefined, 'sw-card-photo'); img.alt = ''; bindAvatarFallback(img); img.src = photoOf(r);
        const who = node('div'); who.append(node('strong', r.full_name || 'Commuter'), node('small', (r.passenger_type || 'Regular') + ' passenger'));
        head.append(img, who);
        const rows = [
            ['Bus', (r.bus_number || '-') + (r.origin ? ' · ' + r.origin + ' to ' + (r.route_destination || '') : '')],
            ['Pickup spot', r.pickup_label || 'Pinned spot'],
            ['Distance from bus', d === null ? 'Bus location unavailable' : dist(d)],
            ['Waiting since', ago(r.updated_at) || '-'],
            ['Phone', r.phone_number && String(r.phone_number).length > 3 ? r.phone_number : 'Not provided']
        ];
        const dl = node('dl', undefined, 'sw-card-list');
        rows.forEach(([k, v]) => { dl.append(node('dt', k), node('dd', v)); });
        const close = node('button', 'Close', 'sw-card-close'); close.type = 'button';
        card.append(head, dl, close); back.append(card); document.body.append(back);
        const shut = () => { back.remove(); document.removeEventListener('keydown', esc); };
        const esc = e => { if (e.key === 'Escape') shut(); };
        close.addEventListener('click', shut); back.addEventListener('click', e => { if (e.target === back) shut(); });
        document.addEventListener('keydown', esc); close.focus();
    }
    function choose(r) {
        selected = selected === r.request_id ? null : r.request_id;
        routeViewMode = 'focus';
        updateRouteButton();
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
        if (!cur && seen.size === 1) cur = Array.from(seen)[0];
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

    filter.addEventListener('change', () => { selected = null; fittedOnce = false; routeFitKey = ''; routeViewMode = 'focus'; render(); });
    el('staffRefresh').addEventListener('click', refresh);
    el('staffRouteView').addEventListener('click', toggleStaffRoute);
    window.addEventListener('resize', () => { sizeSheet(); if (map) map.invalidateSize(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    initMap();
    refresh();
    const timer = setInterval(() => { if (!document.hidden && !busy) refresh(); }, 8000);
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
})();
