<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

/* AUTO EXPIRE */
mbus_db_prepare($conn, "
    UPDATE reservation
    SET status='Cancelled'
    WHERE status='Pending'
    AND created_at <= NOW() - INTERVAL 5 MINUTE
", []);

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* RESERVATIONS - prepared statement */
$sql = mbus_db_prepare($conn, "
    SELECT
        reservation.reservation_id,
        reservation.schedule_id,
        reservation.seat_number,
        reservation.pickup_location,
        reservation.destination,
        reservation.status,
        reservation.created_at,
        schedule.departure_time,
        schedule.arrival_time,
        routes.origin,
        routes.destination AS route_destination,
        buses.bus_number
    FROM reservation
    JOIN schedule ON reservation.schedule_id = schedule.schedule_id
    JOIN routes ON schedule.route_id = routes.route_id
    JOIN buses ON schedule.bus_id = buses.bus_id
    WHERE reservation.user_id = ?
    ORDER BY reservation.reservation_id DESC
", [$user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/my_reservation.css">
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
                <a href="my_reservations.php" aria-current="page"><i class="fa fa-ticket" aria-hidden="true"></i> My Reservations</a>
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

<main class="main" role="main">

<header class="page-header">
    <h1>My Reservations</h1>
</header>

<?php if(mbus_db_num_rows($sql) > 0){ ?>
    <?php while($row = mbus_db_fetch_assoc($sql)){ ?>
    <?php
    $statusClass = strtolower($row['status']);
    $remaining = 0;

    if($row['status'] === "Pending"){
        $created = strtotime($row['created_at']);
        $expire = $created + (5 * 60);
        $remaining = $expire - time();

        if($remaining < 0){
            mbus_db_prepare($conn, "UPDATE reservation SET status='Cancelled' WHERE reservation_id=?", [$row['reservation_id']]);
            $row['status'] = "Cancelled";
            $statusClass = "cancelled";
        }
    }
    ?>

    <article class="card reservation-card" data-reservation-id="<?php echo $row['reservation_id']; ?>">

    <div class="reservation-header">
        <div class="bus-info">
            <span class="badge badge-primary">Bus <?php echo htmlspecialchars($row['bus_number']); ?></span>
        </div>
        <div class="status-badge status-<?php echo $statusClass; ?>">
            <?php echo htmlspecialchars($row['status']); ?>
        </div>
    </div>

    <div class="reservation-route">
        <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
        <span><?php echo htmlspecialchars($row['origin']); ?> &#8594; <?php echo htmlspecialchars($row['route_destination']); ?></span>
    </div>

    <ul class="reservation-details">
        <li><strong>Seat:</strong> <?php echo htmlspecialchars($row['seat_number']); ?></li>
        <li><strong>Trip:</strong> <?php echo htmlspecialchars($row['pickup_location']); ?> &#8594; <?php echo htmlspecialchars($row['destination']); ?></li>
        <li><strong>Departure:</strong> <?php echo date("F d, Y g:i A", strtotime($row['departure_time'])); ?></li>
        <li><strong>Expected Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($row['arrival_time'])); ?></li>
    </ul>

    <?php if($row['status'] === "Pending"){ ?>

    <div class="reservation-timer">
        <span class="timer-label">Time Remaining:</span>
        <span class="timer-value countdown" data-seconds="<?php echo $remaining; ?>"><?php echo gmdate("i:s", $remaining); ?></span>
    </div>

    <div class="reservation-actions btn-group">
        <a class="btn btn-primary" href="payment.php?schedule_id=<?php echo $row['schedule_id']; ?>">
            <i class="fa fa-credit-card" aria-hidden="true"></i> Pay Now
        </a>
        <button type="button" class="btn btn-outline cancel-btn" onclick="cancelReservation(<?php echo $row['reservation_id']; ?>, this)" aria-label="Cancel reservation for seat <?php echo htmlspecialchars($row['seat_number']); ?>">
            <i class="fa fa-times" aria-hidden="true"></i> Cancel
        </button>
    </div>

    <?php } ?>

    <?php if($row['status'] === "Paid"){ ?>
    <div class="reservation-actions btn-group">
        <a class="btn btn-success" href="ticket.php?schedule_id=<?php echo $row['schedule_id']; ?>">
            <i class="fa fa-ticket" aria-hidden="true"></i> View Ticket
        </a>
    </div>
    <?php } ?>

    <?php if($row['status'] === "Cancelled"){ ?>
    <div class="reservation-actions">
        <div class="status-badge status-cancelled">Reservation Cancelled</div>
    </div>
    <?php } ?>

    </article>

    <?php } ?>

<?php }else{ ?>

<div class="card empty-state" role="status">
    <i class="fa fa-ticket" aria-hidden="true" style="font-size: var(--fs-3xl); color: var(--color-text-muted); margin-bottom: var(--space-4);"></i>
    <h3>No reservations found.</h3>
    <p>You don't have any active reservations yet.</p>
    <a href="schedule.php" class="btn btn-primary" style="margin-top: var(--space-4);">
        <i class="fa fa-bus" aria-hidden="true"></i> Browse Schedules
    </a>
</div>

<?php } ?>

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
    
    // Add/remove body class for CSS to hide hamburger
    document.body.classList.toggle('sidebar-open', isOpen);
    
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

// Countdown timers for pending reservations
function startCountdowns() {
    document.querySelectorAll('.countdown').forEach(el => {
        let seconds = parseInt(el.dataset.seconds, 10);
        if (isNaN(seconds) || seconds <= 0) return;
        
        const update = () => {
            if (seconds <= 0) {
                el.textContent = 'Expired';
                el.classList.add('expired');
                // Reload page to show cancelled status
                setTimeout(() => location.reload(), 1000);
                return;
            }
            const m = String(Math.floor(seconds / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            el.textContent = m + ':' + s;
            seconds--;
        };
        update();
        setInterval(update, 1000);
    });
}

// Cancel reservation via AJAX
function cancelReservation(reservationId, btn) {
    if (!confirm('Cancel this reservation?')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Cancelling...';
    
    // Get CSRF token from a hidden input or meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('cancel_reservation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'reservation_id=' + reservationId + '&csrf_token=' + csrfToken
    })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === 'ok') {
            // Show success feedback
            btn.closest('.reservation-card').querySelector('.status-badge').textContent = 'Cancelled';
            btn.closest('.reservation-card').querySelector('.status-badge').className = 'status-badge status-cancelled';
            btn.closest('.reservation-actions').innerHTML = '<div class="status-badge status-cancelled">Reservation Cancelled</div>';
            showToast('Reservation cancelled');
        } else {
            alert('Failed: ' + resp);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-times"></i> Cancel';
        }
    })
    .catch(() => {
        alert('Network error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-times"></i> Cancel';
    });
}

