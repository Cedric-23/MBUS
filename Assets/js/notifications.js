(function () {
  var btn = document.getElementById('notifBtn'); if (!btn) return;
  var panel = document.getElementById('notifPanel'), list = document.getElementById('notifList'), dot = document.getElementById('notifDot');
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  function ago(iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'Just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' hr ago'; return Math.floor(s / 86400) + ' d ago';
  }
  /* Pages instead of a scrolling list: PER items at a time with Previous / Next. */
  var PER = 3, page = 0, items = [];
  var st = document.createElement('style');
  st.textContent = '.notif-panel{overflow:visible!important;max-height:none!important;width:min(280px,calc(100vw - 32px))!important;padding:10px!important;border-radius:16px!important}' +
    '.notif-panel h2{font-size:.82rem!important;margin:0 2px 6px!important}' +
    '.notif-list{gap:4px!important}.notif-list li{padding:7px 10px!important;font-size:.76rem!important;line-height:1.3!important;border-radius:12px!important}.notif-list small{font-size:.66rem!important}' +
    '.notif-pager{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}' +
    '.notif-pager[hidden]{display:none}' +
    '.notif-pager span{font-size:.78rem;color:#6b7a90;font-weight:600}' +
    '.notif-pager button{min-height:30px;padding:0 12px;border:0;border-radius:999px;background:#eaf1fd;color:#2f6fe4;font:inherit;font-size:.74rem;font-weight:700;cursor:pointer}' +
    '.notif-pager button:disabled{opacity:.4;cursor:default}';
  document.head.appendChild(st);
  var pager = document.createElement('div'); pager.className = 'notif-pager'; pager.hidden = true;
  pager.innerHTML = '<button type="button" data-d="-1">Previous</button><span></span><button type="button" data-d="1">Next</button>';
  list.parentNode.insertBefore(pager, list.nextSibling);
  pager.addEventListener('click', function (e) {
    e.stopPropagation();
    var b = e.target.closest('button'); if (!b || b.disabled) return;
    page += +b.dataset.d; draw();
  });
  function render(d) {
    dot.hidden = !d.unread; dot.textContent = d.unread > 9 ? '9+' : d.unread;
    items = d.items || []; if (panel.hidden) page = 0; draw();
  }
  function draw() {
    list.innerHTML = '';
    var pages = Math.max(1, Math.ceil(items.length / PER));
    page = Math.min(Math.max(page, 0), pages - 1);
    pager.hidden = pages < 2;
    pager.querySelector('span').textContent = (page + 1) + ' / ' + pages;
    pager.querySelector('[data-d="-1"]').disabled = page === 0;
    pager.querySelector('[data-d="1"]').disabled = page >= pages - 1;
    if (!items.length) { var e = document.createElement('p'); e.className = 'notif-empty'; e.textContent = 'No notifications yet. Payment, ID and bus updates show up here.'; list.appendChild(e); return; }
    items.slice(page * PER, page * PER + PER).forEach(function (n) {
      var li = document.createElement('li'); if (n.unread) li.className = 'is-new';
      var m = document.createElement('span'); m.textContent = n.message;
      var t = document.createElement('small'); t.textContent = ago(n.at);
      li.appendChild(m); li.appendChild(t); list.appendChild(li);
    });
  }
  var endpoint = window.MBUS_NOTIFICATIONS_ENDPOINT || 'notifications.php';
  function load() { return fetch(endpoint, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(render).catch(function () {}); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = panel.hidden; panel.hidden = !open; btn.setAttribute('aria-expanded', String(open));
    if (open) load().then(function () {
      if (!dot.hidden) fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'csrf_token=' + encodeURIComponent(token) })
        .then(function () { setTimeout(function () { dot.hidden = true; }, 1500); });
    });
  });
  document.addEventListener('click', function (e) { if (!panel.hidden && !panel.contains(e.target)) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
  load(); setInterval(function () { if (!document.hidden) load(); }, 5000);
})();
