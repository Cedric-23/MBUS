<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/activity_log.php";

if(!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator'){
    header("Location: ../login.php");
    exit();
}

$operator_id = (int)$_SESSION['user_id'];
$b = mbus_local_bounds(); // app-timezone boundaries (not the DB clock)
$notice = '';
$error = '';
require_once "../Includes/fare.php";

// Walk-in seats need two extra columns; add them once per session if the migration has not been run.
if (empty($_SESSION['walkin_schema'])) {
    mbus_db_query($conn, "ALTER TABLE reservation ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'Online'");
    mbus_db_query($conn, "ALTER TABLE reservation ADD COLUMN IF NOT EXISTS walkin_name VARCHAR(100)");
    $_SESSION['walkin_schema'] = 1;
}

$dir_in = (string)($_GET['dir'] ?? $_POST['dir'] ?? '');
$mode_in = (string)($_GET['mode'] ?? $_POST['mode'] ?? '');
function riders_url(array $q): string { return 'passengers.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0)); }
function riders_dir(string $origin): string { return stripos($origin, 'morong') !== false ? 'm' : 'h'; }

// Estimate a pick-up request: nearest stop to the commuter's pin (projected onto the route line between
// the route's two terminals), the stop they are going to, and the fare between those stops.
function riders_estimate(array $trip, $lat, $lng, string $dest_in): array
{
    $order = ['MORONG TERMINAL','BINARITAN','POBLACION','HILLTOP','SABANG','ANVAYA','MABAYO','MINANGA','MORONG GATE','GROUP 6','IDESS','APPAREL','GATE 2','TRIBOA','TOWER','KORYO','TECHNO','UA','NSD','PETRON','INDUSTRIAL','HARBOR POINT','MAIN GATE'];
    if (riders_dir((string)$trip['origin']) === 'h') $order = array_reverse($order);
    $n = count($order);
    $di = array_search(strtoupper(trim($dest_in)), $order, true);
    $di = $di === false ? $n - 1 : (int)$di;
    $oLat = (float)($trip['origin_lat'] ?? 0); $oLng = (float)($trip['origin_lng'] ?? 0);
    $dLat = (float)($trip['destination_lat'] ?? 0); $dLng = (float)($trip['destination_lng'] ?? 0);
    $t = null;
    if (is_numeric($lat) && is_numeric($lng) && $oLat && $oLng && $dLat && $dLng) {
        $k = cos(deg2rad(($oLat + $dLat) / 2));
        $vx = ($dLng - $oLng) * $k; $vy = $dLat - $oLat;
        $len = $vx * $vx + $vy * $vy;
        if ($len > 0) { $t = max(0.0, min(1.0, ((($lng - $oLng) * $k) * $vx + (($lat - $oLat)) * $vy) / $len)); }
    }
    $pi = $t === null ? 0 : (int)round($t * ($n - 1));
    if ($pi >= $di) $pi = max(0, $di - 1);
    $f = mbus_fare_table();
    $fare = round(abs(($f[$order[$di]] ?? 0) - ($f[$order[$pi]] ?? 0)), 2);
    if ($fare <= 0) $fare = max(14.00, round(75.50 * abs($di - $pi) / max(1, $n - 1), 2));
    return ['pickup' => $order[$pi], 'dest' => $order[$di], 'fare' => $fare];
}

// Confirm boarding from a rider card (reservations only)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_boarded'])){
    $back_schedule = (int)($_POST['schedule_id'] ?? 0);
    if(!csrf_validate_token()){
        $error = "Session expired. Try again.";
    } else {
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);
        $stmt = mbus_db_prepare($conn, "UPDATE reservation r SET status='Boarded'
            WHERE r.reservation_id = ?
              AND r.status IN ('Paid', 'Confirmed')
              AND r.schedule_id IN (
                  SELECT s.schedule_id FROM schedule s
                  JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
                  WHERE oba.operator_id = ? AND s.trip_status IN ('Scheduled', 'Boarding', 'Departed')
              )", [$reservation_id, $operator_id]);
        $ok = $stmt !== false && $conn->affectedRows > 0;
        $_SESSION['riders_flash'] = $ok ? 'Passenger boarded.' : 'Could not board this passenger.';
    }
    header("Location: " . riders_url(['schedule_id' => $back_schedule, 'dir' => $dir_in, 'mode' => 'res']));
    exit();
}

