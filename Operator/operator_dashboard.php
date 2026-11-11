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

/* NEXT TRIP + REST OF TODAY (this operator's buses, still open) */
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
$current = $trips[0] ?? null;
$rest = array_slice($trips, 1, 4);
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
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
    <link rel="stylesheet" href="../Assets/css/gps_status.css">
</head>

<body class="has-sidebar staff-home">
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
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="profile-menu-role">Operator account</span>
                        </div>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="tracking-sr-only" aria-live="polite" role="status">
            <span id="operatorTrackingStatus">Operator location sharing is active.</span>
            <select id="operatorTrackingTrip" aria-label="Active trip"></select>
        </div>

        <?php if($current){
            $label = ['Scheduled' => 'Up next', 'Boarding' => 'Boarding now', 'Departed' => 'On the road'][$current['trip_status']] ?? 'Up next';
        ?>
        <section class="next-trip" aria-label="Current trip">
            <div class="next-trip-top">
                <span class="next-state"><?php echo $label; ?></span>
                <span class="next-when"><?php echo date('g:i A', strtotime($current['departure_time'])); ?></span>
            </div>
            <p class="next-route"><?php echo htmlspecialchars($current['origin'] . ' to ' . $current['destination']); ?></p>
            <p class="next-meta"><?php echo htmlspecialchars($current['bus_number']); ?> &middot; <?php echo (int)$current['boarded']; ?> of <?php echo (int)$current['booked']; ?> boarded</p>
            <div class="next-actions">
                <a class="next-cta" href="scan.php">Scan tickets</a>
                <a class="next-cta is-ghost" href="passengers.php?schedule_id=<?php echo (int)$current['schedule_id']; ?>">Seat map</a>
            </div>
        </section>
        <?php } else { ?>
        <section class="next-trip is-empty" aria-label="No trips">
            <p class="next-route">No trips left today</p>
            <p class="next-meta">Nothing else is assigned to your buses. Check upcoming trips.</p>
            <a class="next-cta" href="manage_trips.php">View trips</a>
        </section>
        <?php } ?>

        <?php if($rest){ ?>
        <section class="home-section" aria-labelledby="later-h">
            <div class="home-section-head"><h2 id="later-h">Later today</h2><a href="manage_trips.php">All trips</a></div>
            <ul class="soon-list">
                <?php foreach($rest as $t){ $full = (int)$t['booked'] >= (int)$t['capacity']; ?>
                <li><a href="passengers.php?schedule_id=<?php echo (int)$t['schedule_id']; ?>">
                    <span class="soon-time"><?php echo date('g:i', strtotime($t['departure_time'])); ?><small><?php echo date('A', strtotime($t['departure_time'])); ?></small></span>
                    <span class="soon-body"><strong><?php echo htmlspecialchars($t['origin'] . ' to ' . $t['destination']); ?></strong><small><?php echo htmlspecialchars($t['bus_number']); ?></small></span>
                    <span class="seat-chip <?php echo $full ? 'full' : 'ok'; ?>"><?php echo (int)$t['booked']; ?>/<?php echo (int)$t['capacity']; ?></span>
                </a></li>
                <?php } ?>
            </ul>
        </section>
        <?php } ?>

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
