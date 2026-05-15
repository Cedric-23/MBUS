<?php
session_start();
include "../config/db_connect.php";

/* AUTO EXPIRE */
mbus_db_query($conn,"
UPDATE reservation
SET status='Cancelled'
WHERE status='Pending'
AND created_at<=NOW()-INTERVAL 5 MINUTE
");

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id=$_SESSION['user_id'];

/* RESERVATIONS */
$sql=mbus_db_query($conn,"
SELECT
reservation.reservation_id,
reservation.schedule_id,
reservation.seat_number,
reservation.pickup_location,
reservation.destination,
reservation.status,
reservation.created_at,
schedule.departure_time,
schedule.arrival_time,
routes.origin,
routes.destination AS route_destination,
buses.bus_number
FROM reservation
JOIN schedule ON reservation.schedule_id=schedule.schedule_id
JOIN routes ON schedule.route_id=routes.route_id
JOIN buses ON schedule.bus_id=buses.bus_id
WHERE reservation.user_id='$user_id'
ORDER BY reservation.reservation_id DESC
");
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Reservations</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/my_reservation.css">

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

<div class="page-title">My Reservations</div>

<?php if(mbus_db_num_rows($sql)>0){ ?>

<?php while($row=mbus_db_fetch_assoc($sql)){ ?>

<?php
$statusClass=strtolower($row['status']);
$remaining=0;

if($row['status']=="Pending"){
    $created=strtotime($row['created_at']);
    $expire=$created+(5*60);
    $remaining=$expire-time();

    if($remaining<0){
        mbus_db_query($conn,"UPDATE reservation SET status='Cancelled' WHERE reservation_id='".$row['reservation_id']."'");
        $row['status']="Cancelled";
        $statusClass="cancelled";
    }
}
?>

<div class="card">

<div class="top">
<div class="bus">Bus <?= $row['bus_number'] ?></div>
<div class="status <?= $statusClass ?>"><?= $row['status'] ?></div>
</div>

<div class="route"><?= $row['origin'] ?> → <?= $row['route_destination'] ?></div>

<div class="info"><b>Seat:</b> <?= $row['seat_number'] ?></div>
<div class="info"><b>Trip:</b> <?= $row['pickup_location'] ?> → <?= $row['destination'] ?></div>
<div class="info"><b>Departure:</b> <?= $row['departure_time'] ?></div>
<div class="info"><b>Expected Arrival:</b> <?= $row['arrival_time'] ?></div>

<?php if($row['status']=="Pending"){ ?>

<div class="timer">
Time Remaining:
<span class="countdown" data-seconds="<?= $remaining ?>"><?= gmdate("i:s",$remaining) ?></span>
</div>

<div class="actions">
<a class="btn pay-btn" href="payment.php?schedule_id=<?= $row['schedule_id'] ?>">Pay Now</a>
<button class="btn cancel-btn" onclick="cancelReservation(<?= $row['reservation_id'] ?>,this)">Cancel</button>
</div>

<?php } ?>

<?php if($row['status']=="Paid"){ ?>
<div class="actions">
<a class="btn ticket-btn" href="ticket.php?schedule_id=<?= $row['schedule_id'] ?>">View Ticket</a>
</div>
<?php } ?>

<?php if($row['status']=="Cancelled"){ ?>
<div class="actions">
<div class="status cancelled">Reservation Cancelled</div>
</div>
<?php } ?>

</div>

<?php } ?>

<?php }else{ ?>

<div class="empty">No reservations found.</div>

<?php } ?>

</div>

<script src="../Assets/js/my_reservations.js"></script>

</body>
</html>