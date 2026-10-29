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
    <title>Admin Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_dashboard.css">
</head>

<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

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
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
    <p>Manage buses, operators, routes, and overall system operations.</p>
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