<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'commuter') {
    header('Location: ../login.php');
    exit;
}
$schedule_id = filter_var($_GET['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Bus Tracking — MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/live_pickup.css">
</head>
<body class="has-bottom-nav live-page">
<main class="main" role="main">
    <header class="page-header">
        <div class="page-header-left"><h1>Live bus tracking</h1></div>
        <div class="page-header-right">
            <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1))); ?></span>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header"><span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span><span class="profile-menu-role">Commuter account</span></div>
                    <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>
    <div class="live-heading">
        <span class="live-tag"><span aria-hidden="true"></span>Trip location</span>
        <p>Pick a bus to see where it is right now.</p>
    </div>
    <div id="liveMessage" class="live-notice" role="status" aria-live="polite">Loading current bus locations…</div>
    <div class="live-layout">
        <section class="live-map-panel live-card" aria-labelledby="mapHeading">
            <div class="live-card-head"><h2 id="mapHeading">Bus location</h2><span id="trackingStatus" class="live-status is-ended">Waiting for trip</span></div>
            <div id="trackingMap" class="live-map" aria-label="Live bus map"></div>
            <p id="mapMessage" class="live-map-hint">The bus appears once the driver shares location.</p>
            <div class="live-map-footer"><span id="updatedAt">Location update: —</span><span id="freshness">—</span></div>
            <p class="live-small live-map-disclaimer">Refreshes while this page is open.</p>
        </section>
        <div class="live-side">
            <section class="live-card" aria-labelledby="tripHeading">
                <div class="live-card-head"><h2 id="tripHeading">Bus in service</h2></div>
                <div class="live-field"><label for="liveSchedule">Choose a bus</label><select id="liveSchedule" name="schedule_id" disabled><option value="">Loading trips…</option></select></div>
                <p id="tripRoute" class="live-route-copy">Loading current buses…</p>
            </section>
        </div>
    </div>
    <noscript><p class="live-notice live-notice-error">Enable JavaScript to load live bus locations.</p></noscript>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-map-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script>window.mbusLive = <?php echo json_encode(['scheduleId' => $schedule_id, 'csrfToken' => csrf_get_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/commuter_live.js"></script>
<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
