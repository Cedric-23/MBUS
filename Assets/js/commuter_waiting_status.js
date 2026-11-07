/* Waiting tab: only your waiting status. Pinning and live tracking happen on the pickup page. */
(function () {
    'use strict';
    const ENDPOINT = '../api/live_tracking.php';
    const WINDOW_MIN = 30;
    const card = document.getElementById('statusCard');
    const flash = document.getElementById('waitFlash');
    let csrf = (window.mbusWaitingConfig || {}).csrfToken || '';
    let timer = null, busy = false;

    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function say(text) { flash.hidden = !text; flash.textContent = text || ''; }
    function ago(ms) { const m = Math.max(0, Math.round(ms / 60000)); return m < 1 ? 'Just now' : m + ' min ago'; }

    function empty() {
        card.className = 'ws-card ws-empty';
        card.innerHTML = '<i class="fa fa-hourglass-half" aria-hidden="true"></i><h2>You are not waiting for a bus</h2>' +
            '<p>Find a bus that has not left yet, pin your spot, and the driver will see you.</p>' +
            '<div class="ws-actions"><a class="ws-btn ws-btn-primary" href="schedule.php">Find a bus</a><a class="ws-btn ws-btn-soft" href="live.php">See live buses</a></div>';
    }

    function waiting(req, trip, serverNow) {
        const sid = Number(req.schedule_id);
        const updated = Date.parse(req.updated_at) || serverNow;
        const left = Math.max(0, Math.round((updated + WINDOW_MIN * 60000 - serverNow) / 60000));
        const route = trip ? (trip.origin_label || trip.origin || 'Morong Terminal') + ' → ' + (trip.destination_label || trip.destination || 'Harbor Point') : 'Trip is no longer active';
        card.className = 'ws-card';
        card.innerHTML = '<span class="ws-live"><i aria-hidden="true"></i>You are waiting</span>' +
            '<p class="ws-bus">' + (trip ? 'Bus ' + esc(trip.bus_number) : 'Your bus') + '</p>' +
            '<p class="ws-route">' + esc(route) + '</p>' +
            '<dl class="ws-meta"><div><dt>Your spot</dt><dd>' + esc(req.pickup_label || 'Pinned spot') + '</dd></div>' +
            '<div><dt>Shared</dt><dd>' + esc(ago(serverNow - updated)) + '</dd></div>' +
            '<div><dt>Stays active for</dt><dd>' + left + ' min</dd></div></dl>' +
            '<div class="ws-actions"><a class="ws-btn ws-btn-primary" href="live.php?schedule_id=' + sid + '">Track the bus</a>' +
            '<button type="button" id="wsCancel" class="ws-btn ws-btn-ghost">Cancel waiting</button></div>';
        document.getElementById('wsCancel').addEventListener('click', () => cancel(sid));
    }

    async function request(url, options) {
        const res = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
        let data;
        try { data = await res.json(); } catch (_) { throw new Error('Unreadable response.'); }
        if (data && data.csrf_token) csrf = data.csrf_token;
        if (!res.ok || !data.ok) throw new Error(data.message || 'Something went wrong.');
        return data;
    }

    async function cancel(sid) {
        if (busy) return;
        busy = true;
        try {
            await request(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ csrf_token: csrf, action: 'cancel_waiting', schedule_id: String(sid) }) });
            say('');
            await load();
        } catch (e) { say(e.message); }
        busy = false;
    }

    async function load() {
        clearTimeout(timer);
        if (!document.hidden) {
            try {
                const data = await request(ENDPOINT);
                say('');
                const req = data.my_request;
                const now = Date.parse(data.server_time) || Date.now();
                if (req && req.status === 'Waiting') {
                    const trip = (data.schedules || []).find(t => Number(t.schedule_id) === Number(req.schedule_id));
                    waiting(req, trip, now);
                } else {
                    empty();
                }
            } catch (e) { say(e.message); if (!card.querySelector('.ws-btn')) empty(); }
        }
        timer = setTimeout(load, 15000);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    load();
})();
