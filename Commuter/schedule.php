<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

/* FILTERS */
$filter = isset($_GET['filter']) ? $_GET['filter'] : "today";
$day = isset($_GET['day']) ? $_GET['day'] : "";

/* AUTO CURRENT DAY */
$currentDayName = date("l");

if($filter=="thisweek" && $day==""){
    $day=$currentDayName;
}

/* WHERE */
$where="";

if($filter=="today"){
    $where="WHERE schedule.departure_time >= NOW() + INTERVAL 30 MINUTE AND DATE(schedule.departure_time)=CURDATE()";
}
elseif($filter=="tomorrow"){
    $where="WHERE DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)";
}
elseif($filter=="thisweek"){
    $where="WHERE YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1) AND schedule.departure_time >= NOW() + INTERVAL 30 MINUTE";
    if($day!=""){$where.=" AND DAYNAME(schedule.departure_time)='$day'";}
}
elseif($filter=="nextweek"){
    $where="WHERE YEARWEEK(schedule.departure_time,1)=YEARWEEK(DATE_ADD(CURDATE(),INTERVAL 1 WEEK),1)";
    if($day!=""){$where.=" AND DAYNAME(schedule.departure_time)='$day'";}
}
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Bus Schedule</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../Assets/css/modern_theme.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_schedule.css">

<!-- FullCalendar CSS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet'>

<!-- Google Maps API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initScheduleMap">
</script>

<!-- FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

</head>

<body>

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" onclick="toggleSidebar()">
    <i class="fa fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar">

<div class="logo-container">
<img src="../Assets/images/mbus_logo.png" class="logo">
</div>

<h2>Commuter</h2>

<div class="menu-top">
<a href="commuter_dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
<a href="my_reservations.php"><i class="fa fa-ticket"></i> My Reservations</a>
<a href="history.php"><i class="fa fa-history"></i> History</a>
<a href="profile.php"><i class="fa fa-user"></i> My Profile</a>
<a href="verification.php"><i class="fa fa-id-card"></i> Verification</a>
            
<!-- Notification Bell -->
<div class="notification-container">
    <div class="notification-bell" onclick="toggleNotifications()">
        <i class="fa fa-bell"></i>
        <span class="notification-badge" id="notificationBadge">0</span>
    </div>
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-dropdown-header">
            Notifications
        </div>
        <div id="notificationList">
            <div class="notification-empty">Loading notifications...</div>
        </div>
    </div>
</div>
</div>

<a href="../logout.php" class="logout-btn">
<i class="fa fa-sign-out"></i> Logout
</a>

</div>

<div class="main">

<h1>Bus Schedules</h1>

<!-- View Toggle -->
<div class="view-toggle">
    <button class="toggle-btn active" onclick="showListView()">
        <i class="fa fa-list"></i> List View
    </button>
    <button class="toggle-btn" onclick="showCalendarView()">
        <i class="fa fa-calendar"></i> Calendar View
    </button>
</div>

<!-- Route Map Container -->
<div id="scheduleMapContainer" style="margin-bottom: 20px;">
    <div id="scheduleMap" style="height: 350px; width: 100%; border-radius: 8px; display: none;"></div>
    <div id="mapPlaceholder" style="height: 350px; width: 100%; border-radius: 8px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #666;">
        <p>Click "View Route" on any schedule to see the route on the map</p>
    </div>
</div>

<!-- List View -->
<div id="listView">
<div class="tabs">
<a href="schedule.php?filter=today" class="tab <?php if($filter=="today")echo"active"; ?>">Today</a>
<a href="schedule.php?filter=tomorrow" class="tab <?php if($filter=="tomorrow")echo"active"; ?>">Tomorrow</a>
<a href="schedule.php?filter=thisweek" class="tab <?php if($filter=="thisweek")echo"active"; ?>">This Week</a>
<a href="schedule.php?filter=nextweek" class="tab <?php if($filter=="nextweek")echo"active"; ?>">Next Week</a>
</div>

