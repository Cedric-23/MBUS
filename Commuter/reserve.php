<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";
require_once __DIR__ . '/../Includes/schedule_booking.php';

/* Authenticate and validate submitted bookings before any database mutation. */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}
if(isset($_POST['confirm_reserve']) && !csrf_validate_token()){
    http_response_code(403);
    die("Security validation failed. Please refresh the page and try again.");
}

/* AUTO EXPIRE - 10 MINUTE RESERVATION EXPIRATION */
$expiry_result = mbus_db_prepare($conn,"
UPDATE reservation
SET status='Cancelled'
WHERE status='Pending'
AND created_at<=NOW()-INTERVAL '10 minutes'
", []);
if($expiry_result === false){
    die("Reservation service is temporarily unavailable. Please try again.");
}

/* AUTH */
$user_id=$_SESSION['user_id'];

/* SCHEDULE */
if(!isset($_GET['schedule_id']) || !is_scalar($_GET['schedule_id'])){die("Schedule not found.");}

$schedule_id=(int)$_GET['schedule_id'];

// AJAX expects plain "ok" only on success. Never redirect a failed POST into
// HTML (fetch follows redirects); give it a conflict response instead.
$reject_booking = function(string $action) use ($schedule_id): void {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        http_response_code(409);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $action === 'pickup'
            ? "Seat reservations are closed. Open pickup.php?schedule_id=$schedule_id to mark your pickup spot."
            : "This schedule is no longer available. Please choose another trip.";
        exit();
    }
    header("Location: pickup.php?schedule_id=$schedule_id");
    exit();
};

/* STOPS */
$stops=["MORONG TERMINAL","BINARITAN","POBLACION","HILLTOP","SABANG","ANVAYA","MABAYO","MINANGA","MORONG GATE","GROUP 6","IDESS","APPAREL","GATE 2","TRIBOA","TOWER","KORYO","TECHNO","UA","NSD","PETRON","INDUSTRIAL","HARBOR POINT","MAIN GATE"];

/* FARE TABLE */
$fare_table=mbus_stop_positions();
$passenger_type=mbus_passenger_type($conn,(int)$user_id);

/* ROUTE
 * Falls back to NULL coordinates if the routes table has no map columns yet
 * (supabase/03_add_map_coordinates.sql not applied), so booking still works without the map. */
$route_sql = "
SELECT routes.origin,routes.destination,buses.bus_number,buses.capacity,schedule.departure_time,schedule.arrival_time,schedule.trip_status,schedule.schedule_status,
%s
FROM schedule
JOIN routes ON schedule.route_id=routes.route_id
JOIN buses ON schedule.bus_id=buses.bus_id
WHERE schedule.schedule_id=?
";
$route_query=mbus_db_prepare($conn,sprintf($route_sql,"routes.origin_lat,routes.origin_lng,routes.destination_lat,routes.destination_lng"), [$schedule_id]);
if($route_query === false){
    error_log("Commuter/reserve.php route query failed, retrying without coordinates: " . ($conn->lastError ?? 'unknown error'));
    $route_query=mbus_db_prepare($conn,sprintf($route_sql,"NULL AS origin_lat,NULL AS origin_lng,NULL AS destination_lat,NULL AS destination_lng"), [$schedule_id]);
}

$route_data=mbus_db_fetch_assoc($route_query);

if(!$route_data){die("Invalid schedule.");}

/* Shared policy also rejects terminal, inactive, and stale schedules. */
$booking_action = mbus_schedule_action($route_data);
if($booking_action !== 'reserve'){
    $reject_booking($booking_action);
}

/* REVERSE */
if(strtoupper($route_data['origin'])=='SBMA' && strtoupper($route_data['destination'])=='MORONG TERMINAL'){
    $stops=array_reverse($stops);
}

/* PICKUP */
$pickup_stops=$stops;
array_pop($pickup_stops);
array_pop($pickup_stops);

