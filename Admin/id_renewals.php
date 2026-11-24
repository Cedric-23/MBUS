<?php
session_start();
if (!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != "admin") {
    header("Location: ../login.php");
    exit();
}
include "../config/db_connect.php";
include "../Includes/csrf.php";
require_once "../Includes/notify.php";
require_once "../Includes/id_renewal.php";
mbus_renewal_ensure_table($conn);

/* ---------- Review one renewal ---------- */
if (isset($_POST['review'])) {
    $rid = (int)($_POST['renewal_id'] ?? 0);
    $approve = $_POST['review'] === 'approve';
    $reason = trim((string)($_POST['rejection_reason'] ?? ''));
    if (!csrf_validate_token()) {
        $_SESSION['ir_flash'] = ['error', 'Security validation failed. Please try again.'];
    } elseif (!$approve && $reason === '') {
        $_SESSION['ir_flash'] = ['error', 'Add a short reason so the driver knows what to fix.'];
    } else {
        $q = mbus_db_prepare($conn, "SELECT user_id, new_expiry, document_b64, document_mime FROM id_renewal WHERE renewal_id = ? AND status = 'Pending'", [$rid]);
        $r = $q === false ? false : mbus_db_fetch_assoc($q);
        if (!$r) {
            $_SESSION['ir_flash'] = ['error', 'That renewal was already reviewed.'];
        } elseif ($approve && $r['new_expiry'] <= date('Y-m-d')) {
            $_SESSION['ir_flash'] = ['error', 'That expiry date has already passed. Reject it and ask for a valid ID.'];
        } else {
            mbus_db_prepare($conn, "UPDATE id_renewal SET status = ?, reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? WHERE renewal_id = ?",
                [$approve ? 'Approved' : 'Rejected', (int)$_SESSION['user_id'], $approve ? null : $reason, $rid]);
            if ($approve) {
                /* the driver's latest approved application carries the licence expiry and photo */
                $a = mbus_db_prepare($conn, "SELECT application_id FROM operator_application WHERE user_id = ? AND application_status::text = 'Approved' ORDER BY application_id DESC LIMIT 1", [(int)$r['user_id']]);
                $app = $a === false ? false : mbus_db_fetch_assoc($a);
                if ($app) {
                    mbus_db_prepare($conn, "UPDATE operator_application SET license_expiry = ?, license_document_b64 = ?, license_document_mime = ? WHERE application_id = ?",
                        [$r['new_expiry'], $r['document_b64'], $r['document_mime'], (int)$app['application_id']]);
                }
                mbus_notify($conn, (int)$r['user_id'], 'Your renewed ID was approved. It is valid until ' . date('M j, Y', strtotime($r['new_expiry'])) . '.');
            } else {
                mbus_notify($conn, (int)$r['user_id'], 'Your renewed ID was rejected: ' . $reason . ' You can send a new one from My Profile.');
            }
            $_SESSION['ir_flash'] = ['success', $approve ? 'Approved.' : 'Rejected.'];
        }
    }
    header('Location: id_renewals.php');
    exit();
}
$success = ''; $error = '';
if (!empty($_SESSION['ir_flash'])) {
    [$kind, $text] = $_SESSION['ir_flash']; unset($_SESSION['ir_flash']);
    if ($kind === 'success') $success = $text; else $error = $text;
}

