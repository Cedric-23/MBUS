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
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Bus Schedule</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Assets/css/responsive.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_schedule.css">

<!-- FullCalendar CSS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet'>

<!-- Google Maps API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=<?php echo getenv('GOOGLE_MAPS_API_KEY') ?: 'YOUR_API_KEY'; ?>&callback=initScheduleMap">
</script>

<!-- FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

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
            
<!-- Notification Bell -->
<div class="notification-container">
    <button class="notification-bell" onclick="toggleNotifications()" aria-label="Notifications" aria-expanded="false" aria-controls="notificationDropdown">
        <i class="fa fa-bell" aria-hidden="true"></i>
        <span class="notification-badge" id="notificationBadge" aria-live="polite">0</span>
    </button>
    <div class="notification-dropdown" id="notificationDropdown" role="menu" aria-label="Notifications">
        <div class="notification-dropdown-header">
            Notifications
        </div>
        <div id="notificationList" role="list">
            <div class="notification-empty">Loading notifications...</div>
        </div>
    </div>
</div>
</nav>

<a href="../logout.php" class="logout-btn btn btn-outline btn-block">
<i class="fa fa-sign-out" aria-hidden="true"></i> Logout
</a>

</aside>

<main class="main" role="main">

<header class="page-header">
    <h1>Bus Schedules</h1>
</header>

<!-- View Toggle -->
<div class="view-toggle" role="group" aria-label="View options">
    <button class="btn btn-secondary toggle-btn active" onclick="showListView()" aria-pressed="true">
        <i class="fa fa-list" aria-hidden="true"></i> List View
    </button>
    <button class="btn btn-secondary toggle-btn" onclick="showCalendarView()" aria-pressed="false">
        <i class="fa fa-calendar" aria-hidden="true"></i> Calendar View
    </button>
</div>

<!-- Route Map Container -->
<div id="scheduleMapContainer" class="map-container">
    <div id="scheduleMap" class="map-canvas" style="display: none;"></div>
    <div id="mapPlaceholder" class="map-placeholder">
        <p>Click "View Route" on any schedule to see the route on the map</p>
    </div>
</div>

<!-- List View -->
<div id="listView">
<nav class="tabs" aria-label="Schedule filters">
<a href="schedule.php?filter=today" class="tab <?php if($filter=="today")echo"active"; ?>" aria-current="<?php echo $filter=="today" ? "page" : "false"; ?>">Today</a>
<a href="schedule.php?filter=tomorrow" class="tab <?php if($filter=="tomorrow")echo"active"; ?>" aria-current="<?php echo $filter=="tomorrow" ? "page" : "false"; ?>">Tomorrow</a>
<a href="schedule.php?filter=thisweek" class="tab <?php if($filter=="thisweek")echo"active"; ?>" aria-current="<?php echo $filter=="thisweek" ? "page" : "false"; ?>">This Week</a>
<a href="schedule.php?filter=nextweek" class="tab <?php if($filter=="nextweek")echo"active"; ?>" aria-current="<?php echo $filter=="nextweek" ? "page" : "false"; ?>">Next Week</a>
</nav>

