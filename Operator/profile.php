<?php
session_start();
if(!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator'){
    header('Location: ../login.php');
    exit();
}
$name = htmlspecialchars($_SESSION['full_name'] ?? 'Operator');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Operator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css"><link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
</head>
<body class="has-bottom-nav">
<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>My Profile</h1></div>
        <div class="page-header-right"><div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($name, 0, 1)); ?></span></button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role">Operator account</span></div>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div></div>
    </header>
    <section class="card" style="max-width:720px;margin:0 auto;"><h2><i class="fa fa-user" aria-hidden="true"></i> Account</h2><p>You are signed in as <strong><?php echo $name; ?></strong>.</p></section>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
    <a href="passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
    <a href="notifications.php" class="bottom-nav-item"><i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body></html>
