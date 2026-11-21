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

        /* ===== Quick-action dock (phones): back, notifications and profile float at the bottom, above the
           footer nav, within thumb reach. They stay with you while you scroll, so nothing is pinned to the top. ===== */
        const bar = document.querySelector('.page-header, .home-top');
        const profileWrap = document.querySelector('.profile-menu-wrap');
        if (bar && profileWrap) setupDock(bar, profileWrap);

        // Keep both the selected date range and selected weekday visible on narrow screens.
        window.requestAnimationFrame(function () {
            document.querySelectorAll('.date-filter-btn.active, .day-tab.day-active').forEach(function (activeItem) {
                activeItem.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
            });
        });
    });

    function setupDock(bar, profileWrap) {
        const header = document.querySelector('.page-header');
        const path = location.pathname;
        const inCommuter = path.indexOf('/Commuter/') !== -1;
        const inAdmin = path.indexOf('/Admin/') !== -1;
        const mq = window.matchMedia('(max-width: 768px)');

        const css = document.createElement('style');
        css.textContent =
            /* desktop: normal header, with the back arrow beside the title */
            '.page-header h1 .hdr-back{display:inline-grid;place-items:center;vertical-align:middle;width:40px;height:40px;margin:0 10px 0 0;border-radius:50%;background:#fff;border:1px solid #dbe5f4;color:#2f6fe4;font-size:.95rem;text-decoration:none}' +
            '.mbus-bell{position:relative}' +
            '.mbus-bell .notif-btn{position:relative;display:grid;place-items:center;width:44px;height:44px;padding:0;border:0;border-radius:50%;background:#fff;color:#2f6fe4;font-size:1.05rem;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
            '.mbus-bell .notif-dot{position:absolute;top:2px;right:2px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#e5484d;color:#fff;font-size:.68rem;font-weight:700;line-height:18px;text-align:center;box-sizing:border-box}' +
            '.mbus-bell .notif-dot[hidden],.mbus-bell .notif-panel[hidden]{display:none}' +
            '.mbus-bell .notif-panel{position:absolute;right:0;top:calc(100% + 8px);width:min(340px,calc(100vw - 24px));max-height:60vh;overflow:auto;padding:14px;border-radius:16px;background:#fff;border:1px solid #dbe5f4;box-shadow:0 12px 30px rgba(22,32,51,.18);z-index:60}' +
            '.mbus-bell .notif-panel h2{margin:0 0 8px;font-size:1rem}' +
            '.mbus-bell .notif-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}' +
            '.mbus-bell .notif-list li{display:grid;gap:2px;padding:10px 12px;border-radius:12px;background:#f5f8fd;font-size:.88rem;color:#172b4d}' +
            '.mbus-bell .notif-list li.is-new{background:#eaf1fd}' +
            '.mbus-bell .notif-list small{color:#6b7a90}' +
            '.mbus-bell .notif-empty{margin:0;color:#6b7a90;font-size:.88rem}' +
            /* phones: the dock */
            '@media(max-width:768px){' +
            '.mbus-dock{position:fixed;left:14px;bottom:var(--dock-b,96px);z-index:90;display:flex;align-items:center;gap:10px}' +
            '.mbus-dock .profile-menu-wrap,.mbus-dock .notif-wrap{position:relative!important;top:auto!important;right:auto!important;left:auto!important;bottom:auto!important;margin:0!important;transform:none!important;display:block!important}' +
            '.mbus-dock .hdr-back,.mbus-dock .notif-btn,.mbus-dock .profile-trigger{position:relative!important;display:grid!important;place-items:center;width:48px!important;height:48px!important;min-width:48px!important;padding:0!important;margin:0!important;border:0!important;border-radius:50%!important;background:#fff!important;box-shadow:0 6px 16px rgba(30,60,120,.24)!important;color:#2f6fe4;font-size:1.05rem;cursor:pointer;text-decoration:none;-webkit-tap-highlight-color:transparent}' +
            '.mbus-dock .profile-trigger-label,.mbus-dock .profile-trigger>.fa-chevron-down{display:none!important}' +
            '.mbus-dock .profile-avatar{display:block!important;width:44px!important;height:44px!important;border-radius:50%;overflow:hidden}' +
            '.mbus-dock .profile-avatar img{width:100%;height:100%;object-fit:cover;display:block}' +
            '.mbus-dock .profile-menu,.mbus-dock .notif-panel{position:fixed!important;top:auto!important;right:auto!important;left:14px!important;bottom:calc(var(--dock-b,96px) + 60px)!important;width:min(300px,calc(100vw - 28px))!important;max-height:55vh;overflow:auto;z-index:95}' +
            'body.has-dock main{margin-bottom:64px}' +
            '}';
        document.head.appendChild(css);

        const dock = document.createElement('div');
        dock.className = 'mbus-dock';
        dock.setAttribute('role', 'toolbar');
        dock.setAttribute('aria-label', 'Quick actions');

        // Where things live on a normal (desktop) header, so we can put them back.
        const home = function (node) { return node ? { parent: node.parentNode, next: node.nextSibling } : null; };
        const restore = function (node, h) {
            if (!node || !h) return;
            if (h.next && h.next.parentNode === h.parent) h.parent.insertBefore(node, h.next);
            else h.parent.appendChild(node);
        };

        const profileHome = home(profileWrap);

        // Back button on inner pages (pages that are a tab in the bottom nav don't need one).
        let back = null;
        const isTab = document.querySelector('.bottom-nav-item.active, .bottom-nav-item[aria-current="page"]');
        const hasBack = header && header.querySelector('.back-btn, .back-link, .ar-back-link, .hdr-back, [data-back]');
        const title = header && header.querySelector('h1');
        if (header && !isTab && !hasBack && title && (inCommuter || inAdmin)) {
            back = document.createElement('a');
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
        }

        // Notification bell on every commuter page (it used to exist only on Home).
        let bell = document.querySelector('.notif-wrap');
        let bellHome = home(bell);
        let injected = false;
        if (!bell && inCommuter) {
            bell = document.createElement('div');
            bell.className = 'notif-wrap mbus-bell';
            bell.innerHTML =
                '<button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>' +
                '<div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>';
            injected = true;
        }

        const placeDock = function () {
            const nav = document.querySelector('.bottom-nav');
            let b = 16;
            if (nav) {
                const r = nav.getBoundingClientRect();
                if (r.height) b = Math.max(16, window.innerHeight - r.top + 12);
            }
            document.documentElement.style.setProperty('--dock-b', b + 'px');
        };

        const toPhone = function () {
            if (!dock.parentNode) document.body.appendChild(dock);
            if (back) dock.appendChild(back);
            if (bell) dock.appendChild(bell);
            dock.appendChild(profileWrap);
            document.body.classList.add('has-dock');
            placeDock();
        };

        const toDesktop = function () {
            restore(profileWrap, profileHome);
            if (bell) {
                if (injected) {
                    const right = document.querySelector('.page-header-right');
                    if (right) right.insertBefore(bell, right.firstChild);
                    else if (header) header.appendChild(bell);
                } else {
                    restore(bell, bellHome);
                }
            }
            if (back && title) title.insertBefore(back, title.firstChild);
            if (dock.parentNode) dock.parentNode.removeChild(dock);
            document.body.classList.remove('has-dock');
        };

        const apply = function () { if (mq.matches) toPhone(); else toDesktop(); };
        apply();
        if (mq.addEventListener) mq.addEventListener('change', apply); else if (mq.addListener) mq.addListener(apply);
        window.addEventListener('resize', placeDock);
        window.addEventListener('orientationchange', placeDock);
        window.addEventListener('load', placeDock);
        setTimeout(placeDock, 300);

        if (injected) {
            const script = document.createElement('script');
            script.src = base + 'notifications.js';
            document.body.appendChild(script);
        }
    }
})();
