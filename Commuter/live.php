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
    <title>Live Buses — MBUS</title>
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
    <link rel="stylesheet" href="../Assets/css/waiting_board.css">
    <link rel="stylesheet" href="../Assets/css/commuter_live.css">
</head>
<body class="has-bottom-nav waiting-board-page commuter-live-page live-page">
<main class="main" role="main">
    <header class="page-header">
        <div class="page-header-left"><h1>Live bus tracking</h1></div>
        <div class="page-header-right">
            <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-header"><span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span><span class="profile-menu-role">Commuter account</span></div>
                    <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>
    <p id="liveMessage" class="board-notice" role="status" aria-live="polite" hidden></p>

    <section class="board-summary" aria-label="Summary">
        <div class="board-stat">
            <span class="board-stat-value" id="busCount">0</span>
            <span class="board-stat-label" id="busLabel">buses live</span>
        </div>
        <label class="board-filter">
            <span class="sr-only">Choose a bus</span>
            <select id="liveSchedule" name="schedule_id" aria-label="Choose a bus" disabled>
                <option value="">All buses</option>
            </select>
        </label>
    </section>

    <section class="board-map-card" aria-labelledby="mapHeading">
        <div class="board-map-head">
            <h2 id="mapHeading">Live map</h2>
            <div class="board-legend" aria-hidden="true">
                <span><i class="dot-bus"></i>Bus</span>
                <span><i class="dot-stale"></i>Old location</span>
            </div>
            <button type="button" id="routeViewButton" class="route-view-btn" disabled>Full route</button>
        </div>
        <div id="trackingMap" class="board-map" role="region" aria-label="Map of live buses"></div>
        <p id="mapMessage" class="board-map-error live-map-note" hidden></p>
        <p id="routeEstimate" class="route-estimate live-route-estimate" role="status" aria-live="polite">Select a bus to see its road route and destination estimate.</p>
    </section>

    <section class="board-list-card" aria-labelledby="busListHeading">
        <div class="board-list-head">
            <h2 id="busListHeading">Buses in service</h2>
            <span class="board-count" id="trackingStatus">0</span>
        </div>
        <p id="busEmpty" class="board-empty">Loading buses…</p>
        <div id="busList" class="board-list" aria-live="polite"></div>
        <p class="board-empty live-refresh-note">Updates every few seconds while this page is open.</p>
    </section>
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
<script src="../Assets/js/route_utils.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/route_utils.js'); ?>"></script>
<script src="../Assets/js/commuter_live.js"></script>
<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
