/* Commuter Waiting board: shown only after a rider has an active waiting request. */
(() => {
    'use strict';
    const root = document.getElementById('commuterWaitingBoard');
    if (!root) return;
    const el = id => document.getElementById(id);
    const endpoint = root.dataset.endpoint;
    const config = window.mbusWaitingBoard || {};
    const FALLBACK_AVATAR = '../Assets/images/mbus_icon.png';
    const FRESH_MS = 120000;

    let csrf = config.csrfToken || '';
    let myRequest = null, schedules = [], locations = [], serverNow = Date.now();
    let map = null, busLayer = null, riderLayer = null, routeLayer = null, mapAttempted = false;
    let routeData = null, routeKey = '', routeFailure = null, routeRequest = 0;
    let routeViewMode = 'focus', fittedOnce = false, refreshing = null, busy = false, timer = null;

    const num = value => {
        if (value === null || value === undefined || (typeof value === 'string' && !value.trim())) return null;
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
    };
    const validPoint = (lat, lng) => num(lat) !== null && num(lng) !== null && Math.abs(Number(lat)) <= 90 && Math.abs(Number(lng)) <= 180;
    const escapeHtml = text => String(text == null ? '' : text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function say(text) { const box = el('cwbNotice'); box.hidden = !text; box.textContent = text || ''; }
    function ago(value) {
        const stamp = Date.parse(value);
        if (!Number.isFinite(stamp)) return 'time unavailable';
        const seconds = Math.max(0, (serverNow - stamp) / 1000);
        return seconds < 45 ? 'just now' : seconds < 3600 ? Math.round(seconds / 60) + ' min ago' : Math.round(seconds / 3600) + ' hr ago';
    }
    function distanceMeters(a, b) {
        const rad = Math.PI / 180, lat1 = a[0] * rad, lat2 = b[0] * rad;
        const dLat = (b[0] - a[0]) * rad, dLng = (b[1] - a[1]) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
    }
    function distanceLabel(meters) {
        if (window.MBUSRouting) return window.MBUSRouting.formatDistance(meters);
        return meters < 1000 ? Math.round(meters) + ' m' : (meters / 1000).toFixed(1) + ' km';
    }
    function routeName(trip) {
        const from = trip.origin_label || (window.MBUSRouting ? window.MBUSRouting.displayName(trip.origin) : trip.origin) || 'Origin';
        const to = trip.destination_label || (window.MBUSRouting ? window.MBUSRouting.displayName(trip.destination) : trip.destination) || 'Destination';
        return from + ' → ' + to;
    }
    function requestTrip() {
        if (!myRequest) return null;
        return schedules.find(trip => String(trip.schedule_id) === String(myRequest.schedule_id)) || myRequest;
    }
    function busLocation(trip) {
        if (!trip) return null;
        const loc = locations.find(item => String(item.schedule_id) === String(trip.schedule_id));
        if (!loc || !validPoint(loc.latitude, loc.longitude)) return null;
        const updatedAt = Date.parse(loc.updated_at);
        const age = Number.isFinite(updatedAt) ? Math.max(0, serverNow - updatedAt) : Infinity;
        return { loc, age, fresh: age <= FRESH_MS };
    }
    function ownPoint() {
        return myRequest && validPoint(myRequest.latitude, myRequest.longitude)
            ? [Number(myRequest.latitude), Number(myRequest.longitude)] : null;
    }
    function busPoint(trip) {
        const current = busLocation(trip);
        return current ? [Number(current.loc.latitude), Number(current.loc.longitude)] : null;
    }
    function focusedPoints(trip) {
        const points = [];
        const rider = ownPoint(), bus = busPoint(trip);
        if (rider) points.push(rider);
        if (bus) points.push(bus);
        if (!points.length && trip && validPoint(trip.origin_lat, trip.origin_lng)) points.push([Number(trip.origin_lat), Number(trip.origin_lng)]);
        return points;
    }
    function fit(points, animate) {
        if (!map || !points.length) return;
        if (points.length === 1) map.setView(points[0], 16, { animate: !!animate });
        else map.fitBounds(L.latLngBounds(points).pad(.3), {
            paddingTopLeft: [24, 100],
            paddingBottomRight: [24, Math.max(220, (el('cwbSheet').offsetHeight || 190) + 36)],
            maxZoom: 16,
            animate: !!animate
        });
    }
    function initMap() {
        if (mapAttempted) return;
        mapAttempted = true;
        if (!window.L) { el('cwbMapError').hidden = false; return; }
        try {
            map = L.map('cwbMap', { zoomControl: false }).setView([14.6798552, 120.2728757], 13);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · routing by OSRM'
            }).addTo(map).on('tileerror', () => { el('cwbMapError').hidden = false; });
            routeLayer = L.layerGroup().addTo(map);
            busLayer = L.layerGroup().addTo(map);
            riderLayer = L.layerGroup().addTo(map);
        } catch (_) { map = null; el('cwbMapError').hidden = false; }
    }
    function busIcon(busNumber, stale) {
        return L.divIcon({
            className: 'cwb-bus-icon', iconSize: [114, 34], iconAnchor: [57, 17],
            html: '<span class="cwb-bus-pin' + (stale ? ' is-stale' : '') + '"><i class="fa fa-bus" aria-hidden="true"></i> ' + escapeHtml(busNumber || 'Bus') + '</span>'
        });
    }
    function avatarUrl() {
        const value = String(config.profileImage || '');
        return value && value !== FALLBACK_AVATAR ? value : FALLBACK_AVATAR;
    }
    function personIcon() {
        return L.divIcon({ className: 'cwb-person-icon', iconSize: [48, 48], iconAnchor: [24, 24],
            html: '<span class="cwb-person-pin"><img src="' + escapeHtml(avatarUrl()) + '" alt=""></span>' });
    }
    function fallbackAvatar(marker) {
        const image = marker && marker.getElement() && marker.getElement().querySelector('.cwb-person-pin img');
        if (image) image.addEventListener('error', () => { image.src = FALLBACK_AVATAR; }, { once: true });
    }
    function updateRouteButton() {
        const button = el('cwbRouteView');
        button.disabled = !routeData || !map;
        button.textContent = routeViewMode === 'focus' ? 'Full route' : 'Focus bus';
        button.setAttribute('aria-pressed', routeViewMode === 'route' ? 'true' : 'false');
    }
    function toggleRoute() {
        const trip = requestTrip();
        if (!routeData || !trip || !map) return;
        if (routeViewMode === 'focus') {
            routeViewMode = 'route';
            const bounds = L.latLngBounds(routeData.points);
            focusedPoints(trip).forEach(point => bounds.extend(point));
            map.fitBounds(bounds.pad(.06), { paddingTopLeft: [24, 100], paddingBottomRight: [24, Math.max(220, (el('cwbSheet').offsetHeight || 190) + 36)], maxZoom: 12, animate: false });
        } else {
            routeViewMode = 'focus';
            fit(focusedPoints(trip), false);
        }
        updateRouteButton();
    }
    function updatePill(trip) {
        const pill = el('cwbLocationPill');
        const location = busLocation(trip);
        const bus = trip.bus_number || 'your bus';
        const dot = pill.querySelector('.cwb-status-dot');
        pill.hidden = false;
        if (!location) {
            dot.classList.add('is-off');
            el('cwbLocationText').textContent = 'Waiting for location · ' + bus;
        } else if (location.fresh) {
            dot.classList.remove('is-off');
            el('cwbLocationText').textContent = 'Location on · ' + bus;
        } else {
            dot.classList.add('is-off');
            el('cwbLocationText').textContent = 'Last seen · ' + bus;
        }
    }
    function updateEstimate(trip) {
        const box = el('cwbRouteEstimate');
        if (!trip) { box.textContent = 'Your bus route is unavailable.'; return; }
        const destination = trip.destination_label || (window.MBUSRouting ? window.MBUSRouting.displayName(trip.destination) : trip.destination) || 'destination';
        const location = busLocation(trip);
        if (!routeData) {
            box.textContent = routeFailure || (location && !location.fresh ? 'Last bus pin ' + ago(location.loc.updated_at) + '. Road route is loading.' : 'Loading the road route…');
            return;
        }
        if (!location) {
            box.textContent = 'To ' + destination + ': ' + window.MBUSRouting.formatDistance(routeData.distanceMeters) + ' road route · ' + window.MBUSRouting.formatDuration(routeData.durationSeconds) + ' driving estimate. Waiting for the first live bus pin.';
            return;
        }
        if (!location.fresh) {
            box.textContent = 'Last bus pin ' + ago(location.loc.updated_at) + '. Live destination estimate returns when GPS is fresh.';
            return;
        }
        const remaining = window.MBUSRouting.remainingOnRoute(routeData, [Number(location.loc.latitude), Number(location.loc.longitude)], 2500);
        if (!remaining || !remaining.onRoute) {
            box.textContent = 'Bus location is away from the mapped route. Destination ETA is unavailable until it returns to the route.';
            return;
        }
        box.textContent = 'To ' + destination + ': ' + window.MBUSRouting.formatDistance(remaining.distanceMeters) + ' on road · ' + window.MBUSRouting.formatDuration(remaining.durationSeconds) + '. Estimate excludes traffic and stops.';
    }
    function loadRoute(trip) {
        const key = trip && [trip.schedule_id, trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng].join(':');
        if (!trip || !window.MBUSRouting || !map || !routeLayer || ![trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng].every(value => num(value) !== null)) {
            routeRequest++; routeKey = ''; routeData = null; routeFailure = 'Road route is unavailable for this bus.';
            if (routeLayer) routeLayer.clearLayers();
            updateRouteButton(); updateEstimate(trip); return;
        }
        if (routeKey === key) { updateEstimate(trip); return; }
        routeKey = key; routeData = null; routeFailure = null;
        const requestId = ++routeRequest;
        if (routeLayer) routeLayer.clearLayers();
        updateRouteButton();
        window.MBUSRouting.getDrivingRoute({ lat: Number(trip.origin_lat), lng: Number(trip.origin_lng) }, { lat: Number(trip.destination_lat), lng: Number(trip.destination_lng) })
            .then(route => {
                if (requestId !== routeRequest || routeKey !== key) return;
                routeData = route; routeFailure = null;
                routeLayer.clearLayers();
                L.polyline(route.points, { color: '#2786c8', weight: 5, opacity: .82 }).addTo(routeLayer);
                L.circleMarker(route.points[0], { radius: 5, color: '#fff', weight: 2, fillColor: '#2786c8', fillOpacity: 1 }).addTo(routeLayer);
                L.circleMarker(route.points[route.points.length - 1], { radius: 5, color: '#fff', weight: 2, fillColor: '#18365d', fillOpacity: 1 }).addTo(routeLayer);
                updateRouteButton(); updateEstimate(trip);
            })
            .catch(error => {
                if (requestId !== routeRequest || routeKey !== key) return;
                routeFailure = error.message || 'Road directions are unavailable right now.';
                updateRouteButton(); updateEstimate(trip);
            });
    }
    async function request(url, options) {
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const requestOptions = Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }, options || {});
        if (controller) requestOptions.signal = controller.signal;
        let timeoutId;
        const timeout = new Promise((_, reject) => {
            timeoutId = setTimeout(() => {
                if (controller) controller.abort();
                reject(new Error('Request timed out. Check your connection and try again.'));
            }, 10000);
        });
        const operation = (async () => {
            const response = await fetch(url, requestOptions);
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Live tracking returned an unreadable response.'); }
            if (data && data.csrf_token) csrf = data.csrf_token;
            if (!response.ok || data.ok !== true) throw new Error(data.message || 'Unable to load waiting status.');
            return data;
        })();
        try { return await Promise.race([operation, timeout]); }
        catch (error) {
            if (error && error.name === 'AbortError') throw new Error('Request timed out. Check your connection and try again.');
            throw error;
        } finally { clearTimeout(timeoutId); }
    }
    function showEmpty() {
        el('cwbEmpty').hidden = false;
        el('cwbMap').hidden = true;
        el('cwbLocationPill').hidden = true;
        el('cwbSheet').hidden = true;
        if (busLayer) busLayer.clearLayers();
        if (riderLayer) riderLayer.clearLayers();
        if (routeLayer) routeLayer.clearLayers();
        routeRequest++; routeData = null; routeKey = ''; routeFailure = null; updateRouteButton();
    }
    function render() {
        if (!myRequest || myRequest.status !== 'Waiting') { showEmpty(); return; }
        const trip = requestTrip();
        el('cwbEmpty').hidden = true;
        el('cwbMap').hidden = false;
        el('cwbSheet').hidden = false;
        if (!map) initMap();
        el('cwbCount').textContent = '1';
        const selectedBusLocation = busLocation(trip);
        el('cwbBusLiveCount').textContent = selectedBusLocation && selectedBusLocation.fresh ? '1 bus live' : 'No live bus';
        updatePill(trip);
        el('cwbUpdated').textContent = 'Updated ' + new Date(serverNow).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        const list = el('cwbList');
        list.replaceChildren();
        const row = document.createElement('li'); row.className = 'cwb-row is-selected';
        const select = document.createElement('button'); select.type = 'button'; select.className = 'cwb-row-main';
        select.setAttribute('aria-label', 'Focus bus ' + (trip && trip.bus_number || 'waiting bus'));
        const busGlyph = document.createElement('span'); busGlyph.className = 'cwb-row-icon'; busGlyph.innerHTML = '<i class="fa fa-bus" aria-hidden="true"></i>';
        const text = document.createElement('span'); text.className = 'cwb-row-text';
        const name = document.createElement('strong'); name.textContent = trip && trip.bus_number ? String(trip.bus_number) : 'Your bus';
        const route = document.createElement('small'); route.textContent = trip ? routeName(trip) : 'Trip details unavailable';
        text.append(name, route); select.append(busGlyph, text);
        const location = busLocation(trip), rider = ownPoint();
        if (location && rider) {
            const meters = distanceMeters(rider, [Number(location.loc.latitude), Number(location.loc.longitude)]);
            const distance = document.createElement('span'); distance.className = 'cwb-distance'; distance.textContent = distanceLabel(meters); distance.title = 'Straight-line distance to the bus'; select.append(distance);
        } else {
            const state = document.createElement('span'); state.className = 'cwb-row-state'; state.textContent = location ? 'Pickup saved' : 'No live pin'; select.append(state);
        }
        select.addEventListener('click', () => { routeViewMode = 'focus'; fit(focusedPoints(trip), true); updateRouteButton(); });
        const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'cwb-cancel'; cancel.textContent = 'Cancel'; cancel.disabled = busy;
        cancel.setAttribute('aria-label', 'Cancel waiting for ' + (trip && trip.bus_number || 'your bus'));
        cancel.addEventListener('click', cancelWaiting);
        row.append(select, cancel); list.append(row);

        if (map) {
            busLayer.clearLayers(); riderLayer.clearLayers();
            const points = [];
            if (location) {
                const point = [Number(location.loc.latitude), Number(location.loc.longitude)]; points.push(point);
                const busMarker = L.marker(point, { icon: busIcon(trip.bus_number, !location.fresh), title: 'Bus ' + (trip.bus_number || '') , zIndexOffset: 500 }).addTo(busLayer);
                busMarker.bindPopup('Bus ' + escapeHtml(trip.bus_number || ''));
                busMarker.on('click', () => { routeViewMode = 'focus'; fit(focusedPoints(trip), true); updateRouteButton(); });
            }
            if (rider) {
                points.push(rider);
                const marker = L.marker(rider, { icon: personIcon(), title: 'Your pickup spot', zIndexOffset: 900 }).addTo(riderLayer).bindPopup('Your pickup spot · ' + escapeHtml(myRequest.pickup_label || 'Pinned pickup'));
                fallbackAvatar(marker);
                if (location) L.polyline([rider, [Number(location.loc.latitude), Number(location.loc.longitude)]], { color: '#d1493f', weight: 3, dashArray: '4 8', opacity: .9 }).addTo(riderLayer);
            }
            if (!fittedOnce) { fit(points.length ? points : focusedPoints(trip), false); fittedOnce = true; }
            else if (routeViewMode === 'focus') fit(focusedPoints(trip), false);
            requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        }
        updateEstimate(trip);
        loadRoute(trip);
    }
    async function cancelWaiting() {
        if (busy || !myRequest) return;
        busy = true; say('Cancelling your waiting request…');
        const cancelButton = document.querySelector('.cwb-cancel');
        if (cancelButton) cancelButton.disabled = true;
        el('cwbRefresh').disabled = true;
        let cancelled = false;
        try {
            await request(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ csrf_token: csrf, action: 'cancel_waiting', schedule_id: String(myRequest.schedule_id) }) });
            const pendingRefresh = refreshing;
            if (pendingRefresh) await pendingRefresh;
            myRequest = Object.assign({}, myRequest, { status: 'Cancelled' });
            say('Waiting cancelled.'); render();
            cancelled = true;
        } catch (error) { say(error.message || 'Could not cancel waiting.'); }
        finally { busy = false; el('cwbRefresh').disabled = false; if (!cancelled && cancelButton) cancelButton.disabled = false; }
        if (cancelled) await load();
    }
    async function load() {
        if (refreshing) return refreshing;
        if (busy) return;
        refreshing = (async () => {
            try {
                const data = await request(endpoint);
                serverNow = Date.parse(data.server_time) || Date.now();
                schedules = Array.isArray(data.schedules) ? data.schedules : [];
                locations = Array.isArray(data.locations) ? data.locations : [];
                const nextRequest = data.my_request && data.my_request.status === 'Waiting' ? data.my_request : null;
                const oldId = myRequest && myRequest.schedule_id;
                myRequest = nextRequest;
                if (String(oldId || '') !== String(myRequest && myRequest.schedule_id || '')) {
                    fittedOnce = false; routeViewMode = 'focus'; routeRequest++; routeKey = ''; routeData = null; routeFailure = null;
                }
                say(''); render();
            } catch (error) { say(error.message || 'Unable to load waiting status.'); if (!myRequest) showEmpty(); }
            finally { refreshing = null; }
        })();
        return refreshing;
    }
    function scheduleNext() {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(async () => { if (!busy) await load(); scheduleNext(); }, 10000);
    }
    el('cwbRefresh').addEventListener('click', load);
    el('cwbRouteView').addEventListener('click', toggleRoute);
    window.addEventListener('resize', () => { if (map) { fit(focusedPoints(requestTrip()), false); map.invalidateSize(); } });
    document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else { load().then(scheduleNext); } });
    window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });

    load().then(scheduleNext);
})();
