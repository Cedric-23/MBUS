<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

include "../config/db_connect.php";
include "../Includes/csrf.php";

// Handle mark as read
if(isset($_POST['mark_read'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $notification_id = (isset($_POST['notification_id']) && is_scalar($_POST['notification_id'])) ? (int)$_POST['notification_id'] : 0;
        $result = mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE notification_id = ? AND user_id = ?", [$notification_id, $_SESSION['user_id']]);
        if($result === false){ $error = "Failed to update notification"; }
        else { $success = "Notification marked as read"; }
    }
}

// Handle mark all as read
if(isset($_POST['mark_all_read'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        $result = mbus_db_prepare($conn, "UPDATE notification SET status = 'Read' WHERE user_id = ? AND status = 'Unread'", [$user_id]);
        if($result === false){ $error = "Failed to update notifications"; }
        else { $success = "All notifications marked as read"; }
    }
}

// Handle delete
if(isset($_POST['delete'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $notification_id = (isset($_POST['notification_id']) && is_scalar($_POST['notification_id'])) ? (int)$_POST['notification_id'] : 0;
        $result = mbus_db_prepare($conn, "DELETE FROM notification WHERE notification_id = ? AND user_id = ?", [$notification_id, $_SESSION['user_id']]);
        if($result === false){ $error = "Failed to delete notification"; }
        else { $success = "Notification deleted"; }
    }
}

// Handle clear all
if(isset($_POST['clear_all'])){
    if(!csrf_validate_token()){
        $error = "Security validation failed";
    } else {
        $user_id = $_SESSION['user_id'];
        $result = mbus_db_prepare($conn, "DELETE FROM notification WHERE user_id = ?", [$user_id]);
        if($result === false){ $error = "Failed to clear notifications"; }
        else { $success = "All notifications cleared"; }
    }
}

// Fetch notifications
$user_id = $_SESSION['user_id'];
$notifications = mbus_db_prepare($conn, "
    SELECT * FROM notification
    WHERE user_id = ?
    ORDER BY notification_date DESC
    LIMIT 50
", [$user_id]);

if($notifications === false){
    http_response_code(500);
    die("Unable to load notifications. Please try again.");
}
$unread_result = mbus_db_prepare($conn, "
    SELECT COUNT(*) as count FROM notification
    WHERE user_id = ? AND status = 'Unread'
", [$user_id]);
if($unread_result === false){
    http_response_code(500);
    die("Unable to load notification count. Please try again.");
}
$unread_row = mbus_db_fetch_assoc($unread_result);
$unread_count = (int)($unread_row['count'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Commuter</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/notification-page.css">
    <link rel="stylesheet" href="../Assets/css/live_pickup.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="shortcut icon" href="../Assets/images/mbus_icon.png">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
</head>

<body class="has-bottom-nav">

<main class="main" role="main">

<header class="page-header">
    <h1>Notifications</h1>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
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

<?php if(isset($success)): ?>
<div class="alert alert-success" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="alert alert-error" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Waiting location: rider selects their own location for the assigned operator. -->
<section class="live-card waiting-alert-card" aria-labelledby="waitingHeading">
    <div class="live-card-head"><div><h2 id="waitingHeading">Waiting location</h2><p class="waiting-alert-intro">If you couldn’t reserve, share your location with the operator assigned to an active Boarding or Departed bus. This is your location only; this page does not track the bus.</p></div></div>
    <p id="waitingMessage" class="live-notice" role="status" aria-live="polite">Choose your bus and location. Nothing is shared until you confirm.</p>
    <div id="waitingOwnRequest" class="waiting-own-request" hidden><span class="live-status" id="waitingOwnStatus">Waiting</span><p id="waitingOwnDetails" class="live-request-title"></p><p class="live-small">Your waiting location is available to the assigned operator for this trip.</p><button id="cancelWaitingBtn" type="button" class="live-text-button live-danger">Cancel my waiting request</button></div>
    <div id="waitingPicker">
        <div class="live-field"><label for="waitingSchedule">Active bus</label><select id="waitingSchedule"><option value="">Loading active buses…</option></select><small>Only buses currently Boarding or Departed are shown.</small></div>
        <div class="waiting-picker-grid">
            <div><div id="waitingMap" class="live-map" aria-label="Choose your waiting location on the map"></div><p id="waitingMapMessage" class="live-small">Tap the map or drag the pin to your current location.</p></div>
            <div><button id="locateWaitingBtn" type="button" class="live-button live-button-secondary">Use my current location (GPS)</button>
                <details id="waitingCoordinates" class="live-coordinate-entry"><summary>Enter coordinates manually instead</summary><div class="live-coordinate-fields"><label>Latitude<input id="waitingLatitude" type="number" inputmode="decimal" step="any" min="-90" max="90" placeholder="e.g. 14.5995"></label><label>Longitude<input id="waitingLongitude" type="number" inputmode="decimal" step="any" min="-180" max="180" placeholder="e.g. 120.9842"></label></div></details>
                <div class="live-field"><label for="waitingLabel">Nearby landmark (optional)</label><input id="waitingLabel" type="text" maxlength="100" autocomplete="off" placeholder="e.g. Main Gate bus stop"></div>
                <p id="waitingCoordinateText" class="live-small">Choose your location using GPS, the map, or coordinates.</p>
                <p class="live-privacy">Selecting a point does not send it. Review your selected location, then explicitly confirm to share it with the assigned operator.</p>
                <button id="shareWaitingBtn" type="button" class="live-button live-button-primary" disabled>Share waiting location</button>
            </div>
        </div>
    </div>
</section>
<!-- Stats Card -->
<section class="card" style="margin-bottom: var(--space-6);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-4);">
        <div>
            <h3 style="margin: 0 0 var(--space-1); font-size: var(--fs-lg); font-weight: var(--fw-semibold);">
                <i class="fa fa-bell" aria-hidden="true"></i> <?php echo $unread_count; ?> Unread
            </h3>
            <p style="margin: 0; color: var(--color-text-muted); font-size: var(--fs-sm);">
                Total: <?php echo mbus_db_num_rows($notifications); ?> notifications
            </p>
        </div>
        <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
            <form method="POST" style="display: inline;">
                <?php csrf_field(); ?>
                <button type="submit" name="mark_all_read" class="btn btn-secondary">
                    <i class="fa fa-check-double" aria-hidden="true"></i> Mark All Read
                </button>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clear all notifications?');">
                <?php csrf_field(); ?>
                <button type="submit" name="clear_all" class="btn btn-danger">
                    <i class="fa fa-trash" aria-hidden="true"></i> Clear All
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Notifications List -->
<section class="card">
    <?php if(mbus_db_num_rows($notifications) > 0): ?>
        <div class="notification-list-page">
            <?php while($notif = mbus_db_fetch_assoc($notifications)): ?>
            <div class="notification-item-page <?php echo $notif['status'] === 'Unread' ? 'unread' : ''; ?>">
                <div class="notification-content">
                    <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                    <div class="notification-time"><?php echo date('M j, Y g:i A', strtotime($notif['notification_date'])); ?></div>
                </div>
                <div class="notification-actions-page">
                    <?php if($notif['status'] === 'Unread'): ?>
                    <form method="POST" style="display: inline;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                        <button type="submit" name="mark_read" class="btn btn-sm btn-outline">
                            <i class="fa fa-check" aria-hidden="true"></i> Mark Read
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                        <button type="submit" name="delete" class="btn btn-sm btn-danger">
                            <i class="fa fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="notification-empty-page">
            <i class="fa fa-bell-slash" aria-hidden="true"></i>
            <p>No notifications</p>
        </div>
    <?php endif; ?>
</section>

</main>

<!-- Bottom Navigation -->
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i>
        <span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i>
        <span>Schedule</span>
    </a>
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i>
        <span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="notifications.php" class="bottom-nav-item active" aria-current="page">
        <i class="fa fa-bell" aria-hidden="true"></i>
        <span>Alerts</span>
    </a>
</nav>

<script>window.mbusWaitingConfig = <?php echo json_encode(['csrfToken' => csrf_get_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../Assets/js/commuter_waiting_alerts.js"></script>
<script src="../Assets/js/app-header.js"></script>
</body>
</html>
