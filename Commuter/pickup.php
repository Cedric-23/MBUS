<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'commuter') {
    header('Location: ../login.php');
    exit;
}
include "../config/db_connect.php";

$schedule_id = filter_var($_GET['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$trip = null;
if ($schedule_id) {
    $q = mbus_db_prepare($conn, "
        SELECT s.schedule_id, s.departure_time, s.trip_status, b.bus_number, r.origin, r.destination
        FROM schedule s JOIN buses b ON b.bus_id = s.bus_id JOIN routes r ON r.route_id = s.route_id
        WHERE s.schedule_id = ?", [$schedule_id]);
    $trip = $q === false ? null : (mbus_db_fetch_assoc($q) ?: null);
}
if (!$trip) {
    header('Location: schedule.php');
    exit;
}
$profile_query = mbus_db_prepare($conn, "SELECT profile_picture FROM users WHERE user_id = ?", [(int)$_SESSION['user_id']]);
$profile_row = $profile_query === false ? null : mbus_db_fetch_assoc($profile_query);
$profile_picture = basename((string)($profile_row['profile_picture'] ?? ''));
$profile_image = ($profile_picture !== '' && $profile_picture !== 'default.png')
    ? '../Assets/profile_pictures/' . rawurlencode($profile_picture)
    : '../Assets/images/mbus_icon.png';
$config = [
    'csrfToken' => csrf_get_token(),
    'scheduleId' => (int)$trip['schedule_id'],
    'bus' => $trip['bus_number'],
    'route' => $trip['origin'] . ' to ' . $trip['destination'],
    'departure' => date('g:i A', strtotime($trip['departure_time'])),
    'profileImage' => $profile_image,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pick up - MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_pickup.css">
</head>
<body class="pickup-page">
    <div id="pickupMap" class="pickup-map" role="region" aria-label="Map showing you and the bus"></div>

    <a class="pickup-back" id="pickupBack" href="live.php" aria-label="Go back"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
    <p id="pickupToast" class="pickup-toast" role="status" aria-live="polite" hidden></p>
    <button type="button" id="pickupLocate" class="pickup-locate" aria-label="Use my current location"><i class="fa fa-location-crosshairs" aria-hidden="true"></i></button>

    <section class="pickup-sheet" aria-label="Pickup details">
        <div class="pickup-bus">
            <span class="pickup-bus-badge"><i class="fa fa-bus" aria-hidden="true"></i> <?php echo htmlspecialchars($trip['bus_number']); ?></span>
            <span class="pickup-bus-route"><?php echo htmlspecialchars($trip['origin'] . ' to ' . $trip['destination']); ?></span>
        </div>

        <p id="pickupEta" class="pickup-eta">Looking for the bus…</p>
        <p id="pickupHint" class="pickup-hint">Drag the blue pin to where you'll wait.</p>

        <label id="pickupLabelWrap" class="pickup-field">
            <span>Spot name <small>(optional)</small></span>
            <input id="pickupLabel" type="text" maxlength="100" autocomplete="off" placeholder="e.g. Main gate">
        </label>

        <div class="pickup-actions">
            <button type="button" id="pickupWait" class="pickup-btn pickup-btn-primary" disabled>I'm waiting here</button>
            <button type="button" id="pickupCancel" class="pickup-btn pickup-btn-ghost" hidden>Cancel waiting</button>
        </div>
    </section>

    <script>window.mbusPickup = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../Assets/js/commuter_pickup.js"></script>
</body>
</html>
