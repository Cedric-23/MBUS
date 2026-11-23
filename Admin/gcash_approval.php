<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/activity_log.php";
require_once "../Includes/gcash_manual.php";

$success = $_SESSION['gc_flash'] ?? ''; $error = ''; unset($_SESSION['gc_flash']);
$admin_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = mbus_gcash_pending($conn);
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (!$pending) {
        $error = 'There is no pending change.';
    } elseif (isset($_POST['approve'])) {
        mbus_set_setting($conn, 'gcash_name', (string)$pending['name']);
        mbus_set_setting($conn, 'gcash_number', (string)$pending['number']);
        if (!empty($pending['qr'])) mbus_set_setting($conn, 'gcash_qr', (string)$pending['qr']);
        mbus_set_setting($conn, 'gcash_pending', '');
        log_activity($conn, $admin_id, 'GCash account change approved', (string)$pending['number']);
        $_SESSION['gc_flash'] = 'Approved. Commuters now pay to the new account.';
        header('Location: gcash_approval.php'); exit();
    } elseif (isset($_POST['reject'])) {
        mbus_set_setting($conn, 'gcash_pending', '');
        log_activity($conn, $admin_id, 'GCash account change rejected', (string)$pending['number']);
        $_SESSION['gc_flash'] = 'Change rejected. The current account stays active.';
        header('Location: gcash_approval.php'); exit();
    }
}

$pending = mbus_gcash_pending($conn);
$cName = mbus_setting($conn, 'gcash_name'); $cNum = mbus_setting($conn, 'gcash_number'); $cQr = mbus_setting($conn, 'gcash_qr');
$smsToken = mbus_sms_token($conn);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$smsUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/Admin/gcash_approval.php')), '/') . '/api/gcash_sms_webhook.php?token=' . rawurlencode($smsToken);
$lastQ = mbus_db_prepare($conn, "SELECT EXTRACT(EPOCH FROM (NOW() - MAX(received_at))) AS secs FROM incoming_payments", []);
$lastRow = $lastQ === false ? false : mbus_db_fetch_assoc($lastQ);
$lastSecs = ($lastRow && $lastRow['secs'] !== null) ? (int)$lastRow['secs'] : null;
$phoneOk = $lastSecs !== null && $lastSecs < 86400 * 2;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash account - MBUS</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/operator_payments.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
</head>

<body class="stf has-sidebar">

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
    <h1>GCash account</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span><?php include_once dirname(__DIR__) . '/Includes/admin_menu.php'; echo mbus_admin_dot($conn); ?>
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

<?php if($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="gc-grid">
<section class="gc-card" aria-labelledby="gcCur">
    <div class="gc-top"><h2 id="gcCur">Current account</h2><span class="pay-badge ok">Live</span></div>
    <?php if($cNum === ''): ?><p class="gc-empty">Nothing set yet.</p><?php else: ?>
    <div class="gc-body">
        <?php if($cQr !== ''): ?><img class="gc-qr" src="<?php echo htmlspecialchars($cQr, ENT_QUOTES); ?>" alt="Current GCash QR"><?php endif; ?>
        <div><p class="gc-name"><?php echo htmlspecialchars($cName); ?></p><p class="gc-num"><?php echo htmlspecialchars($cNum); ?></p></div>
    </div>
    <?php endif; ?>
</section>

<section class="gc-card <?php echo $pending ? 'gc-pending' : ''; ?>" aria-labelledby="gcReq">
    <div class="gc-top"><h2 id="gcReq">Requested change</h2><?php if($pending): ?><span class="pay-badge">Pending</span><?php endif; ?></div>
    <?php if($pending): ?>
    <div class="gc-body">
        <?php if(!empty($pending['qr'])): ?><img class="gc-qr" src="<?php echo htmlspecialchars($pending['qr'], ENT_QUOTES); ?>" alt="Requested GCash QR"><?php endif; ?>
        <div><p class="gc-name"><?php echo htmlspecialchars($pending['name']); ?></p><p class="gc-num"><?php echo htmlspecialchars($pending['number']); ?></p>
        <p class="gc-meta">From <?php echo htmlspecialchars($pending['by_name'] ?? 'a conductor'); ?>, <?php echo htmlspecialchars(date('M j, g:i A', strtotime($pending['at'] ?? 'now'))); ?></p></div>
    </div>
    <form method="POST" class="gc-actions">
        <?php csrf_field(); ?>
        <button type="submit" name="reject" value="1" class="gc-btn" onclick="return confirm('Reject this change?');">Reject</button>
        <button type="submit" name="approve" value="1" class="gc-btn primary">Approve</button>
    </form>
    <?php else: ?><p class="gc-empty">No changes waiting.</p><?php endif; ?>
</section>
</div>

<section class="gc-card" aria-labelledby="gcAuto">
    <div class="gc-top"><h2 id="gcAuto">SMS auto-confirm</h2><span class="pay-dot <?php echo $phoneOk ? 'on' : 'off'; ?>"><?php echo $phoneOk ? 'Phone connected' : ($lastSecs === null ? 'Not set up' : 'Phone quiet'); ?></span></div>
    <ol class="pay-steps">
        <li>On the phone with the GCash SIM, install an SMS forwarder app.</li>
        <li>Forward GCash received messages to this Admin-only webhook address:</li>
    </ol>
    <div class="pay-copy"><code id="smsUrl"><?php echo htmlspecialchars($smsUrl); ?></code><button type="button" class="pay-btn" id="copyUrl">Copy</button></div>
    <p class="pay-note"><?php echo $lastSecs === null ? 'No GCash SMS received yet.' : 'Last message ' . ($lastSecs < 3600 ? max(1, (int)round($lastSecs / 60)) . ' min' : ($lastSecs < 86400 ? (int)round($lastSecs / 3600) . ' h' : (int)round($lastSecs / 86400) . ' d')) . ' ago.'; ?></p>
</section>

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
document.getElementById('copyUrl')?.addEventListener('click', function () {
    var button = this, value = document.getElementById('smsUrl').textContent, label = button.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(value) : Promise.reject()).catch(function () {
        var input = document.createElement('input'); input.value = value; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
    });
    button.textContent = 'Copied'; setTimeout(function () { button.textContent = label; }, 1500);
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
    <a href="manage_operators.php" class="bottom-nav-item">
        <i class="fa fa-users" aria-hidden="true"></i><span>Operators</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/password-toggle.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
