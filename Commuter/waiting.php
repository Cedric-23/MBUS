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
    <link rel="stylesheet" href="../Assets/css/waiting.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
</head>
<body class="waiting-page has-bottom-nav">
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
                    <a href="notifications.php" role="menuitem"><i class="fa fa-bell" aria-hidden="true"></i> Updates</a>
                    <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                    <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                </div>
            </div>
        </div>
    </header>

    <p id="waitFlash" class="wait-flash" role="status" aria-live="polite" hidden></p>

    <section id="waitSkeleton" class="wait-skeleton" aria-hidden="true">
        <span></span><span></span><span></span>
    </section>

    <!-- State: rider is marked waiting -->
    <section id="waitState" class="wait-card wait-card-status" aria-labelledby="waitStateTitle" hidden>
        <div class="wait-status-top">
            <span class="wait-live"><span class="wait-dot" aria-hidden="true"></span><span id="waitStateLabel">You're waiting</span></span>
            <span id="waitStateChip" class="wait-chip">Active</span>
        </div>
        <h2 id="waitStateTitle">—</h2>
        <p id="waitStop" class="wait-stop">—</p>
        <dl class="wait-meta">
            <div><dt>Shared</dt><dd id="waitShared">—</dd></div>
            <div><dt>Expires</dt><dd id="waitExpires">—</dd></div>
            <div><dt>Bus</dt><dd id="waitBusGps">—</dd></div>
        </dl>
        <div class="wait-actions">
            <button type="button" id="waitCancel" class="wait-btn wait-btn-danger">Cancel waiting</button>
        </div>
    </section>

    <!-- State: choose a bus, pin the pickup point, then mark waiting -->
    <section id="waitPicker" class="wait-card wait-picker-card" aria-labelledby="waitPickerTitle" hidden>
        <h2 id="waitPickerTitle">Mark yourself as waiting</h2>
        <p class="wait-lead">Choose a bus, pin your spot, then confirm.</p>

        <div class="wait-step-heading"><span>1</span><h3>Choose your bus</h3></div>
        <div id="waitTrips" class="wait-trips" role="radiogroup" aria-label="Active buses"></div>

        <div class="wait-step-heading"><span>2</span><h3>Pin your pickup spot</h3></div>
        <button type="button" id="waitPin" class="wait-btn wait-btn-location" disabled>
            <i class="fa fa-location-crosshairs" aria-hidden="true"></i>
            <span>Use my current location</span>
        </button>
        <p id="waitPinStatus" class="wait-pin-status" role="status" aria-live="polite">Choose a bus first</p>
        <div id="waitMap" class="wait-map" aria-label="Tap the map or drag the pin to set your pickup spot"></div>
        <p class="wait-map-tip">Tap the map or move the pin to adjust.</p>

        <div class="wait-optional">
            <label class="wait-landmark">Stop name <span>(optional)</span>
                <input id="waitLabel" type="text" maxlength="100" autocomplete="off" placeholder="e.g. Main gate">
            </label>
        </div>
        <div class="wait-coords" hidden>
            <label>Latitude<input id="waitLat" type="number" inputmode="decimal" step="any" min="-90" max="90"></label>
            <label>Longitude<input id="waitLng" type="number" inputmode="decimal" step="any" min="-180" max="180"></label>
        </div>
        <button type="button" id="waitShare" class="wait-btn wait-btn-primary" disabled>
            <i class="fa fa-hourglass-half" aria-hidden="true"></i>
            <span id="waitShareLabel">Mark me as waiting</span>
        </button>
    </section>

    <!-- State: no active bus -->
    <section id="waitEmpty" class="wait-card wait-empty" hidden>
        <i class="fa fa-bus" aria-hidden="true"></i>
        <h2>No bus available</h2>
        <p>Check back when a bus is boarding or in service.</p>
        <a href="schedule.php" class="wait-btn wait-btn-ghost">See schedule</a>
    </section>

    <section class="wait-updates" aria-label="Trip updates">
        <a href="live.php" class="wait-updates-link">
            <span><i class="fa fa-map-location-dot" aria-hidden="true"></i> Track the bus</span>
            <i class="fa fa-chevron-right" aria-hidden="true"></i>
        </a>
        <a href="notifications.php" class="wait-updates-link">
            <span><i class="fa fa-bell" aria-hidden="true"></i> Updates</span>
            <i class="fa fa-chevron-right" aria-hidden="true"></i>
        </a>
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
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/commuter_waiting.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
