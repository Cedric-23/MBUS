<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
$schedule_id = filter_var($_GET['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'commuter') {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Waiting — MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_waiting_status.css">
    </head>
<body class="waiting-status-page has-bottom-nav">
<main class="main" role="main">

    <header class="page-header waiting-header">
        <div class="page-header-left"><h1>Waiting</h1></div>
        <div class="page-header-right">
            <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header">
                        <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                        <span class="profile-menu-role">Commuter account</span>
                    </div>
                    <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>

    <p id="waitFlash" class="wait-flash" role="status" aria-live="polite" hidden></p>

    <section id="statusCard" class="ws-card" aria-live="polite">
        <p class="ws-loading">Checking your status…</p>
    </section>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item"><i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>

<script>window.mbusWaitingConfig = <?php echo json_encode(['csrfToken' => csrf_get_token(), 'scheduleId' => $schedule_id], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="../Assets/js/commuter_waiting_status.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
