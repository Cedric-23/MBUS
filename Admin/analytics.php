<?php

session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";

if(!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin'){
    header("Location: ../login.php");
    exit();
}

/* GET ANALYTICS DATA */

// Total reservations
$total_reservations_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM reservation");
$total_reservations = mbus_db_fetch_assoc($total_reservations_query)['count'];

// Total revenue (paid reservations)
$total_revenue_query = mbus_db_query($conn, "SELECT SUM(amount) as total FROM payment WHERE status = 'Completed'");
$total_revenue = mbus_db_fetch_assoc($total_revenue_query)['total'] ?? 0;

// Total users
$total_users_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM users");
$total_users = mbus_db_fetch_assoc($total_users_query)['count'];

// Active schedules
$active_schedules_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM schedule WHERE schedule_status = 'Active'");
$active_schedules = mbus_db_fetch_assoc($active_schedules_query)['count'];

// Total buses
$total_buses_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM buses");
$total_buses = mbus_db_fetch_assoc($total_buses_query)['count'];

// Pending verifications
$pending_verifications_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM commuter_verification WHERE verification_status = 'Pending'");
$pending_verifications = mbus_db_fetch_assoc($pending_verifications_query)['count'];

// Pending operator applications
$pending_applications_query = mbus_db_query($conn, "SELECT COUNT(*) as count FROM operator_application WHERE application_status = 'Pending'");
$pending_applications = mbus_db_fetch_assoc($pending_applications_query)['count'];

