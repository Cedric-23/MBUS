<?php
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

include "../Includes/csrf.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="icon" href="../Assets/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/mbus_icon.png">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token()); ?>">

    <title>Commuter Dashboard</title>
</head>

<body class="has-sidebar">

    <!-- Mobile Sidebar Toggle - Fixed, always accessible -->
    <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
        </div>

        <h2>Commuter</h2>

        <nav class="menu-top" aria-label="Commuter menu">
                    <a href="commuter_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
                    <a href="my_reservations.php"><i class="fa fa-ticket" aria-hidden="true"></i> My Reservations</a>
                    <a href="history.php"><i class="fa fa-history" aria-hidden="true"></i> History</a>
                    <a href="profile.php"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                    <a href="verification.php"><i class="fa fa-id-card" aria-hidden="true"></i> Verification</a>
                </nav>

                <div class="menu-bottom">
                    <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
                        <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
                    </a>
                </div>

    </aside>

    <!-- MAIN -->
    <main class="main" role="main">

        <!-- PAGE HEADER - Part of page flow (scrolls with content), like schedule.php -->
        <header class="page-header" role="banner">
            <div class="page-header-left">
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
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

            <!-- QUICK ACTIONS -->
            <section class="cards" aria-labelledby="cards-heading">
                <h2 id="cards-heading" class="visually-hidden">Quick Actions</h2>

                <!-- TODAY -->
                <article class="card card-animated">
                    <h3 class="day">Today</h3>
                    <p class="date"><?php echo date("F d"); ?></p>
                    <p>View today's available bus schedules.</p>
                    <a href="schedule.php?filter=today" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- TOMORROW -->
                <article class="card card-animated">
                    <h3 class="day">Tomorrow</h3>
                    <p class="date"><?php echo date("F d", strtotime("+1 day")); ?></p>
                    <p>Check schedules for tomorrow.</p>
                    <a href="schedule.php?filter=tomorrow" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- THIS WEEK -->
                <article class="card card-animated">
                    <h3 class="day">This Week</h3>
                    <p class="date">
                        <?php echo date("M d"); ?> -
                        <?php echo date("M d", strtotime("+6 days")); ?>
                    </p>
                    <p>Browse schedules available this week.</p>
                    <a href="schedule.php?filter=thisweek" class="btn btn-primary">Open Schedule</a>
                </article>

                <!-- NEXT WEEK -->
                <article class="card card-animated">
                    <h3 class="day">Next Week</h3>
                    <p class="date">
                        <?php echo date("M d", strtotime("+7 days")); ?> -
                        <?php echo date("M d", strtotime("+13 days")); ?>
                    </p>
                    <p>View upcoming schedules for next week.</p>
                    <a href="schedule.php?filter=nextweek" class="btn btn-primary">Open Schedule</a>
                </article>

            </section>

            <!-- NOTICE -->
            <section class="notice card" aria-labelledby="notice-heading">
                <h3 id="notice-heading">Travel Reminder</h3>
                <p>Please arrive at least 15 minutes before your departure time.</p>
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
function toggleNotifications() {
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