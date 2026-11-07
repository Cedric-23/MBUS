/* MBUS route helpers. Live bus coordinates are deliberately never sent to the routing service. */
(function (global) {
    'use strict';

    const routeCache = new Map();
    const ROUTE_API = '../api/route.php';

    function finite(value) {
        if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) return null;
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
    }

    function validPoint(point) {
        return point && finite(point.lat) !== null && finite(point.lng) !== null &&
            Math.abs(Number(point.lat)) <= 90 && Math.abs(Number(point.lng)) <= 180;
    }

    function endpointKey(point) {
        return Number(point.lat).toFixed(5) + ',' + Number(point.lng).toFixed(5);
    }

    function haversineMeters(a, b) {
        const rad = Math.PI / 180;
        const lat1 = Number(a[0]) * rad;
        const lat2 = Number(b[0]) * rad;
        const dLat = (Number(b[0]) - Number(a[0])) * rad;
        const dLng = (Number(b[1]) - Number(a[1])) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
    }

    async function getDrivingRoute(origin, destination) {
        if (!validPoint(origin) || !validPoint(destination)) throw new Error('Route endpoints are missing valid map coordinates.');
        const key = endpointKey(origin) + '>' + endpointKey(destination);
        if (routeCache.has(key)) return routeCache.get(key);

        const query = new URLSearchParams({
            origin_lat: Number(origin.lat).toFixed(7),
            origin_lng: Number(origin.lng).toFixed(7),
            destination_lat: Number(destination.lat).toFixed(7),
            destination_lng: Number(destination.lng).toFixed(7)
        });
        const promise = fetch(ROUTE_API + '?' + query.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        }).then(async response => {
            let payload;
            try { payload = await response.json(); } catch (_) { throw new Error('The road-routing service returned an unreadable response.'); }
            if (!response.ok || !payload.ok || !payload.route) throw new Error(payload.message || 'Road directions are unavailable right now.');
            const raw = payload.route.coordinates;
            if (!Array.isArray(raw) || raw.length < 2) throw new Error('No road route could be found between these stops.');
            const points = raw.map(pair => [Number(pair[1]), Number(pair[0])]).filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
            if (points.length < 2) throw new Error('The road route did not contain enough map points.');
            const cumulativeMeters = [0];
            for (let i = 1; i < points.length; i++) cumulativeMeters.push(cumulativeMeters[i - 1] + haversineMeters(points[i - 1], points[i]));
            const distanceMeters = finite(payload.route.distance_m);
            const durationSeconds = finite(payload.route.duration_s);
            if (distanceMeters === null || durationSeconds === null || distanceMeters <= 0 || durationSeconds <= 0) throw new Error('The road route estimate is invalid.');
            return { points, cumulativeMeters, distanceMeters, durationSeconds };
        }).catch(error => {
            routeCache.delete(key);
            throw error;
        });

        routeCache.set(key, promise);
        if (routeCache.size > 30) routeCache.delete(routeCache.keys().next().value);
        return promise;
    }

    function remainingOnRoute(route, position, maxOffsetMeters) {
        if (!route || !Array.isArray(route.points) || route.points.length < 2 || !validPoint({ lat: position && position[0], lng: position && position[1] })) return null;
        const latitude = Number(position[0]);
        const longitude = Number(position[1]);
        let bestOffset = Infinity;
        let bestAlong = 0;

        for (let i = 0; i < route.points.length - 1; i++) {
            const a = route.points[i];
            const b = route.points[i + 1];
            const meanLat = ((a[0] + b[0] + latitude) / 3) * Math.PI / 180;
            const scaleX = 111320 * Math.cos(meanLat);
            const scaleY = 110540;
            const ax = a[1] * scaleX, ay = a[0] * scaleY;
            const bx = b[1] * scaleX, by = b[0] * scaleY;
            const px = longitude * scaleX, py = latitude * scaleY;
            const dx = bx - ax, dy = by - ay;
            const lengthSquared = dx * dx + dy * dy;
            const t = lengthSquared > 0 ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / lengthSquared)) : 0;
            const nearestX = ax + t * dx, nearestY = ay + t * dy;
            const offset = Math.hypot(px - nearestX, py - nearestY);
            if (offset < bestOffset) {
                bestOffset = offset;
                const segmentMeters = (route.cumulativeMeters[i + 1] || 0) - (route.cumulativeMeters[i] || 0);
                bestAlong = (route.cumulativeMeters[i] || 0) + segmentMeters * t;
            }
        }

        if (bestOffset > (Number(maxOffsetMeters) || 2500)) return { onRoute: false, offsetMeters: bestOffset };
        const remainingMeters = Math.max(0, route.distanceMeters - bestAlong);
        return {
            onRoute: true,
            offsetMeters: bestOffset,
            distanceMeters: remainingMeters,
            // OSRM's route duration is a road-network estimate; scale it by mapped progress.
            durationSeconds: route.distanceMeters > 0 ? route.durationSeconds * remainingMeters / route.distanceMeters : 0,
            progress: route.distanceMeters > 0 ? Math.max(0, Math.min(1, bestAlong / route.distanceMeters)) : 0
        };
    }

    function formatDistance(meters) {
        const n = Number(meters);
        if (!Number.isFinite(n) || n < 0) return '—';
        if (n < 1000) return Math.round(n) + ' m';
        return (n / 1000).toFixed(1).replace(/\.0$/, '') + ' km';
    }

    function formatDuration(seconds) {
        const n = Number(seconds);
        if (!Number.isFinite(n) || n < 0) return '—';
        const minutes = Math.max(1, Math.round(n / 60));
        if (minutes < 60) return 'about ' + minutes + ' min';
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        return 'about ' + hours + ' hr' + (rest ? ' ' + rest + ' min' : '');
    }

    function displayName(name) {
        const raw = String(name || '').trim();
        const key = raw.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
        if (key.includes('morong') && (key.includes('terminal') || key.includes('bataan'))) return 'Morong Bus Terminal';
        if (key === 'sbma' || key === 'sbma terminal' || key.includes('harbor point') || key.includes('national bookstore') || key.includes('sbma e bus stop')) return 'Harbor Point · National Bookstore stop';
        return raw || 'Stop';
    }

    global.MBUSRouting = { getDrivingRoute, remainingOnRoute, formatDistance, formatDuration, displayName };
})(window);