$pending = []; $recent = [];
$q = mbus_db_prepare($conn, "SELECT r.renewal_id, r.new_expiry, r.document_mime, r.submitted_at, u.full_name, u.email,
    (SELECT license_expiry FROM operator_application a WHERE a.user_id = r.user_id AND a.application_status::text = 'Approved' ORDER BY a.application_id DESC LIMIT 1) AS old_expiry
    FROM id_renewal r JOIN users u ON u.user_id = r.user_id WHERE r.status = 'Pending' ORDER BY r.submitted_at ASC", []);
while ($q !== false && ($row = mbus_db_fetch_assoc($q))) $pending[] = $row;
$q = mbus_db_prepare($conn, "SELECT r.renewal_id, r.new_expiry, r.status, r.reviewed_at, r.rejection_reason, u.full_name
    FROM id_renewal r JOIN users u ON u.user_id = r.user_id WHERE r.status <> 'Pending' ORDER BY r.reviewed_at DESC NULLS LAST LIMIT 20", []);
while ($q !== false && ($row = mbus_db_fetch_assoc($q))) $recent[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Driver ID renewals - MBUS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <style>
        .ir-card{max-width:760px;margin:0 auto 14px;padding:16px 18px}
        .ir-card h2{margin:0 0 2px;font-size:1.05rem}
        .ir-card dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:12px 0;font-size:.9rem}
        .ir-card dt{color:#6b7a90}.ir-card dd{margin:0;font-weight:600}
        .ir-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .ir-actions input[type=text]{flex:1 1 180px;min-width:0}
        .ir-recent{max-width:760px;margin:18px auto 0;padding:0;list-style:none;display:grid;gap:8px;font-size:.88rem}
        .ir-recent li{padding:10px 14px;border-radius:14px;background:#fff}
        .ir-bad{color:#b42318}.ir-ok{color:#1b7a46}
    </style>
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>
<body class="stf has-sidebar">
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar"><i class="fa fa-bars" aria-hidden="true"></i></button>
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>
<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Admin</h2>
    <nav class="menu-top" aria-label="Admin menu">
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>
    <div class="menu-bottom"><a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a></div>
</aside>

<main class="main" role="main">
<header class="page-header">
    <h1>Driver ID renewals</h1>
    <div class="page-header-right">
                        <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
                <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span><?php include_once dirname(__DIR__) . '/Includes/admin_menu.php'; echo mbus_admin_dot($conn); ?>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                    <span class="profile-menu-role">Admin account</span>
                </div>
                <?php echo mbus_admin_approvals_link($conn); ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </div>
</header>

<?php if ($success !== ''): ?><div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if (!$pending): ?>
<section class="card ir-card"><h2>All caught up</h2><p>No renewed IDs are waiting for review.</p></section>
<?php endif; ?>
<?php foreach ($pending as $p): ?>
<section class="card ir-card">
    <h2><?php echo htmlspecialchars($p['full_name']); ?></h2>
    <small><?php echo htmlspecialchars($p['email']); ?> &middot; sent <?php echo date('M j, Y g:i A', strtotime($p['submitted_at'])); ?></small>
    <dl>
        <dt>Current expiry</dt><dd><?php echo $p['old_expiry'] ? date('M j, Y', strtotime($p['old_expiry'])) . ($p['old_expiry'] < date('Y-m-d') ? ' <span class="ir-bad">(expired)</span>' : '') : 'Not on file'; ?></dd>
        <dt>New expiry</dt><dd><?php echo date('M j, Y', strtotime($p['new_expiry'])); ?></dd>
        <dt>Photo</dt><dd><a href="renewal_file.php?id=<?php echo (int)$p['renewal_id']; ?>" target="_blank" rel="noopener">Open renewed ID</a></dd>
    </dl>
    <form method="POST" class="ir-actions">
        <?php csrf_field(); ?>
        <input type="hidden" name="renewal_id" value="<?php echo (int)$p['renewal_id']; ?>">
        <button class="btn btn-primary" type="submit" name="review" value="approve">Approve</button>
        <input class="form-control" type="text" name="rejection_reason" maxlength="200" placeholder="Reason, if rejecting" aria-label="Rejection reason">
        <button class="btn btn-outline" type="submit" name="review" value="reject">Reject</button>
    </form>
</section>
<?php endforeach; ?>

<?php if ($recent): ?>
<ul class="ir-recent" aria-label="Recently reviewed">
    <?php foreach ($recent as $r): ?>
    <li><strong><?php echo htmlspecialchars($r['full_name']); ?></strong> &middot;
        <span class="<?php echo $r['status'] === 'Approved' ? 'ir-ok' : 'ir-bad'; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
        &middot; new expiry <?php echo date('M j, Y', strtotime($r['new_expiry'])); ?><?php echo !empty($r['rejection_reason']) ? ' &middot; ' . htmlspecialchars($r['rejection_reason']) : ''; ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
</main>
<script>
function toggleSidebar(){var s=document.querySelector('.sidebar'),o=document.querySelector('.sidebar-overlay'),t=document.querySelector('.sidebar-toggle');var open=s.classList.toggle('open');o.classList.toggle('active');if(t)t.setAttribute('aria-expanded',open);document.body.style.overflow=open?'hidden':'';}
</script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/notifications.js"></script>

</body>
</html>
