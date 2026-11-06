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
        var label = r.status === 'Pending' ? 'Unpaid' : r.status;
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
        backdrop.hidden = true;
        document.body.style.overflow = '';
        if (lastFocus) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
        var seat = e.target.closest('.rseat[data-seat]');
        if (seat) { open(seat.dataset.seat, seat); return; }
        if (e.target.closest('#riderClose') || e.target === backdrop) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });

    $('boardForm').addEventListener('submit', function () {
        var b = $('rdBoard');
        setTimeout(function () { b.disabled = true; b.textContent = 'Confirming'; }, 0);
    });

    // Keep the chosen trip chip in view
    var on = document.querySelector('.trip-chip.on');
    if (on && on.scrollIntoView) on.scrollIntoView({ inline: 'center', block: 'nearest' });
})();
