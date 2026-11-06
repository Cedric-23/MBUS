/* Commuter live trip map and explicitly confirmed waiting-location sharing. */
(function () {
    'use strict';
    const config = window.mbusLive || {};
    const el = id => document.getElementById(id);
    const select = el('liveSchedule');
    if (!select) return;
    let trips = [], requests = [], csrf = config.csrfToken || '', chosen = '', point = null;
    let map = null, routeLayer = null, busMarker = null, userMarker = null, pollTimer = null, busy = false, loading = false, version = 0;
    const visible = () => !document.hidden;
    function notify(text, type) { el('liveMessage').textContent = text; el('liveMessage').className = 'live-notice' + (type ? ' live-notice-' + type : ''); }
    function number(v) { const n = Number(v); return Number.isFinite(n) ? n : null; }
    function coords(obj) {
        if (!obj) return null;
        const lat = number(obj.latitude ?? obj.lat ?? obj.bus_latitude ?? obj.bus_lat);
        const lng = number(obj.longitude ?? obj.lng ?? obj.lon ?? obj.bus_longitude ?? obj.bus_lng);
        return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 ? [lat, lng] : null;
    }
    function setStatus(text, live) { el('trackingStatus').textContent = text; el('trackingStatus').className = 'live-status' + (live ? '' : ' is-ended'); }
    if (typeof L !== 'undefined' && el('trackingMap')) {
        map = L.map('trackingMap').setView([14.676, 120.27], 11);
        const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
        tiles.on('tileerror', () => { el('mapMessage').textContent = 'Map tiles could not load. Route and bus status remain available below; try again when your connection improves.'; });
        routeLayer = L.layerGroup().addTo(map);
        map.on('click', e => setPoint(e.latlng.lat, e.latlng.lng, false));
    } else { el('trackingMap').hidden = true; el('mapMessage').textContent = 'The map could not load. Live trip status is still available; map pin selection is unavailable.'; }
    function setPoint(lat, lng, pan) {
        lat = number(lat); lng = number(lng);
        if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
        point = { latitude: lat, longitude: lng };
        el('coordinateText').textContent = 'Selected point: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
        if (map) {
            if (!userMarker) {
                userMarker = L.marker([lat, lng], { draggable: true, title: 'Your selected waiting point' }).addTo(map).bindPopup('Selected waiting point');
                userMarker.on('dragend', () => { const p = userMarker.getLatLng(); setPoint(p.lat, p.lng, false); });
            } else userMarker.setLatLng([lat, lng]);
            if (pan) map.setView([lat, lng], 16);
        }
        el('waitBtn').disabled = !chosen || busy;
    }
    function getTrip() { return trips.find(t => String(t.schedule_id) === String(chosen)); }
    function draw(trip, location) {
        const loc = coords(location);
        if (routeLayer) routeLayer.clearLayers();
        if (busMarker && map) { map.removeLayer(busMarker); busMarker = null; }
        const bounds = [];
        if (trip && routeLayer) {
            const stops = [
                { name: trip.origin || trip.origin_name || 'Route origin', lat: trip.origin_lat, lng: trip.origin_lng },
                { name: trip.destination || trip.destination_name || 'Route endpoint', lat: trip.destination_lat, lng: trip.destination_lng }
            ];
            stops.forEach(stop => { const lat = number(stop.lat), lng = number(stop.lng); if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) return; const p = [lat, lng]; bounds.push(p); L.circleMarker(p, { radius: 7, color: '#18365d', fillColor: '#fff', fillOpacity: 1, weight: 3 }).addTo(routeLayer).bindPopup(document.createTextNode(stop.name)); });
        }
        if (loc && map) { busMarker = L.marker(loc, { title: 'Bus location' }).addTo(map).bindPopup('Bus location'); bounds.push(loc); }
        if (bounds.length && map) map.fitBounds(bounds, { padding: [28, 28], maxZoom: 14 });
        return loc;
    }
    function updateWaiting() {
        const active = requests.find(r => ['waiting','active','pending'].includes(String(r.status || '').toLowerCase()));
        el('waitingCard').hidden = !active;
        el('markWaitingPanel').hidden = !!active;
        if (!active) return;
        el('waitingStatus').textContent = active.status || 'Waiting';
        el('waitingDetails').textContent = (active.pickup_label || 'Pinned location') + (active.latitude != null && active.longitude != null ? ' · ' + Number(active.latitude).toFixed(5) + ', ' + Number(active.longitude).toFixed(5) : '');
        el('waitingExpiry').textContent = 'Shared with operators for this trip only.';
        el('cancelWaitingBtn').dataset.requestId = active.request_id || '';
    }
    async function request(url, options) {
        const response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }, options || {}));
        let data; try { data = await response.json(); } catch (_) { throw new Error('Live tracking service returned an unreadable response.'); }
        if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to update live tracking.');
        return data;
    }
    function activeTrips(data) {
        const src = Array.isArray(data.active) ? data.active : Array.isArray(data.schedules) ? data.schedules : data.schedule ? [data.schedule] : data.active && typeof data.active === 'object' ? [data.active] : [];
        return src.filter(t => t && t.schedule_id != null);
    }
    async function load() {
        if (loading || busy || !visible()) return;
        loading = true; const thisVersion = ++version;
        try {
            const data = await request('../api/live_tracking.php' + (chosen ? '?schedule_id=' + encodeURIComponent(chosen) : ''), {});
            if (thisVersion !== version) return;
            trips = activeTrips(data);
            if (data.csrf_token) csrf = data.csrf_token;
            if (!trips.some(t => String(t.schedule_id) === String(chosen))) chosen = String(config.scheduleId || (trips[0] && trips[0].schedule_id) || '');
            if (!trips.some(t => String(t.schedule_id) === String(chosen))) chosen = String((trips[0] || {}).schedule_id || '');
            select.replaceChildren(new Option(trips.length ? 'Choose a trip' : 'No active reservations', ''));
            trips.forEach(t => { const o = new Option((t.bus_number || 'Bus') + ' · ' + (t.origin || 'Origin') + ' → ' + (t.destination || t.route_destination || 'Endpoint'), String(t.schedule_id)); select.add(o); });
            select.value = chosen; select.disabled = !trips.length;
            const trip = getTrip();
            el('tripRoute').textContent = trip ? (trip.bus_number || 'Bus') + ' · ' + (trip.origin || 'Origin') + ' → ' + (trip.destination || trip.route_destination || 'Endpoint') : 'No active paid, confirmed, or boarded reservation is available.';
            const selectedSchedule = data.schedule && String(data.schedule.schedule_id) === String(chosen) ? data.schedule : trip;
            const location = data.location;
            const busLocation = location && (location.schedule_id == null || String(location.schedule_id) === String(chosen)) ? coords(location) : null;
            draw(selectedSchedule, busLocation);
            const serverNow = Date.parse(data.server_time || Date.now());
            const updated = location && (location.updated_at || location.recorded_at || location.timestamp || location.created_at);
            const age = updated ? Math.max(0, serverNow - Date.parse(updated)) : Infinity;
            const fresh = busLocation && Number.isFinite(age) && age <= 120000;
            el('updatedAt').textContent = 'Location update: ' + (updated ? new Date(updated).toLocaleString() : 'Not available');
            el('freshness').textContent = fresh ? 'Updated ' + Math.max(0, Math.floor(age / 1000)) + ' sec ago' : busLocation ? 'Location is stale' : 'No bus GPS available';
            setStatus(fresh ? 'Live location' : busLocation ? 'Stale location' : trip ? 'No bus GPS' : 'No active trip', !!fresh);
            el('mapMessage').textContent = fresh ? 'Bus location is being shared by the operator.' : busLocation ? 'Last reported bus location may be out of date. Do not rely on it as current.' : trip ? 'The operator has not shared a bus GPS location. No bus marker is shown.' : 'Select one of your active reservations to view its route.';
            requests = Array.isArray(data.requests) ? data.requests : data.waiting ? [data.waiting] : [];
            updateWaiting();
            if (!trips.length) notify('No active paid, confirmed, or boarded reservations were found. Book or check your trip from Schedule.', '');
            else if (!busLocation) notify('Trip loaded. Bus GPS is not currently available; no live bus marker is shown.', '');
            else if (!fresh) notify('The last bus location is stale. Wait for a fresh operator update.', 'error');
            else notify('Live bus location updated.', 'success');
        } catch (e) { if (thisVersion === version) { setStatus('Connection issue', false); notify(e.message, 'error'); } }
        finally { loading = false; if (thisVersion === version && visible()) pollTimer = setTimeout(load, 9000); }
    }
    async function mutate(action) {
        if (busy || !chosen) return;
        if (action === 'mark_waiting' && !point) { notify('Use GPS or tap the map to choose your waiting point first.', 'error'); return; }
        busy = true; el('waitBtn').disabled = true;
        const params = new URLSearchParams({ csrf_token: csrf, action, schedule_id: chosen });
        if (action === 'mark_waiting') { params.set('latitude', point.latitude); params.set('longitude', point.longitude); if (el('pickupLabel').value.trim()) params.set('pickup_label', el('pickupLabel').value.trim().slice(0, 100)); }
        else if (el('cancelWaitingBtn').dataset.requestId) params.set('request_id', el('cancelWaitingBtn').dataset.requestId);
        try { const data = await request('../api/live_tracking.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: params }); notify(data.message || (action === 'mark_waiting' ? 'Waiting location shared with your operator.' : 'Waiting request cancelled.'), 'success'); }
        catch (e) { notify(e.message, 'error'); }
        finally { busy = false; await load(); }
    }
    select.addEventListener('change', () => { chosen = select.value; point = null; el('coordinateText').textContent = 'Choose a point using GPS or tap the map.'; el('waitBtn').disabled = !chosen; clearTimeout(pollTimer); load(); });
    el('waitBtn').addEventListener('click', () => mutate('mark_waiting'));
    el('cancelWaitingBtn').addEventListener('click', () => mutate('cancel_waiting'));
    el('locateBtn').addEventListener('click', () => {
        if (!navigator.geolocation) { notify('GPS is unavailable. Tap the map to select your location.', 'error'); return; }
        el('locateBtn').disabled = true;
        navigator.geolocation.getCurrentPosition(p => { el('locateBtn').disabled = false; setPoint(p.coords.latitude, p.coords.longitude, true); }, () => { el('locateBtn').disabled = false; notify('Could not access GPS. Tap the map to select your location.', 'error'); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
    });
    document.addEventListener('visibilitychange', () => { if (!visible()) clearTimeout(pollTimer); else load(); });
    load();
})();
