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
AND DATE(schedule.departure_time) = CURDATE()
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
    <title>Operator Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_dashboard.css">
</head>

<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle btn btn-icon" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container">
        <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
    </div>

    <h2>Operator</h2>

    <nav class="menu-top" aria-label="Operator menu">
            <a href="operator_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
            <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
        </nav>

        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
            </a>
        </div>
    </aside>

<main class="main" role="main">

<!-- WELCOME -->
<div class="welcome">
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
    <p>Manage assigned trips, monitor passengers, and track boarding status in real-time.</p>
</div>

<!-- CARDS -->
<div class="cards">

<!-- TRIPS TODAY -->
<a href="manage_trips.php?filter=today" class="card-link">
    <div class="card">
        <h3>Trips Today</h3>
        <div class="card-number"><?php echo $trips_today; ?></div>
        <p>Assigned trips scheduled today</p>
    </div>
</a>

<!-- PAID -->
<a href="passengers.php?status=Paid" class="card-link">
    <div class="card">
        <h3>Paid Passengers</h3>
        <div class="card-number"><?php echo $total_passengers; ?></div>
        <p>Passengers with confirmed bookings</p>
    </div>
</a>

<!-- BOARDED -->
<a href="passengers.php?status=Boarded" class="card-link">
    <div class="card">
        <h3>Boarded Passengers</h3>
        <div class="card-number"><?php echo $total_boarded; ?></div>
        <p>Passengers already boarded</p>
    </div>
</a>

<!-- ACTIVE -->
<a href="manage_trips.php?filter=active" class="card-link">
    <div class="card">
        <h3>Active Trips</h3>
        <div class="card-number"><?php echo $active_trips; ?></div>
        <p>Scheduled or boarding trips</p>
    </div>
</a>

</div>

</main>

<script>
let notificationDropdownOpen = false;

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    
    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen);
    }
    
    // Trap focus when sidebar is open on mobile
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    } else {
        document.body.style.overflow = '';
    }
}

// Close sidebar when clicking a nav link on mobile
document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// Handle keyboard navigation for sidebar
document.addEventListener('keydown', function(e) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});
</script>

</body>
</html>