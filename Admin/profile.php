<?php
session_start();
include_once __DIR__ . '/../config/db_connect.php';
include_once __DIR__ . '/../Includes/profile_photo.php';
if(!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'admin'){
    header('Location: ../login.php');
    exit();
}
[$photoOk, $photoErr] = mbus_photo_handle($conn, (int)$_SESSION['user_id']);
$name = htmlspecialchars($_SESSION['full_name'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css"><link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>
<body class="has-bottom-nav">
<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>My Profile</h1></div>
        <div class="page-header-right"><div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span></button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role">Admin account</span></div>
                <?php include_once "../Includes/admin_menu.php"; echo mbus_admin_approvals_link($conn); ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div></div>
    </header>
    <?php mbus_photo_card($conn, (int)$_SESSION['user_id'], (string)($_SESSION['full_name'] ?? 'Admin'), 'Admin account', $photoOk, $photoErr); ?>
    <section class="card" style="max-width:720px;margin:0 auto;"><h2><i class="fa fa-user" aria-hidden="true"></i> Account</h2><p>You are signed in as <strong><?php echo $name; ?></strong>.</p></section>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="admin_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_schedules.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedules</span></a>
    <a href="manage_buses.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Buses</span></a>
    <a href="manage_operators.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Operators</span></a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body></html>
