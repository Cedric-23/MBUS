<?php
session_start();
include "../config/db_connect.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if(!isset($_GET['schedule_id'])){
    die("Schedule not found.");
}

$schedule_id = mbus_db_escape($conn, $_GET['schedule_id']);

/* =========================
   USER
========================= */
$user_query = mbus_db_query($conn, "
    SELECT full_name
    FROM users
    WHERE user_id='$user_id'
");
$user = mbus_db_fetch_assoc($user_query);

/* =========================
   RESERVATIONS (PAID)
========================= */
$res_query = mbus_db_query($conn, "
    SELECT *
    FROM reservation
    WHERE user_id='$user_id'
    AND schedule_id='$schedule_id'
    AND status='Paid'
    ORDER BY reservation_id DESC
");

if(mbus_db_num_rows($res_query) <= 0){
    die("No paid reservation found.");
}

/* =========================
   PAYMENT
========================= */
$payment_query = mbus_db_query($conn, "
    SELECT p.*
    FROM payment p
    JOIN reservation r ON p.reservation_id = r.reservation_id
    WHERE r.user_id='$user_id'
    AND r.schedule_id='$schedule_id'
    ORDER BY p.payment_id DESC
    LIMIT 1
");

$payment = mbus_db_fetch_assoc($payment_query);

/* =========================
   SAFE VARIABLES
========================= */
$payment_method    = isset($payment['payment_method']) ? $payment['payment_method'] : '';
$payment_reference = isset($payment['payment_reference']) ? $payment['payment_reference'] : '';
$payment_date      = isset($payment['payment_date']) ? $payment['payment_date'] : '';
$ticket_code       = isset($payment['ticket_code']) ? $payment['ticket_code'] : '';
$amount            = isset($payment['amount']) ? $payment['amount'] : '';

/* =========================
   RESERVATION DATA
========================= */
$seats = [];
$pickup = "";
$destination = "";

while($row = mbus_db_fetch_assoc($res_query)){

    if(!empty($row['seat_number'])){
        $seats[] = $row['seat_number'];
    }

    if(empty($pickup)){
        $pickup = $row['pickup_location'];
    }

    if(empty($destination)){
        $destination = $row['destination'];
    }
}

$seat_list = implode(", ", $seats);

/* =========================
   SCHEDULE INFO
========================= */
$schedule_query = mbus_db_query($conn, "
    SELECT buses.bus_number, schedule.departure_time
    FROM schedule
    JOIN buses ON schedule.bus_id = buses.bus_id
    WHERE schedule.schedule_id='$schedule_id'
");

$data = mbus_db_fetch_assoc($schedule_query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>MBUS Digital Ticket</title>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- ✅ YOUR EXTERNAL CSS -->
    <link rel="stylesheet" href="../Assets/css/commuter_ticket.css">

</head>

<body>

<div class="ticket">

    <!-- HEADER -->
    <div class="header">
        <h1>MBUS</h1>
        <p>Digital Bus Ticket</p>
    </div>

    <!-- TICKET CODE -->
    <div class="ticket-code">
        <?= !empty($ticket_code) ? $ticket_code : "N/A"; ?>
    </div>

    <!-- PASSENGER -->
    <div class="section">
        <div class="label">Passenger</div>
        <div class="value"><?= $user['full_name']; ?></div>
    </div>

    <!-- ROUTE -->
    <div class="route">
        <div class="route-place"><?= strtoupper($pickup); ?></div>
        <div class="route-to">to</div>
        <div class="route-place"><?= strtoupper($destination); ?></div>
    </div>

    <!-- BUS -->
    <div class="section">
        <div class="label">Bus Number</div>
        <div class="value"><?= $data['bus_number']; ?></div>
    </div>

    <!-- TIME -->
    <div class="section">
        <div class="label">Departure Time</div>
        <div class="value">
            <?= date("F d, Y - h:i A", strtotime($data['departure_time'])); ?>
        </div>
    </div>

    <!-- SEATS -->
    <div class="section">
        <div class="label">Seat Number</div>
        <div class="value"><?= $seat_list; ?></div>
    </div>

    <hr>

    <!-- PAYMENT -->
    <div class="payment-box">

        <div class="section">
            <div class="label">Amount Paid</div>
            <div class="value">
                <?= !empty($amount) ? "₱".number_format($amount,2) : "N/A"; ?>
            </div>
        </div>

        <div class="section">
            <div class="label">Payment Method</div>
            <div class="value">
                <?= !empty($payment_method) ? $payment_method : "N/A"; ?>
            </div>
        </div>

        <div class="section">
            <div class="label">Reference Number</div>
            <div class="value">
                <?= !empty($payment_reference) ? $payment_reference : "N/A"; ?>
            </div>
        </div>

        <div class="section">
            <div class="label">Payment Date</div>
            <div class="value">
                <?= !empty($payment_date) ? date("F d, Y - h:i A", strtotime($payment_date)) : "N/A"; ?>
            </div>
        </div>

    </div>

    <!-- BUTTONS -->
    <a href="download_ticket.php?schedule_id=<?= $schedule_id; ?>" class="btn download">
        Download PDF Ticket
    </a>

    <a href="my_reservations.php" class="btn reservation-btn">
        View My Reservations
    </a>

    <a href="commuter_dashboard.php" class="back-link">
        Back to Dashboard
    </a>

</div>

</body>
</html>