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
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/waiting_board.css">
    <link rel="stylesheet" href="../Assets/css/commuter_live.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/commuter_live.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=2">
    <link rel="stylesheet" href="../Assets/css/commuter_modern.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/commuter_modern.css'); ?>">
</head>
<body class="has-bottom-nav commuter-live-page cm">
<main class="main lv-shell" role="main">
    <h1 class="lv-visually-hidden">Live buses</h1>
    <p id="liveMessage" class="lv-notice" role="status" aria-live="polite" hidden></p>

    <div class="lv-content">
        <section class="lv-map-panel" aria-labelledby="mapHeading">
            <div class="lv-panel-head">
                <div>
                    <p class="lv-section-label">LIVE MAP</p>
                    <h2 id="mapHeading">Bus locations</h2>
                </div>
                <button type="button" id="routeViewButton" class="route-view-btn" disabled aria-pressed="false">Focus bus</button>
            </div>
            <div class="lv-map-toolbar">
                <label class="lv-filter">
                    <span class="lv-filter-label">Trip</span>
                    <select id="liveSchedule" name="schedule_id" aria-label="Choose a bus" disabled>
                        <option value="">All buses</option>
                    </select>
                </label>
                <div class="lv-legend" aria-label="Map legend">
                    <span><i class="dot-bus" aria-hidden="true"></i>Live</span>
                    <span><i class="route-line-key" aria-hidden="true"></i>Route</span>
                    <span><i class="dot-stale" aria-hidden="true"></i>Last pin</span>
                </div>
            </div>
            <div id="trackingMap" class="lv-map" role="region" aria-label="Map of live buses"></div>
            <p id="mapMessage" class="lv-map-error" role="status" aria-live="polite" hidden></p>
            <div class="lv-route-card">
                <span class="lv-route-icon" aria-hidden="true"><i class="fa fa-route"></i></span>
                <p id="routeEstimate" role="status" aria-live="polite">Select a bus to see its road route and destination estimate.</p>
            </div>
        </section>

        <section class="lv-bus-panel" aria-labelledby="busListHeading">
            <div class="lv-bus-panel-head">
                <div>
                    <p class="lv-section-label">IN SERVICE</p>
                    <h2 id="busListHeading">Active buses</h2>
                    <p class="lv-bus-hint">Tap a bus to focus its map pin.</p>
                </div>
                <div class="lv-bus-stats">
                    <span class="lv-live-count" role="status" aria-live="polite">
                        <span class="lv-live-indicator" aria-hidden="true"></span>
                        <span class="lv-live-count-text"><strong id="busCount">0</strong><span id="busLabel">live</span></span>
                    </span>
                    <span class="lv-trip-count"><strong id="trackingStatus">0</strong><span>trips</span></span>
                    <div class="profile-menu-wrap">
                        <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                            <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
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
            </div>
            <p id="busEmpty" class="lv-empty">Finding active buses…</p>
            <div id="busList" class="lv-bus-list" aria-live="polite"></div>
            <p class="lv-refresh-note"><i class="fa fa-rotate" aria-hidden="true"></i> Auto-refreshes while this page is open</p>
        </section>
    </div>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-map-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>

<script>window.mbusLive = <?php echo json_encode(['scheduleId' => $schedule_id], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/route_utils.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/route_utils.js'); ?>"></script>
<script src="../Assets/js/live_schedule_selection.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/live_schedule_selection.js'); ?>"></script>
<script src="../Assets/js/commuter_live.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/commuter_live.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
