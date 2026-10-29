<?php
session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){
    header("Location: ../login.php");
    exit();
}

include("../config/db_connect.php");
include("../Includes/csrf.php");

/* FETCH ROUTES */
$routes = mbus_db_query($conn, "
    SELECT *
    FROM routes
    ORDER BY origin ASC
");

/* FETCH BUSES */
$buses = mbus_db_query($conn, "
    SELECT *
    FROM buses
    ORDER BY bus_number ASC
");

/* ADD SCHEDULE */
if(isset($_POST['add_schedule'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = mbus_db_escape($conn, $_POST['bus_id']);
        $route_id = mbus_db_escape($conn, $_POST['route_id']);
        $departure = $_POST['departure_date'] . ' ' . $_POST['departure_time'];
        $arrival = $_POST['arrival_date'] . ' ' . $_POST['arrival_time'];
        $status = "Active";

        mbus_db_prepare($conn, "
            INSERT INTO schedule
            (bus_id, route_id, departure_time, arrival_time, schedule_status)
            VALUES (?, ?, ?, ?, ?)
        ", [$bus_id, $route_id, $departure, $arrival, $status]);

        $success = "Schedule Added Successfully";
        header("Location: manage_schedules.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Schedule</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_schedules.css">
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
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php" aria-current="page"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
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

<header class="page-header">
    <h1>Add Schedule</h1>
</header>

<?php if(isset($success)): ?>
    <div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
    <div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<section class="card" style="max-width: 600px;">
    <h2 style="text-align: center; margin-bottom: var(--space-6); color: var(--color-primary-dark);">Add Schedule</h2>
    
    <form method="POST" class="form-section">
        <?php csrf_field(); ?>
        
        <div class="form-group">
            <label for="bus_id">Select Bus</label>
            <select name="bus_id" id="bus_id" class="form-control" required>
                <option value="">Select Bus</option>
                <?php 
                mbus_db_data_seek($buses, 0);
                while($bus = mbus_db_fetch_assoc($buses)){ ?>
                    <option value="<?php echo $bus['bus_id']; ?>">
                        <?php echo htmlspecialchars($bus['bus_number']); ?> - <?php echo htmlspecialchars($bus['bus_type']); ?> - <?php echo $bus['capacity']; ?> Seats
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label for="route_id">Select Route</label>
            <select name="route_id" id="route_id" class="form-control" required>
                <option value="">Select Route</option>
                <?php 
                mbus_db_data_seek($routes, 0);
                while($route = mbus_db_fetch_assoc($routes)){ ?>
                    <option value="<?php echo $route['route_id']; ?>">
                        <?php echo htmlspecialchars($route['origin']); ?> → <?php echo htmlspecialchars($route['destination']); ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label for="departure_date">Departure Date</label>
                <input type="date" name="departure_date" id="departure_date" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="departure_time">Departure Time</label>
                <input type="time" name="departure_time" id="departure_time" class="form-control" required>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label for="arrival_date">Arrival Date</label>
                <input type="date" name="arrival_date" id="arrival_date" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="arrival_time">Arrival Time</label>
                <input type="time" name="arrival_time" id="arrival_time" class="form-control" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="add_schedule" class="btn btn-primary" style="width: 100%;">
                <i class="fa fa-plus" aria-hidden="true"></i> Add Schedule
            </button>
        </div>
    </form>
</section>

</main>

<script>
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