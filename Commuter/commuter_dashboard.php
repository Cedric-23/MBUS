<?php
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/commuter_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="icon" href="../Assets/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/mbus_icon.png">

    <title>Commuter Dashboard</title>
</head>

<body>

    <!-- SIDEBAR -->
    <div class="sidebar">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo">
        </div>

        <h2>Commuter</h2>

        <div class="menu-top">
            <a href="commuter_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
            <a href="my_reservations.php"><i class="fa fa-ticket"></i> My Reservations</a>
            <a href="history.php"><i class="fa fa-history"></i> History</a>
        </div>

        <a href="../logout.php" class="logout-btn">
            <i class="fa fa-sign-out"></i> Logout
        </a>

    </div>

    <!-- MAIN -->
    <div class="main">

        <!-- WELCOME -->
        <div class="welcome">
            <h1>Welcome, <?php echo $_SESSION['full_name']; ?>!</h1>
            <p>Manage your schedules, reservations, and upcoming trips easily.</p>
        </div>

        <!-- DASHBOARD CARDS -->
        <div class="cards">

            <!-- TODAY -->
            <div class="card">
                <h3 class="day">Today</h3>
                <p class="date"><?php echo date("F d"); ?></p>
                <p>View today's available bus schedules.</p>
                <a href="schedule.php?filter=today">Open Schedule</a>
            </div>

            <!-- TOMORROW -->
            <div class="card">
                <h3 class="day">Tomorrow</h3>
                <p class="date"><?php echo date("F d", strtotime("+1 day")); ?></p>
                <p>Check schedules for tomorrow.</p>
                <a href="schedule.php?filter=tomorrow">Open Schedule</a>
            </div>

            <!-- THIS WEEK -->
            <div class="card">
                <h3 class="day">This Week</h3>
                <p class="date">
                    <?php echo date("M d"); ?> -
                    <?php echo date("M d", strtotime("+6 days")); ?>
                </p>
                <p>Browse schedules available this week.</p>
                <a href="schedule.php?filter=thisweek">Open Schedule</a>
            </div>

            <!-- NEXT WEEK -->
            <div class="card">
                <h3 class="day">Next Week</h3>
                <p class="date">
                    <?php echo date("M d", strtotime("+7 days")); ?> -
                    <?php echo date("M d", strtotime("+13 days")); ?>
                </p>
                <p>View upcoming schedules for next week.</p>
                <a href="schedule.php?filter=nextweek">Open Schedule</a>
            </div>

        </div>

        <!-- NOTICE -->
        <div class="notice">
            <h3>Travel Reminder</h3>
            <p>Please arrive at least 15 minutes before your departure time.</p>
        </div>

    </div>

</body>
</html>