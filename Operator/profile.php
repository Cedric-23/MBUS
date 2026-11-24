<?php
session_start();
require_once __DIR__ . '/../Includes/staff_nav.php';
include_once __DIR__ . '/../config/db_connect.php';
include_once __DIR__ . '/../Includes/profile_photo.php';
include_once __DIR__ . '/../Includes/validation.php';
include_once __DIR__ . '/../Includes/csrf.php';
include_once __DIR__ . '/../Includes/phone_unique.php';
require_once __DIR__ . '/../Includes/id_renewal.php';
require_once __DIR__ . '/../Includes/notify.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower((string)($_SESSION['user_type'] ?? '')), ['driver', 'conductor'], true)) {
    header('Location: ../login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$isConductor = strtolower((string)$_SESSION['user_type']) === 'conductor';
$staffLabel = $isConductor ? 'Conductor' : 'Driver';
$loadProfile = static function () use ($conn, $userId) {
    $query = mbus_db_prepare($conn, "SELECT full_name, email, phone_number FROM users WHERE user_id = ?", [$userId]);
    return $query === false ? false : mbus_db_fetch_assoc($query);
};
$profile = $loadProfile();
if (!$profile) {
    http_response_code(404);
    exit($staffLabel . ' account not found.');
}

[$photoOk, $photoErr] = mbus_photo_handle($conn, $userId);
$profileErrors = [];
$profileSuccess = '';
$formValues = [
    'full_name' => (string)$profile['full_name'],
    'email' => (string)$profile['email'],
    'phone_number' => preg_replace('/^0/', '', (string)($profile['phone_number'] ?? '')),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['update_profile'])) {
    $formValues = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'phone_number' => trim((string)($_POST['phone_number'] ?? '')),
    ];

    if (!csrf_validate_token()) {
        $profileErrors['form'] = 'Security validation failed. Please reload and try again.';
    } else {
        $nameError = mbus_check_full_name($formValues['full_name']);
        if ($nameError !== '') $profileErrors['full_name'] = $nameError;

        if ($formValues['email'] === '') {
            $profileErrors['email'] = 'Email address is required.';
        } elseif (strlen($formValues['email']) > 100) {
            $profileErrors['email'] = 'Email address must be 100 characters or fewer.';
        } elseif (filter_var($formValues['email'], FILTER_VALIDATE_EMAIL) === false) {
            $profileErrors['email'] = 'Enter a valid email address.';
        }

        $phoneError = mbus_check_phone($formValues['phone_number']);
        if ($phoneError !== '') $profileErrors['phone_number'] = $phoneError;

        if (!isset($profileErrors['email'])) {
            $duplicate = mbus_db_prepare($conn, "SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ? LIMIT 1", [$formValues['email'], $userId]);
            if ($duplicate === false) {
                $profileErrors['form'] = 'Unable to check the email address right now. Please try again.';
            } elseif (mbus_db_fetch_assoc($duplicate)) {
                $profileErrors['email'] = 'That email address is already linked to another account.';
            }
        }

        if (!isset($profileErrors['phone_number']) && $formValues['phone_number'] !== '' && mbus_phone_in_use($conn, (string)$formValues['phone_number'], (int)$userId) !== false) {
            $profileErrors['phone_number'] = 'That mobile number is already used by another account.';
        }

        if (!$profileErrors) {
            $updated = mbus_db_prepare($conn, "UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE user_id = ?", [
                $formValues['full_name'], $formValues['email'], $formValues['phone_number'], $userId,
            ]);
            if ($updated === false) {
                error_log('Operator profile update failed: ' . mbus_db_error($conn));
                $profileErrors['form'] = 'Your changes could not be saved. Please try again.';
            } else {
                $_SESSION['full_name'] = $formValues['full_name'];
                $_SESSION['email'] = $formValues['email'];
                $_SESSION['phone_number'] = $formValues['phone_number'];
                $profileSuccess = 'Profile details saved.';
                $profile = $loadProfile();
                if ($profile) {
                    $formValues = [
                        'full_name' => (string)$profile['full_name'],
                        'email' => (string)$profile['email'],
                    'phone_number' => preg_replace('/^0/', '', (string)($profile['phone_number'] ?? '')),
                    ];
                }
            }
        }
    }
}

