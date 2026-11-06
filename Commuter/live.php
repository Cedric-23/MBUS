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
    <title>Live Pickup — MBUS</title>
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
        <div class="page-header-left"><h1>Live pickup</h1></div>
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
    <div class="live-heading"><span class="live-tag"><span aria-hidden="true"></span>Waiting locations</span><p>Missed the reservation window? Let your operator know where you’re waiting.</p></div>
    <div id="liveMessage" class="live-notice" role="status" aria-live="polite">Loading available trips…</div>
    <div class="live-layout">
        <section class="live-map-panel live-card" aria-labelledby="mapHeading">
            <div class="live-card-head"><h2 id="mapHeading">Your waiting spot</h2><button class="live-button live-button-secondary" id="locateBtn" type="button"><i class="fa fa-location-crosshairs" aria-hidden="true"></i> Use my location</button></div>
            <div id="pickupMap" class="live-map" aria-label="Waiting location map. Tap the map to place your waiting pin."></div>
            <p id="mapMessage" class="live-map-hint">Tap the map or use your location, then drag the pin to a safe waiting spot.</p>
            <div class="live-map-footer"><span id="coordinateText">No waiting spot selected</span><button id="centerPinBtn" type="button" class="live-text-button">Pin map center</button></div>
            <p class="live-small live-map-disclaimer">Trip status is updated by the operator. This map does not track bus GPS.</p>
        </section>
        <div class="live-side">
            <section id="waitingCard" class="live-card live-waiting-card" aria-labelledby="waitingHeading" hidden>
                <div class="live-card-head"><h2 id="waitingHeading">Your pickup request</h2><span class="live-status" id="waitingStatus">Waiting</span></div>
                <p id="waitingTrip" class="live-request-title"></p>
                <p id="waitingDetails"></p>
                <p id="waitingExpiry" class="live-small"></p>
                <button id="cancelWaitingBtn" type="button" class="live-text-button live-danger">Stop waiting</button>
            </section>
            <section class="live-card" aria-labelledby="requestHeading">
                <div class="live-card-head"><h2 id="requestHeading">Arrange a pickup</h2></div>
                <form id="pickupForm">
                    <div class="live-field"><label for="liveSchedule">Bus &amp; route</label><select id="liveSchedule" name="schedule_id" required disabled><option value="">Loading trips…</option></select></div>
                    <p id="tripStatus" class="live-small"></p>
                    <a id="reserveInstead" class="live-button live-button-secondary" href="schedule.php" hidden>Reserve a seat instead</a>
                    <div class="live-field"><label for="pickupLabel">Waiting spot / nearby landmark</label><input id="pickupLabel" name="pickup_label" type="text" maxlength="100" placeholder="e.g. Main Gate bus stop" required autocomplete="off"><small>Wait at a safe, accessible roadside stop.</small></div>
                    <div class="live-field"><label for="liveDestination">Where are you going?</label><select id="liveDestination" name="destination" required disabled><option value="">Choose a bus first</option></select></div>
                    <details class="live-coordinate-entry"><summary>Enter coordinates instead</summary><div class="live-coordinate-fields"><label>Latitude<input id="manualLatitude" type="number" min="-90" max="90" step="any" placeholder="14.67"></label><label>Longitude<input id="manualLongitude" type="number" min="-180" max="180" step="any" placeholder="120.27"></label></div><button id="manualPinBtn" type="button" class="live-button live-button-secondary">Set waiting spot</button></details>
                    <p class="live-privacy"><i class="fa fa-shield-halved" aria-hidden="true"></i> Only after you confirm, your waiting pin and destination are shared with the assigned operators and admins.</p>
                    <button id="waitBtn" type="submit" class="live-button live-button-primary" disabled><i class="fa fa-hand" aria-hidden="true"></i> I’m waiting here</button>
                    <p class="live-small">Pickup is subject to bus availability and is not a seat reservation. Requests expire after 30 minutes; confirm again if you’re still waiting.</p>
                </form>
            </section>
        </div>
    </div>
    <noscript><p class="live-notice live-notice-error">Enable JavaScript to load the map and submit a waiting request.</p></noscript>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-map-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="notifications.php" class="bottom-nav-item"><i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span></a>
</nav>
<script>window.mbusLive = <?php echo json_encode(['scheduleId' => $schedule_id, 'csrfToken' => csrf_get_token(), 'timezoneOffset' => date('P')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/commuter_live.js"></script>
<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
