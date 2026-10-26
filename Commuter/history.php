<?php
session_start();
include "../config/db_connect.php";
include "../Includes/csrf.php";

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* FETCH HISTORY - prepared statement */
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
    AND (reservation.status = 'Boarded' OR reservation.status = 'Cancelled')
    ORDER BY reservation.reservation_id DESC
", [$user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip History</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/commuter_history.css">
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
            <a href="history.php" aria-current="page"><i class="fa fa-history" aria-hidden="true"></i> History</a>
            <a href="profile.php"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
            <a href="verification.php"><i class="fa fa-id-card" aria-hidden="true"></i> Verification</a>
        </nav>

    <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
        <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
    </a>
</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Trip History</h1>
</header>

<div id="history-container">

<?php if(mbus_db_num_rows($sql) > 0){ ?>

<?php while($row = mbus_db_fetch_assoc($sql)){ ?>

<article class="card history-card" data-reservation-id="<?php echo $row['reservation_id']; ?>">

<div class="reservation-header">
    <div class="bus-info">
        <span class="badge badge-primary">Bus <?php echo htmlspecialchars($row['bus_number']); ?></span>
    </div>
    <div class="status-badge status-<?php echo strtolower($row['status']); ?>">
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
    <li><strong>Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($row['arrival_time'])); ?></li>
</ul>

<div class="reservation-actions btn-group">
    <a class="btn btn-secondary" href="ticket.php?schedule_id=<?php echo $row['schedule_id']; ?>">
        <i class="fa fa-ticket" aria-hidden="true"></i> View Ticket
    </a>
    <button type="button" class="btn btn-outline clear-btn" onclick="clearHistory(<?php echo $row['reservation_id']; ?>, this)" aria-label="Clear this trip from history">
        <i class="fa fa-trash" aria-hidden="true"></i> Clear
    </button>
</div>

</article>

<?php } ?>

<?php }else{ ?>

<div class="card empty-state" role="status">
    <i class="fa fa-history" aria-hidden="true" style="font-size: var(--fs-3xl); color: var(--color-text-muted); margin-bottom: var(--space-4);"></i>
    <h3>No trip history found.</h3>
    <p>Your completed and cancelled trips will appear here.</p>
    <a href="schedule.php" class="btn btn-primary" style="margin-top: var(--space-4);">
        <i class="fa fa-bus" aria-hidden="true"></i> Browse Schedules
    </a>
</div>

<?php } ?>

</div>

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

// Clear history via AJAX
function clearHistory(reservationId, btn) {
    if (!confirm('Remove this trip from history?')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Clearing...';
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('clear_history.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'reservation_id=' + reservationId + '&csrf_token=' + csrfToken
    })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === 'ok') {
            // Animate removal
            const card = btn.closest('.history-card');
            card.style.transition = 'opacity 0.3s, transform 0.3s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(20px)';
            setTimeout(() => {
                card.remove();
                // Check if empty
                if (!document.querySelector('.history-card')) {
                    document.getElementById('history-container').innerHTML = `
                        <div class="card empty-state" role="status">
                            <i class="fa fa-history" aria-hidden="true" style="font-size: var(--fs-3xl); color: var(--color-text-muted); margin-bottom: var(--space-4);"></i>
                            <h3>No trip history found.</h3>
                            <p>Your completed and cancelled trips will appear here.</p>
                            <a href="schedule.php" class="btn btn-primary" style="margin-top: var(--space-4);">
                                <i class="fa fa-bus" aria-hidden="true"></i> Browse Schedules
                            </a>
                        </div>
                    `;
                }
            }, 300);
            showToast('Trip removed from history');
        } else {
            alert('Failed: ' + resp);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-trash"></i> Clear';
        }
    })
    .catch(() => {
        alert('Network error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash"></i> Clear';
    });
}

// Simple toast notification
function showToast(message) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
    
    requestAnimationFrame(() => toast.classList.add('show'));
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
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

/* Mobile history card improvements */
@media (max-width: 768px) {
    .history-card {
        padding: var(--space-4);
    }
    
    .reservation-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-2);
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