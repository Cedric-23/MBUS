/* Address picker: Country > Region > Province > City/Municipality > Barangay (PSGC data), street typed by hand.
   Builds the full address into the hidden #home-address field the server already validates.
   If the address list can't load, the person can type the whole address instead. */
(function () {
  var root = document.getElementById('addressPicker');
  if (!root) return;
  var API = 'https://psgc.gitlab.io/api/';
  var el = function (n) { return root.querySelector('[data-addr="' + n + '"]'); };
  var region = el('region'), province = el('province'), city = el('city'), brgy = el('barangay'), street = el('street');
  var hidden = document.getElementById('home-address'), manual = el('manual'), note = el('note');
  var KEY = 'mbus_reg_addr_v1', cache = {};

  function get(path) {
    if (cache[path]) return Promise.resolve(cache[path]);
    return fetch(API + path, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error('addr');
      return r.json();
    }).then(function (d) { d.sort(function (a, b) { return a.name.localeCompare(b.name); }); cache[path] = d; return d; });
  }
  function fill(sel, items, label) {
    sel.innerHTML = '';
    var o = document.createElement('option'); o.value = ''; o.textContent = label; sel.appendChild(o);
    items.forEach(function (i) { var x = document.createElement('option'); x.value = i.code; x.textContent = i.name; sel.appendChild(x); });
    sel.disabled = !items.length;
  }
  function reset(sel, label) { fill(sel, [], label); }
  function text(sel) { return sel.value ? sel.options[sel.selectedIndex].textContent : ''; }

  function compose() {
    var parts = [street.value.trim(), brgy.value ? (/^brgy|barangay/i.test(text(brgy)) ? text(brgy) : 'Brgy. ' + text(brgy)) : '', text(city), text(province), text(region), 'Philippines'];
    var ok = street.value.trim() && region.value && city.value && (brgy.value || brgy.dataset.empty);
    hidden.value = ok ? parts.filter(Boolean).join(', ') : '';
    try { localStorage.setItem(KEY, JSON.stringify({ r: region.value, p: province.value, c: city.value, b: brgy.value, s: street.value })); } catch (e) {}
    if (hidden.setCustomValidity) hidden.setCustomValidity('');
  }

  function loadProvinces() {
    reset(province, 'Province'); reset(city, 'City / Municipality'); reset(brgy, 'Barangay'); brgy.dataset.empty = '';
    if (!region.value) return Promise.resolve();
    return get('regions/' + region.value + '/provinces/').then(function (list) {
      if (list.length) { fill(province, list, 'Province'); province.required = true; return; }
      // regions without provinces (e.g. NCR) go straight to cities
      province.required = false; province.disabled = true; province.innerHTML = '<option value="">Not applicable</option>';
      return get('regions/' + region.value + '/cities-municipalities/').then(function (c) { fill(city, c, 'City / Municipality'); });
    });
  }
  function loadCities() {
    reset(city, 'City / Municipality'); reset(brgy, 'Barangay'); brgy.dataset.empty = '';
    if (!province.value) return Promise.resolve();
    return get('provinces/' + province.value + '/cities-municipalities/').then(function (c) { fill(city, c, 'City / Municipality'); });
  }
  function loadBarangays() {
    reset(brgy, 'Barangay'); brgy.dataset.empty = '';
    if (!city.value) return Promise.resolve();
    return get('cities-municipalities/' + city.value + '/barangays/').then(function (b) {
      if (b.length) fill(brgy, b, 'Barangay');
      else { brgy.innerHTML = '<option value="">No barangay list</option>'; brgy.disabled = true; brgy.dataset.empty = '1'; }
    });
  }
  function fail() {
    root.classList.add('is-manual');
    note.textContent = 'We couldn’t load the address list. Type your full address instead.';
    note.hidden = false;
    manual.hidden = false; manual.required = true; manual.name = 'home-address-manual';
    [region, province, city, brgy, street].forEach(function (s) { s.required = false; s.disabled = true; });
    manual.addEventListener('input', function () { hidden.value = manual.value.trim(); });
    hidden.value = manual.value.trim();
  }

  region.addEventListener('change', function () { loadProvinces().then(compose).catch(fail); compose(); });
  province.addEventListener('change', function () { loadCities().then(compose).catch(fail); compose(); });
  city.addEventListener('change', function () { loadBarangays().then(compose).catch(fail); compose(); });
  brgy.addEventListener('change', compose);
  street.addEventListener('input', compose);

  // restore a saved pick (only while the registration draft still exists)
  var saved = null;
  try { if (localStorage.getItem('mbus_reg_draft_v1')) saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}

  get('regions/').then(function (list) {
    fill(region, list.map(function (r) { return { code: r.code, name: r.name + (r.regionName && r.regionName !== r.name ? ' (' + r.regionName + ')' : '') }; }), 'Region');
    if (!saved || !saved.r) return;
    region.value = saved.r;
    return loadProvinces().then(function () {
      if (saved.p) { province.value = saved.p; return loadCities(); }
    }).then(function () {
      if (saved.c) { city.value = saved.c; return loadBarangays(); }
    }).then(function () { if (saved.b) brgy.value = saved.b; street.value = saved.s || ''; compose(); });
  }).catch(fail);

  var form = root.closest('form');
  if (form) form.addEventListener('submit', function (e) {
    if (!hidden.disabled && !hidden.value && !root.classList.contains('is-manual')) {
      e.preventDefault();
      var missing = [region, province, city, brgy, street].find(function (s) { return !s.disabled && !s.value; }) || street;
      missing.focus(); note.textContent = 'Complete your address: pick each dropdown and add your street.'; note.hidden = false;
    }
  });
})();
