<?php

session_start();

/* =========================
   CHECK ADMIN ACCESS
========================= */

if(
    !isset($_SESSION['user_type'])
    ||
    strtolower($_SESSION['user_type']) != "admin"
){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";

include_once "../Includes/admin_menu.php";
include_once "../config/app.php";
$bd = mbus_local_bounds();
$one = function($sql, $params = []) use ($conn) {
    $q = mbus_db_prepare($conn, $sql, $params);
    $r = $q === false ? false : mbus_db_fetch_assoc($q);
    return $r ? (int)$r['n'] : 0;
};
$counts = [
    'Buses' => $one("SELECT COUNT(*) AS n FROM buses"),
    'Operators' => $one("SELECT COUNT(*) AS n FROM users WHERE LOWER(user_type) = 'operator'"),
    'Routes' => $one("SELECT COUNT(*) AS n FROM routes"),
    'Schedules' => $one("SELECT COUNT(*) AS n FROM schedule WHERE departure_time >= ?", [$bd['today']]),
];
$pending = $one("SELECT COUNT(*) AS n FROM commuter_verification WHERE verification_status = 'Pending'");
$trips_today = $one("SELECT COUNT(*) AS n FROM schedule WHERE departure_time >= ? AND departure_time < ?", [$bd['today'], $bd['tomorrow']]);
$riders_today = $one("SELECT COUNT(*) AS n FROM reservation r JOIN schedule s ON s.schedule_id = r.schedule_id
    WHERE r.status IN ('Paid','Confirmed','Boarded') AND s.departure_time >= ? AND s.departure_time < ?", [$bd['today'], $bd['tomorrow']]);
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
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-notifications.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">

    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
</head>

<body class="has-sidebar staff-home">
<!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
        </div>

        <h2>Admin</h2>

        <nav class="menu-top" aria-label="Admin menu">
            <a href="admin_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
            <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
            <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
            <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
            <a href="waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>            <a href="notifications.php"><i class="fa fa-bell" aria-hidden="true"></i> Notifications</a>
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
                            <span class="profile-menu-role">Admin account</span>
                        </div>
                        <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <?php if($pending > 0){ ?>
        <a class="attn" href="verifications.php">
            <span><strong><?php echo $pending; ?> ID <?php echo $pending === 1 ? 'request' : 'requests'; ?> waiting</strong><small>Students, seniors and PWD need approval for their discount.</small></span>
            <span class="attn-go">Review</span>
        </a>
        <?php } else { ?>
        <p class="attn is-clear"><span><strong>All caught up</strong><small>No ID requests waiting.</small></span></p>
        <?php } ?>

        <div class="today-strip" aria-label="Today">
            <div><b><?php echo $trips_today; ?></b><span>Trips today</span></div>
            <div><b><?php echo $riders_today; ?></b><span>Booked riders today</span></div>
        </div>

        <section class="home-section" aria-labelledby="manage-h">
            <div class="home-section-head"><h2 id="manage-h">Manage</h2></div>
            <ul class="manage-list">
                <li><a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i>Schedules<span class="count"><?php echo $counts['Schedules']; ?> upcoming</span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i>Buses<span class="count"><?php echo $counts['Buses']; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i>Operators<span class="count"><?php echo $counts['Operators']; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
                <li><a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i>Routes<span class="count"><?php echo $counts['Routes']; ?></span><i class="fa fa-chevron-right" aria-hidden="true"></i></a></li>
            </ul>
        </section>

    </div></main>


<script src="../Assets/js/app-header.js"></script>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_schedules.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span>
    </a>
    <a href="manage_buses.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
</body>
</html>
