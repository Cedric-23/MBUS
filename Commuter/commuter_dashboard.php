<?php
session_start();

/* CHECK LOGIN */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/modern_theme.css">
    <link rel="stylesheet" href="../Assets/css/commuter_dashboard.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="icon" href="../Assets/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/mbus_icon.png">

    <title>Commuter Dashboard</title>
</head>

<body>

    <!-- Mobile Sidebar Toggle -->
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fa fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <div class="sidebar">

        <div class="logo-container">
            <img src="../Assets/images/mbus_logo.png" class="logo">
        </div>

        <h2>Commuter</h2>

        <div class="menu-top">
            <a href="commuter_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
            <a href="my_reservations.php"><i class="fa fa-ticket"></i> My Reservations</a>
            <a href="history.php"><i class="fa fa-history"></i> History</a>
            <a href="profile.php"><i class="fa fa-user"></i> My Profile</a>
            <a href="verification.php"><i class="fa fa-id-card"></i> Verification</a>
            
            <!-- Notification Bell -->
            <div class="notification-container">
                <div class="notification-bell" onclick="toggleNotifications()">
                    <i class="fa fa-bell"></i>
                    <span class="notification-badge" id="notificationBadge">0</span>
                </div>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-dropdown-header">
                        Notifications
                    </div>
                    <div id="notificationList">
                        <div class="notification-empty">Loading notifications...</div>
                    </div>
                </div>
            </div>
        </div>

        <a href="../logout.php" class="logout-btn">
            <i class="fa fa-sign-out"></i> Logout
        </a>

    </div>

    <!-- MAIN -->
    <div class="main">

        <!-- WELCOME -->
        <div class="welcome">
            <h1>Welcome, <?php echo $_SESSION['full_name']; ?>!</h1>
            <p>Manage your schedules, reservations, and upcoming trips easily.</p>
        </div>

        <!-- DASHBOARD CARDS -->
        <div class="cards">

            <!-- TODAY -->
            <div class="card glass-card card-animated">
                <h3 class="day">Today</h3>
                <p class="date"><?php echo date("F d"); ?></p>
                <p>View today's available bus schedules.</p>
                <a href="schedule.php?filter=today" class="btn-modern btn-primary">Open Schedule</a>
            </div>

            <!-- TOMORROW -->
            <div class="card glass-card card-animated">
                <h3 class="day">Tomorrow</h3>
                <p class="date"><?php echo date("F d", strtotime("+1 day")); ?></p>
                <p>Check schedules for tomorrow.</p>
                <a href="schedule.php?filter=tomorrow" class="btn-modern btn-primary">Open Schedule</a>
            </div>

            <!-- THIS WEEK -->
            <div class="card glass-card card-animated">
                <h3 class="day">This Week</h3>
                <p class="date">
                    <?php echo date("M d"); ?> -
                    <?php echo date("M d", strtotime("+6 days")); ?>
                </p>
                <p>Browse schedules available this week.</p>
                <a href="schedule.php?filter=thisweek" class="btn-modern btn-primary">Open Schedule</a>
            </div>

            <!-- NEXT WEEK -->
            <div class="card glass-card card-animated">
                <h3 class="day">Next Week</h3>
                <p class="date">
                    <?php echo date("M d", strtotime("+7 days")); ?> -
                    <?php echo date("M d", strtotime("+13 days")); ?>
                </p>
                <p>View upcoming schedules for next week.</p>
                <a href="schedule.php?filter=nextweek" class="btn-modern btn-primary">Open Schedule</a>
            </div>

        </div>

        <!-- NOTICE -->
        <div class="notice">
            <h3>Travel Reminder</h3>
            <p>Please arrive at least 15 minutes before your departure time.</p>
        </div>

    </div>

<script>
let notificationDropdownOpen = false;

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}

// Toggle notification dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.add('active');
        loadNotifications();
    } else {
        dropdown.classList.remove('active');
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
             onclick="markAsRead(${notif.id})">
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

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();
</script>

</body>
</html>