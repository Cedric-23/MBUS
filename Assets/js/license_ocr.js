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

        var m = up.match(/\b([A-Z])\s?([0-9OIl]{2})\s?[-–—_.]?\s?([0-9OIl]{2})\s?[-–—_.]?\s?([0-9OIl]{6})\b/);
        if (m) {
            var fix = function (s) { return s.replace(/[OD]/g, '0').replace(/[IL]/g, '1'); };
            out.number = m[1] + fix(m[2]) + '-' + fix(m[3]) + '-' + fix(m[4]);
        }

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
        return out;
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
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) { say(''); return; }
            if (!/^image\/(jpeg|png)$/.test(file.type)) { say('Auto-fill works with photos (JPG or PNG). Please type your details.', 'warn'); return; }
            var mine = ++run;
            say('Reading your license…', 'busy');
            loadTesseract().then(function (T) { return T.recognize(file, 'eng'); }).then(function (r) {
                if (mine !== run) return;
                var f = parse(r && r.data ? r.data.text : '');
                var filled = [];
                if (setField(form, 'license-number', f.number)) filled.push('license number');
                if (setField(form, 'license-expiry', f.expiry)) filled.push('expiry date');
                if (setField(form, 'driver-last-name', f.lastName)) filled.push('last name');
                if (setField(form, 'driver-first-name', f.firstName)) filled.push('first name');
                if (filled.length) say('Filled in your ' + filled.join(', ') + '. Please check that they are correct.', 'ok');
                else say('We could not read the details from this photo. Please type them in, or retake it with better light.', 'warn');
            }).catch(function () {
                if (mine === run) say('Auto-fill is unavailable right now. Please type your details.', 'warn');
            });
        });
    }

    window.MbusLicenseOcr = { parse: parse };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
