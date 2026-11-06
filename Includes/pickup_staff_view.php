<?php
// Included by role-checked Admin/waiting.php and Operator/waiting.php; no DB writes on page load.
if (!isset($pickupStaffRole) || !in_array($pickupStaffRole, ['admin', 'operator'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$isAdmin = $pickupStaffRole === 'admin';
$dashboard = $isAdmin ? 'admin_dashboard.php' : 'operator_dashboard.php';
$roleLabel = $isAdmin ? 'Admin' : 'Operator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waiting pickups — MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/live_pickup.css">
    <style>
        .pickup-staff-page { font-family: Inter, sans-serif; background: #f6f8fb; }
        .pickup-staff-page .main { box-sizing: border-box; }
        .pickup-staff-facts { margin: 12px 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; font-size: .875rem; }
        .pickup-staff-facts dt { color: #667085; }
        .pickup-staff-facts dd { margin: 0; overflow-wrap: anywhere; }
        .pickup-staff-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .pickup-staff-actions .live-button-primary { width: auto; flex: 1; }
        .pickup-staff-page .live-card h3 { margin: 0; font-size: 1rem; overflow-wrap: anywhere; }
        .pickup-staff-page .page-header { gap: 12px; flex-wrap: wrap; }
    </style>
</head>
<body class="live-page pickup-staff-page">
<main class="main" role="main" id="pickupStaff" data-endpoint="../api/pickup.php">
    <header class="page-header">
        <div class="page-header-left">
            <a href="<?php echo $dashboard; ?>" class="back-btn" aria-label="Back to dashboard"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
            <h1>Waiting pickups</h1>
        </div>
        <div class="page-header-right">
            <a class="live-button live-button-secondary" href="<?php echo $isAdmin ? 'manage_schedules.php' : 'manage_trips.php'; ?>"><?php echo $isAdmin ? 'Manage schedules' : 'Manage trips'; ?></a>
            <?php if (!$isAdmin): ?><a class="live-button live-button-secondary" href="passengers.php">Reserved riders</a><?php endif; ?>
        </div>
    </header>
    <section class="live-heading">
        <span class="live-tag"><span aria-hidden="true"></span><?php echo $roleLabel; ?> pickup workspace</span>
        <p><?php echo $isAdmin ? 'Review waiting commuters across all buses.' : 'Review waiting commuters on your assigned buses only.'; ?> Refreshes every 15 seconds.</p>
    </section>
    <p id="staffNotice" class="live-notice" role="status" aria-live="polite" hidden></p>
    <p class="live-notice">Pickup requests do not reserve or guarantee a seat, create a ticket, or collect payment. Check safe stopping conditions and capacity before pickup.</p>
    <div class="live-layout">
        <section class="live-card live-map-panel" aria-labelledby="staffMapHeading">
            <div class="live-card-head"><h2 id="staffMapHeading">Waiting locations</h2><button type="button" id="staffRefresh" class="live-button live-button-secondary">Refresh</button></div>
            <div id="staffMap" class="live-map" role="region" aria-label="Map of waiting commuters"></div>
            <p id="staffMapError" class="live-map-hint" hidden>The map could not load. Use the waiting list and coordinates below.</p>
            <p class="live-map-hint">Markers are commuter-shared pickup locations. They are not real bus GPS, live vehicle tracking, or an estimated arrival time.</p>
            <div class="live-map-footer"><span id="staffUpdated">Loading pickup requests…</span><span>Requests expire after 30 minutes.</span></div>
        </section>
        <section class="live-side" aria-label="Waiting commuter details">
            <div class="live-card">
                <div class="live-card-head"><h2>Waiting commuters</h2><span class="live-status" id="staffCount">0 waiting</span></div>
                <div class="live-field"><label for="staffSchedule">Filter by trip</label><select id="staffSchedule"><option value="">All authorized trips</option></select></div>
                <p class="live-small">Only active, unexpired requests are shown. Reading this page does not renew a commuter’s request.</p>
                <p id="staffEmpty" class="live-small">Loading waiting commuters…</p>
                <div id="staffList" class="live-staff-list"></div>
            </div>
        </section>
    </div>
    <noscript><p class="live-notice live-notice-error">Enable JavaScript to view and complete pickup requests.</p></noscript>
</main>
<nav class="bottom-nav" role="navigation" aria-label="<?php echo $roleLabel; ?> navigation">
    <a href="<?php echo $dashboard; ?>" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="<?php echo $isAdmin ? 'manage_schedules.php' : 'manage_trips.php'; ?>" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-map-marker-alt" aria-hidden="true"></i><span>Pickups</span></a>
    <a href="notifications.php" class="bottom-nav-item"><i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span></a>
</nav>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/pickup_staff.js"></script>
</body>
</html>
