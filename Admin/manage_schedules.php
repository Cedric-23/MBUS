<?php
session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){
    header("Location: ../login.php");
    exit();
}

include("../config/db_connect.php");
include("../Includes/csrf.php");

/* UPDATE */
if(isset($_POST['update_schedule'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $schedule_id = mbus_db_escape($conn, $_POST['schedule_id']);
        $bus_id = mbus_db_escape($conn, $_POST['bus_id']);
        $route_id = mbus_db_escape($conn, $_POST['route_id']);
        $departure_time = mbus_db_escape($conn, $_POST['departure_time']);
        $arrival_time = mbus_db_escape($conn, $_POST['arrival_time']);
        $schedule_status = mbus_db_escape($conn, $_POST['schedule_status']);

        mbus_db_prepare($conn, "
            UPDATE schedule SET
            bus_id = ?,
            route_id = ?,
            departure_time = ?,
            arrival_time = ?,
            schedule_status = ?
            WHERE schedule_id = ?
        ", [$bus_id, $route_id, $departure_time, $arrival_time, $schedule_status, $schedule_id]);

        $success = "Schedule updated successfully";
        header("Location: manage_schedules.php");
        exit();
    }
}

/* DELETE */
if(isset($_POST['delete_schedule'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $schedule_id = mbus_db_escape($conn, $_POST['schedule_id']);
        
        mbus_db_prepare($conn, "
            DELETE FROM schedule
            WHERE schedule_id = ?
        ", [$schedule_id]);

        $success = "Schedule deleted successfully";
        header("Location: manage_schedules.php");
        exit();
    }
}

/* ADD */
if(isset($_POST['add_schedule'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = mbus_db_escape($conn, $_POST['bus_id']);
        $route_id = mbus_db_escape($conn, $_POST['route_id']);
        $departure_time = mbus_db_escape($conn, $_POST['departure_time']);
        $arrival_time = mbus_db_escape($conn, $_POST['arrival_time']);
        $schedule_status = mbus_db_escape($conn, $_POST['schedule_status']);

        mbus_db_prepare($conn, "
            INSERT INTO schedule(
                bus_id, route_id,
                departure_time, arrival_time,
                schedule_status
            )
            VALUES(
                ?, ?, ?, ?, ?
            )
        ", [$bus_id, $route_id, $departure_time, $arrival_time, $schedule_status]);

        $success = "Schedule added successfully";
        header("Location: manage_schedules.php");
        exit();
    }
}

/* FETCH */
$routes = mbus_db_query($conn, "SELECT * FROM routes ORDER BY origin ASC");
$buses  = mbus_db_query($conn, "SELECT * FROM buses ORDER BY bus_id ASC");

$query = mbus_db_query($conn, "
SELECT schedule.*, routes.origin, routes.destination
FROM schedule
LEFT JOIN routes ON schedule.route_id=routes.route_id
ORDER BY departure_time ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schedules</title>

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
    <h1>Manage Schedules</h1>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- View Toggle -->
<div class="view-toggle" role="group" aria-label="View options">
    <button class="btn btn-secondary toggle-btn active" onclick="showListView()" aria-pressed="true">
        <i class="fa fa-list" aria-hidden="true"></i> List View
    </button>
    <button class="btn btn-secondary toggle-btn" onclick="showCalendarView()" aria-pressed="false">
        <i class="fa fa-calendar" aria-hidden="true"></i> Calendar View
    </button>
</div>

<!-- Filter Tabs -->
<nav class="tabs" aria-label="Schedule filters">
    <a href="?filter=all" class="tab <?php echo (!isset($_GET['filter']) || $_GET['filter']==='all') ? 'active' : ''; ?>" aria-current="<?php echo (!isset($_GET['filter']) || $_GET['filter']==='all') ? 'page' : 'false'; ?>">All</a>
    <a href="?filter=morong" class="tab <?php echo (isset($_GET['filter']) && $_GET['filter']==='morong') ? 'active' : ''; ?>" aria-current="<?php echo (isset($_GET['filter']) && $_GET['filter']==='morong') ? 'page' : 'false'; ?>">Morong → SBMA</a>
    <a href="?filter=sbma" class="tab <?php echo (isset($_GET['filter']) && $_GET['filter']==='sbma') ? 'active' : ''; ?>" aria-current="<?php echo (isset($_GET['filter']) && $_GET['filter']==='sbma') ? 'page' : 'false'; ?>">SBMA → Morong</a>
</nav>

<!-- ADD SCHEDULE -->
<section class="card" style="margin-bottom: var(--space-6);">
    <h2>Add Schedule</h2>
    <form method="POST" class="form-section">
        <?php csrf_field(); ?>
        
        <div class="form-grid">
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

            <div class="form-group">
                <label for="departure_time">Departure Time</label>
                <input type="datetime-local" name="departure_time" id="departure_time" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="arrival_time">Arrival Time</label>
                <input type="datetime-local" name="arrival_time" id="arrival_time" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="schedule_status">Status</label>
                <select name="schedule_status" id="schedule_status" class="form-control">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="add_schedule" class="btn btn-primary">
                <i class="fa fa-plus" aria-hidden="true"></i> Add Schedule
            </button>
        </div>
    </form>
</section>

<!-- SCHEDULE LIST -->
<section class="card">
    <div class="table-responsive">
        <table class="schedules-table">
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Bus</th>
                    <th scope="col">Route</th>
                    <th scope="col">Departure</th>
                    <th scope="col">Arrival</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                mbus_db_data_seek($query, 0);
                while($row = mbus_db_fetch_assoc($query)){
                    // Apply filter
                    $filter = $_GET['filter'] ?? 'all';
                    $route_type = ($row['origin'] == 'Morong Terminal') ? 'morong' : 'sbma';
                    if($filter !== 'all' && $filter !== $route_type) continue;
                    
                    $status_class = strtolower($row['schedule_status']);
                ?>
                <tr class="schedule-row" data-route="<?php echo $route_type; ?>">
                    <form method="POST" class="schedule-form">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="schedule_id" value="<?php echo $row['schedule_id']; ?>">
                        
                        <td data-label="ID"><?php echo $row['schedule_id']; ?></td>
                        
                        <td data-label="Bus">
                            <span class="view-mode">Bus <?php echo $row['bus_id']; ?></span>
                            <div class="edit-controls">
                                <select name="bus_id" class="form-control edit-input">
                                    <?php 
                                    mbus_db_data_seek($buses, 0); 
                                    while($bus = mbus_db_fetch_assoc($buses)){ ?>
                                        <option value="<?php echo $bus['bus_id']; ?>" 
                                            <?php echo ($bus['bus_id'] == $row['bus_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bus['bus_number']); ?> - <?php echo htmlspecialchars($bus['bus_type']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </td>
                        
                        <td data-label="Route">
                            <span class="view-mode">
                                <span class="route-badge">
                                    <?php echo htmlspecialchars($row['origin']); ?> → <?php echo htmlspecialchars($row['destination']); ?>
                                </span>
                            </span>
                            <div class="edit-controls">
                                <select name="route_id" class="form-control edit-input">
                                    <?php 
                                    mbus_db_data_seek($routes, 0); 
                                    while($route = mbus_db_fetch_assoc($routes)){ ?>
                                        <option value="<?php echo $route['route_id']; ?>" 
                                            <?php echo ($route['route_id'] == $row['route_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($route['origin']); ?> → <?php echo htmlspecialchars($route['destination']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </td>
                        
                        <td data-label="Departure">
                            <span class="view-mode"><?php echo date('M j, Y g:i A', strtotime($row['departure_time'])); ?></span>
                            <div class="edit-controls">
                                <input type="datetime-local" name="departure_time" class="form-control edit-input" 
                                    value="<?php echo date('Y-m-d\TH:i', strtotime($row['departure_time'])); ?>">
                            </div>
                        </td>
                        
                        <td data-label="Arrival">
                            <span class="view-mode"><?php echo date('M j, Y g:i A', strtotime($row['arrival_time'])); ?></span>
                            <div class="edit-controls">
                                <input type="datetime-local" name="arrival_time" class="form-control edit-input" 
                                    value="<?php echo date('Y-m-d\TH:i', strtotime($row['arrival_time'])); ?>">
                            </div>
                        </td>
                        
                        <td data-label="Status">
                            <span class="view-mode">
                                <span class="status-badge status-<?php echo $status_class; ?>">
                                    <?php echo $row['schedule_status']; ?>
                                </span>
                            </span>
                            <div class="edit-controls">
                                <select name="schedule_status" class="form-control edit-input">
                                    <option value="Active" <?php echo ($row['schedule_status'] == "Active") ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo ($row['schedule_status'] == "Inactive") ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </td>
                        
                        <td class="actions-col" data-label="Actions">
                            <div class="action-buttons">
                                <button type="submit" name="update_schedule" class="btn btn-sm btn-success save-btn">
                                    <i class="fa fa-save" aria-hidden="true"></i> Save
                                </button>
                                <button type="submit" name="delete_schedule" class="btn btn-sm btn-danger delete-btn" 
                                    onclick="return confirm('Delete this schedule?')">
                                    <i class="fa fa-trash" aria-hidden="true"></i> Delete
                                </button>
                            </div>
                        </td>
                    </form>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>

</main>

<script>
// View toggle functions
function showListView() {
    document.getElementById('listView').style.display = 'block';
    document.getElementById('calendarView').style.display = 'none';
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.toggle-btn[onclick="showListView()"]').classList.add('active');
}

function showCalendarView() {
    document.getElementById('listView').style.display = 'none';
    document.getElementById('calendarView').style.display = 'block';
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.toggle-btn[onclick="showCalendarView()"]').classList.add('active');
}

// Filter schedules
function filterSchedules(filter, btn) {
    // Update URL without reload
    const url = new URL(window.location);
    if(filter === 'all') {
        url.searchParams.delete('filter');
    } else {
        url.searchParams.set('filter', filter);
    }
    window.history.pushState({}, '', url);
    location.reload();
}

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