<?php if($filter=="thisweek"){ ?>

<div class="day-tabs" role="tablist" aria-label="Days this week">
<?php
$current_day=date("N");
$days=[1=>"Monday",2=>"Tuesday",3=>"Wednesday",4=>"Thursday",5=>"Friday",6=>"Saturday",7=>"Sunday"];

for($i=$current_day;$i<=7;$i++){
?>
<a href="schedule.php?filter=thisweek&day=<?php echo $days[$i]; ?>" class="day-tab <?php if($day==$days[$i])echo"day-active"; ?>" role="tab" aria-selected="<?php echo $day==$days[$i] ? "true" : "false"; ?>">
<?php echo $days[$i]; ?>
</a>
<?php } ?>
</div>

<?php } elseif($filter=="nextweek"){ ?>

<div class="day-tabs" role="tablist" aria-label="Days next week">
<?php
$days=["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
foreach($days as $d){
?>
<a href="schedule.php?filter=nextweek&day=<?php echo $d; ?>" class="day-tab <?php if($day==$d)echo"day-active"; ?>" role="tab" aria-selected="<?php echo $day==$d ? "true" : "false"; ?>">
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

<div class="table-responsive" role="region" aria-label="Bus schedules" tabindex="0">
<table class="table table-striped" data-schedule-table>

<thead>
<tr>
<th scope="col">Bus Number</th>
<th scope="col">Route</th>
<th scope="col">Departure</th>
<th scope="col">Arrival</th>
<th scope="col">Status</th>
<th scope="col">Available Seats</th>
<th scope="col">Map</th>
<th scope="col">Action</th>
</tr>
</thead>

<tbody>
<?php while($row=mbus_db_fetch_assoc($result)){
$schedule_id=$row['schedule_id'];

$seat_sql="SELECT COUNT(*) AS total_reserved FROM reservation WHERE schedule_id='$schedule_id' AND status!='Cancelled'";
$seat_result=mbus_db_query($conn,$seat_sql);
$seat_data=mbus_db_fetch_assoc($seat_result);

$reserved=$seat_data['total_reserved'];
$available=28-$reserved;
?>

<tr data-schedule-id="<?php echo $schedule_id; ?>">

<td data-label="Bus Number"><?php echo htmlspecialchars($row['bus_number']); ?></td>

<td data-label="Route">
<span class="badge badge-primary route-badge"><?php echo htmlspecialchars($row['origin']); ?> &#8594; <?php echo htmlspecialchars($row['destination']); ?></span>
</td>

<td data-label="Departure"><?php echo date("F d, g:i A",strtotime($row['departure_time'])); ?></td>
<td data-label="Arrival"><?php echo date("F d, g:i A",strtotime($row['arrival_time'])); ?></td>

<td data-label="Status">
<?php
$status=strtolower($row['schedule_status']);
if($status=="active"){echo"<span class='badge badge-success'>Active</span>";}
elseif($status=="cancelled"){echo"<span class='badge badge-error'>Cancelled</span>";}
elseif($status=="delayed"){echo"<span class='badge badge-warning'>Delayed</span>";}
else{echo"<span class='badge badge-secondary'>".htmlspecialchars($row['schedule_status'])."</span>";}
?>
</td>

<td data-label="Available Seats" class="seat-availability">
<?php
if($available<=0){echo"<span class='badge badge-error'>FULL</span>";}
else{echo"<span class='badge badge-success'>$available / 28</span>";}
?>
</td>

<td data-label="Map">
<?php if($row['origin_lat'] && $row['origin_lng'] && $row['destination_lat'] && $row['destination_lng']){ ?>
<button type="button" class="btn btn-sm btn-outline view-route-btn" onclick="viewRoute(<?php echo $row['origin_lat']; ?>, <?php echo $row['origin_lng']; ?>, <?php echo $row['destination_lat']; ?>, <?php echo $row['destination_lng']; ?>, '<?php echo htmlspecialchars($row['origin'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['destination'], ENT_QUOTES); ?>')">
<i class="fa fa-map" aria-hidden="true"></i> View Route
</button>
<?php } else { ?>
<span class="text-muted" style="font-size: var(--fs-xs);">No map data</span>
<?php } ?>
</td>

<td data-label="Action">
<?php if($available<=0){ ?>
<button type="button" class="btn btn-sm btn-disabled" disabled aria-disabled="true">Full</button>
<?php }else{ ?>
<a href="reserve.php?schedule_id=<?php echo $schedule_id; ?>" class="btn btn-sm btn-primary">Select</a>
<?php } ?>
</td>

</tr>

<?php } ?>

</tbody>
</table>
</div>

<?php }else{ ?>

<div class="card empty-state" role="status">
<h3>No schedules available.</h3>
<p>There are currently no available schedules for this selected filter.</p>
</div>

<?php } ?>

</div>
</div>

<!-- Calendar View -->
<div id="calendarView" class="calendar-view" style="display: none;" aria-hidden="true">
    <div id="calendar" class="card"></div>
</div>

</main>

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
                    seatCell.innerHTML = '<span class="badge badge-error">FULL</span>';
                } else {
                    seatCell.innerHTML = `<span class="badge badge-success">${data.available} / 28</span>`;
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
    document.getElementById('calendarView').setAttribute('aria-hidden', 'true');
    document.getElementById('listView').removeAttribute('aria-hidden');
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-pressed', 'false');
    });
    document.querySelector('.toggle-btn:first-child').classList.add('active');
    document.querySelector('.toggle-btn:first-child').setAttribute('aria-pressed', 'true');
}

function showCalendarView() {
    document.getElementById('listView').style.display = 'none';
    document.getElementById('calendarView').style.display = 'block';
    document.getElementById('listView').setAttribute('aria-hidden', 'true');
    document.getElementById('calendarView').removeAttribute('aria-hidden');
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-pressed', 'false');
    });
    document.querySelector('.toggle-btn:last-child').classList.add('active');
    document.querySelector('.toggle-btn:last-child').setAttribute('aria-pressed', 'true');
    
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
                if (confirm(`Book ${props.bus_number} - ${props.origin} \u2192 ${props.destination}?\nDeparture: ${props.departure_time}\nAvailable Seats: ${props.available_seats}`)) {
                    window.location.href = `reserve.php?schedule_id=${info.event.id}`;
                }
            } else {
                alert('This schedule is fully booked.');
            }
        },
        eventDidMount: function(info) {
            const props = info.event.extendedProps;
            info.el.title = `${props.bus_number} - ${props.origin} \u2192 ${props.destination}\nDeparture: ${props.departure_time}\nAvailable: ${props.available_seats}/28`;
        },
        height: 'auto',
        eventColor: '#1e90ff'
    });
    
    calendar.render();
}

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