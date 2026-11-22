/* Registration extras: structured Philippine address (dropdowns + street typed by hand) and a clean submitting screen. */
(function () {
    'use strict';

    /* ===== 1. Address ===== */
    (function address() {
        var API = 'https://psgc.gitlab.io/api';
        var els = {
            region: document.getElementById('addrRegion'),
            province: document.getElementById('addrProvince'),
            city: document.getElementById('addrCity'),
            barangay: document.getElementById('addrBarangay')
        };
        if (!els.region) return;
        var provinceWrap = document.getElementById('addrProvinceWrap');
        var note = document.getElementById('addrNote');
        var country = document.getElementById('addrCountry');
        var pre = window.MBUS_ADDR || {};
        if (country) country.disabled = true; /* only the Philippines for now */

        function cacheGet(key) {
            try { var v = sessionStorage.getItem('psgc:' + key); return v ? JSON.parse(v) : null; } catch (e) { return null; }
        }
        function cacheSet(key, value) {
            try { sessionStorage.setItem('psgc:' + key, JSON.stringify(value)); } catch (e) { /* storage full or blocked */ }
        }
        function getJson(path) {
            var hit = cacheGet(path);
            if (hit) return Promise.resolve(hit);
            return fetch(API + path).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).then(function (data) {
                if (!Array.isArray(data)) throw new Error('Unexpected reply');
                cacheSet(path, data);
                return data;
            });
        }
        function getFirst(paths) {
            var i = 0;
            function next() {
                if (i >= paths.length) return Promise.reject(new Error('No list found'));
                return getJson(paths[i++]).catch(next);
            }
            return next();
        }

        function labelOf(item, kind) {
            if (kind === 'region') {
                var r = item.regionName || '';
                return r && r !== item.name ? r + ' \u2013 ' + item.name : item.name;
            }
            return item.name;
        }
        function codeOf(select) {
            var o = select.options && select.options[select.selectedIndex];
            return o ? o.getAttribute('data-code') : '';
        }
        function clear(select, placeholder, disabled) {
            select.innerHTML = '';
            var o = document.createElement('option');
            o.value = '';
            o.textContent = placeholder;
            select.appendChild(o);
            select.disabled = !!disabled;
        }
        function fill(select, items, placeholder, kind, selected) {
            clear(select, placeholder, false);
            items.forEach(function (it) {
                var op = document.createElement('option');
                op.value = labelOf(it, kind);
                op.textContent = op.value;
                op.setAttribute('data-code', it.code);
                select.appendChild(op);
            });
            if (selected) {
                select.value = selected;
                if (select.value !== selected) select.value = '';
            }
        }
        var byName = function (a, b) { return String(a.name).localeCompare(String(b.name)); };
        var byCode = function (a, b) { return String(a.code).localeCompare(String(b.code)); };

        /* If the list can't be loaded (offline, blocked), the person can still type the address by hand. */
        function useTyping() {
            if (note) {
                note.hidden = false;
                note.textContent = 'We could not load the address list. Please type your region, province, city and barangay.';
            }
            ['region', 'province', 'city', 'barangay'].forEach(function (k) {
                var s = els[k];
                if (!s || s.tagName !== 'SELECT') return;
                var i = document.createElement('input');
                i.type = 'text';
                i.name = s.name;
                i.id = s.id;
                i.maxLength = 80;
                i.placeholder = s.getAttribute('data-ph') || '';
                i.required = k !== 'province';
                i.value = pre[k] || '';
                s.parentNode.replaceChild(i, s);
                els[k] = i;
            });
            if (provinceWrap) provinceWrap.hidden = false;
        }

        function onBarangays(code, restoring) {
            clear(els.barangay, 'Loading\u2026', true);
            return getFirst([
                '/cities-municipalities/' + code + '/barangays/',
                '/cities/' + code + '/barangays/',
                '/municipalities/' + code + '/barangays/'
            ]).then(function (items) {
                items.sort(byName);
                fill(els.barangay, items, 'Select barangay', 'barangay', restoring ? pre.barangay : '');
            });
        }
        function loadCities(path, restoring) {
            clear(els.city, 'Loading\u2026', true);
            clear(els.barangay, 'Select city first', true);
            return getJson(path).then(function (items) {
                items.sort(byName);
                fill(els.city, items, 'Select city or municipality', 'city', restoring ? pre.city : '');
                var code = codeOf(els.city);
                if (code) return onBarangays(code, restoring);
            });
        }
        function onProvince(restoring) {
            var code = codeOf(els.province);
            clear(els.city, code ? 'Loading\u2026' : 'Select province first', true);
            clear(els.barangay, 'Select city first', true);
            if (!code) return Promise.resolve();
            return loadCities('/provinces/' + code + '/cities-municipalities/', restoring);
        }
        function onRegion(restoring) {
            var code = codeOf(els.region);
            clear(els.province, code ? 'Loading\u2026' : 'Select region first', true);
            clear(els.city, 'Select province first', true);
            clear(els.barangay, 'Select city first', true);
            if (!code) return Promise.resolve();
            return getJson('/regions/' + code + '/provinces/').then(function (provs) {
                if (!provs.length) {
                    /* e.g. Metro Manila has no provinces: its cities sit directly under the region */
                    if (provinceWrap) provinceWrap.hidden = true;
                    els.province.required = false;
                    clear(els.province, '', true);
                    return loadCities('/regions/' + code + '/cities-municipalities/', restoring);
                }
                if (provinceWrap) provinceWrap.hidden = false;
                els.province.required = true;
                provs.sort(byName);
                fill(els.province, provs, 'Select province', 'province', restoring ? pre.province : '');
                if (codeOf(els.province)) return onProvince(restoring);
            });
        }

        els.region.addEventListener('change', function () { onRegion(false).catch(useTyping); });
        els.province.addEventListener('change', function () { onProvince(false).catch(useTyping); });
        els.city.addEventListener('change', function () {
            var code = codeOf(els.city);
            if (!code) { clear(els.barangay, 'Select city first', true); return; }
            onBarangays(code, false).catch(useTyping);
        });

        clear(els.region, 'Loading\u2026', true);
        clear(els.province, 'Select region first', true);
        clear(els.city, 'Select province first', true);
        clear(els.barangay, 'Select city first', true);
        getJson('/regions/').then(function (items) {
            items.sort(byCode);
            fill(els.region, items, 'Select region', 'region', pre.region);
            return onRegion(true);
        }).catch(useTyping);
    })();

    /* ===== 2. Submitting screen (replaces a button that just says "Please wait") ===== */
    (function submitting() {
        var form = document.querySelector('.form-body');
        if (!form) return;
        var overlay = null;
        function show() {
            if (overlay) return;
            overlay = document.createElement('div');
            overlay.className = 'mbus-submit-overlay';
            overlay.setAttribute('role', 'alertdialog');
            overlay.setAttribute('aria-live', 'polite');
            overlay.setAttribute('aria-label', 'Submitting your application');
            overlay.innerHTML = '<div class="mbus-submit-card"><div class="mbus-submit-spin" aria-hidden="true"></div>' +
                '<h2>Submitting your application</h2>' +
                '<p>Uploading your documents and photos. Please keep this page open.</p></div>';
            document.body.appendChild(overlay);
        }
        function hide() {
            if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
            overlay = null;
        }
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return; /* still moving between steps */
            var type = document.getElementById('user-type');
            if (!type || type.value !== 'driver') return;
            show();
            setTimeout(hide, 60000);
        });
        window.addEventListener('pageshow', hide);
    })();
})();
