<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";

/* AUTO EXPIRE - 10 MINUTE RESERVATION EXPIRATION */
mbus_db_query($conn,"
UPDATE reservation
SET status='Cancelled'
WHERE status='Pending'
AND created_at<=NOW()-INTERVAL 10 MINUTE
");

/* NOTIFY USER OF EXPIRED RESERVATIONS */
$expired_query = mbus_db_query($conn,"
SELECT reservation_id, user_id, ticket_code
FROM reservation
WHERE status='Cancelled'
AND created_at<=NOW()-INTERVAL 10 MINUTE
AND created_at>NOW()-INTERVAL 10 MINUTE 30 SECOND
");

while($expired = mbus_db_fetch_assoc($expired_query)){
    $notification_msg = "Your reservation (Ticket: {$expired['ticket_code']}) has expired due to non-payment within 10 minutes.";
    mbus_db_query($conn,"
    INSERT INTO notification(user_id, message, notification_date, status)
    VALUES({$expired['user_id']}, '$notification_msg', NOW(), 'Unread')
    ");
}

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id=$_SESSION['user_id'];

/* SCHEDULE */
if(!isset($_GET['schedule_id'])){die("Schedule not found.");}

$schedule_id=mbus_db_escape($conn,$_GET['schedule_id']);

/* STOPS */
$stops=["MORONG TERMINAL","BINARITAN","POBLACION","HILLTOP","SABANG","ANVAYA","MABAYO","MINANGA","MORONG GATE","GROUP 6","IDESS","APPAREL","GATE 2","TRIBOA","TOWER","KORYO","TECHNO","UA","NSD","PETRON","INDUSTRIAL","HARBOR POINT","MAIN GATE"];

/* FARE TABLE */
$fare_table=[
"SABANG"=>14.00,"ANVAYA"=>20.38,"MABAYO"=>30.28,"MINANGA"=>30.28,"MORONG GATE"=>41.06,
"GROUP 6"=>41.06,"IDESS"=>41.06,"APPAREL"=>51.84,"GATE 2"=>51.84,"TRIBOA"=>54.70,
"TOWER"=>54.70,"KORYO"=>65.26,"TECHNO"=>65.26,"UA"=>65.26,"NSD"=>69.66,"PETRON"=>69.66,
"INDUSTRIAL"=>69.66,"HARBOR POINT"=>75.50,"MAIN GATE"=>75.50,"MORONG TERMINAL"=>75.50,
"BINARITAN"=>75.50,"POBLACION"=>75.50,"HILLTOP"=>75.50
];

/* ROUTE */
$route_query=mbus_db_query($conn,"
SELECT routes.origin,routes.destination,buses.bus_number,schedule.departure_time,schedule.trip_status,
routes.origin_lat,routes.origin_lng,routes.destination_lat,routes.destination_lng
FROM schedule
JOIN routes ON schedule.route_id=routes.route_id
JOIN buses ON schedule.bus_id=buses.bus_id
WHERE schedule.schedule_id='$schedule_id'
");

$route_data=mbus_db_fetch_assoc($route_query);

if(!$route_data){die("Invalid schedule.");}

/* SCHEDULE VALIDATION - MINIMUM PREPARATION TIME (30 MINUTES) */
$min_preparation_time = 30; // minutes
$departure_time = strtotime($route_data['departure_time']);
$current_time = time();
$time_diff = ($departure_time - $current_time) / 60; // in minutes

if($time_diff < $min_preparation_time){
    die("Booking unavailable. Schedule departs in less than {$min_preparation_time} minutes. Please select a later schedule.");
}

/* STATUS */
if(in_array($route_data['trip_status'],['Departed','Arrived','Cancelled'])){
    die("Booking unavailable.");
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
$reserved=[];
$seat_sql="SELECT COUNT(*) AS total_reserved FROM reservation
WHERE schedule_id='$schedule_id'
AND(status='Paid' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL 10 MINUTE))
";
$reserved_query=mbus_db_query($conn,$seat_sql);

while($r=mbus_db_fetch_assoc($reserved_query)){$reserved[]=$r['total_reserved'];}

$available_seats=28-count($reserved);

/* RESERVE */
if(isset($_POST['confirm_reserve'])){

$pickup=mbus_db_escape($conn,$_POST['pickup']);
$destination=mbus_db_escape($conn,$_POST['destination']);
$seats=explode(",",$_POST['seats']);

foreach($seats as $seat){

$seat=intval($seat);

$check=mbus_db_query($conn,"
SELECT reservation_id FROM reservation
WHERE schedule_id='$schedule_id'
AND seat_number='$seat'
AND(status='Paid' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL 10 MINUTE))
");

if(mbus_db_num_rows($check)>0){continue;}

do{
$ticket_code=rand(1000,9999);
$check_code=mbus_db_query($conn,"SELECT reservation_id FROM reservation WHERE ticket_code='$ticket_code'");
}while(mbus_db_num_rows($check_code)>0);

mbus_db_query($conn,"
INSERT INTO reservation(ticket_code,user_id,schedule_id,seat_number,pickup_location,destination,reservation_date,created_at,status)
VALUES('$ticket_code','$user_id','$schedule_id','$seat','$pickup','$destination',NOW(),NOW(),'Pending')
");

// Log reservation creation
log_activity($conn, $user_id, ACTION_RESERVATION_CREATE, "Reservation created for schedule $schedule_id, seat $seat, ticket code: $ticket_code");

// Send email notification
$user_email_query = mbus_db_query($conn, "SELECT email FROM users WHERE user_id = '$user_id'");
$user_email_data = mbus_db_fetch_assoc($user_email_query);
$user_email = $user_email_data['email'];

$route_info = "$route_data[origin] → $route_data[destination]";
$departure_formatted = date('F d, Y g:i A', strtotime($route_data['departure_time']));

$email_subject = "Reservation Created - Ticket: $ticket_code";
$email_message = get_reservation_created_email($ticket_code, $departure_formatted, $route_info);
send_email_notification($user_email, $email_subject, $email_message);

}

echo "ok";
exit();
}
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reserve Seat</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../Assets/css/modern_theme.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_reserve.css">

<!-- Google Maps API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initReserveMap">
</script>

</head>

<body>

<div class="sidebar">

<div class="logo-container">
<img src="../Assets/images/mbus_logo.png" class="logo">
</div>

<h2>Commuter</h2>

<div class="menu-top">
<a href="commuter_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
<a href="my_reservations.php"><i class="fa fa-ticket"></i> My Reservations</a>
<a href="history.php"><i class="fa fa-history"></i> History</a>
</div>

<a href="../logout.php" class="logout-btn">
<i class="fa fa-sign-out"></i> Logout
</a>

</div>

<div class="main">

<div class="page-title">Select Your Seat</div>

<a href="schedule.php" class="back-btn">← Back to Bus Schedules</a>

<div class="layout">

<div class="left-panel">

<div class="container">

<div class="top-info glass-card">
<div class="route-box"><?= $route_data['origin']; ?> → <?= $route_data['destination']; ?></div>
<div class="counter">Available Seats: <?= $available_seats; ?>/28</div>
</div>

<?php if($route_data['origin_lat'] && $route_data['origin_lng'] && $route_data['destination_lat'] && $route_data['destination_lng']){ ?>
<div class="route-map-container">
    <div id="reserveMap" style="height: 300px; width: 100%; border-radius: 8px;"></div>
</div>
<?php } ?>

<div class="legend">
<div><div class="box available-box"></div>Available</div>
<div><div class="box selected-box"></div>Selected</div>
<div><div class="box reserved-box"></div>Reserved</div>
</div>

<?php
$seat=1;
for($r=1;$r<=7;$r++){
echo "<div class='row'>";
for($c=1;$c<=4;$c++){
if($seat<=28){
$class="seat";$disabled="";
if(in_array($seat,$reserved)){$class.=" reserved";$disabled="disabled";}
echo "<button type='button' class='$class' data-seat='$seat' $disabled>$seat</button>";
$seat++;
}
if($c==2){echo "<div class='aisle'></div>";}
}
echo "</div>";
}
?>

<label>Pickup Location</label>
<select id="pickup">
<option value="">Select Pickup</option>
<?php foreach($pickup_stops as $stop){echo "<option value='$stop'>$stop</option>";} ?>
</select>

<label>Destination</label>
<select id="destination">
<option value="">Select Destination</option>
</select>

<button type="button" id="doneBtn">Done</button>

</div>

</div>

<div class="right-panel" id="summaryPanel">

<h2>Reservation Summary</h2>

<div class="notice success" id="successBox"></div>
<div class="notice error" id="errorBox"></div>

<p><b>Bus:</b> <?= $route_data['bus_number']; ?></p>
<p><b>Departure:</b> <?= $route_data['departure_time']; ?></p>
<p><b>Seats:</b> <span id="summarySeats">-</span></p>
<p><b>Pickup:</b> <span id="summaryPickup">-</span></p>
<p><b>Destination:</b> <span id="summaryDestination">-</span></p>

<div id="passengerTypes"></div>

<button type="button" id="calculateBtn">Calculate Fare</button>

<div class="breakdown" id="fareBreakdown">
<div id="fareDetails"></div>
<hr>
<h3>Total: ₱<span id="totalAmount">0.00</span></h3>
<button type="button" id="reserveBtn">Reserve For 10 Minutes</button>
<button type="button" id="payBtn">Pay Now</button>
</div>

</div>

</div>

</div>

<script>
const scheduleId = <?= $schedule_id; ?>;
const route = <?= json_encode($stops); ?>;
const fareTable = <?= json_encode($fare_table); ?>;
</script>

<script>
let reserveMap;
let reserveDirectionsService;
let reserveDirectionsRenderer;

function initReserveMap() {
    <?php if($route_data['origin_lat'] && $route_data['origin_lng'] && $route_data['destination_lat'] && $route_data['destination_lng']){ ?>
    const originLat = <?= $route_data['origin_lat']; ?>;
    const originLng = <?= $route_data['origin_lng']; ?>;
    const destLat = <?= $route_data['destination_lat']; ?>;
    const destLng = <?= $route_data['destination_lng']; ?>;

    reserveMap = new google.maps.Map(document.getElementById("reserveMap"), {
        center: { lat: (originLat + destLat) / 2, lng: (originLng + destLng) / 2 },
        zoom: 10,
    });

    reserveDirectionsService = new google.maps.DirectionsService();
    reserveDirectionsRenderer = new google.maps.DirectionsRenderer({
        map: reserveMap
    });

    // Create markers
    const originMarker = new google.maps.Marker({
        position: { lat: originLat, lng: originLng },
        map: reserveMap,
        title: "<?= $route_data['origin']; ?>",
        label: "A"
    });

    const destMarker = new google.maps.Marker({
        position: { lat: destLat, lng: destLng },
        map: reserveMap,
        title: "<?= $route_data['destination']; ?>",
        label: "B"
    });

    // Draw route
    const request = {
        origin: { lat: originLat, lng: originLng },
        destination: { lat: destLat, lng: destLng },
        travelMode: google.maps.TravelMode.DRIVING
    };

    reserveDirectionsService.route(request, function(result, status) {
        if (status === "OK") {
            reserveDirectionsRenderer.setDirections(result);

            // Fit bounds to show entire route
            const bounds = new google.maps.LatLngBounds();
            bounds.extend({ lat: originLat, lng: originLng });
            bounds.extend({ lat: destLat, lng: destLng });
            reserveMap.fitBounds(bounds);
        }
    });
    <?php } ?>
}
</script>

<script src="../Assets/js/commuter_reserve.js"></script>

</body>
</html>