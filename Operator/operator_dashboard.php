<?php
session_start();

require_once __DIR__ . '/../Includes/staff_nav.php';
if(
    !isset($_SESSION['user_type']) ||
    strtolower((string)$_SESSION['user_type']) !== 'driver'
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

/* BUS, CONDUCTOR AND SCHEDULES (today highlighted, everything upcoming listed) */
require_once __DIR__ . '/../Includes/driver_home.php';
$home = mbus_driver_home_data($conn, (int)$operator_id, $b);
$today_ymd = date('Y-m-d', strtotime($b['today']));

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

/* CURRENT WAITING RIDERS (the signal a driver needs most while on the road) */
$waitingCount = 0;
$wq = mbus_db_prepare($conn, "SELECT COUNT(*) AS n
    FROM pickup_requests p
    JOIN schedule s ON s.schedule_id = p.schedule_id
    JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
    WHERE oba.operator_id = ? AND p.status = 'Waiting' AND p.expires_at > NOW()
      AND s.schedule_status = 'Active' AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed')
      AND s.departure_time >= ? AND s.departure_time < ?", [$operator_id, $b['today'], $b['tomorrow']]);
if ($wq !== false && ($wrow = mbus_db_fetch_assoc($wq))) $waitingCount = (int)$wrow['n'];

/* Shared avatar rendering; Driver accounts do not receive Conductor GCash controls. */
require_once dirname(__DIR__) . '/Includes/header_avatar.php';

$greeting = (int)date('G') < 12 ? 'Good morning' : ((int)date('G') < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($_SESSION['full_name'] ?? 'there'))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">

    <title>Driver Dashboard</title>
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
    <link rel="stylesheet" href="../Assets/css/gps_status.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/operator_dashboard_extras.css?v=1">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/driver_home.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/driver_home.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>

<body class="stf has-sidebar staff-home">
<!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
        </div>

        <h2>Driver</h2>

        <?php echo mbus_staff_sidebar_nav('driver', 'home'); ?>

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
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo mbus_header_avatar('../'); ?></span>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="profile-menu-role">Driver account</span>
                        </div>
                        <?php echo mbus_staff_profile_links('driver'); ?>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="tracking-sr-only" aria-live="polite" role="status">
            <span id="operatorTrackingStatus">Driver location sharing is active.</span>
            <select id="operatorTrackingTrip" aria-label="Active trip"></select>
        </div>

        <?php echo mbus_driver_home_today_html($home, $today_ymd); ?>

        <div class="today-strip" aria-label="Today">
            <div><b><?php echo $stat['trips']; ?></b><span>Trips today</span></div>
            <div><b><?php echo $stat['booked']; ?></b><span>Booked riders today</span></div>
            <div><b><?php echo $stat['boarded']; ?></b><span>Boarded</span></div>
            <div><b><?php echo $my_buses; ?></b><span><?php echo $my_buses === 1 ? 'Bus' : 'Buses'; ?></span></div>
        </div>

        <?php echo mbus_driver_home_buses_html($home, '../'); ?>

        <?php echo mbus_driver_home_schedule_html($home, $today_ymd); ?>

    </div></main>


<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/notifications.js"></script>
<script>window.OPERATOR_CSRF_TOKEN = <?php echo json_encode(csrf_get_token()); ?>; window.MBUS_GPS_ENABLED = true;</script>

<?php echo mbus_staff_bottom_nav('driver', 'home'); ?>
<script src="../Assets/js/operator_tracker.js"></script>
</body>
</html>
