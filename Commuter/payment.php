<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if(!isset($_GET['schedule_id'])){
    die("Invalid request.");
}

$schedule_id = mbus_db_escape($conn, $_GET['schedule_id']);

// Authorization check - verify user has pending reservations for this schedule
$auth_check = mbus_db_prepare($conn,"
    SELECT COUNT(*) as count
    FROM reservation
    WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'
", [$user_id, $schedule_id]);

$auth_result = mbus_db_fetch_assoc($auth_check);

if($auth_result['count'] <= 0){
    die("No pending reservation found for this schedule.");
}

// Get reservation details with prepared statement
$res_query = mbus_db_prepare($conn, "
SELECT reservation.*, schedule.departure_time, schedule.arrival_time,
routes.origin, routes.destination AS route_destination,
buses.bus_number
FROM reservation
JOIN schedule ON reservation.schedule_id = schedule.schedule_id
JOIN routes ON schedule.route_id = routes.route_id
JOIN buses ON schedule.bus_id = buses.bus_id
WHERE reservation.user_id = ?
AND reservation.schedule_id = ?
AND reservation.status = 'Pending'
ORDER BY reservation.reservation_id
", [$user_id, $schedule_id]);

if(mbus_db_num_rows($res_query) <= 0){
    die("No pending reservation found.");
}

$reservations = [];
while($row = mbus_db_fetch_assoc($res_query)){
    $reservations[] = $row;
}

$created_at = strtotime($reservations[0]['created_at']);
$expiry = $created_at + (10 * 60); // 10 minutes matching reserve.php

$acct_type = mbus_passenger_type($conn, (int)$user_id);
// Default passenger types: the verified account holder's own (first) seat gets their type.
$default_types = [];
foreach($reservations as $i => $r){
    $stored = (!empty($r['passenger_type']) && in_array($r['passenger_type'], MBUS_PASSENGER_TYPES, true)) ? $r['passenger_type'] : 'Regular';
    $default_types[$r['reservation_id']] = $i === 0 ? $acct_type : $stored;
}
// Total is always computed on the server; nothing from the URL or form is trusted.
$quote = mbus_quote_lines($reservations, $default_types);
$total = $quote['total'];
$expired = time() > $expiry;

$error = "";

/* CONFIRM PAYMENT */
if(isset($_POST['confirm_payment'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $method = 'GCash'; // GCash is the only accepted payment method
        $reference = trim((string)($_POST['reference_number'] ?? ''));

        // Passenger types were chosen on the reserve page and are saved on each seat.
        // The first seat follows the account's status; extra discounted seats carry their ID photo.
        $types = []; $covered = []; $ids = [];
        foreach($reservations as $i => $r){
            $rid = $r['reservation_id'];
            $types[$rid] = $default_types[$rid];
            $covered[$rid] = ($types[$rid] !== 'Regular' && $i === 0);
            if($types[$rid] !== 'Regular' && !$covered[$rid]){
                if(empty($r['id_image_b64'])){
                    $error = "Seat {$r['seat_number']} is a {$types[$rid]} ticket without an ID photo. Please reserve again.";
                    break;
                }
                $ids[$rid] = ['mime' => $r['id_image_mime'], 'b64' => $r['id_image_b64']];
            }
        }
        $quote = mbus_quote_lines($reservations, $types);
        $total = $quote['total'];

        if($error !== ""){
            // keep the message from above
        } elseif($expired){
            mbus_db_prepare($conn, "UPDATE reservation SET status='Cancelled' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'", [$user_id, $schedule_id]);
            $error = "This reservation expired after 10 minutes. Please reserve your seats again.";
        } elseif($reference === '' || strlen($reference) < 10){
            $error = "Reference number required (at least 10 digits).";
        } else {
            $ticket_code = "MB-" . random_int(1000,9999);
            $conn->pdo->beginTransaction();
            try{
                $res_id = $reservations[0]['reservation_id'];
                foreach($quote['lines'] as $ln){
                    $rid = $ln['reservation_id'];
                    $status = $ln['type'] === 'Regular' ? 'None' : ($covered[$rid] ? 'Approved' : 'Submitted');
                    $up = mbus_db_prepare($conn, "
                    UPDATE reservation
                    SET status = 'Paid', passenger_type = ?, discount_status = ?, id_image_b64 = ?, id_image_mime = ?,
                        fare_amount = ?, discount_amount = ?
                    WHERE reservation_id = ? AND user_id = ? AND status = 'Pending'
                    ", [$ln['type'], $status, $ids[$rid]['b64'] ?? null, $ids[$rid]['mime'] ?? null, $ln['base'], $ln['discount'], $rid, $user_id]);
                    if($up === false || (int)$conn->affectedRows < 1){
                        throw new Exception("A seat is no longer pending. Please reserve again.");
                    }
                }

                mbus_db_prepare($conn, "
                INSERT INTO payment(
                reservation_id, amount, payment_method,
                payment_reference, payment_status,
                ticket_code, payment_date
                ) VALUES(
                ?, ?, ?, ?, ?, ?, NOW()
                )
                ", [$res_id, $total, $method, $reference, 'Paid', $ticket_code]);

                $conn->pdo->commit();
                log_activity($conn, $user_id, ACTION_PAYMENT_SUCCESS, "Payment completed for schedule $schedule_id, ticket: $ticket_code, total $total");

                $user_email_query = mbus_db_prepare($conn, "SELECT email FROM users WHERE user_id = ?", [$user_id]);
                $user_email_data = $user_email_query === false ? false : mbus_db_fetch_assoc($user_email_query);
                $user_email = $user_email_data['email'] ?? '';
                $route_info = $reservations[0]['origin'] . " → " . $reservations[0]['route_destination'];
                $departure_formatted = date('F d, Y g:i A', strtotime($reservations[0]['departure_time']));
                $email_subject = "Payment Confirmed - Ticket: $ticket_code";
                $email_message = "Dear Commuter,

Your payment has been confirmed!

Ticket: $ticket_code
Route: $route_info
Departure: $departure_formatted
Amount: ₱" . number_format($total, 2) . "

Thank you for using MBUS!";
                if($user_email !== ''){ send_email_notification($user_email, $email_subject, $email_message); }

                header("Location: ticket.php?schedule_id=$schedule_id");
                exit();
            } catch(Exception $e){
                if($conn->pdo->inTransaction()){ $conn->pdo->rollBack(); }
                $error = "Payment failed: " . $e->getMessage();
            }
        }
    }
}

/* CANCEL */
if(isset($_POST['cancel_reservation'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        mbus_db_prepare($conn, "
        UPDATE reservation
        SET status='Cancelled'
        WHERE user_id=?
        AND schedule_id=?
        AND status='Pending'
        ", [$user_id, $schedule_id]);
        
        // Log cancellation
        log_activity($conn, $user_id, ACTION_RESERVATION_CANCEL, "Cancelled pending reservation for schedule $schedule_id");
        
        header("Location: schedule.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Payment</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../Assets/css/responsive.css">
<link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
<link rel="stylesheet" href="../Assets/css/commuter_payment.css">

    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>

<body class="payment-page">

<main class="main" role="main">

<header class="page-header">
    <div class="page-header-left">
        <a href="my_reservations.php" class="back-btn" aria-label="Back to my reservations"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
        <h1>Payment</h1>
    </div>

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

<div class="payment-layout">

<!-- LEFT PANEL -->
<div class="payment-panel payment-panel-main">

<div class="card trip-summary">

<div class="route-badge">
<?php echo htmlspecialchars($reservations[0]['origin']); ?> &#8594; <?php echo htmlspecialchars($reservations[0]['route_destination']); ?>
</div>

<ul class="trip-details">
    <li><strong>Bus:</strong> <?php echo htmlspecialchars($reservations[0]['bus_number']); ?></li>
    <li><strong>Departure:</strong> <?php echo date("F d, Y g:i A", strtotime($reservations[0]['departure_time'])); ?></li>
    <li><strong>Expected Arrival:</strong> <?php echo date("F d, Y g:i A", strtotime($reservations[0]['arrival_time'])); ?></li>
    <li><strong>Pickup:</strong> <?php echo htmlspecialchars($reservations[0]['pickup_location']); ?></li>
    <li><strong>Destination:</strong> <?php echo htmlspecialchars($reservations[0]['destination']); ?></li>
    <li class="trip-total"><strong>Total:</strong> <span>&#8369;<span id="totalAmt"><?php echo number_format($total, 2); ?></span></span></li>
</ul>

<div class="seats-section">
    <strong>Seats</strong>
    <?php foreach($quote['lines'] as $ln){ ?>
    <div class="pay-seat">
        <div class="pay-seat-head">
            <span class="seat-tag">Seat <?php echo htmlspecialchars((string)$ln['seat']); ?></span>
            <span class="pay-type-label"><?php echo htmlspecialchars($ln['type']); ?></span>
            <span class="pay-line">&#8369;<?php echo number_format($ln['base'] - $ln['discount'], 2); ?></span>
        </div>
    </div>
    <?php } ?>
    <p class="pay-fee">Reservation fee: &#8369;<?php echo number_format(MBUS_RESERVATION_FEE, 2); ?> per seat</p>
</div>
</div>

</div>

</div>

<!-- RIGHT PANEL -->
<div class="payment-panel payment-panel-sidebar">

<div class="card" id="paymentSummaryCard">

<div class="timer-box" id="timerBox">
    <i class="fa fa-clock" aria-hidden="true"></i>
    <span>Expires in: </span>
    <span id="countdown">--:--</span>
</div>

<?php if($error): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<form method="POST" class="payment-form" id="payForm">
    <?php csrf_field(); ?>
    
    <div class="gcash-row">
        <span class="gcash-mark" aria-hidden="true"><i class="fa fa-mobile-alt"></i></span>
        <span class="gcash-text"><strong>GCash</strong><small>Only accepted payment method</small></span>
    </div>
    <input type="hidden" name="payment_method" value="GCash">

    <div class="form-group">
        <label for="reference_number">GCash reference number</label>
        <input type="text" name="reference_number" id="reference_number" class="form-control" placeholder="e.g. 1234567890123" inputmode="numeric" autocomplete="off" required minlength="10">
        <small class="form-hint">Find it in your GCash receipt after sending the payment.</small>
    </div>

    <button type="submit" name="confirm_payment" class="btn btn-primary btn-block"<?php echo $expired ? ' disabled' : ''; ?>>
        Confirm payment &#8369;<span id="btnAmt"><?php echo number_format($total, 2); ?></span>
    </button>
</form>


</div>

</div>

</div>

</main>

<script>
let countdownInterval;

function startCountdown() {
    const expiry = <?php echo $expiry; ?> * 1000; // Convert to milliseconds
    const countdownEl = document.getElementById('countdown');
    const timerBox = document.getElementById('timerBox');
    
    function update() {
        const now = Date.now();
        const diff = expiry - now;
        
        if(diff <= 0){
            countdownEl.textContent = "00:00";
            timerBox.classList.add('expired');
            clearInterval(countdownInterval);
            // Optionally redirect or show expired message
            return;
        }
        
        const minutes = Math.floor(diff / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        countdownEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }
    
    update();
    countdownInterval = setInterval(update, 1000);
}

startCountdown();
</script>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i><span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span>
    </a>
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/flow-feedback.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
