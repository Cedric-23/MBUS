<?php
session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin'){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";
include "../Includes/csrf.php";

// Handle mark as read
if(isset($_POST['mark_read'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $notification_id = (int)$_POST['notification_id'];
        mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE notification_id = ?", [$notification_id]);
        $success = "Notification marked as read";
    }
}

// Handle mark all as read
if(isset($_POST['mark_all_read'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE user_id = ? AND status = 'Unread'", [$user_id]);
        $success = "All notifications marked as read";
    }
}

// Handle delete
if(isset($_POST['delete'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $notification_id = (int)$_POST['notification_id'];
        mbus_db_prepare($conn, "DELETE FROM notification WHERE notification_id = ?", [$notification_id]);
        $success = "Notification deleted";
    }
}

// Handle clear all
if(isset($_POST['clear_all'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        mbus_db_prepare($conn, "DELETE FROM notification WHERE user_id = ?", [$user_id]);
        $success = "All notifications cleared";
    }
}

// Fetch notifications
$user_id = $_SESSION['user_id'];
$notifications = mbus_db_prepare($conn, "
    SELECT * FROM notification 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
", [$user_id]);

$unread_count = mbus_db_prepare($conn, "
    SELECT COUNT(*) as count FROM notification 
    WHERE user_id = ? AND status = 'Unread'
", [$user_id]);
$unread_count = mbus_db_fetch_assoc($unread_count)['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-notifications.css">
    <link rel="icon" href="../Assets/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/mbus_icon.png">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
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
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
        <a href="notifications.php" aria-current="page"><i class="fa fa-bell" aria-hidden="true"></i> Notifications</a>
    </nav>

    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Notifications</h1>
</header>

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Stats Card -->
<section class="card" style="margin-bottom: var(--space-6);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-4);">
        <div>
            <h3 style="margin: 0 0 var(--space-1); font-size: var(--fs-lg); font-weight: var(--fw-semibold);">
                <i class="fa fa-bell" aria-hidden="true"></i> <?php echo $unread_count; ?> Unread
            </h3>
            <p style="margin: 0; color: var(--color-text-muted); font-size: var(--fs-sm);">
                Total: <?php echo mbus_db_num_rows($notifications); ?> notifications
            </p>
        </div>
        <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
            <form method="POST" style="display: inline;">
                <?php csrf_field(); ?>
                <button type="submit" name="mark_all_read" class="btn btn-secondary">
                    <i class="fa fa-check-double" aria-hidden="true"></i> Mark All Read
                </button>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clear all notifications?');">
                <?php csrf_field(); ?>
                <button type="submit" name="clear_all" class="btn btn-danger">
                    <i class="fa fa-trash" aria-hidden="true"></i> Clear All
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Notifications List -->
<section class="card">
    <?php if(mbus_db_num_rows($notifications) > 0): ?>
        <div class="notification-list-page">
            <?php while($notif = mbus_db_fetch_assoc($notifications)): ?>
            <div class="notification-item-page <?php echo $notif['status'] === 'Unread' ? 'unread' : ''; ?>">
                <div class="notification-content">
                    <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                    <div class="notification-time"><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></div>
                </div>
                <div class="notification-actions-page">
                    <?php if($notif['status'] === 'Unread'): ?>
                    <form method="POST" style="display: inline;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                        <button type="submit" name="mark_read" class="btn btn-sm btn-outline">
                            <i class="fa fa-check" aria-hidden="true"></i> Mark Read
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                        <button type="submit" name="delete" class="btn btn-sm btn-danger">
                            <i class="fa fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="notification-empty-page">
            <i class="fa fa-bell-slash" aria-hidden="true"></i>
            <p>No notifications</p>
        </div>
    <?php endif; ?>
</section>

</main>

<script>
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

document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

document.addEventListener('keydown', function(e) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});
</script>

</body>
</html>
