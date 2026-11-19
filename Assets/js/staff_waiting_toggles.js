/* Waiting board: show / hide the pins on the map.
   One button on each group (pick-up, reservation). The choice is remembered on this device. */
(() => {
    'use strict';
    if (!document.getElementById('staffMap')) return;
    const KEY = 'mbusWaitPins';
    const state = { pick: true, res: true };
    try {
        const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (saved && typeof saved === 'object') { state.pick = saved.pick !== false; state.res = saved.res !== false; }
    } catch (e) { /* storage may be blocked */ }

    const style = document.createElement('style');
    style.textContent =
        '.sw-eye{flex:none;display:inline-flex;align-items:center;gap:6px;min-height:30px;padding:0 10px;border:1px solid #d8e1ec;border-radius:999px;background:#fff;color:#18365d;font:inherit;font-size:.75rem;font-weight:700;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
        '.sw-group-reserve .sw-eye{color:#4a32b0;border-color:#d9d0ff}' +
        '.sw-eye[aria-pressed="false"]{background:#eef1f5;border-color:#e1e6ee;color:#6b7a8d}' +
        '.sw-eye:focus-visible{outline:3px solid rgba(47,111,228,.4);outline-offset:2px}' +
        '.sw-hide-pick .leaflet-marker-icon:has(> .sw-person){display:none!important}' +
        '.sw-hide-res .leaflet-marker-icon:has(> .sw-res-pin){display:none!important}';
    document.head.append(style);

    const buttons = {};
    function paint() {
        document.body.classList.toggle('sw-hide-pick', !state.pick);
        document.body.classList.toggle('sw-hide-res', !state.res);
        Object.keys(buttons).forEach(key => {
            const b = buttons[key], on = state[key];
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.querySelector('i').className = 'fa ' + (on ? 'fa-eye' : 'fa-eye-slash');
            b.querySelector('span').textContent = on ? 'Hide' : 'Show';
            b.setAttribute('aria-label', (on ? 'Hide ' : 'Show ') + (key === 'pick' ? 'pick-up' : 'reservation') + ' pins on the map');
        });
    }
    function save() {
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
    }
    function add(selector, key) {
        const head = document.querySelector(selector + ' .sw-group-head');
        if (!head) return;
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'sw-eye';
        b.append(document.createElement('i'), document.createElement('span'));
        b.addEventListener('click', () => { state[key] = !state[key]; save(); paint(); });
        const count = head.querySelector('.sw-group-count');
        head.insertBefore(b, count || null);
        buttons[key] = b;
    }
    add('.sw-group-pickup', 'pick');
    add('.sw-group-reserve', 'res');
    paint();

    /* "Show on map" in a reservation card also turns the reservation pins back on */
    document.addEventListener('click', e => {
        const b = e.target.closest && e.target.closest('.sw-card-pick');
        if (b && b.textContent === 'Show on map' && !state.res) { state.res = true; save(); paint(); }
    }, true);
})();
