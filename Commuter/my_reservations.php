<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

/* AUTO EXPIRE */
mbus_db_prepare($conn, "
    UPDATE reservation
    SET status='Cancelled'
    WHERE status='Pending'
    AND expires_at <= NOW()
", []);

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* RESERVATIONS - prepared statement */
$sql = mbus_db_prepare($conn, "
    SELECT
        reservation.reservation_id,
        reservation.schedule_id,
        reservation.seat_number,
        reservation.pickup_location,
        reservation.destination,
        reservation.status,
        reservation.created_at,
        reservation.expires_at,
        schedule.departure_time,
        schedule.arrival_time,
        schedule.trip_status,
        schedule.schedule_status,
        routes.origin,
        routes.destination AS route_destination,
        buses.bus_number
    FROM reservation
    JOIN schedule ON reservation.schedule_id = schedule.schedule_id
    JOIN routes ON schedule.route_id = routes.route_id
    JOIN buses ON schedule.bus_id = buses.bus_id
    WHERE reservation.user_id = ?
    ORDER BY reservation.reservation_id DESC
", [$user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title>My Reservations</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/my_reservation.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-filters.css">
    <link rel="stylesheet" href="../Assets/css/direction_tabs.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=2">
    <link rel="stylesheet" href="../Assets/css/commuter_modern.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/commuter_modern.css'); ?>">
</head>

<body class="has-bottom-nav cm">

<main class="main" role="main">

<header class="page-header">
    <h1>My Reservations</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<?php if(isset($_GET['ref_sent'])): ?>
<div class="ref-sent-note" role="status"><strong>Reference number sent.</strong> An operator will confirm your payment shortly. Your ticket appears here once it&rsquo;s confirmed.</div>
<?php endif; ?>

<?php
$all = [];
while($sql !== false && ($r0 = mbus_db_fetch_assoc($sql))) $all[] = $r0;
/* one entry per trip; keeps the newest-first order */
$groups = [];
foreach($all as $r0){ $groups[(int)$r0['schedule_id']][] = $r0; }

/* Sort every trip into Active / History / Cancelled so the rider can filter instead of scrolling through old trips. */
$cats = []; $catCount = ['active' => 0, 'history' => 0, 'cancelled' => 0];
foreach($groups as $sid => $rows0){
    $f0 = $rows0[0];
    $sts = array_column($rows0, 'status');
    $ended = in_array((string)($f0['trip_status'] ?? ''), ['Arrived', 'Completed'], true)
        || in_array((string)($f0['schedule_status'] ?? ''), ['Completed', 'Cancelled'], true)
        || strtotime((string)$f0['arrival_time']) < time() - 3 * 3600;
    if(!array_diff($sts, ['Cancelled'])) $cat = 'cancelled';
    elseif($ended) $cat = 'history';
    elseif(array_intersect($sts, ['Pending', 'Paid', 'Boarded'])) $cat = 'active';
    else $cat = 'history';
    $cats[$sid] = $cat; $catCount[$cat]++;
}

$aw_stmt = $conn->pdo->prepare("SELECT COALESCE(NULLIF(reference_number, ''), '') AS ref FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? AND status = 'Submitted' LIMIT 1");
$seat_actions = function($row, $awaiting, $remaining){ ?>
    <?php if($row['status'] === "Pending" && $awaiting){ ?>
    <div class="reservation-actions btn-group">
        <a class="btn btn-outline" href="payment_return.php?schedule_id=<?php echo (int)$row['schedule_id']; ?>"><i class="fa fa-hourglass-half" aria-hidden="true"></i> <?php echo $awaiting === 'approval' ? 'Waiting for approval' : 'Waiting for GCash payment'; ?></a>
        <button type="button" class="btn btn-outline cancel-btn" onclick="cancelReservation(<?php echo (int)$row['reservation_id']; ?>, this)" aria-label="Cancel reservation for seat <?php echo htmlspecialchars($row['seat_number']); ?>"><i class="fa fa-times" aria-hidden="true"></i> Cancel</button>
    </div>
    <?php } elseif($row['status'] === "Pending"){ ?>
    <div class="reservation-timer"><span class="timer-label">Time Remaining:</span> <span class="timer-value countdown" data-seconds="<?php echo $remaining; ?>"><?php echo gmdate("i:s", $remaining); ?></span></div>
    <div class="reservation-actions btn-group">
        <a class="btn btn-primary" href="payment.php?schedule_id=<?php echo (int)$row['schedule_id']; ?>"><i class="fa fa-credit-card" aria-hidden="true"></i> Pay Now</a>
        <button type="button" class="btn btn-outline cancel-btn" onclick="cancelReservation(<?php echo (int)$row['reservation_id']; ?>, this)" aria-label="Cancel reservation for seat <?php echo htmlspecialchars($row['seat_number']); ?>"><i class="fa fa-times" aria-hidden="true"></i> Cancel</button>
    </div>
    <?php } elseif($row['status'] === "Paid"){ ?>
    <div class="reservation-actions btn-group">
        <a class="btn btn-success" href="ticket.php?reservation_id=<?php echo (int)$row['reservation_id']; ?>"><i class="fa fa-ticket" aria-hidden="true"></i> View Ticket</a>
    </div>
    <?php } elseif($row['status'] === "Cancelled"){ ?>
    <div class="reservation-actions"><div class="status-badge status-cancelled">Reservation Cancelled</div></div>
    <?php } ?>
<?php };
?>
<?php if($groups){ ?>
    <!-- Same look as the Schedule page: day pills + a segmented status switch -->
    <div class="date-filter-container">
        <nav class="date-filters" id="tfDays" data-today="<?php echo date('Y-m-d'); ?>" aria-label="Trip dates">
            <a href="#" class="date-filter-btn active" data-range="all" role="button"><i class="fa fa-list" aria-hidden="true"></i>All dates</a>
            <a href="#" class="date-filter-btn" data-range="today" role="button"><i class="fa fa-calendar-day" aria-hidden="true"></i>Today</a>
            <a href="#" class="date-filter-btn" data-range="yesterday" role="button"><i class="fa fa-calendar-minus" aria-hidden="true"></i>Yesterday</a>
            <a href="#" class="date-filter-btn" data-range="thisweek" role="button"><i class="fa fa-calendar-week" aria-hidden="true"></i>This Week</a>
            <a href="#" class="date-filter-btn" data-range="lastweek" role="button"><i class="fa fa-calendar-alt" aria-hidden="true"></i>Last Week</a>
            <a href="#" class="date-filter-btn" data-range="month" role="button"><i class="fa fa-calendar" aria-hidden="true"></i>This Month</a>
            <a href="#" class="date-filter-btn" data-range="lastmonth" role="button"><i class="fa fa-calendar-check" aria-hidden="true"></i>Last Month</a>
        </nav>
    </div>
    <div class="dir-tabs trip-status" role="tablist" aria-label="Trip status">
        <button type="button" class="dir-tab" role="tab" data-filter="active" aria-selected="false">Active <span class="dir-count"><?php echo (int)$catCount['active']; ?></span></button>
        <button type="button" class="dir-tab" role="tab" data-filter="history" aria-selected="false">History <span class="dir-count"><?php echo (int)$catCount['history']; ?></span></button>
        <button type="button" class="dir-tab" role="tab" data-filter="cancelled" aria-selected="false">Cancelled <span class="dir-count"><?php echo (int)$catCount['cancelled']; ?></span></button>
    </div>
    <div class="card empty-state tf-empty" id="tfEmpty" role="status" hidden>
        <i class="fa fa-ticket" aria-hidden="true" style="font-size: var(--fs-3xl); color: var(--color-text-muted); margin-bottom: var(--space-4);"></i>
        <h3 id="tfEmptyText">No trips here.</h3>
    </div>
    <?php foreach($groups as $sid => $rows){
        $first = $rows[0];
        $cat = $cats[$sid];
        $tripDate = date('Y-m-d', strtotime((string)$first['departure_time']));
        $tripIsCompleted = (in_array((string)($first['trip_status'] ?? ''), ['Arrived'], true) || (string)($first['schedule_status'] ?? '') === 'Completed')
            && in_array('Boarded', array_column($rows, 'status'), true);
        $info = [];
        foreach($rows as $k => $row){
            $remaining = 0; $awaiting = false;
            if($row['status'] === "Pending"){
                $remaining = max(0, strtotime((string)$row['expires_at']) - time());
                $aw_stmt->execute([$_SESSION['user_id'], $row['schedule_id']]);
                $aw_row = $aw_stmt->fetch(PDO::FETCH_ASSOC);
                $awaiting = $aw_row === false ? false : ($aw_row['ref'] !== '' ? 'approval' : 'payment');
            }
            $info[$k] = [$remaining, $awaiting];
        }
        if(count($rows) === 1){ $row = $first; [$remaining, $awaiting] = $info[0]; ?>
    <article class="card reservation-card" data-cat="<?php echo $cat; ?>" data-date="<?php echo $tripDate; ?>" data-reservation-id="<?php echo (int)$row['reservation_id']; ?>" data-schedule-id="<?php echo (int)$row['schedule_id']; ?>">
    <div class="reservation-header">
        <div class="bus-info"><span class="badge badge-primary">Bus <?php echo htmlspecialchars($row['bus_number']); ?></span></div>
        <div class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></div>
    </div>
    <div class="trip-completion-confirmation" data-trip-completion role="status" aria-live="polite"<?php echo $tripIsCompleted ? '' : ' hidden'; ?>>
        <i class="fa fa-circle-check" aria-hidden="true"></i>
        <span><strong>Trip completed.</strong> Thank you for riding with MBUS.</span>
    </div>
    <div class="reservation-route"><i class="fa fa-map-marker-alt" aria-hidden="true"></i> <span><?php echo htmlspecialchars($row['origin']); ?> &#8594; <?php echo htmlspecialchars($row['route_destination']); ?></span></div>
    <ul class="reservation-details">
        <li><strong>Seat:</strong> <?php echo htmlspecialchars($row['seat_number']); ?></li>
        <li><strong>Trip:</strong> <?php echo htmlspecialchars($row['pickup_location']); ?> &#8594; <?php echo htmlspecialchars($row['destination']); ?></li>
        <li><strong>Departure:</strong> <?php echo date("F d, Y g:i A", strtotime($row['departure_time'])); ?></li>
        <li><strong>Expected Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($row['arrival_time'])); ?></li>
    </ul>
    <?php $seat_actions($row, $awaiting, $remaining); ?>
    </article>
    <?php } else {
        $counts = array_count_values(array_column($rows, 'status'));
        $summary = [];
        foreach($counts as $st => $n) $summary[] = $n . ' ' . strtolower($st);
        $seatNums = array_column($rows, 'seat_number'); $seatList = count($seatNums) > 6 ? implode(', ', array_slice($seatNums, 0, 6)) . '…' : implode(', ', $seatNums); ?>
    <div class="reservation-group" data-cat="<?php echo $cat; ?>" data-date="<?php echo $tripDate; ?>" data-schedule-id="<?php echo (int)$sid; ?>">
    <div class="trip-completion-confirmation" data-trip-completion role="status" aria-live="polite"<?php echo $tripIsCompleted ? '' : ' hidden'; ?>>
        <i class="fa fa-circle-check" aria-hidden="true"></i>
        <span><strong>Trip completed.</strong> Thank you for riding with MBUS.</span>
    </div>
    <?php
        $pendingRows = array_values(array_filter(array_keys($rows), function($k) use($rows){ return $rows[$k]['status'] === 'Pending'; }));
        $paidCount = count(array_filter($rows, function($r){ return in_array($r['status'], ['Paid','Boarded'], true); }));
        $grpAwait = false; $grpRemaining = 0;
        foreach($pendingRows as $k){ if($info[$k][1]){ $grpAwait = $info[$k][1]; } $grpRemaining = max($grpRemaining, $info[$k][0]); }
    ?>
    <article class="card reservation-card res-group">
        <div class="reservation-header">
            <div class="bus-info"><span class="badge badge-primary">Bus <?php echo htmlspecialchars($first['bus_number']); ?></span></div>
            <div class="rg-statuses"><?php foreach($counts as $st => $n){ ?><span class="status-badge status-<?php echo strtolower($st); ?>"><?php echo (int)$n . ' ' . htmlspecialchars($st); ?></span><?php } ?></div>
        </div>
        <div class="reservation-route"><i class="fa fa-map-marker-alt" aria-hidden="true"></i> <span><?php echo htmlspecialchars($first['origin']); ?> &#8594; <?php echo htmlspecialchars($first['route_destination']); ?></span></div>
        <ul class="reservation-details">
            <li><strong>Seats:</strong> <?php echo htmlspecialchars($seatList); ?> (<?php echo count($rows); ?>)</li>
            <li><strong>Departure:</strong> <?php echo date("F d, Y g:i A", strtotime($first['departure_time'])); ?></li>
            <li><strong>Expected Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($first['arrival_time'])); ?></li>
        </ul>
        <?php if($pendingRows){ ?>
        <div class="rg-bar">
            <?php if($grpAwait){ ?>
                <a class="rg-pill rg-wait" href="payment_return.php?schedule_id=<?php echo (int)$sid; ?>"><i class="fa fa-hourglass-half" aria-hidden="true"></i> <?php echo $grpAwait === 'approval' ? 'Waiting for approval' : 'Waiting for GCash payment'; ?></a>
            <?php } else { ?>
                <span class="rg-time"><i class="fa fa-clock" aria-hidden="true"></i> <span class="countdown" data-seconds="<?php echo $grpRemaining; ?>"><?php echo gmdate("i:s", $grpRemaining); ?></span> left</span>
                <a class="btn btn-primary rg-pay" href="payment.php?schedule_id=<?php echo (int)$sid; ?>"><i class="fa fa-credit-card" aria-hidden="true"></i> Pay now</a>
            <?php } ?>
        </div>
        <?php } ?>
        <?php if($paidCount){ ?>
        <div class="rg-bar"><a class="btn btn-success rg-pay" href="ticket.php?schedule_id=<?php echo (int)$sid; ?>"><i class="fa fa-ticket" aria-hidden="true"></i> Show ticket<?php echo $paidCount > 1 ? 's' : ''; ?></a></div>
        <?php } ?>
        <details class="rg-more">
            <summary><span class="rg-more-open">Show more information</span><span class="rg-more-close">Hide information</span><i class="fa fa-chevron-down rg-chev" aria-hidden="true"></i></summary>
            <ul class="rg-list">
            <?php foreach($rows as $k => $row){ ?>
                <li class="rg-row" data-reservation-id="<?php echo (int)$row['reservation_id']; ?>">
                    <span class="rg-seat-no">Seat <?php echo htmlspecialchars($row['seat_number']); ?></span>
                    <span class="rg-stop"><?php echo htmlspecialchars($row['pickup_location']); ?> &#8594; <?php echo htmlspecialchars($row['destination']); ?></span>
                    <span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                    <?php if($row['status'] === 'Pending'){ ?>
                    <button type="button" class="rg-x cancel-btn" onclick="cancelReservation(<?php echo (int)$row['reservation_id']; ?>, this)" aria-label="Cancel seat <?php echo htmlspecialchars($row['seat_number']); ?>"><i class="fa fa-times" aria-hidden="true"></i></button>
                    <?php } elseif($row['status'] === 'Paid'){ ?>
                    <a class="rg-x" href="ticket.php?reservation_id=<?php echo (int)$row['reservation_id']; ?>" aria-label="Ticket for seat <?php echo htmlspecialchars($row['seat_number']); ?>"><i class="fa fa-ticket" aria-hidden="true"></i></a>
                    <?php } else { ?><span class="rg-x rg-x-empty"></span><?php } ?>
                </li>
            <?php } ?>
            </ul>
        </details>
    </article>
    </div>
    <?php } } ?>

<?php }else{ ?>

<div class="card empty-state" role="status">
    <i class="fa fa-ticket" aria-hidden="true" style="font-size: var(--fs-3xl); color: var(--color-text-muted); margin-bottom: var(--space-4);"></i>
    <h3>No reservations yet.</h3>
    <p>Reserve a seat on an upcoming bus and it will show up here.</p>
    <a href="schedule.php" class="btn btn-primary" style="margin-top: var(--space-4);">
        <i class="fa fa-bus" aria-hidden="true"></i> Reserve a Bus
    </a>
</div>

<?php } ?>

