/* Operator location sharing that survives page changes.
   Loaded on every operator page. It finds the operator's Boarding/Departed trip by itself,
   keeps the last state in sessionStorage so a new page starts sharing right away (no off/on
   flicker), and shows the same small status pill everywhere. */
(() => {
    'use strict';

    const ENDPOINT = '../api/live_tracking.php';
    const KEY = 'mbus_op_track';
    const SEND_EVERY = 8500;
    const POLL_EVERY = 10000;

    let token = window.OPERATOR_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';
    let watch = null;
    let trips = [];
    let polling = false;
    let sending = false;

    const read = () => { try { return JSON.parse(sessionStorage.getItem(KEY)) || {}; } catch (e) { return {}; } };
    const write = patch => { try { sessionStorage.setItem(KEY, JSON.stringify({ ...read(), ...patch })); } catch (e) { /* storage blocked */ } };
    const clear = () => { try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ } };

    /* ---------- status pill (same on every page) ---------- */
    const style = document.createElement('style');
    style.textContent = `
      .op-loc{position:fixed;z-index:900;right:12px;bottom:calc(var(--bottom-nav-height,60px) + 12px + env(safe-area-inset-bottom));
        display:flex;align-items:center;gap:8px;max-width:calc(100vw - 24px);padding:8px 14px;border-radius:999px;
        background:#fff;color:#172b4d;font:600 .8125rem/1.2 Inter,system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.16);pointer-events:none}
      .op-loc[hidden]{display:none}
      .op-loc i{width:9px;height:9px;border-radius:50%;background:#98a2b3;flex:none}
      .op-loc.is-live i{background:#12b76a;box-shadow:0 0 0 0 rgba(18,183,106,.5);animation:oploc 2s infinite}
      .op-loc.is-wait i{background:#f79009}.op-loc.is-error i{background:#f04438}
      .op-loc span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      @media (min-width:769px){.op-loc{bottom:16px}}
      @media (prefers-reduced-motion:reduce){.op-loc.is-live i{animation:none}}
      @keyframes oploc{70%{box-shadow:0 0 0 8px rgba(18,183,106,0)}100%{box-shadow:0 0 0 0 rgba(18,183,106,0)}}`;
    document.head.appendChild(style);

    const pill = document.createElement('div');
    pill.className = 'op-loc';
    pill.setAttribute('role', 'status');
    pill.setAttribute('aria-live', 'polite');
    pill.innerHTML = '<i aria-hidden="true"></i><span></span>';
    pill.hidden = true;
    document.body.appendChild(pill);

    const legacyText = document.getElementById('operatorTrackingStatus');
    const legacyBadge = document.getElementById('operatorTrackingBadge');

    function show(tone, text, long) {
        pill.hidden = false;
        pill.className = 'op-loc is-' + tone;
        pill.lastChild.textContent = text;
        if (legacyText) legacyText.textContent = long || text;
        if (legacyBadge) {
            legacyBadge.textContent = tone === 'live' ? 'Live' : tone === 'wait' ? 'Waiting' : tone === 'error' ? 'Check' : 'Off';
            legacyBadge.className = 'gps-badge is-' + (tone === 'wait' ? 'waiting' : tone);
        }
    }
    function hide(text) {
        pill.hidden = true;
        if (legacyText) legacyText.textContent = text || 'No active trip.';
        if (legacyBadge) { legacyBadge.textContent = 'Off'; legacyBadge.className = 'gps-badge'; }
    }

    // First paint from the last page's state, so there's no flash of "off".
    const cached = read();
    if (cached.sid && cached.sharing) show('live', 'Location on · ' + (cached.bus || 'bus'), 'Sharing your location.');

    /* ---------- choosing the trip ---------- */
    function pickTrip() {
        const pref = Number(window.OPERATOR_TRACK_SCHEDULE_ID) || Number(read().sid) || 0;
        return trips.find(t => t.schedule_id === pref && t.trip_status === 'Departed')
            || trips.find(t => t.trip_status === 'Departed')
            || trips.find(t => t.schedule_id === pref)
            || trips.find(t => t.trip_status === 'Boarding')
            || trips[0] || null;
    }

    /* ---------- upload ---------- */
    async function send(fix) {
        const trip = pickTrip();
        if (!trip || !fix || sending) return;
        if (Date.now() - (Number(read().lastSent) || 0) < SEND_EVERY) return;
        sending = true;
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({
                    csrf_token: token, action: 'update_location', schedule_id: String(trip.schedule_id),
                    latitude: String(fix.lat), longitude: String(fix.lng), accuracy: String(fix.acc)
                })
            });
            const data = await res.json();
            if (data && data.csrf_token) token = data.csrf_token;
            if (!res.ok || data.ok !== true) throw new Error(data.message || 'Location rejected.');
            write({ lastSent: Date.now(), sharing: true, sid: trip.schedule_id, bus: trip.bus_number });
            show('live', 'Location on · ' + (trip.bus_number || 'bus'),
                'Sharing your location · updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        } catch (e) {
            show('error', 'Location not sent', 'Not sharing: ' + (e.message || 'connection problem'));
        } finally {
            sending = false;
        }
    }

    /* ---------- GPS watch ---------- */
    const toFix = p => ({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.max(0, p.coords.accuracy || 0), ts: Date.now() });
    const geoError = e => {
        if (!window.isSecureContext) return 'Location needs HTTPS.';
        if (e && e.code === 1) return 'Location is blocked in your browser';
        if (e && e.code === 3) return 'Location timed out, retrying';
        return 'No location signal yet';
    };

    function startWatch() {
        if (watch !== null) return;
        if (!navigator.geolocation) { show('error', 'Location not supported'); return; }
        // A recent fix from the previous page goes out immediately.
        const last = read().fix;
        if (last && Date.now() - last.ts < 30000) send(last);
        navigator.geolocation.getCurrentPosition(p => { const f = toFix(p); write({ fix: f }); send(f); }, () => {}, { enableHighAccuracy: true, maximumAge: 60000, timeout: 15000 });
        watch = navigator.geolocation.watchPosition(p => {
            const f = toFix(p); write({ fix: f }); send(f);
        }, e => show('wait', geoError(e), geoError(e)), { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    }
    function stopWatch() {
        if (watch !== null && navigator.geolocation) navigator.geolocation.clearWatch(watch);
        watch = null;
    }

    /* ---------- find the active trip ---------- */
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const res = await fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (data && data.csrf_token) token = data.csrf_token;
            if (!res.ok || data.ok !== true) throw new Error(data.message || 'Unable to check trips.');
            trips = (data.schedules || []).filter(t => ['Boarding', 'Departed'].includes(t.trip_status)).map(t => ({ ...t, schedule_id: Number(t.schedule_id) }));
            const trip = pickTrip();
            if (!trip) {
                stopWatch(); clear(); hide('No active trip.');
                return;
            }
            write({ sid: trip.schedule_id, bus: trip.bus_number, sharing: true });
            if (!read().lastSent || pill.hidden) show('wait', 'Getting location…', 'Getting your location…');
            startWatch();
        } catch (e) {
            // Network hiccup: keep sharing from the last known state instead of flipping to off.
            if (!read().sid) hide('Could not check trips.');
        } finally {
            polling = false;
        }
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden) { poll(); startWatch(); } });
    window.addEventListener('pagehide', stopWatch);   // the next page restarts it at once from the cache

    const banner = document.getElementById('departingBanner');
    if (banner) setTimeout(() => { banner.hidden = true; }, 12000);

    poll();
    setInterval(() => { if (!document.hidden) poll(); }, POLL_EVERY);
})();
