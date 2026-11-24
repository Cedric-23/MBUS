<?php
require_once __DIR__ . '/csrf.php';
// Shared Waiting board for staff roles. Driver/Conductor: assigned buses. Admin: all buses.
if (!isset($pickupStaffRole) || !in_array($pickupStaffRole, ['admin', 'driver', 'conductor'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$isAdmin = $pickupStaffRole === 'admin';
$isConductor = $pickupStaffRole === 'conductor';
$isDriver = $pickupStaffRole === 'driver';
$dashboard = $isAdmin ? 'admin_dashboard.php' : ($isDriver ? 'operator_dashboard.php' : '../Conductor/conductor_dashboard.php');
$roleLabel = $isAdmin ? 'Admin' : ($isDriver ? 'Driver' : 'Conductor');
$navItems = $isAdmin
    ? [
        ['admin_dashboard.php', 'fa-home', 'Home'],
        ['manage_schedules.php', 'fa-calendar', 'Schedules'],
        ['manage_buses.php', 'fa-bus', 'Buses'],
        ['manage_operators.php', 'fa-users', 'Operators'],
    ]
    : ($isDriver
        ? [
        [$dashboard, 'fa-home', 'Home'],
        ['my_bus.php', 'fa-bus-simple', 'My Bus'],
    ]
    : [
        [$dashboard, 'fa-home', 'Home'],
        ['manage_trips.php', 'fa-bus', 'Trips'],
        ['scan.php', 'fa-qrcode', 'Scan'],
        ['passengers.php', 'fa-users', 'Riders'],
    ]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waiting — MBUS <?php echo $roleLabel; ?></title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/staff_waiting.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/staff_waiting.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/unify.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/staff_waiting_fix.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_waiting_fix.css'); ?>">
</head>
<body class="stf has-bottom-nav staff-wait-page<?php echo $isAdmin ? '' : ' staff-only-commuters'; ?>">
<main class="main" role="main" id="pickupStaff" data-role="<?php echo $pickupStaffRole; ?>" data-endpoint="../api/pickup.php" data-live-endpoint="../api/live_tracking.php">

    <header class="sw-bar">
        <div class="sw-title">
            <h1>Waiting</h1>
            <p id="staffUpdated">Loading…</p>
        </div>
        <label class="sw-filter">
            <span class="sr-only">Choose a bus</span>
            <select id="staffSchedule" aria-label="Choose a bus"><option value="">All buses</option></select>
        </label>
        <button type="button" id="staffRefresh" class="sw-icon-btn" aria-label="Refresh">
            <i class="fa fa-rotate" aria-hidden="true"></i>
        </button>
        <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                    <span class="profile-menu-role"><?php echo $roleLabel; ?> account</span>
                </div>
                <?php if ($isDriver): ?><a href="my_bus.php" role="menuitem"><i class="fa fa-bus-simple" aria-hidden="true"></i> My Bus</a><a href="reports.php" role="menuitem"><i class="fa fa-chart-line" aria-hidden="true"></i> Analytics</a><?php endif; ?>
                <?php if ($isConductor): ?><a href="payments.php" role="menuitem"><i class="fa fa-wallet" aria-hidden="true"></i> GCash Payments</a><a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a><?php elseif (!$isAdmin): ?><a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a><?php endif; ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </header>

    <div id="staffMap" class="sw-map" role="region" aria-label="Map of waiting riders and live buses"></div>
    <p id="staffNotice" class="sw-notice" role="status" aria-live="polite" hidden></p>
    <p id="staffMapError" class="sw-notice sw-notice-soft" hidden>Map tiles are unavailable right now. The list below is still current.</p>

    <section class="sw-sheet" aria-label="Riders waiting">
        <button type="button" class="sw-handle" id="swToggle" aria-expanded="true" aria-controls="swScroll">
            <span class="sw-handle-title" id="swSummary">Waiting</span>
            <i class="fa fa-chevron-down sw-handle-caret" aria-hidden="true"></i>
        </button>
        <div class="sw-sheet-head">
            <h2>Waiting</h2>
            <span class="sw-sheet-sub" id="busLabel"></span>
        </div>
        <?php if ($isAdmin): ?><div class="sw-view" id="swView" role="group" aria-label="What to show">
            <button type="button" data-view="both" aria-pressed="true">Both</button>
            <button type="button" data-view="buses" aria-pressed="false"><i class="fa fa-bus" aria-hidden="true"></i> Buses</button>
            <button type="button" data-view="commuters" aria-pressed="false"><i class="fa fa-user-group" aria-hidden="true"></i> Commuters</button>
        </div><?php else: ?><div class="sw-view" id="swView" role="group" aria-label="What to show">
            <button type="button" data-view="commuters" aria-pressed="true"><i class="fa fa-user-group" aria-hidden="true"></i> Commuters</button>
        </div><?php endif; ?>
        <div class="sw-route-controls">
            <p id="staffRouteEstimate" class="sw-route-estimate" role="status" aria-live="polite">Choose a bus to see its route and destination estimate.</p>
        </div>
        <div class="sw-scroll" id="swScroll">
            <section class="sw-bus-section" aria-labelledby="swBusTitle">
                <header class="sw-group-head">
                    <span class="sw-group-icon sw-bus-icon" aria-hidden="true"><i class="fa fa-bus"></i></span>
                    <div class="sw-group-title"><h3 id="swBusTitle">Buses</h3><small>Tap a bus to see only its riders</small></div>
                    <span class="sw-group-count" id="swBusCount">0</span>
                </header>
                <p id="swBusEmpty" class="sw-empty">No bus trips to show right now.</p>
                <ul id="swBusList" class="sw-bus-list"></ul>
            </section>
            <section class="sw-group sw-group-pickup" aria-labelledby="swPickupTitle">
                <header class="sw-group-head">
                    <span class="sw-group-icon" aria-hidden="true"><i class="fa fa-location-dot"></i></span>
                    <div class="sw-group-title">
                        <h3 id="swPickupTitle">Waiting for pick-up</h3>
                        <small>Pinned a spot on the map</small>
                    </div>
                    <span class="sw-group-count" id="staffCount">0</span>
                </header>
                <p id="staffEmpty" class="sw-empty">No one is waiting for a pick-up.</p>
                <ul id="staffList" class="sw-list" aria-live="polite"></ul>
            </section>
            <section class="sw-group sw-group-reserve" aria-labelledby="swReserveTitle">
                <header class="sw-group-head">
                    <span class="sw-group-icon" aria-hidden="true"><i class="fa fa-ticket"></i></span>
                    <div class="sw-group-title">
                        <h3 id="swReserveTitle">Waiting for reservation</h3>
                        <small>Paid seat, not on board yet</small>
                    </div>
                    <span class="sw-group-count" id="staffReserveCount">0</span>
                </header>
                <p id="staffReserveEmpty" class="sw-empty">No reserved riders are waiting to board.</p>
                <ul id="staffReserveList" class="sw-list" aria-live="polite"></ul>
            </section>
        </div>
    </section>
    <noscript><p class="sw-notice">Enable JavaScript to see waiting riders.</p></noscript>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <?php foreach ($navItems as $item): ?>
    <a href="<?php echo $item[0]; ?>" class="bottom-nav-item"><i class="fa <?php echo $item[1]; ?>" aria-hidden="true"></i><span><?php echo $item[2]; ?></span></a>
    <?php endforeach; ?>
    <a href="waiting.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/route_utils.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/route_utils.js'); ?>"></script>
<script src="../Assets/js/stop_coords.js?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/js/stop_coords.js'); ?>"></script>
<script src="../Assets/js/staff_waiting.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/staff_waiting.js'); ?>"></script>
<script src="../Assets/js/staff_waiting_toggles.js?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/js/staff_waiting_toggles.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
<?php if ($isDriver || $isConductor) { ?><script>window.OPERATOR_CSRF_TOKEN = <?php echo json_encode(csrf_get_token()); ?>;</script><?php } ?>
<?php if (in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['driver', 'conductor'], true)) { ?><script src="../Assets/js/operator_tracker.js"></script><?php } ?>
</body>
</html>
