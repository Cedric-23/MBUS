/* Live pickup shares a location only on an explicit form submission. */
(function () {
    'use strict';
    const config = window.mbusLive || {};
    const el = id => document.getElementById(id);
    const form = el('pickupForm');
    if (!form) return;
    const select = el('liveSchedule');
    const destination = el('liveDestination');
    const waitButton = el('waitBtn');
    const message = el('liveMessage');
    let schedules = [];
    let requests = [];
    let csrf = config.csrfToken || '';
    let ready = false;
    let busy = false;
    let firstLoad = true;
    let pin = null;
    let marker = null;
    let map = null;
    let routeLayer = null;
    let selectedTripKey = '';
    let serverOffset = 0;
    let timer = null;
    let getController = null;
    let requestVersion = 0;

    const statusKey = status => String(status || '').toLowerCase().replace(/[_-]/g, ' ');
    const currentTrip = () => schedules.find(s => String(s.schedule_id) === select.value);
    const requestForTrip = () => requests.find(r => String(r.schedule_id) === select.value);
    const textNode = text => { const p = document.createElement('span'); p.textContent = text; return p; };
    function notify(text, type) {
        message.textContent = text;
        message.className = 'live-notice' + (type ? ' live-notice-' + type : '');
    }
    function parseTime(value) {
        if (!value) return NaN;
        let normalized = String(value).replace(' ', 'T');
        // PostgreSQL local timestamps need the app offset, not the phone's timezone.
        if (!/(Z|[+-]\d{2}:?\d{2})$/.test(normalized)) normalized += config.timezoneOffset || '+08:00';
        return Date.parse(normalized);
    }
    function effectiveStatus(request) {
        let status = statusKey(request.status);
        if (status === 'waiting' && parseTime(request.expires_at) <= Date.now() + serverOffset) status = 'expired';
        return status;
    }
    function refreshButton() {
        const trip = currentTrip();
        waitButton.disabled = !ready || busy || !trip || trip.action !== 'pickup' || !pin || !destination.value || !el('pickupLabel').value.trim();
        const waiting = requests.some(r => effectiveStatus(r) === 'waiting');
        waitButton.textContent = busy ? 'Saving…' : waiting ? 'Update my waiting request' : 'I’m waiting here';
    }
    function setPin(latitude, longitude, moveMap) {
        const lat = Number(latitude), lng = Number(longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            notify('Enter valid latitude and longitude coordinates.', 'error');
            return;
        }
        pin = { lat, lng };
        el('coordinateText').textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
        el('manualLatitude').value = lat.toFixed(6);
        el('manualLongitude').value = lng.toFixed(6);
        if (map) {
            if (!marker) {
                marker = L.marker([lat, lng], {
                    draggable: true,
                    title: 'Your waiting spot — drag to adjust',
                    icon: L.divIcon({ className: 'live-pin', html: '<i class="fa fa-person" aria-hidden="true"></i>', iconSize: [28, 28], iconAnchor: [14, 14] })
                }).addTo(map).bindPopup(textNode('Your waiting spot'));
                marker.on('dragend', function () { const point = marker.getLatLng(); setPin(point.lat, point.lng, false); });
            } else marker.setLatLng([lat, lng]);
            if (moveMap) map.setView([lat, lng], 16);
        }
        refreshButton();
    }
    if (typeof L !== 'undefined') {
        map = L.map('pickupMap').setView([14.676, 120.27], 12);
        const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        tiles.on('tileerror', function () { el('mapMessage').textContent = 'Map tiles could not load. Retry your connection, or enter your waiting coordinates below.'; });
        map.on('click', event => setPin(event.latlng.lat, event.latlng.lng, false));
        routeLayer = L.layerGroup().addTo(map);
    } else {
        el('pickupMap').hidden = true;
        el('centerPinBtn').disabled = true;
        el('mapMessage').textContent = 'The map could not load. Use GPS or enter coordinates below to choose your waiting spot.';
    }
    function drawRoute(trip) {
        if (!map || !routeLayer) return;
        routeLayer.clearLayers();
        const points = [];
        [['origin_lat', 'origin_lng', trip.origin], ['destination_lat', 'destination_lng', trip.destination]].forEach(function (stop) {
            if (trip[stop[0]] === null || trip[stop[0]] === undefined || trip[stop[0]] === '' || trip[stop[1]] === null || trip[stop[1]] === undefined || trip[stop[1]] === '') return;
            const lat = Number(trip[stop[0]]), lng = Number(trip[stop[1]]);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            points.push([lat, lng]);
            L.circleMarker([lat, lng], { radius: 6, color: '#18365d', fillOpacity: .8 }).addTo(routeLayer).bindPopup(textNode(stop[2] || 'Route stop'));
        });
        if (points.length && !pin) map.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 13 });
    }
    function syncTrip(restore) {
        const trip = currentTrip();
        const key = trip ? String(trip.schedule_id) + ':' + JSON.stringify(trip.stops || []) : '';
        if (key !== selectedTripKey) {
            selectedTripKey = key;
            const previous = destination.value;
            destination.replaceChildren(new Option('Select a destination', ''));
            let stops = trip && Array.isArray(trip.stops) ? trip.stops : [];
            if (!stops.length && trip && trip.destination) stops = [trip.destination];
            stops.forEach(stop => destination.add(new Option(stop, stop)));
            if (stops.includes(previous)) destination.value = previous;
            if (trip) drawRoute(trip);
        }
        destination.disabled = !ready || !trip;
        const reserve = el('reserveInstead');
        reserve.hidden = !trip || trip.action !== 'reserve';
        if (trip) reserve.href = 'reserve.php?schedule_id=' + encodeURIComponent(trip.schedule_id);
        el('tripStatus').textContent = !trip ? 'No active trip selected.' : trip.action === 'reserve'
            ? 'Reservations are still open for this bus. Reserve a seat before the 30-minute cutoff.'
            : trip.trip_status === 'Departed' ? 'Bus departed · pickup requests are available.'
            : 'Seat reservations are closed · pickup requests are available.';
        if (restore && trip && String(restore.schedule_id) === String(trip.schedule_id)) {
            destination.value = restore.destination || '';
            el('pickupLabel').value = restore.pickup_label || '';
            setPin(restore.latitude, restore.longitude, true);
        }
        refreshButton();
    }
    function renderWaiting() {
        const request = requests.find(r => effectiveStatus(r) === 'waiting') || requests[0];
        el('waitingCard').hidden = !request;
        if (!request) { el('cancelWaitingBtn').hidden = true; return; }
        const status = effectiveStatus(request);
        const labels = { waiting: 'Waiting', 'picked up': 'Picked up', cancelled: 'Cancelled', expired: 'Expired' };
        el('waitingStatus').textContent = labels[status] || request.status;
        el('waitingStatus').className = 'live-status' + (status === 'waiting' ? '' : ' is-ended');
        el('waitingTrip').textContent = (request.bus_number || 'Bus') + ' · ' + (request.origin || '') + ' → ' + (request.route_destination || request.destination || '');
        el('waitingDetails').textContent = (request.pickup_label || 'Pinned spot') + ' → ' + request.destination;
        const minutes = Math.max(0, Math.ceil((parseTime(request.expires_at) - Date.now() - serverOffset) / 60000));
        el('waitingExpiry').textContent = status === 'waiting'
            ? 'Visible to your operator and admins. Expires in ' + minutes + ' min unless you confirm again.'
            : status === 'picked up' ? 'Your operator marked you picked up. Have a safe trip.'
            : status === 'expired' ? 'This request is no longer in the waiting queue. Confirm again if you are still waiting.'
            : 'Your waiting location is no longer in the active queue.';
        el('cancelWaitingBtn').hidden = status !== 'waiting';
        el('cancelWaitingBtn').disabled = busy;
        el('cancelWaitingBtn').dataset.requestId = request.request_id;
    }
    async function api(options) {
        const controller = options && options.signal ? null : new AbortController();
        const timeout = controller ? setTimeout(() => controller.abort(), 15000) : null;
        try {
            const response = await fetch('../api/pickup.php', Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined }, options || {}));
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Pickup service is unavailable. Please try again shortly.'); }
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load pickup information.');
            return data;
        } catch (error) {
            if (controller && error.name === 'AbortError') throw new Error('The request timed out. Refresh to check its status before trying again.');
            throw error;
        } finally { if (timeout) clearTimeout(timeout); }
    }
    async function load() {
        if (busy) return;
        clearTimeout(timer);
        const version = ++requestVersion;
        if (getController) getController.abort();
        getController = new AbortController();
        const timeout = setTimeout(() => getController && getController.abort(), 15000);
        try {
            const data = await api({ signal: getController.signal });
            if (version !== requestVersion) return;
            ready = data.ready === true;
            schedules = Array.isArray(data.schedules) ? data.schedules : [];
            requests = Array.isArray(data.requests) ? data.requests : [];
            if (data.csrf_token) csrf = data.csrf_token;
            const serverTime = Date.parse(data.server_time);
            if (Number.isFinite(serverTime)) serverOffset = serverTime - Date.now();
            const waiting = requests.find(r => effectiveStatus(r) === 'waiting');
            let chosen = select.value;
            if (firstLoad) chosen = String(config.scheduleId || (waiting && waiting.schedule_id) || '');
            if (!schedules.some(s => String(s.schedule_id) === chosen)) chosen = String((schedules.find(s => s.action === 'pickup') || schedules[0] || {}).schedule_id || '');
            select.replaceChildren(new Option(schedules.length ? 'Choose a bus' : 'No active trips available', ''));
            schedules.forEach(s => select.add(new Option(s.bus_number + ' · ' + s.origin + ' → ' + s.destination + ' · ' + (s.action === 'pickup' ? 'Pick up' : 'Reserve'), String(s.schedule_id))));
            select.value = chosen;
            select.disabled = !ready || !schedules.length;
            syncTrip(firstLoad && waiting ? waiting : null);
            renderWaiting();
            if (!ready) notify(data.message || 'Live pickup needs a database update. Please contact the administrator.', 'error');
            else if (!schedules.length) notify('No active trips right now. Check the schedule for your next bus.');
            else if (firstLoad && config.scheduleId && !schedules.some(s => String(s.schedule_id) === String(config.scheduleId))) notify('That trip is no longer available. Choose another active bus.', 'error');
            else if (firstLoad || message.classList.contains('live-notice-error')) notify('Choose your bus, pin a safe spot and confirm when you’re ready.');
            firstLoad = false;
        } catch (error) {
            if (version !== requestVersion) return;
            ready = false;
            if (error.name !== 'AbortError') notify(error.message, 'error');
            else notify('The pickup service took too long to respond. Retrying shortly.', 'error');
        } finally {
            clearTimeout(timeout);
            refreshButton();
            if (version === requestVersion) timer = setTimeout(load, 15000);
        }
    }
    async function mutate(values) {
        if (busy || !ready) return;
        busy = true;
        ++requestVersion;
        if (getController) getController.abort();
        clearTimeout(timer);
        refreshButton();
        renderWaiting();
        let outcome = null;
        try {
            const payload = new URLSearchParams(Object.assign({ csrf_token: csrf }, values));
            const data = await api({ method: 'POST', body: payload });
            outcome = { text: data.message || 'Your pickup request has been updated.', type: 'success' };
        } catch (error) { outcome = { text: error.message, type: 'error' }; }
        finally {
            busy = false;
            await load();
            if (ready && outcome) notify(outcome.text, outcome.type);
        }
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const trip = currentTrip();
        if (!trip || trip.action !== 'pickup' || !pin || !form.reportValidity()) return;
        mutate({ action: 'wait', schedule_id: trip.schedule_id, latitude: pin.lat, longitude: pin.lng, pickup_label: el('pickupLabel').value.trim(), destination: destination.value });
    });
    select.addEventListener('change', () => syncTrip(requestForTrip()));
    destination.addEventListener('change', refreshButton);
    el('pickupLabel').addEventListener('input', refreshButton);
    el('cancelWaitingBtn').addEventListener('click', function () { mutate({ action: 'cancel', request_id: this.dataset.requestId }); });
    el('centerPinBtn').addEventListener('click', function () { if (map) { const center = map.getCenter(); setPin(center.lat, center.lng, false); } });
    el('manualPinBtn').addEventListener('click', function () {
        if (el('manualLatitude').value === '' || el('manualLongitude').value === '') { notify('Enter both latitude and longitude.', 'error'); return; }
        setPin(el('manualLatitude').value, el('manualLongitude').value, true);
    });
    el('locateBtn').addEventListener('click', function () {
        const button = this;
        if (!navigator.geolocation) { notify('GPS is not available. Tap the map or enter coordinates to mark your spot.', 'error'); return; }
        button.disabled = true;
        el('mapMessage').textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(function (position) {
            button.disabled = false;
            setPin(position.coords.latitude, position.coords.longitude, true);
            el('mapMessage').textContent = 'Location found. Check the pin, then confirm to share your waiting spot.';
        }, function () {
            button.disabled = false;
            el('mapMessage').textContent = 'Location access was denied or timed out. Tap the map or enter coordinates instead.';
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
    });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { clearTimeout(timer); }
        else if (!busy) load();
    });
    load();
})();
