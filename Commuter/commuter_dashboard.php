<?php
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

include "../Includes/csrf.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token()); ?>">

    <title>Commuter Dashboard</title>
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
</head>

<body class="has-bottom-nav">

    <!-- MAIN -->
    <main class="main" role="main">

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
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="dashboard-content">

            <!-- QUICK ACTIONS -->
            <section class="cards" aria-labelledby="cards-heading">
                <h2 id="cards-heading" class="visually-hidden">Quick Actions</h2>

                <!-- TODAY -->
                <article class="card card-animated">
                    <h3 class="day">Today</h3>
                    <p class="date"><?php echo date("F d"); ?></p>
                    <p>View today's available bus schedules.</p>
                    <a href="schedule.php?filter=today" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- TOMORROW -->
                <article class="card card-animated">
                    <h3 class="day">Tomorrow</h3>
                    <p class="date"><?php echo date("F d", strtotime("+1 day")); ?></p>
                    <p>Check schedules for tomorrow.</p>
                    <a href="schedule.php?filter=tomorrow" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- THIS WEEK -->
                <article class="card card-animated">
                    <h3 class="day">This Week</h3>
                    <p class="date">
                        <?php echo date("M d"); ?> -
                        <?php echo date("M d", strtotime("+6 days")); ?>
                    </p>
                    <p>Browse schedules available this week.</p>
                    <a href="schedule.php?filter=thisweek" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- NEXT WEEK -->
                <article class="card card-animated">
                    <h3 class="day">Next Week</h3>
                    <p class="date">
                        <?php echo date("M d", strtotime("+7 days")); ?> -
                        <?php echo date("M d", strtotime("+13 days")); ?>
                    </p>
                    <p>View upcoming schedules for next week.</p>
                    <a href="schedule.php?filter=nextweek" class="btn btn-primary">Open Schedule</a>
                </article>

            </section>

            <!-- NOTICE -->
            <section class="notice card" aria-labelledby="notice-heading">
                <h3 id="notice-heading">Travel Reminder</h3>
                <p>Please arrive at least 15 minutes before your departure time.</p>
            </section>

        </div>

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
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i>
        <span>Alerts</span>
    </a>
</nav>

<script src="../Assets/js/app-header.js"></script>
</body>
</html>