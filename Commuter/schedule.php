<?php
session_start();
include "../config/db_connect.php";
require_once __DIR__ . '/../Includes/schedule_booking.php';
require_once __DIR__ . '/../Includes/route_locations.php';

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

/* FILTERS */
$filter = isset($_GET['filter']) ? $_GET['filter'] : "all";
$allowed_filters=["all","today","tomorrow","thisweek","nextweek"];
if(!in_array($filter, $allowed_filters, true)){ $filter="all"; }
$day = isset($_GET['day']) && is_scalar($_GET['day']) ? trim((string)$_GET['day']) : "";
$allowed_days=["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
$day_match=array_values(array_filter($allowed_days, function($name) use ($day){ return strtolower($name) === strtolower($day); }));
$day=$day_match[0] ?? "";

/* AUTO CURRENT DAY */
$currentDayName = date("l");

if($filter=="thisweek" && $day==""){
    $day=$currentDayName;
}

/* WHERE
 * Schedule times are stored as local (Asia/Manila) timestamps, so the date
 * boundaries are computed here in PHP (app timezone) instead of using the
 * database's NOW()/CURRENT_DATE, which follow the DB session timezone and can
 * be UTC behind a connection pooler. These values are server-generated, not user input.
 */
$tzNow = new DateTimeImmutable('now');
$nowSql = $tzNow->format('Y-m-d H:i:s');
$todayStart = $tzNow->setTime(0, 0, 0);
$tomorrowStart = $todayStart->modify('+1 day');
$dayAfterStart = $todayStart->modify('+2 days');
$weekStart = $todayStart->modify('monday this week');      // ISO week starts Monday
$nextWeekStart = $weekStart->modify('+7 days');
$weekAfterNextStart = $weekStart->modify('+14 days');
$fmt = function(DateTimeImmutable $d){ return $d->format('Y-m-d H:i:s'); };

$where="";
$activeWhere="WHERE schedule.schedule_status='Active' AND schedule.trip_status IN ('Scheduled','Boarding','Departed')";

if($filter=="all"){
    $where=$activeWhere." AND schedule.departure_time >= '" . $fmt($todayStart) . "'";
}
elseif($filter=="today"){
    $where=$activeWhere." AND schedule.departure_time >= '" . $fmt($todayStart) . "' AND schedule.departure_time < '" . $fmt($tomorrowStart) . "'";
}
elseif($filter=="tomorrow"){
    $where=$activeWhere." AND schedule.departure_time >= '" . $fmt($tomorrowStart) . "' AND schedule.departure_time < '" . $fmt($dayAfterStart) . "'";
}
elseif($filter=="thisweek"){
    $where=$activeWhere." AND schedule.departure_time >= '" . $fmt($todayStart) . "' AND schedule.departure_time < '" . $fmt($nextWeekStart) . "'";
    if($day!=""){$where.=" AND LOWER(TRIM(TO_CHAR(schedule.departure_time, 'FMDay')))='".strtolower($day)."'";}
}
elseif($filter=="nextweek"){
    $where=$activeWhere." AND schedule.departure_time >= '" . $fmt($nextWeekStart) . "' AND schedule.departure_time < '" . $fmt($weekAfterNextStart) . "'";
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


<link rel="stylesheet" href="../Assets/css/direction_tabs.css">

<!-- FullCalendar JS -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>

<body class="has-bottom-nav schedule-page">

<main class="main" role="main">

<header class="page-header">
    <h1>Bus Schedules</h1>

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


<!-- List View -->
<div id="listView">
<div class="date-filter-container">
<nav class="date-filters" aria-label="Schedule filters">
<a href="schedule.php?filter=all" class="date-filter-btn <?php if($filter=="all")echo"active"; ?>" aria-current="<?php echo $filter=="all" ? "page" : "false"; ?>">
<i class="fa fa-list" aria-hidden="true"></i>All Upcoming
</a>
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
$buildListSql = function($coordColumns) use ($where){
    return "SELECT schedule.schedule_id,buses.bus_number,buses.capacity,routes.origin,routes.destination,
schedule.departure_time,schedule.arrival_time,schedule.schedule_status,schedule.trip_status,
$coordColumns
FROM schedule
JOIN buses ON schedule.bus_id=buses.bus_id
JOIN routes ON schedule.route_id=routes.route_id
$where
ORDER BY schedule.departure_time ASC";
};

$result=mbus_db_query($conn,$buildListSql("routes.origin_lat,routes.origin_lng,routes.destination_lat,routes.destination_lng"));

// The map coordinate columns (supabase/03_add_map_coordinates.sql) may be missing on a database
// that never ran that migration. That must not hide every schedule: retry without them
// (schedules then simply show without a "View Route" map button).
if($result === false){
    error_log("Commuter/schedule.php list query failed (filter=$filter), retrying without route coordinates: " . ($conn->lastError ?? 'unknown error'));
    $result=mbus_db_query($conn,$buildListSql("NULL AS origin_lat,NULL AS origin_lng,NULL AS destination_lat,NULL AS destination_lng"));
}

// mbus_db_query() returns false when the SQL itself fails. Keep that visible instead of
// showing it as an empty list ("No schedules available"); details are in the server log.
$query_failed = ($result === false);
if($query_failed){
    error_log("Commuter/schedule.php list query failed again without route coordinates (filter=$filter): " . ($conn->lastError ?? 'unknown error'));
}
?>

<?php if(mbus_db_num_rows($result)>0){
$today_key = $todayStart->format('Y-m-d');
$tomorrow_key = $tomorrowStart->format('Y-m-d');
$last_day_key = null;
?>

<div class="bus-list" role="list" aria-label="Bus schedules" data-schedule-table data-dir-list>
<?php while($row=mbus_db_fetch_assoc($result)){
$row = mbus_route_apply_terminal_defaults($row);
$schedule_id=$row['schedule_id'];

$seat_sql="SELECT COUNT(*) AS total_reserved FROM reservation
WHERE schedule_id=?
AND status IN ('Pending', 'Paid', 'Confirmed', 'Boarded')
AND (status <> 'Pending' OR created_at > NOW()-INTERVAL '10 minutes')";
$seat_result=mbus_db_prepare($conn,$seat_sql,[$schedule_id]);
$seat_data=$seat_result === false ? false : mbus_db_fetch_assoc($seat_result);
$reserved=(int)($seat_data['total_reserved'] ?? 0);
$capacity=(int)$row['capacity'];
$available=max(0,$capacity-$reserved);

$dep_ts=strtotime($row['departure_time']);
$arr_ts=strtotime($row['arrival_time']);
$day_key=date('Y-m-d',$dep_ts);
if($day_key!==$last_day_key){
    $last_day_key=$day_key;
    $heading = $day_key===$today_key ? 'Today' : ($day_key===$tomorrow_key ? 'Tomorrow' : date('l',$dep_ts));
?>
<h2 class="day-heading"><?php echo $heading; ?><time datetime="<?php echo $day_key; ?>"><?php echo date('M j',$dep_ts); ?></time></h2>
<?php }

$mins = ($arr_ts && $arr_ts > $dep_ts) ? (int)round(($arr_ts-$dep_ts)/60) : 0;
$duration = $mins>0 ? (intdiv($mins,60)>0 ? intdiv($mins,60).' hr'.($mins%60 ? ' '.($mins%60).' min' : '') : $mins.' min') : '';

$status=strtolower($row['schedule_status']);
$chip_class = $available<=0 ? 'full' : ($available<=5 ? 'low' : 'ok');
$action = $seat_data === false ? 'unavailable' : mbus_schedule_action($row, $available);
$unavailable_at = strtotime($day_key . ' +1 day');
if($row['trip_status'] !== 'Departed'){
    $unavailable_at = min($unavailable_at, max($dep_ts, $arr_ts ?: 0));
}
?>

<article class="bus-card<?php echo $available<=0 ? ' is-full' : ''; ?>" role="listitem" data-dir="<?php echo stripos((string)$row['origin'], 'morong') !== false ? 'm' : 'h'; ?>"
    data-schedule-id="<?php echo (int)$schedule_id; ?>" data-schedule-action="<?php echo $action; ?>"
    data-reserve-cutoff="<?php echo $dep_ts - 30 * 60; ?>" data-unavailable-at="<?php echo $unavailable_at; ?>">
    <div class="bus-card-top">
        <span class="bus-name"><i class="fa fa-bus" aria-hidden="true"></i><?php echo htmlspecialchars($row['bus_number']); ?></span>
        <?php if($status!=="active"){ ?>
            <span class="status-chip"><?php echo htmlspecialchars($row['schedule_status']); ?></span>
        <?php }else{ ?>
            <span class="seat-chip <?php echo $chip_class; ?>"><?php echo $available<=0 ? 'Fully booked' : $available.' seats left'; ?></span>
        <?php } ?>
    </div>

    <div class="timetable">
        <div class="stop">
            <span class="stop-time"><?php echo date('g:i',$dep_ts); ?><small><?php echo date('A',$dep_ts); ?></small></span>
            <span class="stop-dot" aria-hidden="true"></span>
            <span class="stop-name"><?php echo htmlspecialchars($row['origin_label']); ?></span>
        </div>
        <div class="leg">
            <span class="leg-note"><?php echo $duration ? htmlspecialchars($duration) : ''; ?></span>
            <span class="leg-line" aria-hidden="true"></span>
            <span></span>
        </div>
        <div class="stop">
            <span class="stop-time"><?php echo $arr_ts ? date('g:i',$arr_ts) : '--'; ?><small><?php echo $arr_ts ? date('A',$arr_ts) : ''; ?></small></span>
            <span class="stop-dot end" aria-hidden="true"></span>
            <span class="stop-name"><?php echo htmlspecialchars($row['destination_label']); ?></span>
        </div>
    </div>

    <div class="bus-actions">
        <a class="btn-reserve schedule-action-link<?php echo $action === 'unavailable' ? ' is-disabled' : ''; ?>"
            <?php if($action !== 'unavailable'){ ?>href="<?php echo $action === 'pickup' ? 'pickup.php' : 'reserve.php'; ?>?schedule_id=<?php echo (int)$schedule_id; ?>"<?php }else{ ?>aria-disabled="true" tabindex="-1"<?php } ?>><?php echo $action === 'reserve' ? 'Reserve seat' : ($action === 'pickup' ? 'Pick up' : 'Unavailable'); ?></a>
    </div>
</article>

<?php } ?>
</div>

<?php }elseif($query_failed){ ?>

<div class="card empty-state" role="alert">
<span class="empty-icon" aria-hidden="true"><i class="fa fa-triangle-exclamation"></i></span>
<h3>Schedules couldn't be loaded.</h3>
<p>Something went wrong while loading the schedule. Refresh the page or try again in a moment.</p>
<a class="empty-action" href="schedule.php?filter=<?php echo htmlspecialchars($filter); ?>">Refresh</a>
</div>

<?php }else{ ?>

<div class="card empty-state" role="status">
<span class="empty-icon" aria-hidden="true"><i class="fa fa-bus"></i></span>
<h3>No schedules available.</h3>
<p>Nothing is scheduled for this filter yet.</p>
<?php if($filter!=="all"){ ?><a class="empty-action" href="schedule.php?filter=all">See all upcoming</a><?php } ?>
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
let scheduleCalendar;
// Anchor to the server clock, not the phone's timezone or potentially skewed clock.
let scheduleClockOffset = <?php echo (int)round(microtime(true) * 1000); ?> - Date.now();
const scheduleCards = Array.from(document.querySelectorAll('[data-schedule-action]'));

function currentScheduleAction(action, reserveCutoff, unavailableAt) {
    const now = Math.floor((Date.now() + scheduleClockOffset) / 1000);
    if (action === 'unavailable' || now >= Number(unavailableAt)) return 'unavailable';
    // Exactly 30 minutes is still bookable; only strictly below the cutoff is pickup.
    return action === 'reserve' && now > Number(reserveCutoff) ? 'pickup' : action;
}

function updateScheduleCard(card) {
    const action = currentScheduleAction(card.dataset.scheduleAction, card.dataset.reserveCutoff, card.dataset.unavailableAt);
    const link = card.querySelector('.schedule-action-link');
    link.textContent = action === 'reserve' ? 'Reserve seat' : (action === 'pickup' ? 'Pick up' : 'Unavailable');
    link.classList.toggle('is-disabled', action === 'unavailable');
    if (action === 'unavailable') {
        link.removeAttribute('href');
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('tabindex', '-1');
    } else {
        link.href = (action === 'pickup' ? 'pickup.php' : 'reserve.php') + '?schedule_id=' + encodeURIComponent(card.dataset.scheduleId);
        link.removeAttribute('aria-disabled');
        link.removeAttribute('tabindex');
    }
    return action;
}

function updateScheduleCards() { scheduleCards.forEach(updateScheduleCard); }
updateScheduleCards();
setInterval(updateScheduleCards, 1000);
// Recheck at click time too, so a click between timer ticks cannot follow a stale CTA.
document.addEventListener('click', function(e) {
    const link = e.target.closest('.schedule-action-link');
    if (link && updateScheduleCard(link.closest('[data-schedule-action]')) === 'unavailable') e.preventDefault();
}, true);

let scheduleRefreshPending = false;
async function refreshScheduleActions() {
    if (scheduleRefreshPending || document.hidden) return;
    scheduleRefreshPending = true;
    try {
        if (scheduleCards.length) {
            const lastExpiry = Math.max(...scheduleCards.map(card => Number(card.dataset.unavailableAt)));
            const end = new Date(lastExpiry * 1000).toISOString();
            const response = await fetch('../api/get_calendar_events.php?start=<?php echo $todayStart->format('Y-m-d'); ?>&end=' + encodeURIComponent(end), {cache: 'no-store'});
            if (!response.ok) return;
            const events = await response.json();
            if (!Array.isArray(events)) return;
            const byId = new Map(events.map(event => [String(event.id), event.extendedProps]));
            if (events.length) scheduleClockOffset = Number(events[0].extendedProps.server_time) * 1000 - Date.now();
            scheduleCards.forEach(card => {
                const props = byId.get(card.dataset.scheduleId);
                card.dataset.scheduleAction = props ? props.action : 'unavailable';
                if (props) {
                    card.dataset.reserveCutoff = props.reserve_cutoff;
                    card.dataset.unavailableAt = props.unavailable_at;
                    card.classList.toggle('is-full', props.available_seats <= 0);
                    const chip = card.querySelector('.seat-chip');
                    if (chip) {
                        chip.textContent = props.available_seats <= 0 ? 'Fully booked' : props.available_seats + ' seats left';
                        chip.className = 'seat-chip ' + (props.available_seats <= 0 ? 'full' : (props.available_seats <= 5 ? 'low' : 'ok'));
                    }
                }
            });
            updateScheduleCards();
        }
        if (scheduleCalendar) scheduleCalendar.refetchEvents();
    } catch (error) {
        // Local clock-based cutoffs keep working during a temporary network failure.
    } finally {
        scheduleRefreshPending = false;
    }
}
setInterval(refreshScheduleActions, 30000);
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) { updateScheduleCards(); refreshScheduleActions(); }
});
window.addEventListener('pageshow', function(e) { if (e.persisted) { updateScheduleCards(); refreshScheduleActions(); } });


