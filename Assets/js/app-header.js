/* Pretty confirmation pop-up instead of the browser's plain confirm() box.
   Every page that already calls confirm(...) (approve a payment, approve an ID, remove a seat, cancel a booking...)
   gets the pop-up automatically: the box is shown, and if the person says yes the same click/submit is repeated
   with the answer "yes". Anything that cannot be repeated safely still uses the normal browser box. */
(function () {
    'use strict';
    var nativeConfirm = window.confirm ? window.confirm.bind(window) : null;
    var approved = false;
    var overlay = null;
    var lastFocus = null;
    var previousOverflow = '';

    var css = document.createElement('style');
    css.textContent =
        '.mbus-confirm-overlay{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:20px;background:rgba(20,33,61,.5);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);animation:mbusCfFade .15s ease}' +
        '.mbus-confirm-card{width:min(360px,100%);padding:24px 20px 20px;border-radius:26px;background:#fff;text-align:center;box-shadow:0 22px 50px rgba(20,33,61,.32);animation:mbusCfPop .18s ease;font-family:inherit}' +
        '.mbus-confirm-ico{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 12px;border-radius:50%;background:#eaf1fd;color:#2f6fe4;font-size:1.5rem;font-weight:800}' +
        '.mbus-confirm-ico.is-danger{background:#fdecea;color:#d92d20}' +
        '.mbus-confirm-card h2{margin:0 0 6px;color:#14213d;font-size:1.12rem;font-weight:700}' +
        '.mbus-confirm-card p{margin:0 0 18px;color:#5b6b7e;font-size:.93rem;line-height:1.5;overflow-wrap:anywhere}' +
        '.mbus-confirm-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}' +
        '.mbus-confirm-actions button{min-height:48px;padding:0 12px;border-radius:999px;font:inherit;font-size:.95rem;font-weight:700;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
        '.mbus-confirm-cancel{border:1.5px solid #cfe0fb;background:#fff;color:#2f6fe4}' +
        '.mbus-confirm-ok{border:0;background:#2f6fe4;color:#fff}' +
        '.mbus-confirm-ok.is-danger{background:#d92d20}' +
        '.mbus-confirm-actions button:focus-visible{outline:3px solid rgba(47,111,228,.45);outline-offset:2px}' +
        '@keyframes mbusCfFade{from{opacity:0}to{opacity:1}}' +
        '@keyframes mbusCfPop{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}' +
        '@media(prefers-reduced-motion:reduce){.mbus-confirm-overlay,.mbus-confirm-card{animation:none}}';
    (document.head || document.documentElement).appendChild(css);

    function copyFor(message) {
        var m = String(message || '');
        if (/arrive|received/i.test(m)) return { title: 'Confirm payment', ok: 'Yes, it arrived', cancel: 'Not yet', danger: false };
        if (/remove/i.test(m)) return { title: 'Remove this seat?', ok: 'Yes, remove', cancel: 'Keep it', danger: true };
        if (/cancel/i.test(m)) return { title: 'Cancel reservation?', ok: 'Yes, cancel', cancel: 'Keep it', danger: true };
        if (/delete/i.test(m)) return { title: 'Delete this?', ok: 'Yes, delete', cancel: 'Keep it', danger: true };
        if (/reject|decline/i.test(m)) return { title: 'Reject this?', ok: 'Yes, reject', cancel: 'Go back', danger: true };
        if (/approve/i.test(m)) return { title: 'Approve this?', ok: 'Yes, approve', cancel: 'Not yet', danger: false };
        return { title: 'Please confirm', ok: 'Confirm', cancel: 'Cancel', danger: false };
    }

    function closeDialog() {
        if (!overlay) return;
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        document.body.style.overflow = previousOverflow;
        document.removeEventListener('keydown', onKey, true);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* element gone */ } }
    }

    function onKey(e) {
        if (!overlay) return;
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); return; }
        if (e.key === 'Tab') {
            var btns = overlay.querySelectorAll('button');
            if (!btns.length) return;
            var first = btns[0], last = btns[btns.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    var pending = null;
    function finish(ok) {
        var done = pending;
        pending = null;
        closeDialog();
        if (done) done(ok);
    }

    function openDialog(message, done) {
        closeDialog();
        pending = done;
        var c = copyFor(message);
        lastFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        overlay = document.createElement('div');
        overlay.className = 'mbus-confirm-overlay';
        overlay.innerHTML =
            '<div class="mbus-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="mbusCfTitle" aria-describedby="mbusCfMsg">' +
            '<div class="mbus-confirm-ico' + (c.danger ? ' is-danger' : '') + '" aria-hidden="true">' + (c.danger ? '!' : '?') + '</div>' +
            '<h2 id="mbusCfTitle"></h2><p id="mbusCfMsg"></p>' +
            '<div class="mbus-confirm-actions"><button type="button" class="mbus-confirm-cancel"></button><button type="button" class="mbus-confirm-ok' + (c.danger ? ' is-danger' : '') + '"></button></div></div>';
        overlay.querySelector('#mbusCfTitle').textContent = c.title;
        overlay.querySelector('#mbusCfMsg').textContent = String(message || '');
        var cancelBtn = overlay.querySelector('.mbus-confirm-cancel');
        var okBtn = overlay.querySelector('.mbus-confirm-ok');
        cancelBtn.textContent = c.cancel;
        okBtn.textContent = c.ok;
        cancelBtn.addEventListener('click', function () { finish(false); });
        okBtn.addEventListener('click', function () { finish(true); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) finish(false); });
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey, true);
        /* the safer choice is focused first when the action removes something */
        (c.danger ? cancelBtn : okBtn).focus();
    }

    /* Repeat the click / form submit that asked the question, this time with the answer already "yes". */
    function replay(ev, submitter) {
        approved = true;
        try {
            if (ev.type === 'submit') {
                var form = ev.target;
                if (form.requestSubmit) form.requestSubmit(submitter || undefined); else form.submit();
            } else {
                var t = ev.target;
                var el = t && t.closest ? (t.closest('button, a, input, summary, [onclick]') || t) : t;
                el.click();
            }
        } finally {
            approved = false;
        }
    }

    window.confirm = function (message) {
        if (approved) { approved = false; return true; }
        var ev = window.event;
        var canReplay = ev && ev.target && (ev.type === 'click' || ev.type === 'submit');
        if (!canReplay || !document.body) return nativeConfirm ? nativeConfirm(message) : true;
        var submitter = ev.submitter || null;
        openDialog(message, function (ok) { if (ok) replay(ev, submitter); });
        return false;
    };
})();

