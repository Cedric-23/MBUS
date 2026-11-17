<?php
session_start();
include_once __DIR__ . '/../config/db_connect.php';
include_once __DIR__ . '/../Includes/profile_photo.php';
include_once __DIR__ . '/../Includes/validation.php';
include_once __DIR__ . '/../Includes/csrf.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_type'] ?? '') !== 'operator') {
    header('Location: ../login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$loadProfile = static function () use ($conn, $userId) {
    $query = mbus_db_prepare($conn, "SELECT full_name, email, phone_number FROM users WHERE user_id = ?", [$userId]);
    return $query === false ? false : mbus_db_fetch_assoc($query);
};
$profile = $loadProfile();
if (!$profile) {
    http_response_code(404);
    exit('Operator account not found.');
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

$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Operator'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Operator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../Assets/css/responsive.css"><link rel="stylesheet" href="../Assets/css/bottom-nav.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/bottom-nav.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css"><link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/operator_profile.css?v=<?php echo (int)filemtime(__DIR__ . '/../Assets/css/operator_profile.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
    <link rel="stylesheet" href="../Assets/css/unify.css?v=1">
</head>
<body class="has-bottom-nav profile-page operator-profile">
<main class="main" role="main">
    <header class="page-header" role="banner">
        <div class="page-header-left"><h1>My Profile</h1></div>
        <div class="page-header-right"><div class="profile-menu-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu"><span class="profile-avatar" aria-hidden="true"><?php require_once dirname(__DIR__) . '/Includes/header_avatar.php'; echo mbus_header_avatar('../'); ?></span></button>
            <div class="profile-menu" id="profileMenu" role="menu">
                <div class="profile-menu-header"><span class="profile-menu-name"><?php echo $name; ?></span><span class="profile-menu-role">Operator account</span></div>
                <a href="payments.php" role="menuitem"><i class="fa fa-wallet" aria-hidden="true"></i> Payments</a>
                <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
            </div>
        </div></div>
    </header>

    <?php mbus_photo_card($conn, $userId, (string)($_SESSION['full_name'] ?? 'Operator'), 'Operator account', $photoOk, $photoErr); ?>
    <?php if ($profileSuccess !== ''): ?><div class="alert alert-success" role="status" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($profileSuccess); ?></div><?php endif; ?>
    <?php if (isset($profileErrors['form'])): ?><div class="alert alert-error" role="alert" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($profileErrors['form']); ?></div><?php endif; ?>

    <section class="card pf-form" style="max-width:720px;margin:0 auto 16px;">
        <h2><i class="fa fa-user-pen" aria-hidden="true"></i> Account details</h2>
        <p class="form-hint">Update the details used for your operator account and future sign-ins.</p>
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
</main>
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="operator_dashboard.php" class="bottom-nav-item"><i class="fa fa-home" aria-hidden="true"></i><span>Home</span></a>
    <a href="manage_trips.php" class="bottom-nav-item"><i class="fa fa-bus" aria-hidden="true"></i><span>Trips</span></a>
    <a href="scan.php" class="bottom-nav-item"><i class="fa fa-qrcode" aria-hidden="true"></i><span>Scan</span></a>
    <a href="passengers.php" class="bottom-nav-item"><i class="fa fa-users" aria-hidden="true"></i><span>Riders</span></a>
    <a href="waiting.php" class="bottom-nav-item"><i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span></a>
</nav>
<script src="../Assets/js/app-header.js"></script>
<script src="../Assets/js/operator_tracker.js"></script>
<script>(function(){var el=document.getElementById('operatorPhone');if(!el)return;function fix(){var d=el.value.replace(/\D/g,'');if(/^63/.test(d)&&d.length>10)d=d.slice(2);else if(/^0/.test(d))d=d.slice(1);el.value=d.slice(0,10);}el.addEventListener('input',fix);fix();})();</script>
</body></html>
