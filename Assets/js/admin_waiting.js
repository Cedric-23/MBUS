/* Staff Waiting board: waiting riders + live bus positions from the operator app. */
(() => {
    'use strict';

    const root = document.getElementById('pickupStaff');
    if (!root) return;

    const pickupEndpoint = root.dataset.endpoint;
    const liveEndpoint = root.dataset.liveEndpoint || '../api/live_tracking.php';
    const notice = document.getElementById('staffNotice');
    const list = document.getElementById('staffList');
    const empty = document.getElementById('staffEmpty');
    const count = document.getElementById('staffCount');
    const waitingCount = document.getElementById('waitingCount');
    const busCount = document.getElementById('busCount');
    const filter = document.getElementById('staffSchedule');
    const updated = document.getElementById('staffUpdated');
    const refreshButton = document.getElementById('staffRefresh');

    let requests = [];
    let schedules = [];
    let locations = [];
    let csrfToken = '';
    let ready = false;
    let refreshing = null;
    let pending = false;
    let map = null;
    let markerLayer = null;
    let fitted = false;
    const operatorView = root.dataset.role === 'operator';

    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };

    function showNotice(message, kind) {
        notice.textContent = message || '';
        notice.className = 'board-notice' + (kind === 'success' ? ' is-success' : '');
        notice.hidden = !message;
    }

    function relative(value) {
        const time = Date.parse(value);
        if (!Number.isFinite(time)) return '—';
        const secs = Math.max(0, Math.round((Date.now() - time) / 1000));
        if (secs < 45) return 'just now';
        if (secs < 3600) return Math.round(secs / 60) + ' min ago';
        return Math.round(secs / 3600) + ' h ago';
    }

    function clock(value) {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? 'unknown time' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function valid(lat, lng) {
        return Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))
            && Math.abs(Number(lat)) <= 90 && Math.abs(Number(lng)) <= 180;
    }

    function initMap() {
        if (!window.L) {
            document.getElementById('staffMapError').hidden = false;
            return;
        }
        try {
            map = L.map('staffMap', { zoomControl: true }).setView([14.678, 120.266], 15);
            const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);
            tiles.on('tileerror', () => { document.getElementById('staffMapError').hidden = false; });
            markerLayer = L.layerGroup().addTo(map);
        } catch (error) {
            map = null;
            document.getElementById('staffMapError').hidden = false;
        }
    }

    async function get(url) {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            let data;
            try { data = await response.json(); }
            catch (error) { throw new Error('The waiting service returned an invalid response.'); }
            if (!response.ok || data.ok !== true) throw new Error(data.message || 'Waiting service unavailable.');
            return data;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    function busIcon(busNumber) {
        return L.divIcon({
            className: 'board-bus-marker',
            html: '<span class="board-bus-pin"><i class="fa fa-bus" aria-hidden="true"></i>' + (busNumber || 'Bus') + '</span>',
            iconSize: [74, 28],
            iconAnchor: [37, 14]
        });
    }

    function render() {
        const selected = filter.value;
        const visibleRequests = requests.filter(r => !selected || String(r.schedule_id) === selected);
        const visibleBuses = locations.filter(l => !selected || String(l.schedule_id) === selected);

        count.textContent = String(visibleRequests.length);
        waitingCount.textContent = String(requests.length);
        busCount.textContent = String(locations.filter(l => Date.now() - Date.parse(l.updated_at) < 5 * 60 * 1000).length);
        empty.hidden = visibleRequests.length > 0 || !ready;
        empty.textContent = ready ? 'No one is waiting right now.' : 'Waiting list is not available yet.';

        list.replaceChildren();
        if (markerLayer) markerLayer.clearLayers();
        const bounds = [];

        visibleBuses.forEach(bus => {
            if (!map || !valid(bus.latitude, bus.longitude)) return;
            const point = [bus.latitude, bus.longitude];
            bounds.push(point);
            L.marker(point, { icon: busIcon(bus.bus_number), title: 'Bus ' + bus.bus_number })
                .addTo(markerLayer)
                .bindPopup('<strong>Bus ' + (bus.bus_number || '') + '</strong><br>Live · ' + relative(bus.updated_at));
        });

        visibleRequests.forEach(request => {
            const card = node('article', undefined, 'board-rider');
            const top = node('div', undefined, 'board-rider-top');
            top.append(node('h3', request.full_name || 'Commuter', 'board-rider-name'),
                node('span', relative(request.updated_at), 'board-rider-time'));

            const meta = node('p', undefined, 'board-rider-meta');
            const bus = node('span');
            bus.innerHTML = '<i class="fa fa-bus" aria-hidden="true"></i>';
            bus.append(document.createTextNode(' ' + (request.bus_number || 'Bus')));
            const stop = node('span');
            stop.innerHTML = '<i class="fa fa-location-dot" aria-hidden="true"></i>';
            stop.append(document.createTextNode(' ' + (request.pickup_label || 'Pinned spot')));
            const dest = node('span');
            dest.innerHTML = '<i class="fa fa-arrow-right" aria-hidden="true"></i>';
            dest.append(document.createTextNode(' ' + (request.destination || request.route_destination || '—')));
            meta.append(bus, stop, dest);

            const actions = node('div', undefined, 'board-rider-actions');
            if (map && valid(request.latitude, request.longitude)) {
                const point = [request.latitude, request.longitude];
                bounds.push(point);
                const marker = L.circleMarker(point, { radius: 8, color: '#fff', weight: 3, fillColor: '#d1493f', fillOpacity: 1 })
                    .addTo(markerLayer)
                    .bindPopup('<strong>' + (request.full_name || 'Commuter') + '</strong><br>' + (request.pickup_label || 'Waiting spot') + '<br>' + relative(request.updated_at));
                const show = node('button', 'Show', 'board-btn board-btn-quiet');
                show.type = 'button';
                show.addEventListener('click', () => {
                    document.getElementById('staffMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    map.setView(point, 16);
                    marker.openPopup();
                });
                actions.append(show);
            }
            const done = node('button', 'Picked up', 'board-btn board-btn-primary');
            done.type = 'button';
            done.disabled = !ready || !csrfToken || pending;
            done.addEventListener('click', () => markPickedUp(request, done));
            actions.append(done);

            card.append(top, meta, actions);
            list.append(card);
        });

        const selectedBus = visibleBuses.find(bus => valid(bus.latitude, bus.longitude));
        if (map && selectedBus && (operatorView || selected)) {
            // Keep a selected/current bus identifiable instead of fitting the route and rider across a town.
            map.setView([Number(selectedBus.latitude), Number(selectedBus.longitude)], 16, { animate: false });
            fitted = true;
        } else if (map && bounds.length && !fitted) {
            map.fitBounds(bounds, { padding: [28, 28], maxZoom: 14 });
            fitted = true;
        }
        if (map) window.requestAnimationFrame(() => map.invalidateSize({ pan: false }));
    }

    function updateFilter() {
        let selected = filter.value;
        filter.replaceChildren(new Option('All buses', ''));
        const seen = new Set();
        schedules.forEach(schedule => {
            const id = String(schedule.schedule_id);
            if (seen.has(id)) return;
            seen.add(id);
            const option = new Option('Bus ' + schedule.bus_number + ' · ' + clock(schedule.departure_time), id);
            filter.append(option);
        });
        locations.forEach(bus => {
            const id = String(bus.schedule_id);
            if (seen.has(id)) return;
            seen.add(id);
            filter.append(new Option('Bus ' + bus.bus_number + ' · live', id));
        });
        if (operatorView && !selected && locations.length) selected = String(locations[0].schedule_id);
        if (Array.from(filter.options).some(option => option.value === selected)) filter.value = selected;
    }

    function refresh() {
        if (refreshing) return refreshing;
        refreshButton.disabled = true;
        refreshing = (async () => {
            try {
                const results = await Promise.allSettled([get(pickupEndpoint), get(liveEndpoint)]);
                const pickup = results[0].status === 'fulfilled' ? results[0].value : null;
                const live = results[1].status === 'fulfilled' ? results[1].value : null;

                if (pickup) {
                    ready = pickup.ready === true;
                    csrfToken = typeof pickup.csrf_token === 'string' ? pickup.csrf_token : '';
                    requests = Array.isArray(pickup.requests) ? pickup.requests : [];
                    schedules = Array.isArray(pickup.schedules) ? pickup.schedules : [];
                    if (!ready) showNotice(pickup.message || 'Waiting is not configured yet.');
                } else {
                    ready = false;
                    requests = [];
                    showNotice(results[0].reason && results[0].reason.message ? results[0].reason.message : 'Could not load waiting riders.');
                }
                locations = live && Array.isArray(live.locations) ? live.locations : (live && live.location ? [Object.assign({ schedule_id: (live.schedule || {}).schedule_id }, live.location)] : []);

                updateFilter();
                render();
                updated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' · refreshes every 12 s';
            } catch (error) {
                showNotice(error.message || 'Could not refresh.');
            } finally {
                refreshing = null;
                refreshButton.disabled = false;
            }
        })();
        return refreshing;
    }

    async function markPickedUp(request, button) {
        if (pending) return;
        pending = true;
        button.disabled = true;
        try {
            const response = await fetch(pickupEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'picked_up',
                    request_id: String(request.request_id)
                })
            });
            const data = await response.json();
            if (!response.ok || data.ok !== true) throw new Error(data.message || 'Could not mark as picked up.');
            requests = requests.filter(item => item.request_id !== request.request_id);
            render();
            showNotice((request.full_name || 'Rider') + ' marked as picked up.', 'success');
            await refresh();
        } catch (error) {
            showNotice(error.message || 'Could not mark as picked up.');
        } finally {
            pending = false;
            button.disabled = false;
        }
    }

    filter.addEventListener('change', render);
    refreshButton.addEventListener('click', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    initMap();
    refresh();
    const timer = window.setInterval(() => { if (!document.hidden && !pending) refresh(); }, 12000);
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
})();
