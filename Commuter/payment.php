<?php
session_start();
header('Cache-Control: no-store, max-age=0'); // never show an old copy (and an old timer) after Back
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
$requested_reservation_id = filter_var($_GET['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reservation_filter = '';
$reservation_filter_params = [];
if($requested_reservation_id){
    $transaction_lookup = $conn->pdo->prepare("SELECT reservation_ids FROM paymongo_checkout
        WHERE user_id = ? AND schedule_id = ? AND status IN ('Created', 'Submitted')
        ORDER BY created_at DESC");
    $transaction_lookup->execute([$user_id, $schedule_id]);
    $transaction_ids = [(int)$requested_reservation_id];
    $matched_checkout = false;
    foreach($transaction_lookup->fetchAll(PDO::FETCH_COLUMN) as $ids_json){
        $linked = json_decode((string)$ids_json, true);
        if(is_array($linked) && in_array((int)$requested_reservation_id, array_map('intval', $linked), true)){
            $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $linked), static fn($id) => $id > 0)));
            $matched_checkout = true;
            break;
        }
    }
    if(!$matched_checkout){
        // No payment has been opened yet. Seats reserved together share one created_at,
        // so they are paid as one booking: every seat of it, not just the one in the link.
        $booking_lookup = $conn->pdo->prepare("SELECT reservation_id FROM reservation
            WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'
            AND created_at = (SELECT created_at FROM reservation WHERE reservation_id = ? AND user_id = ?)");
        $booking_lookup->execute([$user_id, $schedule_id, $requested_reservation_id, $user_id]);
        $booking_ids = array_values(array_filter(array_map('intval', $booking_lookup->fetchAll(PDO::FETCH_COLUMN)), static fn($id) => $id > 0));
        if($booking_ids){
            $transaction_ids = $booking_ids;
        }
    }
    $reservation_filter = ' AND reservation.reservation_id IN (' . implode(',', array_fill(0, count($transaction_ids), '?')) . ')';
    $reservation_filter_params = $transaction_ids;
}

