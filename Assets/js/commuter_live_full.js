/* Full-screen toggle for the live map (CSS based, so it also works on iPhones). */
(function () {
  var wrap = document.getElementById('lvMapWrap'), btn = document.getElementById('lvFullBtn');
  if (!wrap || !btn) return;
  function set(on) {
    wrap.classList.toggle('is-full', on);
    document.documentElement.classList.toggle('lv-noscroll', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.setAttribute('aria-label', on ? 'Exit full screen' : 'Full screen map');
    btn.querySelector('i').className = on ? 'fa fa-compress' : 'fa fa-expand';
    // Leaflet re-measures itself on window resize
    [0, 120, 350].forEach(function (t) { setTimeout(function () { window.dispatchEvent(new Event('resize')); }, t); });
  }
  btn.addEventListener('click', function () { set(!wrap.classList.contains('is-full')); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && wrap.classList.contains('is-full')) set(false); });
})();
