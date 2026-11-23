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