/* ---- Driver ID renewal: new expiry date + photo, sent to the admin for approval ---- */
$renewError = ''; $renewSuccess = '';
if (!$isConductor && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['submit_renewal'])) {
    $latestRenewal = mbus_renewal_latest($conn, $userId);
    $newExpiry = trim((string)($_POST['new_expiry'] ?? ''));
    $file = $_FILES['renewal_document'] ?? null;
    $d = DateTime::createFromFormat('Y-m-d', $newExpiry);
    if (!csrf_validate_token()) {
        $renewError = 'Security validation failed. Please reload and try again.';
    } elseif ($latestRenewal && $latestRenewal['status'] === 'Pending') {
        $renewError = 'You already have a renewal waiting for approval.';
    } elseif (!$d || $d->format('Y-m-d') !== $newExpiry) {
        $renewError = 'Enter the new expiry date shown on your ID.';
    } elseif ($newExpiry <= date('Y-m-d')) {
        $renewError = 'That date has already passed. Use your renewed ID.';
    } elseif ($newExpiry > date('Y-m-d', strtotime('+15 years'))) {
        $renewError = 'That expiry date is too far ahead. Check the year.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $renewError = 'Upload a photo of your renewed ID.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $renewError = 'File is too large. Maximum is 5 MB.';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            $renewError = 'Only JPG, PNG or PDF files are allowed.';
        } else {
            $ok = mbus_db_prepare($conn, "INSERT INTO id_renewal (user_id, new_expiry, document_b64, document_mime) VALUES (?, ?, ?, ?)",
                [$userId, $newExpiry, base64_encode(file_get_contents($file['tmp_name'])), $mime]);
            if ($ok === false) {
                $renewError = 'Could not send your renewal. Please try again.';
            } else {
                $renewSuccess = 'Sent. An admin will review your renewed ID shortly.';
                mbus_notify_admins($conn, ($_SESSION['full_name'] ?? 'A driver') . ' sent a renewed ID for approval.');
            }
        }
    }
}
if (!$isConductor) {
    $idStatus = mbus_driver_id_status($conn, $userId);
    $renewal = mbus_renewal_latest($conn, $userId);
}

$name = htmlspecialchars((string)($_SESSION['full_name'] ?? $staffLabel));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo $staffLabel; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css"><link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/app-header.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_profile.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/operator_profile.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=3">
    <link rel="stylesheet" href="../Assets/css/staff_modern.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/staff_modern.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/notif_bell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../Assets/css/notif_bell.css'); ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES); ?>">
</head>
<body class="stf has-bottom-nav profile-page operator-profile">
<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>My Profile</h1></div>
        <div class="page-header-right">
                <div class="notif-wrap">
                    <button class="notif-btn" type="button" id="notifBtn" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications"><i class="fa fa-bell" aria-hidden="true"></i><span class="notif-dot" id="notifDot" hidden>0</span></button>
                    <div class="notif-panel" id="notifPanel" hidden><h2>Notifications</h2><ul id="notifList" class="notif-list"></ul></div>
                </div>
