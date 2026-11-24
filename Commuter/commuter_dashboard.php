<?php
require_once __DIR__ . "/../config/app.php";
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";
require_once __DIR__ . "/../Includes/schedule_booking.php";

$user_id = (int)$_SESSION['user_id'];
$bounds = mbus_local_bounds();
$first_name = explode(' ', trim($_SESSION['full_name'] ?? 'there'))[0];
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$passenger_type = mbus_passenger_type($conn, $user_id);

/* Which pending seats are already paid and waiting for an operator to approve them?
   A GCash checkout with a reference number = waiting for approval; one without = still at the GCash step. */
$await_by_res = [];
$await_by_sched = [];
$cq = mbus_db_prepare($conn, "SELECT schedule_id, reservation_ids, COALESCE(NULLIF(reference_number, ''), '') AS ref
    FROM paymongo_checkout WHERE user_id = ? AND status = 'Submitted'", [$user_id]);
while($cq !== false && ($c = mbus_db_fetch_assoc($cq))){
    $pstate = $c['ref'] !== '' ? 'approval' : 'checkout';
    $ids = json_decode((string)$c['reservation_ids'], true);
    if(is_array($ids) && $ids){
        foreach($ids as $id){ if(is_scalar($id)) $await_by_res[(int)$id] = $pstate; }
    } else {
        $csid = (int)$c['schedule_id'];
        if(!isset($await_by_sched[$csid]) || $pstate === 'approval') $await_by_sched[$csid] = $pstate;
    }
}

/* NEXT TRIP: soonest upcoming booking (paid, on board, or a pending one still inside its 10 minutes) */
$next = null;
$q = mbus_db_prepare($conn, "
    SELECT r.reservation_id, r.schedule_id, r.seat_number, r.status, r.pickup_location, r.destination,
           s.departure_time, s.arrival_time, b.bus_number, rt.origin, rt.destination AS route_destination
    FROM reservation r
    JOIN schedule s ON s.schedule_id = r.schedule_id
    JOIN buses b ON b.bus_id = s.bus_id
    JOIN routes rt ON rt.route_id = s.route_id
    WHERE r.user_id = ?
      AND (r.status IN ('Paid','Confirmed','Boarded') OR (r.status = 'Pending' AND r.expires_at > NOW()))
      AND s.schedule_status = 'Active'
      AND s.trip_status IN ('Scheduled','Boarding','Departed')
      AND COALESCE(s.arrival_time, s.departure_time + INTERVAL '4 hours') >= ?
    ORDER BY s.departure_time ASC, r.seat_number ASC", [$user_id, $bounds['now']]);
$groups = [];
while($q !== false && ($r = mbus_db_fetch_assoc($q))){
    $sid = (int)$r['schedule_id'];
    if(!isset($groups[$sid])){ $groups[$sid] = $r; $groups[$sid]['seats'] = []; $groups[$sid]['pending'] = false; $groups[$sid]['boarded'] = false; $groups[$sid]['stops'] = []; $groups[$sid]['riders'] = []; $groups[$sid]['n_unpaid'] = 0; $groups[$sid]['n_checkout'] = 0; $groups[$sid]['n_approval'] = 0; }
    $groups[$sid]['seats'][] = $r['seat_number'];
    /* payment state of this seat: unpaid | checkout (at the GCash step) | approval (paid, waiting for the operator) | paid | boarded */
    $pay = 'paid';
    if($r['status'] === 'Pending'){
        $pay = $await_by_res[(int)$r['reservation_id']] ?? ($await_by_sched[$sid] ?? 'unpaid');
        if($pay === 'unpaid') $groups[$sid]['n_unpaid']++;
        elseif($pay === 'checkout') $groups[$sid]['n_checkout']++;
        else $groups[$sid]['n_approval']++;
    } elseif($r['status'] === 'Boarded'){
        $pay = 'boarded';
    }
    /* one entry per seat: its own pickup and drop-off */
    $groups[$sid]['riders'][] = [
        'seat' => $r['seat_number'],
        'pickup' => (string)($r['pickup_location'] ?: $r['origin']),
        'drop' => (string)($r['destination'] ?: $r['route_destination']),
        'pay' => $pay,
    ];
    $groups[$sid]['stops'][(string)($r['pickup_location'] ?: $r['origin']) . '>' . (string)($r['destination'] ?: $r['route_destination'])] = true;
    if($r['status'] === 'Pending') $groups[$sid]['pending'] = true;
    if($r['status'] === 'Boarded') $groups[$sid]['boarded'] = true;
}
$groups = array_values($groups);
$next = $groups[0] ?? null;
$others = array_slice($groups, 1);

/* LEAVING SOON: next few departures that are still open */
$soon = [];
$q = mbus_db_prepare($conn, "
    SELECT s.schedule_id, s.departure_time, s.arrival_time, s.schedule_status, s.trip_status,
           b.bus_number, b.capacity, rt.origin, rt.destination
    FROM schedule s
    JOIN buses b ON b.bus_id = s.bus_id
    JOIN routes rt ON rt.route_id = s.route_id
    WHERE s.schedule_status = 'Active' AND s.trip_status IN ('Scheduled','Boarding')
      AND s.departure_time >= ? AND s.departure_time < ?
    ORDER BY s.departure_time ASC LIMIT 4", [$bounds['now'], $bounds['day_after']]);
while($q !== false && ($r = mbus_db_fetch_assoc($q))){
    $c = mbus_db_prepare($conn, "SELECT COUNT(*) AS n FROM reservation WHERE schedule_id = ?
        AND status IN ('Pending','Paid','Confirmed','Boarded')
        AND (status <> 'Pending' OR expires_at > NOW())", [(int)$r['schedule_id']]);
    $cr = $c === false ? false : mbus_db_fetch_assoc($c);
    $r['available'] = max(0, (int)$r['capacity'] - (int)($cr['n'] ?? 0));
    $r['action'] = mbus_schedule_action($r, $r['available']);
    if($r['action'] !== 'unavailable') $soon[] = $r;
}

function mbus_home_when(string $ts): string {
    $t = strtotime($ts);
    return date('g:i A', $t);
}

/* "2" on its own reads like a seat count: call it Bus 2 (names like BUS-103 stay as they are) */
function mbus_home_bus(string $n): string {
    return preg_match('/^bus/i', $n) ? $n : 'Bus ' . $n;
}

/* what to show for one seat's payment state */
function mbus_home_pay_tag(string $pay): string {
    return ['approval' => 'Paid, waiting for approval', 'checkout' => 'Finish your GCash payment', 'unpaid' => 'Not paid yet', 'paid' => 'Paid', 'boarded' => 'On board'][$pay] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token()); ?>">
    <title>Home - MBUS</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/commuter_modern.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/commuter_modern.css'); ?>">
    <style>
        /* multi reservation: one row per seat with its own pickup and drop-off */
        .next-riders-head, .next-riders li { display: grid; grid-template-columns: 42px minmax(0, 1fr) minmax(0, 1fr); gap: 8px; align-items: center; }
        .next-riders-head { margin: 12px 0 6px; padding: 0 10px; font-size: .7rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .78; }
        .next-riders { margin: 0 0 2px; padding: 0; list-style: none; display: grid; gap: 6px; }
        .next-riders li { padding: 8px 10px; border-radius: 12px; background: rgba(255, 255, 255, .16); font-size: .8rem; line-height: 1.25; }
        .next-riders li span { overflow-wrap: anywhere; }
        .next-riders .nr-seat { display: grid; place-items: center; height: 28px; border-radius: 9px; background: #fff; color: #2f6fe4; font-size: .85rem; font-weight: 700; }
        .next-riders .nr-tag { grid-column: 2 / -1; font-style: normal; font-size: .72rem; font-weight: 600; opacity: .92; }
        .next-riders .nr-tag.is-unpaid { color: #ffe3a3; opacity: 1; }
        .next-riders-more { margin: 6px 0 0; font-size: .8rem; opacity: .85; }
        .next-kind { display: inline-block; margin: 8px 0 0; padding: 3px 10px; border-radius: 999px; background: rgba(255, 255, 255, .2); font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .soon-sub { display: flex; align-items: center; gap: 8px; margin: 14px 2px 6px; font-size: .8rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b7a90; }
        .soon-sub span { padding: 1px 8px; border-radius: 999px; background: #e3ecfb; color: #2f6fe4; font-size: .72rem; }
    </style>
</head>

<body class="home-page has-bottom-nav cm">
<main class="main" role="main">

    <header class="home-top">
        <div>
            <p class="home-hello"><?php echo $greeting; ?></p>
            <h1><?php echo htmlspecialchars($first_name); ?></h1>
        </div>
        <div class="home-top-actions">
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
        <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="announcements.php" role="menuitem"><i class="fa fa-bullhorn" aria-hidden="true"></i> Updates</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
            </div>
    </header>

    <?php if($next){
        $seat_count = count($next['seats']);
        $is_multi = $seat_count > 1; /* one seat = single reservation, 2+ seats = multi reservation */
        /* single: the rider's own stops. multi: every seat has its own stops, so the headline is the whole trip */
        $route = $is_multi
            ? $next['origin'] . ' to ' . $next['route_destination']
            : ($next['pickup_location'] ?: $next['origin']) . ' to ' . ($next['destination'] ?: $next['route_destination']);
        $tickets_word = $is_multi ? 'tickets' : 'ticket';
        $sid_q = (int)$next['schedule_id'];
        $hint = '';
        if(!empty($next['boarded'])){ $state = 'On board'; $href = 'ticket.php?schedule_id=' . $sid_q; $cta = 'View ' . $tickets_word; }
        elseif($next['n_unpaid'] > 0){
            /* at least one seat still needs paying */
            $state = 'Waiting for payment'; $href = 'payment.php?schedule_id=' . $sid_q; $cta = 'Pay with GCash';
            if($next['n_approval'] > 0) $hint = $next['n_approval'] . ' of ' . $seat_count . ' seats already paid and waiting for approval.';
        }
        elseif($next['n_checkout'] > 0){ $state = 'Finish your payment'; $href = 'payment_return.php?schedule_id=' . $sid_q; $cta = 'Continue payment'; }
        elseif($next['n_approval'] > 0){
            /* everything is paid: the operator just has to approve it */
            $state = 'Waiting for approval'; $href = 'payment_return.php?schedule_id=' . $sid_q; $cta = 'View payment status';
            $hint = 'Your payment was sent. Your ' . $tickets_word . ' will appear here once an operator approves it.';
        }
        else { $state = 'Ready to board'; $href = 'ticket.php?schedule_id=' . $sid_q; $cta = 'Show ' . $tickets_word; }
        if($is_multi && !$next['pending']) $cta .= ' (' . $seat_count . ')';
        $show_riders = array_slice($next['riders'], 0, 4);
    ?>
    <section class="next-trip" aria-label="Your next trip">
        <div class="next-trip-top">
            <span class="next-state<?php echo $next['pending'] ? ' is-pending' : ''; ?>"><?php echo $state; ?></span>
            <span class="next-when"><?php echo htmlspecialchars(mbus_home_when($next['departure_time'])); ?></span>
        </div>
        <span class="next-kind"><?php echo $is_multi ? 'Multi reservation' : 'Single reservation'; ?></span>
        <p class="next-route"><?php echo htmlspecialchars($route); ?></p>
        <?php if($is_multi){ ?>
        <p class="next-meta"><?php echo htmlspecialchars(mbus_home_bus($next['bus_number'])); ?> &middot; <?php echo $seat_count; ?> seats reserved</p>
        <div class="next-riders-head" aria-hidden="true"><span>Seat</span><span>Pickup</span><span>Drop-off</span></div>
        <ul class="next-riders">
            <?php foreach($show_riders as $rd){ ?>
            <li><b class="nr-seat"><?php echo htmlspecialchars((string)$rd['seat']); ?></b><span><?php echo htmlspecialchars($rd['pickup']); ?></span><span><?php echo htmlspecialchars($rd['drop']); ?></span><?php if($next['pending']){ ?><em class="nr-tag is-<?php echo htmlspecialchars($rd['pay']); ?>"><?php echo htmlspecialchars(mbus_home_pay_tag($rd['pay'])); ?></em><?php } ?></li>
            <?php } ?>
        </ul>
        <?php if($seat_count > 4){ ?><p class="next-riders-more">+<?php echo $seat_count - 4; ?> more seat<?php echo $seat_count - 4 > 1 ? 's' : ''; ?> on your tickets</p><?php } ?>
        <?php } else { ?>
        <p class="next-meta"><?php echo htmlspecialchars(mbus_home_bus($next['bus_number'])); ?> &middot; Seat <?php echo htmlspecialchars((string)$next['seats'][0]); ?></p>
        <?php } ?>
        <?php if($hint !== ''){ ?><p class="next-meta"><?php echo htmlspecialchars($hint); ?></p><?php } ?>
        <a class="next-cta" href="<?php echo $href; ?>"><?php echo $cta; ?></a>
    </section>
    <?php } else { ?>
    <section class="next-trip is-empty" aria-label="Book a trip">
        <p class="next-route">Where to next?</p>
        <p class="next-meta">Pick a bus and reserve your seat in a few taps.</p>
        <a class="next-cta" href="schedule.php">Find a bus</a>
    </section>
    <?php } ?>

    <?php if($others){ ?>
    <section class="home-section" aria-labelledby="mine-h">
        <div class="home-section-head">
            <h2 id="mine-h">Your other trips</h2>
            <a href="my_reservations.php">See all</a>
        </div>
        <?php
        $groups = ['Single reservations' => [], 'Multi reservations' => []];
        foreach($others as $o){ $groups[count($o['seats']) > 1 ? 'Multi reservations' : 'Single reservations'][] = $o; }
        foreach($groups as $gTitle => $group){ if(!$group) continue; ?>
        <h3 class="soon-sub"><?php echo $gTitle; ?> <span><?php echo count($group); ?></span></h3>
        <ul class="soon-list">
            <?php foreach($group as $o){
                $oMulti = count($o['seats']) > 1;
                $oSid = (int)$o['schedule_id'];
                /* where it goes and what the chip says depends on the payment state, not just on "pending" */
                if($o['n_unpaid'] > 0){ $oHref = 'payment.php?schedule_id=' . $oSid; $oChip = 'Pay now'; $oWait = true; }
                elseif($o['n_checkout'] > 0){ $oHref = 'payment_return.php?schedule_id=' . $oSid; $oChip = 'Finish payment'; $oWait = true; }
                elseif($o['n_approval'] > 0){ $oHref = 'payment_return.php?schedule_id=' . $oSid; $oChip = 'Awaiting approval'; $oWait = true; }
                else { $oHref = 'ticket.php?schedule_id=' . $oSid; $oChip = $o['boarded'] ? 'On board' : ($oMulti ? 'Tickets' : 'Ticket'); $oWait = false; }
                $oRoute = $oMulti
                    ? $o['origin'] . ' to ' . $o['route_destination']
                    : ($o['pickup_location'] ?: $o['origin']) . ' to ' . ($o['destination'] ?: $o['route_destination']);
                $oSeats = $oMulti ? count($o['seats']) . ' seats' : 'Seat ' . $o['seats'][0];
            ?>
            <li>
                <a href="<?php echo htmlspecialchars($oHref); ?>">
                    <span class="soon-time"><?php echo date('g:i', strtotime($o['departure_time'])); ?><small><?php echo date('A', strtotime($o['departure_time'])); ?></small></span>
                    <span class="soon-body">
                        <strong><?php echo htmlspecialchars($oRoute); ?></strong>
                        <small><?php echo htmlspecialchars(mbus_home_bus($o['bus_number'])); ?> &middot; <?php echo htmlspecialchars($oSeats); ?> &middot; <?php echo htmlspecialchars(mbus_home_when($o['departure_time'])); ?></small>
                    </span>
                    <span class="seat-chip <?php echo $oWait ? 'low' : 'ok'; ?>"><?php echo $oChip; ?></span>
                </a>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
    </section>
    <?php } ?>

    <section class="home-section" aria-labelledby="soon-h">
        <div class="home-section-head">
            <h2 id="soon-h">Leaving soon</h2>
            <a href="schedule.php?filter=today">See all</a>
        </div>
        <?php if(!$soon){ ?>
            <p class="home-empty">No open departures right now.</p>
        <?php } else { ?>
        <ul class="soon-list">
            <?php foreach($soon as $s){
                $href = $s['action'] === 'reserve' ? 'reserve.php?schedule_id=' . (int)$s['schedule_id'] : 'pickup.php?schedule_id=' . (int)$s['schedule_id'];
                $chip = $s['available'] <= 0 ? 'Full' : $s['available'] . ' left';
                $cls = $s['available'] <= 0 ? 'full' : ($s['available'] <= 5 ? 'low' : 'ok');
            ?>
            <li>
                <a href="<?php echo $href; ?>">
                    <span class="soon-time"><?php echo date('g:i', strtotime($s['departure_time'])); ?><small><?php echo date('A', strtotime($s['departure_time'])); ?></small></span>
                    <span class="soon-body">
                        <strong><?php echo htmlspecialchars($s['origin'] . ' to ' . $s['destination']); ?></strong>
                        <small><?php echo htmlspecialchars(mbus_home_bus($s['bus_number'])); ?></small>
                    </span>
                    <span class="seat-chip <?php echo $cls; ?>"><?php echo $chip; ?></span>
                </a>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
    </section>

    <a class="home-perk" href="announcements.php">
        <span><strong>Service updates</strong><small>Delays, suspensions and other bus operation notices.</small></span>
        <i class="fa fa-chevron-right" aria-hidden="true"></i>
    </a>

    <?php if($passenger_type === 'Regular'){ ?>
    <a class="home-perk" href="verification.php">
        <span><strong>Student, senior or PWD?</strong><small>Verify your ID and get 20% off your fare.</small></span>
        <i class="fa fa-chevron-right" aria-hidden="true"></i>
    </a>
    <?php } else { ?>
    <p class="home-perk is-on"><strong><?php echo htmlspecialchars($passenger_type); ?> discount active</strong><small>20% off the fare on your own seat.</small></p>
    <?php } ?>

</main>

<!-- Bottom Navigation -->
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item active" aria-current="page">
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
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>

<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/notifications.js"></script>
</body>
</html>
