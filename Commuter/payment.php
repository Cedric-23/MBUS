<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if(!isset($_GET['schedule_id'])){
    die("Invalid request.");
}

$schedule_id = mbus_db_escape($conn, $_GET['schedule_id']);

// Authorization check - verify user has pending reservations for this schedule
$auth_check = mbus_db_prepare($conn,"
    SELECT COUNT(*) as count
    FROM reservation
    WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'
", [$user_id, $schedule_id]);

$auth_result = mbus_db_fetch_assoc($auth_check);

if($auth_result['count'] <= 0){
    die("No pending reservation found for this schedule.");
}

// Get reservation details with prepared statement
$res_query = mbus_db_prepare($conn, "
SELECT reservation.*, schedule.departure_time, schedule.arrival_time,
routes.origin, routes.destination AS route_destination,
buses.bus_number
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN routes ON schedule.route_id = routes.route_id
JOIN buses ON schedule.bus_id = buses.bus_id
WHERE reservation.user_id = ?
AND reservation.schedule_id = ?
AND reservation.status = 'Pending'
", [$user_id, $schedule_id]);

if(mbus_db_num_rows($res_query) <= 0){
    die("No pending reservation found.");
}

$reservations = [];
while($row = mbus_db_fetch_assoc($res_query)){
    $reservations[] = $row;
}

$created_at = strtotime($reservations[0]['created_at']);
$expiry = $created_at + (10 * 60); // 10 minutes matching reserve.php

$total = isset($_GET['total']) ? floatval($_GET['total']) : 0;

// Calculate total if not provided
if($total <= 0){
    foreach($reservations as $r){
        $pickup = $r['pickup_location'];
        $dest = $r['destination'];
        // fare_table would need to be passed or calculated
        // For now use GET total
    }
}

$error = "";

/* CONFIRM PAYMENT */
if(isset($_POST['confirm_payment'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $method = mbus_db_escape($conn, $_POST['payment_method']);
        $reference = mbus_db_escape($conn, $_POST['reference_number']);

        if(empty($reference)){
            $error = "Reference number required.";
        } else {
            $ticket_code = "MB-" . rand(1000,9999);

            // Use transaction for payment processing
            $conn->pdo->beginTransaction();

            try{
                $get_res = mbus_db_prepare($conn, "
                SELECT reservation_id FROM reservation
                WHERE user_id = ?
                AND schedule_id = ?
                AND status = 'Pending'
                LIMIT 1
                ", [$user_id, $schedule_id]);

                $res_data = mbus_db_fetch_assoc($get_res);

                if(!$res_data){
                    throw new Exception("No pending reservation found");
                }

                $res_id = $res_data['reservation_id'];

                mbus_db_prepare($conn, "
                INSERT INTO payment(
                reservation_id, amount, payment_method,
                payment_reference, payment_status,
                ticket_code, payment_date
                ) VALUES(
                ?, ?, ?, ?, ?, ?, NOW()
                )
                ", [$res_id, $total, $method, $reference, 'Paid', $ticket_code]);

                $update = mbus_db_prepare($conn, "
                UPDATE reservation
                SET status = 'Paid'
                WHERE user_id = ?
                AND schedule_id = ?
                AND status = 'Pending'
                ", [$user_id, $schedule_id]);

                $conn->pdo->commit();

                if($update){
                    // Log payment
                    log_activity($conn, $user_id, ACTION_PAYMENT, "Payment completed for schedule $schedule_id, ticket: $ticket_code");
                    
                    // Send email confirmation
                    $user_email_query = mbus_db_query($conn, "SELECT email FROM users WHERE user_id = '$user_id'");
                    $user_email_data = mbus_db_fetch_assoc($user_email_query);
                    $user_email = $user_email_data['email'];
                    
                    $route_info = "$reservations[0][origin] → $reservations[0][route_destination]";
                    $departure_formatted = date('F d, Y g:i A', strtotime($reservations[0]['departure_time']));
                    
                    $email_subject = "Payment Confirmed - Ticket: $ticket_code";
                    $email_message = "Dear Commuter,

Your payment has been confirmed!

Ticket: $ticket_code
Route: $route_info
Departure: $departure_formatted
Amount: ₱" . number_format($total, 2) . "

Thank you for using MBUS!";
                    send_email_notification($user_email, $email_subject, $email_message);
                    
                    header("Location: ticket.php?schedule_id=$schedule_id");
                    exit();
                }
            } catch(Exception $e){
                $conn->pdo->rollBack();
                $error = "Payment failed: " . $e->getMessage();
            }
        }
    }
}

/* CANCEL */
if(isset($_POST['cancel_reservation'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        mbus_db_prepare($conn, "
        UPDATE reservation
        SET status='Cancelled'
        WHERE user_id=?
        AND schedule_id=?
        AND status='Pending'
        ", [$user_id, $schedule_id]);
        
        // Log cancellation
        log_activity($conn, $user_id, ACTION_RESERVATION_CANCEL, "Cancelled pending reservation for schedule $schedule_id");
        
        header("Location: schedule.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payment</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Assets/css/responsive.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_payment.css">

</head>

<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle btn btn-icon" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

<div class="logo-container">
<img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
</div>

<h2>Commuter</h2>

<nav class="menu-top" aria-label="Commuter menu">
<a href="commuter_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
<a href="my_reservations.php"><i class="fa fa-ticket" aria-hidden="true"></i> My Reservations</a>
<a href="history.php"><i class="fa fa-history" aria-hidden="true"></i> History</a>

<!-- Notification Bell -->
<div class="notification-container">
    <button class="notification-bell" onclick="toggleNotifications()" aria-label="Notifications" aria-expanded="false" aria-controls="notificationDropdown">
        <i class="fa fa-bell" aria-hidden="true"></i>
        <span class="notification-badge" id="notificationBadge" aria-live="polite">0</span>
    </button>
    <div class="notification-dropdown" id="notificationDropdown" role="menu" aria-label="Notifications">
        <div class="notification-dropdown-header">
            Notifications
        </div>
        <div id="notificationList" role="list">
            <div class="notification-empty">Loading notifications...</div>
        </div>
    </div>
</div>
</nav>

<a href="../logout.php" class="logout-btn btn btn-outline btn-block">
<i class="fa fa-sign-out" aria-hidden="true"></i> Logout
</a>

</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Online Payment</h1>
</header>

<div class="payment-layout">

<!-- LEFT PANEL -->
<div class="payment-panel payment-panel-main">

<div class="card trip-summary">

<div class="route-badge">
<?php echo htmlspecialchars($reservations[0]['origin']); ?> &#8594; <?php echo htmlspecialchars($reservations[0]['route_destination']); ?>
</div>

<ul class="trip-details">
    <li><strong>Bus:</strong> <?php echo htmlspecialchars($reservations[0]['bus_number']); ?></li>
    <li><strong>Departure:</strong> <?php echo date("F d, Y g:i A", strtotime($reservations[0]['departure_time'])); ?></li>
    <li><strong>Expected Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($reservations[0]['arrival_time'])); ?></li>
    <li><strong>Pickup:</strong> <?php echo htmlspecialchars($reservations[0]['pickup_location']); ?></li>
    <li><strong>Destination:</strong> <?php echo htmlspecialchars($reservations[0]['destination']); ?></li>
</ul>

<div class="seats-section">
    <strong>Reserved Seats:</strong>
    <div class="seat-list">
        <?php foreach($reservations as $r){ ?>
        <span class="seat-tag">Seat <?php echo htmlspecialchars($r['seat_number']); ?></span>
        <?php } ?>
    </div>
</div>

</div>

</div>

<!-- RIGHT PANEL -->
<div class="payment-panel payment-panel-sidebar">

<div class="card" id="paymentSummaryCard">

<div class="timer-box" id="timerBox">
    <i class="fa fa-clock" aria-hidden="true"></i>
    <span>Expires in: </span>
    <span id="countdown">--:--</span>
</div>

<?php if($error): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<form method="POST" class="payment-form">
    <?php csrf_field(); ?>
    
    <fieldset>
        <legend>Payment Method</legend>
        
        <div class="payment-method">
            <input type="radio" name="payment_method" id="method_gcash" value="GCash" required>
            <label for="method_gcash">
                <i class="fa fa-mobile-alt" aria-hidden="true"></i>
                GCash
            </label>
        </div>
        
        <div class="payment-method">
            <input type="radio" name="payment_method" id="method_maya" value="Maya">
            <label for="method_maya">
                <i class="fa fa-wallet" aria-hidden="true"></i>
                Maya
            </label>
        </div>
        
        <div class="payment-method">
            <input type="radio" name="payment_method" id="method_bank" value="Bank Transfer">
            <label for="method_bank">
                <i class="fa fa-university" aria-hidden="true"></i>
                Bank Transfer
            </label>
        </div>
    </fieldset>

    <div class="form-group">
        <label for="reference_number">Reference Number <span class="required" aria-hidden="true">*</span></label>
        <input type="text" name="reference_number" id="reference_number" class="form-control" placeholder="Enter transaction reference number" required minlength="10">
        <small class="form-hint">Enter the reference number from your payment app</small>
    </div>

    <button type="submit" name="confirm_payment" class="btn btn-primary btn-block">
        <i class="fa fa-check" aria-hidden="true"></i> Confirm Payment
    </button>
</form>

<form method="POST" class="cancel-form">
    <?php csrf_field(); ?>
    <button type="submit" name="cancel_reservation" class="btn btn-outline btn-danger btn-block">
        <i class="fa fa-times" aria-hidden="true"></i> Cancel Reservation
    </button>
</form>

</div>

</div>

</div>

</main>

<script>
let notificationDropdownOpen = false;
let countdownInterval;

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

// Countdown timer
function startCountdown() {
    const expiry = <?php echo $expiry; ?> * 1000; // Convert to milliseconds
    const countdownEl = document.getElementById('countdown');
    const timerBox = document.getElementById('timerBox');
    
    function update() {
        const now = Date.now();
        const diff = expiry - now;
        
        if(diff <= 0){
            countdownEl.textContent = "00:00";
            timerBox.classList.add('expired');
            clearInterval(countdownInterval);
            // Optionally redirect or show expired message
            return;
        }
        
        const minutes = Math.floor(diff / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        countdownEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }
    
    update();
    countdownInterval = setInterval(update, 1000);
}

// Payment method selection visual feedback
document.querySelectorAll('.payment-method input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
        if(this.checked){
            this.closest('.payment-method').classList.add('selected');
        }
    });
});

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

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();
startCountdown();

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