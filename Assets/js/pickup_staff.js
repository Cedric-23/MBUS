(() => {
    'use strict';
    const root = document.getElementById('pickupStaff');
    if (!root) return;
    const endpoint = root.dataset.endpoint;
    const notice = document.getElementById('staffNotice');
    const list = document.getElementById('staffList');
    const empty = document.getElementById('staffEmpty');
    const count = document.getElementById('staffCount');
    const filter = document.getElementById('staffSchedule');
    const updated = document.getElementById('staffUpdated');
    const refreshButton = document.getElementById('staffRefresh');
    let requests = [];
    let csrfToken = '';
    let ready = false;
    let refreshing = null;
    let map = null;
    let markerLayer = null;
    let fitted = false;
    let pendingMutation = false;

    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    function showNotice(message, kind = 'error') {
        notice.textContent = message;
        notice.className = 'live-notice live-notice-' + kind;
        notice.dataset.kind = kind;
        notice.hidden = !message;
    }
    function dateLabel(value) {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? 'Unknown time' : date.toLocaleString();
    }
    function validPoint(request) {
        return Number.isFinite(request.latitude) && Number.isFinite(request.longitude)
            && Math.abs(request.latitude) <= 90 && Math.abs(request.longitude) <= 180;
    }
    function initMap() {
        if (!window.L) {
            document.getElementById('staffMapError').hidden = false;
            return;
        }
        try {
            map = L.map('staffMap').setView([14.815, 120.275], 11);
            const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            tiles.on('tileerror', () => { document.getElementById('staffMapError').hidden = false; });
            markerLayer = L.layerGroup().addTo(map);
        } catch (_) {
            map = null;
            document.getElementById('staffMapError').hidden = false;
        }
    }
    async function api(options = {}) {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal, ...options
            });
            let data;
            try { data = await response.json(); }
            catch (_) { throw new Error('The pickup service returned an invalid response. Please try again.'); }
            if (!response.ok || data.ok !== true) {
                throw new Error(data.message || 'Pickup service unavailable. Please try again.');
            }
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('Pickup service timed out. Please try again.');
            throw error;
        } finally {
            window.clearTimeout(timeout);
        }
    }
    function render() {
        list.replaceChildren();
        if (markerLayer) markerLayer.clearLayers();
        const selected = filter.value;
        const visible = requests.filter(request => !selected || String(request.schedule_id) === selected);
        count.textContent = visible.length + ' waiting';
        empty.textContent = ready ? 'No waiting commuters for these trips.' : 'Live pickup is not enabled yet.';
        empty.hidden = visible.length > 0;
        const bounds = [];
        visible.forEach(request => {
            const card = node('article', undefined, 'live-card live-waiting-card');
            card.append(node('h3', request.full_name || 'Commuter'));
            card.append(node('p', 'Bus ' + request.bus_number + ' · ' + request.origin + ' → ' + request.route_destination, 'live-small'));
            const facts = node('dl', undefined, 'pickup-staff-facts');
            const details = [
                ['Pickup', request.pickup_label || 'Pinned pickup point'],
                ['Destination', request.destination],
                ['Updated', dateLabel(request.updated_at)],
                ['Expires', dateLabel(request.expires_at)],
                ['Coordinates', validPoint(request) ? request.latitude.toFixed(5) + ', ' + request.longitude.toFixed(5) : 'Unavailable']
            ];
            details.forEach(([label, value]) => facts.append(node('dt', label), node('dd', value)));
            card.append(facts);
            const actions = node('div', undefined, 'pickup-staff-actions');
            if (map && validPoint(request)) {
                const point = [request.latitude, request.longitude];
                bounds.push(point);
                const popup = node('div');
                popup.append(node('strong', request.full_name || 'Commuter'));
                popup.append(node('p', 'Bus ' + request.bus_number + ' · Destination: ' + request.destination));
                popup.append(node('p', request.pickup_label || 'Pinned pickup point'));
                const marker = L.circleMarker(point, { radius: 9, color: '#fff', weight: 3, fillColor: '#18365d', fillOpacity: 1 })
                    .addTo(markerLayer).bindPopup(popup);
                const locate = node('button', 'Show on map', 'live-button live-button-secondary');
                locate.type = 'button';
                locate.addEventListener('click', () => {
                    document.getElementById('staffMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    map.setView(point, 16);
                    marker.openPopup();
                });
                actions.append(locate);
            }
            const complete = node('button', 'Mark picked up', 'live-button live-button-primary');
            complete.type = 'button';
            complete.disabled = !ready || !csrfToken || pendingMutation;
            complete.addEventListener('click', () => markPickedUp(request, complete));
            actions.append(complete);
            card.append(actions);
            list.append(card);
        });
        if (map && bounds.length && !fitted) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
            fitted = true;
        }
    }
    function updateFilter(schedules) {
        const selected = filter.value;
        filter.replaceChildren(node('option', 'All authorized trips'));
        filter.firstChild.value = '';
        schedules.forEach(schedule => {
            const option = node('option', 'Bus ' + schedule.bus_number + ' · ' + schedule.origin + ' → ' + schedule.destination + ' · ' + dateLabel(schedule.departure_time));
            option.value = String(schedule.schedule_id);
            filter.append(option);
        });
        if (Array.from(filter.options).some(option => option.value === selected)) filter.value = selected;
    }
    function refresh() {
        if (refreshing) return refreshing;
        refreshButton.disabled = true;
        refreshing = (async () => {
            try {
                const data = await api();
                ready = data.ready === true;
                csrfToken = typeof data.csrf_token === 'string' ? data.csrf_token : '';
                requests = Array.isArray(data.requests) ? data.requests : [];
                updateFilter(Array.isArray(data.schedules) ? data.schedules : []);
                render();
                updated.textContent = 'Last checked: ' + dateLabel(data.server_time);
                if (!ready) showNotice(data.message || 'Ask an administrator to deploy supabase/10_live_pickup.sql.');
                else if (notice.dataset.kind === 'error') showNotice('');
            } catch (error) {
                ready = false;
                requests = [];
                render();
                empty.textContent = 'The waiting list could not be loaded. Refresh to try again.';
                updated.textContent = 'Refresh failed — waiting data unavailable';
                showNotice(error.message || 'Could not load waiting commuters.');
            } finally {
                refreshing = null;
                refreshButton.disabled = false;
            }
        })();
        return refreshing;
    }
    async function markPickedUp(request, button) {
        if (pendingMutation || !window.confirm('Confirm that ' + (request.full_name || 'this commuter') + ' has been picked up? This will not change reservations or payments.')) return;
        pendingMutation = true;
        button.disabled = true;
        try {
            const data = await api({ method: 'POST', body: new URLSearchParams({
                csrf_token: csrfToken, action: 'picked_up', request_id: String(request.request_id)
            }) });
            // Let an older GET finish before requesting a fresh post-mutation snapshot.
            if (refreshing) await refreshing;
            requests = requests.filter(item => item.request_id !== request.request_id);
            showNotice(data.message || 'Pickup marked complete.', 'success');
            await refresh();
        } catch (error) {
            showNotice(error.message || 'Pickup could not be completed.');
        } finally {
            pendingMutation = false;
            render();
        }
    }
    filter.addEventListener('change', render);
    refreshButton.addEventListener('click', refresh);
    initMap();
    refresh();
    // GET only; polling never refreshes expiry. Location changes require explicit rider POST.
    const timer = window.setInterval(() => { if (!document.hidden && !pendingMutation) refresh(); }, 15000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !pendingMutation) refresh(); });
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
})();
