<?php
require_once __DIR__ . "/../config/app.php";
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
include "../config/db_connect.php";
include "../Includes/csrf.php";
include_once "../Includes/announcements.php";

/* Commuters see announcements sent to everyone or to commuters. */
$items = mbus_announcements_active($conn, ['All', 'Commuters']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service updates - MBUS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/commuter_modern.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/commuter_modern.css'); ?>">
    <style>
        .upd-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .upd-card { padding: 14px 16px; border-radius: 14px; background: #fff; box-shadow: 0 1px 4px rgba(20, 40, 80, .08); }
        .upd-card h2 { margin: 6px 0 4px; font-size: 1rem; }
        .upd-card p { margin: 0; font-size: .9rem; line-height: 1.4; overflow-wrap: anywhere; }
        .upd-card small { color: #6b7a90; }
        .upd-tag { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #e3ecfb; color: #2f6fe4; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .upd-tag.is-suspension, .upd-tag.is-delay, .upd-tag.is-service-alert { background: #ffe9d6; color: #b4531a; }
        .upd-tag.is-service-resumed { background: #dff5e6; color: #1f7a3d; }
    </style>
</head>
<body class="home-page has-bottom-nav cm">
<main class="main" role="main">
    <header class="home-top">
        <div>
            <p class="home-hello">Bus operations</p>
            <h1>Service updates</h1>
        </div>
    </header>

    <?php if (!$items): ?>
        <p class="home-empty">No service updates right now. Buses are running as scheduled.</p>
    <?php else: ?>
    <ul class="upd-list">
        <?php foreach ($items as $a): ?>
        <li class="upd-card">
            <span class="upd-tag is-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $a['category']))); ?>"><?php echo htmlspecialchars($a['category']); ?></span>
            <h2><?php echo htmlspecialchars($a['title']); ?></h2>
            <p><?php echo nl2br(htmlspecialchars($a['body'])); ?></p>
            <small><?php echo date('M j, g:i A', strtotime($a['created_at'])); ?><?php echo $a['expires_at'] ? ' &middot; until ' . date('M j, g:i A', strtotime($a['expires_at'])) : ''; ?></small>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item"><i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
