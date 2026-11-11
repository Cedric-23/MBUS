<?php
session_start();
if(!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != "admin"){
    header("Location: ../login.php");
    exit();
}
include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/activity_log.php";

$error = ""; $success = "";

if(isset($_POST['review'])){
    $vid = (int)($_POST['verification_id'] ?? 0);
    $decision = $_POST['review'] === 'approve' ? 'Approved' : 'Rejected';
    $reason = trim((string)($_POST['rejection_reason'] ?? ''));
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } elseif($decision === 'Rejected' && $reason === ''){
        $error = "Add a short reason so the commuter knows what to fix.";
    } else {
        $q = mbus_db_prepare($conn, "SELECT user_id, verification_type FROM commuter_verification WHERE verification_id = ? AND verification_status = 'Pending'", [$vid]);
        $v = $q === false ? false : mbus_db_fetch_assoc($q);
        if(!$v){
            $error = "That request was already reviewed.";
        } else {
            mbus_db_prepare($conn, "UPDATE commuter_verification SET verification_status = ?, reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? WHERE verification_id = ?",
                [$decision, (int)$_SESSION['user_id'], $decision === 'Rejected' ? $reason : null, $vid]);
            if($decision === 'Approved'){
                mbus_db_prepare($conn, "UPDATE users SET verification_status = 'Approved', verification_type = ? WHERE user_id = ?", [$v['verification_type'], (int)$v['user_id']]);
                $msg = "Your {$v['verification_type']} verification was approved. You now get 20% off your fare.";
                log_activity($conn, (int)$_SESSION['user_id'], ACTION_VERIFICATION_APPROVE, "Approved {$v['verification_type']} verification #$vid");
            } else {
                mbus_db_prepare($conn, "UPDATE users SET verification_status = 'Rejected', verification_type = NULL WHERE user_id = ?", [(int)$v['user_id']]);
                $msg = "Your verification was rejected: $reason";
                log_activity($conn, (int)$_SESSION['user_id'], ACTION_VERIFICATION_REJECT, "Rejected verification #$vid");
            }
            $success = "Request " . strtolower($decision) . ".";
        }
    }
}

/* Ticket discount review (IDs uploaded at payment for group bookings) */
if(isset($_POST['review_ticket'])){
    $rid = (int)($_POST['reservation_id'] ?? 0);
    $decision = $_POST['review_ticket'] === 'approve' ? 'Approved' : 'Rejected';
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $u = mbus_db_prepare($conn, "UPDATE reservation SET discount_status = ?, discount_reviewed_by = ?, discount_reviewed_at = NOW()
            WHERE reservation_id = ? AND discount_status = 'Pending'", [$decision, (int)$_SESSION['user_id'], $rid]);
        if($u === false || (int)$conn->affectedRows < 1){
            $error = "That ticket was already reviewed.";
        } else {
            log_activity($conn, (int)$_SESSION['user_id'], $decision === 'Approved' ? ACTION_VERIFICATION_APPROVE : ACTION_VERIFICATION_REJECT, "$decision ticket discount ID for reservation #$rid");
            $success = "Ticket discount " . strtolower($decision) . ($decision === 'Rejected' ? ". The operator will collect the difference at boarding." : ".");
        }
    }
}
$tab = ($_GET['tab'] ?? '') === 'tickets' ? 'tickets' : 'accounts';

