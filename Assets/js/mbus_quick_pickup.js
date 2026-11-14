/* One-tap "Pick up": tells the system the rider is waiting. The server picks the trip
   (reserved trip first), so the rider never chooses a bus. Operators confirm on their side. */
window.MbusPickup = (() => {
    'use strict';
    const endpoint = '../api/pickup.php';
    let csrf = '';

    async function load() {
        const res = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        const d = await res.json().catch(() => ({}));
        if (d.csrf_token) csrf = d.csrf_token;
        if (!res.ok || d.ok !== true) throw new Error(d.message || 'Pickup is not available right now.');
        const req = Array.isArray(d.requests) && d.requests.length ? d.requests[0] : null;
        return {
            request: req && req.status === 'Waiting' ? req : null,
            last: req,
            trip: d.my_trip || null,
            schedules: Array.isArray(d.schedules) ? d.schedules : []
        };
    }
    function locate() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) return reject(new Error('Location is not supported on this device.'));
            navigator.geolocation.getCurrentPosition(p => resolve(p.coords), e => reject(new Error(
                e.code === 1 ? 'Allow location access so the operator can find you.' : 'Could not get your location. Try again in an open area.'
            )), { enableHighAccuracy: true, timeout: 12000, maximumAge: 15000 });
        });
    }
    async function post(fields) {
        const res = await fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
            body: new URLSearchParams(Object.assign({ csrf_token: csrf }, fields))
        });
        const d = await res.json().catch(() => ({}));
        if (d.csrf_token) csrf = d.csrf_token;
        if (!res.ok || d.ok !== true) throw new Error(d.message || 'Something went wrong. Please try again.');
        return d;
    }
    async function notify() {
        if (!csrf) await load();
        const c = await locate();
        return post({ action: 'quick', latitude: c.latitude, longitude: c.longitude, pickup_label: 'My location' });
    }
    async function cancel(requestId) {
        if (!csrf) await load();
        return post({ action: 'cancel', request_id: requestId });
    }
    return { load, notify, cancel };
})();
