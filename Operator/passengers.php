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

/* FILTER */

$filter=isset($_GET['filter'])
?$_GET['filter']
:'all';

/* SEARCH */

$search=isset($_GET['search'])
?mbus_db_escape($conn,$_GET['search'])
:'';

$where="

WHERE operator_bus_assignments.operator_id='".$_SESSION['user_id']."'

AND schedule.departure_time >= NOW()

";

/* FILTER LOGIC */

if($filter=="today"){

$where.="

AND DATE(schedule.departure_time)=CURDATE()

";

}elseif($filter=="tomorrow"){

$where.="

AND DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)

";

}elseif($filter=="this_week"){

$where.="

AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)

";

}elseif($filter=="next_week"){

$where.="

AND YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1)+1

";

}

/* SEARCH LOGIC */

if($search!=""){

$where.="

AND(

users.full_name LIKE '%$search%'

OR reservation.ticket_code LIKE '%$search%'

OR reservation.seat_number LIKE '%$search%'

)

";

}

/* STATS */

$total_passengers=mbus_db_fetch_assoc(mbus_db_query($conn,"
SELECT COUNT(*) total

FROM reservation

JOIN schedule
ON reservation.schedule_id=schedule.schedule_id

JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id='".$_SESSION['user_id']."'

AND schedule.departure_time >= NOW()
"));

$paid_passengers=mbus_db_fetch_assoc(mbus_db_query($conn,"
SELECT COUNT(*) total

FROM reservation

JOIN schedule
ON reservation.schedule_id=schedule.schedule_id

JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id='".$_SESSION['user_id']."'

AND reservation.status='Paid'

AND schedule.departure_time >= NOW()
"));

$boarded_passengers=mbus_db_fetch_assoc(mbus_db_query($conn,"
SELECT COUNT(*) total

FROM reservation

JOIN schedule
ON reservation.schedule_id=schedule.schedule_id

JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id='".$_SESSION['user_id']."'

AND reservation.status='Boarded'

AND schedule.departure_time >= NOW()
"));

$today_trips=mbus_db_fetch_assoc(mbus_db_query($conn,"
SELECT COUNT(*) total

FROM schedule

JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

WHERE operator_bus_assignments.operator_id='".$_SESSION['user_id']."'

AND DATE(schedule.departure_time)=CURDATE()

AND schedule.departure_time >= NOW()
"));

/* FETCH PASSENGERS */

$query=mbus_db_query($conn,"
SELECT

reservation.*,

users.full_name,

schedule.departure_time,

routes.origin,
routes.destination,

buses.bus_number

FROM reservation

JOIN users
ON reservation.user_id=users.user_id

JOIN schedule
ON reservation.schedule_id=schedule.schedule_id

JOIN routes
ON schedule.route_id=routes.route_id

JOIN buses
ON schedule.bus_id=buses.bus_id

JOIN operator_bus_assignments
ON schedule.bus_id=operator_bus_assignments.bus_id

$where

ORDER BY schedule.departure_time ASC,
reservation.seat_number ASC
");

?>

<!DOCTYPE html>
<html>

<head>

<title>Passenger List</title>

<!-- ✅ SIDEBAR CSS -->
<link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">

<!-- ✅ PAGE CSS -->
<link rel="stylesheet" href="../Assets/css/operator/operator_passengers.css">

<!-- ✅ ICONS -->
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<!-- ✅ UPDATED SIDEBAR -->
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

<h1>Passenger List</h1>

<p>
Monitor all passengers and trip reservations.
</p>

</div>

<div class="cards">

<div class="card">

<h3>Total Passengers</h3>

<div class="number">
<?= $total_passengers['total']; ?>
</div>

</div>

<div class="card">

<h3>Paid Passengers</h3>

<div class="number">
<?= $paid_passengers['total']; ?>
</div>

</div>

<div class="card">

<h3>Boarded Passengers</h3>

<div class="number">
<?= $boarded_passengers['total']; ?>
</div>

</div>

<div class="card">

<h3>Trips Today</h3>

<div class="number">
<?= $today_trips['total']; ?>
</div>

</div>

</div>

<div class="table-card">

<div class="filters">

<a href="?filter=all" class="filter-btn <?= ($filter=='all')?'active-filter':''; ?>">
All
</a>

<a href="?filter=today" class="filter-btn <?= ($filter=='today')?'active-filter':''; ?>">
Today
</a>

<a href="?filter=tomorrow" class="filter-btn <?= ($filter=='tomorrow')?'active-filter':''; ?>">
Tomorrow
</a>

<a href="?filter=this_week" class="filter-btn <?= ($filter=='this_week')?'active-filter':''; ?>">
This Week
</a>

<a href="?filter=next_week" class="filter-btn <?= ($filter=='next_week')?'active-filter':''; ?>">
Next Week
</a>

</div>

<form method="GET" class="search-box">

<input
type="hidden"
name="filter"
value="<?= $filter; ?>"
>

<input
type="text"
name="search"
placeholder="Search passenger, ticket code, seat..."
value="<?= htmlspecialchars($search); ?>"
>

<button type="submit">
Search
</button>

</form>

<table>

<tr>

<th>Passenger</th>
<th>Bus</th>
<th>Route</th>
<th>Seat</th>
<th>Pickup</th>
<th>Destination</th>
<th>Ticket Code</th>
<th>Departure</th>
<th>Status</th>

</tr>

<?php

if(mbus_db_num_rows($query)>0){

while($row=mbus_db_fetch_assoc($query)){

?>

<tr>

<td class="passenger-name">
<?= $row['full_name']; ?>
</td>

<td>
<?= $row['bus_number']; ?>
</td>

<td>

<span class="route-badge">

<?= $row['origin']; ?>
→
<?= $row['destination']; ?>

</span>

</td>

<td>
<?= $row['seat_number']; ?>
</td>

<td>
<?= $row['pickup_location']; ?>
</td>

<td>
<?= $row['destination']; ?>
</td>

<td class="ticket">
<?= $row['ticket_code']; ?>
</td>

<td>
<?= date("M j, g:i A",strtotime($row['departure_time'])); ?>
</td>

<td>

<?php if($row['status']=="Paid"){ ?>

<span class="paid">
Paid
</span>

<?php }elseif($row['status']=="Boarded"){ ?>

<span class="boarded">
Boarded
</span>

<?php }else{ ?>

<span class="cancelled">
<?= $row['status']; ?>
</span>

<?php } ?>

</td>

</tr>

<?php

}

}else{

?>

<tr>

<td colspan="9">

No passengers found.

</td>

</tr>

<?php } ?>

</table>

</div>

</div>

</body>

</html>