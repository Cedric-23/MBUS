<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'admin') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
include "../Includes/csrf.php";
include_once "../Includes/email_helper.php";
$flash = $_SESSION['ca_flash'] ?? ''; unset($_SESSION['ca_flash']); $error = '';
$admin_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['application_id'] ?? 0);
    if (!csrf_validate_token()) { $error = 'Security validation failed. Please try again.'; }
    else {
        $pdo = $conn->pdo;
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM conductor_application WHERE application_id = ? AND application_status = 'Pending' FOR UPDATE");
            $st->execute([$id]); $app = $st->fetch(PDO::FETCH_ASSOC);
            if (!$app) throw new RuntimeException('That application was already reviewed.');
            if (isset($_POST['approve'])) {
                $st = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)"); $st->execute([$app['email']]);
                if ($st->fetchColumn()) throw new RuntimeException('An account with this email already exists.');
                $st = $pdo->prepare("INSERT INTO users (full_name, email, phone_number, password, user_type) VALUES (?, ?, ?, ?, 'Conductor') RETURNING user_id");
                $st->execute([$app['full_name'], $app['email'], $app['phone_number'], $app['password_hash']]);
                $uid = (int)$st->fetchColumn();
                $pdo->prepare("UPDATE conductor_application SET application_status='Approved', user_id=?, reviewed_at=NOW(), reviewed_by=? WHERE application_id=?")->execute([$uid, $admin_id, $id]);
                $pdo->commit();
                @send_email_notification($app['email'], 'Your MBUS conductor account', '<p>Hi ' . htmlspecialchars($app['full_name']) . ', your conductor account is approved. Log in with the password you chose.</p>');
                $_SESSION['ca_flash'] = 'Approved ' . $app['full_name'] . '. They can log in now.';
            } else {
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if ($reason === '' || strlen($reason) > 500) throw new RuntimeException('Give a short reason (up to 500 characters) when rejecting.');
                $pdo->prepare("UPDATE conductor_application SET application_status='Rejected', reviewed_at=NOW(), reviewed_by=?, rejection_reason=? WHERE application_id=?")->execute([$admin_id, $reason, $id]);
                $pdo->commit();
                $_SESSION['ca_flash'] = 'Rejected ' . $app['full_name'] . '.';
            }
            header('Location: conductor_applications.php?status=' . urlencode((string)($_GET['status'] ?? 'pending'))); exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the review. Please try again.';
            if (!($e instanceof RuntimeException)) error_log('Conductor application review: ' . $e->getMessage());
        }
    }
}

$opts = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$f = $_GET['status'] ?? 'pending'; if ($f !== 'all' && !isset($opts[$f])) $f = 'pending';
$counts = array_fill_keys(array_keys($opts), 0);
$cq = mbus_db_prepare($conn, "SELECT LOWER(application_status::text) AS s, COUNT(*) AS c FROM conductor_application GROUP BY 1", []);
while ($cq !== false && ($r = mbus_db_fetch_assoc($cq))) if (isset($counts[$r['s']])) $counts[$r['s']] = (int)$r['c'];
$where = $f === 'all' ? '' : "WHERE LOWER(application_status::text) = '" . $f . "'";
$rows = [];
$q = mbus_db_prepare($conn, "SELECT application_id, full_name, email, phone_number, application_status::text AS status, submitted_at, rejection_reason FROM conductor_application $where ORDER BY (application_status::text = 'Pending') DESC, submitted_at DESC LIMIT 100", []);
while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conductor applications - MBUS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/manage_operators.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
    <link rel="stylesheet" href="../Assets/css/admin/admin_application_review.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/admin/admin_application_review.css'); ?>">
</head>
<body class="has-sidebar">
<button class="sidebar-toggle" type="button" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>
<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Admin</h2>
    <nav class="menu-top" aria-label="Admin menu">
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php" aria-current="page"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
    </nav>
    <div class="menu-bottom"><a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a></div>
</aside>

