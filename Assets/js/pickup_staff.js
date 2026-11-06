/* Operator location sharing: starts by itself once location is allowed. */
(() => {
    'use strict';

    const endpoint = window.OPERATOR_LIVE_ENDPOINT || '../api/live_tracking.php';
    const token = window.OPERATOR_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const status = document.getElementById('operatorTrackingStatus');

    if (status && document.getElementById('enableOperatorGps')) dashboardTracking();
    else if (status) trackBus();

    function errorText(error) {
        if (!window.isSecureContext) return 'Location needs HTTPS.';
        if (error && error.code === 1) return 'Location is blocked. Allow it in your browser.';
        if (error && error.code === 2) return 'No location signal yet.';
        if (error && error.code === 3) return 'Location timed out. Retrying…';
        return 'Location unavailable.';
    }

    function dashboardTracking() {
        const button = document.getElementById('enableOperatorGps');
        const select = document.getElementById('operatorTrackingTrip');
        const label = document.getElementById('operatorTrackingTripLabel');
        const badge = document.getElementById('operatorTrackingBadge');
        const activeStatuses = ['Boarding', 'Departed'];

        let enabled = false;
        let watch = null;
        let latest = null;
        let active = [];
        let selected = '';
        let lastSent = 0;
        let pollTimer = null;
        let busy = false;
        let uploadBusy = false;
        let gpsMap = null;
        let gpsMarker = null;
        let gpsAccuracy = null;

        const set = (text, tone) => {
            if (status) status.textContent = text;
            if (badge) {
                badge.textContent = tone === 'live' ? 'Live' : tone === 'waiting' ? 'Waiting' : tone === 'error' ? 'Check' : 'Off';
                badge.className = 'gps-badge' + (tone ? ' is-' + tone : '');
            }
        };

        const stopWatch = () => {
            if (watch !== null && navigator.geolocation) navigator.geolocation.clearWatch(watch);
            watch = null;
        };

        const schedulesFrom = data => Array.isArray(data.schedules) ? data.schedules : Array.isArray(data.operator_schedules) ? data.operator_schedules : Array.isArray(data.trips) ? data.trips : [];

        function renderTrips() {
            const previous = selected;
            select.replaceChildren();
            active.forEach(trip => {
                const option = document.createElement('option');
                option.value = String(trip.schedule_id);
                option.textContent = (trip.bus_number || 'Trip') + ' · ' + (trip.origin || '') + ' → ' + (trip.destination || '');
                select.append(option);
            });
            label.hidden = active.length < 2;
            select.hidden = active.length < 2;
            if (active.some(trip => String(trip.schedule_id) === previous)) select.value = previous;
            else {
                const preferred = active.find(trip => trip.trip_status === 'Departed') || active.find(trip => trip.trip_status === 'Boarding') || active[0];
                selected = preferred ? String(preferred.schedule_id) : '';
                select.value = selected;
                lastSent = 0;
            }
        }

        const chosen = () => active.find(trip => String(trip.schedule_id) === String(select.value || selected)) || null;

        async function upload() {
            if (!enabled || !latest || document.hidden || uploadBusy) return;
            const trip = chosen();
            if (!trip) { set('Waiting for a trip to start boarding.', 'waiting'); return; }
            if (Date.now() - lastSent < 8500) return;
            uploadBusy = true;
            try {
                const coords = latest.coords;
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: new URLSearchParams({
                        csrf_token: token,
                        action: 'update_location',
                        schedule_id: String(trip.schedule_id),
                        latitude: String(coords.latitude),
                        longitude: String(coords.longitude),
                        accuracy: String(coords.accuracy)
                    })
                });
                const data = await response.json();
                if (!response.ok || data.ok !== true) throw new Error(data.message || 'Location rejected.');
                lastSent = Date.now();
                set('Sharing · ' + (trip.bus_number || 'bus') + ' · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), 'live');
            } catch (error) {
                set('Not sharing: ' + (error.message || 'connection problem'), 'error');
            } finally {
                uploadBusy = false;
            }
        }

        async function poll() {
            if (!enabled || document.hidden || busy) return;
            busy = true;
            try {
                const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
                const data = await response.json();
                if (!response.ok || data.ok !== true) throw new Error(data.message || 'Unable to check trips.');
                const next = schedulesFrom(data)
                    .filter(trip => activeStatuses.includes(trip.trip_status || trip.status) && trip.schedule_id != null)
                    .map(trip => ({ ...trip, trip_status: trip.trip_status || trip.status }));
                const signature = next.map(trip => trip.schedule_id + ':' + trip.trip_status).sort().join('|');
                const old = active.map(trip => trip.schedule_id + ':' + trip.trip_status).sort().join('|');
                if (signature !== old) { active = next; renderTrips(); lastSent = 0; }
                if (!chosen()) { set('Waiting for a trip to start boarding.', 'waiting'); return; }
                if (!latest) { set('Getting your location…', 'waiting'); return; }
                await upload();
            } catch (error) {
                set('Could not check trips: ' + (error.message || 'network error'), 'error');
            } finally {
                busy = false;
            }
        }

        if (window.L && document.getElementById('operatorGpsMap')) {
            try {
                gpsMap = L.map('operatorGpsMap', { zoomControl: false }).setView([14.676, 120.27], 11);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap'
                }).addTo(gpsMap);
            } catch (error) {
                gpsMap = null;
            }
        }

        function showFix(position) {
            if (!gpsMap) return;
            const loc = [position.coords.latitude, position.coords.longitude];
            if (!gpsMarker) gpsMarker = L.marker(loc, { title: 'Your bus' }).addTo(gpsMap).bindPopup('Your bus');
            else gpsMarker.setLatLng(loc);
            if (gpsAccuracy) gpsMap.removeLayer(gpsAccuracy);
            const accuracy = Number(position.coords.accuracy);
            if (Number.isFinite(accuracy) && accuracy > 0) {
                gpsAccuracy = L.circle(loc, { radius: accuracy, color: '#1f6fb2', fillOpacity: 0.08, weight: 1 }).addTo(gpsMap);
            }
            gpsMap.setView(loc, Math.max(gpsMap.getZoom(), 15));
        }

        function startWatch() {
            stopWatch();
            if (!enabled || document.hidden) return;
            if (!window.isSecureContext) { set('Location needs HTTPS.', 'error'); return; }
            if (!navigator.geolocation) { set('This browser cannot share location.', 'error'); return; }
            set('Getting your location…', 'waiting');
            watch = navigator.geolocation.watchPosition(position => {
                latest = position;
                showFix(position);
                upload();
            }, error => set(errorText(error), 'error'), { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
            if (button) button.hidden = true;
        }

        function startPolling() {
            clearInterval(pollTimer);
            poll();
            pollTimer = setInterval(poll, 10000);
        }

        function enable() {
            if (enabled) return;
            enabled = true;
            startWatch();
            startPolling();
        }

        button.addEventListener('click', enable);
        select.addEventListener('change', () => { selected = select.value; lastSent = 0; if (latest) upload(); });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopWatch();
                clearInterval(pollTimer);
                pollTimer = null;
                set('Paused while this page is hidden.', 'waiting');
            } else if (enabled) {
                startWatch();
                startPolling();
            }
        });
        window.addEventListener('pagehide', () => { stopWatch(); clearInterval(pollTimer); pollTimer = null; });

        // Location is already allowed (see gps.php), so sharing starts on its own.
        if (window.MBUS_GPS_ENABLED) enable();
        else { set('Location is off.', 'off'); if (button) button.hidden = false; }
    }

    function trackBus() {
        const active = [...document.querySelectorAll('.trip-row')].filter(row => ['Boarding', 'Departed'].includes(row.dataset.tripStatus));
        if (!active.length) { status.textContent = 'No active trip.'; return; }
        const preferredId = Number(window.OPERATOR_TRACK_SCHEDULE_ID);
        const preferred = Number.isSafeInteger(preferredId) && preferredId > 0 ? active.find(row => Number(row.dataset.scheduleId) === preferredId) : null;
        const row = preferred || active.find(row => row.dataset.tripStatus === 'Departed') || active[0];
        const sid = Number(row.dataset.scheduleId);

        let watch = null;
        let lastSent = 0;
        let busy = false;

        const stop = () => { if (watch !== null) navigator.geolocation.clearWatch(watch); watch = null; };

        async function send(position) {
            if (busy || document.hidden) return;
            if (Date.now() - lastSent < 8500) return;
            busy = true;
            try {
                const coords = position.coords;
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: new URLSearchParams({
                        csrf_token: token,
                        action: 'update_location',
                        schedule_id: String(sid),
                        latitude: String(coords.latitude),
                        longitude: String(coords.longitude),
                        accuracy: String(coords.accuracy)
                    })
                });
                const data = await response.json();
                if (!response.ok || data.ok !== true) throw new Error(data.message || 'Location rejected.');
                lastSent = Date.now();
                status.textContent = 'Sharing your location · updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                const badge = document.getElementById('operatorTrackingBadge');
                if (badge) { badge.textContent = 'Live'; badge.className = 'gps-badge is-live'; }
            } catch (error) {
                status.textContent = 'Not sharing: ' + (error.message || 'connection problem');
            } finally {
                busy = false;
            }
        }

        function start() {
            stop();
            if (document.hidden) return;
            if (!window.isSecureContext) { status.textContent = 'Location needs HTTPS.'; return; }
            if (!navigator.geolocation) { status.textContent = 'This browser cannot share location.'; return; }
            status.textContent = 'Pinning your location…';
            // First fix is published immediately so departing is visible right away.
            navigator.geolocation.getCurrentPosition(send, error => { status.textContent = errorText(error); }, { enableHighAccuracy: true, maximumAge: 30000, timeout: 15000 });
            watch = navigator.geolocation.watchPosition(send, error => { status.textContent = errorText(error); }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stop();
                status.textContent = 'Sharing paused while this page is hidden.';
            } else start();
        });
        window.addEventListener('pagehide', stop);
        start();
    }

    const departing = document.getElementById('departingBanner');
    if (departing) {
        window.setTimeout(() => { departing.hidden = true; }, 12000);
    }
})();
