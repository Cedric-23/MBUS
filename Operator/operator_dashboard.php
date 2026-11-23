<?php
session_start();

if(
    !isset($_SESSION['user_type']) ||
    strtolower($_SESSION['user_type']) != 'operator'
){
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../Includes/gps_gate.php';
mbus_require_gps('Operator/operator_dashboard.php');

include("../config/db_connect.php");
include("../Includes/activity_log.php");
include("../Includes/csrf.php");

$operator_id = $_SESSION['user_id'];

/* App-timezone day boundaries, not the DB clock */
$b = mbus_local_bounds();

/* TODAY'S OPEN TRIPS (this operator's buses): running first, then by departure time */
$trips = [];
$tq = mbus_db_prepare($conn, "
SELECT s.schedule_id, s.departure_time, s.trip_status, b.bus_number, b.capacity,
       rt.origin, rt.destination,
       (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status IN ('Paid','Confirmed','Boarded')) AS booked,
       (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status = 'Boarded') AS boarded
FROM schedule s
JOIN buses b ON b.bus_id = s.bus_id
JOIN routes rt ON rt.route_id = s.route_id
JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
WHERE oba.operator_id = ?
AND s.schedule_status = 'Active'
AND s.trip_status IN ('Scheduled','Boarding','Departed')
AND s.departure_time >= ? AND s.departure_time < ?
ORDER BY (s.trip_status = 'Departed') DESC, (s.trip_status = 'Boarding') DESC, s.departure_time ASC", [$operator_id, $b['today'], $b['tomorrow']]);
while($tq !== false && ($r = mbus_db_fetch_assoc($tq))) $trips[] = $r;
$todays_trips = array_slice($trips, 0, 5);

/* TODAY STATS */
$stat = ['trips' => 0, 'booked' => 0, 'boarded' => 0, 'pending' => 0];
$sq = mbus_db_prepare($conn, "SELECT COUNT(DISTINCT s.schedule_id) AS trips,
    COUNT(CASE WHEN r.status IN ('Paid','Confirmed','Boarded') THEN 1 END) AS booked,
    COUNT(CASE WHEN r.status = 'Boarded' THEN 1 END) AS boarded,
    COUNT(CASE WHEN r.status = 'Pending' THEN 1 END) AS pending
  FROM schedule s JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
  LEFT JOIN reservation r ON r.schedule_id = s.schedule_id
  WHERE oba.operator_id = ? AND s.schedule_status = 'Active' AND s.departure_time >= ? AND s.departure_time < ?", [$operator_id, $b['today'], $b['tomorrow']]);
if ($sq !== false && ($row = mbus_db_fetch_assoc($sq))) $stat = array_map('intval', $row);

/* MY BUSES */
$my_buses = 0;
$mq = mbus_db_prepare($conn, "SELECT COUNT(*) AS n FROM operator_bus_assignments WHERE operator_id = ?", [$operator_id]);
if ($mq !== false && ($mrow = mbus_db_fetch_assoc($mq))) $my_buses = (int)$mrow['n'];

/* GCash payments waiting for a check (same count as the red dot on the profile icon) */
require_once dirname(__DIR__) . '/Includes/header_avatar.php';
$pay_pending = mbus_pending_payments_count();

$state_label = ['Scheduled' => 'Up next', 'Boarding' => 'Boarding now', 'Departed' => 'On the road'];
$greeting = (int)date('G') < 12 ? 'Good morning' : ((int)date('G') < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($_SESSION['full_name'] ?? 'there'))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">

    <title>Operator Dashboard</title>
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
    <link rel="stylesheet" href="../Assets/css/gps_status.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=2">
    <link rel="stylesheet" href="../Assets/css/operator_dashboard_extras.css?v=1">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
</head>

<body class="stf has-sidebar staff-home">
<!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
        </div>

        <h2>Operator</h2>

        <nav class="menu-top" aria-label="Operator menu">
            <a href="operator_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
            <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
            <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
            <a href="payments.php"><i class="fa fa-wallet" aria-hidden="true"></i> Payments</a>
        <a href="my_bus.php"><i class="fa fa-bus-simple" aria-hidden="true"></i> My Bus</a>
            <a href="waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>

        </nav>

        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
        </div>

    </aside>

    <!-- MAIN -->
    <main class="main" role="main"><div class="home-wrap">

        <header class="home-top">
            <div>
                <p class="home-hello"><?php echo $greeting; ?></p>
                <h1><?php echo htmlspecialchars($first_name); ?></h1>
            </div>
            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo mbus_header_avatar('../'); ?></span><?php echo mbus_payment_dot(); ?>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="profile-menu-role">Operator account</span>
                        </div>
                <a href="my_bus.php" role="menuitem"><i class="fa fa-bus-simple" aria-hidden="true"></i> My Bus</a>
                        <a href="payments.php" role="menuitem"><i class="fa fa-wallet" aria-hidden="true"></i> Payments<?php echo mbus_payment_pill(); ?></a>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="tracking-sr-only" aria-live="polite" role="status">
            <span id="operatorTrackingStatus">Operator location sharing is active.</span>
            <select id="operatorTrackingTrip" aria-label="Active trip"></select>
        </div>

        <?php if($pay_pending > 0){ ?>
        <a class="attn" href="payments.php">
            <span><strong><?php echo $pay_pending; ?> GCash <?php echo $pay_pending === 1 ? 'payment' : 'payments'; ?> to check</strong><small>Riders are waiting for their tickets until you approve.</small></span>
            <span class="attn-go">Review</span>
        </a>
        <?php } else { ?>
        <p class="attn is-clear"><span><strong>All caught up</strong><small>No payments waiting.</small></span></p>
        <?php } ?>

        <div class="today-strip" aria-label="Today">
            <div><b><?php echo $stat['trips']; ?></b><span>Trips today</span></div>
            <div><b><?php echo $stat['booked']; ?></b><span>Booked riders today</span></div>
            <div><b><?php echo $stat['boarded']; ?></b><span>Boarded</span></div>
            <div><b><?php echo $my_buses; ?></b><span><?php echo $my_buses === 1 ? 'Bus' : 'Buses'; ?></span></div>
        </div>

        <section class="home-section" aria-labelledby="manage-h">
            <div class="home-section-head"><h2 id="manage-h">Manage</h2></div>
            <ul class="manage-list">
                <li><a href="scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i>Scan tickets<i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i>Trips<span class="count"><?php echo $stat['trips']; ?> today</span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i>Riders<span class="count"><?php echo $stat['booked']; ?> booked</span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="payments.php"><i class="fa fa-wallet" aria-hidden="true"></i>Payments<span class="count"><?php echo $pay_pending > 0 ? $pay_pending . ' to check' : ''; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i>Waiting<span class="count"><?php echo $stat['pending']; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="my_bus.php"><i class="fa fa-bus-simple" aria-hidden="true"></i>My Bus<span class="count"><?php echo $my_buses; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
            </ul>
        </section>

        <section class="home-section" aria-labelledby="today-h">
            <div class="home-section-head"><h2 id="today-h">Trips today</h2><a href="manage_trips.php">All trips</a></div>
            <?php if($todays_trips){ ?>
            <ul class="soon-list">
                <?php foreach($todays_trips as $t){ $full = (int)$t['booked'] >= (int)$t['capacity']; $bus_label = ctype_digit((string)$t['bus_number']) ? 'Bus ' . $t['bus_number'] : $t['bus_number']; ?>
                <li><a href="passengers.php?schedule_id=<?php echo (int)$t['schedule_id']; ?>">
                    <span class="soon-time"><?php echo date('g:i', strtotime($t['departure_time'])); ?><small><?php echo date('A', strtotime($t['departure_time'])); ?></small></span>
                    <span class="soon-body"><strong><?php echo htmlspecialchars($t['origin'] . ' to ' . $t['destination']); ?></strong><small><?php echo htmlspecialchars($bus_label . ' · ' . ($state_label[$t['trip_status']] ?? 'Up next') . ' · ' . (int)$t['boarded'] . ' of ' . (int)$t['booked'] . ' boarded'); ?></small></span>
                    <span class="seat-chip <?php echo $full ? 'full' : 'ok'; ?>"><?php echo (int)$t['booked']; ?>/<?php echo (int)$t['capacity']; ?></span>
                </a></li>
                <?php } ?>
            </ul>
            <?php } else { ?>
            <p class="attn is-clear"><span><strong>No trips left today</strong><small>Nothing else is assigned to your buses. Check upcoming trips.</small></span></p>
            <?php } ?>
        </section>

    </div></main>


<script src="../Assets/js/app-header.js"></script>
<script>window.OPERATOR_CSRF_TOKEN = <?php echo json_encode(csrf_get_token()); ?>; window.MBUS_GPS_ENABLED = true;</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_trips.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Riders</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/operator_tracker.js"></script>
</body>
</html>
