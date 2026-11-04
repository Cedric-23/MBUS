<?php
session_start();

if(
    !isset($_SESSION['user_type']) ||
    strtolower($_SESSION['user_type']) != 'operator'
){
    header("Location: ../login.php");
    exit();
}

include("../config/db_connect.php");
include("../Includes/activity_log.php");

$operator_id = $_SESSION['user_id'];

/* TRIPS TODAY */
$trips_today_query = mbus_db_prepare($conn, "
SELECT COUNT(*) as total
FROM schedule
LEFT JOIN operator_bus_assignments
ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND DATE(schedule.departure_time) = CURRENT_DATE
", [$operator_id]);

$trips_today = mbus_db_fetch_assoc($trips_today_query)['total'];

/* TOTAL PASSENGERS */
$passengers_query = mbus_db_prepare($conn, "
SELECT COUNT(*) as total
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND (reservation.status = 'Paid' OR reservation.status = 'Boarded')
", [$operator_id]);

$total_passengers = mbus_db_fetch_assoc($passengers_query)['total'];

/* BOARDED */
$boarded_query = mbus_db_prepare($conn, "
SELECT COUNT(*) as total
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND reservation.status = 'Boarded'
", [$operator_id]);

$total_boarded = mbus_db_fetch_assoc($boarded_query)['total'];

/* ACTIVE */
$active_query = mbus_db_prepare($conn, "
SELECT COUNT(*) as total
FROM schedule
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id = ?
AND (schedule.trip_status = 'Scheduled' OR schedule.trip_status = 'Boarding')
", [$operator_id]);

$active_trips = mbus_db_fetch_assoc($active_query)['total'];
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
    <link rel="stylesheet" href="../Assets/css/professional-notifications.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">

    <title>Operator Dashboard</title>
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/dashboard-home.css">
</head>

<body class="has-sidebar">
<!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
        </div>

        <h2>Operator</h2>

        <nav class="menu-top" aria-label="Operator menu">
            <a href="operator_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
            <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
            <a href="notifications.php"><i class="fa fa-bell" aria-hidden="true"></i> Notifications</a>
        </nav>

        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
        </div>

    </aside>

    <!-- MAIN -->
    <main class="main dashboard-home" role="main">

        <!-- PAGE HEADER - Part of page flow (scrolls with content), like schedule.php -->
        <header class="page-header" role="banner">
            <div class="page-header-left">
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
            </div>
            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
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

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <p class="dashboard-intro">Keep today's trips and passenger movement on track.</p>

            <!-- QUICK ACTIONS -->
            <section class="cards" aria-labelledby="cards-heading">
                <h2 id="cards-heading" class="dashboard-section-title">Operations overview</h2>

                <!-- TRIPS TODAY -->
                <article class="card card-animated">
                    <h3 class="day">Trips Today</h3>
                    <p class="date"><?php echo $trips_today; ?> scheduled</p>
                    <p>Assigned trips scheduled today</p>
                    <a href="manage_trips.php?filter=today" class="btn btn-primary">View Trips</a>
                </article>

                <!-- PAID PASSENGERS -->
                <article class="card card-animated">
                    <h3 class="day">Paid Passengers</h3>
                    <p class="date"><?php echo $total_passengers; ?> confirmed</p>
                    <p>Passengers with confirmed bookings</p>
                    <a href="passengers.php?status=Paid" class="btn btn-primary">View Passengers</a>
                </article>

                <!-- BOARDED PASSENGERS -->
                <article class="card card-animated">
                    <h3 class="day">Boarded Passengers</h3>
                    <p class="date"><?php echo $total_boarded; ?> boarded</p>
                    <p>Passengers already boarded</p>
                    <a href="passengers.php?status=Boarded" class="btn btn-primary">View Boarded</a>
                </article>

                <!-- ACTIVE TRIPS -->
                <article class="card card-animated">
                    <h3 class="day">Active Trips</h3>
                    <p class="date"><?php echo $active_trips; ?> active</p>
                    <p>Scheduled or boarding trips</p>
                    <a href="manage_trips.php?filter=active" class="btn btn-primary">View Active</a>
                </article>

            </section>

        </div>

    </main>


<script src="../Assets/js/app-header.js"></script>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_trips.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="passengers.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Passengers</span>
    </a>
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span>
    </a>
</nav>
</body>
</html>