<?php
session_start();
if (!isset($_SESSION['user_type']) || strtolower($_SESSION['user_type']) != 'admin') {
    header("Location: ../login.php");
    exit();
}
include("../config/db_connect.php");
include("../Includes/csrf.php");
include_once("../Includes/announcements.php");
include_once("../Includes/admin_menu.php");

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$success = null; $error = null;

/* PUBLISH */
if (isset($_POST['publish_announcement'])) {
    if (!csrf_validate_token()) $error = "Security validation failed. Please try again.";
    else {
        [$clean, $err] = mbus_announcement_validate($_POST);
        if ($err !== '') $error = $err;
        elseif (mbus_announcement_publish($conn, $clean, $admin_id) === 0) $error = "The announcement could not be published. Please try again.";
        else $success = "Announcement published and sent to " . strtolower($clean['audience'] === 'All' ? 'all commuters and staff' : $clean['audience']) . ".";
    }
}

/* WITHDRAW */
if (isset($_POST['withdraw_announcement'])) {
    if (!csrf_validate_token()) $error = "Security validation failed. Please try again.";
    elseif (!isset($_POST['announcement_id']) || !is_scalar($_POST['announcement_id']) || filter_var($_POST['announcement_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) $error = "Invalid announcement.";
    elseif (!mbus_announcement_withdraw($conn, (int)$_POST['announcement_id'])) $error = "The announcement could not be withdrawn.";
    else $success = "Announcement withdrawn.";
}

$items = mbus_announcements_active($conn);
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/admin/admin_ui.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/admin/admin_ui.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>
<body class="stf has-sidebar has-bottom-nav">

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Admin</h2>
    <nav class="menu-top" aria-label="Admin menu">
        <a href="admin_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="manage_schedules.php"><i class="fa fa-calendar" aria-hidden="true"></i> Manage Schedules</a>
        <a href="manage_buses.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Buses</a>
        <a href="manage_operators.php"><i class="fa fa-user-gear" aria-hidden="true"></i> Manage Operators</a>
        <a href="manage_routes.php"><i class="fa fa-route" aria-hidden="true"></i> Manage Routes</a>
        <a href="announcements.php" aria-current="page"><i class="fa fa-bullhorn" aria-hidden="true"></i> Announcements</a>
    </nav>
    <div class="menu-bottom"><a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a></div>
</aside>

<main class="main" role="main">
<header class="page-header" role="banner">
    <div class="page-header-left"><h1>Announcements</h1></div>
    <div class="page-header-right">                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
                <div class="profile-menu-wrap">
        <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
            <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span><?php echo mbus_admin_dot($conn); ?>
        </button>
        <div class="profile-menu" id="profileMenu" role="menu">
            <div class="profile-menu-header">
                <span class="profile-menu-name"><?php echo $h($_SESSION['full_name'] ?? 'Account'); ?></span>
                <span class="profile-menu-role">Admin account</span>
            </div>
            <?php echo mbus_admin_approvals_link($conn); ?>
            <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
        </div>
    </div></div>
</header>

<?php if ($success): ?><div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<section class="ad-group">
    <h2 class="ad-day">Publish a transportation update</h2>
    <form method="POST" class="ad-form">
        <?php csrf_field(); ?>
        <label class="ad-field"><span>Title</span><input type="text" name="title" maxlength="120" required placeholder="e.g. Morong trips delayed by road works"></label>
        <label class="ad-field"><span>Message</span><textarea name="body" rows="4" maxlength="1000" required placeholder="What riders and staff need to know"></textarea></label>
        <div class="ad-two">
            <label class="ad-field"><span>Category</span>
                <select name="category"><?php foreach (MBUS_ANNOUNCEMENT_CATEGORIES as $c): ?><option><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?></select></label>
            <label class="ad-field"><span>Send to</span>
                <select name="audience"><?php foreach (MBUS_ANNOUNCEMENT_AUDIENCES as $a): ?><option><?php echo htmlspecialchars($a); ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="ad-field"><span>Expires (optional)</span><input type="datetime-local" name="expires_at" min="<?php echo date('Y-m-d\TH:i'); ?>"></label>
        <button type="submit" name="publish_announcement" value="1" class="ad-btn primary"><i class="fa fa-bullhorn" aria-hidden="true"></i> Publish</button>
    </form>
</section>

<section class="ad-group">
    <h2 class="ad-day">Active announcements <span><?php echo count($items); ?></span></h2>
    <?php if (!$items): ?><div class="ad-empty"><i class="fa fa-bullhorn" aria-hidden="true"></i><p>No active announcements.</p></div><?php endif; ?>
    <?php foreach ($items as $a): ?>
    <article class="ad-tr">
        <div class="ad-c route">
            <span><strong><?php echo htmlspecialchars($a['category']); ?></strong> &middot; <?php echo htmlspecialchars($a['title']); ?></span>
            <small><?php echo htmlspecialchars($a['audience']); ?> &middot; <?php echo date('M j, g:i A', strtotime($a['created_at'])); ?><?php echo $a['expires_at'] ? ' &middot; expires ' . date('M j, g:i A', strtotime($a['expires_at'])) : ''; ?></small>
            <p><?php echo nl2br(htmlspecialchars($a['body'])); ?></p>
        </div>
        <div class="ad-c actions">
            <form method="POST" data-confirm="Withdraw this announcement?"><?php csrf_field(); ?><input type="hidden" name="announcement_id" value="<?php echo (int)$a['announcement_id']; ?>"><button type="submit" name="withdraw_announcement" value="1" class="ad-btn">Withdraw</button></form>
        </div>
    </article>
    <?php endforeach; ?>
</section>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_schedules.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span></a>
    <a href="manage_buses.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span></a>
    <a href="manage_operators.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Operators</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script src="../Assets/js/admin_list.js?v=<?php echo filemtime(__DIR__ . '/../Assets/js/admin_list.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/notifications.js"></script>

</body>
</html>
