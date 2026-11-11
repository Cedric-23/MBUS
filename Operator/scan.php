<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/activity_log.php";
require_once "../Includes/ticket_qr.php";

if(!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator'){
    if($_SERVER['REQUEST_METHOD'] === 'POST'){
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Please log in again.']);
        exit();
    }
    header("Location: ../login.php");
    exit();
}

$operator_id = (int)$_SESSION['user_id'];

function scan_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/** Resolve a scanned/typed code to [schedule_id, user_id], or null. Accepts the QR token or the MB-#### ticket code. */
function scan_resolve($conn, string $code): ?array
{
    $code = trim($code);
    if($code === ''){ return null; }
    if(preg_match('/^MB-\d{3,8}$/i', $code)){
        $q = mbus_db_prepare($conn, "
            SELECT r.schedule_id, r.user_id
            FROM payment p
            JOIN reservation r ON r.reservation_id = p.reservation_id
            WHERE UPPER(p.ticket_code) = UPPER(?)
            ORDER BY p.payment_id DESC LIMIT 1
        ", [$code]);
        $row = $q === false ? false : mbus_db_fetch_assoc($q);
        return $row ? ['schedule_id' => (int)$row['schedule_id'], 'user_id' => (int)$row['user_id']] : null;
    }
    return mbus_verify_ticket_token($code);
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!csrf_validate_token()){
        scan_json(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'], 403);
    }

    $action = (string)($_POST['action'] ?? '');
    $ref = scan_resolve($conn, (string)($_POST['code'] ?? ''));
    if(!$ref){
        scan_json(['ok' => false, 'error' => 'This is not a valid MBUS ticket.']);
    }
    $schedule_id = $ref['schedule_id'];
    $user_id = $ref['user_id'];

    // The ticket must be for a bus assigned to this operator.
    $sq = mbus_db_prepare($conn, "
        SELECT s.schedule_id, s.departure_time, s.trip_status, b.bus_number, ro.origin, ro.destination AS route_destination
        FROM schedule s
        JOIN buses b ON b.bus_id = s.bus_id
        JOIN routes ro ON ro.route_id = s.route_id
        WHERE s.schedule_id = ?
        AND s.bus_id IN (SELECT bus_id FROM operator_bus_assignments WHERE operator_id = ?)
    ", [$schedule_id, $operator_id]);
    $trip = $sq === false ? false : mbus_db_fetch_assoc($sq);
    if(!$trip){
        scan_json(['ok' => false, 'error' => 'This ticket is for a bus that is not assigned to you.']);
    }

    if($action === 'board'){
        $up = mbus_db_prepare($conn, "
            UPDATE reservation SET status = 'Boarded'
            WHERE user_id = ? AND schedule_id = ? AND status IN ('Paid', 'Confirmed')
            AND schedule_id IN (SELECT schedule_id FROM schedule WHERE trip_status IN ('Scheduled', 'Boarding', 'Departed'))
        ", [$user_id, $schedule_id]);
        $boarded_count = $up === false ? 0 : (int)$conn->affectedRows;
        if($boarded_count <= 0){
            scan_json(['ok' => false, 'error' => 'Nothing to board. The ticket is already used or the trip is closed.']);
        }
        log_activity($conn, $operator_id, 'Passenger Boarded', "QR boarding: user $user_id, schedule $schedule_id, seats $boarded_count");
        scan_json(['ok' => true, 'boarded' => $boarded_count]);
    }

    // lookup
    $rq = mbus_db_prepare($conn, "
        SELECT r.seat_number, r.pickup_location, r.destination, r.status
        FROM reservation r
        WHERE r.user_id = ? AND r.schedule_id = ? AND r.status IN ('Paid', 'Confirmed', 'Boarded')
        ORDER BY NULLIF(regexp_replace(r.seat_number, '\D', '', 'g'), '')::int ASC
    ", [$user_id, $schedule_id]);
    $seats = [];
    $pickup = $dest = '';
    $paid = 0;
    $boarded = 0;
    while($rq !== false && ($r = mbus_db_fetch_assoc($rq))){
        $seats[] = $r['seat_number'];
        $pickup = $pickup ?: $r['pickup_location'];
        $dest = $dest ?: $r['destination'];
        if($r['status'] !== 'Boarded'){ $paid++; } else { $boarded++; }
    }
    if(!$seats){
        scan_json(['ok' => false, 'error' => 'No paid reservation found for this ticket. It may be unpaid, expired or cancelled.']);
    }

    $uq = mbus_db_prepare($conn, "SELECT full_name FROM users WHERE user_id = ?", [$user_id]);
    $u = $uq === false ? false : mbus_db_fetch_assoc($uq);
    $pq = mbus_db_prepare($conn, "
        SELECT p.amount, p.payment_method, p.payment_reference, p.payment_date, p.ticket_code
        FROM payment p JOIN reservation r ON r.reservation_id = p.reservation_id
        WHERE r.user_id = ? AND r.schedule_id = ?
        ORDER BY p.payment_id DESC LIMIT 1
    ", [$user_id, $schedule_id]);
    $pay = $pq === false ? false : mbus_db_fetch_assoc($pq);

    $trip_open = in_array($trip['trip_status'], ['Scheduled', 'Boarding', 'Departed'], true);
    $state = $paid > 0 ? 'Paid' : 'Boarded';
    $can_board = $paid > 0 && $trip_open;
    $note = '';
    if($paid <= 0){ $note = 'This passenger has already boarded.'; }
    elseif(!$trip_open){ $note = 'This trip is ' . $trip['trip_status'] . ', so boarding is closed.'; }

    scan_json(['ok' => true, 'ticket' => [
        'passenger' => $u['full_name'] ?? 'Passenger',
        'route' => $trip['origin'] . ' to ' . $trip['route_destination'],
        'bus' => $trip['bus_number'],
        'departure' => date('D, M j \a\t g:i A', strtotime($trip['departure_time'])),
        'pickup' => $pickup,
        'destination' => $dest,
        'seats' => $seats,
        'state' => $state,
        'can_board' => $can_board,
        'note' => $note,
        'amount' => $pay ? number_format((float)$pay['amount'], 2) : null,
        'method' => $pay['payment_method'] ?? null,
        'reference' => $pay['payment_reference'] ?? null,
        'ticket_code' => $pay['ticket_code'] ?? null,
    ]]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Ticket</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_scan.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
</head>
<body class="has-sidebar has-bottom-nav scan-page">

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
    <div class="logo-container"><img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo"></div>
    <h2>Operator</h2>
    <nav class="menu-top" aria-label="Operator menu">
        <a href="operator_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="scan.php" aria-current="page"><i class="fa fa-qrcode" aria-hidden="true"></i> Scan Ticket</a>
        <a href="manage_trips.php"><i class="fa fa-bus" aria-hidden="true"></i> Manage Trips</a>
        <a href="passengers.php"><i class="fa fa-users" aria-hidden="true"></i> Passenger List</a>
        <a href="waiting.php"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</a>
    </nav>
    <div class="menu-bottom">
        <a href="../logout.php" class="logout-btn btn btn-outline btn-block"><i class="fa fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
</aside>

<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>Scan ticket</h1></div>
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

    <div class="scan-wrap">

        <section class="scan-camera" id="scanCamera" aria-label="Camera">
            <video id="scanVideo" playsinline muted></video>
            <div class="scan-frame" aria-hidden="true"></div>
            <p class="scan-status" id="scanStatus" role="status">Starting camera</p>
        </section>

        <div class="scan-tools">
            <button type="button" class="scan-btn ghost" id="scanRestart" hidden>Scan again</button>
            <label class="scan-btn ghost" for="scanFile">Use a photo</label>
            <input type="file" id="scanFile" accept="image/*" hidden>
        </div>

        <form class="scan-manual" id="scanManual" autocomplete="off">
            <label for="scanCode">Or type the ticket code</label>
            <div class="scan-manual-row">
                <input type="text" id="scanCode" placeholder="MB-1234" autocapitalize="characters" inputmode="text">
                <button type="submit" class="scan-btn solid">Check</button>
            </div>
        </form>

        <div class="scan-error" id="scanError" role="alert" hidden></div>

        <article class="scan-result" id="scanResult" aria-live="polite" hidden>
            <div class="scan-result-head">
                <div>
                    <h2 id="rName"></h2>
                    <p id="rRoute"></p>
                </div>
                <span class="scan-state" id="rState"></span>
            </div>
            <dl class="scan-facts">
                <dt>Seats</dt><dd id="rSeats"></dd>
                <dt>Bus</dt><dd id="rBus"></dd>
                <dt>Departure</dt><dd id="rDeparture"></dd>
                <dt>Pickup</dt><dd id="rPickup"></dd>
                <dt>Destination</dt><dd id="rDestination"></dd>
                <dt>Paid</dt><dd id="rPaid"></dd>
                <dt>GCash ref</dt><dd id="rRef"></dd>
            </dl>
            <p class="scan-note" id="rNote" hidden></p>
            <button type="button" class="scan-btn solid big" id="boardBtn">Confirm boarding</button>
        </article>

        <div class="scan-done" id="scanDone" role="status" hidden>
            <span class="scan-done-mark" aria-hidden="true"><i class="fa fa-check"></i></span>
            <h2>Passenger boarded</h2>
            <p id="doneText"></p>
            <button type="button" class="scan-btn solid big" id="nextBtn">Scan next ticket</button>
        </div>
    </div>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
    <a href="scan.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>

<script>const scanCsrf = <?php echo json_encode(csrf_get_token()); ?>;</script>
<script src="../Assets/js/vendor/jsQR.js"></script>
<script src="../Assets/js/operator_scan.js"></script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/operator_tracker.js"></script>
</body>
</html>