</main>

<script>
// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');

    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');

    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen);
    }

    // Add/remove body class for CSS to hide hamburger
    document.body.classList.toggle('sidebar-open', isOpen);

    if (isOpen) {
        document.body.style.overflow = 'hidden';
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    } else {
        document.body.style.overflow = '';
    }
}

// Close sidebar when clicking a nav link on mobile
document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// Handle keyboard navigation for sidebar
document.addEventListener('keydown', function(e) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});

// Countdown timers for pending reservations
function startCountdowns() {
    document.querySelectorAll('.countdown').forEach(el => {
        let seconds = parseInt(el.dataset.seconds, 10);
        if (isNaN(seconds) || seconds <= 0) return;

        const update = () => {
            if (seconds <= 0) {
                el.textContent = 'Expired';
                el.classList.add('expired');
                // Reload page to show cancelled status
                setTimeout(() => location.reload(), 1000);
                return;
            }
            const m = String(Math.floor(seconds / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            el.textContent = m + ':' + s;
            seconds--;
        };
        update();
        setInterval(update, 1000);
    });
}

// Cancel reservation via AJAX
function cancelReservation(reservationId, btn) {
    if (!confirm('Cancel this reservation?')) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = btn.classList.contains('rg-x') ? '<i class="fa fa-spinner fa-spin"></i>' : '<i class="fa fa-spinner fa-spin"></i> Cancelling...';

    // Get CSRF token from a hidden input or meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    fetch('cancel_reservation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'reservation_id=' + reservationId + '&csrf_token=' + csrfToken
    })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === 'ok') {
            showToast('Reservation cancelled');
            // A group checkout may cancel several seats at once; reload all rows.
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Failed: ' + resp);
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    })
    .catch(() => {
        alert('Network error');
        btn.disabled = false;
        btn.innerHTML = origHtml;
    });
}

