<?php
session_start();

if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'operator'){
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
        $notification_id = (isset($_POST['notification_id']) && is_scalar($_POST['notification_id'])) ? (int)$_POST['notification_id'] : 0;
        $result = mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE notification_id = ? AND user_id = ?", [$notification_id, $_SESSION['user_id']]);
        if($result === false){ $error = "Failed to update notification"; }
        else { $success = "Notification marked as read"; }
    }
}

// Handle mark all as read
if(isset($_POST['mark_all_read'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        $result = mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE user_id = ? AND status = 'Unread'", [$user_id]);
        if($result === false){ $error = "Failed to update notifications"; }
        else { $success = "All notifications marked as read"; }
    }
}

// Handle delete
if(isset($_POST['delete'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $notification_id = (isset($_POST['notification_id']) && is_scalar($_POST['notification_id'])) ? (int)$_POST['notification_id'] : 0;
        $result = mbus_db_prepare($conn, "DELETE FROM notification WHERE notification_id = ? AND user_id = ?", [$notification_id, $_SESSION['user_id']]);
        if($result === false){ $error = "Failed to delete notification"; }
        else { $success = "Notification deleted"; }
    }
}

// Handle clear all
if(isset($_POST['clear_all'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        $result = mbus_db_prepare($conn, "DELETE FROM notification WHERE user_id = ?", [$user_id]);
        if($result === false){ $error = "Failed to clear notifications"; }
        else { $success = "All notifications cleared"; }
    }
}

// Fetch notifications
$user_id = $_SESSION['user_id'];
$notifications = mbus_db_prepare($conn, "
    SELECT * FROM notification
    WHERE user_id = ?
    ORDER BY notification_date DESC
    LIMIT 50
", [$user_id]);

if($notifications === false){
    http_response_code(500);
    die("Unable to load notifications. Please try again.");
}
$unread_result = mbus_db_prepare($conn, "
    SELECT COUNT(*) as count FROM notification
    WHERE user_id = ? AND status = 'Unread'
", [$user_id]);
if($unread_result === false){
    http_response_code(500);
    die("Unable to load notification count. Please try again.");
}
$unread_row = mbus_db_fetch_assoc($unread_result);
$unread_count = (int)($unread_row['count'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Operator</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/professional-notifications.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">
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

    <h2>Operator</h2>

    <nav class="menu-top" aria-label="Operator menu">
        <a href="operator_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
        <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
        <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
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

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Operator account</span>
                        </div>
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
                    <div class="notification-time"><?php echo date('M j, Y g:i A', strtotime($notif['notification_date'])); ?></div>
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

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_trips.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Riders</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
    <a href="live_map.php" class="bottom-nav-item"><i class="fa fa-map-location-dot" aria-hidden="true"></i><span>Live</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/operator_tracker.js"></script>
</body>
</html>
