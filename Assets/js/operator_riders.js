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
        st.className = 'rider-state ' + (r.status === 'Boarded' ? 'boarded' : r.status === 'Paid' ? 'paid' : 'pending');
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

        var canBoard = r.status === 'Paid' && TRIP_OPEN;
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
    function fare() {
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
        $('wkWho').textContent = activeReq ? activeReq.name + ' · pick-up request' : 'Walk-in passenger';
        $('wkNameRow').hidden = !!activeReq;
        $('wkName').value = '';
        selectVal($('wkPickup'), activeReq ? activeReq.spot : '') || selectVal($('wkPickup'), TRIP_ORIGIN);
        selectVal($('wkDest'), activeReq ? activeReq.dest : '') || selectVal($('wkDest'), TRIP_DEST);
        fare();
        sheet.hidden = true; walk.hidden = false; backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        $('walkClose').focus();
    }
    function setActive(req) {
        activeReq = req;
        document.querySelectorAll('#pickupList li').forEach(function (li) { li.classList.toggle('on', !!req && +li.dataset.req === req.id); });
        document.querySelectorAll('.pk-pick').forEach(function (b) { b.textContent = req && +b.dataset.req === req.id ? 'Cancel' : 'Assign seat'; });
        var hint = $('ridersHint');
        if (hint) hint.textContent = req ? 'Tap an empty seat for ' + req.name + '.' : 'Tap an empty seat to seat a walk-in. Reserved seats are locked here.';
        var map = document.querySelector('.rider-map');
        if (req && map && map.scrollIntoView) map.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (walk) {
        $('wkPickup').addEventListener('change', fare);
        $('wkDest').addEventListener('change', fare);
        $('walkForm').addEventListener('submit', function () {
            var g = $('wkGo'); setTimeout(function () { g.disabled = true; g.textContent = 'Confirming'; }, 0);
        });
    }

    document.addEventListener('click', function (e) {
        var seat = e.target.closest('.rseat[data-seat]');
        if (seat) { open(seat.dataset.seat, seat); return; }
        var add = e.target.closest('.rseat[data-add]');
        if (add) { openWalk(add.dataset.add, add); return; }
        var pick = e.target.closest('.pk-pick');
        if (pick) {
            var id = +pick.dataset.req, req = PICKUPS.filter(function (p) { return p.id === id; })[0];
            setActive(activeReq && activeReq.id === id ? null : req || null);
            return;
        }
        if (e.target.closest('#riderClose') || e.target.closest('#walkClose') || e.target === backdrop) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (!sheet.hidden || (walk && !walk.hidden))) close(); });

    $('boardForm').addEventListener('submit', function () {
        var b = $('rdBoard');
        setTimeout(function () { b.disabled = true; b.textContent = 'Confirming'; }, 0);
    });

    // Keep the chosen trip chip in view
    var on = document.querySelector('.trip-chip.on');
    if (on && on.scrollIntoView) on.scrollIntoView({ inline: 'center', block: 'nearest' });
})();
