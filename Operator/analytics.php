<?php
session_start();
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../Includes/staff_assignment_scope.php';
require_once __DIR__ . '/../Includes/header_avatar.php';

$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['driver', 'conductor'], true)) {
    header('Location: ../login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$isConductor = $role === 'conductor';
$staffLabel = $isConductor ? 'Conductor' : 'Driver';
$name = htmlspecialchars((string)($_SESSION['full_name'] ?? $staffLabel));
$homeUrl = $isConductor ? '../Conductor/conductor_dashboard.php' : 'operator_dashboard.php';

function mbus_staff_rows($conn, string $sql, array $params): array
{
    $q = mbus_db_prepare($conn, $sql, $params);
    $rows = [];
    while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
    return $rows;
}

$b = mbus_local_bounds();
$from = (new DateTimeImmutable($b['today']))->modify('-29 days')->format('Y-m-d H:i:s');
$assignment = mbus_staff_assignment_condition($role, 's.bus_id');

/* One row per trip on this person's buses in the last 30 days. Everything below is totalled from it. */
$trips = mbus_staff_rows($conn, "
SELECT s.schedule_id, s.departure_time, s.trip_status, b.bus_number, b.capacity,
       rt.origin, rt.destination,
       (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status IN ('Paid','Confirmed','Boarded')) AS booked,
       (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status = 'Boarded') AS boarded,
       (SELECT COUNT(*) FROM reservation r WHERE r.schedule_id = s.schedule_id AND r.status = 'Cancelled') AS cancelled,
       (SELECT COALESCE(SUM(p.amount), 0) FROM payment p JOIN reservation r ON r.reservation_id = p.reservation_id
         WHERE r.schedule_id = s.schedule_id AND p.payment_status = 'Paid') AS revenue
FROM schedule s
JOIN buses b ON b.bus_id = s.bus_id
JOIN routes rt ON rt.route_id = s.route_id
WHERE $assignment AND s.schedule_status <> 'Cancelled'
  AND s.departure_time >= ? AND s.departure_time < ?
ORDER BY s.departure_time DESC", [$userId, $from, $b['tomorrow']]);

$t = ['trips' => 0, 'booked' => 0, 'boarded' => 0, 'cancelled' => 0, 'seats' => 0, 'revenue' => 0.0, 'noshow' => 0, 'today' => 0, 'done' => 0];
$byDay = [];
for ($i = 6; $i >= 0; $i--) $byDay[(new DateTimeImmutable($b['today']))->modify("-$i days")->format('Y-m-d')] = 0;
$byRoute = [];
$byStatus = [];
foreach ($trips as $tr) {
    $booked = (int)$tr['booked']; $boarded = (int)$tr['boarded'];
    $dep = (string)$tr['departure_time'];
    $t['trips']++; $t['booked'] += $booked; $t['boarded'] += $boarded;
    $t['cancelled'] += (int)$tr['cancelled']; $t['seats'] += (int)$tr['capacity']; $t['revenue'] += (float)$tr['revenue'];
    if ($dep >= $b['today'] && $dep < $b['tomorrow']) $t['today']++;
    $past = $dep < $b['now'] && in_array($tr['trip_status'], ['Departed', 'Completed'], true);
    if ($past) { $t['done']++; $t['noshow'] += max(0, $booked - $boarded); }
    $day = substr($dep, 0, 10);
    if (isset($byDay[$day])) $byDay[$day] += $isConductor ? $boarded : $booked;
    $label = $tr['origin'] . ' to ' . $tr['destination'];
    $byRoute[$label] = ($byRoute[$label] ?? 0) + ($isConductor ? $boarded : $booked);
    $st = (string)($tr['trip_status'] ?: 'Scheduled');
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
}
arsort($byRoute); $byRoute = array_slice($byRoute, 0, 5, true);
ksort($byStatus);
$load = $t['seats'] > 0 ? round($t['booked'] / $t['seats'] * 100) : 0;
$boardRate = $t['booked'] > 0 ? round($t['boarded'] / $t['booked'] * 100) : 0;
$avgFare = $t['booked'] > 0 ? $t['revenue'] / $t['booked'] : 0;

$allTimeRevenue = 0.0; $reviewCount = 0; $waiting = 0; $pickedUp = 0; $myBuses = 0;
if (!$isConductor) {
    $r = mbus_staff_rows($conn, "SELECT COALESCE(SUM(p.amount),0) AS n FROM payment p
        JOIN reservation r ON r.reservation_id = p.reservation_id JOIN schedule s ON s.schedule_id = r.schedule_id
        WHERE p.payment_status = 'Paid' AND $assignment", [$userId]);
    $allTimeRevenue = (float)($r[0]['n'] ?? 0);
    $r = mbus_staff_rows($conn, "SELECT COUNT(*) AS n FROM operator_bus_assignments WHERE operator_id = ?", [$userId]);
    $myBuses = (int)($r[0]['n'] ?? 0);
} else {
    $r = mbus_staff_rows($conn, "SELECT COUNT(*) AS n FROM paymongo_checkout pc JOIN schedule s ON s.schedule_id = pc.schedule_id
        WHERE $assignment AND pc.session_id LIKE 'manual\\_%' AND LOWER(pc.status) = 'submitted'", [$userId]);
    $reviewCount = (int)($r[0]['n'] ?? 0);
    $r = mbus_staff_rows($conn, "SELECT COUNT(*) AS n FROM pickup_requests pr JOIN schedule s ON s.schedule_id = pr.schedule_id
        WHERE $assignment AND pr.status = 'Waiting' AND pr.expires_at > NOW()", [$userId]);
    $waiting = (int)($r[0]['n'] ?? 0);
    $r = mbus_staff_rows($conn, "SELECT COUNT(*) AS n FROM pickup_requests pr JOIN schedule s ON s.schedule_id = pr.schedule_id
        WHERE $assignment AND pr.status = 'Picked up' AND pr.updated_at >= ?", [$userId, $from]);
    $pickedUp = (int)($r[0]['n'] ?? 0);
}

function mbus_bars(array $data, bool $dayLabels = false): void
{
    $max = max(1, $data ? max($data) : 1);
    echo '<div class="bar-chart">';
    foreach ($data as $label => $n) {
        $l = $dayLabels ? date('D j', strtotime((string)$label)) : (string)$label;
        echo '<div class="bar-item"><div class="bar-label">' . htmlspecialchars($l) . '</div><div class="bar"><div class="bar-fill" style="width:' . round($n / $max * 100) . '%"></div></div><div class="bar-value">' . (int)$n . '</div></div>';
    }
    echo '</div>';
}
$noData = $t['trips'] === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?php echo $staffLabel; ?></title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/admin/analytics.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
</head>
<body class="stf has-bottom-nav">
<main class="main" role="main">
<header class="page-header" role="banner">
    <div class="page-header-left"><h1>Analytics</h1></div>
    <div class="page-header-right"><div class="profile-menu-wrap">
        <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php echo mbus_header_avatar('../'); ?></span></button>
        <div class="profile-menu" id="profileMenu" role="menu">
            <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role"><?php echo $staffLabel; ?> account</span></div>
            <a href="analytics.php" role="menuitem"><i class="fa fa-chart-line" aria-hidden="true"></i> Analytics</a>
            <a href="reports.php" role="menuitem"><i class="fa fa-file-lines" aria-hidden="true"></i> Reports</a>
            <?php if ($isConductor): ?><a href="payments.php" role="menuitem"><i class="fa fa-wallet" aria-hidden="true"></i> GCash Payments</a><?php else: ?><a href="my_bus.php" role="menuitem"><i class="fa fa-bus-simple" aria-hidden="true"></i> My Bus</a><?php endif; ?>
            <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
            <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
        </div>
    </div></div>
</header>

<div class="dashboard-content">
<p class="date" style="margin:0 0 14px">Last 30 days on <?php echo $isConductor ? 'the buses you are assigned to' : 'your bus'; ?>.</p>
<section class="cards" aria-label="Key numbers">
<?php if ($isConductor): ?>
    <article class="card"><h3 class="day"><?php echo $boardRate; ?>%</h3><p class="date">Boarding rate</p><p><?php echo number_format($t['boarded']); ?> boarded of <?php echo number_format($t['booked']); ?> booked</p></article>
    <article class="card"><h3 class="day"><?php echo number_format($t['noshow']); ?></h3><p class="date">No-shows</p><p>Booked riders who never boarded a finished trip</p></article>
    <article class="card"><h3 class="day"><?php echo number_format($reviewCount); ?></h3><p class="date">GCash to review</p><p><a href="payments.php">Open GCash payments</a></p></article>
    <article class="card"><h3 class="day"><?php echo number_format($waiting); ?></h3><p class="date">Riders waiting now</p><p><?php echo number_format($pickedUp); ?> picked up in 30 days</p></article>
    <article class="card"><h3 class="day"><?php echo number_format($t['trips']); ?></h3><p class="date">Trips covered</p><p><?php echo number_format($t['today']); ?> today</p></article>
<?php else: ?>
    <article class="card"><h3 class="day">₱<?php echo number_format($t['revenue'], 2); ?></h3><p class="date">Fares collected</p><p>All time: ₱<?php echo number_format($allTimeRevenue, 2); ?></p></article>
    <article class="card"><h3 class="day"><?php echo number_format($t['trips']); ?></h3><p class="date">Trips</p><p><?php echo number_format($t['today']); ?> today, <?php echo number_format($t['done']); ?> finished</p></article>
    <article class="card"><h3 class="day"><?php echo number_format($t['boarded']); ?></h3><p class="date">Riders carried</p><p><?php echo number_format($t['booked']); ?> booked seats</p></article>
    <article class="card"><h3 class="day"><?php echo $load; ?>%</h3><p class="date">Average seat load</p><p><?php echo number_format($t['booked']); ?> of <?php echo number_format($t['seats']); ?> seats offered</p></article>
    <article class="card"><h3 class="day">₱<?php echo number_format($avgFare, 2); ?></h3><p class="date">Average fare per seat</p><p><?php echo number_format($t['cancelled']); ?> cancelled bookings</p></article>
<?php endif; ?>
</section>

<?php if ($noData): ?>
<section class="chart-card card"><p class="empty-state">No trips on your <?php echo $isConductor ? 'assigned buses' : 'bus'; ?> in the last 30 days yet. Numbers appear here after your first scheduled trip.</p></section>
<?php else: ?>
<section class="chart-card card" style="margin-bottom:var(--space-6)"><h2><?php echo $isConductor ? 'Riders boarded, last 7 days' : 'Seats booked, last 7 days'; ?></h2><div class="chart-container"><?php mbus_bars($byDay, true); ?></div></section>
<section class="chart-card card" style="margin-bottom:var(--space-6)"><h2><?php echo $isConductor ? 'Boarded by route' : 'Busiest routes'; ?></h2><div class="chart-container"><?php mbus_bars($byRoute); ?></div></section>
<section class="chart-card card" style="margin-bottom:var(--space-6)"><h2>Trips by status</h2><div class="chart-container"><?php mbus_bars($byStatus); ?></div></section>
<?php endif; ?>
</div>
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="<?php echo $homeUrl; ?>" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <?php if ($isConductor): ?>
    <a href="manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
    <?php else: ?>
    <a href="my_bus.php" class="bottom-nav-item"><i class="fa fa-bus-simple" aria-hidden="true"></i><span>My Bus</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
    <?php endif; ?>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
