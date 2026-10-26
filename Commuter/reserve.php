<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";

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

// Authorization check - verify schedule exists and is bookable
$schedule_check = mbus_db_prepare($conn,"
    SELECT schedule_id, schedule_status, trip_status, departure_time
    FROM schedule
    WHERE schedule_id = ?
", [$schedule_id]);

$schedule_info = mbus_db_fetch_assoc($schedule_check);

if(!$schedule_info){
    die("Invalid schedule.");
}

if($schedule_info['schedule_status'] != 'Active'){
    die("Schedule is not available for booking.");
}

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
    // Validate CSRF token
    if(!csrf_validate_token()){
        die("Security validation failed. Please refresh the page and try again.");
    }

$pickup=mbus_db_escape($conn,$_POST['pickup']);
$destination=mbus_db_escape($conn,$_POST['destination']);
$seats=explode(",",$_POST['seats']);

foreach($seats as $seat){

$seat=intval($seat);

// Use transaction to prevent race condition
$conn->pdo->beginTransaction();

try{
    $check=mbus_db_prepare($conn,"
SELECT reservation_id FROM reservation
WHERE schedule_id = ?
AND seat_number = ?
AND(status='Paid' OR status='Boarded' OR(status='Pending' AND created_at>NOW()-INTERVAL 10 MINUTE))
FOR UPDATE
", [$schedule_id, $seat]);

    if(mbus_db_num_rows($check)>0){
        $conn->pdo->rollBack();
        continue;
    }

    do{
        $ticket_code=rand(1000,9999);
        $check_code=mbus_db_prepare($conn,"SELECT reservation_id FROM reservation WHERE ticket_code = ?", [$ticket_code]);
    }while(mbus_db_num_rows($check_code)>0);

    mbus_db_prepare($conn,"
INSERT INTO reservation(ticket_code,user_id,schedule_id,seat_number,pickup_location,destination,reservation_date,created_at,status)
VALUES(?, ?, ?, ?, ?, ?, NOW(), NOW(), 'Pending')
", [$ticket_code, $user_id, $schedule_id, $seat, $pickup, $destination]);

    $conn->pdo->commit();

} catch(Exception $e){
    $conn->pdo->rollBack();
    continue;
}

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

<!-- Google Maps API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=<?php echo getenv('GOOGLE_MAPS_API_KEY') ?: 'YOUR_API_KEY'; ?>&callback=initReserveMap">
</script>

</head>

<body class="has-sidebar">

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle btn btn-icon" onclick="toggleSidebar()" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebar">
    <i class="fa fa-bars" aria-hidden="true"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

<div class="logo-container">
<img src="../Assets/images/mbus_logo.png" class="logo" alt="MBUS Logo">
</div>

<h2>Commuter</h2>

<nav class="menu-top" aria-label="Commuter menu">
<a href="commuter_dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> Dashboard</a>
<a href="my_reservations.php"><i class="fa fa-ticket" aria-hidden="true"></i> My Reservations</a>
<a href="history.php"><i class="fa fa-history" aria-hidden="true"></i> History</a>
<a href="profile.php"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
<a href="verification.php"><i class="fa fa-id-card" aria-hidden="true"></i> Verification</a>
</nav>

<a href="../logout.php" class="logout-btn btn btn-outline btn-block">
<i class="fa fa-sign-out" aria-hidden="true"></i> Logout
</a>

</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Select Your Seat</h1>
    <a href="schedule.php" class="btn btn-outline btn-sm">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> Back to Schedules
    </a>
</header>

<div class="reserve-layout">

<!-- LEFT PANEL -->
<div class="reserve-panel reserve-panel-main">

<div class="card trip-info-card">

<div class="route-header">
    <span class="route-badge"><?php echo htmlspecialchars($route_data['origin']); ?> &#8594; <?php echo htmlspecialchars($route_data['destination']); ?></span>
    <span class="seat-counter"><?php echo $available_seats; ?>/28 seats available</span>
</div>

<?php if($route_data['origin_lat'] && $route_data['origin_lng'] && $route_data['destination_lat'] && $route_data['destination_lng']){ ?>
<div class="route-map-container">
    <div id="reserveMap" class="map-canvas" style="height: 250px;"></div>
</div>
<?php } ?>

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

<div class="seat-map" role="grid" aria-label="Bus seat map">

<?php
$seat=1;
for($r=1;$r<=7;$r++){
echo "<div class='seat-row' role='row'>";
for($c=1;$c<=4;$c++){
if($seat<=28){
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
    <i class="fa fa-check" aria-hidden="true"></i> Done Selecting Seats
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
    <i class="fa fa-calculator" aria-hidden="true"></i> Calculate Fare
</button>

<div class="fare-breakdown" id="fareBreakdown" style="display:none;">
    <div id="fareDetails"></div>
    <div class="fare-total">
        <strong>Total: <span id="totalAmount">&#8369;0.00</span></strong>
    </div>
    <button type="button" id="reserveBtn" class="btn btn-primary btn-block">
        <i class="fa fa-clock" aria-hidden="true"></i> Reserve For 10 Minutes
    </button>
    <a href="payment.php?schedule_id=<?php echo $schedule_id; ?>&total=0" id="payBtn" class="btn btn-success btn-block" style="display:none;">
        <i class="fa fa-credit-card" aria-hidden="true"></i> Pay Now
    </a>
</div>

</div>

</div>

</div>

</main>

<script>
const scheduleId = <?php echo $schedule_id; ?>;
const route = <?php echo json_encode($stops); ?>;
const fareTable = <?php echo json_encode($fare_table); ?>;
</script>

<script>
let reserveMap;
let reserveDirectionsService;
let reserveDirectionsRenderer;

function initReserveMap() {
    <?php if($route_data['origin_lat'] && $route_data['origin_lng'] && $route_data['destination_lat'] && $route_data['destination_lng']){ ?>
    const originLat = <?php echo $route_data['origin_lat']; ?>;
    const originLng = <?php echo $route_data['origin_lng']; ?>;
    const destLat = <?php echo $route_data['destination_lat']; ?>;
    const destLng = <?php echo $route_data['destination_lng']; ?>;

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
        title: "<?php echo $route_data['origin']; ?>",
        label: "A"
    });

    const destMarker = new google.maps.Marker({
        position: { lat: destLat, lng: destLng },
        map: reserveMap,
        title: "<?php echo $route_data['destination']; ?>",
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

<script>
let notificationDropdownOpen = false;

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.sidebar-toggle');
    
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    
    if (toggle) {
        toggle.setAttribute('aria-expanded', isOpen);
    }
    
    // Add/remove body class for CSS to hide hamburger
    document.body.classList.toggle('sidebar-open', isOpen);
    
    // Trap focus when sidebar is open on mobile
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        const firstLink = sidebar.querySelector('a, button');
        if (firstLink) firstLink.focus();
    } else {
        document.body.style.overflow = '';
    }
}

// Toggle notification dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const bell = document.querySelector('.notification-bell');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.add('active');
        if (bell) bell.setAttribute('aria-expanded', 'true');
        loadNotifications();
    } else {
        dropdown.classList.remove('active');
        if (bell) bell.setAttribute('aria-expanded', 'false');
    }
}

// Load notifications
function loadNotifications() {
    fetch('../api/get_notifications.php')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.unread_count);
            renderNotifications(data.notifications);
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
        });
}

// Update notification badge
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// Render notifications
function renderNotifications(notifications) {
    const list = document.getElementById('notificationList');
    
    if (notifications.length === 0) {
        list.innerHTML = '<div class="notification-empty">No notifications</div>';
        return;
    }
    
    list.innerHTML = notifications.map(notif => `
        <div class="notification-item ${notif.status === 'Unread' ? 'unread' : ''}" 
             onclick="markAsRead(${notif.id})" role="listitem">
            <div class="notification-message">${notif.message}</div>
            <div class="notification-time">${notif.time_ago}</div>
        </div>
    `).join('');
}

// Mark notification as read
function markAsRead(notificationId) {
    const formData = new FormData();
    formData.append('notification_id', notificationId);
    
    fetch('../api/mark_notification_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const container = document.querySelector('.notification-container');
    if (!container.contains(event.target) && notificationDropdownOpen) {
        toggleNotifications();
    }
});

// Close sidebar when clicking a nav link on mobile
document.querySelectorAll('.menu-top a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();

// Handle keyboard navigation for sidebar
document.addEventListener('keydown', function(e) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar.classList.contains('open') && e.key === 'Escape') {
        toggleSidebar();
    }
});
</script>

</body>
</html>