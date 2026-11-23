<?php
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/auth_validation.php';
require_once __DIR__ . '/Includes/reset_mailer.php';

$error = '';
$success = '';
$email_value = strtolower(trim((string)($_POST['email'] ?? '')));
$field_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } else {
        $wait = 30 - (time() - (int)($_SESSION['reset_last_request'] ?? 0));
        $email_error = mbus_validate_email($email_value);
        if ($wait > 0) {
            // Stops rapid repeat requests from tying up the server with mail attempts
            $error = 'Please wait ' . $wait . ' seconds before requesting another code.';
        } elseif ($email_error !== null) {
            $field_error = $email_error;
            $error = 'Please fix the highlighted field.';
        } else {
            $_SESSION['reset_last_request'] = time();
            unset($_SESSION['reset_email'], $_SESSION['reset_verified_at'], $_SESSION['reset_attempts']);
            $result = mbus_db_prepare($conn, 'SELECT user_id, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', [$email_value]);
            $user = $result === false ? null : mbus_db_fetch_assoc($result);

            if ($result === false) {
                $error = 'Password reset is temporarily unavailable. Please try again in a moment.';
            } elseif (!$user) {
                // Do not reveal whether an email is registered.
                $success = 'If an MBUS account matches that email, we will send a 6-digit verification code. Check your inbox and spam folder.';
            } else {
                $code = (string)random_int(100000, 999999);
                $expiry = date('Y-m-d H:i:s', time() + 15 * 60);
                $updated = mbus_db_prepare($conn, 'UPDATE users SET reset_code = ?, reset_expiry = ? WHERE user_id = ?', [$code, $expiry, $user['user_id']]);
                $mail_error = $updated === false ? 'Password reset is temporarily unavailable. Please try again in a moment.' : mbus_send_reset_code($email_value, $code);

                if ($mail_error !== null) {
                    if ($updated !== false) mbus_db_prepare($conn, 'UPDATE users SET reset_code = NULL, reset_expiry = NULL WHERE user_id = ?', [$user['user_id']]);
                    $error = $mail_error;
                } else {
                    $_SESSION['reset_email'] = $email_value;
                    $_SESSION['reset_sent_at'] = time();
                    $_SESSION['reset_expires_at'] = time() + 15 * 60;
                    $_SESSION['reset_attempts'] = 0;
                    header('Location: verify_code.php', true, 303);
                    exit();
                }
            }
        }
    }
}
function reset_page_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your password | MBUS</title>
    <link rel="stylesheet" href="Assets/css/global/font.css">
    <link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
        <link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/>
    <link rel="stylesheet" href="Assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/Assets/css/auth.css') ?>">
    <link rel="stylesheet" href="Assets/css/reset.css?v=<?= @filemtime(__DIR__ . '/Assets/css/reset.css') ?>">
    <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-reset">
<div class="auth-hero-m"><h1>Reset your password</h1><p>We’ll send a 6-digit verification code to your MBUS email.</p></div>
    <div class="left-container">
        <div class="left-container-child-1"><div class="logo-wordmark"><img src="Assets/images/mbus_logo.png" alt="MBUS"></div></div>
        <div class="left-container-child-2"><div class="lc-heading"><h1>Travel smarter.</h1><h1>Travel on time.</h1></div><div class="lc-sub-heading"><p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p></div></div>
        <img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true"><div class="lc-circle-1"></div><div class="lc-circle-2"></div>
    </div>
    <main class="right-container"><div class="form-container">
        <header class="form-header"><div class="form-header-text"><h1>Reset your password</h1><p>We’ll send a 6-digit verification code to your MBUS email.</p></div></header>
        <?php if ($error): ?><div class="form-alert error" role="alert"><?= reset_page_e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="form-alert success" role="status"><?= reset_page_e($success) ?></div><?php endif; ?>
        <form method="post" class="form-body reset-form" novalidate>
            <?php csrf_field(); ?>
            <div class="form-field<?= $field_error ? ' is-invalid' : '' ?>">
                <label for="email">Email address</label>
                <div class="reset-input"><input id="email" name="email" type="email" value="<?= reset_page_e($email_value) ?>" placeholder="juan@example.com" maxlength="100" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus <?= $field_error ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                <i class="input-icon" data-lucide="mail" aria-hidden="true"></i></div>
                <?php if ($field_error): ?><span class="field-error" id="email-error"><?= reset_page_e($field_error) ?></span><?php endif; ?>
            </div>
            <button class="submit-button" type="submit" name="send_code">Send verification code</button>
        </form>
        <footer class="reset-footer"><p>Remembered your password? <a href="login.php">Sign in</a></p></footer>
    </div></main>
<script>if(window.lucide) lucide.createIcons();</script>
<script src="Assets/js/submit_feedback.js"></script>
</body>
</html>