<?php if($filter=="thisweek"){ ?>

<div class="day-tabs">
<?php
$current_day=date("N");
$days=[1=>"Monday",2=>"Tuesday",3=>"Wednesday",4=>"Thursday",5=>"Friday",6=>"Saturday",7=>"Sunday"];

for($i=$current_day;$i<=7;$i++){
?>
<a href="schedule.php?filter=thisweek&day=<?php echo $days[$i]; ?>" class="day-tab <?php if($day==$days[$i])echo"day-active"; ?>">
<?php echo $days[$i]; ?>
</a>
<?php } ?>
</div>

<?php } elseif($filter=="nextweek"){ ?>

<div class="day-tabs">
<?php
$days=["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
foreach($days as $d){
?>
<a href="schedule.php?filter=nextweek&day=<?php echo $d; ?>" class="day-tab <?php if($day==$d)echo"day-active"; ?>">
<?php echo $d; ?>
</a>
<?php } ?>
</div>

<?php } ?>

<?php
$sql="SELECT schedule.schedule_id,buses.bus_number,routes.origin,routes.destination,
schedule.departure_time,schedule.arrival_time,schedule.schedule_status,
routes.origin_lat,routes.origin_lng,routes.destination_lat,routes.destination_lng
FROM schedule
JOIN buses ON schedule.bus_id=buses.bus_id
JOIN routes ON schedule.route_id=routes.route_id
$where
ORDER BY schedule.departure_time ASC";

$result=mbus_db_query($conn,$sql);
?>

<?php if(mbus_db_num_rows($result)>0){ ?>

<table class="table-modern">

<tr>
<th>Bus Number</th>
<th>Route</th>
<th>Departure</th>
<th>Arrival</th>
<th>Status</th>
<th>Available Seats</th>
<th>Map</th>
<th>Action</th>
</tr>

<?php while($row=mbus_db_fetch_assoc($result)){
$schedule_id=$row['schedule_id'];

$seat_sql="SELECT COUNT(*) AS total_reserved FROM reservation WHERE schedule_id='$schedule_id' AND status!='Cancelled'";
$seat_result=mbus_db_query($conn,$seat_sql);
$seat_data=mbus_db_fetch_assoc($seat_result);

$reserved=$seat_data['total_reserved'];
$available=28-$reserved;
?>

<tr data-schedule-id="<?php echo $schedule_id; ?>">

<td><?php echo $row['bus_number']; ?></td>

<td>
<span class="route-badge"><?php echo $row['origin']; ?> → <?php echo $row['destination']; ?></span>
</td>

<td><?php echo date("F d, g:i A",strtotime($row['departure_time'])); ?></td>
<td><?php echo date("F d, g:i A",strtotime($row['arrival_time'])); ?></td>

<td>
<?php
$status=strtolower($row['schedule_status']);
if($status=="active"){echo"<span class='badge active-badge'>Active</span>";}
elseif($status=="cancelled"){echo"<span class='badge cancelled-badge'>Cancelled</span>";}
elseif($status=="delayed"){echo"<span class='badge delayed-badge'>Delayed</span>";}
else{echo"<span class='badge'>".$row['schedule_status']."</span>";}
?>
</td>

<td class="seat-availability">
<?php
if($available<=0){echo"<span class='full'>FULL</span>";}
else{echo"<span class='available'>$available / 28</span>";}
?>
</td>

<td>
<?php if($row['origin_lat'] && $row['origin_lng'] && $row['destination_lat'] && $row['destination_lng']){ ?>
<button type="button" class="btn-modern btn-secondary" onclick="viewRoute(<?php echo $row['origin_lat']; ?>, <?php echo $row['origin_lng']; ?>, <?php echo $row['destination_lat']; ?>, <?php echo $row['destination_lng']; ?>, '<?php echo $row['origin']; ?>', '<?php echo $row['destination']; ?>')">
<i class="fa fa-map"></i> View Route
</button>
<?php } else { ?>
<span style="color: #999; font-size: 12px;">No map data</span>
<?php } ?>
</td>

<td>
<?php if($available<=0){ ?>
<button disabled class="btn-modern" style="background: #ccc; cursor: not-allowed;">Full</button>
<?php }else{ ?>
<a href="reserve.php?schedule_id=<?php echo $schedule_id; ?>" class="btn-modern btn-primary">
Select
</a>
<?php } ?>
</td>

</tr>

<?php } ?>

</table>

<?php }else{ ?>

<div class="no-data">
<h3>No schedules available.</h3>
<p>There are currently no available schedules for this selected filter.</p>
</div>

<?php } ?>

</div>
</div>

<!-- Calendar View -->
<div id="calendarView" style="display: none;">
    <div id="calendar"></div>
</div>

</div>

</div>

<script>
let scheduleMap;
let scheduleDirectionsService;
let scheduleDirectionsRenderer;

function initScheduleMap() {
    scheduleMap = new google.maps.Map(document.getElementById("scheduleMap"), {
        center: { lat: 14.5995, lng: 120.9842 },
        zoom: 10,
    });

    scheduleDirectionsService = new google.maps.DirectionsService();
    scheduleDirectionsRenderer = new google.maps.DirectionsRenderer({
        map: scheduleMap
    });
}

function viewRoute(originLat, originLng, destLat, destLng, originName, destName) {
    // Show map, hide placeholder
    document.getElementById("scheduleMap").style.display = "block";
    document.getElementById("mapPlaceholder").style.display = "none";

    // Clear previous route
    if (scheduleDirectionsRenderer) {
        scheduleDirectionsRenderer.setDirections({routes: []});
    }

    // Create markers
    const originMarker = new google.maps.Marker({
        position: { lat: originLat, lng: originLng },
        map: scheduleMap,
        title: originName,
        label: "A"
    });

    const destMarker = new google.maps.Marker({
        position: { lat: destLat, lng: destLng },
        map: scheduleMap,
        title: destName,
        label: "B"
    });

    // Draw route
    const request = {
        origin: { lat: originLat, lng: originLng },
        destination: { lat: destLat, lng: destLng },
        travelMode: google.maps.TravelMode.DRIVING
    };

    scheduleDirectionsService.route(request, function(result, status) {
        if (status === "OK") {
            scheduleDirectionsRenderer.setDirections(result);

            // Fit bounds to show entire route
            const bounds = new google.maps.LatLngBounds();
            bounds.extend({ lat: originLat, lng: originLng });
            bounds.extend({ lat: destLat, lng: destLng });
            scheduleMap.fitBounds(bounds);
        }
    });

    // Scroll to map
    document.getElementById("scheduleMapContainer").scrollIntoView({ behavior: "smooth" });
}
</script>

<style>
.view-route-btn {
    background: #4285f4;
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: background 0.3s;
}

.view-route-btn:hover {
    background: #3367d6;
}

.route-map-container {
    margin-bottom: 20px;
}

/* View Toggle */
.view-toggle {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.toggle-btn {
    padding: 10px 20px;
    background: #1e3a5f;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.3s;
}

.toggle-btn:hover {
    background: #2d4f7a;
}

.toggle-btn.active {
    background: #1e90ff;
}

.toggle-btn i {
    font-size: 16px;
}

/* Calendar Styling */
#calendar {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>

<script>
let notificationDropdownOpen = false;

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}

// Toggle notification dropdown
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.add('active');
        loadNotifications();
    } else {
        dropdown.classList.remove('active');
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
             onclick="markAsRead(${notif.id})">
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

// Auto-refresh notifications every 30 seconds
setInterval(loadNotifications, 30000);

// Initial load
loadNotifications();

// Live seat availability updates
function updateSeatAvailability() {
    const rows = document.querySelectorAll('tr[data-schedule-id]');
    rows.forEach(row => {
        const scheduleId = row.getAttribute('data-schedule-id');
        const seatCell = row.querySelector('.seat-availability');
        
        fetch(`../api/get_seat_availability.php?schedule_id=${scheduleId}`)
            .then(response => response.json())
            .then(data => {
                if (data.available <= 0) {
                    seatCell.innerHTML = '<span class="full">FULL</span>';
                } else {
                    seatCell.innerHTML = `<span class="available">${data.available} / 28</span>`;
                }
            })
            .catch(error => {
                console.error('Error updating seat availability:', error);
            });
    });
}

// Poll seat availability every 15 seconds
setInterval(updateSeatAvailability, 15000);

// Initial seat availability update
setTimeout(updateSeatAvailability, 2000);

// View Toggle Functions
function showListView() {
    document.getElementById('listView').style.display = 'block';
    document.getElementById('calendarView').style.display = 'none';
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.toggle-btn:first-child').classList.add('active');
}

function showCalendarView() {
    document.getElementById('listView').style.display = 'none';
    document.getElementById('calendarView').style.display = 'block';
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.toggle-btn:last-child').classList.add('active');
    
    if (!calendar) {
        initCalendar();
    }
}

// Initialize FullCalendar
let calendar;

function initCalendar() {
    const calendarEl = document.getElementById('calendar');
    
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: '../api/get_calendar_events.php',
        eventClick: function(info) {
            const props = info.event.extendedProps;
            if (props.available_seats > 0) {
                if (confirm(`Book ${props.bus_number} - ${props.origin} → ${props.destination}?\nDeparture: ${props.departure_time}\nAvailable Seats: ${props.available_seats}`)) {
                    window.location.href = `reserve.php?schedule_id=${info.event.id}`;
                }
            } else {
                alert('This schedule is fully booked.');
            }
        },
        eventDidMount: function(info) {
            const props = info.event.extendedProps;
            info.el.title = `${props.bus_number} - ${props.origin} → ${props.destination}\nDeparture: ${props.departure_time}\nAvailable: ${props.available_seats}/28`;
        },
        height: 'auto',
        eventColor: '#1e90ff'
    });
    
    calendar.render();
}
</script>

</body>
</html>