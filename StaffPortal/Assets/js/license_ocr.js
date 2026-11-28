/* Reads the uploaded driver's license photo in the browser (Tesseract OCR) and fills in the details it can find.
   Nothing leaves the device for this step. Fields are only filled when empty or when we filled them earlier,
   so anything the applicant typed is never overwritten. */
(function () {
    'use strict';
    var TESS_SRC = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';

    function cap(s) {
        return s.toLowerCase().replace(/(^|[\s'\-.])([a-zñ])/g, function (m, a, b) { return a + b.toUpperCase(); }).replace(/\s+/g, ' ').trim();
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function validDate(y, m, d) {
        return y >= 2000 && y <= 2100 && m >= 1 && m <= 12 && d >= 1 && d <= 31 ? y + '-' + pad(m) + '-' + pad(d) : '';
    }

    /* Pure parser, exposed for tests. Returns {number, expiry, lastName, firstName} with '' for anything not found. */
    function parse(raw) {
        var out = { number: '', expiry: '', lastName: '', firstName: '' };
        var text = String(raw || '').replace(/\r/g, '');
        var up = text.toUpperCase();

        /* OCR often adds or swaps a character (N01 read as NO01, 0 as O), so allow one extra leading character and fix look-alikes */
        var fix = function (t) { return t.replace(/[OQD]/g, '0').replace(/[ILl|]/g, '1').replace(/S/g, '5').replace(/B/g, '8'); };
        var m = up.match(/\b([A-Z])([0-9OQDILS|]{2,3})[\s\-–—_.:]*([0-9OQDILS|]{2})[\s\-–—_.:]*([0-9OQDILSB|]{6})\b/);
        if (m) out.number = m[1] + fix(m[2]).slice(-2) + '-' + fix(m[3]) + '-' + fix(m[4]);

        var dates = [], re = /\b(20\d{2})\s?[\/\-.]\s?(\d{1,2})\s?[\/\-.]\s?(\d{1,2})\b/g, d;
        while ((d = re.exec(up))) {
            var iso = validDate(+d[1], +d[2], +d[3]);
            if (iso) dates.push({ iso: iso, at: d.index });
        }
        if (dates.length) {
            var ex = up.search(/EXPIR/), pick = null;
            if (ex >= 0) {
                dates.forEach(function (x) { if (x.at >= ex && (!pick || x.at < pick.at)) pick = x; });
            }
            if (!pick) dates.forEach(function (x) { if (!pick || x.iso > pick.iso) pick = x; });
            out.expiry = pick.iso;
        }

        var lines = text.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
        for (var i = 0; i < lines.length; i++) {
            if (!/LAST\s*NAME/i.test(lines[i])) continue;
            var cand = lines[i].replace(/^.*?(LAST\s*NAME[^A-Za-z]*(FIRST\s*NAME[^A-Za-z]*)?(MIDDLE\s*NAME)?)/i, '').trim();
            var pool = [cand, lines[i + 1] || ''];
            for (var k = 0; k < pool.length; k++) {
                var nm = pool[k].toUpperCase().replace(/[^A-ZÑ,.' \-]/g, ' ').replace(/\s+/g, ' ').trim();
                var parts = nm.match(/^([A-ZÑ][A-ZÑ.' \-]+?)\s*,\s*([A-ZÑ][A-ZÑ.' \-]+)$/);
                if (parts) { out.lastName = cap(parts[1]); out.firstName = cap(parts[2]); break; }
            }
            if (out.lastName) break;
        }
        if (!out.lastName) {
            for (var j = 0; j < lines.length; j++) {
                var L = lines[j].toUpperCase().replace(/[^A-ZÑ,.' \-]/g, ' ').replace(/\s+/g, ' ').trim();
                if (/REPUBLIC|PHILIPPINES|LICENSE|DEPARTMENT|TRANSPORT|ADDRESS|NATIONALITY|AGENCY|EXPIR|BIRTH|WEIGHT|HEIGHT/.test(L)) continue;
                var q = L.match(/^([A-ZÑ][A-ZÑ.' \-]{1,}?)\s*,\s*([A-ZÑ][A-ZÑ.' \-]{1,})$/);
                if (q) { out.lastName = cap(q[1]); out.firstName = cap(q[2]); break; }
            }
        }
        return out;
    }

    /* Merge parse results: keep the first non-empty value of each field. */
    function merge(a, b) {
        var o = {};
        ['number', 'expiry', 'lastName', 'firstName'].forEach(function (k) { o[k] = a[k] || b[k] || ''; });
        return o;
    }
    function complete(f) { return f.number && f.expiry && f.lastName && f.firstName; }

    /* Upscale, grayscale and stretch the contrast so glare and soft focus hurt less. */
    function preprocess(file) {
        return (window.createImageBitmap ? createImageBitmap(file, { imageOrientation: 'from-image' }) : Promise.reject()).then(function (bmp) {
            var scale = Math.min(3, Math.max(0.5, 2200 / Math.max(bmp.width, bmp.height)));
            var w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var ctx = c.getContext('2d', { willReadFrequently: true });
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
            ctx.drawImage(bmp, 0, 0, w, h);
            var img = ctx.getImageData(0, 0, w, h), px = img.data, hist = new Uint32Array(256), i, g;
            for (i = 0; i < px.length; i += 4) { g = (px[i] * 0.299 + px[i + 1] * 0.587 + px[i + 2] * 0.114) | 0; px[i] = g; hist[g]++; }
            var total = w * h, lo = 0, hi = 255, acc = 0;
            for (i = 0; i < 256; i++) { acc += hist[i]; if (acc >= total * 0.02) { lo = i; break; } }
            acc = 0;
            for (i = 255; i >= 0; i--) { acc += hist[i]; if (acc >= total * 0.02) { hi = i; break; } }
            var span = Math.max(40, hi - lo);
            for (i = 0; i < px.length; i += 4) {
                g = Math.max(0, Math.min(255, ((px[i] - lo) * 255 / span) | 0));
                px[i] = px[i + 1] = px[i + 2] = g; px[i + 3] = 255;
            }
            ctx.putImageData(img, 0, 0);
            if (bmp.close) bmp.close();
            return c;
        });
    }

    /* Several attempts per photo (cleaned-up image, sparse-text mode, original) until every field is found. */
    function readFiles(T, files, onStep) {
        var found = { number: '', expiry: '', lastName: '', firstName: '' };
        return T.createWorker('eng').then(function (worker) {
            var chain = Promise.resolve();
            files.forEach(function (file) {
                chain = chain.then(function () {
                    if (complete(found)) return;
                    return preprocess(file).catch(function () { return file; }).then(function (clean) {
                        var plan = [[clean, '3'], [clean, '11'], [file, '3']];
                        var step = Promise.resolve();
                        plan.forEach(function (p) {
                            step = step.then(function () {
                                if (complete(found)) return;
                                onStep();
                                return worker.setParameters({ tessedit_pageseg_mode: p[1] }).then(function () { return worker.recognize(p[0]); }).then(function (r) {
                                    found = merge(found, parse(r && r.data ? r.data.text : ''));
                                }).catch(function () {});
                            });
                        });
                        return step;
                    });
                });
            });
            return chain.then(function () { return worker.terminate(); }, function () { return worker.terminate(); }).then(function () { return found; });
        });
    }

    function loadTesseract() {
        if (window.Tesseract) return Promise.resolve(window.Tesseract);
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = TESS_SRC; s.async = true;
            s.onload = function () { window.Tesseract ? resolve(window.Tesseract) : reject(new Error('OCR failed to load')); };
            s.onerror = function () { reject(new Error('OCR failed to load')); };
            document.head.appendChild(s);
        });
    }

    function setField(form, name, value) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el || !value) return false;
        if (el.value && el.dataset.ocr !== '1') return false;
        el.value = value; el.dataset.ocr = '1';
        if (!el.dataset.ocrWatch) {
            el.dataset.ocrWatch = '1';
            el.addEventListener('input', function (e) { if (e.isTrusted) delete el.dataset.ocr; });   // the applicant edited it: keep their text
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function init() {
        var input = document.querySelector('input[name="license_document"]');
        if (!input) return;
        var form = input.form, group = input.closest('.upload-group');
        if (!form || !group) return;

        var note = document.createElement('p');
        note.className = 'ocr-note'; note.hidden = true; note.setAttribute('role', 'status');
        group.appendChild(note);
        function say(msg, kind) { note.hidden = !msg; note.textContent = msg || ''; note.dataset.kind = kind || ''; }

        var run = 0;
        function start(list) {
            var files = list.filter(function (f) { return /^image\/(jpeg|png)$/.test(f.type); }).slice(0, 2);
            if (!files.length) { say(list.length ? 'Auto-fill works with photos (JPG or PNG). Please type your details.' : '', list.length ? 'warn' : ''); return; }
            var mine = ++run, tries = 0;
            say('Reading your license…', 'busy');
            loadTesseract().then(function (T) {
                return readFiles(T, files, function () { if (mine === run && ++tries > 1) say('Still reading, trying another way…', 'busy'); });
            }).then(function (f) {
                if (mine !== run) return;
                var filled = [];
                if (setField(form, 'license-number', f.number)) filled.push('license number');
                if (setField(form, 'license-expiry', f.expiry)) filled.push('expiry date');
                if (setField(form, 'driver-last-name', f.lastName)) filled.push('last name');
                if (setField(form, 'driver-first-name', f.firstName)) filled.push('first name');
                if (filled.length) say('Filled in your ' + filled.join(', ') + '. Please check that they are correct.', 'ok');
                else say('We could not read the details from this photo. Please type them in.', 'warn');
            }).catch(function () {
                if (mine === run) say('Auto-fill is unavailable right now. Please type your details.', 'warn');
            });
        }
        /* the multi-file box announces the originals; a plain input falls back to its own change event */
        if (group.hasAttribute('data-multi')) group.addEventListener('mbus:picked', function (e) { start(e.detail.files || []); });
        else input.addEventListener('change', function () { start(Array.from(input.files || [])); });
    }

    window.MbusLicenseOcr = { parse: parse };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