// Seat a walk-in or a pickup request (cash fare, boarded right away)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_walkin'])){
    $back_schedule = (int)($_POST['schedule_id'] ?? 0);
    $msg = 'Could not seat this passenger.';
    do {
        if(!csrf_validate_token()){ $msg = 'Session expired. Try again.'; break; }
        $sq = mbus_db_prepare($conn, "SELECT s.schedule_id, s.trip_status, bu.capacity, ro.origin,
                to_jsonb(ro)->>'origin_lat' AS origin_lat, to_jsonb(ro)->>'origin_lng' AS origin_lng,
                to_jsonb(ro)->>'destination_lat' AS destination_lat, to_jsonb(ro)->>'destination_lng' AS destination_lng
            FROM schedule s
            JOIN buses bu ON bu.bus_id = s.bus_id JOIN routes ro ON ro.route_id = s.route_id
            WHERE s.schedule_id = ? AND s.bus_id IN (SELECT bus_id FROM operator_bus_assignments WHERE operator_id = ?)", [$back_schedule, $operator_id]);
        $sc = $sq === false ? false : mbus_db_fetch_assoc($sq);
        if(!$sc){ $msg = 'Trip not found.'; break; }
        if(!in_array($sc['trip_status'], ['Scheduled', 'Boarding', 'Departed'], true)){ $msg = 'This trip is closed.'; break; }
        $seat = (int)($_POST['seat'] ?? 0);
        if($seat < 1 || $seat > (int)$sc['capacity']){ $msg = 'Pick a valid seat.'; break; }
        $taken = mbus_db_prepare($conn, "SELECT 1 FROM reservation WHERE schedule_id = ? AND seat_number = ?
            AND (status IN ('Paid','Confirmed','Boarded') OR (status = 'Pending' AND created_at > NOW() - INTERVAL '10 minutes'))", [$back_schedule, (string)$seat]);
        if($taken !== false && mbus_db_num_rows($taken) > 0){ $msg = "Seat $seat is already taken."; break; }

        $fares = mbus_fare_table();
        $uid = null; $source = 'Walk-in'; $wname = trim((string)($_POST['name'] ?? ''));
        $wname = $wname !== '' ? mb_substr($wname, 0, 100) : 'Walk-in passenger';
        $req_id = (int)($_POST['request_id'] ?? 0);
        if($req_id > 0){
            // Pick-up: stops and fare come from the commuter's pin and chosen destination, not from the form.
            $rq = mbus_db_prepare($conn, "SELECT p.request_id, p.user_id, p.latitude, p.longitude, p.destination, u.full_name FROM pickup_requests p JOIN users u ON u.user_id = p.user_id
                WHERE p.request_id = ? AND p.schedule_id = ? AND p.status = 'Picked up'
                AND NOT EXISTS (SELECT 1 FROM reservation rv WHERE rv.user_id = p.user_id AND rv.schedule_id = p.schedule_id AND rv.source = 'Pickup' AND rv.status IN ('Paid','Confirmed','Boarded'))", [$req_id, $back_schedule]);
            $rrow = $rq === false ? false : mbus_db_fetch_assoc($rq);
            if(!$rrow){ $msg = 'That pick-up is already seated or no longer available.'; break; }
            $uid = (int)$rrow['user_id']; $wname = $rrow['full_name']; $source = 'Pickup';
            $est = riders_estimate($sc, $rrow['latitude'], $rrow['longitude'], (string)$rrow['destination']);
            $pickup = $est['pickup']; $dest = $est['dest']; $fare = $est['fare'];
        } else {
            $pickup = strtoupper(trim((string)($_POST['pickup'] ?? '')));
            $dest = strtoupper(trim((string)($_POST['destination'] ?? '')));
            if(!isset($fares[$pickup], $fares[$dest]) || $pickup === $dest){ $msg = 'Choose a pickup and a different drop-off stop.'; break; }
            $fare = round(abs($fares[$dest] - $fares[$pickup]), 2);
            if($fare <= 0){ $msg = 'Those stops have no fare between them.'; break; }
        }

        $code = random_int(1000, 9999);
        for($i = 0; $i < 20; $i++){
            $c = mbus_db_prepare($conn, "SELECT 1 FROM reservation WHERE ticket_code = ?", [$code]);
            if($c === false || mbus_db_num_rows($c) === 0) break;
            $code = random_int(1000, 9999);
        }
        $ins = mbus_db_prepare($conn, "INSERT INTO reservation(ticket_code,user_id,schedule_id,seat_number,pickup_location,destination,reservation_date,created_at,status,source,walkin_name)
            VALUES (?,?,?,?,?,?,NOW(),NOW(),'Boarded',?,?)", [$code, $uid, $back_schedule, (string)$seat, $pickup, $dest, $source, $wname]);
        if($ins === false){ $msg = "Seat $seat is already taken."; break; }
        $rid = mbus_db_prepare($conn, "SELECT reservation_id FROM reservation WHERE schedule_id = ? AND seat_number = ? AND ticket_code = ? ORDER BY reservation_id DESC LIMIT 1", [$back_schedule, (string)$seat, $code]);
        $rrow2 = $rid === false ? false : mbus_db_fetch_assoc($rid);
        if($rrow2){
            mbus_db_prepare($conn, "INSERT INTO payment(reservation_id,amount,payment_method,payment_reference,payment_status,ticket_code) VALUES (?,?,'Cash',?,'Paid',?)",
                [(int)$rrow2['reservation_id'], $fare, 'CASH-' . $code, (string)$code]);
        }
        if($req_id > 0){
            
        }
        log_activity($conn, $operator_id, 'Walk-in seated', "Schedule $back_schedule, seat $seat, $source, fare $fare");
        $msg = "Seat $seat confirmed. Collect ₱" . number_format($fare, 2) . " cash.";
    } while(false);
    $_SESSION['riders_flash'] = $msg;
    header("Location: " . riders_url(['schedule_id' => $back_schedule, 'dir' => $dir_in, 'mode' => ((int)($_POST['request_id'] ?? 0) > 0) ? 'pick' : 'walk']));
    exit();
}
$notice = $_SESSION['riders_flash'] ?? '';
unset($_SESSION['riders_flash']);

// Include today's Departed trips so the operator can review the live bus and waiting riders en route.
$since = $b['today'];
$tq = mbus_db_prepare($conn, "
    SELECT s.schedule_id, s.departure_time, s.trip_status, bu.bus_number, bu.capacity, ro.origin, ro.destination,
           to_jsonb(ro)->>'origin_lat' AS origin_lat, to_jsonb(ro)->>'origin_lng' AS origin_lng,
           to_jsonb(ro)->>'destination_lat' AS destination_lat, to_jsonb(ro)->>'destination_lng' AS destination_lng
    FROM schedule s
    JOIN buses bu ON bu.bus_id = s.bus_id
    JOIN routes ro ON ro.route_id = s.route_id
    WHERE s.bus_id IN (SELECT bus_id FROM operator_bus_assignments WHERE operator_id = ?)
    AND s.departure_time >= '$since'
    AND s.trip_status NOT IN ('Arrived', 'Cancelled')
    ORDER BY s.departure_time ASC
    LIMIT 60
", [$operator_id]);
$all_trips = [];
while($tq !== false && ($t = mbus_db_fetch_assoc($tq))){ $t['dir'] = riders_dir($t['origin']); $all_trips[] = $t; }

// Pick the trip: requested one, otherwise the next one that hasn't left yet
$selected = null;
$want = (int)($_GET['schedule_id'] ?? 0);
foreach($all_trips as $t){ if((int)$t['schedule_id'] === $want){ $selected = $t; break; } }
$dir = in_array($dir_in, ['m', 'h'], true) ? $dir_in : ($selected['dir'] ?? '');
if($selected && $selected['dir'] !== $dir){ $selected = null; }
if($dir === ''){
    foreach($all_trips as $t){ if($t['departure_time'] >= $b['now']){ $dir = $t['dir']; break; } }
    if($dir === '' && $all_trips){ $dir = $all_trips[0]['dir']; }
    if($dir === ''){ $dir = 'm'; }
}
$trips = array_values(array_filter($all_trips, fn($t) => $t['dir'] === $dir));
$dir_count = ['m' => 0, 'h' => 0];
$dir_label = ['m' => 'Morong → SBMA', 'h' => 'SBMA → Morong'];
foreach($all_trips as $t){
    $dir_count[$t['dir']]++;
    $dir_label[$t['dir']] = preg_replace('/\s+Terminal$/i', '', $t['origin']) . ' → ' . preg_replace('/\s+Terminal$/i', '', $t['destination']);
}
if(!$selected){
    foreach($trips as $t){ if($t['departure_time'] >= $b['now']){ $selected = $t; break; } }
    if(!$selected && $trips){ $selected = $trips[0]; }
}
$mode = in_array($mode_in, ['res', 'walk', 'pick'], true) ? $mode_in : (($selected['trip_status'] ?? '') === 'Departed' ? 'walk' : 'res');

$riders = [];     // seat number => details
$pickups = [];    // waiting pick-up requests for this trip
if($selected){
    $rq = mbus_db_prepare($conn, "
        SELECT r.reservation_id, r.seat_number, r.status, r.pickup_location, r.destination, r.ticket_code, r.source, r.walkin_name,
               u.full_name, u.phone_number,
               (SELECT p.amount FROM payment p WHERE p.reservation_id = r.reservation_id OR p.reservation_id IN
                    (SELECT reservation_id FROM reservation WHERE user_id = r.user_id AND schedule_id = r.schedule_id)
                    ORDER BY p.payment_id DESC LIMIT 1) AS amount,
               (SELECT p.payment_reference FROM payment p WHERE p.reservation_id = r.reservation_id OR p.reservation_id IN
                    (SELECT reservation_id FROM reservation WHERE user_id = r.user_id AND schedule_id = r.schedule_id)
                    ORDER BY p.payment_id DESC LIMIT 1) AS reference
        FROM reservation r
        LEFT JOIN users u ON u.user_id = r.user_id
        WHERE r.schedule_id = ?
        AND (r.status IN ('Paid', 'Confirmed', 'Boarded')
             OR (r.status = 'Pending' AND r.created_at > NOW() - INTERVAL '10 minutes'))
    ", [(int)$selected['schedule_id']]);
    while($rq !== false && ($r = mbus_db_fetch_assoc($rq))){
        $walk = ($r['source'] ?? 'Online') !== 'Online';
        $riders[(string)$r['seat_number']] = [
            'id' => (int)$r['reservation_id'],
            'seat' => $r['seat_number'],
            'status' => $r['status'],
            'walk' => $walk,
            'kind' => $r['source'] === 'Pickup' ? 'Pickup' : ($walk ? 'Walk-in' : 'Reservation'),
            'name' => $r['full_name'] ?: ($r['walkin_name'] ?: 'Walk-in passenger'),
            'phone' => $r['phone_number'],
            'pickup' => $r['pickup_location'],
            'destination' => $r['destination'],
            'code' => $r['ticket_code'],
            'amount' => $r['amount'] !== null ? number_format((float)$r['amount'], 2) : null,
            'reference' => $r['reference'],
        ];
    }
    $pq = mbus_db_prepare($conn, "SELECT p.request_id, p.pickup_label, p.destination, p.latitude, p.longitude, p.updated_at, u.user_id, u.full_name, u.phone_number,
            (u.profile_picture_b64 IS NOT NULL AND u.profile_picture_b64 <> '') AS has_pic, LEFT(MD5(COALESCE(u.profile_picture_b64,'')),8) AS pic_v
        FROM pickup_requests p JOIN users u ON u.user_id = p.user_id
        WHERE p.schedule_id = ? AND p.status = 'Picked up'
          AND NOT EXISTS (SELECT 1 FROM reservation rv WHERE rv.user_id = p.user_id AND rv.schedule_id = p.schedule_id AND rv.source = 'Pickup' AND rv.status IN ('Paid','Confirmed','Boarded'))
        ORDER BY p.updated_at ASC", [(int)$selected['schedule_id']]);
    while($pq !== false && ($p = mbus_db_fetch_assoc($pq))){
        $est = riders_estimate($selected, $p['latitude'], $p['longitude'], (string)$p['destination']);
        $pickups[] = ['id' => (int)$p['request_id'], 'name' => $p['full_name'], 'phone' => $p['phone_number'], 'spot' => $p['pickup_label'] ?: 'Pinned spot',
            'near' => $est['pickup'], 'dest' => $est['dest'], 'fare' => $est['fare'],
            'photo' => in_array($p['has_pic'], [true, 1, '1', 't', 'true'], true) ? '../avatar.php?u=' . (int)$p['user_id'] . '&v=' . $p['pic_v'] : ''];
    }
}
$trip_open = $selected && in_array($selected['trip_status'], ['Scheduled', 'Boarding', 'Departed'], true);
$walk_open = $selected && in_array($selected['trip_status'], ['Scheduled', 'Boarding', 'Departed'], true);
$stops = [];
$ft = mbus_fare_table(); asort($ft);
foreach($ft as $k => $v){ $stops[] = ['key' => $k, 'label' => ucwords(strtolower($k)), 'fare' => $v]; }

function riders_when(string $dt, string $today_key, string $tomorrow_key): string
{
    $ts = strtotime($dt);
    $key = date('Y-m-d', $ts);
    $day = $key === $today_key ? 'Today' : ($key === $tomorrow_key ? 'Tomorrow' : date('M j', $ts));
    return $day . ' ' . date('g:i A', $ts);
}
$today_key = substr($b['today'], 0, 10);
$tomorrow_key = substr($b['tomorrow'], 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Riders</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_riders.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
</head>
<body class="has-sidebar has-bottom-nav riders-page">

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Operator</h2>
    <nav class="menu-top" aria-label="Operator menu">
        <a href="operator_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="scan.php"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
        <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
        <a href="passengers.php" aria-current="page"><i class="fa fa-users" aria-hidden="true"></i> Riders</a>
        <a href="waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>
    </nav>
    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
</aside>

<main class="main" role="main">
<header class="page-header">
    <h1>Riders</h1>
    <div class="page-header-right">
        <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                    <span class="profile-menu-role">Operator account</span>
                </div>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </div>
</header>

<div class="riders-wrap">

<?php if($notice !== ''){ ?><div class="riders-toast" role="status"><?php echo htmlspecialchars($notice); ?></div><?php } ?>

<?php if(!$all_trips){ ?>
    <div class="riders-empty">
        <h2>No trips to show</h2>
        <p>Trips on your buses will appear here once they are scheduled.</p>
    </div>
<?php } else { ?>

    <div class="seg dir-tabs" role="tablist" aria-label="Direction">
        <?php foreach(['m', 'h'] as $d){ ?>
        <a class="seg-tab<?php echo $dir === $d ? ' on' : ''; ?>" role="tab" aria-selected="<?php echo $dir === $d ? 'true' : 'false'; ?>" href="<?php echo htmlspecialchars(riders_url(['dir' => $d, 'mode' => $mode_in])); ?>">
            <?php echo htmlspecialchars($dir_label[$d]); ?><span class="seg-count"><?php echo (int)$dir_count[$d]; ?></span>
        </a>
        <?php } ?>
    </div>

<?php if(!$selected){ ?>
    <div class="riders-empty">
        <h2>No <?php echo htmlspecialchars($dir_label[$dir]); ?> trips</h2>
        <p>Nothing scheduled in this direction yet.</p>
    </div>
<?php } else { ?>

    <nav class="trip-picker" aria-label="Choose a trip">
        <?php foreach($trips as $t){ $on = (int)$t['schedule_id'] === (int)$selected['schedule_id']; ?>
        <a class="trip-chip<?php echo $on ? ' on' : ''; ?>" href="<?php echo htmlspecialchars(riders_url(['schedule_id' => (int)$t['schedule_id'], 'dir' => $dir, 'mode' => $mode_in])); ?>"<?php echo $on ? ' aria-current="true"' : ''; ?>>
            <strong><?php echo htmlspecialchars($t['bus_number']); ?></strong>
            <span><?php echo htmlspecialchars(riders_when($t['departure_time'], $today_key, $tomorrow_key)); ?></span>
        </a>
        <?php } ?>
    </nav>

<?php if($mode === 'pick'){ ?>
    <section class="pickup-panel" aria-label="Pick-up list">
        <div class="pickup-head"><h2>Pick-up list</h2><span class="seg-count"><?php echo count($pickups); ?></span></div>
        <?php if(!$pickups){ ?>
            <p class="riders-hint">No one to seat yet. When you tap Picked up on the Waiting tab, they show here so you can give them a seat.</p>
        <?php } else { ?>
        <?php $compact = count($pickups) > 3; ?>
        <?php if($compact){ ?>
        <button type="button" class="pk-compact" id="pkCompact" aria-haspopup="dialog">
            <span class="pk-stack"><?php foreach(array_slice($pickups, 0, 4) as $i => $pk){ if($pk['photo']){ ?><img class="pk-stack-img" src="<?php echo htmlspecialchars($pk['photo']); ?>" alt=""><?php } else { ?><span class="pk-stack-img pk-initial"><?php echo htmlspecialchars(strtoupper(substr($pk['name'], 0, 1))); ?></span><?php } } if(count($pickups) > 4){ ?><span class="pk-stack-img pk-stack-more">+<?php echo count($pickups) - 4; ?></span><?php } ?></span>
            <span class="pk-compact-text"><strong id="pkCompactTitle"><?php echo count($pickups); ?> to seat</strong><small id="pkCompactSub">Tap to choose who to seat</small></span>
            <span class="pk-compact-go" aria-hidden="true">›</span>
        </button>
        <div class="pk-modal-back" id="pkModal" hidden>
            <div class="pk-modal" role="dialog" aria-modal="true" aria-label="Pick-up list">
                <div class="pk-modal-head"><h3><?php echo count($pickups); ?> to seat</h3><button type="button" class="pk-modal-x" id="pkModalClose">Close</button></div>
        <ul class="pickup-list" id="pickupList">
            <?php foreach($pickups as $pk){ ?>
            <li data-req="<?php echo (int)$pk['id']; ?>">
                <button type="button" class="pk-pick" data-req="<?php echo (int)$pk['id']; ?>"<?php echo $walk_open ? '' : ' disabled'; ?>>
                    <?php if($pk['photo']){ ?><img class="pk-photo" src="<?php echo htmlspecialchars($pk['photo']); ?>" alt=""><?php } else { ?><span class="pk-photo pk-initial"><?php echo htmlspecialchars(strtoupper(substr($pk['name'], 0, 1))); ?></span><?php } ?>
                    <span class="pk-who"><strong><?php echo htmlspecialchars($pk['name']); ?></strong>
                        <small>Near <?php echo htmlspecialchars(ucwords(strtolower($pk['near']))); ?> → <?php echo htmlspecialchars(ucwords(strtolower($pk['dest']))); ?></small></span>
                    <span class="pk-fare">₱<?php echo number_format($pk['fare'], 2); ?></span>
                </button>
            </li>
            <?php } ?>
        </ul>
            </div>
        </div>
        <?php } else { ?>
        <ul class="pickup-list" id="pickupList">
            <?php foreach($pickups as $pk){ ?>
            <li data-req="<?php echo (int)$pk['id']; ?>">
                <button type="button" class="pk-pick" data-req="<?php echo (int)$pk['id']; ?>"<?php echo $walk_open ? '' : ' disabled'; ?>>
                    <?php if($pk['photo']){ ?><img class="pk-photo" src="<?php echo htmlspecialchars($pk['photo']); ?>" alt=""><?php } else { ?><span class="pk-photo pk-initial"><?php echo htmlspecialchars(strtoupper(substr($pk['name'], 0, 1))); ?></span><?php } ?>
                    <span class="pk-who"><strong><?php echo htmlspecialchars($pk['name']); ?></strong>
                        <small>Near <?php echo htmlspecialchars(ucwords(strtolower($pk['near']))); ?> → <?php echo htmlspecialchars(ucwords(strtolower($pk['dest']))); ?></small></span>
                    <span class="pk-fare">₱<?php echo number_format($pk['fare'], 2); ?></span>
                </button>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
        <?php } ?>
    </section>
<?php } ?>

    <section class="bus-panel" aria-label="Seats">
        <div class="bus-panel-head">
            <h2><?php echo htmlspecialchars($selected['origin']); ?> to <?php echo htmlspecialchars($selected['destination']); ?></h2>
            <span class="trip-state"><?php echo htmlspecialchars($selected['trip_status']); ?></span>
        </div>

        <div class="seg mode-tabs" role="tablist" aria-label="Rider type">
            <?php foreach(['res' => 'Reservation', 'walk' => 'Walk-in', 'pick' => 'Pickup'] as $m => $ml){ ?>
            <a class="seg-tab<?php echo $mode === $m ? ' on' : ''; ?>" role="tab" aria-selected="<?php echo $mode === $m ? 'true' : 'false'; ?>" href="<?php echo htmlspecialchars(riders_url(['schedule_id' => (int)$selected['schedule_id'], 'dir' => $dir, 'mode' => $m])); ?>"><?php echo $ml; if($m === 'pick' && $pickups){ ?><span class="seg-count"><?php echo count($pickups); ?></span><?php } ?></a>
            <?php } ?>
        </div>

        <ul class="seat-key" aria-hidden="true">
            <li><i class="key paid"></i>Paid</li>
            <li><i class="key boarded"></i>Boarded</li>
            <li><i class="key pending"></i>Unpaid</li>
            <li><i class="key empty"></i>Empty</li>
        </ul>

        <div class="bus-front" aria-hidden="true">Front of bus</div>
        <div class="rider-map mode-<?php echo $mode; ?>" role="group" aria-label="Bus seats">
        <?php
        $capacity = (int)$selected['capacity'];
        $rows = (int)ceil($capacity / 4);
        $n = 1;
        for($r = 1; $r <= $rows; $r++){
            echo "<div class='seat-row'>";
            for($c = 1; $c <= 4; $c++){
                if($n <= $capacity){
                    $info = $riders[(string)$n] ?? null;
                    if($info){
                        $cls = $info['status'] === 'Boarded' ? 'boarded' : (in_array($info['status'], ['Paid','Confirmed'], true) ? 'paid' : 'pending');
                        $mine = $mode === 'pick' ? $info['kind'] === 'Pickup' : ($mode === 'walk' ? $info['kind'] === 'Walk-in' : !$info['walk']);   // this tab owns the seat
                        $label = 'Seat ' . $n . ', ' . htmlspecialchars($info['name'], ENT_QUOTES) . ', ' . $info['status'];
                        if($mine){
                            echo "<button type='button' class='rseat $cls" . ($info['walk'] ? ' walkin' : '') . "' data-seat='$n' aria-label='$label'>$n</button>";
                        } else {
                            echo "<span class='rseat $cls locked" . ($info['walk'] ? ' walkin' : '') . "' aria-disabled='true' aria-label='$label (other tab)'>$n</span>";
                        }
                    } elseif(($mode === 'walk' || $mode === 'pick') && $walk_open){
                        echo "<button type='button' class='rseat empty open' data-add='$n' aria-label='Seat $n empty, " . ($mode === 'pick' ? 'seat a pick-up' : 'add walk-in') . "'>$n</button>";
                    } else {
                        echo "<span class='rseat empty locked' aria-disabled='true' aria-label='Seat $n empty'>$n</span>";
                    }
                    $n++;
                }
                if($c == 2){ echo "<span class='aisle'></span>"; }
            }
            echo "</div>";
        }
        ?>
        </div>
        <p class="riders-hint" id="ridersHint"><?php
            if($mode === 'res') echo $riders ? 'Tap a reserved seat to confirm boarding. Walk-in seats are on the Walk-in tab.' : 'No reservations on this bus yet.';
            elseif(!$walk_open) echo 'This trip is closed.';
            elseif($mode === 'pick') echo $pickups ? 'Tap a person above, then tap the seat they will take.' : 'No one to seat yet. Tap Picked up on the Waiting tab first.';
            else echo 'Tap an empty seat to seat a walk-in. Other seats are locked here.';
        ?></p>
    </section>


<?php } } ?>
</div>
</main>

<?php if($selected){ ?>
<div class="rider-backdrop" id="riderBackdrop" hidden></div>
<section class="rider-sheet" id="riderSheet" role="dialog" aria-modal="true" aria-labelledby="rdName" hidden>
    <div class="rider-sheet-head">
        <div>
            <h2 id="rdName"></h2>
            <p id="rdSeat"></p>
        </div>
        <button type="button" class="rider-close" id="riderClose" aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button>
    </div>
    <span class="rider-state" id="rdState"></span>
    <dl class="rider-facts">
        <dt>Pickup</dt><dd id="rdPickup"></dd>
        <dt>Drop-off</dt><dd id="rdDest"></dd>
        <dt>Phone</dt><dd id="rdPhone"></dd>
        <dt>Ticket</dt><dd id="rdCode"></dd>
        <dt>Paid</dt><dd id="rdAmount"></dd>
        <dt>Reference</dt><dd id="rdRef"></dd>
    </dl>
    <form method="POST" id="boardForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="mark_boarded" value="1">
        <input type="hidden" name="reservation_id" id="rdId" value="">
        <input type="hidden" name="schedule_id" value="<?php echo (int)$selected['schedule_id']; ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
        <button type="submit" class="rider-board" id="rdBoard">Confirm boarding</button>
    </form>
    <p class="rider-note" id="rdNote" hidden></p>
</section>

<section class="rider-sheet" id="walkSheet" role="dialog" aria-modal="true" aria-labelledby="wkTitle" hidden>
    <div class="rider-sheet-head">
        <div>
            <h2 id="wkTitle">Seat walk-in</h2>
            <p id="wkWho"></p>
        </div>
        <button type="button" class="rider-close" id="walkClose" aria-label="Close"><i class="fa fa-xmark" aria-hidden="true"></i></button>
    </div>
    <form method="POST" id="walkForm" class="walk-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="add_walkin" value="1">
        <input type="hidden" name="schedule_id" value="<?php echo (int)$selected['schedule_id']; ?>">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
        <input type="hidden" name="seat" id="wkSeat" value="">
        <input type="hidden" name="request_id" id="wkReq" value="">
        <div class="wk-summary" id="wkSummary" hidden></div>
        <label class="wk-field" id="wkNameRow">Name (optional)
            <input type="text" name="name" id="wkName" maxlength="100" autocomplete="off" placeholder="Walk-in passenger">
        </label>
        <label class="wk-field wk-manual">Pickup stop
            <select name="pickup" id="wkPickup" required><option value="">Choose stop</option>
            <?php foreach($stops as $st){ echo '<option value="' . htmlspecialchars($st['key']) . '" data-fare="' . $st['fare'] . '">' . htmlspecialchars($st['label']) . '</option>'; } ?>
            </select>
        </label>
        <label class="wk-field wk-manual">Drop-off stop
            <select name="destination" id="wkDest" required><option value="">Choose stop</option>
            <?php foreach($stops as $st){ echo '<option value="' . htmlspecialchars($st['key']) . '" data-fare="' . $st['fare'] . '">' . htmlspecialchars($st['label']) . '</option>'; } ?>
            </select>
        </label>
        <div class="wk-fare">Cash fare <strong id="wkFare">₱0.00</strong></div>
        <button type="submit" class="rider-board" id="wkGo" disabled>Confirm seat</button>
    </form>
</section>
<script>
const RIDERS = <?php echo json_encode($riders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const PICKUPS = <?php echo json_encode($pickups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const TRIP_OPEN = <?php echo $trip_open ? 'true' : 'false'; ?>;
const TRIP_ORIGIN = <?php echo json_encode(strtoupper((string)$selected['origin'])); ?>;
const TRIP_DEST = <?php echo json_encode(strtoupper((string)$selected['destination'])); ?>;
</script>
<script src="../Assets/js/operator_riders.js"></script>
<?php } ?>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="manage_trips.php" class="bottom-nav-item">
        <i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-users" aria-hidden="true"></i><span>Riders</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/operator_tracker.js"></script>
</body>
</html>
