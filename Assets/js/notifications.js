(function () {
  var btn = document.getElementById('notifBtn'); if (!btn) return;
  var panel = document.getElementById('notifPanel'), list = document.getElementById('notifList'), dot = document.getElementById('notifDot');
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  function ago(iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'Just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' hr ago'; return Math.floor(s / 86400) + ' d ago';
  }
  function render(d) {
    dot.hidden = !d.unread; dot.textContent = d.unread > 9 ? '9+' : d.unread;
    list.innerHTML = '';
    if (!d.items.length) { var e = document.createElement('p'); e.className = 'notif-empty'; e.textContent = 'No notifications yet. Payment, ID and bus updates show up here.'; list.appendChild(e); return; }
    d.items.forEach(function (n) {
      var li = document.createElement('li'); if (n.unread) li.className = 'is-new';
      var m = document.createElement('span'); m.textContent = n.message;
      var t = document.createElement('small'); t.textContent = ago(n.at);
      li.appendChild(m); li.appendChild(t); list.appendChild(li);
    });
  }
  function load() { return fetch('notifications.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(render).catch(function () {}); }
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = panel.hidden; panel.hidden = !open; btn.setAttribute('aria-expanded', String(open));
    if (open) load().then(function () {
      if (!dot.hidden) fetch('notifications.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'csrf_token=' + encodeURIComponent(token) })
        .then(function () { setTimeout(function () { dot.hidden = true; }, 1500); });
    });
  });
  document.addEventListener('click', function (e) { if (!panel.hidden && !panel.contains(e.target)) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
  load(); setInterval(function () { if (panel.hidden) load(); }, 60000);
})();