$tickets = [];
$q = mbus_db_prepare($conn, "SELECT r.reservation_id, r.seat_number, r.passenger_type, r.discount_amount, r.status, r.pickup_location, r.destination,
        s.departure_time, b.bus_number, u.full_name
    FROM reservation r JOIN users u ON u.user_id = r.user_id JOIN schedule s ON s.schedule_id = r.schedule_id JOIN buses b ON b.bus_id = s.bus_id
    WHERE r.discount_status = 'Pending' AND r.id_image_b64 IS NOT NULL ORDER BY s.departure_time, r.reservation_id", []);
while($q !== false && ($r = mbus_db_fetch_assoc($q))) $tickets[] = $r;

$pending = [];
$q = mbus_db_prepare($conn, "SELECT v.verification_id, v.verification_type, v.document_number, v.expiry_date, v.document_mime, v.submitted_at, u.full_name, u.email
    FROM commuter_verification v JOIN users u ON u.user_id = v.user_id
    WHERE v.verification_status = 'Pending' ORDER BY v.submitted_at", []);
while($q !== false && ($r = mbus_db_fetch_assoc($q))) $pending[] = $r;

$history = [];
$q = mbus_db_prepare($conn, "SELECT v.verification_type, v.verification_status, v.reviewed_at, u.full_name
    FROM commuter_verification v JOIN users u ON u.user_id = v.user_id
    WHERE v.verification_status <> 'Pending' ORDER BY v.reviewed_at DESC NULLS LAST LIMIT 15", []);
while($q !== false && ($r = mbus_db_fetch_assoc($q))) $history[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifications - MBUS</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
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
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
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
    <h1>Verifications</h1>

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

<?php if($success){ ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php } ?>
<?php if($error){ ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php } ?>

<nav class="v-tabs" aria-label="Verification type">
    <a href="?tab=accounts" class="<?php echo $tab === 'accounts' ? 'on' : ''; ?>">Accounts <span><?php echo count($pending); ?></span></a>
    <a href="?tab=tickets" class="<?php echo $tab === 'tickets' ? 'on' : ''; ?>">Ticket discounts <span><?php echo count($tickets); ?></span></a>
</nav>

<?php if($tab === 'tickets'){ ?>
<p class="v-meta">IDs uploaded at payment for seats booked for other people. Check the ID matches the passenger type. A rejected ticket stays valid; the operator collects the discount back at boarding.</p>
<?php if(!$tickets){ ?><p class="v-empty">No ticket discounts waiting.</p><?php } ?>
<div class="v-list">
<?php foreach($tickets as $t){ ?>
<article class="card v-item">
    <div class="v-item-head">
        <div><strong>Seat <?php echo htmlspecialchars((string)$t['seat_number']); ?></strong> &middot; booked by <?php echo htmlspecialchars($t['full_name']); ?><br>
        <small>Bus <?php echo htmlspecialchars($t['bus_number']); ?> &middot; <?php echo htmlspecialchars(date('M j, g:i A', strtotime($t['departure_time']))); ?> &middot; <?php echo htmlspecialchars($t['pickup_location']); ?> &rarr; <?php echo htmlspecialchars($t['destination']); ?></small></div>
        <span class="v-pill"><?php echo htmlspecialchars($t['passenger_type']); ?></span>
    </div>
    <p class="v-meta">Discount &#8369;<?php echo number_format((float)$t['discount_amount'], 2); ?> &middot; ticket <?php echo htmlspecialchars($t['status']); ?></p>
    <a href="../id_image.php?r=<?php echo (int)$t['reservation_id']; ?>" target="_blank" rel="noopener"><img class="v-img" src="../id_image.php?r=<?php echo (int)$t['reservation_id']; ?>" alt="Passenger ID for seat <?php echo htmlspecialchars((string)$t['seat_number']); ?>" loading="lazy"></a>
    <form method="POST" class="v-actions">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="reservation_id" value="<?php echo (int)$t['reservation_id']; ?>">
        <button class="btn btn-primary" name="review_ticket" value="approve" type="submit">Approve</button>
        <button class="btn btn-outline" name="review_ticket" value="reject" type="submit">Reject</button>
    </form>
</article>
<?php } ?>
</div>
<?php } else { ?>
<h2 class="v-h">Waiting for review (<?php echo count($pending); ?>)</h2>
<?php if(!$pending){ ?><p class="v-empty">Nothing to review right now.</p><?php } ?>
<div class="v-list">
<?php foreach($pending as $p){ ?>
<article class="card v-item">
    <div class="v-item-head">
        <div><strong><?php echo htmlspecialchars($p['full_name']); ?></strong><br><small><?php echo htmlspecialchars($p['email']); ?></small></div>
        <span class="v-pill"><?php echo htmlspecialchars($p['verification_type']); ?></span>
    </div>
    <p class="v-meta">ID no. <strong><?php echo htmlspecialchars($p['document_number']); ?></strong>
    &middot; <?php echo $p['expiry_date'] ? 'expires ' . htmlspecialchars(date('M j, Y', strtotime($p['expiry_date']))) : 'no expiry'; ?>
    &middot; sent <?php echo htmlspecialchars(date('M j, g:i A', strtotime($p['submitted_at']))); ?></p>
    <?php if(($p['document_mime'] ?? '') === 'application/pdf'){ ?>
        <a class="btn btn-outline" href="verification_file.php?id=<?php echo (int)$p['verification_id']; ?>" target="_blank" rel="noopener">Open PDF</a>
    <?php } else { ?>
        <a href="verification_file.php?id=<?php echo (int)$p['verification_id']; ?>" target="_blank" rel="noopener"><img class="v-img" src="verification_file.php?id=<?php echo (int)$p['verification_id']; ?>" alt="ID uploaded by <?php echo htmlspecialchars($p['full_name']); ?>" loading="lazy"></a>
    <?php } ?>
    <form method="POST" class="v-actions">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="verification_id" value="<?php echo (int)$p['verification_id']; ?>">
        <input class="form-control" name="rejection_reason" placeholder="Reason (required to reject)" maxlength="200">
        <button class="btn btn-primary" name="review" value="approve" type="submit">Approve</button>
        <button class="btn btn-outline" name="review" value="reject" type="submit">Reject</button>
    </form>
</article>
<?php } ?>
</div>

<?php if($history){ ?>
<h2 class="v-h">Recently reviewed</h2>
<ul class="v-history">
<?php foreach($history as $h){ ?>
    <li><span><?php echo htmlspecialchars($h['full_name']); ?> &middot; <?php echo htmlspecialchars($h['verification_type']); ?></span><strong class="<?php echo $h['verification_status'] === 'Approved' ? 'ok' : 'no'; ?>"><?php echo htmlspecialchars($h['verification_status']); ?></strong></li>
<?php } ?>
</ul>
<?php } ?>
<?php } /* end accounts tab */ ?>
</main>
<style>
.v-tabs{display:flex;gap:.4rem;margin:0 0 .75rem}.v-tabs a{padding:.4rem .8rem;border-radius:999px;border:1px solid #e5eaf1;color:#3c4a60;text-decoration:none;font-size:.9rem}.v-tabs a.on{background:#1e3a5f;border-color:#1e3a5f;color:#fff}.v-tabs span{opacity:.75;margin-left:.2rem}
.v-h{font-size:1.0625rem;margin:20px 0 10px}.v-empty{color:var(--color-text-muted)}
.v-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.v-item{padding:16px;border-radius:16px}.v-item-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.v-pill{padding:2px 10px;border-radius:999px;background:var(--color-primary-light-bg);color:var(--color-primary);font-weight:600;font-size:.8125rem}
.v-meta{margin:8px 0;font-size:.875rem;color:var(--color-text-muted)}
.v-img{display:block;width:100%;max-height:260px;object-fit:contain;background:#f1f5f9;border-radius:10px;margin-bottom:10px}
.v-actions{display:grid;gap:8px}.v-actions .btn{min-height:44px}
.v-history{list-style:none;padding:0;margin:0;display:grid;gap:6px}.v-history li{display:flex;justify-content:space-between;padding:10px 14px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:10px}
.v-history .ok{color:#067647}.v-history .no{color:#b42318}
</style>

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
    <a href="manage_buses.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span>
    </a>
    <a href="manage_operators.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>