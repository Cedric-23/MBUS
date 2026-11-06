/* Commuter live bus route tracking only. */
(function () {
    'use strict';
    const el = id => document.getElementById(id);
    const select = el('liveSchedule');
    if (!select) return;
    let trips = [], chosen = '', csrf = (window.mbusLive || {}).csrfToken || '';
    let map = null, routeLayer = null, busMarker = null, pollTimer = null, loading = false, version = 0;
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
        tiles.on('tileerror', () => { el('mapMessage').textContent = 'Map tiles could not load. Route and bus status remain available; try again when your connection improves.'; });
        routeLayer = L.layerGroup().addTo(map);
    } else if (el('trackingMap')) { el('trackingMap').hidden = true; el('mapMessage').textContent = 'The map could not load. Live trip status is still available.'; }
    function draw(trip, location) {
        const loc = coords(location);
        if (routeLayer) routeLayer.clearLayers();
        if (busMarker && map) { map.removeLayer(busMarker); busMarker = null; }
        const bounds = [];
        if (trip && routeLayer) [
            { name: trip.origin || trip.origin_name || 'Route origin', lat: trip.origin_lat, lng: trip.origin_lng },
            { name: trip.destination || trip.destination_name || 'Route endpoint', lat: trip.destination_lat, lng: trip.destination_lng }
        ].forEach(stop => { const lat = number(stop.lat), lng = number(stop.lng); if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) return; const p = [lat, lng]; bounds.push(p); L.circleMarker(p, { radius: 7, color: '#18365d', fillColor: '#fff', fillOpacity: 1, weight: 3 }).addTo(routeLayer).bindPopup(document.createTextNode(stop.name)); });
        if (loc && map) { busMarker = L.marker(loc, { title: 'Bus location' }).addTo(map).bindPopup('Bus location'); bounds.push(loc); }
        if (bounds.length && map) map.fitBounds(bounds, { padding: [28, 28], maxZoom: 14 });
        return loc;
    }
    async function request(url) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        let data; try { data = await response.json(); } catch (_) { throw new Error('Live tracking service returned an unreadable response.'); }
        if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load live tracking.');
        return data;
    }
    function activeTrips(data) {
        const src = Array.isArray(data.active) ? data.active : Array.isArray(data.schedules) ? data.schedules : data.schedule ? [data.schedule] : data.active && typeof data.active === 'object' ? [data.active] : [];
        return src.filter(t => t && t.schedule_id != null);
    }
    async function load() {
        if (loading || !visible()) return;
        loading = true; const thisVersion = ++version;
        try {
            const data = await request('../api/live_tracking.php' + (chosen ? '?schedule_id=' + encodeURIComponent(chosen) : ''));
            if (thisVersion !== version) return;
            trips = activeTrips(data); if (data.csrf_token) csrf = data.csrf_token;
            if (!trips.some(t => String(t.schedule_id) === String(chosen))) chosen = String((window.mbusLive || {}).scheduleId || (trips[0] && trips[0].schedule_id) || '');
            if (!trips.some(t => String(t.schedule_id) === String(chosen))) chosen = String((trips[0] || {}).schedule_id || '');
            select.replaceChildren(new Option(trips.length ? 'Choose a bus' : 'No active Boarding/Departed buses', ''));
            trips.forEach(t => select.add(new Option((t.bus_number || 'Bus') + ' · ' + (t.origin || 'Origin') + ' → ' + (t.destination || t.route_destination || 'Endpoint'), String(t.schedule_id))));
            select.value = chosen; select.disabled = !trips.length;
            const trip = trips.find(t => String(t.schedule_id) === String(chosen));
            el('tripRoute').textContent = trip ? (trip.bus_number || 'Bus') + ' · ' + (trip.origin || 'Origin') + ' → ' + (trip.destination || trip.route_destination || 'Endpoint') : 'No assigned bus is currently boarding or in service.';
            const location = data.location && (data.location.schedule_id == null || String(data.location.schedule_id) === String(chosen)) ? data.location : null;
            const busLocation = draw(trip, location);
            const updated = location && (location.updated_at || location.recorded_at || location.timestamp || location.created_at);
            const age = updated ? Math.max(0, Date.parse(data.server_time || Date.now()) - Date.parse(updated)) : Infinity;
            const fresh = busLocation && Number.isFinite(age) && age <= 120000;
            el('updatedAt').textContent = 'Location update: ' + (updated ? new Date(updated).toLocaleString() : 'Not available');
            el('freshness').textContent = fresh ? 'Updated ' + Math.floor(age / 1000) + ' sec ago' : busLocation ? 'Location is stale' : 'No bus GPS available';
            setStatus(fresh ? 'Live location' : busLocation ? 'Stale location' : 'No bus GPS', !!fresh);
            el('mapMessage').textContent = fresh ? 'Bus location is being shared by the operator.' : busLocation ? 'Last reported bus location may be out of date. Do not rely on it as current.' : trip ? 'The operator has not shared a bus GPS location. No bus marker is shown.' : 'Select one of your active reservations to view its route.';
            if (!trips.length) notify('No bus is currently Boarding or Departed. Check again when your bus is in service.');
            else if (!busLocation) notify('Trip loaded. Bus GPS is not currently available; no live bus marker is shown.');
            else if (!fresh) notify('The last bus location is stale. Wait for a fresh operator update.', 'error');
            else notify('Live bus location updated.', 'success');
        } catch (e) { if (thisVersion === version) { setStatus('Connection issue', false); notify(e.message, 'error'); } }
        finally { loading = false; if (thisVersion === version && visible()) pollTimer = setTimeout(load, 9000); }
    }
    select.addEventListener('change', () => { chosen = select.value; clearTimeout(pollTimer); load(); });
    document.addEventListener('visibilitychange', () => { if (!visible()) clearTimeout(pollTimer); else load(); });
    load();
})();
