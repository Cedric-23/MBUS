<?php
/*
 * Email verification for driver/operator and conductor applications.
 * The application is saved when the form is submitted, but the admin only sees it
 * (and is only notified) after the applicant enters the 6-digit code sent to their email.
 */
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/notify.php';
require_once __DIR__ . '/Includes/reset_mailer.php';
require_once __DIR__ . '/Includes/registration_security.php';

$email = strtolower(trim((string)($_SESSION['staff_verify_email'] ?? '')));
$type  = ($_SESSION['staff_verify_type'] ?? '') === 'conductor' ? 'conductor' : 'driver';
if ($email === '') {
    header('Location: register.php?type=' . $type);
    exit();
}
$table = $type === 'conductor' ? 'conductor_application' : 'operator_application';
$roleLabel = $type === 'conductor' ? 'conductor' : 'driver/operator';

$error = '';
$success = '';

/** The newest not-yet-verified application for this email, or null. */
function mbus_staff_unverified($conn, string $table, string $email): ?array
{
    $result = mbus_db_prepare(
        $conn,
        "SELECT application_id, full_name, verification_code, verification_code_expiry, verification_code_attempts
         FROM {$table} WHERE LOWER(email) = LOWER(?) AND email_verified = FALSE ORDER BY application_id DESC LIMIT 1",
        [$email]
    );
    $row = $result === false ? false : mbus_db_fetch_assoc($result);
    return $row ? $row : null;
}

if (isset($_POST['resend'])) {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (!mbus_registration_mail_is_configured()) {
        $error = 'Email verification is temporarily unavailable. Please try again later.';
    } else {
        $limitReason = mbus_registration_allow_code_send($conn, $email);
        if ($limitReason !== null) {
            $error = mbus_registration_limit_message($limitReason, true);
        } else {
            $app = mbus_staff_unverified($conn, $table, $email);
            if (!$app) {
                $error = 'This application is already verified or no longer available. Please start again.';
            } else {
                $newCode = (string)random_int(100000, 999999);
                $newExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $updated = mbus_db_prepare(
                    $conn,
                    "UPDATE {$table} SET verification_code = ?, verification_code_expiry = ?, verification_code_attempts = 0 WHERE application_id = ? AND email_verified = FALSE",
                    [$newCode, $newExpiry, $app['application_id']]
                );
                if ($updated === false) {
                    $error = 'Unable to create a new verification code right now. Please try again later.';
                } else {
                    $mailError = mbus_send_registration_code($email, $newCode, (string)$app['full_name']);
                    $_SESSION['staff_verify_sent_at'] = time();
                    $_SESSION['staff_verify_mail_sent'] = $mailError === null;
                    if ($mailError !== null) {
                        error_log('Staff application verification resend failed: ' . $mailError);
                        $error = 'The verification email could not be sent right now. Please wait before trying again.';
                    } else {
                        $success = 'A new verification code was sent. It expires in 15 minutes.';
                    }
                }
            }
        }
    }
} elseif (isset($_POST['verify_application'])) {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit verification code from your email.';
        } else {
            $app = mbus_staff_unverified($conn, $table, $email);
            if (!$app) {
                header('Location: register.php?type=' . $type, true, 303);
                exit();
            } elseif ((int)$app['verification_code_attempts'] >= 5) {
                $error = 'Too many incorrect attempts. Request a new verification code to continue.';
            } elseif (empty($app['verification_code']) || empty($app['verification_code_expiry'])) {
                $error = 'There is no active verification code. Request a new code to continue.';
            } elseif (strtotime((string)$app['verification_code_expiry']) <= time()) {
                $error = 'That code has expired. Request a new code to continue.';
            } elseif (!hash_equals((string)$app['verification_code'], $code)) {
                mbus_db_prepare(
                    $conn,
                    "UPDATE {$table} SET verification_code_attempts = verification_code_attempts + 1 WHERE application_id = ? AND email_verified = FALSE",
                    [$app['application_id']]
                );
                $attempts = min(5, (int)$app['verification_code_attempts'] + 1);
                $error = $attempts >= 5
                    ? 'Too many incorrect attempts. Request a new verification code to continue.'
                    : 'That code is incorrect. ' . (5 - $attempts) . ' attempts remain.';
            } else {
                $verified = mbus_db_prepare(
                    $conn,
                    "UPDATE {$table} SET email_verified = TRUE, email_verified_at = NOW(), verification_code = NULL, verification_code_expiry = NULL, verification_code_attempts = 0
                     WHERE application_id = ? AND verification_code = ? AND verification_code_expiry > NOW() AND verification_code_attempts < 5 AND email_verified = FALSE",
                    [$app['application_id'], $code]
                );
                if ($verified === false) {
                    $error = 'Unable to verify your email right now. Please try again.';
                } elseif ($conn->affectedRows < 1) {
                    $error = 'That code is no longer valid. Request a new code and try again.';
                } else {
                    // Only now does the application reach the admin.
                    mbus_notify_admins($conn, 'New ' . $roleLabel . ' application from ' . $app['full_name'] . '. Review it in Staff applications.');
                    unset($_SESSION['staff_verify_email'], $_SESSION['staff_verify_type'], $_SESSION['staff_verify_mail_sent'], $_SESSION['staff_verify_sent_at']);
                    header('Location: login.php', true, 303);
                    exit();
                }
            }
        }
    }
}