// Simple toast notification
function showToast(message) {
    // Remove existing toast
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
    
    // Animate in
    requestAnimationFrame(() => toast.classList.add('show'));
    
    // Remove after 3s
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Init
document.addEventListener('DOMContentLoaded', startCountdowns);
</script>

<style>
/* Toast notification - mobile optimized */
.toast {
    position: fixed;
    bottom: var(--space-4);
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: var(--color-text);
    color: var(--color-bg);
    padding: var(--space-3) var(--space-5);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    z-index: var(--z-toast);
    font-size: var(--fs-sm);
    font-weight: 500;
    opacity: 0;
    transition: transform var(--transition-normal), opacity var(--transition-normal);
    max-width: calc(100vw - var(--space-8));
    text-align: center;
}

.toast.show {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}

.countdown.expired {
    color: var(--color-error);
    font-weight: 700;
}

/* Mobile reservation card improvements */
@media (max-width: 768px) {
    .reservation-card {
        padding: var(--space-4);
    }
    
    .reservation-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-2);
    }
    
    .reservation-timer {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        padding: var(--space-2) var(--space-3);
        background: var(--color-warning-light);
        border-radius: var(--radius-md);
        margin-top: var(--space-3);
    }
    
    .timer-label {
        font-size: var(--fs-xs);
        font-weight: 600;
        color: var(--color-warning-dark);
    }
    
    .timer-value {
        font-family: var(--font-mono);
        font-size: var(--fs-lg);
        font-weight: 700;
        color: var(--color-warning-dark);
    }
    
    .reservation-actions {
        margin-top: var(--space-3);
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

</body>
</html>