// Authorization check - verify user has pending reservations for this schedule
$auth_check = mbus_db_prepare($conn, "
    SELECT COUNT(*) as count
    FROM reservation
    WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'" . $reservation_filter . "
", array_merge([$user_id, $schedule_id], $reservation_filter_params));

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
    AND reservation.status = 'Pending'" . $reservation_filter . "
    ORDER BY reservation.reservation_id
    ", array_merge([$user_id, $schedule_id], $reservation_filter_params));

if(mbus_db_num_rows($res_query) <= 0){
    die("No pending reservation found.");
}

$reservations = [];
while($row = mbus_db_fetch_assoc($res_query)){
    $reservations[] = $row;
}

// The hold ends when the EARLIEST pending seat expires (reserve.php cancels each seat on its own clock).
$expiry = min(array_map(function($r){ return strtotime((string)$r['expires_at']); }, $reservations)); // The server-stored hold deadline shared by every page.
$remaining_seconds = max(0, $expiry - time()); // counted down in the browser without trusting the phone's clock

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
$expired = time() >= $expiry;

$error = "";

// A receipt for this transaction is already waiting for an admin: no second payment screen.
$submittedForSelection = false;
$sub = $conn->pdo->prepare("SELECT reservation_ids FROM paymongo_checkout
    WHERE user_id = ? AND schedule_id = ? AND status = 'Submitted'");
$sub->execute([$user_id, $schedule_id]);
foreach($sub->fetchAll(PDO::FETCH_COLUMN) as $ids_json){
    $linked = json_decode((string)$ids_json, true);
    if(!$requested_reservation_id || (is_array($linked) && in_array((int)$requested_reservation_id, array_map('intval', $linked), true))){
        $submittedForSelection = true;
        break;
    }
}
if($submittedForSelection){ header("Location: payment_return.php?schedule_id=" . (int)$schedule_id); exit(); }


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
                    $conn->pdo->prepare("UPDATE reservation SET expires_at = NOW() + INTERVAL '10 minutes' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'")->execute([$user_id, $schedule_id]);
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
                    $conn->pdo->prepare("UPDATE reservation SET expires_at = NOW() + INTERVAL '15 minutes' WHERE user_id = ? AND schedule_id = ? AND status = 'Pending'")->execute([$user_id, $schedule_id]);
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

/* REMOVE ONE SEAT from this booking (the other seats stay and are paid together) */
if(isset($_POST['cancel_seat'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $seatId = filter_var($_POST['seat_reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $shownIds = array_map('intval', array_column($reservations, 'reservation_id'));
        if(!$seatId || !in_array((int)$seatId, $shownIds, true) || count($shownIds) < 2){
            $error = "That seat is no longer part of this booking.";
        } else {
            $remoteSessions = [];
            try {
                $conn->pdo->beginTransaction();
                // An open card payment that included this seat no longer matches: close it.
                $open = $conn->pdo->prepare("SELECT session_id, reservation_ids FROM paymongo_checkout
                    WHERE user_id = ? AND schedule_id = ? AND status = 'Created' FOR UPDATE");
                $open->execute([$user_id, $schedule_id]);
                foreach($open->fetchAll(PDO::FETCH_ASSOC) as $checkout){
                    $linked = json_decode((string)$checkout['reservation_ids'], true);
                    if(!is_array($linked) || !in_array((int)$seatId, array_map('intval', $linked), true)) continue;
                    $conn->pdo->prepare("UPDATE paymongo_checkout SET status = 'Cancelled' WHERE session_id = ? AND status = 'Created'")->execute([$checkout['session_id']]);
                    if(strpos((string)$checkout['session_id'], 'manual_') !== 0) $remoteSessions[] = (string)$checkout['session_id'];
                }
                $upd = $conn->pdo->prepare("UPDATE reservation SET status = 'Cancelled'
                    WHERE reservation_id = ? AND user_id = ? AND schedule_id = ? AND status = 'Pending'");
                $upd->execute([$seatId, $user_id, $schedule_id]);
                if($upd->rowCount() < 1) throw new RuntimeException('seat_not_pending');
                $conn->pdo->commit();
            } catch(Throwable $e){
                if($conn->pdo->inTransaction()) $conn->pdo->rollBack();
                $error = ($e instanceof RuntimeException && $e->getMessage() === 'seat_not_pending')
                    ? 'That seat is no longer pending.' : 'Could not remove that seat. Please try again.';
            }
            foreach(array_unique($remoteSessions) as $sessionId){
                try { paymongo_expire_session($sessionId); } catch(Throwable $e){ error_log('Could not expire PayMongo checkout: ' . $e->getMessage()); }
            }
            if($error === ''){
                log_activity($conn, $user_id, ACTION_RESERVATION_CANCEL, "Removed seat reservation $seatId from schedule $schedule_id booking");
                $left = array_values(array_diff($shownIds, [(int)$seatId]));
                header("Location: payment.php?schedule_id=" . (int)$schedule_id . "&reservation_id=" . (int)$left[0]);
                exit();
            }
        }
    }
}

/* CANCEL THE WHOLE BOOKING */
if(isset($_POST['cancel_reservation'])){
    // Validate CSRF token
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } else {
        $cancelId = filter_var($_POST['reservation_id'] ?? ($reservations[0]['reservation_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // every seat shown on this page belongs to the booking being cancelled
        $cancelIds = array_map('intval', array_column($reservations, 'reservation_id'));
        $remoteSessions = [];
        try {
            $conn->pdo->beginTransaction();
            $checkoutQuery = $conn->pdo->prepare("SELECT session_id, status, reservation_ids
                FROM paymongo_checkout
                WHERE user_id = ? AND schedule_id = ? AND status IN ('Created', 'Submitted')
                ORDER BY created_at DESC FOR UPDATE");
            $checkoutQuery->execute([$user_id, $schedule_id]);
            foreach($checkoutQuery->fetchAll(PDO::FETCH_ASSOC) as $checkout){
                $linked = json_decode((string)$checkout['reservation_ids'], true);
                if(!is_array($linked)) continue;
                $linked = array_values(array_unique(array_filter(array_map('intval', $linked), static fn($id) => $id > 0)));
                if(!$cancelId || !in_array((int)$cancelId, $linked, true)) continue;
                $cancelIds = array_merge($cancelIds, $linked);
                if($checkout['status'] === 'Created' && strpos((string)$checkout['session_id'], 'manual_') !== 0){
                    $remoteSessions[] = (string)$checkout['session_id'];
                }
                $conn->pdo->prepare("UPDATE paymongo_checkout SET status = 'Cancelled'
                    WHERE session_id = ? AND status IN ('Created', 'Submitted')")
                    ->execute([$checkout['session_id']]);
                break;
            }
            $cancelIds = array_values(array_unique(array_filter(array_map('intval', $cancelIds), static fn($id) => $id > 0)));
            $placeholders = implode(',', array_fill(0, count($cancelIds), '?'));
            $update = $conn->pdo->prepare("UPDATE reservation SET status = 'Cancelled'
                WHERE user_id = ? AND schedule_id = ? AND reservation_id IN ($placeholders) AND status = 'Pending'");
            $update->execute(array_merge([$user_id, $schedule_id], $cancelIds));
            if($update->rowCount() < 1) throw new RuntimeException('reservation_not_pending');
            $conn->pdo->commit();
        } catch(Throwable $e){
            if($conn->pdo->inTransaction()) $conn->pdo->rollBack();
            $error = $e instanceof RuntimeException && $e->getMessage() === 'reservation_not_pending'
                ? 'This reservation is no longer pending.' : 'Could not cancel this reservation. Please try again.';
        }
        foreach(array_unique($remoteSessions) as $sessionId){
            try { paymongo_expire_session($sessionId); } catch(Throwable $e){ error_log('Could not expire PayMongo checkout: ' . $e->getMessage()); }
        }
        if($error === ''){
            log_activity($conn, $user_id, ACTION_RESERVATION_CANCEL, "Cancelled transaction for schedule $schedule_id");
            header("Location: schedule.php?cancelled=1");
            exit();
        }
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
<link rel="stylesheet" href="../Assets/css/commuter_payment.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/commuter_payment.css'); ?>">

    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/commuter_mobile.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/commuter_modern.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/commuter_modern.css'); ?>">
    <style>
    :root{--u-col:1040px}
    /* a seat row with a remove button: seat tag | stops | price | x */
    body.payment-page.cm .pay-seat-row.has-x{grid-template-columns:auto minmax(0,1fr) auto auto}
    .pay-seat-x-form{margin:0}
    .pay-seat-x{display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#f3f6fb;color:#b42318;font-size:.85rem;cursor:pointer}
    .pay-seat-x:hover{background:#fdecea}
    .pay-seat-x:focus-visible{outline:3px solid rgba(47,111,228,.4);outline-offset:2px}
    .pay-seats-note{margin:8px 0 0;font-size:.78rem;color:#7a8aa0}
    </style>
</head>

<body class="payment-page cm">

<main class="main" role="main" id="paymentPageMain">

<header class="page-header">
    <div class="page-header-left">
        <a href="my_reservations.php" class="back-btn" aria-label="Back to my reservations"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
        <h1>Payment</h1>
    </div>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
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

<div class="pay-meta">
    <div><span>Bus</span><strong><?php echo htmlspecialchars($reservations[0]['bus_number']); ?></strong></div>
    <div><span>Departs</span><strong><?php echo date("M j", strtotime($reservations[0]['departure_time'])); ?><em><?php echo date("g:i A", strtotime($reservations[0]['departure_time'])); ?></em></strong></div>
    <div><span>Arrives</span><strong><?php echo date("M j", strtotime($reservations[0]['arrival_time'])); ?><em><?php echo date("g:i A", strtotime($reservations[0]['arrival_time'])); ?></em></strong></div>
</div>

<?php
    $stopBySeat = [];
    foreach($reservations as $reservation){ $stopBySeat[(string)$reservation['seat_number']] = $reservation['pickup_location'] . ' → ' . $reservation['destination']; }
    $canRemoveSeat = count($quote['lines']) > 1;
?>
<ul class="pay-seats">
    <?php foreach($quote['lines'] as $ln){ $sk = (string)$ln['seat']; ?>
    <li class="pay-seat-row<?php echo $canRemoveSeat ? ' has-x' : ''; ?>">
        <span class="seat-tag">Seat <?php echo htmlspecialchars($sk); ?></span>
        <span class="pay-seat-info">
            <span class="pay-seat-stop"><?php echo htmlspecialchars($stopBySeat[$sk] ?? ''); ?></span>
            <small class="pay-seat-break"><?php echo htmlspecialchars($ln['type']); ?> &middot; &#8369;<?php echo number_format($ln['base'], 2); ?><?php if($ln['discount'] > 0): ?> &minus; &#8369;<?php echo number_format($ln['discount'], 2); ?><?php endif; ?> + &#8369;<?php echo number_format($ln['fee'], 2); ?> fee</small>
        </span>
        <span class="pay-line">&#8369;<?php echo number_format($ln['final'], 2); ?></span>
        <?php if($canRemoveSeat){ ?>
        <form method="POST" class="pay-seat-x-form" onsubmit="return confirm('Remove seat <?php echo htmlspecialchars($sk, ENT_QUOTES); ?> from this booking? Your other seats stay.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="seat_reservation_id" value="<?php echo (int)($ln['reservation_id'] ?? 0); ?>">
            <button type="submit" name="cancel_seat" value="1" class="pay-seat-x" aria-label="Remove seat <?php echo htmlspecialchars($sk); ?>"><i class="fa fa-times" aria-hidden="true"></i></button>
        </form>
        <?php } ?>
    </li>
    <?php } ?>
</ul>
<?php if($canRemoveSeat){ ?><p class="pay-seats-note">You pay for all <?php echo count($quote['lines']); ?> seats together. Tap &times; to drop a seat you don&rsquo;t need.</p><?php } ?>
<div class="pay-total"><span>Total</span><strong>&#8369;<span id="totalAmt"><?php echo number_format($total, 2); ?></span></strong></div>
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
    <?php if($gNum === ''): ?><p class="gcash-acct">GCash details have not been set up yet. Please ask an operator.</p><?php endif; ?>
    <button type="submit" name="start_gcash" value="1" class="btn btn-primary btn-block"<?php echo ($expired || $gNum === '') ? ' disabled' : ''; ?>>
        Pay &#8369;<span id="btnAmt"><?php echo number_format($total, 2); ?></span> with GCash
    </button>
</form>
<?php endif; ?>

<form method="POST" class="cancel-form" onsubmit="return confirm('Cancel this reservation and release your seats?');">
    <?php csrf_field(); ?>
    <input type="hidden" name="reservation_id" value="<?php echo (int)($reservations[0]['reservation_id'] ?? 0); ?>">
    <button type="submit" name="cancel_reservation" value="1" class="btn btn-block btn-cancel-reservation"><?php echo $canRemoveSeat ? 'Cancel all seats' : 'Cancel reservation'; ?></button>
</form>

</div>

</div>

</div>

</main>

<script>
let countdownInterval;

function startCountdown() {
    // Seconds left as told by the server, counted down with the browser's own stopwatch,
    // so a wrong phone clock (or coming back to a cached page) can't change the time.
    const t0 = performance.now();
    const expiry = Date.now() + <?php echo (int)$remaining_seconds; ?> * 1000;
    const countdownEl = document.getElementById('countdown');
    const timerBox = document.getElementById('timerBox');
    
    function update() {
        const diff = <?php echo (int)$remaining_seconds; ?> * 1000 - (performance.now() - t0);
        
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
// Coming back with the Back button can show a cached copy with an old timer: load a fresh one.
window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.reload(); });
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
