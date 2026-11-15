<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";
include "../Includes/payment_finalize.php";
include "../Includes/gcash_manual.php";

$success = $_SESSION['gp_flash'] ?? ''; $error = ''; unset($_SESSION['gp_flash']);
$admin_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (isset($_POST['save_settings'])) {
        $name = trim((string)($_POST['gcash_name'] ?? '')); $num = preg_replace('/\s+/', '', (string)($_POST['gcash_number'] ?? ''));
        if ($name === '' || strlen($name) > 80 || !preg_match('/^09\d{9}$/', $num)) {
            $error = 'Enter the account name and an 11-digit GCash number starting with 09.';
        } else {
            mbus_set_setting($conn, 'gcash_name', $name); mbus_set_setting($conn, 'gcash_number', $num);
            if (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] !== UPLOAD_ERR_NO_FILE) {
                $up = mbus_receipt_upload('gcash_qr', $error);
                if ($up) mbus_set_setting($conn, 'gcash_qr', 'data:' . $up['mime'] . ';base64,' . $up['b64']);
            }
            if ($error === '') { $_SESSION['gp_flash'] = 'GCash details saved.'; header('Location: payment_verifications.php'); exit(); }
        }
    } elseif (isset($_POST['approve']) || isset($_POST['reject'])) {
        $sid = (string)($_POST['session_id'] ?? '');
        $st = $conn->pdo->prepare("SELECT * FROM paymongo_checkout WHERE session_id = ? AND status = 'Submitted'");
        $st->execute([$sid]);
        $co = $st->fetch(PDO::FETCH_ASSOC);
        if (!$co) {
            $error = 'That payment was already reviewed.';
        } elseif (isset($_POST['approve'])) {
            $res = mbus_finalize_payment($conn, $sid, (string)$co['reference_number']);
            if (!empty($res['ok'])) {
                $conn->pdo->prepare("UPDATE paymongo_checkout SET reviewed_by = ?, reviewed_at = NOW() WHERE session_id = ?")->execute([$admin_id, $sid]);
                $_SESSION['gp_flash'] = 'Payment approved. Ticket ' . ($res['ticket'] ?? '') . ' issued.';
                header('Location: payment_verifications.php'); exit();
            }
            $error = $res['message'] ?? 'Could not approve this payment.';
        } else {
            $reason = trim((string)($_POST['reject_reason'] ?? ''));
            if ($reason === '' || strlen($reason) > 300) {
                $error = 'Give a short reason (up to 300 characters) when rejecting.';
            } else {
                $ids = json_decode((string)$co['reservation_ids'], true) ?: [];
                $conn->pdo->beginTransaction();
                $conn->pdo->prepare("UPDATE paymongo_checkout SET status = 'Rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE session_id = ?")->execute([$reason, $admin_id, $sid]);
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $conn->pdo->prepare("UPDATE reservation SET status = 'Cancelled' WHERE status = 'Pending' AND user_id = ? AND reservation_id IN ($in)")->execute(array_merge([(int)$co['user_id']], $ids));
                }
                $conn->pdo->commit();
                $_SESSION['gp_flash'] = 'Payment rejected and the seats were released.';
                header('Location: payment_verifications.php?status=rejected'); exit();
            }
        }
    }
}

