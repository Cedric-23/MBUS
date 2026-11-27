<?php
session_start();
require_once __DIR__ . '/../Includes/staff_nav.php';
require_once __DIR__ . '/../Includes/csrf.php';
include "../config/db_connect.php";
require_once __DIR__ . '/../Includes/staff_assignment_scope.php';

$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['driver', 'conductor'], true)) {
    header("Location: ../login.php");
    exit();
}
$userId = (int)$_SESSION['user_id'];
$isConductor = $role === 'conductor';
$staffLabel = $isConductor ? 'Conductor' : 'Driver';
$homeUrl = $isConductor ? '../Conductor/conductor_dashboard.php' : 'operator_dashboard.php';
$assignment = mbus_staff_assignment_condition($role, 's.bus_id');

/* ---------- filters ---------- */
$today = date('Y-m-d');
$valid_date = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$range = (string)($_GET['range'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if ($range === 'today') { $from = $to = $today; }
elseif ($range === '7d') { $from = date('Y-m-d', strtotime('-6 days')); $to = $today; }
elseif ($range === '30d') { $from = date('Y-m-d', strtotime('-29 days')); $to = $today; }
elseif ($range === 'month') { $from = date('Y-m-01'); $to = $today; }
elseif ($range === 'all') { $from = $to = ''; }
elseif (!isset($_GET['from']) && !isset($_GET['to'])) { $from = date('Y-m-d', strtotime('-29 days')); $to = $today; $range = '30d'; }
if (!$valid_date($from)) $from = '';
if (!$valid_date($to)) $to = '';
$bus_id = (int)($_GET['bus_id'] ?? 0);
$route_id = (int)($_GET['route_id'] ?? 0);
$trip_status = (string)($_GET['trip_status'] ?? '');
if (!in_array($trip_status, ['Scheduled', 'Boarding', 'Departed', 'Arrived', 'Completed', 'Cancelled'], true)) $trip_status = '';
$res_status = (string)($_GET['res_status'] ?? '');
if (!in_array($res_status, ['Pending', 'Paid', 'Confirmed', 'Boarded', 'Cancelled'], true)) $res_status = '';
$report = (string)($_GET['report'] ?? 'trips');
$reports = ['trips' => 'Trip history', 'buses' => 'By bus', 'daily' => 'By day', 'passengers' => $isConductor ? 'Boarding list' : 'Passengers'];
if ($isConductor) $reports['gcash'] = 'GCash payments';
if (!isset($reports[$report])) $report = 'trips';
$export = isset($_GET['export']);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;

$where = [$assignment]; $params = [$userId]; /* only this person's own buses */
if ($from !== '') { $where[] = 's.departure_time >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 's.departure_time < ?'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
if ($bus_id) { $where[] = 's.bus_id = ?'; $params[] = $bus_id; }
if ($route_id) { $where[] = 's.route_id = ?'; $params[] = $route_id; }
if ($trip_status !== '') { $where[] = 's.trip_status = ?'; $params[] = $trip_status; }
$W = implode(' AND ', $where);

function rep_rows($conn, string $sql, array $p): array {
    $q = mbus_db_prepare($conn, $sql, $p);
    $out = [];
    while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $out[] = $r;
    return $out;
}

/* per-schedule aggregate (one row per trip) used by trips / buses / daily / KPIs */
$tripSql = "SELECT s.schedule_id, s.departure_time, s.arrival_time, s.trip_status, b.bus_id, b.bus_number, b.bus_type, b.capacity,
        ro.origin, ro.destination,
        COALESCE(x.booked,0) AS booked, COALESCE(x.boarded,0) AS boarded, COALESCE(x.cancelled,0) AS cancelled, COALESCE(x.revenue,0) AS revenue
    FROM schedule s
    JOIN buses b ON b.bus_id = s.bus_id
    LEFT JOIN routes ro ON ro.route_id = s.route_id
    LEFT JOIN (
        SELECT r.schedule_id,
            COUNT(CASE WHEN r.status::text IN ('Paid','Confirmed','Boarded') THEN 1 END) AS booked,
            COUNT(CASE WHEN r.status::text = 'Boarded' THEN 1 END) AS boarded,
            COUNT(CASE WHEN r.status::text = 'Cancelled' THEN 1 END) AS cancelled,
            SUM(CASE WHEN p.payment_status::text = 'Paid' THEN p.amount ELSE 0 END) AS revenue
        FROM reservation r LEFT JOIN payment p ON p.reservation_id = r.reservation_id
        GROUP BY r.schedule_id
    ) x ON x.schedule_id = s.schedule_id
    WHERE $W";
$trips = rep_rows($conn, $tripSql . " ORDER BY s.departure_time DESC", $params);

$kpi = ['trips' => count($trips), 'buses' => [], 'booked' => 0, 'boarded' => 0, 'revenue' => 0.0, 'cap' => 0, 'noshow' => 0];
$nowStr = date('Y-m-d H:i:s');
foreach ($trips as $t) {
    $kpi['buses'][$t['bus_id']] = 1; $kpi['booked'] += (int)$t['booked']; $kpi['boarded'] += (int)$t['boarded'];
    $kpi['revenue'] += (float)$t['revenue']; $kpi['cap'] += (int)$t['capacity'];
    if ($t['departure_time'] < $nowStr && in_array($t['trip_status'], ['Departed', 'Arrived', 'Completed'], true)) $kpi['noshow'] += max(0, (int)$t['booked'] - (int)$t['boarded']);
}
$kpi['occ'] = $kpi['cap'] > 0 ? round(100 * $kpi['booked'] / $kpi['cap']) : 0;

/* ---------- build the selected report ---------- */
$title = $reports[$report];
$head = []; $rows = [];
$pct = static fn($a, $b) => $b > 0 ? round(100 * $a / $b) . '%' : '0%';
if ($report === 'trips') {
    $head = ['Date', 'Departure', 'Arrival', 'Bus', 'Type', 'Route', 'Trip status', 'Seats', 'Booked', 'Boarded', 'Cancelled', 'Occupancy', 'Revenue (PHP)'];
    foreach ($trips as $t) {
        $rows[] = [date('Y-m-d', strtotime($t['departure_time'])), date('h:i A', strtotime($t['departure_time'])), date('h:i A', strtotime($t['arrival_time'])),
            $t['bus_number'], $t['bus_type'], trim(($t['origin'] ?? '') . ' - ' . ($t['destination'] ?? ''), ' -'), $t['trip_status'], (int)$t['capacity'],
            (int)$t['booked'], (int)$t['boarded'], (int)$t['cancelled'], $pct((int)$t['booked'], (int)$t['capacity']), number_format((float)$t['revenue'], 2, '.', '')];
    }
} elseif ($report === 'buses') {
    $head = ['Bus', 'Type', 'Seats', 'Trips', 'Passengers booked', 'Boarded', 'Avg occupancy', 'Revenue (PHP)'];
    $agg = [];
    foreach ($trips as $t) {
        $k = $t['bus_id'];
        $agg[$k] ??= ['no' => $t['bus_number'], 'type' => $t['bus_type'], 'cap' => (int)$t['capacity'], 'trips' => 0, 'booked' => 0, 'boarded' => 0, 'rev' => 0.0, 'seatsum' => 0];
        $agg[$k]['trips']++; $agg[$k]['booked'] += (int)$t['booked']; $agg[$k]['boarded'] += (int)$t['boarded']; $agg[$k]['rev'] += (float)$t['revenue']; $agg[$k]['seatsum'] += (int)$t['capacity'];
    }
    usort($agg, fn($a, $b) => $b['booked'] <=> $a['booked']);
    foreach ($agg as $a) $rows[] = [$a['no'], $a['type'], $a['cap'], $a['trips'], $a['booked'], $a['boarded'], $pct($a['booked'], $a['seatsum']), number_format($a['rev'], 2, '.', '')];
} elseif ($report === 'daily') {
    $head = ['Date', 'Trips', 'Buses running', 'Passengers booked', 'Boarded', 'Occupancy', 'Revenue (PHP)'];
    $agg = [];
    foreach ($trips as $t) {
        $d = date('Y-m-d', strtotime($t['departure_time']));
        $agg[$d] ??= ['trips' => 0, 'buses' => [], 'booked' => 0, 'boarded' => 0, 'rev' => 0.0, 'cap' => 0];
        $agg[$d]['trips']++; $agg[$d]['buses'][$t['bus_id']] = 1; $agg[$d]['booked'] += (int)$t['booked']; $agg[$d]['boarded'] += (int)$t['boarded'];
        $agg[$d]['rev'] += (float)$t['revenue']; $agg[$d]['cap'] += (int)$t['capacity'];
    }
    krsort($agg);
    foreach ($agg as $d => $a) $rows[] = [$d, $a['trips'], count($a['buses']), $a['booked'], $a['boarded'], $pct($a['booked'], $a['cap']), number_format($a['rev'], 2, '.', '')];
} elseif ($report === 'gcash') {
    $head = ['Submitted', 'Commuter', 'Bus', 'Route', 'Trip date', 'Amount', 'Reference', 'Status', 'Reviewed'];
    $list = rep_rows($conn, "SELECT COALESCE(pc.submitted_at, pc.created_at) AS sub_at, u.full_name, b.bus_number, ro.origin, ro.destination, s.departure_time,
            pc.amount, pc.reference_number, pc.status, pc.reviewed_at
        FROM paymongo_checkout pc JOIN schedule s ON s.schedule_id = pc.schedule_id JOIN buses b ON b.bus_id = s.bus_id
        LEFT JOIN routes ro ON ro.route_id = s.route_id LEFT JOIN users u ON u.user_id = pc.user_id
        WHERE $W AND pc.session_id LIKE 'manual\\_%' ORDER BY COALESCE(pc.submitted_at, pc.created_at) DESC", $params);
    foreach ($list as $r) $rows[] = [date('Y-m-d h:i A', strtotime($r['sub_at'])), $r['full_name'], $r['bus_number'], trim(($r['origin'] ?? '') . ' - ' . ($r['destination'] ?? ''), ' -'),
        date('Y-m-d h:i A', strtotime($r['departure_time'])), number_format((float)$r['amount'], 2, '.', ''), $r['reference_number'] ?? '-', $r['status'], $r['reviewed_at'] ? date('Y-m-d h:i A', strtotime($r['reviewed_at'])) : '-'];
} else {
    $head = ['Reserved on', 'Ticket', 'Commuter', 'Bus', 'Route', 'Trip date', 'Seat', 'Reservation status', 'Payment', 'Amount (PHP)'];
    $pw = $W; $pp = $params;
    if ($res_status !== '') { $pw .= ' AND r.status::text = ?'; $pp[] = $res_status; }
    $list = rep_rows($conn, "SELECT r.reservation_date, r.ticket_code, u.full_name, b.bus_number, ro.origin, ro.destination, s.departure_time, r.seat_number, r.status::text AS rstatus,
            p.payment_status::text AS pstatus, p.amount
        FROM reservation r JOIN schedule s ON s.schedule_id = r.schedule_id JOIN buses b ON b.bus_id = s.bus_id
        LEFT JOIN routes ro ON ro.route_id = s.route_id LEFT JOIN users u ON u.user_id = r.user_id
        LEFT JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE $pw ORDER BY s.departure_time DESC, r.reservation_id DESC", $pp);
    foreach ($list as $r) $rows[] = [date('Y-m-d h:i A', strtotime($r['reservation_date'])), $r['ticket_code'], $r['full_name'], $r['bus_number'],
        trim(($r['origin'] ?? '') . ' - ' . ($r['destination'] ?? ''), ' -'), date('Y-m-d h:i A', strtotime($r['departure_time'])), $r['seat_number'],
        $r['rstatus'], $r['pstatus'] ?? '-', $r['amount'] !== null ? number_format((float)$r['amount'], 2, '.', '') : ''];
}

/* Conductors do not handle fares, so revenue columns are left out of their reports. */
if ($isConductor) {
    $drop = array_keys(array_filter($head, fn($c) => str_contains($c, '(PHP)')));
    if ($drop) {
        $head = array_values(array_diff_key($head, array_flip($drop)));
        $rows = array_map(fn($r) => array_values(array_diff_key($r, array_flip($drop))), $rows);
    }
}

/* ---------- Excel / PDF export ---------- */
if ($export) {
    require_once __DIR__ . '/../Includes/report_export.php';
    $meta = ['Prepared for' => $staffLabel . ' - ' . ($_SESSION['full_name'] ?? ''), 'From' => $from ?: 'All dates', 'To' => $to ?: 'All dates'];
    $summary = ['Trips' => $kpi['trips'], 'Buses used' => count($kpi['buses']), 'Passengers booked' => $kpi['booked'], 'Boarded' => $kpi['boarded'], 'Seat occupancy' => $kpi['occ'] . '%'];
    if ($isConductor) $summary['No-shows'] = $kpi['noshow']; else $summary['Fares collected (PHP)'] = number_format($kpi['revenue'], 2);
    mbus_report_send(mbus_report_format(), 'mbus_' . strtolower($staffLabel) . '_' . $report . '_' . ($from ?: 'start') . '_to_' . ($to ?: 'now'), $staffLabel . ' analytics - ' . $title, $meta, $summary, $head, $rows);
}

$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$shown = array_slice($rows, ($page - 1) * $per, $per);

$busList = rep_rows($conn, "SELECT b.bus_id, b.bus_number FROM buses b WHERE " . mbus_staff_assignment_condition($role, 'b.bus_id') . " ORDER BY b.bus_number", [$userId]);
$routeList = rep_rows($conn, "SELECT DISTINCT ro.route_id, ro.origin, ro.destination FROM routes ro JOIN schedule s ON s.route_id = ro.route_id WHERE $assignment ORDER BY ro.origin", [$userId]);
$qs = static function (array $over = []) use ($range, $from, $to, $bus_id, $route_id, $trip_status, $res_status, $report) {
    $q = array_merge(['report' => $report, 'range' => $range, 'from' => $from, 'to' => $to, 'bus_id' => $bus_id ?: '', 'route_id' => $route_id ?: '', 'trip_status' => $trip_status, 'res_status' => $res_status], $over);
    return http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$busLabel = static fn($n) => ctype_digit((string)$n) ? 'Bus ' . $n : $n;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?php echo $staffLabel; ?></title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/admin/reports.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/admin/reports.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
</head>
<body class="stf has-bottom-nav reports-page">
<main class="main" role="main">
<header class="page-header" role="banner">
    <div class="page-header-left"><h1>Analytics</h1></div>
    <div class="page-header-right">
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
<div class="profile-menu-wrap">
        <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span></button>
        <div class="profile-menu" id="profileMenu" role="menu">
            <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $h($_SESSION['full_name'] ?? $staffLabel); ?></span><span class="profile-menu-role"><?php echo $staffLabel; ?> account</span></div>
            <?php echo mbus_staff_profile_links($role, 'reports'); ?>
            <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
        </div>
    </div></div>
</header>

<div class="rp">
    <form method="get" class="rp-filters">
        <input type="hidden" name="report" value="<?php echo $h($report); ?>">
        <div class="rp-ranges" role="group" aria-label="Quick range">
            <?php foreach (['today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'all' => 'All time'] as $k => $l): ?>
            <a href="?<?php echo $h($qs(['range' => $k, 'from' => '', 'to' => '', 'page' => ''])); ?>"<?php echo $range === $k ? ' class="on"' : ''; ?>><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
        <?php $rpActive = $bus_id || $route_id || $trip_status !== '' || $res_status !== '' || $range === ''; ?>
        <button type="button" class="rp-toggle" id="rpToggle" aria-expanded="<?php echo $rpActive ? 'true' : 'false'; ?>" aria-controls="rpMore"><span>More filters</span><i class="fa fa-chevron-down" aria-hidden="true"></i></button>
        <div class="rp-more" id="rpMore"<?php echo $rpActive ? '' : ' hidden'; ?>>
        <div class="rp-grid">
            <label>From<input type="date" name="from" value="<?php echo $h($from); ?>"></label>
            <label>To<input type="date" name="to" value="<?php echo $h($to); ?>"></label>
            <label>Bus<select name="bus_id"><option value="">All buses</option><?php foreach ($busList as $b): ?><option value="<?php echo (int)$b['bus_id']; ?>"<?php echo $bus_id === (int)$b['bus_id'] ? ' selected' : ''; ?>><?php echo $h($busLabel($b['bus_number'])); ?></option><?php endforeach; ?></select></label>
            <label>Route<select name="route_id"><option value="">All routes</option><?php foreach ($routeList as $ro): ?><option value="<?php echo (int)$ro['route_id']; ?>"<?php echo $route_id === (int)$ro['route_id'] ? ' selected' : ''; ?>><?php echo $h($ro['origin'] . ' - ' . $ro['destination']); ?></option><?php endforeach; ?></select></label>
            <label>Trip status<select name="trip_status"><option value="">Any</option><?php foreach (['Scheduled', 'Boarding', 'Departed', 'Arrived', 'Completed', 'Cancelled'] as $st): ?><option<?php echo $trip_status === $st ? ' selected' : ''; ?>><?php echo $st; ?></option><?php endforeach; ?></select></label>
            <?php if ($report === 'passengers'): ?><label>Reservation<select name="res_status"><option value="">Any</option><?php foreach (['Pending', 'Paid', 'Confirmed', 'Boarded', 'Cancelled'] as $st): ?><option<?php echo $res_status === $st ? ' selected' : ''; ?>><?php echo $st; ?></option><?php endforeach; ?></select></label><?php endif; ?>
        </div>
        <div class="rp-actions">
            <button type="submit" class="btn btn-primary">Apply filters</button>
            <a class="btn btn-outline" href="reports.php?report=<?php echo $h($report); ?>">Reset</a>
        </div>
        </div>
    </form>

    <section class="rp-kpis" aria-label="Summary for the selected filters">
        <div><strong><?php echo number_format($kpi['trips']); ?></strong><span>Trips</span></div>
        <div><strong><?php echo number_format(count($kpi['buses'])); ?></strong><span><?php echo count($kpi['buses']) === 1 ? 'Bus used' : 'Buses used'; ?></span></div>
        <div><strong><?php echo number_format($kpi['booked']); ?></strong><span>Passengers booked</span></div>
        <div><strong><?php echo number_format($kpi['boarded']); ?></strong><span>Boarded</span></div>
        <div><strong><?php echo $kpi['occ']; ?>%</strong><span>Seat occupancy</span></div>
        <?php if ($isConductor): ?><div><strong><?php echo number_format($kpi['noshow']); ?></strong><span>No-shows</span></div><?php else: ?><div><strong>₱<?php echo number_format($kpi['revenue'], 2); ?></strong><span>Fares collected</span></div><?php endif; ?>
    </section>

    <nav class="rp-tabs" aria-label="Report type">
        <?php foreach ($reports as $k => $l): ?><a href="?<?php echo $h($qs(['report' => $k, 'page' => ''])); ?>"<?php echo $report === $k ? ' class="on" aria-current="page"' : ''; ?>><?php echo $l; ?></a><?php endforeach; ?>
    </nav>

    <section class="rp-card">
        <div class="rp-card-head">
            <div><h2><?php echo $h($title); ?></h2><p><?php echo number_format($total); ?> row<?php echo $total === 1 ? '' : 's'; ?> · <?php echo $from ? $h(date('M j, Y', strtotime($from))) : 'the beginning'; ?> to <?php echo $to ? $h(date('M j, Y', strtotime($to))) : 'now'; ?></p></div>
            <span class="rp-export"><a class="btn btn-primary" href="?<?php echo $h($qs(['export' => 1, 'format' => 'xlsx'])); ?>"><i class="fa fa-file-excel-o" aria-hidden="true"></i> Excel</a> <a class="btn btn-primary" href="?<?php echo $h($qs(['export' => 1, 'format' => 'pdf'])); ?>"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> PDF</a></span>
        </div>
        <?php if (!$shown): ?>
            <p class="rp-empty">Nothing matches these filters. Try a wider date range.</p>
        <?php else: ?>
        <div class="rp-table-wrap"><table class="rp-table">
            <thead><tr><?php foreach ($head as $c): ?><th><?php echo $h($c); ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($shown as $r): ?><tr><?php foreach ($r as $i => $c): ?><td data-label="<?php echo $h($head[$i]); ?>"><?php echo $h($c); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php if ($pages > 1): ?>
        <div class="rp-pager">
            <?php if ($page > 1): ?><a href="?<?php echo $h($qs(['page' => $page - 1])); ?>">Previous</a><?php endif; ?>
            <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
            <?php if ($page < $pages): ?><a href="?<?php echo $h($qs(['page' => $page + 1])); ?>">Next</a><?php endif; ?>
        </div>
        <?php endif; endif; ?>
    </section>
</div>
</main>

<?php echo mbus_staff_bottom_nav($role, ''); ?>
<script>
(function(){var t=document.getElementById('rpToggle'),m=document.getElementById('rpMore');if(!t||!m)return;
function set(o){t.setAttribute('aria-expanded',o?'true':'false');m.hidden=!o;t.querySelector('span').textContent=o?'Hide filters':'More filters';}
set(t.getAttribute('aria-expanded')==='true');
t.addEventListener('click',function(){set(m.hidden);});})();
</script>
<script src="../Assets/js/app-header.js"></script>
<script>window.MBUS_NOTIFICATIONS_ENDPOINT = 'notifications.php';</script>
<script src="../Assets/js/notifications.js"></script>
</body></html>