// Simple toast notification
function showToast(message) {
    // Remove existing toast
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => toast.classList.add('show'));

    // Remove after 3s
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Init
document.addEventListener('DOMContentLoaded', startCountdowns);

// Keep the passenger's Trips page in sync with operator arrival updates.
async function refreshTripCompletion() {
    const cards = document.querySelectorAll('[data-schedule-id]');
    if (!cards.length) return;
    try {
        const response = await fetch('../api/commuter_trip_status.php', { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) return;
        const data = await response.json();
        if (!data || data.ok !== true || !data.trips) return;
        cards.forEach(card => {
            const status = data.trips[String(card.dataset.scheduleId)];
            const notice = card.querySelector('[data-trip-completion]');
            if (notice && status === 'Completed') notice.hidden = false;
        });
    } catch (error) {
        // A temporary status refresh failure should not interrupt reservation actions.
    }
}
refreshTripCompletion();
setInterval(refreshTripCompletion, 15000);

/* Trip filters: day pills (All dates / Today / Yesterday / This Week / Last Week / This Month / Last Month)
   combined with the Active / History / Cancelled switch. Instant, no reload; the counts follow the day pill. */
(function () {
    const tabs = Array.from(document.querySelectorAll('.trip-status .dir-tab'));
    const days = document.getElementById('tfDays');
    if (!tabs.length || !days) return;
    const pills = Array.from(days.querySelectorAll('.date-filter-btn'));
    const items = Array.from(document.querySelectorAll('[data-cat]'));
    const empty = document.getElementById('tfEmpty');
    const emptyText = document.getElementById('tfEmptyText');
    const today = days.dataset.today;
    const messages = {
        active: 'No active trips right now.',
        history: 'No past trips yet.',
        cancelled: 'No cancelled trips.'
    };
    const pad = n => String(n).padStart(2, '0');
    const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const parts = s => s.split('-').map(Number);
    const shift = (s, n) => { const p = parts(s); return ymd(new Date(p[0], p[1] - 1, p[2] + n)); };
    const monthOf = (s, offset) => { const p = parts(s); const d = new Date(p[0], p[1] - 1 + offset, 1); return d.getFullYear() + '-' + pad(d.getMonth() + 1); };
    // Weeks run Monday to Sunday, like the Schedule page.
    const weekStart = s => { const p = parts(s); const dow = (new Date(p[0], p[1] - 1, p[2]).getDay() + 6) % 7; return shift(s, -dow); };
    let status = 'active';
    let range = 'all';

    function inRange(date) {
        if (range === 'all') return true;
        if (!date) return false;
        if (range === 'today') return date === today;
        if (range === 'yesterday') return date === shift(today, -1);
        if (range === 'thisweek') { const w = weekStart(today); return date >= w && date <= shift(w, 6); }
        if (range === 'lastweek') { const w = shift(weekStart(today), -7); return date >= w && date <= shift(w, 6); }
        if (range === 'month') return date.slice(0, 7) === monthOf(today, 0);
        if (range === 'lastmonth') return date.slice(0, 7) === monthOf(today, -1);
        return true;
    }
    const matches = (el, st) => el.dataset.cat === st && inRange(el.dataset.date || '');
    const countOf = st => items.filter(el => matches(el, st)).length;

    function apply() {
        let shown = 0;
        items.forEach(el => {
            const show = matches(el, status);
            el.hidden = !show;
            if (show) shown++;
        });
        tabs.forEach(tab => {
            const on = tab.dataset.filter === status;
            tab.classList.toggle('on', on);
            tab.setAttribute('aria-selected', String(on));
            const n = tab.querySelector('.dir-count');
            if (n) n.textContent = countOf(tab.dataset.filter);
        });
        if (empty) {
            empty.hidden = shown > 0;
            if (emptyText) emptyText.textContent = range !== 'all' ? 'No trips for this day.' : (messages[status] || 'No trips here.');
        }
        try { sessionStorage.setItem('mbusTripFilter', status); } catch (e) { /* storage unavailable */ }
    }

    tabs.forEach(tab => tab.addEventListener('click', () => { status = tab.dataset.filter; apply(); }));
    pills.forEach(pill => pill.addEventListener('click', e => {
        e.preventDefault();
        range = pill.dataset.range;
        pills.forEach(p => {
            const on = p === pill;
            p.classList.toggle('active', on);
            p.setAttribute('aria-current', on ? 'page' : 'false');
        });
        apply();
    }));

    try {
        const saved = sessionStorage.getItem('mbusTripFilter');
        if (saved && messages[saved]) status = saved;
    } catch (e) { /* ignore */ }
    if (countOf(status) === 0) {
        status = ['active', 'history', 'cancelled'].find(f => countOf(f) > 0) || 'active';
    }
    apply();
})();
</script>

<style>
/* Toast notification - mobile optimized */
.toast {
    position: fixed;
    bottom: var(--space-4);
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: var(--color-text);
    color: var(--color-bg);
    padding: var(--space-3) var(--space-5);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    z-index: var(--z-toast);
    font-size: var(--fs-sm);
    font-weight: 500;
    opacity: 0;
    transition: transform var(--transition-normal), opacity var(--transition-normal);
    max-width: calc(100vw - var(--space-8));
    text-align: center;
}

.toast.show {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}

.countdown.expired {
    color: var(--color-error);
    font-weight: 700;
}

/* Trip filters reuse the Schedule page styles (date pills + dir-tabs); only the status switch has 3 columns here */
[data-cat][hidden], .tf-empty[hidden] { display: none !important; }
.dir-tabs.trip-status { grid-template-columns: repeat(3, 1fr); }
.dir-tabs.trip-status .dir-tab { padding: 0 4px; gap: 5px; }

/* Mobile reservation card improvements */
@media (max-width: 768px) {
    .reservation-card {
        padding: var(--space-4);
    }

    .reservation-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-2);
    }

    .reservation-timer {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        padding: var(--space-2) var(--space-3);
        background: var(--color-warning-light);
        border-radius: var(--radius-md);
        margin-top: var(--space-3);
    }

    .timer-label {
        font-size: var(--fs-xs);
        font-weight: 600;
        color: var(--color-warning-dark);
    }

    .timer-value {
        font-family: var(--font-mono);
        font-size: var(--fs-lg);
        font-weight: 700;
        color: var(--color-warning-dark);
    }

    .reservation-actions {
        margin-top: var(--space-3);
    }

    .btn-group {
        flex-direction: column;
        width: 100%;
    }

    .btn-group .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<!-- Bottom Navigation -->
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i>
        <span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i>
        <span>Schedule</span>
    </a>
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i>
        <span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>

<script src="../Assets/js/app-header.js"></script>
</body>
</html>
