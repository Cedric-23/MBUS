<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if(!isset($_GET['schedule_id'])){
    die("Invalid request.");
}

$schedule_id = mbus_db_escape($conn, $_GET['schedule_id']);

$query = mbus_db_query($conn,"
SELECT reservation.*, schedule.departure_time, schedule.arrival_time,
routes.origin, routes.destination AS route_destination,
buses.bus_number
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN routes ON schedule.route_id = routes.route_id
JOIN buses ON schedule.bus_id = buses.bus_id
WHERE reservation.user_id = '$user_id'
AND reservation.schedule_id = '$schedule_id'
AND reservation.status = 'Pending'
");

if(mbus_db_num_rows($query) <= 0){
    die("No pending reservation found.");
}

$reservations = [];
while($row = mbus_db_fetch_assoc($query)){
    $reservations[] = $row;
}

$created_at = strtotime($reservations[0]['created_at']);
$expiry = $created_at + (5 * 60);

$total = isset($_GET['total']) ? floatval($_GET['total']) : 0;

$error = "";

/* CONFIRM PAYMENT */
if(isset($_POST['confirm_payment'])){

    $method = mbus_db_escape($conn, $_POST['payment_method']);
    $reference = mbus_db_escape($conn, $_POST['reference_number']);

    if(empty($reference)){
        $error = "Reference number required.";
    } else {

        $ticket_code = "MB-" . rand(1000,9999);

        $get_res = mbus_db_query($conn,"
        SELECT reservation_id FROM reservation
        WHERE user_id='$user_id'
        AND schedule_id='$schedule_id'
        LIMIT 1
        ");

        $res_data = mbus_db_fetch_assoc($get_res);
        $res_id = $res_data['reservation_id'];

        mbus_db_query($conn,"
        INSERT INTO payment(
        reservation_id, amount, payment_method,
        payment_reference, payment_status,
        ticket_code, payment_date
        ) VALUES(
        '$res_id', '$total', '$method',
        '$reference', 'Paid',
        '$ticket_code', NOW()
        )
        ");

        $update = mbus_db_query($conn,"
        UPDATE reservation
        SET status='Paid'
        WHERE user_id='$user_id'
        AND schedule_id='$schedule_id'
        AND status='Pending'
        ");

        if($update){
            header("Location: ticket.php?schedule_id=$schedule_id");
            exit();
        } else {
            $error = "Payment failed.";
        }
    }
}

/* CANCEL */
if(isset($_POST['cancel_reservation'])){
    mbus_db_query($conn,"
    UPDATE reservation
    SET status='Cancelled'
    WHERE user_id='$user_id'
    AND schedule_id='$schedule_id'
    AND status='Pending'
    ");
    header("Location: schedule.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payment</title>

<!-- ✅ FIXED ICONS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_payment.css">

</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">

    <div class="logo-container">
        <img src="../Assets/images/mbus_logo.png" class="logo">
    </div>

    <h2>Commuter</h2>

    <div class="menu-top">

        <a href="commuter_dashboard.php">
            <i class="fa fa-home"></i> Dashboard
        </a>

        <a href="my_reservations.php">
            <i class="fa fa-ticket-alt"></i> My Reservations
        </a>

        <a href="history.php">
            <i class="fa fa-history"></i> History
        </a>

    </div>

    <a href="../logout.php" class="logout-btn">
        <i class="fa fa-sign-out-alt"></i> Logout
    </a>

</div>

<!-- MAIN -->
<div class="main">

<div class="page-title">Online Payment</div>

<div class="layout">

<!-- LEFT PANEL -->
<div class="left-panel">

<div class="card">

<div class="route">
<?= $reservations[0]['origin']; ?> → <?= $reservations[0]['route_destination']; ?>
</div>

<div class="info"><b>Bus:</b> <?= $reservations[0]['bus_number']; ?></div>
<div class="info"><b>Departure:</b> <?= $reservations[0]['departure_time']; ?></div>
<div class="info"><b>Expected Arrival:</b> <?= $reservations[0]['arrival_time']; ?></div>
<div class="info"><b>Pickup:</b> <?= $reservations[0]['pickup_location']; ?></div>
<div class="info"><b>Destination:</b> <?= $reservations[0]['destination']; ?></div>

<div class="info"><b>Reserved Seats:</b></div>

<?php foreach($reservations as $r){ ?>
<div class="seat-box">Seat <?= $r['seat_number']; ?></div>
<?php } ?>

<div class="total">Total: ₱<?= number_format($total,2); ?></div>

</div>

</div>

<!-- RIGHT PANEL -->
<div class="right-panel">

<div class="card">

<div class="timer-box">
Time Remaining: <span id="countdown"></span>
</div>

<?php if($error != ""){ ?>
<div class="notice error"><?= $error; ?></div>
<?php } ?>

<form method="POST">

<label>Choose Payment Method</label>

<div class="payment-method">
<input type="radio" name="payment_method" value="GCash" required> GCash     (0951-8189-487)
</div>

<div class="payment-method">
<input type="radio" name="payment_method" value="Maya"> Maya       (0951-8189-487)
</div>

<label>Reference Number</label>

<input type="text" name="reference_number" placeholder="Enter reference number" required>

<button type="submit" name="confirm_payment" class="pay-btn">
Confirm Payment
</button>

</form>

<form method="POST">
<button type="submit" name="cancel_reservation" class="cancel-btn">
Cancel Reservation
</button>
</form>

</div>

</div>

</div>

</div>

<!-- COUNTDOWN -->
<script>
const expiryTime = <?= $expiry * 1000; ?>;
</script>

<script src="../Assets/js/commuter_payment.js"></script>

</body>
</html>