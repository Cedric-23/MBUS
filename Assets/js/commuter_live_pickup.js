/* Live tab: single Pick up button. No bus selection. */
(() => {
    'use strict';
    const btn = document.getElementById('lvPickBtn');
    if (!btn) return;
    const title = document.getElementById('lvPickTitle'), sub = document.getElementById('lvPickSub');
    let request = null, trip = null, busy = false;

    function paint(msg) {
        if (request) {
            title.textContent = 'You’re marked as waiting';
            sub.textContent = msg || 'Your operator can see where you are. Stay at your spot.';
            btn.textContent = 'Cancel'; btn.className = 'lv-pick-btn is-cancel';
        } else {
            title.textContent = 'Waiting for your bus?';
            sub.textContent = msg || (trip ? 'Tap Pick up and the operator of ' + (/^bus/i.test(trip.bus_number) ? trip.bus_number : 'Bus ' + trip.bus_number) + ' will see you.' : 'Tap Pick up and the nearest bus will see you.');
            btn.textContent = 'Pick up'; btn.className = 'lv-pick-btn';
        }
        btn.disabled = busy;
    }
    async function refresh(msg) {
        try { const s = await MbusPickup.load(); request = s.request; trip = s.trip; paint(msg); }
        catch (e) { paint(e.message); }
    }
    btn.addEventListener('click', async () => {
        if (busy) return;
        busy = true; btn.disabled = true; const label = btn.textContent; btn.textContent = request ? 'Cancelling…' : 'Locating…';
        try {
            if (request) { await MbusPickup.cancel(request.request_id); await refresh('Cancelled. Tap Pick up when you’re ready again.'); }
            else { const d = await MbusPickup.notify(); await refresh(d.message); }
        } catch (e) { btn.textContent = label; paint(e.message); }
        busy = false; btn.disabled = false;
    });
    refresh();
    setInterval(() => { if (!document.hidden && !busy) refresh(); }, 10000);
})();
