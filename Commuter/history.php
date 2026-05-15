<?php
session_start();
include "../config/db_connect.php";

/* AUTH */
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id=$_SESSION['user_id'];

/* FETCH HISTORY */
$sql=mysqli_query($conn,"
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
AND (reservation.status='Boarded' OR reservation.status='Cancelled')
ORDER BY reservation.reservation_id DESC
");
?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Trip History</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_history.css">

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

<div class="page-title">Trip History</div>

<div id="history-container">

<?php if(mysqli_num_rows($sql)>0){ ?>

<?php while($row=mysqli_fetch_assoc($sql)){ ?>

<div class="card">

<div class="top">
<div class="bus">Bus <?= $row['bus_number'] ?></div>
<div class="status <?= strtolower($row['status']) ?>"><?= $row['status'] ?></div>
</div>

<div class="route"><?= $row['origin'] ?> → <?= $row['route_destination'] ?></div>

<div class="info"><b>Seat:</b> <?= $row['seat_number'] ?></div>
<div class="info"><b>Trip:</b> <?= $row['pickup_location'] ?> → <?= $row['destination'] ?></div>
<div class="info"><b>Departure:</b> <?= $row['departure_time'] ?></div>
<div class="info"><b>Arrival:</b> <?= $row['arrival_time'] ?></div>

<div class="actions">
<a class="btn ticket-btn" href="ticket.php?schedule_id=<?= $row['schedule_id'] ?>">View Ticket</a>
<button class="btn clear-btn" onclick="clearHistory(<?= $row['reservation_id'] ?>, this)">Clear</button>
</div>

</div>

<?php } ?>

<?php }else{ ?>

<div class="empty" id="empty-msg">No trip history found.</div>

<?php } ?>

</div>

</div>

<script src="../Assets/js/commuter_history.js"></script>

</body>
</html>