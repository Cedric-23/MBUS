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

/* UPDATE BUS */
if(isset($_POST['update_bus'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $bus_id = (int)($_POST['bus_id'] ?? 0);
        $bus_number = trim((string)($_POST['bus_number'] ?? ''));
        $bus_type = (string)($_POST['bus_type'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 0);
        if($bus_id < 1 || $bus_number === '' || !in_array($bus_type, ['Aircon','Ordinary'], true) || $capacity < 1 || $capacity > 100){
            $error = "Please provide a bus number, a type and a capacity from 1 to 100.";
        } else {
            $u = mbus_db_prepare($conn, "UPDATE buses SET bus_number = ?, bus_type = ?, capacity = ? WHERE bus_id = ?", [$bus_number, $bus_type, $capacity, $bus_id]);
            $success = $u === false ? "Bus could not be updated." : "Bus updated";
            if($u === false){ $error = $success; unset($success); }
        }
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
    <link rel="stylesheet" href="../Assets/css/admin/schedules_compact.css">
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

<?php
$bq = mbus_db_query($conn, "
    SELECT b.*,
      (SELECT COUNT(*) FROM schedule sc WHERE sc.bus_id=b.bus_id AND sc.departure_time >= NOW() AND sc.schedule_status='Active') AS upcoming,
      (SELECT string_agg(u.full_name, ', ') FROM operator_bus_assignments oa JOIN users u ON u.user_id=oa.operator_id WHERE oa.bus_id=b.bus_id) AS operators
    FROM buses b ORDER BY b.bus_id DESC");
$bus_list = [];
while($bq !== false && ($row = mbus_db_fetch_assoc($bq))){ $bus_list[] = $row; }
$aircon = count(array_filter($bus_list, fn($x) => strtolower($x['bus_type']) === 'aircon'));
?>
<div class="sc-summary">
    <span><b><?php echo count($bus_list); ?></b>buses</span>
    <span><b><?php echo $aircon; ?></b>aircon</span>
    <span><b><?php echo count($bus_list) - $aircon; ?></b>ordinary</span>
    <span><b><?php echo array_sum(array_map(fn($x) => (int)$x['capacity'], $bus_list)); ?></b>total seats</span>
</div>

<details class="sc-add" <?php echo isset($error) && isset($_POST['add_bus']) ? 'open' : ''; ?>>
    <summary class="btn btn-primary btn-sm"><i class="fa fa-plus" aria-hidden="true"></i> Add bus</summary>
    <form method="POST" class="sc-add-form">
        <?php csrf_field(); ?>
        <input type="text" name="bus_number" class="form-control" placeholder="Bus number" aria-label="Bus number" required>
        <select name="bus_type" class="form-control" aria-label="Bus type" required>
            <option value="">Type</option><option value="Aircon">Aircon</option><option value="Ordinary">Ordinary</option>
        </select>
        <input type="number" name="capacity" class="form-control" placeholder="Seats" aria-label="Capacity" min="1" max="100" required>
        <button type="submit" name="add_bus" class="btn btn-primary btn-sm">Save</button>
    </form>
</details>

<section class="sc-list" aria-label="Buses">
<?php if(!$bus_list): ?><p class="sc-empty">No buses yet.</p><?php endif; ?>
<?php foreach($bus_list as $row):
    $fid = 'bf-' . (int)$row['bus_id'];
    $is_ac = strtolower($row['bus_type']) === 'aircon';
?>
<article class="sc-row <?php echo $is_ac ? 'dir-morong' : 'dir-sbma'; ?>">
    <div class="sc-main people">
        <span class="sc-avatar bus"><i class="fa fa-bus" aria-hidden="true"></i></span>
        <span class="sc-who">
            <strong>Bus <?php echo htmlspecialchars($row['bus_number']); ?></strong>
            <small><?php echo !empty($row['operators']) ? htmlspecialchars($row['operators']) : 'No operator assigned'; ?></small>
        </span>
        <span class="sc-chip <?php echo $is_ac ? '' : 'green'; ?>"><?php echo htmlspecialchars($row['bus_type']); ?> &middot; <?php echo (int)$row['capacity']; ?> seats</span>
        <span class="sc-actions">
            <button type="button" class="sc-edit-btn" onclick="scEdit(this,true)"><i class="fa fa-pen" aria-hidden="true"></i> Edit</button>
            <button type="submit" form="<?php echo $fid; ?>" name="delete_bus" class="sc-icon sc-del" onclick="return confirm('Delete this bus?')" aria-label="Delete bus"><i class="fa fa-trash" aria-hidden="true"></i></button>
        </span>
    </div>
    <div class="sc-meta"><span><i class="fa fa-calendar" aria-hidden="true"></i> <?php echo (int)$row['upcoming']; ?> upcoming trip<?php echo (int)$row['upcoming'] === 1 ? '' : 's'; ?></span></div>
    <div class="sc-edit" hidden>
        <input type="text" form="<?php echo $fid; ?>" name="bus_number" class="form-control" value="<?php echo htmlspecialchars($row['bus_number'], ENT_QUOTES); ?>" aria-label="Bus number">
        <select form="<?php echo $fid; ?>" name="bus_type" class="form-control" aria-label="Bus type">
            <option <?php echo $is_ac ? 'selected' : ''; ?>>Aircon</option><option <?php echo !$is_ac ? 'selected' : ''; ?>>Ordinary</option>
        </select>
        <input type="number" form="<?php echo $fid; ?>" name="capacity" class="form-control" min="1" max="100" value="<?php echo (int)$row['capacity']; ?>" aria-label="Capacity">
        <span class="sc-edit-actions">
            <button type="submit" form="<?php echo $fid; ?>" name="update_bus" class="btn btn-sm btn-primary">Save</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="scEdit(this,false)">Cancel</button>
        </span>
    </div>
    <form id="<?php echo $fid; ?>" method="POST" hidden><?php csrf_field(); ?><input type="hidden" name="bus_id" value="<?php echo (int)$row['bus_id']; ?>"></form>
</article>
<?php endforeach; ?>
</section>

</main>

<script>
function scEdit(btn, on) {
    const row = btn.closest('.sc-row');
    row.querySelector('.sc-edit').hidden = !on;
    if (!on) row.querySelectorAll('.sc-edit select, .sc-edit input').forEach(el => {
        if (el.tagName === 'SELECT') Array.from(el.options).forEach(o => { o.selected = o.defaultSelected; });
        else el.value = el.defaultValue;
    });
    else { const f = row.querySelector('.sc-edit input'); if (f) f.focus(); }
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