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
            mbus_db_prepare($conn, "INSERT INTO notification(user_id, message, notification_date, status) VALUES(?, ?, NOW(), 'Unread')", [(int)$v['user_id'], $msg]);
            $success = "Request " . strtolower($decision) . ".";
        }
    }
}

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
        <a href="verifications.php" aria-current="page" class="menu-bottom-link"><i class="fa fa-id-card" aria-hidden="true"></i> Verifications</a>
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block">
            <i class="fa fa-sign-out" aria-hidden="true"></i> Logout
        </a>
    </div>
</aside>

<main class="main" role="main">
<header class="page-header"><h1>Verifications</h1></header>

<?php if($success){ ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php } ?>
<?php if($error){ ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php } ?>

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
</main>
<style>
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