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
    $where="WHERE schedule.departure_time >= NOW() AND DATE(schedule.departure_time)=CURDATE()";
}
elseif($filter=="tomorrow"){
    $where="WHERE DATE(schedule.departure_time)=DATE_ADD(CURDATE(),INTERVAL 1 DAY)";
}
elseif($filter=="thisweek"){
    $where="WHERE YEARWEEK(schedule.departure_time,1)=YEARWEEK(CURDATE(),1) AND schedule.departure_time >= NOW()";
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
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_schedule.css">

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

<h1>Bus Schedules</h1>

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
schedule.departure_time,schedule.arrival_time,schedule.schedule_status
FROM schedule
JOIN buses ON schedule.bus_id=buses.bus_id
JOIN routes ON schedule.route_id=routes.route_id
$where
ORDER BY schedule.departure_time ASC";

$result=mbus_db_query($conn,$sql);
?>

<?php if(mbus_db_num_rows($result)>0){ ?>

<table>

<tr>
<th>Bus Number</th>
<th>Route</th>
<th>Departure</th>
<th>Arrival</th>
<th>Status</th>
<th>Available Seats</th>
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

<tr>

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

<td>
<?php
if($available<=0){echo"<span class='full'>FULL</span>";}
else{echo"<span class='available'>$available / 28</span>";}
?>
</td>

<td>
<?php if($available<=0){ ?>
<button disabled>Full</button>
<?php }else{ ?>
<a href="reserve.php?schedule_id=<?php echo $schedule_id; ?>">
<button class="select-btn">Select</button>
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

</body>
</html>