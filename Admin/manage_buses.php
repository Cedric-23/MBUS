<?php

session_start();

/* CHECK ADMIN */
if(
    !isset($_SESSION['user_type'])
    ||
    strtolower($_SESSION['user_type']) != "admin"
){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";
include "../Includes/csrf.php";

/* ADD BUS */
if(isset($_POST['add_bus'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_number = trim($_POST['bus_number']);
        $bus_type   = trim($_POST['bus_type']);
        $capacity   = trim($_POST['capacity']);

        // Use prepared statement
        mbus_db_prepare($conn, "
INSERT INTO buses (bus_number, bus_type, capacity)
VALUES (?, ?, ?)
", [$bus_number, $bus_type, $capacity]);

        $success = "Bus added successfully";
    }
}

/* DELETE BUS */
if(isset($_POST['delete_bus'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = (int)$_POST['bus_id'];
        mbus_db_prepare($conn, "DELETE FROM buses WHERE bus_id = ?", [$bus_id]);
        $success = "Bus deleted successfully";
    }
}

$bus_query = mbus_db_query($conn, "
    SELECT * FROM buses
    ORDER BY bus_id DESC
");
$bus_rows = [];
if($bus_query !== false){
    while($bus = mbus_db_fetch_assoc($bus_query)){
        $bus_rows[] = $bus;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Buses</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_buses.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_resources.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
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
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php" aria-current="page"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
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
    <h1>Manage Buses</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Admin account</span>
                        </div>
                        <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<section class="resource-page" aria-labelledby="bus-page-heading">
    <div class="resource-intro">
        <div>
            <p class="resource-kicker">Fleet</p>
            <h2 class="resource-heading" id="bus-page-heading">Keep the fleet ready to roll</h2>
            <p class="resource-description">Manage the buses available for new schedules.</p>
        </div>
        <span class="resource-count"><?php echo count($bus_rows); ?> <?php echo count($bus_rows) === 1 ? 'bus' : 'buses'; ?></span>
    </div>

    <details class="resource-add" <?php echo isset($error) && isset($_POST['add_bus']) ? 'open' : ''; ?>>
        <summary class="btn btn-primary btn-sm"><i class="fa fa-plus" aria-hidden="true"></i> Add bus</summary>
        <form method="POST" class="resource-add-form">
            <?php csrf_field(); ?>
            <div class="resource-field">
                <label for="bus_number">Bus number</label>
                <input type="text" name="bus_number" id="bus_number" class="form-control" placeholder="e.g. 101" required>
            </div>
            <div class="resource-field">
                <label for="bus_type">Bus type</label>
                <select name="bus_type" id="bus_type" class="form-control" required>
                    <option value="">Select type</option>
                    <option value="Aircon">Aircon</option>
                    <option value="Ordinary">Ordinary</option>
                </select>
            </div>
            <div class="resource-field">
                <label for="capacity">Capacity</label>
                <input type="number" name="capacity" id="capacity" class="form-control" min="1" max="100" placeholder="Seats" required>
            </div>
            <div class="resource-add-actions">
                <button type="submit" name="add_bus" class="btn btn-primary btn-sm"><i class="fa fa-check" aria-hidden="true"></i> Save bus</button>
            </div>
        </form>
    </details>

    <section class="resource-list" aria-labelledby="bus-list-heading">
        <div class="resource-list-heading">
            <h2 id="bus-list-heading">Fleet inventory</h2>
            <span class="resource-list-note">Newest buses first</span>
        </div>
        <?php if(!$bus_rows): ?>
            <p class="resource-empty">No buses yet. Add one to make it available for scheduling.</p>
        <?php endif; ?>
        <?php foreach($bus_rows as $row):
            $is_aircon = strtolower((string)$row['bus_type']) === 'aircon';
        ?>
            <article class="resource-row">
                <span class="resource-mark" aria-hidden="true"><i class="fa fa-bus"></i></span>
                <div class="resource-main">
                    <p class="resource-name">Bus <?php echo htmlspecialchars((string)$row['bus_number']); ?></p>
                    <div class="resource-meta">
                        <span><i class="fa fa-chair" aria-hidden="true"></i><?php echo htmlspecialchars((string)$row['capacity']); ?> seats</span>
                        <span><i class="fa fa-hashtag" aria-hidden="true"></i>Fleet ID <?php echo (int)$row['bus_id']; ?></span>
                    </div>
                </div>
                <span class="resource-tag <?php echo $is_aircon ? '' : 'resource-tag--ordinary'; ?>"><?php echo htmlspecialchars((string)$row['bus_type']); ?></span>
                <form method="POST" class="resource-actions">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="bus_id" value="<?php echo (int)$row['bus_id']; ?>">
                    <button type="submit" name="delete_bus" class="resource-icon-btn resource-icon-btn--danger" title="Delete bus" aria-label="Delete bus <?php echo htmlspecialchars((string)$row['bus_number']); ?>" onclick="return confirm('Delete this bus?')">
                        <i class="fa fa-trash" aria-hidden="true"></i>
                    </button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
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

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_schedules.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span>
    </a>
    <a href="manage_buses.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
