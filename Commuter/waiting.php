<?php
require_once __DIR__ . '/../config/app.php';
session_start();
require_once __DIR__ . '/../Includes/csrf.php';
require_once __DIR__ . '/../config/db_connect.php';

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_type'] ?? '')) !== 'commuter') {
    header('Location: ../login.php');
    exit;
}

$avatar = basename((string)($_SESSION['profile_picture'] ?? ''));
$avatarPath = __DIR__ . '/../Assets/profile_pictures/' . $avatar;
$pq = mbus_db_prepare($conn, "SELECT LEFT(MD5(COALESCE(profile_picture_b64,'')),8) AS pic_v, (profile_picture_b64 IS NOT NULL AND profile_picture_b64 <> '') AS has_pic FROM users WHERE user_id = ?", [(int)$_SESSION['user_id']]);
$pr = $pq === false ? null : mbus_db_fetch_assoc($pq);
$hasDbPic = !empty($pr['has_pic']) && $pr['has_pic'] !== 'f';
$profileImage = $hasDbPic ? '../avatar.php?v=' . rawurlencode((string)$pr['pic_v']) : (($avatar !== '' && strtolower($avatar) !== 'default.png' && is_file($avatarPath))
    ? '../Assets/profile_pictures/' . rawurlencode($avatar) . '?v=' . rawurlencode((string)filemtime($avatarPath))
    : '../Assets/images/mbus_icon.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>Waiting — MBUS</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/commuter_waiting_board.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/commuter_waiting_board.css'); ?>">
</head>
<body class="commuter-waiting-board has-bottom-nav">
<main id="commuterWaitingBoard" class="cwb-main" data-endpoint="../api/live_tracking.php">
    <header class="cwb-header">
        <div class="cwb-title">
            <h1>Waiting</h1>
            <p id="cwbUpdated">Checking your waiting status…</p>
        </div>
        <button type="button" id="cwbRefresh" class="cwb-icon-button" aria-label="Refresh waiting status">
            <i class="fa fa-rotate" aria-hidden="true"></i>
        </button>
        <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><img src="<?php echo htmlspecialchars($profileImage, ENT_QUOTES); ?>" alt=""></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account', ENT_QUOTES); ?></span>
                    <span class="profile-menu-role">Commuter account</span>
                </div>
                <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </header>

    <div id="cwbEmpty" class="cwb-empty" hidden>
        <span class="cwb-empty-icon"><i class="fa fa-hourglass-half" aria-hidden="true"></i></span>
        <h2>You’re not waiting yet</h2>
        <p>Choose a bus and pin your pickup spot first.</p>
        <a href="schedule.php" class="cwb-empty-action">Choose a bus</a>
    </div>

    <div id="cwbMap" class="cwb-map" role="region" aria-label="Map showing your waiting bus and pickup spot" hidden></div>
    <div id="cwbLocationPill" class="cwb-location-pill" hidden><span class="cwb-status-dot"></span><span id="cwbLocationText"></span></div>
    <p id="cwbMapError" class="cwb-map-error" role="status" hidden>Map tiles are unavailable right now. Your bus status is still shown below.</p>
    <p id="cwbNotice" class="cwb-notice" role="status" aria-live="polite" hidden></p>

    <section id="cwbSheet" class="cwb-sheet" aria-label="Your waiting bus" hidden>
        <div class="cwb-sheet-handle" aria-hidden="true"></div>
        <div class="cwb-sheet-head">
            <h2><span id="cwbCount">1</span> waiting</h2>
            <span id="cwbBusLiveCount" class="cwb-sheet-sub"></span>
        </div>
        <div class="cwb-route-controls">
            <p id="cwbRouteEstimate" class="cwb-route-estimate" role="status" aria-live="polite">Loading your bus route…</p>
            <button type="button" id="cwbRouteView" class="cwb-route-view" disabled>Full route</button>
        </div>
        <ul id="cwbList" class="cwb-list" aria-live="polite"></ul>
    </section>
</main>

<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="schedule.php" class="bottom-nav-item"><i class="fa fa-calendar" aria-hidden="true"></i><span>Schedule</span></a>
    <a href="live.php" class="bottom-nav-item"><i class="fa fa-location-dot" aria-hidden="true"></i><span>Live</span></a>
    <a href="my_reservations.php" class="bottom-nav-item"><i class="fa fa-ticket" aria-hidden="true"></i><span>Trips</span></a>
    <a href="waiting.php" class="bottom-nav-item active" aria-current="page"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>

<script>window.mbusWaitingBoard = <?php echo json_encode(['csrfToken' => csrf_get_token(), 'profileImage' => $profileImage], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/route_utils.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/route_utils.js'); ?>"></script>
<script src="../Assets/js/commuter_waiting_board.js?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/js/commuter_waiting_board.js'); ?>"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