$conn->pdo->exec("UPDATE paymongo_checkout SET status = 'Expired' WHERE status = 'Submitted' AND session_id LIKE 'manual\\_%' AND submitted_at < NOW() - INTERVAL '30 minutes'");
$smsToken = mbus_sms_token($conn);
$smsUrl = (function(){ $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'; return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/gcash_sms_webhook.php?token='; })();
$recvQ = mbus_db_prepare($conn, "SELECT reference_number, amount, sender, received_at, used_by FROM incoming_payments ORDER BY received_at DESC LIMIT 8", []);
$received = []; while ($recvQ !== false && ($rr = mbus_db_fetch_assoc($recvQ))) $received[] = $rr;
$opts = ['submitted' => 'To verify', 'paid' => 'Approved', 'rejected' => 'Rejected'];
$f = $_GET['status'] ?? 'submitted'; if (!isset($opts[$f])) $f = 'submitted';
$counts = array_fill_keys(array_keys($opts), 0);
$cq = mbus_db_prepare($conn, "SELECT LOWER(status) AS s, COUNT(*) AS c FROM paymongo_checkout WHERE session_id LIKE 'manual\_%' GROUP BY 1", []);
while ($cq !== false && ($r = mbus_db_fetch_assoc($cq))) if (isset($counts[$r['s']])) $counts[$r['s']] = (int)$r['c'];
$rows = [];
$q = mbus_db_prepare($conn, "SELECT (c.receipt_b64 IS NOT NULL AND c.receipt_b64 <> '') AS has_receipt, c.session_id, c.amount, c.reference_number, c.submitted_at, c.status, c.reject_reason, c.lines, u.full_name, u.email,
    (SELECT COUNT(*) FROM paymongo_checkout d WHERE d.reference_number = c.reference_number AND d.session_id <> c.session_id) AS dups,
    (SELECT r.origin || ' → ' || r.destination FROM schedule s JOIN routes r ON r.route_id = s.route_id WHERE s.schedule_id = c.schedule_id) AS route,
    (SELECT s.departure_time FROM schedule s WHERE s.schedule_id = c.schedule_id) AS departure
    FROM paymongo_checkout c JOIN users u ON u.user_id = c.user_id
    WHERE c.session_id LIKE 'manual\_%' AND LOWER(c.status) = ? ORDER BY c.submitted_at DESC LIMIT 100", [$f]);
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
$gName = mbus_setting($conn, 'gcash_name'); $gNum = mbus_setting($conn, 'gcash_number'); $gQr = mbus_setting($conn, 'gcash_qr');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash payments - MBUS</title>

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
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
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
        <a href="manage_operators.php" aria-current="page"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
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
    <h1>GCash payments</h1>

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

<?php if($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<nav class="ad-seg vf-tabs" aria-label="Status">
    <?php foreach($opts as $k => $label): ?>
    <a href="?status=<?php echo $k; ?>" class="<?php echo $f === $k ? 'on' : ''; ?>"><?php echo $label; ?> <span class="vf-count"><?php echo $counts[$k]; ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if(!$rows): ?><p class="oa-empty"><?php echo $f === 'submitted' ? 'Nothing needs checking. Payments confirm automatically when the SMS arrives.' : 'Nothing here.'; ?></p><?php endif; ?>
<div class="oa-list">
<?php foreach($rows as $c): $seats = array_map(fn($l) => $l['seat'] . ' (' . $l['type'] . ')', json_decode((string)$c['lines'], true) ?: []); ?>
    <article class="oa-card">
        <div class="oa-head">
            <div><h2><?php echo htmlspecialchars($c['full_name']); ?></h2><p><?php echo htmlspecialchars($c['email']); ?></p></div>
            <strong>&#8369;<?php echo number_format((float)$c['amount'], 2); ?></strong>
        </div>
        <dl class="oa-facts">
            <div><dt>Reference</dt><dd><?php echo htmlspecialchars((string)$c['reference_number']); ?><?php if((int)$c['dups'] > 0): ?> <span class="oa-bad">· used before</span><?php endif; ?></dd></div>
            <div><dt>Trip</dt><dd><?php echo htmlspecialchars((string)$c['route']); ?><br><?php echo $c['departure'] ? date('M j, g:i A', strtotime($c['departure'])) : ''; ?></dd></div>
            <div><dt>Seats</dt><dd><?php echo htmlspecialchars(implode(', ', $seats)); ?></dd></div>
            <div><dt>Submitted</dt><dd><?php echo $c['submitted_at'] ? date('M j, g:i A', strtotime($c['submitted_at'])) : ''; ?></dd></div>
        </dl>
        <?php if(!empty($c['has_receipt'])): ?><div class="oa-docs"><a class="ad-btn sm" target="_blank" rel="noopener" href="payment_receipt.php?id=<?php echo urlencode($c['session_id']); ?>"><i class="fa fa-receipt" aria-hidden="true"></i> View receipt</a></div><?php endif; ?>
        <?php if(strtolower($c['status']) === 'rejected' && $c['reject_reason']): ?><p class="oa-reason">Reason: <?php echo htmlspecialchars($c['reject_reason']); ?></p><?php endif; ?>
        <?php if(strtolower($c['status']) === 'submitted'): ?>
        <form method="POST" class="oa-actions">
            <?php csrf_field(); ?>
            <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($c['session_id']); ?>">
            <button type="submit" name="approve" value="1" class="ad-btn sm primary" onclick="return confirm('Does the receipt show &#8369;<?php echo number_format((float)$c['amount'], 2); ?> sent to your GCash? Approve and issue the ticket?');">Approve</button>
            <details class="oa-reject"><summary class="ad-btn sm">Reject</summary>
                <textarea name="reject_reason" rows="2" maxlength="300" placeholder="Reason shown to the commuter"></textarea>
                <button type="submit" name="reject" value="1" class="ad-btn sm">Reject and release seats</button>
            </details>
        </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

<details class="oa-admin" <?php echo $gNum === '' ? 'open' : ''; ?>>
    <summary>Automatic payment matching (phone setup)</summary>
    <p>Install an SMS forwarder app on the phone that has your GCash SIM (e.g. &ldquo;SMS Forwarder&rdquo; on Android). Forward messages containing &ldquo;received&rdquo; to this web address, sending the message text in a field named <code>message</code>. MBUS then confirms commuters&rsquo; tickets by itself when their reference number and amount match.</p>
    <p><code style="word-break:break-all"><?php echo htmlspecialchars($smsUrl . $smsToken); ?></code></p>
    <?php if($received): ?><p>Latest payments seen:</p><ul style="margin:0 0 10px;padding-left:18px;font-size:.86rem">
    <?php foreach($received as $rc): ?><li>&#8369;<?php echo number_format((float)$rc['amount'], 2); ?> · ref <?php echo htmlspecialchars($rc['reference_number']); ?> · <?php echo $rc['used_by'] ? 'matched' : 'unmatched'; ?></li><?php endforeach; ?></ul><?php else: ?><p>No payments received yet.</p><?php endif; ?>
</details>

<details class="oa-admin" <?php echo $gNum === '' ? 'open' : ''; ?>>
    <summary>Your GCash details</summary>
    <p>Commuters see this QR and number on the payment page. Use the GCash account that should receive fares.</p>
    <form method="POST" enctype="multipart/form-data" class="oa-admin-form">
        <?php csrf_field(); ?>
        <input type="text" name="gcash_name" placeholder="Account name" value="<?php echo htmlspecialchars($gName); ?>" required maxlength="80">
        <input type="tel" name="gcash_number" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($gNum); ?>" pattern="09\d{9}" maxlength="11" required>
        <input type="file" name="gcash_qr" accept="image/jpeg,image/png" aria-label="GCash QR image">
        <button type="submit" name="save_settings" value="1" class="ad-btn primary">Save</button>
    </form>
    <?php if($gQr !== ''): ?><p>Current QR:</p><img src="<?php echo htmlspecialchars($gQr, ENT_QUOTES); ?>" alt="Current GCash QR" style="width:140px;border-radius:10px;border:1px solid #e1e7ef"><?php endif; ?>
</details>

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
