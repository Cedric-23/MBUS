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

/* USER */
$user_query = mbus_db_prepare($conn, "
    SELECT full_name
    FROM users
    WHERE user_id=?
", [$user_id]);
$user = mbus_db_fetch_assoc($user_query);

/* RESERVATIONS (PAID) */
$res_query = mbus_db_prepare($conn, "
    SELECT *
    FROM reservation
    WHERE user_id=?
    AND schedule_id=?
    AND status='Paid'
    ORDER BY reservation_id DESC
", [$user_id, $schedule_id]);

if(mbus_db_num_rows($res_query) <= 0){
    die("No paid reservation found.");
}

/* PAYMENT */
$payment_query = mbus_db_prepare($conn, "
    SELECT p.*
    FROM payment p
    JOIN reservation r ON p.reservation_id = r.reservation_id
    WHERE r.user_id=?
    AND r.schedule_id=?
    ORDER BY p.payment_id DESC
    LIMIT 1
", [$user_id, $schedule_id]);

$payment = mbus_db_fetch_assoc($payment_query);

/* SAFE VARIABLES */
$payment_method    = isset($payment['payment_method']) ? $payment['payment_method'] : '';
$payment_reference = isset($payment['payment_reference']) ? $payment['payment_reference'] : '';
$payment_date      = isset($payment['payment_date']) ? $payment['payment_date'] : '';
$ticket_code       = isset($payment['ticket_code']) ? $payment['ticket_code'] : '';
$amount            = isset($payment['amount']) ? $payment['amount'] : '';

/* RESERVATION DATA */
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

/* SCHEDULE INFO */
$schedule_query = mbus_db_prepare($conn, "
    SELECT buses.bus_number, schedule.departure_time
    FROM schedule
    JOIN buses ON schedule.bus_id = buses.bus_id
    WHERE schedule.schedule_id=?
", [$schedule_id]);

$data = mbus_db_fetch_assoc($schedule_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBUS Digital Ticket</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_ticket.css">
</head>

<body>

<div class="ticket-container">
    
    <!-- TICKET CARD -->
    <div class="ticket card">
        
        <!-- HEADER -->
        <header class="ticket-header">
            <div class="ticket-brand">
                <i class="fa fa-bus" aria-hidden="true"></i>
                <span>MBUS</span>
            </div>
            <div class="ticket-type">Digital Bus Ticket</div>
        </header>

        <!-- TICKET CODE -->
        <div class="ticket-code-display">
            <i class="fa fa-qrcode" aria-hidden="true"></i>
            <span><?php echo !empty($ticket_code) ? $ticket_code : "N/A"; ?></span>
        </div>

        <!-- PASSENGER -->
        <div class="ticket-section">
            <div class="ticket-label">Passenger</div>
            <div class="ticket-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
        </div>

        <!-- ROUTE -->
        <div class="ticket-route">
            <div class="ticket-place">
                <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                <span><?php echo strtoupper(htmlspecialchars($pickup)); ?></span>
            </div>
            <div class="ticket-arrow">
                <i class="fa fa-arrow-right" aria-hidden="true"></i>
            </div>
            <div class="ticket-place">
                <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                <span><?php echo strtoupper(htmlspecialchars($destination)); ?></span>
            </div>
        </div>

        <hr class="ticket-divider">

        <!-- BUS -->
        <div class="ticket-section">
            <div class="ticket-label"><i class="fa fa-bus" aria-hidden="true"></i> Bus Number</div>
            <div class="ticket-value"><?php echo htmlspecialchars($data['bus_number']); ?></div>
        </div>

        <!-- TIME -->
        <div class="ticket-section">
            <div class="ticket-label"><i class="fa fa-clock" aria-hidden="true"></i> Departure Time</div>
            <div class="ticket-value"><?php echo date("F d, Y - h:i A", strtotime($data['departure_time'])); ?></div>
        </div>

        <!-- SEATS -->
        <div class="ticket-section">
            <div class="ticket-label"><i class="fa fa-chair" aria-hidden="true"></i> Seat Number(s)</div>
            <div class="ticket-value ticket-seats"><?php echo $seat_list; ?></div>
        </div>

        <hr class="ticket-divider">

        <!-- PAYMENT -->
        <div class="ticket-payment">
            <h3 class="payment-title"><i class="fa fa-receipt" aria-hidden="true"></i> Payment Details</h3>
            
            <div class="payment-grid">
                <div class="payment-item">
                    <div class="ticket-label">Amount Paid</div>
                    <div class="ticket-value"><?php echo !empty($amount) ? "₱".number_format($amount,2) : "N/A"; ?></div>
                </div>
                <div class="payment-item">
                    <div class="ticket-label">Payment Method</div>
                    <div class="ticket-value"><?php echo !empty($payment_method) ? htmlspecialchars($payment_method) : "N/A"; ?></div>
                </div>
                <div class="payment-item">
                    <div class="ticket-label">Reference Number</div>
                    <div class="ticket-value"><?php echo !empty($payment_reference) ? htmlspecialchars($payment_reference) : "N/A"; ?></div>
                </div>
                <div class="payment-item">
                    <div class="ticket-label">Payment Date</div>
                    <div class="ticket-value"><?php echo !empty($payment_date) ? date("F d, Y - h:i A", strtotime($payment_date)) : "N/A"; ?></div>
                </div>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="ticket-actions">
            <a href="download_ticket.php?schedule_id=<?php echo $schedule_id; ?>" class="btn btn-primary btn-block">
                <i class="fa fa-download" aria-hidden="true"></i> Download PDF Ticket
            </a>
            <a href="my_reservations.php" class="btn btn-outline btn-block">
                <i class="fa fa-list" aria-hidden="true"></i> View My Reservations
            </a>
            <a href="commuter_dashboard.php" class="btn btn-text btn-block">
                <i class="fa fa-arrow-left" aria-hidden="true"></i> Back to Dashboard
            </a>
        </div>

    </div>

    <!-- QR Code placeholder for future enhancement -->
    <div class="ticket-footer">
        <p>This is your official digital ticket. Present this to the bus conductor upon boarding.</p>
    </div>

</div>

<script>
// Print ticket functionality
window.addEventListener('load', function() {
    // Add keyboard shortcut for print
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
});
</script>

</body>
</html>