function initScheduleCalendar() {
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') return;
    scheduleCalendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: '../api/get_calendar_events.php',
        eventClick: function(info) {
            const props = info.event.extendedProps;
            const action = currentScheduleAction(props.action, props.reserve_cutoff, props.unavailable_at);
            info.jsEvent.preventDefault();
            if (action === 'pickup') {
                window.location.href = 'pickup.php?schedule_id=' + encodeURIComponent(info.event.id);
            } else if (action === 'reserve') {
                if (confirm(`Book ${props.bus_number} - ${props.origin} \u2192 ${props.destination}?\nDeparture: ${props.departure_time}\nAvailable Seats: ${props.available_seats}`)) {
                    window.location.href = 'reserve.php?schedule_id=' + encodeURIComponent(info.event.id);
                }
            } else {
                alert('This schedule is unavailable.');
            }
        },
        eventDidMount: function(info) {
            const props = info.event.extendedProps;
            info.el.title = `${props.bus_number} - ${props.origin} \u2192 ${props.destination}\nDeparture: ${props.departure_time}\nAvailable: ${props.available_seats}/${props.capacity}`;
        },
        height: 'auto',
        eventColor: '#1e90ff'
    });
    scheduleCalendar.render();
}

document.addEventListener('DOMContentLoaded', initScheduleCalendar);
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
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i>
        <span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>

<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/direction_tabs.js"></script>
</body>
</html>
