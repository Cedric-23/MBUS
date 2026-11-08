(function () {
    var sheet = document.getElementById('riderSheet');
    var backdrop = document.getElementById('riderBackdrop');
    var lastFocus = null;
    function $(id) { return document.getElementById(id); }

    function open(seat, trigger) {
        var r = RIDERS[seat];
        if (!r) return;
        lastFocus = trigger;
        $('rdName').textContent = r.name;
        $('rdSeat').textContent = 'Seat ' + r.seat;
        var st = $('rdState');
        var label = (r.status === 'Pending' ? 'Unpaid' : r.status) + (r.walk ? ' · ' + r.kind : '');
        st.textContent = label;
        st.className = 'rider-state ' + (r.status === 'Boarded' ? 'boarded' : (r.status === 'Paid' || r.status === 'Confirmed') ? 'paid' : 'pending');
        $('rdPickup').textContent = r.pickup || '-';
        $('rdDest').textContent = r.destination || '-';
        var ph = $('rdPhone');
        ph.textContent = '';
        if (r.phone) {
            var a = document.createElement('a');
            a.href = 'tel:' + r.phone;
            a.textContent = r.phone;
            ph.appendChild(a);
        } else { ph.textContent = '-'; }
        $('rdCode').textContent = r.code || '-';
        $('rdAmount').textContent = r.amount ? '₱' + r.amount : '-';
        $('rdRef').textContent = r.reference || '-';
        $('rdId').value = r.id;

        var canBoard = (r.status === 'Paid' || r.status === 'Confirmed') && TRIP_OPEN;
        $('boardForm').hidden = !canBoard;
        var note = $('rdNote');
        var msg = '';
        if (r.status === 'Boarded') msg = 'Already on board.';
        else if (r.status === 'Pending') msg = 'Waiting for payment. The seat is held for 10 minutes.';
        else if (!TRIP_OPEN) msg = 'Boarding is closed for this trip.';
        note.textContent = msg;
        note.hidden = !msg;

        sheet.hidden = false;
        backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        $('riderClose').focus();
    }

    function close() {
        sheet.hidden = true;
        if (walk) walk.hidden = true;
        backdrop.hidden = true;
        document.body.style.overflow = '';
        if (lastFocus) lastFocus.focus();
    }

    /* ---------- walk-in / pick-up seating ---------- */
    var walk = $('walkSheet');
    var activeReq = null;
    function selectVal(sel, want) {
        want = String(want || '').toUpperCase();
        for (var i = 0; i < sel.options.length; i++) if (sel.options[i].value === want) { sel.selectedIndex = i; return true; }
        sel.selectedIndex = 0; return false;
    }
    var pickMode = !!document.querySelector('.rider-map.mode-pick');
    function fare() {
        if (activeReq) { $('wkFare').textContent = '₱' + Number(activeReq.fare).toFixed(2); $('wkGo').disabled = false; return; }
        var a = $('wkPickup'), b = $('wkDest'), go = $('wkGo');
        var fa = a.selectedOptions[0] && a.selectedOptions[0].dataset.fare, fb = b.selectedOptions[0] && b.selectedOptions[0].dataset.fare;
        var ok = a.value && b.value && a.value !== b.value && fa && fb;
        $('wkFare').textContent = ok ? '₱' + Math.abs(parseFloat(fb) - parseFloat(fa)).toFixed(2) : '₱0.00';
        go.disabled = !ok;
    }
    function openWalk(seat, trigger) {
        lastFocus = trigger;
        $('wkSeat').value = seat;
        $('wkReq').value = activeReq ? activeReq.id : '';
        $('wkTitle').textContent = 'Seat ' + seat;
        $('wkWho').textContent = activeReq ? activeReq.name + ' · pick-up' : 'Walk-in passenger';
        $('wkNameRow').hidden = !!activeReq;
        $('wkName').value = '';
        $('wkReq').value = activeReq ? activeReq.id : '';
        var sum = $('wkSummary');
        sum.hidden = !activeReq;
        sum.textContent = '';
        if (activeReq) {
            [['Pinned spot', activeReq.spot], ['Boards near', cap(activeReq.near)], ['Going to', cap(activeReq.dest)]].forEach(function (x) {
                var row = document.createElement('div'); row.className = 'wk-line';
                var k = document.createElement('span'); k.textContent = x[0];
                var v = document.createElement('strong'); v.textContent = x[1];
                row.appendChild(k); row.appendChild(v); sum.appendChild(row);
            });
        }
        document.querySelectorAll('.wk-manual').forEach(function (l) { l.hidden = !!activeReq; });
        $('wkPickup').required = !activeReq; $('wkDest').required = !activeReq;
        selectVal($('wkPickup'), activeReq ? activeReq.near : '') || selectVal($('wkPickup'), TRIP_ORIGIN);
        selectVal($('wkDest'), activeReq ? activeReq.dest : '') || selectVal($('wkDest'), TRIP_DEST);
        $('wkGo').textContent = activeReq ? 'Confirm seat ' + seat : 'Confirm seat';
        fare();
        sheet.hidden = true; walk.hidden = false; backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        $('walkClose').focus();
    }
    function cap(t) { return String(t || '').toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
    function setActive(req) {
        activeReq = req;
        document.querySelectorAll('#pickupList li').forEach(function (li) { li.classList.toggle('on', !!req && +li.dataset.req === req.id); });
        document.querySelectorAll('.rseat.open').forEach(function (b) { b.classList.toggle('ready', !!req); });
        var hint = $('ridersHint');
        if (hint) hint.textContent = req ? 'Now tap the seat ' + req.name + ' will take.' : 'Tap a person above, then tap the seat they will take.';
        var map = document.querySelector('.rider-map');
        var hh = $('ridersHint'); if (hh) hh.classList.remove('riders-hint-warn');
        if (req && map && map.scrollIntoView) map.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (walk) {
        $('wkPickup').addEventListener('change', fare);
        $('wkDest').addEventListener('change', fare);
        $('walkForm').addEventListener('submit', function () {
            var g = $('wkGo'); setTimeout(function () { g.disabled = true; g.textContent = 'Confirming'; }, 0);
        });
    }

    /* compact pick-up list (more than 3 waiting to be seated) */
    var pkModal = $('pkModal');
    function pkOpen(open) {
        if (!pkModal) return;
        pkModal.hidden = !open;
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) { var f = pkModal.querySelector('.pk-pick'); if (f) f.focus(); } else if ($('pkCompact')) $('pkCompact').focus();
    }
    function pkLabel() {
        var sub = $('pkCompactSub'); if (!sub) return;
        sub.textContent = activeReq ? 'Seating ' + activeReq.name + ' · tap to change' : 'Tap to choose who to seat';
    }
    if ($('pkCompact')) $('pkCompact').addEventListener('click', function () { pkOpen(true); });
    if ($('pkModalClose')) $('pkModalClose').addEventListener('click', function () { pkOpen(false); });
    if (pkModal) pkModal.addEventListener('click', function (e) { if (e.target === pkModal) pkOpen(false); });

    document.addEventListener('click', function (e) {
        var seat = e.target.closest('.rseat[data-seat]');
        if (seat) { open(seat.dataset.seat, seat); return; }
        var add = e.target.closest('.rseat[data-add]');
        if (add) {
            if (pickMode && !activeReq) { var h = $('ridersHint'); if (h) { h.textContent = 'Pick a person from the list first.'; h.classList.add('riders-hint-warn'); } return; }
            if (!pickMode) activeReq = null;
            openWalk(add.dataset.add, add); return;
        }
        var pick = e.target.closest('.pk-pick');
        if (pick) {
            var id = +pick.dataset.req, req = PICKUPS.filter(function (p) { return p.id === id; })[0];
            setActive(activeReq && activeReq.id === id ? null : req || null);
            if (pkModal && !pkModal.hidden) pkOpen(false);
            pkLabel();
            return;
        }
        if (e.target.closest('#riderClose') || e.target.closest('#walkClose') || e.target === backdrop) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pkModal && !pkModal.hidden) { pkOpen(false); return; } if (e.key === 'Escape' && (!sheet.hidden || (walk && !walk.hidden))) close(); });

    $('boardForm').addEventListener('submit', function () {
        var b = $('rdBoard');
        setTimeout(function () { b.disabled = true; b.textContent = 'Confirming'; }, 0);
    });

    // Keep the chosen trip chip in view
    var on = document.querySelector('.trip-chip.on');
    if (on && on.scrollIntoView) on.scrollIntoView({ inline: 'center', block: 'nearest' });
})();
