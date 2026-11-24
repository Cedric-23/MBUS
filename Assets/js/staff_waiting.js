/* Staff waiting map: assigned buses and the riders waiting for them. Drivers and Conductors
   see their own buses; Admin sees all. Only Conductors can confirm a pick-up. */
(() => {
    'use strict';
    const root = document.getElementById('pickupStaff');
    if (!root) return;

    const pickupEndpoint = root.dataset.endpoint;
    const liveEndpoint = root.dataset.liveEndpoint || '../api/live_tracking.php';
    const FRESH_MS = 120000;
    const STOP_COORDS = window.MBUS_STOP_COORDS || {};
    const el = id => document.getElementById(id);
    const list = el('staffList'), empty = el('staffEmpty'), filter = el('staffSchedule');
    const rList = el('staffReserveList'), rEmpty = el('staffReserveEmpty');

    let requests = [], reservations = [], schedules = [], locations = [], csrf = '', ready = false;
    const hiddenReservationKeys = new Set();
    let trackedPin = '';
    let map = null, layer = null, line = null, routeLayer = null, selected = null, fittedOnce = false;
    let routeKey = '', routeData = null, routeFitKey = '', routeRequest = 0, routeFailure = null;
    let busy = false, refreshing = null;
    const isAdmin = root.dataset.role === 'admin';
    const isConductor = root.dataset.role === 'conductor';
    let view = isAdmin ? 'both' : 'commuters';
    if (isAdmin) {
        try { const v = localStorage.getItem('mbusWaitView'); if (['both', 'buses', 'commuters'].includes(v)) view = v; } catch (e) { /* ignore */ }
    }

    const num = v => { if (v === null || v === undefined || String(v).trim() === '') return null; const n = Number(v); return Number.isFinite(n) ? n : null; };
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

    /* ---------- sheet collapse (keeps the map visible) ---------- */
    function setCollapsed(on) {
        const sheet = document.querySelector('.sw-sheet'), toggle = el('swToggle');
        if (!sheet || !toggle) return;
        sheet.classList.toggle('is-collapsed', on);
        toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
        try { localStorage.setItem('mbusWaitSheet', on ? '1' : '0'); } catch (e) { /* storage may be blocked */ }
        sizeSheet();
        if (map) setTimeout(() => { map.invalidateSize({ pan: false }); sizeSheet(); }, 260);
    }
    function initSheetToggle() {
        const sheet = document.querySelector('.sw-sheet'), toggle = el('swToggle');
        if (!sheet || !toggle) return;
        toggle.addEventListener('click', () => setCollapsed(!sheet.classList.contains('is-collapsed')));
        let saved = null;
        try { saved = localStorage.getItem('mbusWaitSheet'); } catch (e) { saved = null; }
        if (saved === '1') setCollapsed(true);
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
            map.on('click', () => { if (selected || trackedPin) { selected = null; trackedPin = ''; render(); } });
        } catch (e) { map = null; el('staffMapError').hidden = false; }
    }
    const FALLBACK_AVATAR = '../Assets/images/mbus_icon.png';
    const busIcon = (no, stale) => L.divIcon({ className: '', iconSize: [84, 28], iconAnchor: [42, 14],
        html: '<span class="sw-bus' + (stale ? ' is-stale' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i>' + String(no || 'Bus').replace(/[<>&"]/g, '') + '</span>' });
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
    /* reservation pin: the rider's photo at the stop they chose. kind = 'pick' (pick-up stop) or 'drop' (drop-off stop) */
    const reserveIcon = (r, kind, approx, seat, tracked) => L.divIcon({ className: '', iconSize: [38, 38], iconAnchor: [19, 19],
        html: '<span class="sw-res-pin sw-res-' + kind + (approx ? ' is-approx' : '') + (tracked ? ' is-tracked' : '') + '"><span class="sw-person"><img src="' + photoOf(r) + '" alt=""></span><span class="sw-res-tag">' + (kind === 'drop' ? 'Drop-off' : 'Pick-up') + ' · ' + String(seat || '') + '</span></span>' });

    function fit(points) {
        if (!map || !points.length) return;
        if (points.length === 1) { map.setView(points[0], 16, { animate: true }); return; }
        map.fitBounds(points, { padding: [60, 60], maxZoom: 16, paddingBottomRight: [60, (document.querySelector('.sw-sheet') || {}).offsetHeight + 80 || 300], paddingTopLeft: [60, 100] });
    }

    /* ---------- render ---------- */
    function busFor(scheduleId) { return locations.find(l => Number(l.schedule_id) === Number(scheduleId) && validPt(l.latitude, l.longitude)); }

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
            return;
        }
        const rawPoints = [trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng];
        if (rawPoints.some(value => value === null || value === undefined || String(value).trim() === '')) {
            routeRequest++;
            routeKey = ''; routeData = null; routeFailure = null;
            box.textContent = 'This bus route needs terminal coordinates before an estimate is available.';
            if (routeLayer) routeLayer.clearLayers();
            return;
        }
        const origin = { lat: Number(trip.origin_lat), lng: Number(trip.origin_lng) };
        const destination = { lat: Number(trip.destination_lat), lng: Number(trip.destination_lng) };
        if (![origin.lat, origin.lng, destination.lat, destination.lng].every(Number.isFinite)) {
            routeRequest++;
            routeKey = ''; routeData = null; routeFailure = null;
            box.textContent = 'This bus route needs valid terminal coordinates before an estimate is available.';
            if (routeLayer) routeLayer.clearLayers();
            return;
        }
        const key = String(trip.schedule_id) + ':' + [origin.lat, origin.lng, destination.lat, destination.lng].join(',');
        if (routeKey !== key) {
            routeKey = key; routeData = null; routeFailure = null;
            const thisRequest = ++routeRequest;
            if (routeLayer) routeLayer.clearLayers();
            box.textContent = 'Loading the road route to ' + (trip.destination_label || trip.destination || 'the destination') + '…';
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
                    render(); // also redraws the reservation pins that sit along this route (render refreshes the estimate too)
            }).catch(error => {
                if (thisRequest !== routeRequest || routeKey !== key) return;
                routeFailure = { key, until: Date.now() + 30000 };
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

    const COMPACT_AT = 3;
    let lastReqs = [], compactBox = null;
    function updateCompact(reqs) {
        const sheetEl = document.querySelector('.sw-sheet');
        const on = reqs.length > COMPACT_AT;
        if (sheetEl) sheetEl.classList.toggle('is-compact', on);
        if (compactBox) { compactBox.remove(); compactBox = null; }
        if (!on) return;
        compactBox = node('button', undefined, 'sw-compact'); compactBox.type = 'button';
        const stack = node('span', undefined, 'sw-stack');
        reqs.slice(0, 4).forEach(r => { const i = node('img', undefined, 'sw-stack-img'); i.alt = ''; bindAvatarFallback(i); i.src = photoOf(r); stack.append(i); });
        if (reqs.length > 4) stack.append(node('span', '+' + (reqs.length - 4), 'sw-stack-more'));
        const t = node('span', undefined, 'sw-compact-text');
        const near = reqs.find(r => r._km !== null);
        t.append(node('strong', 'View all ' + reqs.length + ' waiting'), node('small', near ? 'Nearest: ' + (near.full_name || 'Commuter') + ' · ' + dist(near._km) : 'Tap to see everyone'));
        compactBox.append(stack, t, node('span', '›', 'sw-compact-go'));
        compactBox.addEventListener('click', openList);
        list.before(compactBox);
    }
    function openList() {
        const old = document.getElementById('swCard'); if (old) old.remove();
        const back = node('div', undefined, 'sw-card-back'); back.id = 'swCard';
        const card = node('div', undefined, 'sw-card sw-listcard'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'Riders waiting for pick-up');
        const head = node('div', undefined, 'sw-listcard-head');
        head.append(node('h3', lastReqs.length + ' waiting for pick-up'));
        const x = node('button', 'Close', 'sw-listcard-x'); x.type = 'button'; head.append(x);
        card.append(head);
        let q = null;
        if (lastReqs.length > 6) { q = node('input', undefined, 'sw-search'); q.type = 'search'; q.placeholder = 'Search name or spot'; q.setAttribute('aria-label', 'Search waiting riders'); card.append(q); }
        const ul = node('ul', undefined, 'sw-minilist');
        const draw = term => {
            ul.replaceChildren();
            lastReqs.filter(r => !term || ((r.full_name || '') + ' ' + (r.pickup_label || '')).toLowerCase().includes(term)).forEach(r => {
                const li = node('li'); const b = node('button', undefined, 'sw-mini'); b.type = 'button';
                const im = node('img', undefined, 'sw-mini-img'); im.alt = ''; bindAvatarFallback(im); im.src = photoOf(r);
                const tx = node('span', undefined, 'sw-mini-text');
                tx.append(node('strong', r.full_name || 'Commuter'), node('small', (r.pickup_label || 'Pinned spot') + (r.bus_number ? ' · ' + r.bus_number : '')));
                b.append(im, tx);
                if (r._km !== null) b.append(node('span', dist(r._km), 'sw-dist'));
                b.addEventListener('click', () => { shut(); if (selected !== r.request_id) choose(r); showDetails(r); });
                li.append(b); ul.append(li);
            });
            if (!ul.children.length) ul.append(node('li', 'No match.', 'sw-mini-none'));
        };
        draw('');
        if (q) q.addEventListener('input', () => draw(q.value.trim().toLowerCase()));
        card.append(ul); back.append(card); document.body.append(back);
        const shut = () => { back.remove(); document.removeEventListener('keydown', esc); };
        const esc = e => { if (e.key === 'Escape') shut(); };
        x.addEventListener('click', shut); back.addEventListener('click', e => { if (e.target === back) shut(); });
        document.addEventListener('keydown', esc); (q || x).focus();
    }

    /* ---------- reservation pins: where each reserved rider gets on and off ---------- */
    /* A point part-way along the road route (frac 0 = origin, 1 = destination). */
    function alongRoute(route, frac) {
        const pts = route && route.points, cum = route && route.cumulativeMeters;
        if (!Array.isArray(pts) || pts.length < 2 || !Array.isArray(cum) || cum.length !== pts.length) return null;
        const total = cum[cum.length - 1];
        if (!(total > 0)) return null;
        const want = total * Math.min(1, Math.max(0, frac));
        let i = 1;
        while (i < cum.length - 1 && cum[i] < want) i++;
        const span = cum[i] - cum[i - 1];
        const t = span > 0 ? Math.min(1, Math.max(0, (want - cum[i - 1]) / span)) : 0;
        return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * t, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * t];
    }
    /* Exact when the stop is in MBUS_STOP_COORDS. Otherwise approximate: placed along the loaded route by the
       stop's position in the trip's stop list (only while that trip's road route is loaded). */
    function stopPoint(r, name) {
        const key = String(name || '').trim().toUpperCase();
        if (!key) return null;
        const known = STOP_COORDS[key];
        if (known && validPt(known[0], known[1])) return { p: [Number(known[0]), Number(known[1])], approx: false };
        const trip = schedules.find(s => Number(s.schedule_id) === Number(r.schedule_id));
        if (!trip) return null;
        /* Each trip keeps its own road route, so pins show for every bus, not only the one picked in the filter. */
        const own = tripRoutes[trip.schedule_id];
        const road = own && own.route ? own.route : (routeData && routeKey.indexOf(String(trip.schedule_id) + ':') === 0 ? routeData : null);
        if (!road) return null;
        const stops = Array.isArray(trip.stops) ? trip.stops : [];
        const i = stops.indexOf(key);
        if (i < 0 || stops.length < 2) return null;
        const p = alongRoute(road, i / (stops.length - 1));
        return p ? { p, approx: true } : null;
    }
    /* Road route per trip (schedule_id -> {key, route}), loaded once so every rider's pickup and drop-off pin can be placed. */
    const tripRoutes = {};
    function ensureTripRoute(trip) {
        const raw = [trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng];
        if (raw.some(v => v === null || v === undefined || String(v).trim() === '') || !window.MBUSRouting) return;
        const o = { lat: Number(trip.origin_lat), lng: Number(trip.origin_lng) }, d = { lat: Number(trip.destination_lat), lng: Number(trip.destination_lng) };
        if (![o.lat, o.lng, d.lat, d.lng].every(Number.isFinite)) return;
        const key = [o.lat, o.lng, d.lat, d.lng].join(',');
        const cur = tripRoutes[trip.schedule_id];
        if (cur && cur.key === key) return;
        tripRoutes[trip.schedule_id] = { key };
        window.MBUSRouting.getDrivingRoute(o, d).then(route => {
            if (!tripRoutes[trip.schedule_id] || tripRoutes[trip.schedule_id].key !== key) return;
            tripRoutes[trip.schedule_id] = { key, route };
            render();
        }).catch(() => {});
    }
    const reservationKey = r => String(r.user_id) + ':' + String(r.schedule_id);
    function reservationSeats(r) {
        if (Array.isArray(r.seat_details) && r.seat_details.length) return r.seat_details;
        return [{ seat_number: r.seat_numbers || '1', pickup_location: r.pickup_location, destination: r.stop_destination }];
    }
    function reservationSeatPoint(r, seat, kind) {
        const prefix = kind === 'pick' ? 'pickup' : 'destination';
        const lat = seat[prefix + '_latitude'], lng = seat[prefix + '_longitude'];
        if (validPt(lat, lng)) return {p:[Number(lat),Number(lng)], approx:false};
        return stopPoint(r, kind === 'pick' ? seat.pickup_location : seat.destination);
    }
    function reservationMapPoints(r) {
        const points = [];
        reservationSeats(r).forEach(seat => ['pick','drop'].forEach(kind => {
            const point = reservationSeatPoint(r, seat, kind);
            if (point) points.push(point.p);
        }));
        return points;
    }
    function reservationPins(rows, pts) {
        if (!layer || !map) return;
        rows.forEach(r => {
            if (hiddenReservationKeys.has(reservationKey(r))) return;
            reservationSeats(r).forEach(seat => ['pick','drop'].forEach(kind => {
                const sp = reservationSeatPoint(r, seat, kind);
                if (!sp) return;
                const name = kind === 'pick' ? seat.pickup_location : seat.destination;
                const seatNumber = seat.seat_number || '?';
                const pinData = Object.assign({}, r, seat);
                pts.push(sp.p);
                const m = L.marker(sp.p, { icon: reserveIcon(pinData, kind, sp.approx, seatNumber, trackedPin === reservationKey(r) + '|' + seatNumber + '|' + kind), title: (r.full_name || 'Commuter') + ' · seat ' + seatNumber + (kind === 'drop' ? ' · drop-off at ' : ' · pick-up at ') + (name || '') + (sp.approx ? ' (approx.)' : ''), zIndexOffset: 200 }).addTo(layer);
                bindAvatarFallback(m.getElement() && m.getElement().querySelector('.sw-person img'));
                m.on('click', () => showReservation(r));
            }));
        });
    }

    function render() {
        const sel = filter.value;
        const reqs = requests.filter(r => !sel || String(r.schedule_id) === sel);
        const resRows = reservations.filter(r => !sel || String(r.schedule_id) === sel);
        const buses = locations.filter(l => (!sel || String(l.schedule_id) === sel) && validPt(l.latitude, l.longitude));

        // distance from each rider to the bus of their trip
        reqs.forEach(r => {
            const b = busFor(r.schedule_id), ok = validPt(r.latitude, r.longitude);
            r._km = b && ok ? km({ lat: +r.latitude, lng: +r.longitude }, { lat: +b.latitude, lng: +b.longitude }) : null;
        });
        reqs.sort((a, b) => (a._km === null) - (b._km === null) || a._km - b._km);

        lastReqs = reqs;
        el('staffCount').textContent = String(reqs.length);
        const busText = buses.length ? buses.length + (buses.length === 1 ? ' bus live' : ' buses live') : 'No bus location yet';
        el('busLabel').textContent = busText;
        const summary = el('swSummary');
        if (summary) summary.textContent = view === 'buses' ? busText : view === 'commuters' ? reqs.length + ' pick-up · ' + resRows.length + ' reserved' : reqs.length + ' pick-up · ' + resRows.length + ' reserved · ' + busText;
        document.body.classList.toggle('sw-view-buses', view === 'buses');
        document.body.classList.toggle('sw-view-commuters', view === 'commuters');
        document.querySelectorAll('#swView button').forEach(b => b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false'));
        renderBuses(sel, reqs, resRows);
        empty.hidden = reqs.length > 0 || !ready;
        empty.textContent = ready ? 'No one is waiting for a pick-up.' : 'Waiting is not available yet.';
        renderReservations(sel);

        if (layer) layer.clearLayers();
        if (line && map) { map.removeLayer(line); line = null; }
        const pts = [];
        (view === 'commuters' ? [] : buses).forEach(b => {
            const stale = Date.now() - Date.parse(b.updated_at) > FRESH_MS, p = [+b.latitude, +b.longitude];
            pts.push(p);
            if (layer) L.marker(p, { icon: busIcon(b.bus_number, stale), title: 'Bus ' + b.bus_number, zIndexOffset: 500 }).addTo(layer);
        });
        list.replaceChildren();
        reqs.forEach(r => {
            const on = selected === r.request_id;
            const ok = validPt(r.latitude, r.longitude), p = ok ? [+r.latitude, +r.longitude] : null;
            if (p && map && view !== 'buses') {
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
            if (isAdmin || !isConductor) {
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

        if (view !== 'buses') { const seen = new Set(resRows.map(r => Number(r.schedule_id))); schedules.filter(t => seen.has(Number(t.schedule_id))).forEach(ensureTripRoute); }
        reservationPins(view === 'buses' ? [] : resRows, pts);
        updateCompact(reqs);
        if (!fittedOnce && pts.length) { fit(pts); fittedOnce = true; }
        updateStaffRoute();
        if (map) requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        sizeSheet();
    }

    /* ---------- buses (what's about to leave, and who is waiting for it) ---------- */
    const STATUS = { Scheduled: 'Ready to go', Boarding: 'Boarding', Departed: 'On the road' };
    const clock = v => { const d = new Date(String(v || '').replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); };
    function renderBuses(sel, reqs, resRows) {
        const ul = el('swBusList'); if (!ul) return;
        const rows = new Map();
        schedules.forEach(sc => rows.set(String(sc.schedule_id), { id: String(sc.schedule_id), no: sc.bus_number, sc }));
        locations.forEach(l => { const k = String(l.schedule_id); if (!rows.has(k)) rows.set(k, { id: k, no: l.bus_number, sc: null }); });
        reservations.forEach(r => { const k = String(r.schedule_id); if (!rows.has(k)) rows.set(k, { id: k, no: r.bus_number, sc: null }); });
        let items = Array.from(rows.values());
        if (sel) items = items.filter(i => i.id === sel);
        const count = (arr, id) => arr.filter(x => String(x.schedule_id) === id).length;
        items.forEach(i => {
            i.pick = count(requests, i.id); i.res = count(reservations, i.id);
            i.loc = locations.find(l => String(l.schedule_id) === i.id && validPt(l.latitude, l.longitude));
        });
        items.sort((a, b) => (b.pick + b.res) - (a.pick + a.res) || String(a.no).localeCompare(String(b.no), undefined, { numeric: true }));
        el('swBusCount').textContent = String(items.length);
        el('swBusEmpty').hidden = items.length > 0;
        ul.replaceChildren();
        items.forEach(i => {
            const sc = i.sc || {}, on = sel === i.id;
            const li = node('li'); const b = node('button', undefined, 'sw-bus-card' + (on ? ' is-on' : '')); b.type = 'button';
            const top = node('span', undefined, 'sw-bus-top');
            top.append(node('strong', /^\d+$/.test(String(i.no)) ? 'Bus ' + i.no : String(i.no || 'Bus')));
            const live = i.loc && Date.now() - Date.parse(i.loc.updated_at) <= FRESH_MS;
            top.append(node('span', live ? 'Live' : 'No signal', 'sw-pill ' + (live ? 'is-live' : 'is-off')));
            if (sc.trip_status) top.append(node('span', STATUS[sc.trip_status] || sc.trip_status, 'sw-pill is-status'));
            const route = [sc.origin_label || sc.origin, sc.destination_label || sc.destination].filter(Boolean).join(' → ');
            const when = sc.departure_time ? 'Departs ' + clock(sc.departure_time) : '';
            const sub = node('small', [route, when].filter(Boolean).join(' · ') || 'Trip details unavailable');
            const chips = node('span', undefined, 'sw-bus-chips');
            chips.append(node('span', i.pick + ' waiting', 'sw-chip'), node('span', i.res + ' reserved', 'sw-chip is-res'));
            b.append(top, sub, chips);
            b.addEventListener('click', () => { filter.value = on ? '' : i.id; selected = null; fittedOnce = false; render(); });
            li.append(b); ul.append(li);
        });
    }
    document.querySelectorAll('#swView button').forEach(b => b.addEventListener('click', () => {
        view = b.dataset.view; selected = null; fittedOnce = false;
        try { localStorage.setItem('mbusWaitView', view); } catch (e) { /* ignore */ }
        render();
    }));

    /* ---------- reservations (paid seat, not on board yet) ---------- */
    const seatLabel = r => r.seats === 1 ? 'Seat ' + (r.seat_numbers || 1) : r.seats + ' seats';
    const stopLabel = r => (r.pickup_location && r.stop_destination) ? r.pickup_location + ' → ' + r.stop_destination : (r.pickup_location || r.stop_destination || '');
    function renderReservations(sel) {
        const rows = reservations.filter(r => !sel || String(r.schedule_id) === sel);
        el('staffReserveCount').textContent = String(rows.length);
        rEmpty.hidden = rows.length > 0;
        rList.replaceChildren();
        const hIcon = document.querySelector('.sw-group-reserve .sw-group-icon');
        if (hIcon) {
            if (rows.length) {
                const pic = node('img'); pic.alt = ''; bindAvatarFallback(pic); pic.src = photoOf(rows[0]);
                pic.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block';
                hIcon.style.overflow = 'hidden'; hIcon.replaceChildren(pic);
            } else if (!hIcon.querySelector('.fa-ticket')) {
                const t = node('i', undefined, 'fa fa-ticket'); hIcon.replaceChildren(t);
            }
        }
        rows.forEach(r => {
            const li = node('li', undefined, 'sw-row');
            const open = node('button', undefined, 'sw-row-main'); open.type = 'button';
            const avatar = node('img', undefined, 'sw-avatar'); avatar.alt = ''; bindAvatarFallback(avatar); avatar.src = photoOf(r);
            const txt = node('span', undefined, 'sw-row-text');
            txt.append(node('strong', r.full_name || 'Commuter'),
                node('small', [seatLabel(r), stopLabel(r), r.bus_number].filter(Boolean).join(' · ')));
            open.append(avatar, txt);
            open.addEventListener('click', () => showReservation(r));
            const more = node('button', 'Details', 'sw-done sw-details'); more.type = 'button';
            more.addEventListener('click', () => showReservation(r));
            li.append(open, more);
            rList.append(li);
        });
        sizeSheet();
    }
    /* Fly to one seat pin (pick-up or drop-off) and keep it highlighted until the map is tapped. */
    function trackPin(r, seat, kind) {
        const sp = reservationSeatPoint(r, seat, kind);
        if (!sp || !map) { say('That stop has no map position yet.'); return; }
        hiddenReservationKeys.delete(reservationKey(r));
        trackedPin = reservationKey(r) + '|' + (seat.seat_number || '?') + '|' + kind;
        render();
        map.flyTo(sp.p, 17, { duration: .8 });
    }
    function showReservation(r) {
        const old = document.getElementById('swCard'); if (old) old.remove();
        const back = node('div', undefined, 'sw-card-back'); back.id = 'swCard';
        const card = node('div', undefined, 'sw-card sw-rcard'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'Reservation details');
        const shut = () => { back.remove(); document.removeEventListener('keydown', esc); };
        const esc = e => { if (e.key === 'Escape') shut(); };

        const head = node('div', undefined, 'sw-rc-head');
        const img = node('img', undefined, 'sw-rc-photo'); img.alt = ''; bindAvatarFallback(img); img.src = photoOf(r);
        const who = node('div', undefined, 'sw-rc-who');
        who.append(node('strong', r.full_name || 'Commuter'),
            node('small', [r.bus_number, seatLabel(r), ago(r.reserved_at)].filter(Boolean).join(' · ')));
        const close = node('button', '×', 'sw-rc-x'); close.type = 'button'; close.setAttribute('aria-label', 'Close');
        head.append(close, img, who);

        const phone = r.phone_number && String(r.phone_number).length > 3 ? String(r.phone_number) : '';
        const meta = node('div', undefined, 'sw-rc-meta');
        meta.append(node('span', (r.origin ? r.origin + ' → ' + (r.route_destination || '') : 'Reserved seat') + ' · not on board yet'));
        if (phone) { const tel = node('a', phone, 'sw-rc-tel'); tel.href = 'tel:' + phone.replace(/[^\d+]/g, ''); meta.append(tel); }

        const seats = node('ul', undefined, 'sw-rc-seats');
        reservationSeats(r).forEach(seat => {
            const li = node('li', undefined, 'sw-rc-seat');
            li.append(node('span', String(seat.seat_number || '?'), 'sw-rc-no'));
            const stops = node('span', undefined, 'sw-rc-stops');
            [['pick', seat.pickup_location, 'Pick-up'], ['drop', seat.destination, 'Drop-off']].forEach(([kind, name, label]) => {
                const b = node('button', undefined, 'sw-rc-track sw-rc-' + kind); b.type = 'button';
                const ok = !!reservationSeatPoint(r, seat, kind);
                b.disabled = !ok || !map;
                b.append(node('small', label), node('span', name || '-'), node('i', undefined, 'fa fa-location-crosshairs'));
                b.setAttribute('aria-label', 'Track ' + label.toLowerCase() + ' of seat ' + (seat.seat_number || '?') + ': ' + (name || ''));
                b.addEventListener('click', () => { shut(); trackPin(r, seat, kind); });
                stops.append(b);
            });
            li.append(stops); seats.append(li);
        });

        const key = reservationKey(r);
        const sw = node('button', undefined, 'sw-rc-switch sw-res-map-toggle'); sw.type = 'button';
        const paint = () => {
            const on = !hiddenReservationKeys.has(key);
            sw.setAttribute('aria-pressed', String(on));
            sw.replaceChildren(node('span', 'Show pins on map'), node('i', undefined, 'sw-rc-knob'));
        };
        paint();
        sw.addEventListener('click', () => {
            if (hiddenReservationKeys.has(key)) hiddenReservationKeys.delete(key); else hiddenReservationKeys.add(key);
            paint(); render();
        });

        card.append(head, meta, seats, sw); back.append(card); document.body.append(back);
        close.addEventListener('click', shut); back.addEventListener('click', e => { if (e.target === back) shut(); });
        document.addEventListener('keydown', esc); close.focus();
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
        card.append(head, dl);
        let pk = null;
        if (isConductor) { pk = node('button', 'Picked up', 'sw-card-pick'); pk.type = 'button'; card.append(pk); }
        card.append(close); back.append(card); document.body.append(back);
        const shut = () => { back.remove(); document.removeEventListener('keydown', esc); };
        if (pk) pk.addEventListener('click', async () => { await pickedUp(r, pk); shut(); });
        const esc = e => { if (e.key === 'Escape') shut(); };
        close.addEventListener('click', shut); back.addEventListener('click', e => { if (e.target === back) shut(); });
        document.addEventListener('keydown', esc); close.focus();
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
        reservations.forEach(r => add(r.schedule_id, 'Bus ' + r.bus_number));
        if (!cur && seen.size === 1) cur = Array.from(seen)[0];
        if ([...filter.options].some(o => o.value === cur)) filter.value = cur;
        filter.parentElement.hidden = seen.size < 2 && root.dataset.role !== 'admin';
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
                    reservations = Array.isArray(p.value.reservations) ? p.value.reservations : [];
                    schedules = Array.isArray(p.value.schedules) ? p.value.schedules : [];
                    if (!ready) say(p.value.message || 'Waiting is not set up yet.');
                } else { ready = false; requests = []; reservations = []; say(p.reason && p.reason.message || 'Could not load waiting riders.'); }
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
            say((r.full_name || 'Rider') + ': ' + (d.message || 'Picked up. Seat them in Riders → Pickup.'), true);
            requests = requests.filter(x => x.request_id !== r.request_id);
            if (selected === r.request_id) selected = null;
            render(); busy = false; await refresh();
        } catch (e) { say(e.message); }
        busy = false; btn.disabled = false;
    }

    filter.addEventListener('change', () => { selected = null; fittedOnce = false; routeFitKey = ''; render(); });
    el('staffRefresh').addEventListener('click', refresh);
    window.addEventListener('resize', () => { sizeSheet(); if (map) map.invalidateSize(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    initSheetToggle();
    initMap();
    refresh();
    const timer = setInterval(() => { if (!document.hidden && !busy) refresh(); }, 8000);
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
})();