document.addEventListener('DOMContentLoaded', function () {
    // Use the photo saved on the profile page (stored in the database) in the header avatar.
    document.querySelectorAll('.profile-avatar img').forEach(function (img) {
        const probe = new Image();
        probe.onload = function () { img.src = probe.src; };
        probe.src = '../avatar.php';
    });

    const trigger = document.querySelector('.profile-trigger');
    const menu = document.getElementById('profileMenu');

    if (trigger && menu) {
        function closeMenu() {
            menu.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const open = menu.classList.toggle('open');
            trigger.setAttribute('aria-expanded', String(open));
        });

        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', closeMenu);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
                trigger.focus();
            }
        });
    }

    // Keep both the selected date range and selected weekday visible on narrow screens.
    window.requestAnimationFrame(function () {
        document.querySelectorAll('.date-filter-btn.active, .day-tab.day-active').forEach(function (activeItem) {
            activeItem.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
        });
    });
});

/* Real-time pages: a page with <body data-live="swap|reload"> re-checks itself every few seconds and
   updates when something changed (new reservation, payment approved, ...), so nobody has to switch tabs.
   "swap" replaces the content below the header in place; "reload" reloads the page. It waits while the
   user is typing, has something expanded or a confirmation open. */
(function () {
    var live = { 'commuter_dashboard.php': 'swap', 'my_reservations.php': 'reload', 'admin_dashboard.php': 'swap', 'payments.php': 'swap' };
    var mode = document.body && (document.body.getAttribute('data-live') || live[location.pathname.split('/').pop()]);
    if (!mode) return;
    var head = document.querySelector('main header.home-top, main header.page-header, main .page-header');
    var box = head ? head.parentNode : document.querySelector('main');
    if (!box) return;
    function parts(root) { return Array.prototype.filter.call(root.children, function (c) { return !c.contains(head && root === box ? head : null) && c.tagName !== 'HEADER' && !c.classList.contains('page-header') && !c.classList.contains('home-top'); }); }
    function sig(nodes) {
        var d = document.createElement('div');
        nodes.forEach(function (n) { d.appendChild(n.cloneNode(true)); });
        d.querySelectorAll('.countdown,.timer-value,script,style').forEach(function (e) { e.remove(); });
        return d.innerHTML.replace(/\s+/g, ' ');
    }
    var last = null, busy = false; /* baseline = what the server sent last time (the page itself may be changed by its own scripts) */
    function idle() {
        if (document.hidden) return false;
        var a = document.activeElement;
        if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && box.contains(a)) return false;
        if (document.querySelector('.mbus-confirm-overlay')) return false;
        if (box.querySelector('details[open]')) return false;
        return true;
    }
    function check() {
        if (busy || !idle()) return;
        busy = true;
        fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'live' } })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                if (!html) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var nh = doc.querySelector('main header.home-top, main header.page-header, main .page-header');
                var nbox = nh ? nh.parentNode : doc.querySelector('main');
                if (!nbox) return;
                var next = parts(nbox), s = sig(next);
                if (last === null) { last = s; return; }
                if (s === last || !idle()) return;
                last = s;
                if (mode === 'reload') { location.reload(); return; }
                parts(box).forEach(function (n) { n.remove(); });
                next.forEach(function (n) { box.appendChild(document.importNode(n, true)); });
            })
            .catch(function () {})
            .then(function () { busy = false; });
    }
    check();
    setInterval(check, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
})();

