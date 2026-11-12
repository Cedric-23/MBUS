<?php
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

/* Load app config so date() uses the app timezone (Asia/Manila), not the server's UTC default */
require_once __DIR__ . "/../config/app.php";
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

/* NEXT TRIP: soonest upcoming booking (paid, on board, or a pending one still inside its 10 minutes) */
$next = null;
$q = mbus_db_prepare($conn, "
    SELECT r.schedule_id, r.seat_number, r.status, r.pickup_location, r.destination,
           s.departure_time, s.arrival_time, b.bus_number, rt.origin, rt.destination AS route_destination
    FROM reservation r
    JOIN schedule s ON s.schedule_id = r.schedule_id
    JOIN buses b ON b.bus_id = s.bus_id
    JOIN routes rt ON rt.route_id = s.route_id
    WHERE r.user_id = ?
      AND (r.status IN ('Paid','Confirmed','Boarded') OR (r.status = 'Pending' AND r.created_at > NOW() - INTERVAL '10 minutes'))
      AND s.schedule_status = 'Active'
      AND s.trip_status IN ('Scheduled','Boarding','Departed')
      AND COALESCE(s.arrival_time, s.departure_time + INTERVAL '4 hours') >= ?
    ORDER BY s.departure_time ASC, r.seat_number ASC", [$user_id, $bounds['now']]);
while($q !== false && ($r = mbus_db_fetch_assoc($q))){
    if($next === null){ $next = $r; $next['seats'] = []; $next['pending'] = false; }
    if($r['schedule_id'] != $next['schedule_id']) break;
    $next['seats'][] = $r['seat_number'];
    if($r['status'] === 'Pending') $next['pending'] = true;
    if($r['status'] === 'Boarded') $next['boarded'] = true;
}

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
        AND (status <> 'Pending' OR created_at > NOW() - INTERVAL '10 minutes')", [(int)$r['schedule_id']]);
    $cr = $c === false ? false : mbus_db_fetch_assoc($c);
    $r['available'] = max(0, (int)$r['capacity'] - (int)($cr['n'] ?? 0));
    $r['action'] = mbus_schedule_action($r, $r['available']);
    if($r['action'] !== 'unavailable') $soon[] = $r;
}

function mbus_home_when(string $ts): string {
    $t = strtotime($ts);
    $day = date('Y-m-d', $t);
    $label = $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D, M j', $t));
    return $label . ' · ' . date('g:i A', $t);
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
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>

<body class="home-page has-bottom-nav">
<main class="main" role="main">

    <header class="home-top">
        <div>
            <p class="home-hello"><?php echo $greeting; ?></p>
            <h1><?php echo htmlspecialchars($first_name); ?></h1>
        </div>
        <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="waiting.php" role="menuitem"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
    </header>

    <?php if($next){
        $route = ($next['pickup_location'] ?: $next['origin']) . ' to ' . ($next['destination'] ?: $next['route_destination']);
        $seat_label = count($next['seats']) > 1 ? 'Seats ' : 'Seat ';
        if(!empty($next['boarded'])){ $state = 'On board'; $href = 'ticket.php?schedule_id=' . (int)$next['schedule_id']; $cta = 'View ticket'; }
        elseif($next['pending']){ $state = 'Waiting for payment'; $href = 'payment.php?schedule_id=' . (int)$next['schedule_id']; $cta = 'Pay with GCash'; }
        else { $state = 'Ready to board'; $href = 'ticket.php?schedule_id=' . (int)$next['schedule_id']; $cta = 'Show ticket'; }
    ?>
    <section class="next-trip" aria-label="Your next trip">
        <div class="next-trip-top">
            <span class="next-state<?php echo $next['pending'] ? ' is-pending' : ''; ?>"><?php echo $state; ?></span>
            <span class="next-when"><?php echo htmlspecialchars(mbus_home_when($next['departure_time'])); ?></span>
        </div>
        <p class="next-route"><?php echo htmlspecialchars($route); ?></p>
        <p class="next-meta"><?php echo htmlspecialchars($next['bus_number']); ?> &middot; <?php echo count($next['seats']) > 4 ? count($next['seats']) . ' seats' : $seat_label . htmlspecialchars(implode(', ', $next['seats'])); ?></p>
        <a class="next-cta" href="<?php echo $href; ?>"><?php echo $cta; ?></a>
    </section>
    <?php } else { ?>
    <section class="next-trip is-empty" aria-label="Book a trip">
        <p class="next-route">Where to next?</p>
        <p class="next-meta">Pick a bus and reserve your seat in a few taps.</p>
        <a class="next-cta" href="schedule.php">Find a bus</a>
    </section>
    <?php } ?>

    <section class="home-section" aria-labelledby="soon-h">
        <div class="home-section-head">
            <h2 id="soon-h">Leaving soon</h2>
            <a href="schedule.php?filter=today">See all</a>
        </div>
        <?php if(!$soon){ ?>
            <p class="home-empty">No open departures right now. Check tomorrow's schedule.</p>
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
                        <small><?php echo htmlspecialchars($s['bus_number']); ?> &middot; <?php echo date('Y-m-d', strtotime($s['departure_time'])) === date('Y-m-d') ? 'Today' : 'Tomorrow'; ?></small>
                    </span>
                    <span class="seat-chip <?php echo $cls; ?>"><?php echo $chip; ?></span>
                </a>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
        <div class="day-chips" aria-label="Browse by day">
            <a href="schedule.php?filter=today">Today</a>
            <a href="schedule.php?filter=tomorrow">Tomorrow</a>
            <a href="schedule.php?filter=thisweek">This week</a>
            <a href="schedule.php?filter=nextweek">Next week</a>
        </div>
    </section>

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
</body>
</html>
