<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";
include "../Includes/paymongo.php";
include "../Includes/payment_finalize.php";
include "../Includes/gcash_manual.php";

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

// A receipt is already waiting for an admin: no second payment screen.
$sub = $conn->pdo->prepare("SELECT 1 FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? AND status = 'Submitted' LIMIT 1");
$sub->execute([$user_id, $schedule_id]);
if($sub->fetchColumn()){ header("Location: payment_return.php?schedule_id=" . (int)$schedule_id); exit(); }


/* PAY WITH GCASH (PayMongo redirect) */
if(isset($_POST['pay_gcash']) && paymongo_enabled()){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } elseif($expired){
        mbus_db_prepare($conn, "UPDATE reservation SET status='Cancelled' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'", [$user_id, $schedule_id]);
        $error = "This reservation expired after 10 minutes. Please reserve your seats again.";
    } else {
        $pl = mbus_checkout_payload($reservations, $default_types);
        $error = $pl['error'];
        if($error === ''){
            $q2 = $pl['quote']; $idsJson = $pl['idsJson']; $linesJson = $pl['linesJson'];
            try{
                // Reuse a still-open checkout for the same seats and amount instead of opening a new one.
                $ex = $conn->pdo->prepare("SELECT checkout_url FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? AND status = 'Created'
                    AND reservation_ids = ? AND amount = ? AND created_at > NOW() - INTERVAL '25 minutes' ORDER BY created_at DESC LIMIT 1");
                $ex->execute([$user_id, $schedule_id, $idsJson, $q2['total']]);
                $url = $ex->fetchColumn();
                if(!$url){
                    $items = [];
                    foreach($q2['lines'] as $l){
                        $items[] = ['name' => 'Seat ' . $l['seat'] . ' (' . $l['type'] . ')', 'amount' => (int)round($l['final'] * 100)];
                    }
                    $base = paymongo_base_url();
                    $co = paymongo_create_gcash_checkout($items,
                        'MBUS ' . $reservations[0]['origin'] . ' to ' . $reservations[0]['route_destination'],
                        'MBUS-' . $reservations[0]['reservation_id'],
                        $base . '/Commuter/payment_return.php?schedule_id=' . (int)$schedule_id,
                        $base . '/Commuter/payment.php?schedule_id=' . (int)$schedule_id,
                        ['user_id' => (string)$user_id, 'schedule_id' => (string)$schedule_id, 'reservation_ids' => implode(',', json_decode($idsJson, true))]);
                    $conn->pdo->prepare("INSERT INTO paymongo_checkout (session_id, user_id, schedule_id, reservation_ids, lines, amount, checkout_url) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$co['id'], $user_id, $schedule_id, $idsJson, $linesJson, $q2['total'], $co['checkout_url']]);
                    // Restart the 10-minute hold so the payer has time to finish in GCash.
                    $conn->pdo->prepare("UPDATE reservation SET created_at = NOW() WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'")->execute([$user_id, $schedule_id]);
                    $url = $co['checkout_url'];
                }
                header("Location: " . $url);
                exit();
            } catch(Throwable $e){
                $error = $e instanceof RuntimeException ? $e->getMessage() : "Could not start the GCash payment. Please try again.";
                if(!($e instanceof RuntimeException)) error_log('GCash checkout start failed: ' . $e->getMessage());
            }
        }
    }
}

/* START GCASH PAYMENT (free flow: pay the exact amount; the SMS on the admin's phone confirms it automatically) */
if(isset($_POST['start_gcash']) && !paymongo_enabled()){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } elseif($expired){
        mbus_db_prepare($conn, "UPDATE reservation SET status='Cancelled' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'", [$user_id, $schedule_id]);
        $error = "This reservation expired after 10 minutes. Please reserve your seats again.";
    } else {
        mbus_sweep_open_payments($conn);
        $recent = $conn->pdo->prepare("SELECT COUNT(*) FROM paymongo_checkout WHERE user_id = ? AND session_id LIKE 'manual\\_%' AND status = 'Expired' AND submitted_at > NOW() - INTERVAL '1 hour'");
        $recent->execute([$user_id]);
        $pl = mbus_checkout_payload($reservations, $default_types);
        $error = $pl['error'];
        if($error === '' && (int)$recent->fetchColumn() >= 3){
            $error = "Several payments weren't completed. Please wait a few minutes before trying again.";
        }
        if($error === ''){
            try{
                $conn->pdo->beginTransaction();
                $sid = mbus_open_gcash_payment($conn, $user_id, $schedule_id, $pl);
                if($sid === null){ $conn->pdo->rollBack(); $error = "Payments are busy right now. Please try again in a moment."; }
                else {
                    // Hold the seats 15 minutes in total while the GCash payment shows up.
                    $conn->pdo->prepare("UPDATE reservation SET created_at = NOW() + INTERVAL '5 minutes' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'")->execute([$user_id, $schedule_id]);
                    $conn->pdo->commit();
                    header("Location: payment_return.php?schedule_id=" . (int)$schedule_id);
                    exit();
                }
            } catch(Throwable $e){
                if($conn->pdo->inTransaction()) $conn->pdo->rollBack();
                error_log('Start GCash payment failed: ' . $e->getMessage());
                $error = "Could not start the payment. Please try again.";
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
        
        if(paymongo_enabled()){
            $open = mbus_db_prepare($conn, "SELECT session_id FROM paymongo_checkout WHERE user_id = ? AND schedule_id = ? AND status = 'Created'", [$user_id, $schedule_id]);
            while($open !== false && ($o = mbus_db_fetch_assoc($open))){ paymongo_expire_session($o['session_id']); }
            mbus_db_prepare($conn, "UPDATE paymongo_checkout SET status = 'Cancelled' WHERE user_id = ? AND schedule_id = ? AND status = 'Created'", [$user_id, $schedule_id]);
        }
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
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
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

<?php if(paymongo_enabled()): ?>
<form method="POST" class="payment-form" id="payForm">
    <?php csrf_field(); ?>
    <div class="gcash-row">
        <span class="gcash-mark" aria-hidden="true"><i class="fa fa-mobile-alt"></i></span>
        <span class="gcash-text"><strong>GCash</strong><small>You&rsquo;ll be sent to GCash to approve the payment</small></span>
    </div>
    <button type="submit" name="pay_gcash" value="1" class="btn btn-primary btn-block"<?php echo $expired ? ' disabled' : ''; ?>>
        Pay &#8369;<span id="btnAmt"><?php echo number_format($total, 2); ?></span> with GCash
    </button>
    <small class="form-hint">Your seats stay held while you pay. Your ticket appears as soon as GCash confirms.</small>
</form>
<?php else:
    $gNum = mbus_setting($conn, 'gcash_number');
?>
<form method="POST" class="payment-form" id="payForm">
    <?php csrf_field(); ?>
    <div class="gcash-row">
        <span class="gcash-mark" aria-hidden="true"><i class="fa fa-mobile-alt"></i></span>
        <span class="gcash-text"><strong>Pay with GCash</strong><small>You&rsquo;ll get the exact amount to send. Your ticket appears by itself once it arrives.</small></span>
    </div>
    <?php if($gNum === ''): ?><p class="gcash-acct">GCash details have not been set up yet. Please ask the admin.</p><?php endif; ?>
    <button type="submit" name="start_gcash" value="1" class="btn btn-primary btn-block"<?php echo ($expired || $gNum === '') ? ' disabled' : ''; ?>>
        Pay &#8369;<span id="btnAmt"><?php echo number_format($total, 2); ?></span> with GCash
    </button>
</form>
<?php endif; ?>


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
