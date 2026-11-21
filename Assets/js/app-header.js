(function () {
    // Where this script lives, so we can load notifications.js from the same folder on any page.
    var self = document.currentScript;
    var base = self && self.src ? self.src.replace(/app-header\.js.*$/, '') : '../Assets/js/';

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

        /* ===== Top bar that stays on screen while scrolling: back, title, notifications, profile ===== */
        const header = document.querySelector('.page-header');
        if (header) {
            const css = document.createElement('style');
            css.textContent =
                '.page-header{position:sticky;top:0;z-index:50;background:rgba(236,243,255,.94);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}' +
                '.page-header h1 .hdr-back{display:inline-grid;place-items:center;vertical-align:middle;width:40px;height:40px;margin:0 10px 0 0;border-radius:50%;background:#fff;border:1px solid #dbe5f4;color:#2f6fe4;font-size:.95rem;text-decoration:none}' +
                '.page-header .notif-wrap{position:relative}' +
                '.page-header .notif-btn{position:relative;display:grid;place-items:center;width:44px;height:44px;padding:0;border:0;border-radius:50%;background:#fff;color:#2f6fe4;font-size:1.05rem;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
                '.page-header .notif-dot{position:absolute;top:2px;right:2px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#e5484d;color:#fff;font-size:.68rem;font-weight:700;line-height:18px;text-align:center;box-sizing:border-box}' +
                '.page-header .notif-dot[hidden],.page-header .notif-panel[hidden]{display:none}' +
                '.page-header .notif-panel{position:absolute;right:0;top:calc(100% + 8px);width:min(340px,calc(100vw - 24px));max-height:60vh;overflow:auto;padding:14px;border-radius:16px;background:#fff;border:1px solid #dbe5f4;box-shadow:0 12px 30px rgba(22,32,51,.18);z-index:60}' +
                '.page-header .notif-panel h2{margin:0 0 8px;font-size:1rem}' +
                '.page-header .notif-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}' +
                '.page-header .notif-list li{display:grid;gap:2px;padding:10px 12px;border-radius:12px;background:#f5f8fd;font-size:.88rem;color:#172b4d}' +
                '.page-header .notif-list li.is-new{background:#eaf1fd}' +
                '.page-header .notif-list small{color:#6b7a90}' +
                '.page-header .notif-empty{margin:0;color:#6b7a90;font-size:.88rem}';
            document.head.appendChild(css);

            // Other sticky bars (like the trip filters) sit just under this header.
            const setHeaderHeight = function () {
                document.documentElement.style.setProperty('--hdr-h', header.getBoundingClientRect().height + 'px');
            };
            setHeaderHeight();
            window.addEventListener('resize', setHeaderHeight);
            if (window.ResizeObserver) new ResizeObserver(setHeaderHeight).observe(header);

            const path = location.pathname;
            const inCommuter = path.indexOf('/Commuter/') !== -1;
            const inAdmin = path.indexOf('/Admin/') !== -1;

            // Back button on inner pages (pages that are a tab in the bottom nav don't need one).
            const isTab = document.querySelector('.bottom-nav-item.active, .bottom-nav-item[aria-current="page"]');
            const hasBack = header.querySelector('.back-btn, .back-link, .ar-back-link, .hdr-back, [data-back]');
            const title = header.querySelector('h1');
            if (!isTab && !hasBack && title && (inCommuter || inAdmin)) {
                const back = document.createElement('a');
                back.className = 'hdr-back';
                back.href = inAdmin ? 'admin_dashboard.php' : 'commuter_dashboard.php';
                back.setAttribute('aria-label', 'Go back');
                back.innerHTML = '<i class="fa fa-arrow-left" aria-hidden="true"></i>';
                back.addEventListener('click', function (event) {
                    try {
                        if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) {
                            event.preventDefault();
                            history.back();
                        }
                    } catch (e) { /* follow the link */ }
                });
                title.insertBefore(back, title.firstChild);
            }

            // Notification bell on every commuter page (it used to exist only on Home).
            const right = header.querySelector('.page-header-right');
            if (inCommuter && right && !document.getElementById('notifBtn')) {
                const wrap = document.createElement('div');
                wrap.className = 'notif-wrap';
                wrap.innerHTML =
                    '<button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>' +
                    '<div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>';
                right.insertBefore(wrap, right.firstChild);
                const script = document.createElement('script');
                script.src = base + 'notifications.js';
                document.body.appendChild(script);
            }
        }

        // Keep both the selected date range and selected weekday visible on narrow screens.
        window.requestAnimationFrame(function () {
            document.querySelectorAll('.date-filter-btn.active, .day-tab.day-active').forEach(function (activeItem) {
                activeItem.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
            });
        });
    });
})();
