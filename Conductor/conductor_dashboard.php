<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['conductor', 'admin'], true)) {
    header('Location: ../login.php');
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/mysqli_compat.php';
require_once __DIR__ . '/../Includes/csrf.php';
require_once __DIR__ . '/../Includes/header_avatar.php';

$conductorId = (int)$_SESSION['user_id'];
$fullName = trim((string)($_SESSION['full_name'] ?? 'Conductor'));
$firstName = explode(' ', $fullName !== '' ? $fullName : 'Conductor')[0];
$greeting = (int)date('G') < 12 ? 'Good morning' : ((int)date('G') < 18 ? 'Good afternoon' : 'Good evening');
$bounds = mbus_local_bounds();

$assignedBuses = 0;
$assignedBusLabels = [];
$assignedQuery = mbus_db_prepare($conn, 'SELECT COUNT(*) AS n FROM conductor_bus_assignments WHERE conductor_id = ?', [$conductorId]);
if ($assignedQuery !== false && ($assignedRow = mbus_db_fetch_assoc($assignedQuery))) $assignedBuses = (int)$assignedRow['n'];
$assignedBusQuery = mbus_db_prepare($conn, "SELECT b.bus_number
    FROM conductor_bus_assignments cba JOIN buses b ON b.bus_id = cba.bus_id
    WHERE cba.conductor_id = ? ORDER BY b.bus_number ASC", [$conductorId]);
while ($assignedBusQuery !== false && ($assignedBusRow = mbus_db_fetch_assoc($assignedBusQuery))) {
    $assignedBusLabels[] = (string)$assignedBusRow['bus_number'];
}

$stats = ['trips' => 0, 'booked' => 0, 'boarded' => 0];
$statsQuery = mbus_db_prepare($conn, "SELECT COUNT(DISTINCT s.schedule_id) AS trips,
        COUNT(CASE WHEN r.status IN ('Paid', 'Confirmed', 'Boarded') THEN 1 END) AS booked,
        COUNT(CASE WHEN r.status = 'Boarded' THEN 1 END) AS boarded
    FROM schedule s
    JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
    LEFT JOIN reservation r ON r.schedule_id = s.schedule_id
    WHERE cba.conductor_id = ? AND s.schedule_status = 'Active'
      AND s.departure_time >= ? AND s.departure_time < ?", [$conductorId, $bounds['today'], $bounds['tomorrow']]);
if ($statsQuery !== false && ($statsRow = mbus_db_fetch_assoc($statsQuery))) $stats = array_map('intval', $statsRow);

$paymentCount = 0;
$paymentQuery = mbus_db_prepare($conn, "SELECT COUNT(*) AS n
    FROM paymongo_checkout pc
    JOIN schedule s ON s.schedule_id = pc.schedule_id
    JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
    WHERE cba.conductor_id = ? AND pc.session_id LIKE 'manual\\_%' AND LOWER(pc.status) = 'submitted'", [$conductorId]);
if ($paymentQuery !== false && ($paymentRow = mbus_db_fetch_assoc($paymentQuery))) $paymentCount = (int)$paymentRow['n'];

$waitingCount = 0;
$waitingQuery = mbus_db_prepare($conn, "SELECT COUNT(*) AS n
    FROM pickup_requests p
    JOIN schedule s ON s.schedule_id = p.schedule_id
    JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
    WHERE cba.conductor_id = ? AND p.status = 'Waiting' AND p.expires_at > NOW()
      AND s.schedule_status = 'Active' AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed')
      AND s.departure_time >= ? AND s.departure_time < ?", [$conductorId, $bounds['today'], $bounds['tomorrow']]);
if ($waitingQuery !== false && ($waitingRow = mbus_db_fetch_assoc($waitingQuery))) $waitingCount = (int)$waitingRow['n'];

$trips = [];
$tripQuery = mbus_db_prepare($conn, "SELECT s.schedule_id, s.departure_time, s.arrival_time, s.trip_status,
        b.bus_number, b.capacity, r.origin, r.destination,
        (SELECT COUNT(*) FROM reservation rv WHERE rv.schedule_id = s.schedule_id AND rv.status IN ('Paid', 'Confirmed', 'Boarded')) AS booked,
        (SELECT COUNT(*) FROM reservation rv WHERE rv.schedule_id = s.schedule_id AND rv.status = 'Boarded') AS boarded
    FROM schedule s
    JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
    JOIN buses b ON b.bus_id = s.bus_id
    JOIN routes r ON r.route_id = s.route_id
    WHERE cba.conductor_id = ? AND s.schedule_status = 'Active'
      AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed')
      AND s.departure_time >= ? AND s.departure_time < ?
    ORDER BY (s.trip_status = 'Departed') DESC, (s.trip_status = 'Boarding') DESC, s.departure_time ASC
    LIMIT 5", [$conductorId, $bounds['today'], $bounds['tomorrow']]);
while ($tripQuery !== false && ($trip = mbus_db_fetch_assoc($tripQuery))) $trips[] = $trip;
$nextTrip = $trips[0] ?? null;
$showingUpcoming = false;
if (!$trips && $assignedBuses > 0) {
    $upcomingQuery = mbus_db_prepare($conn, "SELECT s.schedule_id, s.departure_time, s.arrival_time, s.trip_status,
            b.bus_number, b.capacity, r.origin, r.destination,
            (SELECT COUNT(*) FROM reservation rv WHERE rv.schedule_id = s.schedule_id AND rv.status IN ('Paid', 'Confirmed', 'Boarded')) AS booked,
            (SELECT COUNT(*) FROM reservation rv WHERE rv.schedule_id = s.schedule_id AND rv.status = 'Boarded') AS boarded
        FROM schedule s
        JOIN conductor_bus_assignments cba ON cba.bus_id = s.bus_id
        JOIN buses b ON b.bus_id = s.bus_id
        JOIN routes r ON r.route_id = s.route_id
        WHERE cba.conductor_id = ? AND s.schedule_status = 'Active'
          AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed')
          AND s.departure_time >= ?
        ORDER BY s.departure_time ASC LIMIT 5", [$conductorId, $bounds['now']]);
    while ($upcomingQuery !== false && ($upcomingTrip = mbus_db_fetch_assoc($upcomingQuery))) $trips[] = $upcomingTrip;
    $nextTrip = $trips[0] ?? null;
    $showingUpcoming = (bool)$trips;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Conductor Dashboard — MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_home.css">
    <link rel="stylesheet" href="../Assets/css/staff_home.css">
    <link rel="stylesheet" href="../Assets/css/conductor_dashboard.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/conductor_dashboard.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>
<body class="stf has-sidebar has-bottom-nav staff-home conductor-home">
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Conductor navigation">
        <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
        <h2>Conductor</h2>
        <nav class="menu-top" aria-label="Conductor menu">
            <a href="conductor_dashboard.php" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
            <a href="../Operator/manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
            <a href="../Operator/scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
            <a href="../Operator/passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Riders</a>
            <a href="../Operator/waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>
            <a href="../Operator/announcements.php"><i class="fa fa-bullhorn" aria-hidden="true"></i> Updates</a>
        </nav>
        <div class="menu-bottom">
            <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
        </div>
    </aside>

    <main class="main" role="main"><div class="home-wrap">
        <header class="home-top">
            <div>
                <p class="home-hello"><?php echo htmlspecialchars($greeting); ?></p>
                <h1><?php echo htmlspecialchars($firstName); ?></h1>
            </div>
            <div class="page-header-right">
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo mbus_header_avatar('../'); ?></span>
                        <span class="profile-trigger-label"><?php echo htmlspecialchars($fullName); ?></span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($fullName); ?></span>
                            <span class="profile-menu-role">Conductor account</span>
                        </div>
                        <a href="../Operator/payments.php" role="menuitem"><i class="fa fa-wallet" aria-hidden="true"></i> GCash Payments<?php if ($paymentCount > 0): ?><span class="menu-badge"><?php echo $paymentCount > 99 ? '99+' : $paymentCount; ?></span><?php endif; ?></a>
                        <a href="../Operator/reports.php" role="menuitem"><i class="fa fa-chart-line" aria-hidden="true"></i> Analytics</a>
                        <a href="../Operator/profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../Operator/announcements.php" role="menuitem"><i class="fa fa-bullhorn" aria-hidden="true"></i> Updates</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <section class="cond-highlight" aria-labelledby="condHighlightTitle">
            <?php if ($nextTrip): ?>
                <div>
                    <p><?php echo $nextTrip['trip_status'] === 'Departed' ? 'Trip in progress' : ($nextTrip['trip_status'] === 'Boarding' ? 'Boarding now' : ($showingUpcoming ? 'Next scheduled trip' : 'Next trip today')); ?></p>
                    <h2 id="condHighlightTitle"><?php echo htmlspecialchars($nextTrip['origin'] . ' to ' . $nextTrip['destination']); ?></h2>
                    <small><?php echo htmlspecialchars('Bus ' . $nextTrip['bus_number']); ?> · <?php echo date('g:i A', strtotime($nextTrip['departure_time'])); ?> departure</small>
                </div>
                <span class="cond-highlight-icon" aria-hidden="true"><i class="fa fa-bus"></i></span>
            <?php else: ?>
                <div>
                    <p>Your duty board</p>
                    <h2 id="condHighlightTitle">No trips assigned today</h2>
                    <small><?php echo $assignedBuses > 0 ? 'Assigned bus' . ($assignedBuses === 1 ? '' : 'es') . ': ' . htmlspecialchars(implode(', ', $assignedBusLabels)) . '.' : 'Ask an administrator to assign a bus to your account.'; ?></small>
                </div>
                <span class="cond-highlight-icon" aria-hidden="true"><i class="fa fa-clipboard-check"></i></span>
            <?php endif; ?>
        </section>

        <?php if ($paymentCount > 0): ?>
        <a class="attn cond-attn" href="../Operator/payments.php">
            <span><strong><?php echo $paymentCount; ?> GCash <?php echo $paymentCount === 1 ? 'payment' : 'payments'; ?> to review</strong><small>Only submissions for your assigned buses are included.</small></span>
            <span class="attn-go">Review</span>
        </a>
        <?php endif; ?>

        <div class="today-strip" aria-label="Today's operations">
            <div><b><?php echo $stats['trips']; ?></b><span>Trips today</span></div>
            <div><b><?php echo $stats['booked']; ?></b><span>Booked riders</span></div>
            <div><b><?php echo $stats['boarded']; ?></b><span>Boarded</span></div>
            <div><b><?php echo $paymentCount; ?></b><span>GCash to review</span></div>
        </div>

        <a class="attn<?php echo $waitingCount ? '' : ' is-clear'; ?>" href="../Operator/waiting.php">
            <span><strong><?php echo $waitingCount ? $waitingCount . ' commuter' . ($waitingCount === 1 ? '' : 's') . ' waiting' : 'No commuters waiting'; ?></strong><small><?php echo $waitingCount ? 'Open Waiting to see pickup locations for your assigned buses.' : 'Your assigned-bus Waiting board is clear.'; ?></small></span>
            <span class="attn-go">Open Waiting</span>
        </a>

        <section class="home-section" aria-labelledby="todayTripsHeading">
            <div class="home-section-head"><h2 id="todayTripsHeading"><?php echo $showingUpcoming ? 'Upcoming trips' : 'Trips today'; ?></h2><a href="../Operator/manage_trips.php">All trips</a></div>
            <?php if ($trips): ?>
            <ul class="soon-list">
                <?php foreach ($trips as $trip):
                    $busLabel = ctype_digit((string)$trip['bus_number']) ? 'Bus ' . $trip['bus_number'] : $trip['bus_number'];
                ?>
                <li><a href="../Operator/manage_trips.php?open_schedule=<?php echo (int)$trip['schedule_id']; ?>">
                    <span class="soon-time"><?php echo date('g:i', strtotime($trip['departure_time'])); ?><small><?php echo date('A', strtotime($trip['departure_time'])); ?></small></span>
                    <span class="soon-body"><strong><?php echo htmlspecialchars($trip['origin'] . ' to ' . $trip['destination']); ?></strong><small><?php echo htmlspecialchars($busLabel . ' · ' . $trip['trip_status'] . ' · ' . (int)$trip['boarded'] . ' of ' . (int)$trip['booked'] . ' boarded'); ?></small></span>
                    <span class="seat-chip ok"><?php echo (int)$trip['booked']; ?>/<?php echo (int)$trip['capacity']; ?></span>
                </a></li>
                <?php endforeach; ?>
            </ul>
            <?php elseif ($assignedBuses > 0): ?>
            <div class="cond-unassigned"><strong>No trips scheduled today.</strong>Check Manage Trips for the next departure assigned to your bus.</div>
            <?php else: ?>
            <div class="cond-unassigned"><strong>No bus assigned yet.</strong>Your trip, ticket, waiting, and payment tools will be ready when an administrator assigns a bus.</div>
            <?php endif; ?>
        </section>
    </div></main>

    <nav class="bottom-nav" role="navigation" aria-label="Main navigation">
        <a href="conductor_dashboard.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
        <a href="../Operator/manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
        <a href="../Operator/scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
        <a href="../Operator/passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
        <a href="../Operator/waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
        <a href="../Operator/announcements.php" class="bottom-nav-item"><i class="fa fa-bullhorn" aria-hidden="true"></i><span>Updates</span></a>
    </nav>
    <script src="../Assets/js/app-header.js"></script>
    <script>window.MBUS_NOTIFICATIONS_ENDPOINT = '../Operator/notifications.php';</script>
    <script src="../Assets/js/notifications.js"></script>
</body>
</html>
