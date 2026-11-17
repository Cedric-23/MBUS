<?php
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/reset_mailer.php';
require_once __DIR__ . '/Includes/registration_security.php';

$email = strtolower(trim((string)($_SESSION['registration_email'] ?? '')));
$error = '';
$success = '';
if ($email === '') {
    header('Location: register.php');
    exit();
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
            $newCode = (string)random_int(100000, 999999);
            $newExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $updated = mbus_db_prepare(
                $conn,
                'UPDATE users SET registration_code=?, registration_code_expiry=?, registration_code_attempts=0 WHERE LOWER(email)=LOWER(?) AND email_verified_at IS NULL',
                [$newCode, $newExpiry, $email]
            );
            if ($updated === false) {
                $error = 'Unable to create a new verification code right now. Please try again later.';
            } elseif ($conn->affectedRows < 1) {
                $error = 'This account is already verified or no longer available. Please sign in or start registration again.';
            } else {
                $mailError = mbus_send_registration_code($email, $newCode);
                $_SESSION['registration_sent_at'] = time();
                $_SESSION['registration_mail_sent'] = $mailError === null;
                if ($mailError !== null) {
                    error_log('Registration verification resend failed: ' . $mailError);
                    $error = 'The verification email could not be sent right now. Please wait before trying again.';
                } else {
                    $success = 'A new verification code was sent. It expires in 15 minutes.';
                }
            }
        }
    }
} elseif (isset($_POST['verify_registration'])) {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit verification code from your email.';
        } else {
            $result = mbus_db_prepare(
                $conn,
                'SELECT user_id, registration_code, registration_code_expiry, registration_code_attempts, email_verified_at FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1',
                [$email]
            );
            $user = $result === false ? false : mbus_db_fetch_assoc($result);

            if ($result === false) {
                $error = 'Verification is temporarily unavailable. Please try again.';
            } elseif (!$user) {
                $error = 'Registration account not found. Please start registration again.';
            } elseif (!empty($user['email_verified_at'])) {
                header('Location: login.php?registered=1', true, 303);
                exit();
            } elseif ((int)$user['registration_code_attempts'] >= 5) {
                $error = 'Too many incorrect attempts. Request a new verification code to continue.';
            } elseif (empty($user['registration_code']) || empty($user['registration_code_expiry'])) {
                $error = 'There is no active verification code. Request a new code to continue.';
            } elseif (strtotime((string)$user['registration_code_expiry']) <= time()) {
                $error = 'That code has expired. Request a new code to continue.';
            } elseif (!hash_equals((string)$user['registration_code'], $code)) {
                $attemptUpdate = mbus_db_prepare(
                    $conn,
                    'UPDATE users SET registration_code_attempts=registration_code_attempts+1 WHERE user_id=? AND registration_code=? AND registration_code_attempts<5 AND email_verified_at IS NULL AND registration_code_expiry>NOW()',
                    [$user['user_id'], $user['registration_code']]
                );
                if ($attemptUpdate === false) {
                    $error = 'Verification is temporarily unavailable. Please try again.';
                } elseif ($conn->affectedRows < 1) {
                    $error = 'This code is no longer active. Request a new verification code to continue.';
                } else {
                    $attempts = min(5, (int)$user['registration_code_attempts'] + 1);
                    $error = $attempts >= 5
                        ? 'Too many incorrect attempts. Request a new verification code to continue.'
                        : 'That code is incorrect. ' . (5 - $attempts) . ' attempts remain.';
                }
            } else {
                $verified = mbus_db_prepare(
                    $conn,
                    'UPDATE users SET email_verified_at=NOW(), registration_code=NULL, registration_code_expiry=NULL, registration_code_attempts=0 WHERE user_id=? AND registration_code=? AND registration_code_expiry>NOW() AND registration_code_attempts<5 AND email_verified_at IS NULL',
                    [$user['user_id'], $code]
                );
                if ($verified === false) {
                    $error = 'Unable to verify your account right now. Please try again.';
                } elseif ($conn->affectedRows < 1) {
                    $error = 'That code is no longer valid. Request a new code and try again.';
                } else {
                    unset($_SESSION['registration_email'], $_SESSION['registration_mail_sent'], $_SESSION['registration_sent_at']);
                    header('Location: login.php?registered=1', true, 303);
                    exit();
                }
            }
        }
    }
}

$mailSent = !empty($_SESSION['registration_mail_sent']);
$sentAt = (int)($_SESSION['registration_sent_at'] ?? 0);
$resendWait = max(0, 60 - (time() - $sentAt));
$maskedEmail = mbus_reset_mask_email($email);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify MBUS Email</title>
<link rel="stylesheet" href="Assets/css/responsive.css"><link rel="stylesheet" href="login.css"><link rel="stylesheet" href="Assets/css/auth.css">
</head>
<body class="login-page">
<div class="login-container"><div class="login-card">
<div class="login-header"><h3>Verify your email</h3><p>Enter the 6-digit code sent to <?php echo htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8'); ?>. It expires in 15 minutes.</p></div>
<?php if ($error): ?><div class="login-alert login-alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($success): ?><div class="login-alert login-alert-success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (!$mailSent && $error === ''): ?><div class="login-alert login-alert-error" role="alert">We could not send the verification email. Please wait, then request a new code.</div><?php endif; ?>
<form method="post" action="verify_registration.php" class="login-form">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>">
<div class="form-field"><label for="code">Verification code</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="123456"></div>
<button class="btn btn-primary btn-block login-submit" type="submit" name="verify_registration">Verify email</button>
<p class="form-hint-text">For your security, the code locks after five incorrect attempts. You can request a new one after the resend timer.</p>
</form>
<form method="post" action="verify_registration.php" class="login-form">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>">
<button class="btn btn-block" type="submit" name="resend" id="resend-button" <?php echo $resendWait > 0 ? 'disabled' : ''; ?>>Send a new code</button>
<span id="resend-timer" class="form-hint-text" aria-live="polite"></span>
</form>
<div class="login-footer"><p><a href="register.php">Back to registration</a> · <a href="login.php">Back to login</a></p></div>
</div></div>
<script>
(function () {
    const button = document.getElementById('resend-button');
    const timer = document.getElementById('resend-timer');
    const availableAt = <?php echo (int)($sentAt + 60); ?> * 1000;
    function updateResendTimer() {
        const seconds = Math.max(0, Math.ceil((availableAt - Date.now()) / 1000));
        button.disabled = seconds > 0;
        timer.textContent = seconds > 0 ? 'You can resend in ' + seconds + ' seconds.' : 'You can request up to five codes per hour.';
    }
    updateResendTimer();
    window.setInterval(updateResendTimer, 1000);
})();
</script>
</body>
</html>
