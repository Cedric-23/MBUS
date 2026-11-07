<?php
// Shared Waiting board for staff roles. Operator: own buses. Admin: all buses.
if (!isset($pickupStaffRole) || !in_array($pickupStaffRole, ['admin', 'operator'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$isAdmin = $pickupStaffRole === 'admin';
$dashboard = $isAdmin ? 'admin_dashboard.php' : 'operator_dashboard.php';
$roleLabel = $isAdmin ? 'Admin' : 'Operator';
$navItems = $isAdmin
    ? [
        ['admin_dashboard.php', 'fa-home', 'Home'],
        ['manage_schedules.php', 'fa-calendar', 'Schedules'],
        ['manage_buses.php', 'fa-bus', 'Buses'],
        ['manage_operators.php', 'fa-users', 'Operators'],
    ]
    : [
        ['operator_dashboard.php', 'fa-home', 'Home'],
        ['manage_trips.php', 'fa-bus', 'Trips'],
        ['scan.php', 'fa-qrcode', 'Scan'],
        ['passengers.php', 'fa-users', 'Riders'],
    ];
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
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/staff_waiting.css">
</head>
<body class="has-bottom-nav staff-wait-page">
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
                <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                    <span class="profile-menu-role"><?php echo $roleLabel; ?> account</span>
                </div>
                <a href="notifications.php" role="menuitem"><i class="fa fa-bell" aria-hidden="true"></i> Updates</a>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </header>

    <div id="staffMap" class="sw-map" role="region" aria-label="Map of waiting riders and live buses"></div>
    <p id="staffNotice" class="sw-notice" role="status" aria-live="polite" hidden></p>
    <p id="staffMapError" class="sw-notice sw-notice-soft" hidden>Map tiles are unavailable right now. The list below is still current.</p>

    <section class="sw-sheet" aria-label="Riders waiting">
        <div class="sw-sheet-head">
            <h2><span id="staffCount">0</span> waiting</h2>
            <span class="sw-sheet-sub" id="busLabel"></span>
        </div>
        <p id="staffEmpty" class="sw-empty">No one is waiting right now.</p>
        <ul id="staffList" class="sw-list" aria-live="polite"></ul>
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
<script src="../Assets/js/staff_waiting.js"></script>
<script src="../Assets/js/app-header.js"></script>
<?php if (strtolower((string)($_SESSION['user_type'] ?? '')) === 'operator') { ?><script src="../Assets/js/operator_tracker.js"></script><?php } ?>
</body>
</html>