<div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span></button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role"><?php echo $staffLabel; ?> account</span></div>
                <?php echo mbus_staff_profile_links($isConductor ? 'conductor' : 'driver', 'profile'); ?>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div></div>
    </header>

    <?php mbus_photo_card($conn, $userId, (string)($_SESSION['full_name'] ?? $staffLabel), $staffLabel . ' account', $photoOk, $photoErr); ?>
    <?php if ($profileSuccess !== ''): ?><div class="alert alert-success" role="status" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($profileSuccess); ?></div><?php endif; ?>
    <?php if (isset($profileErrors['form'])): ?><div class="alert alert-error" role="alert" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($profileErrors['form']); ?></div><?php endif; ?>

    <section class="card pf-form" style="max-width:720px;margin:0 auto 16px;">
        <h2><i class="fa fa-user-pen" aria-hidden="true"></i> Account details</h2>
        <p class="form-hint">Update the details used for your <?php echo strtolower($staffLabel); ?> account and future sign-ins.</p>
        <form method="POST" id="operatorProfileForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="update_profile" value="1">
            <div class="pf-field">
                <label for="operatorFullName">Full name</label>
                <input class="form-control" type="text" id="operatorFullName" name="full_name" value="<?php echo htmlspecialchars($formValues['full_name']); ?>" maxlength="100" autocomplete="name" required aria-describedby="operatorFullNameHint<?php echo isset($profileErrors['full_name']) ? ' full_name-error' : ''; ?>">
                <small class="form-hint" id="operatorFullNameHint">Enter your first and last name.</small>
                <?php echo mbus_field_error($profileErrors, 'full_name'); ?>
            </div>
            <div class="pf-field">
                <label for="operatorEmail">Email address</label>
                <input class="form-control" type="email" id="operatorEmail" name="email" value="<?php echo htmlspecialchars($formValues['email']); ?>" maxlength="100" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required aria-describedby="operatorEmailHint<?php echo isset($profileErrors['email']) ? ' email-error' : ''; ?>">
                <small class="form-hint" id="operatorEmailHint">This email will be used the next time you sign in.</small>
                <?php echo mbus_field_error($profileErrors, 'email'); ?>
            </div>
            <div class="pf-field">
                <label for="operatorPhone">Phone number</label>
                <div class="operator-phone-input"><span class="operator-phone-prefix" aria-hidden="true">+63</span><input class="form-control" type="tel" id="operatorPhone" name="phone_number" value="<?php echo htmlspecialchars(preg_replace('/^0/', '', (string)$formValues['phone_number'])); ?>" maxlength="14" minlength="10" pattern="9[0-9]{9}" inputmode="numeric" autocomplete="tel-national" placeholder="9XXXXXXXXX" required aria-describedby="operatorPhoneHint<?php echo isset($profileErrors['phone_number']) ? ' phone_number-error' : ''; ?>"></div>
                <small class="form-hint" id="operatorPhoneHint">Use the same 10-digit mobile format as sign-up.</small>
                <?php echo mbus_field_error($profileErrors, 'phone_number'); ?>
            </div>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </section>

    <?php if (!$isConductor): $canRenew = !($renewal && $renewal['status'] === 'Pending'); ?>
    <section class="card pf-form" id="id-renewal" style="max-width:720px;margin:0 auto 16px;">
        <h2><i class="fa fa-id-card" aria-hidden="true"></i> ID &amp; licence</h2>
        <?php if ($renewSuccess !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($renewSuccess); ?></div><?php endif; ?>
        <?php if ($renewError !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($renewError); ?></div><?php endif; ?>
        <?php if ($idStatus['expiry']): ?>
        <p class="form-hint"><strong>
            <?php if ($idStatus['state'] === 'expired'): ?>Expired on <?php echo date('M j, Y', strtotime($idStatus['expiry'])); ?>.
            <?php elseif ($idStatus['state'] === 'soon'): ?>Expires in <?php echo (int)$idStatus['days']; ?> day<?php echo $idStatus['days'] === 1 ? '' : 's'; ?> (<?php echo date('M j, Y', strtotime($idStatus['expiry'])); ?>).
            <?php else: ?>Valid until <?php echo date('M j, Y', strtotime($idStatus['expiry'])); ?>.<?php endif; ?>
        </strong></p>
        <?php else: ?><p class="form-hint">No expiry date is on file for your ID. You can still send a renewed ID below.</p><?php endif; ?>
        <?php if ($renewal && $renewal['status'] === 'Pending'): ?>
            <p class="form-hint">Your renewed ID (valid until <?php echo date('M j, Y', strtotime($renewal['new_expiry'])); ?>) is waiting for admin approval.</p>
        <?php elseif ($renewal && $renewal['status'] === 'Rejected'): ?>
            <p class="form-hint" style="color:#b42318">Your last renewal was rejected<?php echo !empty($renewal['rejection_reason']) ? ': ' . htmlspecialchars($renewal['rejection_reason']) : '.'; ?> Send a clearer photo below.</p>
        <?php elseif ($renewal && $renewal['status'] === 'Approved'): ?>
            <p class="form-hint" style="color:#1b7a46">Your last renewal was approved.</p>
        <?php endif; ?>
        <?php if ($canRenew): ?>
        <form method="POST" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <input type="hidden" name="submit_renewal" value="1">
            <div class="pf-field">
                <label for="newExpiry">New ID Expiry Date</label>
                <input class="form-control" type="date" id="newExpiry" name="new_expiry" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
            </div>
            <div class="pf-field">
                <label for="renewalDoc">Photo of your renewed ID</label>
                <input class="form-control" type="file" id="renewalDoc" name="renewal_document" accept="image/jpeg,image/png,application/pdf" required>
                <small class="form-hint">JPG, PNG or PDF, up to 5 MB.</small>
            </div>
            <button type="submit" class="btn btn-primary">Send for approval</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<?php echo mbus_staff_bottom_nav($isConductor ? 'conductor' : 'driver', ''); ?>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/operator_tracker.js"></script>
<script>(function(){var el=document.getElementById('operatorPhone');if(!el)return;function fix(){var d=el.value.replace(/\D/g,'');if(/^63/.test(d)&&d.length>10)d=d.slice(2);else if(/^0/.test(d))d=d.slice(1);el.value=d.slice(0,10);}el.addEventListener('input',fix);fix();})();</script>
<script>window.MBUS_NOTIFICATIONS_ENDPOINT = 'notifications.php';</script>
<script src="../Assets/js/notifications.js"></script>
</body></html>
