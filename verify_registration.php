<?php
include 'config/db_connect.php';
session_start();
include 'Includes/csrf.php';

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
    } elseif (trim((string)getenv('SMTP_USERNAME')) === '' || trim((string)getenv('SMTP_PASSWORD')) === '') {
        $error = 'Email verification is not configured. Ask the administrator to add SMTP_USERNAME and SMTP_PASSWORD in Render, then resend the code.';
    } else {
        $new_code = (string)random_int(100000, 999999);
        $new_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $updated = mbus_db_prepare($conn, 'UPDATE users SET registration_code=?, registration_code_expiry=? WHERE LOWER(email)=LOWER(?) AND email_verified_at IS NULL', [$new_code, $new_expiry, $email]);
        if ($updated === false) {
            $error = 'Unable to create a new verification code right now.';
        } else {
            require_once __DIR__ . '/PHPMailer/src/Exception.php';
            require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/src/SMTP.php';
            try {
                $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mailer->isSMTP(); $mailer->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com'; $mailer->SMTPAuth = true;
                $mailer->Username = getenv('SMTP_USERNAME') ?: ''; $mailer->Password = getenv('SMTP_PASSWORD') ?: '';
                $mailer->SMTPSecure = getenv('SMTP_SECURE') ?: 'ssl'; $mailer->Port = (int)(getenv('SMTP_PORT') ?: 465);
                $mailer->setFrom(getenv('SMTP_FROM') ?: $mailer->Username, 'MBUS System'); $mailer->addAddress($email);
                $mailer->Subject = 'Your new MBUS verification code'; $mailer->Body = "Your new MBUS verification code is {$new_code}. It expires in 15 minutes.";
                $mailer->send(); $_SESSION['registration_mail_sent'] = true; $mail_sent = true; $success = 'A new verification code was sent.';
            } catch (Throwable $mail_error) { error_log('Registration verification resend failed: ' . $mail_error->getMessage()); $error = 'The verification email could not be sent. Check the SMTP configuration.'; }
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
            $result = mbus_db_prepare($conn, 'SELECT user_id, registration_code, registration_code_expiry, email_verified_at FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1', [$email]);
            $user = $result === false ? false : mbus_db_fetch_assoc($result);
            if (!$user) {
                $error = 'Registration account not found. Please register again.';
            } elseif (!empty($user['email_verified_at'])) {
                header('Location: login.php?registered=1');
                exit();
            } elseif (!$user['registration_code'] || !hash_equals((string)$user['registration_code'], $code) || empty($user['registration_code_expiry']) || strtotime($user['registration_code_expiry']) <= time()) {
                $error = 'That code is invalid or expired. Request a new code or register again.';
            } else {
                $updated = mbus_db_prepare($conn, 'UPDATE users SET email_verified_at=NOW(), registration_code=NULL, registration_code_expiry=NULL WHERE user_id=?', [$user['user_id']]);
                if ($updated === false) {
                    $error = 'Unable to verify your account right now. Please try again.';
                } else {
                    unset($_SESSION['registration_email'], $_SESSION['registration_mail_sent']);
                    header('Location: login.php?registered=1');
                    exit();
                }
            }
        }
    }
}
$mail_sent = !empty($_SESSION['registration_mail_sent']);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify MBUS Email</title>
<link rel="stylesheet" href="Assets/css/responsive.css"><link rel="stylesheet" href="login.css"><link rel="stylesheet" href="Assets/css/auth.css">
</head><body class="login-page"><div class="login-container"><div class="login-card">
<div class="login-header"><h3>Verify your email</h3><p>Enter the 6-digit code sent to <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>.</p></div>
<?php if ($error): ?><div class="login-alert login-alert-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (!$mail_sent): ?><div class="login-alert login-alert-error" role="alert">The verification email could not be sent. Contact the administrator or check the SMTP configuration before trying again.</div><?php endif; ?>
<form method="post" class="login-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>">
<div class="form-field"><label for="code">Verification code</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="123456"></div>
<button class="btn btn-primary btn-block login-submit" type="submit" name="verify_registration">Verify email</button></form>
<form method="post" class="login-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"><button class="btn btn-block" type="submit" name="resend">Send a new code</button></form>
<div class="login-footer"><p><a href="register.php">Back to registration</a> · <a href="login.php">Back to login</a></p></div>
</div></div></body></html>