/* Trips page: 3 trips per page (one bundle of seats = one trip, like a single seat) with Previous / Next.
   The page's own filter (Active / History / Cancelled + day pills) decides which trips match by toggling
   "hidden"; this only hides the matches that belong to other pages, using its own class. */
(function () {
    if (location.pathname.split('/').pop() !== 'my_reservations.php') return;
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-cat]'));
    if (!items.length) return;
    var PER = 3, page = 0, lastKey = null;
    var st = document.createElement('style');
    st.textContent = '.tf-off{display:none!important}' +
        /* tighter cards so 3 fit comfortably, same details and design */
        '.rf-card .rf-fold>summary{gap:7px!important;padding:12px 14px 10px!important}' +
        '.rf-card .rf-route{font-size:.92rem!important}' +
        '.rf-card .rf-count{padding:6px 10px!important;border-radius:13px!important;gap:4px 8px!important}' +
        '.rf-card .rf-count>strong{font-size:.85rem!important}' +
        '.rf-card .rf-chip{min-width:26px!important;padding:1px 7px!important;font-size:.72rem!important}' +
        '.rf-card .rf-when{font-size:.78rem!important}' +
        '.rf-card .rf-bar{padding:0 14px 12px!important;gap:8px!important}' +
        '.rf-card .rf-bar .btn{min-height:40px!important;font-size:.85rem!important}' +
        '.rf-card .rf-time{padding:6px 10px!important;font-size:.82rem!important}' +
        '.reservation-group{margin-bottom:8px!important}' +
        '.tf-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:14px 0 90px}' +
        '.tf-pager[hidden]{display:none}' +
        '.tf-pager span{font-size:.85rem;font-weight:700;color:#6b7a90}' +
        '.tf-pager button{min-height:40px;padding:0 18px;border:0;border-radius:999px;background:#eaf1fd;color:#2f6fe4;font:inherit;font-size:.85rem;font-weight:700;cursor:pointer}' +
        '.tf-pager button:disabled{opacity:.4;cursor:default}';
    document.head.appendChild(st);
    var pager = document.createElement('div');
    pager.className = 'tf-pager'; pager.hidden = true;
    pager.innerHTML = '<button type="button" data-d="-1">Previous</button><span></span><button type="button" data-d="1">Next</button>';
    items[items.length - 1].parentNode.insertBefore(pager, items[items.length - 1].nextSibling);
    function tab() { var t = document.querySelector('.trip-status .dir-tab.on'); return t ? t.getAttribute('data-filter') : ''; }
    function run() {
        var matching = items.filter(function (el) { return !el.hidden; });
        var key = tab() + '|' + document.querySelectorAll('.date-filter-btn.active').length + (document.querySelector('.date-filter-btn.active') || { dataset: {} }).dataset.range + '|' + matching.length;
        if (key !== lastKey) {
            var restore = lastKey === null ? parseInt(sessionStorage.getItem('mbusTripPage:' + tab()) || '0', 10) : 0;
            page = restore || 0; lastKey = key;
        }
        var pages = Math.max(1, Math.ceil(matching.length / PER));
        page = Math.min(Math.max(page, 0), pages - 1);
        items.forEach(function (el) { el.classList.remove('tf-off'); });
        matching.forEach(function (el, i) { if (i < page * PER || i >= page * PER + PER) el.classList.add('tf-off'); });
        pager.hidden = pages < 2;
        pager.querySelector('span').textContent = (page + 1) + ' / ' + pages;
        pager.querySelector('[data-d="-1"]').disabled = page === 0;
        pager.querySelector('[data-d="1"]').disabled = page >= pages - 1;
        try { sessionStorage.setItem('mbusTripPage:' + tab(), String(page)); } catch (e) { /* ignore */ }
    }
    pager.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b || b.disabled) return;
        page += Number(b.getAttribute('data-d')); run();
        var t = document.querySelector('.trip-status');
        if (t) window.scrollTo({ top: Math.max(0, t.getBoundingClientRect().top + window.scrollY - 90), behavior: 'smooth' });
    });
    var obs = new MutationObserver(run);
    items.forEach(function (el) { obs.observe(el, { attributes: true, attributeFilter: ['hidden'] }); });
    document.querySelectorAll('.trip-status .dir-tab, .date-filter-btn').forEach(function (b) { b.addEventListener('click', function () { setTimeout(run, 0); }); });
    run();
})();
