<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor') { header('Location: ../login.php'); exit(); }
include "../config/db_connect.php";
$fullName = trim((string)($_SESSION['full_name'] ?? 'Conductor'));
$firstName = explode(' ', $fullName !== '' ? $fullName : 'Conductor')[0];
$greeting = (int)date('G') < 12 ? 'Good morning' : ((int)date('G') < 18 ? 'Good afternoon' : 'Good evening');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBUS Conductor</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/unify.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
</head>
<body class="stf has-bottom-nav staff-home">
    <main class="main" role="main"><div class="home-wrap">

        <header class="home-top">
            <div>
                <p class="home-hello"><?php echo $greeting; ?></p>
                <h1><?php echo htmlspecialchars($firstName); ?></h1>
            </div>
            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($fullName); ?></span>
                            <span class="profile-menu-role">Conductor account</span>
                        </div>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <section class="home-section" aria-labelledby="welcome-h">
            <div class="home-section-head"><h2 id="welcome-h">Welcome</h2></div>
            <div class="ad-card">
                <p>Your conductor account is approved. Boarding and ticket tools will appear here once an operator assigns you to a trip.</p>
            </div>
        </section>

    </div></main>

<script src="../Assets/js/app-header.js"></script>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="conductor_dashboard.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="../logout.php" class="bottom-nav-item">
        <i class="fa fa-sign-out" aria-hidden="true"></i><span>Log out</span>
    </a>
</nav>
</body>
</html>
