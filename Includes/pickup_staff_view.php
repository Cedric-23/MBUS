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
    <link rel="stylesheet" href="../Assets/css/waiting_board.css">
</head>
<body class="has-bottom-nav waiting-board-page">
<main class="main" role="main" id="pickupStaff" data-endpoint="../api/pickup.php" data-live-endpoint="../api/live_tracking.php">

    <header class="page-header board-header">
        <div class="page-header-left">
            <h1>Waiting</h1>
            <p class="board-sub" id="staffUpdated">Loading…</p>
        </div>
        <div class="page-header-right">
            <button type="button" id="staffRefresh" class="board-refresh" aria-label="Refresh waiting list">
                <i class="fa fa-rotate" aria-hidden="true"></i>
            </button>
            <div class="profile-menu-wrap">
                <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                    <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
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
        </div>
    </header>

    <p id="staffNotice" class="board-notice" role="status" aria-live="polite" hidden></p>

    <section class="board-summary" aria-label="Summary">
        <div class="board-stat">
            <span class="board-stat-value" id="waitingCount">0</span>
            <span class="board-stat-label">waiting</span>
        </div>
        <div class="board-stat">
            <span class="board-stat-value" id="busCount">0</span>
            <span class="board-stat-label">buses live</span>
        </div>
        <label class="board-filter">
            <span class="sr-only">Filter by trip</span>
            <select id="staffSchedule" aria-label="Filter by trip">
                <option value="">All buses</option>
            </select>
        </label>
    </section>

    <section class="board-map-card" aria-labelledby="staffMapHeading">
        <div class="board-map-head">
            <h2 id="staffMapHeading">Live map</h2>
            <div class="board-legend" aria-hidden="true">
                <span><i class="dot-waiting"></i>Waiting</span>
                <span><i class="dot-bus"></i>Bus</span>
            </div>
        </div>
        <div id="staffMap" class="board-map" role="region" aria-label="Map of waiting riders and live buses"></div>
        <p id="staffMapError" class="board-map-error" hidden>Map tiles are unavailable right now.</p>
    </section>

    <section class="board-list-card" aria-labelledby="staffListHeading">
        <div class="board-list-head">
            <h2 id="staffListHeading">Riders waiting</h2>
            <span class="board-count" id="staffCount">0</span>
        </div>
        <p id="staffEmpty" class="board-empty">No one is waiting right now.</p>
        <div id="staffList" class="board-list" aria-live="polite"></div>
    </section>

    <noscript><p class="board-notice">Enable JavaScript to see waiting riders.</p></noscript>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <?php foreach ($navItems as $item): ?>
    <a href="<?php echo $item[0]; ?>" class="bottom-nav-item"><i class="fa <?php echo $item[1]; ?>" aria-hidden="true"></i><span><?php echo $item[2]; ?></span></a>
    <?php endforeach; ?>
    <a href="waiting.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/admin_waiting.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
