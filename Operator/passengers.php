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

// Confirm boarding from a rider card
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_boarded'])){
    $back_schedule = (int)($_POST['schedule_id'] ?? 0);
    if(!csrf_validate_token()){
        $error = "Session expired. Try again.";
    } else {
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);
        $stmt = mbus_db_prepare($conn, "UPDATE reservation r SET status='Boarded'
            WHERE r.reservation_id = ?
              AND r.status = 'Paid'
              AND r.schedule_id IN (
                  SELECT s.schedule_id FROM schedule s
                  JOIN operator_bus_assignments oba ON oba.bus_id = s.bus_id
                  WHERE oba.operator_id = ? AND s.trip_status IN ('Scheduled', 'Boarding')
              )", [$reservation_id, $operator_id]);
        $ok = $stmt !== false && $conn->affectedRows > 0;
        $_SESSION['riders_flash'] = $ok ? 'Passenger boarded.' : 'Could not board this passenger.';
    }
    header("Location: passengers.php?schedule_id=" . $back_schedule);
    exit();
}
$notice = $_SESSION['riders_flash'] ?? '';
unset($_SESSION['riders_flash']);

// Trips this operator runs: from 3 hours ago so a bus that just left can still be checked
$since = (new DateTimeImmutable($b['now']))->modify('-3 hours')->format('Y-m-d H:i:s');
$tq = mbus_db_prepare($conn, "
    SELECT s.schedule_id, s.departure_time, s.trip_status, bu.bus_number, bu.capacity, ro.origin, ro.destination
    FROM schedule s
    JOIN buses bu ON bu.bus_id = s.bus_id
    JOIN routes ro ON ro.route_id = s.route_id
    WHERE s.bus_id IN (SELECT bus_id FROM operator_bus_assignments WHERE operator_id = ?)
    AND s.departure_time >= '$since'
    ORDER BY s.departure_time ASC
    LIMIT 14
", [$operator_id]);
$trips = [];
while($tq !== false && ($t = mbus_db_fetch_assoc($tq))){ $trips[] = $t; }

// Pick the trip: requested one, otherwise the next one that hasn't left yet
$selected = null;
$want = (int)($_GET['schedule_id'] ?? 0);
foreach($trips as $t){ if((int)$t['schedule_id'] === $want){ $selected = $t; break; } }
if(!$selected){
    foreach($trips as $t){ if($t['departure_time'] >= $b['now']){ $selected = $t; break; } }
    if(!$selected && $trips){ $selected = $trips[0]; }
}

$riders = [];     // seat number => details
if($selected){
    $rq = mbus_db_prepare($conn, "
        SELECT r.reservation_id, r.seat_number, r.status, r.pickup_location, r.destination, r.ticket_code,
               u.full_name, u.phone_number,
               (SELECT p.amount FROM payment p WHERE p.reservation_id IN
                    (SELECT reservation_id FROM reservation WHERE user_id = r.user_id AND schedule_id = r.schedule_id)
                    ORDER BY p.payment_id DESC LIMIT 1) AS amount,
               (SELECT p.payment_reference FROM payment p WHERE p.reservation_id IN
                    (SELECT reservation_id FROM reservation WHERE user_id = r.user_id AND schedule_id = r.schedule_id)
                    ORDER BY p.payment_id DESC LIMIT 1) AS reference
        FROM reservation r
        JOIN users u ON u.user_id = r.user_id
        WHERE r.schedule_id = ?
        AND (r.status IN ('Paid', 'Boarded')
             OR (r.status = 'Pending' AND r.created_at > NOW() - INTERVAL '10 minutes'))
    ", [(int)$selected['schedule_id']]);
    while($rq !== false && ($r = mbus_db_fetch_assoc($rq))){
        $riders[(string)$r['seat_number']] = [
            'id' => (int)$r['reservation_id'],
            'seat' => $r['seat_number'],
            'status' => $r['status'],
            'name' => $r['full_name'],
            'phone' => $r['phone_number'],
            'pickup' => $r['pickup_location'],
            'destination' => $r['destination'],
            'code' => $r['ticket_code'],
            'amount' => $r['amount'] !== null ? number_format((float)$r['amount'], 2) : null,
            'reference' => $r['reference'],
        ];
    }
}
$trip_open = $selected && in_array($selected['trip_status'], ['Scheduled', 'Boarding'], true);

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
        <a href="notifications.php"><i class="fa fa-bell" aria-hidden="true"></i> Notifications</a>
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
                <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
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

<?php if(!$trips){ ?>
    <div class="riders-empty">
        <h2>No trips to show</h2>
        <p>Trips on your buses will appear here once they are scheduled.</p>
    </div>
<?php } else { ?>

    <nav class="trip-picker" aria-label="Choose a trip">
        <?php foreach($trips as $t){ $on = (int)$t['schedule_id'] === (int)$selected['schedule_id']; ?>
        <a class="trip-chip<?php echo $on ? ' on' : ''; ?>" href="passengers.php?schedule_id=<?php echo (int)$t['schedule_id']; ?>"<?php echo $on ? ' aria-current="true"' : ''; ?>>
            <strong><?php echo htmlspecialchars($t['bus_number']); ?></strong>
            <span><?php echo htmlspecialchars(riders_when($t['departure_time'], $today_key, $tomorrow_key)); ?></span>
        </a>
        <?php } ?>
    </nav>

    <section class="bus-panel" aria-label="Seats">
        <div class="bus-panel-head">
            <h2><?php echo htmlspecialchars($selected['origin']); ?> to <?php echo htmlspecialchars($selected['destination']); ?></h2>
            <span class="trip-state"><?php echo htmlspecialchars($selected['trip_status']); ?></span>
        </div>

        <ul class="seat-key" aria-hidden="true">
            <li><i class="key paid"></i>Paid</li>
            <li><i class="key boarded"></i>Boarded</li>
            <li><i class="key pending"></i>Unpaid</li>
            <li><i class="key empty"></i>Empty</li>
        </ul>

        <div class="bus-front" aria-hidden="true">Front of bus</div>
        <div class="rider-map" role="group" aria-label="Bus seats">
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
                        $cls = $info['status'] === 'Boarded' ? 'boarded' : ($info['status'] === 'Paid' ? 'paid' : 'pending');
                        echo "<button type='button' class='rseat $cls' data-seat='$n' aria-label='Seat $n, " . htmlspecialchars($info['name'], ENT_QUOTES) . ", " . $info['status'] . "'>$n</button>";
                    } else {
                        echo "<span class='rseat empty' aria-label='Seat $n empty'>$n</span>";
                    }
                    $n++;
                }
                if($c == 2){ echo "<span class='aisle'></span>"; }
            }
            echo "</div>";
        }
        ?>
        </div>
        <?php if(!$riders){ ?><p class="riders-hint">No riders on this bus yet.</p><?php } else { ?><p class="riders-hint">Tap a seat to see the rider.</p><?php } ?>
    </section>

<?php } ?>
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
        <dt>GCash ref</dt><dd id="rdRef"></dd>
    </dl>
    <form method="POST" id="boardForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="mark_boarded" value="1">
        <input type="hidden" name="reservation_id" id="rdId" value="">
        <input type="hidden" name="schedule_id" value="<?php echo (int)$selected['schedule_id']; ?>">
        <button type="submit" class="rider-board" id="rdBoard">Confirm boarding</button>
    </form>
    <p class="rider-note" id="rdNote" hidden></p>
</section>
<script>
const RIDERS = <?php echo json_encode($riders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const TRIP_OPEN = <?php echo $trip_open ? 'true' : 'false'; ?>;
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
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i><span>Alerts</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
