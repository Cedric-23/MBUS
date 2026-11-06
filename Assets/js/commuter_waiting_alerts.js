/* Alerts-owned commuter waiting-location picker and explicit sharing actions. */
(function () {
    'use strict';
    const el = id => document.getElementById(id);
    const select = el('waitingSchedule');
    if (!select) return;
    let trips = [], chosen = '', csrf = window.mbusWaitingConfig?.csrfToken || '', point = null, requests = [], map = null, marker = null, busy = false, loading = false, timer = null;
    const visible = () => !document.hidden;
    function message(text, kind) { const node = el('waitingMessage'); node.textContent = text; node.className = 'live-notice' + (kind ? ' live-notice-' + kind : ''); }
    function toNumber(value) { if (value === '' || value == null) return null; const n = Number(value); return Number.isFinite(n) ? n : null; }
    function setPoint(lat, lng, center) {
        lat = toNumber(lat); lng = toNumber(lng);
        if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) { message('Enter valid latitude (−90 to 90) and longitude (−180 to 180).', 'error'); return false; }
        point = { latitude: lat, longitude: lng };
        el('waitingLatitude').value = String(lat); el('waitingLongitude').value = String(lng);
        el('waitingCoordinateText').textContent = 'Selected point: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '. It will not be sent until you confirm.';
        if (map) { if (!marker) { marker = L.marker([lat, lng], { draggable: true, title: 'Your selected waiting location' }).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); setPoint(p.lat, p.lng, false); }); } else marker.setLatLng([lat, lng]); if (center) map.setView([lat, lng], 16); }
        el('shareWaitingBtn').disabled = !chosen || busy || !!activeRequest();
        return true;
    }
    function activeRequest() { return requests.find(r => ['waiting', 'active', 'pending'].includes(String(r.status || '').toLowerCase())); }
    if (typeof L !== 'undefined' && el('waitingMap')) {
        map = L.map('waitingMap').setView([14.676, 120.27], 11);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
        map.on('click', e => setPoint(e.latlng.lat, e.latlng.lng, false));
    } else { el('waitingMap').hidden = true; el('waitingMapMessage').textContent = 'Map unavailable. Use GPS or enter coordinates below.'; }
    async function api(url, options) {
        const response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }, options || {}));
        let data; try { data = await response.json(); } catch (_) { throw new Error('Tracking service returned an unreadable response.'); }
        if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to update waiting location.');
        return data;
    }
    function tripList(data) { const list = Array.isArray(data.schedules) ? data.schedules : Array.isArray(data.active) ? data.active : data.schedule ? [data.schedule] : []; return list.filter(t => t && t.schedule_id != null); }
    function renderRequest() {
        const active = activeRequest(); el('waitingOwnRequest').hidden = !active; el('waitingPicker').hidden = !!active;
        if (!active) return;
        el('waitingOwnStatus').textContent = active.status || 'Waiting';
        el('waitingOwnDetails').textContent = (active.pickup_label || 'Pinned location') + (active.latitude != null && active.longitude != null ? ' · ' + Number(active.latitude).toFixed(5) + ', ' + Number(active.longitude).toFixed(5) : '');
        el('cancelWaitingBtn').dataset.requestId = active.request_id || '';
    }
    async function load() {
        if (loading || busy || !visible()) return; loading = true;
        try {
            const data = await api('../api/live_tracking.php' + (chosen ? '?schedule_id=' + encodeURIComponent(chosen) : ''));
            trips = tripList(data); if (data.csrf_token) csrf = data.csrf_token;
            if (!trips.some(t => String(t.schedule_id) === String(chosen))) chosen = String(trips[0]?.schedule_id || '');
            select.replaceChildren(new Option(trips.length ? 'Choose an active bus' : 'No active Boarding/Departed buses', ''));
            trips.forEach(t => select.add(new Option((t.bus_number || 'Bus') + ' · ' + (t.origin || 'Origin') + ' → ' + (t.destination || t.route_destination || 'Endpoint'), String(t.schedule_id))));
            select.value = chosen; select.disabled = !trips.length;
            requests = Array.isArray(data.requests) ? data.requests : data.waiting ? [data.waiting] : [];
            renderRequest();
            if (!trips.length) message('There are no active Boarding or Departed buses available for waiting-location sharing.');
            else if (!activeRequest()) message('Choose your bus and location. Nothing is shared until you press “Share waiting location”.');
        } catch (e) { message(e.message, 'error'); }
        finally { loading = false; if (visible()) timer = setTimeout(load, 9000); }
    }
    async function mutate(action) {
        if (busy || !chosen) return;
        if (action === 'mark_waiting' && (!point || activeRequest())) { message(point ? 'You already have an active waiting request.' : 'Choose your location before sharing.', 'error'); return; }
        busy = true; el('shareWaitingBtn').disabled = true;
        const params = new URLSearchParams({ csrf_token: csrf, action, schedule_id: chosen });
        if (action === 'mark_waiting') { params.set('latitude', point.latitude); params.set('longitude', point.longitude); const label = el('waitingLabel').value.trim(); if (label) params.set('pickup_label', label.slice(0, 100)); }
        else if (el('cancelWaitingBtn').dataset.requestId) params.set('request_id', el('cancelWaitingBtn').dataset.requestId);
        try { const result = await api('../api/live_tracking.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: params }); message(result.message || (action === 'mark_waiting' ? 'Waiting location shared with the assigned operator.' : 'Waiting request cancelled.'), 'success'); }
        catch (e) { message(e.message, 'error'); }
        finally { busy = false; await load(); }
    }
    select.addEventListener('change', () => { chosen = select.value; requests = []; point = null; el('waitingCoordinateText').textContent = 'Choose your location using GPS, the map, or coordinates.'; el('shareWaitingBtn').disabled = true; clearTimeout(timer); load(); });
    function manualCoordinatesChanged() {
        if (el('waitingLatitude').value !== '' && el('waitingLongitude').value !== '') setPoint(el('waitingLatitude').value, el('waitingLongitude').value, false);
        else { point = null; el('shareWaitingBtn').disabled = true; }
    }
    el('waitingLatitude').addEventListener('input', () => { point = null; el('shareWaitingBtn').disabled = true; });
    el('waitingLongitude').addEventListener('input', () => { point = null; el('shareWaitingBtn').disabled = true; });
    el('waitingLatitude').addEventListener('change', manualCoordinatesChanged);
    el('waitingLongitude').addEventListener('change', manualCoordinatesChanged);
    el('locateWaitingBtn').addEventListener('click', () => {
        if (!navigator.geolocation) { message('GPS is unavailable. Tap the map or enter coordinates.', 'error'); return; }
        el('locateWaitingBtn').disabled = true;
        navigator.geolocation.getCurrentPosition(p => { el('locateWaitingBtn').disabled = false; setPoint(p.coords.latitude, p.coords.longitude, true); }, () => { el('locateWaitingBtn').disabled = false; message('Could not access GPS. Tap the map or enter coordinates.', 'error'); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
    });
    el('shareWaitingBtn').addEventListener('click', () => mutate('mark_waiting'));
    el('cancelWaitingBtn').addEventListener('click', () => mutate('cancel_waiting'));
    document.addEventListener('visibilitychange', () => { if (!visible()) clearTimeout(timer); else load(); });
    load();
})();
