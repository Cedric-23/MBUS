<?php
session_start();
include "../config/db_connect.php";
require_once "../Includes/ticket_qr.php";
require_once "../Includes/ticket_checkout_data.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$requestedSchedule = filter_var($_GET['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reservationId = filter_var($_GET['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestedSchedule && !$reservationId) {
    die("Ticket not found.");
}

$user_id = (int)$_SESSION['user_id'];
$user_query = mbus_db_prepare($conn, "SELECT full_name FROM users WHERE user_id = ?", [$user_id]);
$user = $user_query === false ? false : mbus_db_fetch_assoc($user_query);
if (!$user) {
    die("User data unavailable.");
}

$ticketData = mbus_ticket_checkout_data($conn, $user_id, $requestedSchedule ?: null, $reservationId ?: null);
if (!$ticketData) {
    die("No paid reservation found.");
}
$schedule_id = (int)$ticketData['schedule_id'];
$reservations = $ticketData['reservations'];
$payment = $ticketData['payment'];
$fareLines = $ticketData['lines'];
$seats = array_map(static fn($row) => (string)$row['seat_number'], $reservations);
$seat_list = implode(", ", $seats);
$qr_token = mbus_ticket_token($schedule_id, $user_id);

$payment_method = (string)($payment['payment_method'] ?? '');
$payment_reference = (string)($payment['payment_reference'] ?? '');
$payment_date = (string)($payment['payment_date'] ?? '');
$ticket_code = (string)($payment['ticket_code'] ?? '');
$amount = $payment['amount'] ?? null;

$schedule_query = mbus_db_prepare($conn, "SELECT buses.bus_number, schedule.departure_time
    FROM schedule JOIN buses ON schedule.bus_id = buses.bus_id WHERE schedule.schedule_id = ?", [$schedule_id]);
$data = $schedule_query === false ? false : mbus_db_fetch_assoc($schedule_query);
if (!$data) {
    die("Schedule not found.");
}
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
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_mobile.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
</head>

<body class="has-bottom-nav ticket-page">
<header class="page-header" role="banner">
    <div class="page-header-left"><h1>Digital Ticket</h1></div>
    <div class="page-header-right"><div class="profile-menu-wrap">
        <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><img src="<?php $avatar = basename((string)($_SESSION['profile_picture'] ?? '')); echo htmlspecialchars(($avatar !== '' && $avatar !== 'default.png') ? '../Assets/profile_pictures/' . rawurlencode($avatar) : '../Assets/images/mbus_icon.png'); ?>" alt=""></span></button>
        <div class="profile-menu" id="profileMenu" role="menu">
            <div class="profile-menu-header"><span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span><span class="profile-menu-role">Commuter account</span></div>
            <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
            <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
        </div>
    </div></div>
</header>

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
        <div class="ticket-qr">
            <div id="ticketQr" class="ticket-qr-box" role="img" aria-label="Boarding QR code" data-token="<?php echo htmlspecialchars($qr_token); ?>"></div>
            <p class="ticket-qr-hint">Show this QR to the operator when you board</p>
            <p class="ticket-qr-code"><?php echo !empty($ticket_code) ? htmlspecialchars($ticket_code) : "N/A"; ?></p>
        </div>

        <!-- PASSENGER -->
        <div class="ticket-section">
            <div class="ticket-label">Passenger</div>
            <div class="ticket-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
        </div>

        <!-- EACH SEAT KEEPS ITS OWN PICKUP AND DESTINATION -->
        <div class="ticket-section ticket-seat-routes">
            <div class="ticket-label">Pickup and destination by seat</div>
            <?php foreach ($reservations as $reservation): ?>
                <div class="ticket-value">Seat <?php echo htmlspecialchars((string)$reservation['seat_number']); ?>:
                    <?php echo htmlspecialchars((string)$reservation['pickup_location']); ?> →
                    <?php echo htmlspecialchars((string)$reservation['destination']); ?>
                </div>
            <?php endforeach; ?>
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
                <?php foreach ($reservations as $reservation): $line = $fareLines[(int)$reservation['reservation_id']] ?? null; if ($line): ?>
                <div class="payment-item">
                    <div class="ticket-label">Seat <?php echo htmlspecialchars((string)$reservation['seat_number']); ?> fare</div>
                    <div class="ticket-value">₱<?php echo number_format((float)$line['base'], 2); ?> − ₱<?php echo number_format((float)$line['discount'], 2); ?> discount + ₱<?php echo number_format((float)$line['fee'], 2); ?> fee</div>
                </div>
                <?php endif; endforeach; ?>
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
            <a href="download_ticket.php?reservation_id=<?php echo (int)$reservations[0]['reservation_id']; ?>" class="btn btn-primary btn-block">
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

    <div class="ticket-footer">
        <p>This is your official digital ticket. Present this to the bus conductor upon boarding.</p>
    </div>

</div>

<script src="../Assets/js/vendor/qrcode.js"></script>
<script>
(function(){
    var box = document.getElementById('ticketQr');
    if (!box || typeof qrcode === 'undefined') return;
    var qr = qrcode(0, 'M');
    qr.addData(box.dataset.token);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
})();
</script>
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

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item"><i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
