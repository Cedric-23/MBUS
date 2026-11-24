<?php
/* Driver: GCash account setup. The account commuters pay into belongs to the driver.
 * A change goes to an admin for approval; the Conductor then checks and approves each commuter payment (payments.php). */
session_start();
require_once "../config/db_connect.php";
require_once "../config/mysqli_compat.php";
require_once "../Includes/csrf.php";
require_once "../Includes/gcash_manual.php";
require_once "../Includes/staff_nav.php";

if (!isset($_SESSION['user_id']) || mbus_staff_role() !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = (int)$_SESSION['user_id'];
$success = $_SESSION['driver_gcash_flash'] ?? '';
unset($_SESSION['driver_gcash_flash']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_validate_token()) {
        $error = 'Security check failed. Please try again.';
    } else {
        $name = trim((string)($_POST['gcash_name'] ?? ''));
        $num = preg_replace('/\s+/', '', (string)($_POST['gcash_number'] ?? ''));
        if ($name === '' || strlen($name) > 80 || !preg_match('/^09\d{9}$/', $num)) {
            $error = 'Enter the account name and an 11-digit number starting with 09.';
        } else {
            $prev = mbus_gcash_pending($conn);
            $qr = $prev['qr'] ?? '';
            if (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] !== UPLOAD_ERR_NO_FILE) {
                $up = mbus_receipt_upload('gcash_qr', $error);
                if ($up) $qr = 'data:' . $up['mime'] . ';base64,' . $up['b64'];
            }
            if ($error === '') {
                mbus_set_setting($conn, 'gcash_pending', json_encode([
                    'name' => $name, 'number' => $num, 'qr' => $qr,
                    'by' => $driverId, 'by_name' => (string)($_SESSION['full_name'] ?? 'Driver'), 'at' => date('c'),
                ]));
                include_once "../Includes/activity_log.php";
                log_activity($conn, $driverId, 'Driver GCash account change submitted', 'Awaiting admin approval');
                $_SESSION['driver_gcash_flash'] = 'Sent to admin for approval. Commuters keep seeing the current account until it is approved.';
                header('Location: gcash_account.php');
                exit();
            }
        }
    }
}

$gName = mbus_setting($conn, 'gcash_name');
$gNum = mbus_setting($conn, 'gcash_number');
$gQr = mbus_setting($conn, 'gcash_qr');
$gPending = mbus_gcash_pending($conn);
$fullName = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Driver'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
    <title>GCash account — MBUS Driver</title>
    <link rel="icon" href="../Assets/images/mbus_icon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/operator/operator_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_payments.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/operator_payments.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
</head>
<body class="stf has-bottom-nav pay-page">

<main class="main" role="main">
<header class="page-header">
    <div class="page-header-left"><h1>GCash account</h1></div>
    <div class="page-header-right">
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>

        <div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                <span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-name"><?php echo $fullName; ?></span>
                    <span class="profile-menu-role">Driver account</span>
                </div>
                <?php echo mbus_staff_profile_links('driver', 'gcash'); ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div>
    </div>
</header>

<div class="pay-wrap">

<?php if ($success !== ''): ?><div class="pay-alert ok" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="pay-alert bad" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($gNum === ''): ?>
<div class="pay-alert warn" role="status"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> No GCash account is set up yet. Add yours below so commuters can pay.</div>
<?php endif; ?>

<?php if ($gNum !== ''): ?>
<section class="gc-card" aria-label="Current GCash account">
    <div class="gc-top"><h2>Current account</h2><span class="pay-badge ok">Approved</span></div>
    <div class="gc-body">
        <?php if ($gQr !== ''): ?><img class="gc-qr" src="<?php echo htmlspecialchars($gQr, ENT_QUOTES); ?>" alt="GCash QR code"><?php endif; ?>
        <div>
            <p class="gc-name"><?php echo htmlspecialchars($gName); ?></p>
            <p class="gc-num"><?php echo htmlspecialchars($gNum); ?></p>
        </div>
    </div>
</section>
<?php endif; ?>

<details class="pay-panel" <?php echo ($gNum === '' || $gPending) ? 'open' : ''; ?>>
    <summary><i class="fa fa-mobile-screen" aria-hidden="true"></i> <?php echo $gNum === '' ? 'Set up GCash account' : 'Change GCash account'; ?><?php if ($gPending): ?> <span class="pay-badge">Pending approval</span><?php endif; ?></summary>
    <?php if ($gPending): ?><p class="pay-note">Waiting for admin: <strong><?php echo htmlspecialchars($gPending['name']); ?></strong> &middot; <?php echo htmlspecialchars($gPending['number']); ?>. Saving again replaces this request.</p><?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="pay-form">
        <?php csrf_field(); ?>
        <label>Account name<input type="text" name="gcash_name" value="<?php echo htmlspecialchars($gName); ?>" required maxlength="80" autocomplete="off"></label>
        <label>GCash number<input type="tel" name="gcash_number" value="<?php echo htmlspecialchars($gNum); ?>" placeholder="09XXXXXXXXX" pattern="09\d{9}" maxlength="11" inputmode="numeric" required></label>
        <label>QR code <small>(optional)</small><input type="file" name="gcash_qr" accept="image/jpeg,image/png"></label>
        <button type="submit" name="save_settings" value="1" class="pay-btn primary">Send for approval</button>
    </form>
</details>


</div>
</main>

<?php echo mbus_staff_bottom_nav('driver', ''); ?>
<script src="../Assets/js/app-header.js"></script>
<script>window.MBUS_NOTIFICATIONS_ENDPOINT = 'notifications.php';</script>
<script src="../Assets/js/notifications.js"></script>
</body>
</html>
