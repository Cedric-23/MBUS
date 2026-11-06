/* Commuter Waiting: one tap to share a spot, then a clear status card. */
(function () {
    'use strict';

    var el = function (id) { return document.getElementById(id); };
    var endpoint = '../api/live_tracking.php';
    var csrf = (window.mbusWaitingConfig || {}).csrfToken || '';

    var state = {
        trips: [],
        waiting: null,
        locations: [],
        selected: String((window.mbusWaitingConfig || {}).scheduleId || ''),
        point: null,
        busy: false,
        loading: false,
        timer: null,
        map: null,
        marker: null
    };

    var visible = function () { return !document.hidden; };

    function flash(text, kind) {
        var node = el('waitFlash');
        if (!text) { node.hidden = true; node.textContent = ''; node.className = 'wait-flash'; return; }
        node.textContent = text;
        node.className = 'wait-flash' + (kind ? ' is-' + kind : '');
        node.hidden = false;
    }

    function number(value) {
        if (value === '' || value === null || value === undefined) return null;
        var n = Number(value);
        return Number.isFinite(n) ? n : null;
    }

    function relative(value, suffix) {
        var time = Date.parse(value);
        if (!Number.isFinite(time)) return '—';
        var diff = Math.round((Date.now() - time) / 1000);
        var future = diff < 0;
        var secs = Math.abs(diff);
        var text = secs < 45 ? 'just now' : secs < 3600 ? Math.round(secs / 60) + ' min' : Math.round(secs / 3600) + ' h';
        if (future) return 'in ' + (secs < 60 ? Math.max(1, Math.round(secs)) + ' s' : text);
        if (text === 'just now') return text;
        return text + (suffix ? ' ' + suffix : ' ago');
    }

    function distance(from, to) {
        if (!from || !to) return null;
        var rad = Math.PI / 180;
        var dLat = (to[0] - from[0]) * rad;
        var dLng = (to[1] - from[1]) * rad;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(from[0] * rad) * Math.cos(to[0] * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function tripFor(scheduleId) {
        return state.trips.find(function (t) { return String(t.schedule_id) === String(scheduleId); }) || null;
    }

    function busFor(scheduleId) {
        return state.locations.find(function (l) { return String(l.schedule_id) === String(scheduleId); }) || null;
    }

    function tripLabel(trip) {
        if (!trip) return '—';
        return (trip.bus_number || 'Bus') + ' · ' + (trip.origin || 'Origin') + ' to ' + (trip.destination || 'Destination');
    }

    async function api(url, options) {
        var response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }, options || {}));
        var data;
        try { data = await response.json(); }
        catch (e) { throw new Error('The service returned an unreadable response.'); }
        if (!response.ok || data.ok !== true) throw new Error(data.message || 'Something went wrong. Try again.');
        return data;
    }

    function activeTrips(data) {
        var list = Array.isArray(data.schedules) ? data.schedules : [];
        return list.filter(function (t) { return t && t.schedule_id != null; });
    }

    function renderTrips() {
        var box = el('waitTrips');
        box.replaceChildren();
        state.trips.forEach(function (trip) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'wait-trip' + (String(trip.schedule_id) === String(state.selected) ? ' is-on' : '');
            button.setAttribute('role', 'radio');
            button.setAttribute('aria-checked', String(String(trip.schedule_id) === String(state.selected)));

            var bus = document.createElement('span');
            bus.className = 'wait-trip-bus';
            bus.textContent = trip.bus_number || 'Bus';

            var body = document.createElement('span');
            body.className = 'wait-trip-body';
            var route = document.createElement('span');
            route.className = 'wait-trip-route';
            route.textContent = (trip.origin || 'Origin') + ' → ' + (trip.destination || 'Destination');
            var meta = document.createElement('span');
            meta.className = 'wait-trip-state';
            meta.textContent = trip.trip_status === 'Boarding' ? 'Boarding now' : 'On the way';
            body.append(route, meta);

            var check = document.createElement('span');
            check.className = 'wait-trip-check';
            check.innerHTML = '<i class="fa fa-check" aria-hidden="true"></i>';

            button.append(bus, body, check);
            button.addEventListener('click', function () {
                state.selected = String(trip.schedule_id);
                renderTrips();
                updatePinControls();
                updateShareButton();
            });
            box.append(button);
        });
    }

    function updatePinControls() {
        var pin = el('waitPin');
        pin.disabled = !state.selected || state.busy;
        if (!state.selected) el('waitPinStatus').textContent = 'Choose a bus first';
        else if (state.point) el('waitPinStatus').textContent = 'Pickup spot pinned';
        else el('waitPinStatus').textContent = 'Pin your spot on the map or use your location';
    }

    function updateShareButton() {
        var button = el('waitShare');
        var hasTrip = !!state.selected && !!tripFor(state.selected);
        button.disabled = state.busy || !hasTrip || !state.point;
        el('waitShareLabel').textContent = state.busy ? 'Saving…' : 'Mark me as waiting';
        updatePinControls();
    }

    function show(view) {
        el('waitSkeleton').hidden = true;
        el('waitState').hidden = view !== 'state';
        el('waitPicker').hidden = view !== 'picker';
        el('waitEmpty').hidden = view !== 'empty';
        if (view === 'picker' && state.map) {
            window.requestAnimationFrame(function () { state.map.invalidateSize({ pan: false }); });
        }
    }

    function renderState() {
        var waiting = state.waiting;
        var trip = tripFor(waiting.schedule_id);
        el('waitStateChip').textContent = (waiting.status || 'Waiting');
        el('waitStateChip').className = 'wait-chip' + (waiting.status === 'Expired' ? ' is-expired' : '');
        el('waitStateLabel').textContent = waiting.status === 'Expired' ? 'Waiting expired' : "You're waiting";
        el('waitStateTitle').textContent = trip ? tripLabel(trip) : 'Waiting for your bus';
        el('waitStop').textContent = (waiting.pickup_label || 'Pinned spot') +
            (Number.isFinite(waiting.latitude) ? ' · ' + waiting.latitude.toFixed(4) + ', ' + waiting.longitude.toFixed(4) : '');
        el('waitShared').textContent = relative(waiting.updated_at);
        el('waitExpires').textContent = waiting.status === 'Expired' ? 'Expired' : relative(waiting.expires_at).replace(' ago', '');

        var bus = busFor(waiting.schedule_id);
        if (bus) {
            var km = distance([waiting.latitude, waiting.longitude], [bus.latitude, bus.longitude]);
            el('waitBusGps').textContent = (km !== null && km < 50 ? (km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km') : 'Live') + ' · ' + relative(bus.updated_at);
        } else {
            el('waitBusGps').textContent = 'GPS off';
        }
    }

    function render() {
        renderTrips();
        updateShareButton();
        if (state.waiting && state.waiting.status === 'Waiting') {
            renderState();
            show('state');
        } else if (state.waiting && state.waiting.status === 'Expired') {
            renderState();
            show('state');
        } else if (!state.trips.length) {
            show('empty');
        } else {
            show('picker');
        }
    }

    async function load(silent) {
        if (state.loading || !visible()) return;
        state.loading = true;
        try {
            var query = state.waiting && state.waiting.schedule_id ? '?schedule_id=' + encodeURIComponent(state.waiting.schedule_id) : '';
            var data = await api(endpoint + query);
            state.trips = activeTrips(data);
            state.locations = Array.isArray(data.locations) ? data.locations : [];
            state.waiting = data.my_request || null;
            var expired = !!(state.waiting && state.waiting.status === 'Expired');
            if (expired) {
                state.selected = String(state.waiting.schedule_id || state.selected || '');
                state.waiting = null;
            }
            if (data.csrf_token) csrf = data.csrf_token;
            if (state.selected && !tripFor(state.selected)) state.selected = '';
            render();
            if (expired) flash('Your previous wait expired. Pin your spot to start again.');
            else if (!silent) flash('');
        } catch (error) {
            el('waitSkeleton').hidden = true;
            if (!state.trips.length) { show('empty'); }
            flash(error.message, 'error');
        } finally {
            state.loading = false;
            if (visible()) {
                clearTimeout(state.timer);
                state.timer = setTimeout(function () { load(true); }, 15000);
            }
        }
    }

    function currentPosition() {
        return new Promise(function (resolve, reject) {
            if (!window.isSecureContext) { reject(new Error('Location needs HTTPS.')); return; }
            if (!navigator.geolocation) { reject(new Error('This browser cannot share location.')); return; }
            navigator.geolocation.getCurrentPosition(function (position) {
                resolve([position.coords.latitude, position.coords.longitude]);
            }, function (error) {
                reject(new Error(error && error.code === 1 ? 'Location is blocked. Allow it in your browser.' : 'Could not get your location. Try the map instead.'));
            }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 15000 });
        });
    }

    function setPoint(lat, lng, center) {
        lat = number(lat); lng = number(lng);
        if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            flash('Enter a valid latitude and longitude.', 'error');
            return false;
        }
        state.point = [lat, lng];
        el('waitLat').value = String(lat.toFixed(6));
        el('waitLng').value = String(lng.toFixed(6));
        if (state.map) {
            if (!state.marker) {
                state.marker = L.marker([lat, lng], { draggable: true, title: 'Your waiting spot' }).addTo(state.map);
                state.marker.on('dragend', function () {
                    var p = state.marker.getLatLng();
                    state.point = [p.lat, p.lng];
                    el('waitLat').value = p.lat.toFixed(6);
                    el('waitLng').value = p.lng.toFixed(6);
                    el('waitPinStatus').textContent = 'Pickup spot pinned';
                    updateShareButton();
                });
            } else {
                state.marker.setLatLng([lat, lng]);
            }
            if (center) state.map.setView([lat, lng], 17, { animate: true });
        }
        el('waitPinStatus').textContent = 'Pickup spot pinned';
        updateShareButton();
        return true;
    }

    function initMap() {
        var node = el('waitMap');
        if (!node || typeof L === 'undefined') return;
        state.map = L.map(node, { zoomControl: false, attributionControl: true }).setView([14.678, 120.266], 15);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap'
        }).addTo(state.map);
        state.map.on('click', function (event) {
            if (!state.selected) { flash('Choose a bus first.', 'error'); return; }
            setPoint(event.latlng.lat, event.latlng.lng, false);
        });
    }

    async function pinCurrentLocation() {
        if (!state.selected || state.busy) return;
        var button = el('waitPin');
        button.disabled = true;
        el('waitPinStatus').textContent = 'Finding your location…';
        flash('');
        try {
            var point = await currentPosition();
            setPoint(point[0], point[1], true);
        } catch (error) {
            el('waitPinStatus').textContent = 'Could not get location';
            flash(error.message, 'error');
        } finally {
            updatePinControls();
        }
    }

    async function share() {
        if (state.busy || !state.selected) return;
        state.busy = true;
        updateShareButton();
        flash('');
        try {
            var point = state.point;
            if (!point) throw new Error('Pin your pickup spot first.');
            var body = new URLSearchParams({
                csrf_token: csrf,
                action: 'mark_waiting',
                schedule_id: state.selected,
                latitude: String(point[0]),
                longitude: String(point[1])
            });
            var label = el('waitLabel').value.trim();
            if (label) body.set('pickup_label', label.slice(0, 100));
            var result = await api(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body
            });
            state.point = null;
            state.selected = '';
            flash(result.message || 'Waiting shared.', 'success');
            await load(true);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            if (error && error.message) flash(error.message, 'error');
        } finally {
            state.busy = false;
            updateShareButton();
        }
    }

    async function cancel() {
        if (state.busy || !state.waiting) return;
        state.busy = true;
        try {
            var body = new URLSearchParams({
                csrf_token: csrf,
                action: 'cancel_waiting',
                schedule_id: String(state.waiting.schedule_id)
            });
            var result = await api(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body
            });
            state.waiting = null;
            state.selected = '';
            flash(result.message || 'Waiting cancelled.', 'success');
            await load(true);
        } catch (error) {
            flash(error.message, 'error');
        } finally {
            state.busy = false;
        }
    }


    el('waitShare').addEventListener('click', share);
    el('waitCancel').addEventListener('click', cancel);
    el('waitPin').addEventListener('click', pinCurrentLocation);
    el('waitLat').addEventListener('change', function () { setPoint(el('waitLat').value, el('waitLng').value, false); });
    el('waitLng').addEventListener('change', function () { setPoint(el('waitLat').value, el('waitLng').value, false); });
    document.addEventListener('visibilitychange', function () {
        if (!visible()) { clearTimeout(state.timer); }
        else { load(true); }
    });
    window.addEventListener('pagehide', function () { clearTimeout(state.timer); });

    initMap();
    load(false);
})();
