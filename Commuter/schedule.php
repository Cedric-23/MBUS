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
    $where="WHERE schedule.departure_time >= NOW() + INTERVAL '30 minutes' AND DATE(schedule.departure_time)=CURRENT_DATE";
}
elseif($filter=="tomorrow"){
    $where="WHERE DATE(schedule.departure_time)=CURRENT_DATE + INTERVAL '1 day'";
}
elseif($filter=="thisweek"){
    $where="WHERE " . mbus_iso_year_week('schedule.departure_time') . " = " . mbus_iso_year_week('CURRENT_DATE::timestamp') . " AND schedule.departure_time >= NOW() + INTERVAL '30 minutes'";
    if($day!=""){$where.=" AND LOWER(TRIM(TO_CHAR(schedule.departure_time, 'FMDay')))='".strtolower($day)."'";}
}
elseif($filter=="nextweek"){
    $where="WHERE " . mbus_iso_year_week('schedule.departure_time') . " = " . mbus_iso_year_week("(CURRENT_DATE + INTERVAL '1 week')::timestamp") . "";
    if($day!=""){$where.=" AND LOWER(TRIM(TO_CHAR(schedule.departure_time, 'FMDay')))='".strtolower($day)."'";}
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
<link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
<link rel="stylesheet" href="../Assets/css/professional-filters.css">
<link rel="stylesheet" href="../Assets/css/bottom-nav.css">

<!-- FullCalendar CSS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet'>

<!-- Google Maps API -->
<script async defer
src="https://maps.googleapis.com/maps/api/js?key=<?php echo getenv('GOOGLE_MAPS_API_KEY') ?: 'YOUR_API_KEY'; ?>&callback=initScheduleMap">
</script>

<!-- FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
</head>

<body class="has-bottom-nav">

<main class="main" role="main">

<header class="page-header">
    <h1>Bus Schedules</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
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

<!-- Route Map Container -->
<div id="scheduleMapContainer" class="map-container">
    <div id="scheduleMap" class="map-canvas" style="display: none;"></div>
    <div id="mapPlaceholder" class="map-placeholder">
        <p>Click "View Route" on any schedule to see the route on the map</p>
    </div>
</div>

<!-- List View -->
<div id="listView">
<div class="date-filter-container">
<nav class="date-filters" aria-label="Schedule filters">
<a href="schedule.php?filter=today" class="date-filter-btn <?php if($filter=="today")echo"active"; ?>" aria-current="<?php echo $filter=="today" ? "page" : "false"; ?>">
<i class="fa fa-calendar-day" aria-hidden="true"></i>Today
</a>
<a href="schedule.php?filter=tomorrow" class="date-filter-btn <?php if($filter=="tomorrow")echo"active"; ?>" aria-current="<?php echo $filter=="tomorrow" ? "page" : "false"; ?>">
<i class="fa fa-calendar-plus" aria-hidden="true"></i>Tomorrow
</a>
<a href="schedule.php?filter=thisweek" class="date-filter-btn <?php if($filter=="thisweek")echo"active"; ?>" aria-current="<?php echo $filter=="thisweek" ? "page" : "false"; ?>">
<i class="fa fa-calendar-week" aria-hidden="true"></i>This Week
</a>
<a href="schedule.php?filter=nextweek" class="date-filter-btn <?php if($filter=="nextweek")echo"active"; ?>" aria-current="<?php echo $filter=="nextweek" ? "page" : "false"; ?>">
<i class="fa fa-calendar-alt" aria-hidden="true"></i>Next Week
</a>
</nav>
</div>

<?php if($filter=="thisweek"){ ?>

<div class="day-tabs-container">
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

}
</script>

<!-- Bottom Navigation -->
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i>
        <span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-calendar" aria-hidden="true"></i>
        <span>Schedule</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="notifications.php" class="bottom-nav-item">
        <i class="fa fa-bell" aria-hidden="true"></i>
        <span>Alerts</span>
    </a>
</nav>

<script src="../Assets/js/app-header.js"></script>
</body>
</html>