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

$bus_query = mysqli_query($conn,"
    SELECT COUNT(*) AS total
    FROM buses
");

$bus_data = mysqli_fetch_assoc($bus_query);

/* =========================
   TOTAL OPERATORS
========================= */

$operator_query = mysqli_query($conn,"
    SELECT COUNT(*) AS total
    FROM users
    WHERE LOWER(user_type)='operator'
");

$operator_data = mysqli_fetch_assoc($operator_query);

/* =========================
   TOTAL SCHEDULES
========================= */

$schedule_query = mysqli_query($conn,"
    SELECT COUNT(*) AS total
    FROM schedule
");

$schedule_data = mysqli_fetch_assoc($schedule_query);

/* =========================
   TOTAL RESERVATIONS
========================= */

$reservation_query = mysqli_query($conn,"
    SELECT COUNT(*) AS total
    FROM reservation
");

$reservation_data = mysqli_fetch_assoc($reservation_query);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard</title>

<!-- CSS -->
<link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
<link rel="stylesheet" href="../Assets/css/admin/admin_dashboard.css">

<!-- ICONS -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">

<div class="logo-container">
    <img src="../Assets/images/mbus_logo.png" class="logo">
</div>

<h2>Admin</h2>

<div class="menu-top">

<a href="admin_dashboard.php">
<i class="fa-solid fa-house"></i> Dashboard
</a>

<a href="manage_schedules.php">
<i class="fa-solid fa-calendar"></i> Manage Schedules
</a>

<a href="manage_buses.php">
<i class="fa-solid fa-bus"></i> Manage Buses
</a>

<a href="manage_operators.php">
<i class="fa-solid fa-user-gear"></i> Manage Operators
</a>

<a href="manage_routes.php">
<i class="fa-solid fa-route"></i> Manage Routes
</a>

</div>

<div class="logout-btn">
<a href="../logout.php">
<i class="fa-solid fa-right-from-bracket"></i> Logout
</a>
</div>

</div>

<!-- MAIN -->
<div class="main">

<!-- WELCOME -->
<div class="welcome">

<h1>
Welcome, <?php echo $_SESSION['full_name']; ?>!
</h1>

<p>
Manage buses, operators, routes, and overall system operations.
</p>

</div>

<!-- CARDS -->
<div class="cards">

<!-- TOTAL BUSES -->
<div class="card">
<h3>Total Buses</h3>
<div class="number">
<?php echo $bus_data['total']; ?>
</div>
</div>

<!-- TOTAL OPERATORS -->
<div class="card">
<h3>Total Operators</h3>
<div class="number">
<?php echo $operator_data['total']; ?>
</div>
</div>

<!-- TOTAL SCHEDULES -->
<div class="card">
<h3>Total Schedules</h3>
<div class="number">
<?php echo $schedule_data['total']; ?>
</div>
</div>

<!-- TOTAL RESERVATIONS -->
<div class="card">
<h3>Total Reservations</h3>
<div class="number">
<?php echo $reservation_data['total']; ?>
</div>
</div>

</div>

<!-- NOTICE -->
<div class="notice">

<h3>System Notice</h3>

<p>
Only authorized administrators can manage buses, operators, and system configurations.
</p>

</div>

</div>

</body>

</html>