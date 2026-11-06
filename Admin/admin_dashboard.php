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

/* =========================
   TOTAL BUSES
========================= */

$bus_query = mbus_db_query($conn, "
    SELECT COUNT(*) AS total
    FROM buses
");

$bus_data = mbus_db_fetch_assoc($bus_query);

/* =========================
   TOTAL OPERATORS
========================= */

$operator_query = mbus_db_query($conn, "
    SELECT COUNT(*) AS total
    FROM users
    WHERE LOWER(user_type)='operator'
");

$operator_data = mbus_db_fetch_assoc($operator_query);

/* =========================
   TOTAL SCHEDULES
========================= */

$schedule_query = mbus_db_query($conn, "
    SELECT COUNT(*) AS total
    FROM schedule
");

$schedule_data = mbus_db_fetch_assoc($schedule_query);

/* =========================
   TOTAL RESERVATIONS
========================= */

$reservation_query = mbus_db_query($conn, "
    SELECT COUNT(*) AS total
    FROM reservation
");

$reservation_data = mbus_db_fetch_assoc($reservation_query);

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
    <link rel="stylesheet" href="../Assets/css/dashboard-home.css">
</head>

<body class="has-sidebar">
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
                            <span class="profile-menu-role">Admin account</span>
                        </div>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <p class="dashboard-intro">Monitor the core MBUS system resources from one place.</p>

            <!-- QUICK ACTIONS -->
            <section class="cards" aria-labelledby="cards-heading">
                <h2 id="cards-heading" class="dashboard-section-title">System overview</h2>

                <!-- TOTAL BUSES -->
                <article class="card card-animated">
                    <h3 class="day">Total Buses</h3>
                    <p class="date"><?php echo $bus_data['total']; ?> registered</p>
                    <p>All buses in the system</p>
                    <a href="manage_buses.php" class="btn btn-primary">Manage Buses</a>
                </article>

                <!-- TOTAL OPERATORS -->
                <article class="card card-animated">
                    <h3 class="day">Total Operators</h3>
                    <p class="date"><?php echo $operator_data['total']; ?> operators</p>
                    <p>Active operator accounts</p>
                    <a href="manage_operators.php" class="btn btn-primary">Manage Operators</a>
                </article>

                <!-- TOTAL SCHEDULES -->
                <article class="card card-animated">
                    <h3 class="day">Total Schedules</h3>
                    <p class="date"><?php echo $schedule_data['total']; ?> schedules</p>
                    <p>All scheduled trips</p>
                    <a href="manage_schedules.php" class="btn btn-primary">Manage Schedules</a>
                </article>

                <!-- TOTAL RESERVATIONS -->
                <article class="card card-animated">
                    <h3 class="day">Total Reservations</h3>
                    <p class="date"><?php echo $reservation_data['total']; ?> bookings</p>
                    <p>All passenger reservations</p>
                    <a href="manage_schedules.php" class="btn btn-primary">Review Schedules</a>
                </article>

            </section>

        </div>

    </main>


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
