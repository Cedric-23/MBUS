<?php
/* Transportation updates for staff: announcements sent to everyone or to staff (operators, drivers, conductors). */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../Includes/staff_nav.php';
require_once __DIR__ . '/../Includes/header_avatar.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!in_array($role, ['driver', 'operator', 'conductor'], true)) {
    header('Location: ../login.php');
    exit();
}
$staffRole = $role === 'conductor' ? 'conductor' : 'driver';
$staffLabel = $staffRole === 'conductor' ? 'Conductor' : 'Driver';
include '../config/db_connect.php';
include_once '../Includes/announcements.php';
$items = mbus_announcements_active($conn, ['All', 'Staff']);
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service updates - <?php echo $h($staffLabel); ?></title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <style>
        .updates-wrap { max-width: 720px; margin: 0 auto; padding: 20px 16px 40px; }
        .updates-sub { margin: 0 0 16px; color: #6b7a90; font-size: .9rem; }
        .updates-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .updates-card { padding: 14px 16px; border-radius: 14px; background: #fff; box-shadow: 0 1px 4px rgba(20, 40, 80, .08); }
        .updates-card h2 { margin: 6px 0 4px; font-size: 1rem; }
        .updates-card p { margin: 0; font-size: .9rem; line-height: 1.4; overflow-wrap: anywhere; }
        .updates-card small { color: #6b7a90; }
        .updates-tag { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #e3ecfb; color: #2f6fe4; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .updates-tag.is-suspension, .updates-tag.is-delay, .updates-tag.is-service-alert { background: #ffe9d6; color: #b4531a; }
        .updates-tag.is-service-resumed { background: #dff5e6; color: #1f7a3d; }
        .updates-empty { padding: 24px; text-align: center; color: #6b7a90; background: #fff; border-radius: 14px; }
    </style>
</head>
<body class="stf has-sidebar has-bottom-nav staff-updates">
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="<?php echo $h($staffLabel); ?> navigation">
        <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
        <h2><?php echo $h($staffLabel); ?></h2>
        <?php echo mbus_staff_sidebar_nav($staffRole, 'updates'); ?>
        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
        </div>
    </aside>
    <main class="main" role="main">
        <header class="page-header" role="banner">
            <div class="page-header-left"><h1>Service updates</h1></div>
            <div class="page-header-right"><div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php echo mbus_header_avatar('../'); ?></span>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header">
                        <span class="profile-menu-name"><?php echo $h($_SESSION['full_name'] ?? $staffLabel); ?></span>
                        <span class="profile-menu-role"><?php echo $h($staffLabel); ?> account</span>
                    </div>
                    <?php echo mbus_staff_profile_links($staffRole); ?>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div></div>
        </header>
        <div class="updates-wrap">
            <p class="updates-sub">Announcements about bus operations and service availability.</p>
            <?php if (!$items): ?>
                <p class="updates-empty">No service updates right now.</p>
            <?php else: ?>
            <ul class="updates-list">
                <?php foreach ($items as $a): ?>
                <li class="updates-card">
                    <span class="updates-tag is-<?php echo $h(strtolower(str_replace(' ', '-', $a['category']))); ?>"><?php echo $h($a['category']); ?></span>
                    <h2><?php echo $h($a['title']); ?></h2>
                    <p><?php echo nl2br($h($a['body'])); ?></p>
                    <small><?php echo $h(date('M j, g:i A', strtotime($a['created_at']))); ?><?php echo $a['expires_at'] ? ' &middot; until ' . $h(date('M j, g:i A', strtotime($a['expires_at']))) : ''; ?></small>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </main>
    <?php echo mbus_staff_bottom_nav($staffRole, 'updates'); ?>
    <script src="../Assets/js/app-header.js"></script>
</body>
</html>