/* RESERVED */
$seat_sql="SELECT seat_number FROM reservation
WHERE schedule_id=?
AND(status='Paid' OR status='Confirmed' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL '10 minutes'))
";
$reserved_query=mbus_db_prepare($conn,$seat_sql,[$schedule_id]);
if($reserved_query === false){
    die("Unable to check seat availability. Please try again.");
}
$reserved=[];
while($reserved_row=mbus_db_fetch_assoc($reserved_query)){
    $reserved[]=(int)$reserved_row['seat_number'];
}
$reserved_count=count($reserved);
$capacity=(int)($route_data['capacity'] ?? 0);
$available_seats=max(0,$capacity-$reserved_count);
$booking_action = mbus_schedule_action($route_data, $available_seats);
if($booking_action !== 'reserve'){
    $reject_booking($booking_action);
}

/* RESERVE */
if(isset($_POST['confirm_reserve'])){
$pickup_input=$_POST['pickup'] ?? '';
$destination_input=$_POST['destination'] ?? '';
$seats_input=$_POST['seats'] ?? '';
if(!is_string($pickup_input) || !is_string($destination_input) || !is_string($seats_input) || trim($pickup_input) === '' || trim($destination_input) === ''){
    die("Invalid reservation details.");
}
$pickup=mbus_db_escape($conn,$pickup_input);
$destination=mbus_db_escape($conn,$destination_input);
$raw_seats = array_map('trim', explode(',', $seats_input));
if(empty($raw_seats) || count(array_filter($raw_seats, function($seat){ return $seat === '' || !preg_match('/^\d+$/', $seat); })) > 0){
    die("Invalid seat selection.");
}
$requested_seats = array_values(array_unique(array_map('intval', $raw_seats)));
if($capacity <= 0 || empty($requested_seats) || count($requested_seats) > $available_seats || count(array_filter($requested_seats, function($seat) use ($capacity){ return $seat < 1 || $seat > $capacity; })) > 0){
    die("Invalid seat selection or insufficient capacity.");
}
$seats=$requested_seats;
/* Per-seat passenger type (+ ID photo for discounted seats that are not the verified account holder's own) */
$types_in = json_decode((string)($_POST['types'] ?? '{}'), true); if(!is_array($types_in)) $types_in = [];
$ids_in = json_decode((string)($_POST['ids'] ?? '{}'), true); if(!is_array($ids_in)) $ids_in = [];
$seat_info = [];
foreach($seats as $i => $seat_no){
    $t = (string)($types_in[(string)$seat_no] ?? 'Regular');
    if(!in_array($t, MBUS_PASSENGER_TYPES, true)) $t = 'Regular';
    $info = ['type' => $t, 'status' => 'None', 'b64' => null, 'mime' => null];
    if($t !== 'Regular'){
        if($i === 0 && $passenger_type !== 'Regular' && $t === $passenger_type){
            $info['status'] = 'Approved';
        } else {
            $d = (string)($ids_in[(string)$seat_no] ?? '');
            if(!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $d, $m) || strlen($m[2]) > 1200000 || base64_decode($m[2], true) === false){
                http_response_code(422);
                echo "Seat $seat_no needs an ID photo for the $t fare.";
                exit();
            }
            $info['status'] = 'Pending'; $info['mime'] = $m[1]; $info['b64'] = $m[2];
        }
    }
    $seat_info[$seat_no] = $info;
}
$created_reservations=[];
try{
    $conn->pdo->beginTransaction();
    // Serialize bookings for this schedule, and block a concurrent departure
    // update until this transaction commits or rolls back. Seat-row locks alone
    // cannot protect an unoccupied seat or a changing schedule status.
    $locked_result = mbus_db_prepare($conn,"
SELECT schedule.schedule_id,schedule.schedule_status,schedule.trip_status,
       schedule.departure_time,schedule.arrival_time,buses.capacity
FROM schedule
JOIN buses ON buses.bus_id=schedule.bus_id
WHERE schedule.schedule_id=?
FOR UPDATE OF schedule, buses
", [$schedule_id]);
    if($locked_result === false){
        throw new RuntimeException("Schedule availability check failed");
    }
    $locked_schedule = mbus_db_fetch_assoc($locked_result);
    if(!$locked_schedule){
        throw new DomainException('unavailable');
    }
    $locked_count_result = mbus_db_prepare($conn,"
SELECT COUNT(*) AS reserved FROM reservation
WHERE schedule_id=?
AND(status IN ('Paid','Confirmed','Boarded') OR(status='Pending' AND created_at>NOW()-INTERVAL '10 minutes'))
", [$schedule_id]);
    if($locked_count_result === false){
        throw new RuntimeException("Seat availability check failed");
    }
    $locked_count = mbus_db_fetch_assoc($locked_count_result);
    $locked_capacity = (int)$locked_schedule['capacity'];
    $locked_available = max(0, $locked_capacity - (int)$locked_count['reserved']);
    $locked_action = mbus_schedule_action($locked_schedule, $locked_available);
    if($locked_action !== 'reserve'){
        throw new DomainException($locked_action);
    }
    if(count($seats) > $locked_available || max($seats) > $locked_capacity){
        throw new RuntimeException("Insufficient capacity");
    }
    foreach($seats as $seat){
        $check=mbus_db_prepare($conn,"
SELECT reservation_id FROM reservation
WHERE schedule_id = ?
AND seat_number = ?
AND(status='Paid' OR status='Confirmed' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL '10 minutes'))
FOR UPDATE
", [$schedule_id, $seat]);
        if($check === false){
            throw new RuntimeException("Seat availability check failed");
        }
        if(mbus_db_num_rows($check)>0){
            throw new RuntimeException("Selected seat is no longer available");
        }
        do{
            $ticket_code=random_int(1000,9999);
            $check_code=mbus_db_prepare($conn,"SELECT reservation_id FROM reservation WHERE ticket_code = ?", [$ticket_code]);
            if($check_code === false){
                throw new RuntimeException("Ticket code check failed");
            }
        }while(mbus_db_num_rows($check_code)>0);
        // A cutoff can pass even while the transaction is open.
        $locked_action = mbus_schedule_action($locked_schedule, $locked_available - count($created_reservations));
        if($locked_action !== 'reserve'){
            throw new DomainException($locked_action);
        }
        $inserted = mbus_db_prepare($conn,"
INSERT INTO reservation(ticket_code,user_id,schedule_id,seat_number,pickup_location,destination,reservation_date,created_at,status,passenger_type,discount_status,id_image_b64,id_image_mime)
VALUES(?, ?, ?, ?, ?, ?, NOW(), NOW(), 'Pending', ?, ?, ?, ?)
", [$ticket_code, $user_id, $schedule_id, $seat, $pickup, $destination, $seat_info[$seat]['type'], $seat_info[$seat]['status'], $seat_info[$seat]['b64'], $seat_info[$seat]['mime']]);
        if($inserted === false){
            throw new RuntimeException("Reservation insert failed");
        }
        $created_reservations[]=['seat'=>$seat,'ticket_code'=>$ticket_code];
    }
    $conn->pdo->commit();
} catch(Throwable $e){
    if($conn->pdo->inTransaction()){
        $conn->pdo->rollBack();
    }
    if($e instanceof DomainException){
        $reject_booking($e->getMessage());
    }
    http_response_code(409);
    echo "Reservation could not be completed. Please refresh and select available seats.";
    exit();
}

$user_email_query = mbus_db_prepare($conn, "SELECT email FROM users WHERE user_id = ?", [$user_id]);
$user_email_data = $user_email_query === false ? false : mbus_db_fetch_assoc($user_email_query);
$user_email = $user_email_data['email'] ?? '';
$route_info = "$route_data[origin] → $route_data[destination]";
$departure_formatted = date('F d, Y g:i A', strtotime($route_data['departure_time']));
foreach($created_reservations as $created){
    $seat=$created['seat'];
    $ticket_code=$created['ticket_code'];
    log_activity($conn, $user_id, ACTION_RESERVATION_CREATE, "Reservation created for schedule $schedule_id, seat $seat, ticket code: $ticket_code");
    if($user_email !== ''){
        $email_subject = "Reservation Created - Ticket: $ticket_code";
        $email_message = get_reservation_created_email($ticket_code, $departure_formatted, $route_info);
        send_email_notification($user_email, $email_subject, $email_message);
    }
}
echo "ok";
exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reserve Seat</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Assets/css/responsive.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_reserve.css">


    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css">
    <link rel="stylesheet" href="../Assets/css/commuter_verification.css">
</head>

<body class="reserve-page">

<main class="main" role="main">

<header class="page-header">
    <div class="page-header-left">
        <a href="schedule.php" class="back-btn" aria-label="Back to schedules"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
        <h1>Choose your seats</h1>
    </div>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<div class="reserve-layout">

<!-- LEFT PANEL -->
<div class="reserve-panel reserve-panel-main">

<div class="card trip-info-card">

<div class="trip-top">
    <p class="trip-route"><?php echo htmlspecialchars($route_data['origin']); ?> <i class="fa fa-arrow-right" aria-hidden="true"></i> <?php echo htmlspecialchars($route_data['destination']); ?></p>
    <?php $seat_state = $available_seats<=0 ? 'full' : ($available_seats<=5 ? 'low' : ''); ?>
    <span class="seat-counter <?php echo $seat_state; ?>"><?php echo $available_seats; ?> seats left</span>
</div>

<ul class="trip-facts">
    <li><i class="fa fa-clock" aria-hidden="true"></i><?php echo date("D, M j \\a\\t g:i A", strtotime($route_data['departure_time'])); ?></li>
    <li><i class="fa fa-bus" aria-hidden="true"></i><?php echo htmlspecialchars($route_data['bus_number']); ?></li>
</ul>



<div class="seat-legend" role="list" aria-label="Seat status legend">
    <div class="legend-item" role="listitem">
        <span class="legend-box legend-available" aria-hidden="true"></span>
        <span>Available</span>
    </div>
    <div class="legend-item" role="listitem">
        <span class="legend-box legend-selected" aria-hidden="true"></span>
        <span>Selected</span>
    </div>
    <div class="legend-item" role="listitem">
        <span class="legend-box legend-reserved" aria-hidden="true"></span>
        <span>Reserved</span>
    </div>
</div>

</div>

<div class="card seat-map-card">

<div class="bus-front" aria-hidden="true"><i class="fa fa-steering-wheel"></i> Front of bus</div>

<div class="seat-map" role="grid" aria-label="Bus seat map">

<?php
$seat=1;
$seat_rows=(int)ceil($capacity / 4);
for($r=1;$r<=$seat_rows;$r++){
echo "<div class='seat-row' role='row'>";
for($c=1;$c<=4;$c++){
if($seat<=$capacity){
$class="seat-btn";
$disabled="";
$aria_disabled="false";
if(in_array($seat,$reserved)){
$class.=" reserved";
$disabled="disabled";
$aria_disabled="true";
}
echo "<button type='button' class='$class' data-seat='$seat' role='gridcell' $disabled aria-disabled='$aria_disabled' aria-label='Seat $seat'>$seat</button>";
$seat++;
}
if($c==2){echo "<div class='aisle' role='separator' aria-label='Aisle'></div>";}
}
echo "</div>";
}
?>

</div>

</div>

<div class="card pickup-card">

<div class="form-group">
    <label for="pickup">Pickup Location</label>
    <select id="pickup" class="form-control" required>
        <option value="">Select Pickup</option>
        <?php foreach($pickup_stops as $stop){echo "<option value='$stop'>$stop</option>";} ?>
    </select>
</div>

<div class="form-group">
    <label for="destination">Destination</label>
    <select id="destination" class="form-control" required disabled>
        <option value="">Select pickup first</option>
    </select>
</div>

<button type="button" id="doneBtn" class="btn btn-primary btn-block">
    Review my trip
</button>

</div>

</div>

<!-- RIGHT PANEL -->
<div class="reserve-panel reserve-panel-sidebar">

<div class="card summary-card" id="summaryPanel">

<header class="summary-header">
    <h2>Reservation Summary</h2>
</header>

<div class="alert alert-success" id="successBox" style="display:none;" role="alert"></div>
<div class="alert alert-error" id="errorBox" style="display:none;" role="alert"></div>

<dl class="summary-details">
    <dt>Bus</dt>
    <dd><?php echo htmlspecialchars($route_data['bus_number']); ?></dd>
    
    <dt>Departure</dt>
    <dd><?php echo date("F d, Y g:i A", strtotime($route_data['departure_time'])); ?></dd>
    
    <dt>Seats</dt>
    <dd id="summarySeats">-</dd>
    
    <dt>Pickup</dt>
    <dd id="summaryPickup">-</dd>
    
    <dt>Destination</dt>
    <dd id="summaryDestination">-</dd>
</dl>

<div id="passengerTypes" class="passenger-types"></div>

<button type="button" id="calculateBtn" class="btn btn-secondary btn-block">
    Calculate fare
</button>

<div class="fare-breakdown" id="fareBreakdown" style="display:none;">
    <div id="fareDetails"></div>
    <div class="fare-total">
        <strong>Total: <span id="totalAmount">&#8369;0.00</span></strong>
    </div>
    <button type="button" id="reserveBtn" class="btn btn-primary btn-block">
        Reserve seats and pay
    </button>
</div>

</div>

</div>

</div>

</main>

<script>
const scheduleId = <?php echo (int)$schedule_id; ?>;
const csrfToken = <?php echo json_encode(csrf_get_token()); ?>;
const route = <?php echo json_encode($stops); ?>;
const fareTable = <?php echo json_encode($fare_table); ?>;
const passengerType = <?php echo json_encode($passenger_type); ?>;
</script>


<script src="../Assets/js/commuter_reserve.js"></script>

<div class="seat-bar" id="seatBar">
    <div class="seat-bar-info">
        <span class="seat-bar-title" id="seatBarTitle">Pick your seats</span>
        <span class="seat-bar-sub" id="seatBarSub">Tap an available seat</span>
    </div>
    <button type="button" class="seat-bar-btn" id="seatBarBtn">Choose seats</button>
</div>

<script>
/* Sticky seat bar (phones): shows the live selection and walks to the next missing step */
(function(){
    const bar = document.getElementById('seatBar');
    const title = document.getElementById('seatBarTitle');
    const sub = document.getElementById('seatBarSub');
    const btn = document.getElementById('seatBarBtn');
    const pickup = document.getElementById('pickup');
    const dest = document.getElementById('destination');
    if (!bar) return;

    function selected(){ return Array.from(document.querySelectorAll('.seat-btn.selected')).map(b => b.dataset.seat); }

    function refresh(){
        const fare = document.getElementById('fareBreakdown');
        bar.hidden = !!fare && fare.style.display !== 'none';
        const seats = selected();
        if (!seats.length) {
            title.textContent = 'Pick your seats';
            sub.textContent = 'Tap an available seat';
            btn.textContent = 'Choose seats';
        } else if (!pickup.value) {
            title.textContent = (seats.length === 1 ? 'Seat ' : 'Seats ') + seats.join(', ');
            sub.textContent = 'Next: pickup location';
            btn.textContent = 'Set pickup';
        } else if (!dest.value) {
            title.textContent = (seats.length === 1 ? 'Seat ' : 'Seats ') + seats.join(', ');
            sub.textContent = 'Next: destination';
            btn.textContent = 'Set destination';
        } else {
            title.textContent = (seats.length === 1 ? 'Seat ' : 'Seats ') + seats.join(', ');
            sub.textContent = 'Ready to review';
            btn.textContent = 'Review';
        }
    }

    btn.addEventListener('click', function(){
        const seats = selected();
        if (!seats.length) { document.querySelector('.seat-map').scrollIntoView({behavior:'smooth', block:'center'}); return; }
        if (!pickup.value) { pickup.scrollIntoView({behavior:'smooth', block:'center'}); pickup.focus(); return; }
        if (!dest.value) { dest.scrollIntoView({behavior:'smooth', block:'center'}); dest.focus(); return; }
        document.getElementById('doneBtn').click();
        document.getElementById('summaryPanel').scrollIntoView({behavior:'smooth', block:'start'});
    });

    document.addEventListener('click', function(){ setTimeout(refresh, 0); });
    pickup.addEventListener('change', function(){ setTimeout(refresh, 0); });
    dest.addEventListener('change', function(){ setTimeout(refresh, 0); });
    refresh();
})();
</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span>
    </a>
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