<main class="main" role="main">
    <header class="page-header">
        <h1>Conductor applications</h1>
        <div class="page-header-right">
            <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header">
                        <span class="profile-menu-name"><?php echo $h($_SESSION['full_name'] ?? 'Account'); ?></span>
                        <span class="profile-menu-role">Admin account</span>
                    </div>
                    <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>

    <nav class="ar-page-nav" aria-label="Application navigation">
        <a class="ar-back-link" href="manage_operators.php"><i class="fa fa-arrow-left" aria-hidden="true"></i><span>Back to Manage Operators</span></a>
        <a class="ad-btn" href="operator_applications.php"><i class="fa fa-user-check" aria-hidden="true"></i><span>Operator applications</span></a>
    </nav>

    <?php if ($flash): ?><div class="alert alert-success" role="status"><?php echo $h($flash); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo $h($error); ?></div><?php endif; ?>

    <nav class="ad-seg vf-tabs" aria-label="Filter applications by status">
        <?php foreach ($opts as $key => $label): ?>
        <a href="?status=<?php echo $h($key); ?>" class="<?php echo $f === $key ? 'on' : ''; ?>" <?php echo $f === $key ? 'aria-current="page"' : ''; ?>><?php echo $label; ?> <span class="vf-count"><?php echo (int)$counts[$key]; ?></span></a>
        <?php endforeach; ?>
        <a href="?status=all" class="<?php echo $f === 'all' ? 'on' : ''; ?>" <?php echo $f === 'all' ? 'aria-current="page"' : ''; ?>>All <span class="vf-count"><?php echo array_sum($counts); ?></span></a>
    </nav>

    <?php if (!$rows): ?>
        <div class="ca-empty"><i class="fa fa-inbox" aria-hidden="true"></i><strong>No <?php echo $f === 'all' ? '' : $h($f) . ' '; ?>applications</strong><span>Applications matching this status will appear here.</span></div>
    <?php else: ?>
        <div class="ca-list">
            <?php foreach ($rows as $r): $status = strtolower((string)$r['status']); ?>
            <article class="ca-card">
                <div class="ca-head">
                    <div class="ca-person">
                        <h2><?php echo $h($r['full_name']); ?></h2>
                        <p><?php echo $h($r['email']); ?> <span aria-hidden="true">·</span> <?php echo $h($r['phone_number']); ?></p>
                    </div>
                    <span class="ad-pill <?php echo $status === 'pending' ? 'amber' : ($status === 'approved' ? 'active' : 'cancelled'); ?>"><?php echo ucfirst($h($status)); ?></span>
                </div>
                <dl class="ca-facts">
                    <div><dt>Application type</dt><dd>Conductor</dd></div>
                    <div><dt>Submitted</dt><dd><?php echo $h(date('M j, Y · g:i A', strtotime((string)$r['submitted_at']))); ?></dd></div>
                    <div><dt>Contact email</dt><dd><?php echo $h($r['email']); ?></dd></div>
                </dl>
                <?php if ($status === 'rejected' && $r['rejection_reason']): ?><p class="ca-reason"><strong>Rejection reason:</strong> <?php echo $h($r['rejection_reason']); ?></p><?php endif; ?>
                <?php if ($status === 'pending'): ?>
                <form method="POST" class="ca-actions" action="conductor_applications.php?status=<?php echo $h($f); ?>">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="application_id" value="<?php echo (int)$r['application_id']; ?>">
                    <button class="ad-btn primary" name="approve" value="1" type="submit" onclick="return confirm(<?php echo htmlspecialchars(json_encode('Approve the conductor application for ' . (string)$r['full_name'] . '?'), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fa fa-check" aria-hidden="true"></i> Approve</button>
                    <details class="ca-reject">
                        <summary class="ad-btn"><i class="fa fa-xmark" aria-hidden="true"></i> Reject</summary>
                        <textarea name="rejection_reason" maxlength="500" placeholder="Write a short reason (required, up to 500 characters)" aria-label="Rejection reason for <?php echo $h($r['full_name']); ?>"></textarea>
                        <button class="ad-btn" name="reject" value="1" type="submit"><i class="fa fa-paper-plane" aria-hidden="true"></i> Send rejection</button>
                    </details>
                </form>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    if (toggle) toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    document.body.style.overflow = isOpen ? 'hidden' : '';
    if (isOpen) {
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    }
}
document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768 && document.querySelector('.sidebar').classList.contains('open')) toggleSidebar();
    });
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.querySelector('.sidebar').classList.contains('open')) toggleSidebar();
});
</script>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_schedules.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span></a>
    <a href="manage_buses.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span></a>
    <a href="manage_operators.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-users" aria-hidden="true"></i><span>Operators</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