$mailSent = !empty($_SESSION['staff_verify_mail_sent']);
$sentAt = (int)($_SESSION['staff_verify_sent_at'] ?? 0);
$resendWait = max(0, 60 - (time() - $sentAt));
$maskedEmail = mbus_reset_mask_email($email);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify your email | MBUS</title>
<link rel="stylesheet" href="Assets/css/global/font.css"><link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
<link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/><link rel="stylesheet" href="Assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/Assets/css/auth.css') ?>"><link rel="stylesheet" href="Assets/css/reset.css?v=<?= @filemtime(__DIR__ . '/Assets/css/reset.css') ?>"><script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
</head><body class="auth-reset">
<div class="auth-hero-m"><h1>Verify your email</h1><p>Enter the 6-digit code we sent you.</p></div>
<div class="left-container"><div class="left-container-child-1"><div class="logo-wordmark"><img src="Assets/images/mbus_logo.png" alt="MBUS"></div></div><div class="left-container-child-2"><div class="lc-heading"><h1>Travel smarter.</h1><h1>Travel on time.</h1></div><div class="lc-sub-heading"><p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p></div></div><img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true"><div class="lc-circle-1"></div><div class="lc-circle-2"></div></div>
<main class="right-container"><div class="form-container"><header class="form-header"><div class="form-header-text"><h1>Verify your email</h1><p>Enter the 6-digit code sent to <?php echo htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8'); ?>. Your <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?> application is sent to the admin only after your email is verified. The code expires in 15 minutes.</p></div></header>
<?php if ($error): ?><div class="form-alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($success): ?><div class="form-alert success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (!$mailSent && $error === '' && $success === ''): ?><div class="form-alert error" role="alert">We could not send the verification email. Please wait, then request a new code.</div><?php endif; ?>
<form method="post" action="verify_application.php" class="form-body reset-form" novalidate>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>">
<div class="form-field"><label for="code">Verification code</label><div class="reset-input"><input id="code" name="code" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" autofocus aria-describedby="code-hint"><i class="input-icon" data-lucide="key-round" aria-hidden="true"></i></div><span class="field-hint" id="code-hint">The code locks after five wrong attempts.</span></div>
<button class="submit-button" type="submit" name="verify_application">Verify email</button>
</form>
<div class="reset-actions"><form method="post" action="verify_application.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" name="resend" id="resend-button" <?php echo $resendWait > 0 ? 'disabled' : ''; ?>>Send a new code</button></form><span class="reset-timer" id="resend-timer" aria-live="polite"></span></div>
<footer class="reset-footer"><p style="margin-top:14px"><a href="register.php?type=<?php echo $type; ?>">Back to application</a> &middot; <a href="login.php">Back to sign in</a></p></footer>
</div></main>
<script>
if (window.lucide) lucide.createIcons();
(function () {
    const button = document.getElementById('resend-button');
    const timer = document.getElementById('resend-timer');
    const availableAt = <?php echo (int)($sentAt + 60); ?> * 1000;
    function updateResendTimer() {
        const seconds = Math.max(0, Math.ceil((availableAt - Date.now()) / 1000));
        button.disabled = seconds > 0;
        timer.textContent = seconds > 0 ? 'You can resend in ' + seconds + ' seconds.' : '';
    }
    updateResendTimer();
    window.setInterval(updateResendTimer, 1000);
})();
</script>
<script>try{localStorage.removeItem("mbus_reg_draft_v1")}catch(e){}</script>
<script src="Assets/js/submit_feedback.js"></script>
</body>
</html>
