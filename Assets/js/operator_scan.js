(function () {
    var video = document.getElementById('scanVideo');
    var statusEl = document.getElementById('scanStatus');
    var camera = document.getElementById('scanCamera');
    var errorEl = document.getElementById('scanError');
    var resultEl = document.getElementById('scanResult');
    var doneEl = document.getElementById('scanDone');
    var restartBtn = document.getElementById('scanRestart');
    var boardBtn = document.getElementById('boardBtn');
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    var stream = null, scanning = false, currentCode = null, busy = false;

    function $(id) { return document.getElementById(id); }
    function setStatus(t) { statusEl.textContent = t; }

    function post(action, code) {
        var body = new URLSearchParams({ action: action, code: code, csrf_token: scanCsrf });
        return fetch('scan.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Network problem. Check your connection and try again.' }; });
    }

    function reset() {
        currentCode = null;
        errorEl.hidden = true;
        resultEl.hidden = true;
        doneEl.hidden = true;
        camera.hidden = false;
        restartBtn.hidden = true;
        startCamera();
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.hidden = false;
        resultEl.hidden = true;
        camera.hidden = true;
        restartBtn.hidden = false;
    }

    function showTicket(t, code) {
        currentCode = code;
        errorEl.hidden = true;
        $('rName').textContent = t.passenger;
        $('rRoute').textContent = t.route;
        $('rSeats').textContent = t.seats.join(', ');
        $('rBus').textContent = t.bus;
        $('rDeparture').textContent = t.departure;
        $('rPickup').textContent = t.pickup;
        $('rDestination').textContent = t.destination;
        $('rPaid').textContent = t.amount ? '₱' + t.amount : 'Paid';
        $('rRef').textContent = t.reference || '-';
        var st = $('rState');
        st.textContent = t.state === 'Boarded' ? 'Already boarded' : 'Paid';
        st.className = 'scan-state ' + (t.state === 'Boarded' ? 'warn' : 'ok');
        var ppl = $('rPeople');
        ppl.textContent = '';
        var special = (t.people || []).filter(function (p) { return p.type !== 'Regular'; });
        ppl.hidden = !special.length;
        special.forEach(function (p) {
            var row = document.createElement('div');
            row.className = 'scan-person';
            var head = document.createElement('div');
            head.className = 'scan-person-head';
            var who = document.createElement('strong');
            who.textContent = 'Seat ' + p.seat + ' · ' + p.type;
            var chip = document.createElement('span');
            var label = { Approved: 'Verified account', Submitted: 'Check this ID', None: 'No ID' }[p.discount] || p.discount;
            chip.className = 'scan-chip ' + String(p.discount).toLowerCase();
            chip.textContent = label;
            head.appendChild(who); head.appendChild(chip);
            row.appendChild(head);
            if (p.id) {
                var a = document.createElement('a');
                a.href = p.id; a.target = '_blank'; a.rel = 'noopener';
                var img = document.createElement('img');
                img.src = p.id; img.alt = 'Passenger ID, seat ' + p.seat; img.loading = 'lazy';
                a.appendChild(img); row.appendChild(a);
            } else if (p.discount === 'Approved') {
                var ok = document.createElement('small');
                ok.textContent = 'Verified account holder';
                row.appendChild(ok);
            }
            ppl.appendChild(row);
        });
        var note = $('rNote');
        var text = t.note || '';
        note.hidden = !text;
        note.textContent = text;
        boardBtn.hidden = !t.can_board;
        resultEl.hidden = false;
        camera.hidden = true;
        restartBtn.hidden = false;
        resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function lookup(code) {
        if (busy) return;
        busy = true;
        stopCamera();
        setStatus('Checking ticket');
        post('lookup', code).then(function (res) {
            busy = false;
            if (res.ok) { showTicket(res.ticket, code); } else { showError(res.error || 'Could not read this ticket.'); }
        });
    }

    boardBtn.addEventListener('click', function () {
        if (!currentCode || busy) return;
        busy = true;
        boardBtn.disabled = true;
        boardBtn.textContent = 'Confirming';
        post('board', currentCode).then(function (res) {
            busy = false;
            boardBtn.disabled = false;
            boardBtn.textContent = 'Confirm boarding';
            if (res.ok) {
                resultEl.hidden = true;
                camera.hidden = true;
                restartBtn.hidden = true;
                $('doneText').textContent = $('rName').textContent + ' • Seat ' + $('rSeats').textContent;
                doneEl.hidden = false;
            } else {
                showError(res.error || 'Could not confirm boarding.');
            }
        });
    });

    $('nextBtn').addEventListener('click', reset);
    restartBtn.addEventListener('click', reset);

    $('scanManual').addEventListener('submit', function (e) {
        e.preventDefault();
        var v = $('scanCode').value.trim();
        if (v) lookup(v);
    });

    $('scanFile').addEventListener('change', function (e) {
        var file = e.target.files && e.target.files[0];
        if (!file) return;
        var img = new Image();
        img.onload = function () {
            var max = 1000, scale = Math.min(1, max / Math.max(img.width, img.height));
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(d.data, d.width, d.height);
            URL.revokeObjectURL(img.src);
            if (code && code.data) { lookup(code.data); } else { showError('No QR code found in that photo.'); }
        };
        img.src = URL.createObjectURL(file);
        e.target.value = '';
    });

    function tick() {
        if (!scanning) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            var w = video.videoWidth, h = video.videoHeight, scale = Math.min(1, 640 / Math.max(w, h));
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' });
            if (code && code.data) {
                if (navigator.vibrate) navigator.vibrate(60);
                lookup(code.data);
                return;
            }
        }
        requestAnimationFrame(tick);
    }

    function stopCamera() {
        scanning = false;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Camera is not available here. Type the ticket code or use a photo.');
            return;
        }
        setStatus('Starting camera');
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                return video.play();
            })
            .then(function () {
                scanning = true;
                setStatus('Point the camera at the passenger’s QR code');
                requestAnimationFrame(tick);
            })
            .catch(function () {
                setStatus('Camera blocked. Allow camera access, or type the ticket code.');
            });
    }

    window.addEventListener('pagehide', stopCamera);
    startCamera();
})();
