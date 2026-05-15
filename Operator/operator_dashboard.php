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

$operator_id = $_SESSION['user_id'];

/* TRIPS TODAY */
$trips_today_query = mysqli_query($conn,"
SELECT COUNT(*) as total
FROM schedule
LEFT JOIN operator_bus_assignments
ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id='$operator_id'
AND DATE(schedule.departure_time)=CURDATE()
");
$trips_today = mysqli_fetch_assoc($trips_today_query)['total'];

/* TOTAL PASSENGERS */
$passengers_query = mysqli_query($conn,"
SELECT COUNT(*) as total
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id='$operator_id'
AND (reservation.status='Paid' OR reservation.status='Boarded')
");
$total_passengers = mysqli_fetch_assoc($passengers_query)['total'];

/* BOARDED */
$boarded_query = mysqli_query($conn,"
SELECT COUNT(*) as total
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id='$operator_id'
AND reservation.status='Boarded'
");
$total_boarded = mysqli_fetch_assoc($boarded_query)['total'];

/* ACTIVE */
$active_query = mysqli_query($conn,"
SELECT COUNT(*) as total
FROM schedule
JOIN operator_bus_assignments ON schedule.bus_id = operator_bus_assignments.bus_id
WHERE operator_bus_assignments.operator_id='$operator_id'
AND (schedule.trip_status='Scheduled' OR schedule.trip_status='Boarding')
");
$active_trips = mysqli_fetch_assoc($active_query)['total'];
?>

<!DOCTYPE html>
<html>
<head>

<title>Operator Dashboard</title>

<link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
<link rel="stylesheet" href="../Assets/css/operator/operator_dashboard.css">

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

<h2>Operator</h2>

<div class="menu-top">

<a href="operator_dashboard.php">
<i class="fa-solid fa-house"></i> Dashboard
</a>

<a href="manage_trips.php">
<i class="fa-solid fa-bus"></i> Manage Trips
</a>

<a href="passengers.php">
<i class="fa-solid fa-users"></i> Passenger List
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

<!-- ✅ WELCOME (MATCH COMMUTER) -->
<div class="welcome">

<h2>
Welcome, <?= $_SESSION['full_name']; ?>!
</h2>

<p>
Manage assigned trips, monitor passengers, and track boarding status in real-time.
</p>

</div>

<!-- ✅ CARDS (CLICKABLE) -->
<div class="cards">

<!-- TRIPS TODAY -->
<a href="manage_trips.php?filter=today" class="card-link">
<div class="card">
<h3>Trips Today</h3>
<div class="card-number"><?= $trips_today; ?></div>
<p>Assigned trips scheduled today</p>
</div>
</a>

<!-- PAID -->
<a href="passengers.php?status=Paid" class="card-link">
<div class="card">
<h3>Paid Passengers</h3>
<div class="card-number"><?= $total_passengers; ?></div>
<p>Passengers with confirmed bookings</p>
</div>
</a>

<!-- BOARDED -->
<a href="passengers.php?status=Boarded" class="card-link">
<div class="card">
<h3>Boarded Passengers</h3>
<div class="card-number"><?= $total_boarded; ?></div>
<p>Passengers already boarded</p>
</div>
</a>

<!-- ACTIVE -->
<a href="manage_trips.php?filter=active" class="card-link">
<div class="card">
<h3>Active Trips</h3>
<div class="card-number"><?= $active_trips; ?></div>
<p>Scheduled or boarding trips</p>
</div>
</a>

</div>

</div>

</body>
</html>