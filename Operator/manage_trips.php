<?php
session_start();

if(
!isset($_SESSION['user_type'])
||
strtolower($_SESSION['user_type'])!='operator'
){
header("Location: ../login.php");
exit();
}

include("../config/db_connect.php");

$open_schedule=isset($_GET['open'])
?$_GET['open']
:'';

/* FILTER */

$filter=isset($_GET['filter'])
?$_GET['filter']
:'all';

$where_filter="";

if($filter=="today"){

$where_filter="
AND DATE(schedule.departure_time)=CURDATE()
";

}elseif($filter=="tomorrow"){

$where_filter="
AND DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)
";

}elseif($filter=="this_week"){

$where_filter="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)
";

}elseif($filter=="next_week"){

$where_filter="
AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)+1
";

}

/* UPDATE TRIP STATUS */

if(isset($_POST['update_status'])){

$schedule_id=$_POST['schedule_id'];
$trip_status=$_POST['trip_status'];

mbus_db_query($conn,"
UPDATE schedule
SET trip_status='$trip_status'
WHERE schedule_id='$schedule_id'
");

header("Location: manage_trips.php?filter=".$filter);
exit();
}

/* BOARD PASSENGER */

if(isset($_POST['mark_boarded'])){

$reservation_id=$_POST['reservation_id'];

mbus_db_query($conn,"
UPDATE reservation
SET status='Boarded'
WHERE reservation_id='$reservation_id'
");

header("Location: manage_trips.php?filter=".$filter."&open=".$_POST['open_schedule']);
exit();
}

/* FETCH TRIPS */

$operator_id=$_SESSION['user_id'];

$query=mbus_db_query($conn,"
SELECT

schedule.schedule_id,
schedule.departure_time,
schedule.arrival_time,
schedule.trip_status,

routes.origin,
routes.destination,

buses.bus_number,
buses.bus_type

FROM schedule

LEFT JOIN routes
ON schedule.route_id=routes.route_id

LEFT JOIN buses
ON schedule.bus_id=buses.bus_id

LEFT JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id='$operator_id'

$where_filter

ORDER BY schedule.departure_time ASC
");

?>

<!DOCTYPE html>
<html>

<head>

<title>Manage Trips</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/operator/operator_manage_trips.css">

<!-- ✅ ICONS -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<!-- ✅ UPDATED SIDEBAR (MATCH DASHBOARD) -->
<div class="sidebar">

<div class="logo-container">
<img src="../Assets/images/mbus_logo.png" class="logo">
</div>

<h2>Operator</h2>

<div class="menu-top">

<a href="operator_dashboard.php">
<i class="fa-solid fa-house"></i> Dashboard
</a>

<a href="manage_trips.php">
<i class="fa-solid fa-bus"></i> Manage Trips
</a>

<a href="passengers.php">
<i class="fa-solid fa-users"></i> Passenger List
</a>

</div>

<div class="logout-btn">
<a href="../logout.php">
<i class="fa-solid fa-right-from-bracket"></i> Logout
</a>
</div>

</div>

<div class="main">

<div class="page-title">

<h1>Manage Trips</h1>

<p>
Monitor and manage passenger boarding.
</p>

</div>

<div class="card">

<!-- FILTER BUTTONS -->

<div class="filters">

<a
href="?filter=all"
class="filter-btn <?= ($filter=='all')?'active-filter':''; ?>"
>
All
</a>

<a
href="?filter=today"
class="filter-btn <?= ($filter=='today')?'active-filter':''; ?>"
>
Today
</a>

<a
href="?filter=tomorrow"
class="filter-btn <?= ($filter=='tomorrow')?'active-filter':''; ?>"
>
Tomorrow
</a>

<a
href="?filter=this_week"
class="filter-btn <?= ($filter=='this_week')?'active-filter':''; ?>"
>
This Week
</a>

<a
href="?filter=next_week"
class="filter-btn <?= ($filter=='next_week')?'active-filter':''; ?>"
>
Next Week
</a>

</div>

<table>

<tr>

<th>Schedule ID</th>
<th>Bus</th>
<th>Route</th>
<th>Departure</th>
<th>Arrival</th>
<th>Status</th>
<th>Update</th>
<th>Passengers</th>

</tr>

<?php while($row=mbus_db_fetch_assoc($query)){ ?>

<tr>

<td><?= $row['schedule_id']; ?></td>

<td>

<?= $row['bus_number']; ?>

<br>

<small><?= $row['bus_type']; ?></small>

</td>

<td>

<span class="route-badge">

<?= $row['origin']; ?>
→
<?= $row['destination']; ?>

</span>

</td>

<td><?= $row['departure_time']; ?></td>

<td><?= $row['arrival_time']; ?></td>

<td>

<span class="status <?= strtolower($row['trip_status']); ?>">

<?= $row['trip_status']; ?>

</span>

</td>

<td>

<form method="POST" class="action-form">

<input
type="hidden"
name="schedule_id"
value="<?= $row['schedule_id']; ?>"
>

<select
name="trip_status"
class="status-select"
>

<option value="Scheduled" <?= ($row['trip_status']=="Scheduled")?'selected':''; ?>>
Scheduled
</option>

<option value="Boarding" <?= ($row['trip_status']=="Boarding")?'selected':''; ?>>
Boarding
</option>

<option value="Departed" <?= ($row['trip_status']=="Departed")?'selected':''; ?>>
Departed
</option>

<option value="Arrived" <?= ($row['trip_status']=="Arrived")?'selected':''; ?>>
Arrived
</option>

<option value="Cancelled" <?= ($row['trip_status']=="Cancelled")?'selected':''; ?>>
Cancelled
</option>

</select>

<button
type="submit"
name="update_status"
class="update-btn"
>
Update
</button>

</form>

</td>

<td>

<button
class="view-btn"
onclick="togglePassengers(<?= $row['schedule_id']; ?>)"
>
View
</button>

</td>

</tr>

<tr
id="passengers-<?= $row['schedule_id']; ?>"
class="manifest-row"
<?= ($open_schedule==$row['schedule_id'])?"":"style='display:none;'"; ?>
>

<td colspan="8">

<div class="manifest-wrapper">

<h3 class="manifest-title">
Passenger Manifest
</h3>

<table class="manifest-table">

<tr>

<th>Passenger</th>
<th>Seat</th>
<th>Pickup</th>
<th>Destination</th>
<th>Ticket Code</th>
<th>Status</th>
<th>Action</th>

</tr>

<?php

$schedule_id=$row['schedule_id'];

$passengers=mbus_db_query($conn,"
SELECT
reservation.*,
users.full_name

FROM reservation

JOIN users
ON reservation.user_id=users.user_id

WHERE reservation.schedule_id='$schedule_id'

AND(
reservation.status='Paid'
OR reservation.status='Boarded'
)

ORDER BY reservation.seat_number ASC
");

if(mbus_db_num_rows($passengers)>0){

while($passenger=mbus_db_fetch_assoc($passengers)){

?>

<tr>

<td class="passenger-name">
<?= $passenger['full_name']; ?>
</td>

<td>
<?= $passenger['seat_number']; ?>
</td>

<td>
<?= $passenger['pickup_location']; ?>
</td>

<td>
<?= $passenger['destination']; ?>
</td>

<td>
<?= $passenger['ticket_code']; ?>
</td>

<td>

<?php if($passenger['status']=="Boarded"){ ?>

<span class="boarded-badge">
Boarded
</span>

<?php } else { ?>

<span class="paid-badge">
Paid
</span>

<?php } ?>

</td>

<td>

<?php if($passenger['status']!="Boarded"){ ?>

<form
method="POST"
>

<input
type="hidden"
name="reservation_id"
value="<?= $passenger['reservation_id']; ?>"
>

<input
type="hidden"
name="open_schedule"
value="<?= $row['schedule_id']; ?>"
>

<button
type="submit"
name="mark_boarded"
class="board-btn"
>
Boarded
</button>

</form>

<?php } else { ?>

<span class="boarded-badge">
✔ Boarded
</span>

<?php } ?>

</td>

</tr>

<?php

}

}else{

?>

<tr>

<td colspan="7">
No passengers found.
</td>

</tr>

<?php } ?>

</table>

</div>

</td>

</tr>

<tr>

<td colspan="8">
<div class="trip-divider"></div>
</td>

</tr>

<?php } ?>

</table>

</div>

</div>

<script src="../Assets/js/operator/manage_trips.js"></script>

</body>

</html>