/* Commuter live buses: same map and bus pins as the operator board, buses only. */
(function () {
    'use strict';
    const el = id => document.getElementById(id);
    const select = el('liveSchedule');
    if (!select) return;

    const FRESH_MS = 120000;
    let trips = [], locations = [], serverNow = Date.now();
    const requestedNumber = Number((window.mbusLive || {}).scheduleId || 0);
    const requestedScheduleId = Number.isSafeInteger(requestedNumber) && requestedNumber > 0 ? String(requestedNumber) : '';
    let chosen = requestedScheduleId;
    let requestedUnavailableId = '';
    let myRequest = null;
    let csrf = (window.mbusLive || {}).csrfToken || '';
    let cancelBusy = false;
    let manualChoice = false;
    let map = null, markerLayer = null, commuterLayer = null, routeLayer = null, pollTimer = null, loading = false, version = 0, fitted = false;
    let routeKey = '', routeData = null, routeFitKey = '', routeRequestId = 0, routeFailure = null, routeViewMode = 'route';
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
    function routeOf(t) {
        const names = window.MBUSRouting;
        const origin = t.origin_label || (names ? names.displayName(t.origin) : t.origin) || 'Origin';
        const destination = t.destination_label || (names ? names.displayName(t.destination || t.route_destination) : (t.destination || t.route_destination)) || 'Endpoint';
        return origin + ' to ' + destination;
    }

    function initMap() {
        const box = el('trackingMap');
        if (typeof L === 'undefined') {
            box.hidden = true;
            el('mapMessage').hidden = false;
            el('mapMessage').textContent = 'The map could not load. The bus list below still shows who is on the road.';
            return;
        }
        try {
            map = L.map('trackingMap', { zoomControl: true }).setView([14.6798552, 120.2728757], 13);
            const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · routing by OSRM' }).addTo(map);
            tiles.on('tileerror', () => { el('mapMessage').hidden = false; el('mapMessage').textContent = 'Map tiles could not load. Bus status below is still current.'; });
            markerLayer = L.layerGroup().addTo(map);
            commuterLayer = L.layerGroup().addTo(map);
            routeLayer = L.layerGroup().addTo(map);
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
        manualChoice = true;
        select.value = chosen;
        fitted = false;
        routeFitKey = '';
        routeViewMode = 'route';
        render();
    }

    function updateRouteViewControl() {
        const button = el('routeViewButton');
        if (!button) return;
        button.disabled = !routeData || !chosen;
        button.textContent = routeViewMode === 'route' ? 'Focus bus' : 'Full route';
        button.setAttribute('aria-pressed', routeViewMode === 'route' ? 'true' : 'false');
    }

    function toggleRouteView() {
        if (!map || !routeData || !chosen) return;
        if (routeViewMode === 'bus') {
            routeViewMode = 'route';
            const boundsPoints = routeData.points.slice();
            const pair = selectedTrackingPoints();
            boundsPoints.push(...pair);
            map.fitBounds(L.latLngBounds(boundsPoints).pad(0.08), { maxZoom: 12, animate: false });
        } else {
            routeViewMode = 'bus';
            const points = selectedTrackingPoints();
            if (points.length > 1) map.fitBounds(L.latLngBounds(points).pad(0.35), { maxZoom: 16, animate: false });
            else if (points.length === 1) map.setView(points[0], 16, { animate: false });
        }
        updateRouteViewControl();
    }

    function waitingFor(scheduleId) {
        return !!(myRequest && myRequest.status === 'Waiting' && String(myRequest.schedule_id) === String(scheduleId));
    }
    function validPoint(lat, lng) { return valid(lat, lng); }
    function selectedTrackingPoints() {
        const points = [];
        const trip = trips.find(t => String(t.schedule_id) === chosen);
        const state = trip ? stateOf(trip) : null;
        if (waitingFor(chosen) && validPoint(myRequest.latitude, myRequest.longitude)) points.push([Number(myRequest.latitude), Number(myRequest.longitude)]);
        if (state && state.loc) points.push([Number(state.loc.latitude), Number(state.loc.longitude)]);
        return points;
    }
    function riderIcon() {
        const picture = (window.mbusLive || {}).profileImage || '../Assets/images/mbus_icon.png';
        return L.divIcon({ className: 'live-rider-marker', iconSize: [42, 42], iconAnchor: [21, 21],
            html: '<span class="live-rider-avatar"><img src="' + escapeHtml(picture) + '" alt=""></span>' });
    }
    function bindRiderFallback(marker) {
        const img = marker && marker.getElement() && marker.getElement().querySelector('.live-rider-avatar img');
        if (!img) return;
        img.addEventListener('error', () => { img.src = '../Assets/images/mbus_icon.png'; }, { once: true });
    }
    function busToPickupText(trip, state) {
        if (!waitingFor(trip.schedule_id) || !validPoint(myRequest.latitude, myRequest.longitude) || !state || !state.loc) return '';
        const meters = window.MBUSRouting && window.MBUSRouting.distanceMeters
            ? window.MBUSRouting.distanceMeters([Number(myRequest.latitude), Number(myRequest.longitude)], [Number(state.loc.latitude), Number(state.loc.longitude)])
            : haversineMeters([Number(myRequest.latitude), Number(myRequest.longitude)], [Number(state.loc.latitude), Number(state.loc.longitude)]);
        const prefix = state.kind === 'live' ? 'Bus is ' : 'Last bus pin was ';
        return prefix + (window.MBUSRouting ? window.MBUSRouting.formatDistance(meters) : Math.round(meters) + ' m') + ' from your pickup (straight-line). ';
    }
    function haversineMeters(a, b) {
        const rad = Math.PI / 180, lat1 = a[0] * rad, lat2 = b[0] * rad;
        const dLat = (b[0] - a[0]) * rad, dLng = (b[1] - a[1]) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
    }
    async function cancelWaiting(scheduleId, busNumber) {
        if (cancelBusy) return;
        cancelBusy = true;
        let finalMessage = '';
        render();
        notify('Cancelling your waiting request…');
        try {
            const response = await fetch('../api/live_tracking.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
                body: new URLSearchParams({ csrf_token: csrf, action: 'cancel_waiting', schedule_id: String(scheduleId) })
            });
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Live tracking returned an unreadable response.'); }
            if (data && data.csrf_token) csrf = data.csrf_token;
            if (!response.ok || !data.ok) throw new Error(data.message || 'Could not cancel waiting.');
            if (myRequest && String(myRequest.schedule_id) === String(scheduleId)) myRequest = Object.assign({}, myRequest, { status: 'Cancelled' });
            version++;
            render();
            finalMessage = 'Waiting cancelled for bus ' + (busNumber || '') + '.';
            while (loading) await new Promise(resolve => setTimeout(resolve, 25));
            await load();
        } catch (error) {
            finalMessage = error.message || 'Could not cancel waiting.';
        } finally {
            cancelBusy = false;
            render();
            if (finalMessage) notify(finalMessage);
        }
    }

    function clearRoute() {
        routeRequestId++;
        routeKey = '';
        routeData = null;
        routeFailure = null;
        if (routeLayer) routeLayer.clearLayers();
    }

    function updateRouteEstimate(trip, state) {
        const box = el('routeEstimate');
        if (!box) return;
        if (!trip) {
            if (routeLayer) routeLayer.clearLayers();
            routeKey = '';
            routeData = null;
            updateRouteViewControl();
            box.textContent = trips.length > 1 ? 'Select a bus to see its road route and destination estimate.' : 'No active bus route is available.';
            return;
        }
        const endpointValues = [trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng];
        if (endpointValues.some(value => value === null || value === undefined || String(value).trim() === '')) {
            box.textContent = 'This trip does not have mapped terminal coordinates yet.';
            if (routeLayer) routeLayer.clearLayers();
            routeData = null;
            updateRouteViewControl();
            return;
        }
        const origin = { lat: Number(trip.origin_lat), lng: Number(trip.origin_lng) };
        const destination = { lat: Number(trip.destination_lat), lng: Number(trip.destination_lng) };
        const key = String(trip.schedule_id) + ':' + [origin.lat, origin.lng, destination.lat, destination.lng].join(',');
        if (!Number.isFinite(origin.lat) || !Number.isFinite(origin.lng) || !Number.isFinite(destination.lat) || !Number.isFinite(destination.lng)) {
            box.textContent = 'This trip does not have mapped terminal coordinates yet.';
            if (routeLayer) routeLayer.clearLayers();
            routeData = null;
            updateRouteViewControl();
            return;
        }
        if (routeKey !== key) {
            routeKey = key;
            routeData = null;
            routeFailure = null;
            updateRouteViewControl();
            const requestId = ++routeRequestId;
            if (routeLayer) routeLayer.clearLayers();
            box.textContent = busToPickupText(trip, state) + 'Loading the road route to ' + (trip.destination_label || trip.destination || 'the destination') + '…';
            if (!window.MBUSRouting || !map) {
                box.textContent = busToPickupText(trip, state) + 'Road directions are unavailable; the live bus pin is still shown.';
                return;
            }
            window.MBUSRouting.getDrivingRoute(origin, destination).then(route => {
                if (requestId !== routeRequestId || routeKey !== key) return;
                routeData = route;
                routeFailure = null;
                routeLayer.clearLayers();
                L.polyline(route.points, { color: '#1e90ff', weight: 5, opacity: 0.82 }).addTo(routeLayer);
                L.circleMarker(route.points[0], { radius: 5, color: '#fff', weight: 2, fillColor: '#1e90ff', fillOpacity: 1 }).addTo(routeLayer);
                L.circleMarker(route.points[route.points.length - 1], { radius: 5, color: '#fff', weight: 2, fillColor: '#18365d', fillOpacity: 1 }).addTo(routeLayer);
                routeFitKey = key;
                if (routeViewMode === 'route') {
                    const boundsPoints = route.points.slice();
                    boundsPoints.push(...selectedTrackingPoints());
                    map.fitBounds(L.latLngBounds(boundsPoints).pad(0.08), { maxZoom: 12, animate: false });
                }
                updateRouteViewControl();
                updateRouteEstimate(trip, stateOf(trip));
            }).catch(error => {
                if (requestId !== routeRequestId || routeKey !== key) return;
                routeFailure = { key, until: Date.now() + 30000 };
                updateRouteViewControl();
                box.textContent = busToPickupText(trip, stateOf(trip)) + (error.message || 'Road directions are unavailable right now.') + ' The bus pin remains available.';
            });
            return;
        }
        if (!routeData) {
            if (routeFailure && routeFailure.key === key && Date.now() < routeFailure.until) return;
            routeKey = '';
            updateRouteEstimate(trip, state);
            return;
        }
        const destinationName = trip.destination_label || (window.MBUSRouting ? window.MBUSRouting.displayName(trip.destination) : trip.destination) || 'destination';
        const pickupDistance = busToPickupText(trip, state);
        if (!state || !state.loc) {
            box.textContent = pickupDistance + 'To ' + destinationName + ': ' + window.MBUSRouting.formatDistance(routeData.distanceMeters) + ' road route · ' + window.MBUSRouting.formatDuration(routeData.durationSeconds) + ' driving estimate. Waiting for the first live bus pin.';
            return;
        }
        if (state.kind !== 'live') {
            box.textContent = pickupDistance + 'Last bus pin ' + state.label.replace(/^Last seen\s*/, '') + '. Road ETA returns when the driver shares a fresh location.';
            return;
        }
        const remaining = window.MBUSRouting.remainingOnRoute(routeData, [Number(state.loc.latitude), Number(state.loc.longitude)], 2500);
        if (!remaining || !remaining.onRoute) {
            box.textContent = pickupDistance + 'Bus location is away from the mapped route. Destination ETA is unavailable until it returns to the route.';
            return;
        }
        box.textContent = pickupDistance + 'To ' + destinationName + ': ' + window.MBUSRouting.formatDistance(remaining.distanceMeters) + ' on the road · ' + window.MBUSRouting.formatDuration(remaining.durationSeconds) + '. Estimate excludes traffic, stops and detours.';
        if (remaining.distanceMeters < 75) box.textContent = pickupDistance + 'Bus is near ' + destinationName + '. ' + window.MBUSRouting.formatDistance(remaining.distanceMeters) + ' left.';
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
        const defaultTrip = trips.find(t => stateOf(t).kind === 'live') || trips.find(t => stateOf(t).kind === 'stale') || trips[0];
        const selection = window.MBUSLiveSelection.chooseSchedule(
            trips, requestedScheduleId, chosen, manualChoice, defaultTrip && defaultTrip.schedule_id
        );
        const preferredSchedule = myRequest && myRequest.status === 'Waiting' ? String(myRequest.schedule_id) : '';
        const resolvedSelection = preferredSchedule && !requestedScheduleId && !manualChoice && trips.some(t => String(t.schedule_id) === preferredSchedule)
            ? { scheduleId: preferredSchedule, unavailableId: '' } : selection;
        chosen = resolvedSelection.scheduleId;
        requestedUnavailableId = resolvedSelection.unavailableId;
        notify(requestedUnavailableId
            ? 'This bus is no longer active. Choose another bus below if you want to track a different one.'
            : '');
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
            row.disabled = false;
            const icon = document.createElement('span'); icon.className = 'bus-row-icon'; icon.innerHTML = '<i class="fa fa-bus" aria-hidden="true"></i>';
            const main = document.createElement('span'); main.className = 'bus-row-main';
            const name = document.createElement('span'); name.className = 'bus-row-name'; name.textContent = trip.bus_number || 'Bus';
            const route = document.createElement('span'); route.className = 'bus-row-route'; route.textContent = routeOf(trip);
            main.append(name, route);
            const state = document.createElement('span'); state.className = 'bus-row-state'; state.textContent = st.label;
            row.append(icon, main, state);
            row.addEventListener('click', () => pick(trip.schedule_id));
            const wrap = document.createElement('div'); wrap.className = 'bus-row-wrap';
            const pickupAction = window.MBUSLiveSelection.pickupAction(trip.schedule_id, myRequest);
            const go = document.createElement(pickupAction.kind === 'pickup' ? 'a' : 'button');
            go.className = 'bus-row-pick' + (pickupAction.kind === 'cancel' ? ' is-cancel' : '');
            go.textContent = pickupAction.label;
            if (pickupAction.kind === 'cancel') {
                go.type = 'button'; go.disabled = cancelBusy;
                go.setAttribute('aria-label', 'Cancel waiting for bus ' + (trip.bus_number || ''));
                go.addEventListener('click', () => cancelWaiting(trip.schedule_id, trip.bus_number));
            } else if (pickupAction.kind === 'waiting') {
                go.type = 'button'; go.disabled = true;
                go.setAttribute('aria-label', 'You are already waiting for another bus');
                go.title = 'Cancel your current waiting request before choosing another bus.';
            } else {
                go.href = 'pickup.php?schedule_id=' + encodeURIComponent(trip.schedule_id);
                go.setAttribute('aria-label', 'Pick up at bus ' + (trip.bus_number || ''));
            }
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
                const popupAction = window.MBUSLiveSelection.pickupAction(trip.schedule_id, myRequest);
                const popGo = document.createElement(popupAction.kind === 'pickup' ? 'a' : 'button'); popGo.className = 'bus-pop-pick';
                if (popupAction.kind === 'cancel') { popGo.type = 'button'; popGo.classList.add('is-cancel'); popGo.textContent = 'Cancel waiting'; popGo.addEventListener('click', () => cancelWaiting(trip.schedule_id, trip.bus_number)); }
                else if (popupAction.kind === 'waiting') { popGo.type = 'button'; popGo.textContent = 'Already waiting'; popGo.disabled = true; }
                else { popGo.href = 'pickup.php?schedule_id=' + encodeURIComponent(trip.schedule_id); popGo.textContent = 'Pick up'; }
                popup.append(strong, document.createElement('br'), document.createTextNode(st.label), document.createElement('br'), popGo);
                L.marker(point, { icon: busIcon(trip.bus_number, st.kind === 'stale', false), title: 'Bus ' + (trip.bus_number || ''), zIndexOffset: 500 })
                    .addTo(markerLayer).bindPopup(popup).on('click', () => { if (String(trip.schedule_id) !== chosen) pick(trip.schedule_id); });
            });
            if (commuterLayer) {
                commuterLayer.clearLayers();
                const myPoint = myRequest && myRequest.status === 'Waiting' && validPoint(myRequest.latitude, myRequest.longitude)
                    ? [Number(myRequest.latitude), Number(myRequest.longitude)] : null;
                const currentTrip = trips.find(t => String(t.schedule_id) === chosen);
                const currentState = currentTrip ? stateOf(currentTrip) : null;
                const busPoint = currentState && currentState.loc ? [Number(currentState.loc.latitude), Number(currentState.loc.longitude)] : null;
                if (myPoint && (!chosen || String(myRequest.schedule_id) === chosen)) {
                    bounds.push(myPoint);
                    if (myPoint) {
                        const riderMarker = L.marker(myPoint, { icon: riderIcon(), title: 'Your waiting pickup', zIndexOffset: 900 })
                            .addTo(commuterLayer).bindPopup('Your waiting pickup');
                        bindRiderFallback(riderMarker);
                    }
                    if (chosen && String(myRequest.schedule_id) === chosen && busPoint) {
                        L.polyline([myPoint, busPoint], { color: '#d1493f', weight: 3, dashArray: '3 8', opacity: .85 }).addTo(commuterLayer);
                        bounds.push(busPoint);
                    }
                }
            }
            if (!fitted && bounds.length) {
                if (focus && myRequest && myRequest.status === 'Waiting' && String(myRequest.schedule_id) === chosen && bounds.length > 1) map.fitBounds(L.latLngBounds(bounds).pad(0.35), { maxZoom: 16, animate: false });
                else if (focus) map.setView(focus, 16, { animate: false });
                else if (bounds.length === 1) map.setView(bounds[0], 16, { animate: false });
                else map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
                fitted = true;
            } else if (routeViewMode === 'bus') {
                const points = selectedTrackingPoints();
                if (points.length > 1) map.fitBounds(L.latLngBounds(points).pad(0.35), { maxZoom: 16, animate: false });
                else if (focus) map.setView(focus, Math.max(16, map.getZoom()), { animate: false });
            }
            window.requestAnimationFrame(() => map.invalidateSize({ pan: false }));
        }

        const selectedTrip = trips.find(t => String(t.schedule_id) === chosen) || null;
        updateRouteEstimate(selectedTrip, selectedTrip ? stateOf(selectedTrip) : null);

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
            myRequest = data.my_request || null;
            if (data.csrf_token) csrf = data.csrf_token;
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
    el('routeViewButton').addEventListener('click', toggleRouteView);
    document.addEventListener('visibilitychange', () => { if (!visible()) clearTimeout(pollTimer); else load(); });
    initMap();
    load();
})();