// Reservations by status
$reservations_by_status_query = mbus_db_query($conn, "
    SELECT status, COUNT(*) as count 
    FROM reservation 
    GROUP BY status
");
$reservations_by_status = [];
while($row = mbus_db_fetch_assoc($reservations_by_status_query)){
    $reservations_by_status[$row['status']] = $row['count'];
}

// Reservations this month
$reservations_this_month_query = mbus_db_query($conn, "
    SELECT COUNT(*) as count 
    FROM reservation 
    WHERE MONTH(reservation_date) = MONTH(CURRENT_DATE) 
    AND YEAR(reservation_date) = YEAR(CURRENT_DATE)
");
$reservations_this_month = mbus_db_fetch_assoc($reservations_this_month_query)['count'];

// Revenue this month
$revenue_this_month_query = mbus_db_query($conn, "
    SELECT SUM(amount) as total 
    FROM payment 
    WHERE MONTH(payment_date) = MONTH(CURRENT_DATE) 
    AND YEAR(payment_date) = YEAR(CURRENT_DATE)
    AND status = 'Completed'
");
$revenue_this_month = mbus_db_fetch_assoc($revenue_this_month_query)['total'] ?? 0;

// Recent activity logs
$recent_activity_query = mbus_db_query($conn, "
    SELECT al.*, u.full_name 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - MBUS Admin</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/analytics.css">
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
        <a href="analytics.php" aria-current="page"><i class="fa fa-chart-line" aria-hidden="true"></i> Analytics</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_users.php"><i class="fa fa-users" aria-hidden="true"></i> Manage Users</a>
        <a href="manage_verifications.php"><i class="fa fa-id-card" aria-hidden="true"></i> Verifications</a>
        <a href="manage_applications.php"><i class="fa fa-user-plus" aria-hidden="true"></i> Applications</a>
        <a href="activity_logs.php"><i class="fa fa-history" aria-hidden="true"></i> Activity Logs</a>
    </nav>
    
    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">

<!-- PAGE HEADER - Part of page flow (scrolls with content), like schedule.php -->
<header class="page-header" role="banner">
    <div class="page-header-left">
        <h1>Analytics Dashboard</h1>
    </div>
    <div class="page-header-right">
        <!-- Notification Bell - Top right, part of header flow -->
        <div class="notification-container">
            <button class="notification-bell" onclick="toggleNotifications(event)" aria-label="Notifications" aria-expanded="false" aria-controls="notificationDropdown">
                <i class="fa fa-bell" aria-hidden="true"></i>
                <span class="notification-badge" id="notificationBadge" aria-live="polite">0</span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown" role="menu" aria-label="Notifications">
                <div class="notification-dropdown-header" data-count="0">
                    <span>Notifications</span>
                </div>
                <div class="notification-list" id="notificationList" role="list">
                    <div class="notification-empty">Loading notifications...</div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Dashboard Content -->
<div class="dashboard-content">

    <!-- KEY METRICS -->
    <section class="cards" aria-labelledby="metrics-heading">
        <h2 id="metrics-heading" class="visually-hidden">Key Metrics</h2>
        
        <article class="card card-animated">
            <h3 class="day"><?php echo number_format($total_reservations); ?></h3>
            <p class="date">Total Reservations</p>
            <p>This month: <?php echo number_format($reservations_this_month); ?></p>
            <a href="manage_schedules.php" class="btn btn-primary">View Reservations</a>
        </article>
        
        <article class="card card-animated">
            <h3 class="day">₱<?php echo number_format($total_revenue, 2); ?></h3>
            <p class="date">Total Revenue</p>
            <p>This month: ₱<?php echo number_format($revenue_this_month, 2); ?></p>
            <a href="manage_schedules.php" class="btn btn-primary">View Revenue</a>
        </article>
        
        <article class="card card-animated">
            <h3 class="day"><?php echo number_format($total_users); ?></h3>
            <p class="date">Total Users</p>
            <p>Registered accounts</p>
            <a href="manage_users.php" class="btn btn-primary">Manage Users</a>
        </article>
        
        <article class="card card-animated">
            <h3 class="day"><?php echo number_format($active_schedules); ?></h3>
            <p class="date">Active Schedules</p>
            <p>Currently active</p>
            <a href="manage_schedules.php" class="btn btn-primary">Manage Schedules</a>
        </article>

        <article class="card card-animated">
            <h3 class="day"><?php echo number_format($total_buses); ?></h3>
            <p class="date">Total Buses</p>
            <p>In fleet</p>
            <a href="manage_buses.php" class="btn btn-primary">Manage Buses</a>
        </article>
        
        <article class="card card-animated">
            <h3 class="day"><?php echo number_format($pending_verifications + $pending_applications); ?></h3>
            <p class="date">Pending Actions</p>
            <p><?php echo $pending_verifications; ?> verifications, <?php echo $pending_applications; ?> applications</p>
            <a href="manage_verifications.php" class="btn btn-primary">Review Pending</a>
        </article>
    </section>

    <!-- CHARTS SECTION -->
    <section class="chart-card card" aria-labelledby="status-chart-heading" style="margin-bottom: var(--space-6);">
        <h2 id="status-chart-heading">Reservations by Status</h2>
        <div class="chart-container">
            <?php if(!empty($reservations_by_status)): ?>
            <div class="bar-chart">
                <?php foreach($reservations_by_status as $status => $count): 
                    $percentage = $total_reservations > 0 ? ($count / $total_reservations) * 100 : 0;
                ?>
                <div class="bar-item">
                    <div class="bar-label"><?php echo $status; ?></div>
                    <div class="bar">
                        <div class="bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                    </div>
                    <div class="bar-value"><?php echo $count; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="empty-state">No reservation data available</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- QUICK ACTIONS -->
    <section class="chart-card card" aria-labelledby="quick-actions-heading" style="margin-bottom: var(--space-6);">
        <h2 id="quick-actions-heading">Quick Actions</h2>
        <div class="quick-actions">
            <a href="manage_verifications.php" class="action-btn <?php echo $pending_verifications > 0 ? 'urgent' : ''; ?>">
                <i class="fa fa-id-card" aria-hidden="true"></i>
                <span>Review Verifications</span>
                <span class="badge badge-primary"><?php echo $pending_verifications; ?></span>
            </a>
            <a href="manage_applications.php" class="action-btn <?php echo $pending_applications > 0 ? 'urgent' : ''; ?>">
                <i class="fa fa-user-plus" aria-hidden="true"></i>
                <span>Review Applications</span>
                <span class="badge badge-primary"><?php echo $pending_applications; ?></span>
            </a>
            <a href="manage_schedules.php" class="action-btn">
                <i class="fa fa-calendar-plus" aria-hidden="true"></i>
                <span>Add New Schedule</span>
            </a>
            <a href="manage_routes.php" class="action-btn">
                <i class="fa fa-route" aria-hidden="true"></i>
                <span>Manage Routes</span>
            </a>
        </div>
    </section>

    <!-- RECENT ACTIVITY -->
    <section class="chart-card card" aria-labelledby="activity-heading">
        <h2 id="activity-heading">Recent Activity</h2>
        <div class="activity-list">
            <?php if(mbus_db_num_rows($recent_activity_query) > 0): ?>
            <?php while($activity = mbus_db_fetch_assoc($recent_activity_query)): ?>
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fa fa-circle-info" aria-hidden="true"></i>
                </div>
                <div class="activity-details">
                    <p class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></p>
                    <p class="activity-description"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                    <p class="activity-meta">
                        <span><?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?></span>
                        <span>•</span>
                        <span><?php echo date('M d, Y g:i A', strtotime($activity['created_at'])); ?></span>
                    </p>
                </div>
            </div>
            <?php endwhile; ?>
            <?php else: ?>
            <p class="empty-state">No recent activity</p>
            <?php endif; ?>
        </div>
    </section>

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
    
    // Add/remove body class for CSS to hide hamburger
    document.body.classList.toggle('sidebar-open', isOpen);
    
    // Trap focus when sidebar is open on mobile
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        // Focus first focusable element in sidebar
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    } else {
        document.body.style.overflow = '';
    }
}

// Toggle notification dropdown
function toggleNotifications(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('notificationDropdown');
    const bell = document.querySelector('.notification-bell');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.add('active');
        if (bell) bell.setAttribute('aria-expanded', 'true');
        loadNotifications();
    } else {
        dropdown.classList.remove('active');
        if (bell) bell.setAttribute('aria-expanded', 'false');
    }
}

// Load notifications
function loadNotifications() {
    fetch('../api/get_notifications.php')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.unread_count);
            renderNotifications(data.notifications);
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
        });
}

// Update notification badge
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
    
    // Also update the dropdown header count
    const header = document.querySelector('.notification-dropdown-header');
    if (header) {
        header.setAttribute('data-count', count);
    }
}

// Render notifications
function renderNotifications(notifications) {
    const list = document.getElementById('notificationList');
    
    if (notifications.length === 0) {
        list.innerHTML = '<div class="notification-empty">No notifications</div>';
        return;
    }
    
    list.innerHTML = notifications.map(notif => `
        <div class="notification-item ${notif.status === 'Unread' ? 'unread' : ''}" 
             onclick="markAsRead(${notif.id})" role="listitem">
            <div class="notification-message">${notif.message}</div>
            <div class="notification-time">${notif.time_ago}</div>
        </div>
    `).join('');
}

// Mark notification as read
function markAsRead(notificationId) {
    const formData = new FormData();
    formData.append('notification_id', notificationId);
    
    fetch('../api/mark_notification_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const container = document.querySelector('.notification-container');
    if (!container.contains(event.target) && notificationDropdownOpen) {
        toggleNotifications();
    }
});

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

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();
</script>

</body>
</html>