<?php
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/auth_validation.php';
require_once __DIR__ . '/Includes/reset_mailer.php';

$email = strtolower(trim((string)($_SESSION['reset_email'] ?? '')));
if ($email === '') { header('Location: forgot_password.php'); exit(); }
$error = '';
$success = '';
$code_value = preg_replace('/\D+/', '', (string)($_POST['code'] ?? '')) ?? '';
$now = time();
$reset_expires_at = (int)($_SESSION['reset_expires_at'] ?? 0);
$reset_sent_at = (int)($_SESSION['reset_sent_at'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } elseif (isset($_POST['change_email'])) {
        unset($_SESSION['reset_email'], $_SESSION['reset_expires_at'], $_SESSION['reset_sent_at'], $_SESSION['reset_attempts'], $_SESSION['reset_verified_at']);
        header('Location: forgot_password.php', true, 303); exit();
    } elseif (isset($_POST['resend'])) {
        if ($reset_sent_at > 0 && $now - $reset_sent_at < 45) {
            $error = 'Please wait before requesting another code.';
        } elseif ($reset_expires_at < $now - 15 * 60) {
            $error = 'This reset request has expired. Start again to request a new code.';
        } else {
            $result = mbus_db_prepare($conn, 'SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', [$email]);
            $user = $result === false ? null : mbus_db_fetch_assoc($result);
            if (!$user) {
                $error = 'This reset request is no longer available. Start again.';
            } else {
                $new_code = (string)random_int(100000, 999999);
                $new_expiry = date('Y-m-d H:i:s', $now + 15 * 60);
                $updated = mbus_db_prepare($conn, 'UPDATE users SET reset_code = ?, reset_expiry = ? WHERE user_id = ?', [$new_code, $new_expiry, $user['user_id']]);
                $mail_error = $updated === false ? 'Password reset is temporarily unavailable. Please try again in a moment.' : mbus_send_reset_code($email, $new_code);
                if ($mail_error !== null) {
                    $error = $mail_error;
                } else {
                    $_SESSION['reset_sent_at'] = $now;
                    $_SESSION['reset_expires_at'] = $now + 15 * 60;
                    $_SESSION['reset_attempts'] = 0;
                    $reset_sent_at = $now; $reset_expires_at = $now + 15 * 60;
                    $success = 'A new verification code was sent. It may take a few minutes to arrive.';
                }
            }
        }
    } elseif (isset($_POST['verify'])) {
        if (!preg_match('/^\d{6}$/', $code_value)) {
            $error = 'Enter the 6-digit verification code.';
        } elseif ($reset_expires_at < $now) {
            $error = 'This code has expired. Request a new code and try again.';
        } elseif ((int)($_SESSION['reset_attempts'] ?? 0) >= 5) {
            $error = 'Too many incorrect attempts. Request a new code to continue.';
        } else {
            $result = mbus_db_prepare($conn, 'SELECT user_id, reset_code, reset_expiry FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', [$email]);
            $user = $result === false ? null : mbus_db_fetch_assoc($result);
            $valid = $user && !empty($user['reset_code']) && hash_equals((string)$user['reset_code'], $code_value) && !empty($user['reset_expiry']) && strtotime((string)$user['reset_expiry']) >= $now;
            if ($valid) {
                mbus_db_prepare($conn, 'UPDATE users SET reset_code = NULL, reset_expiry = NULL WHERE user_id = ?', [$user['user_id']]);
                $_SESSION['reset_verified_at'] = $now;
                unset($_SESSION['reset_attempts']);
                header('Location: new_password.php', true, 303); exit();
            }
            $_SESSION['reset_attempts'] = (int)($_SESSION['reset_attempts'] ?? 0) + 1;
            $error = $result === false ? 'Password reset is temporarily unavailable. Please try again.' : 'That code is incorrect or has expired.';
        }
    }
}
function verify_page_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$remaining = max(0, $reset_expires_at - time());
$masked_email = mbus_reset_mask_email($email);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check your email | MBUS</title>
<link rel="stylesheet" href="Assets/css/global/font.css"><link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"><link rel="stylesheet" href="Assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/Assets/css/auth.css') ?>"><link rel="stylesheet" href="Assets/css/reset.css?v=<?= @filemtime(__DIR__ . '/Assets/css/reset.css') ?>"><script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
</head><body>
<div class="left-container"><div class="left-container-child-1"><div class="logo-wordmark"><img src="Assets/images/mbus_logo.png" alt="MBUS"></div></div><div class="left-container-child-2"><div class="lc-heading"><h1>Travel smarter.</h1><h1>Travel on time.</h1></div><div class="lc-sub-heading"><p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p></div></div><div class="lc-circle-1"></div><div class="lc-circle-2"></div></div>
<main class="right-container"><div class="form-container"><header class="form-header"><div class="form-header-text"><h1>Check your email</h1><p>Enter the 6-digit code sent to <?= verify_page_e($masked_email) ?>.</p></div></header>
<?php if($error): ?><div class="form-alert error" role="alert"><?= verify_page_e($error) ?></div><?php endif; ?><?php if($success): ?><div class="form-alert success" role="status"><?= verify_page_e($success) ?></div><?php endif; ?>
<form method="post" class="form-body reset-form" novalidate><?php csrf_field(); ?><div class="form-field<?= $error && isset($_POST['verify']) ? ' is-invalid' : '' ?>"><label for="code">Verification code</label><input id="code" name="code" class="code-input" type="text" value="<?= verify_page_e($code_value) ?>" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" autofocus aria-describedby="code-hint"><i class="input-icon" data-lucide="key-round" aria-hidden="true"></i><span class="field-hint" id="code-hint">The code expires in <span id="countdown" role="timer" aria-live="polite">--:--</span>.</span></div><button class="submit-button" type="submit" name="verify">Verify code</button></form>
<div class="reset-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= verify_page_e($_SESSION['csrf_token'] ?? '') ?>"><button type="submit" name="resend" id="resend-button" <?= $reset_sent_at && $now-$reset_sent_at<45 ? 'disabled' : '' ?>>Resend code</button></form><span class="reset-timer" id="resend-timer" aria-live="polite"></span></div>
<footer class="reset-footer"><form method="post"><input type="hidden" name="csrf_token" value="<?= verify_page_e($_SESSION['csrf_token'] ?? '') ?>"><button type="submit" name="change_email" class="reset-actions" style="margin:0 auto;color:var(--blue);background:none;border:0;font:inherit;font-weight:600;cursor:pointer">Use a different email</button></form><p style="margin-top:14px"><a href="login.php">Back to sign in</a></p></footer>
</div></main>
<script>if(window.lucide)lucide.createIcons();const expiresAt=<?= (int)$reset_expires_at ?>*1000;const resendAt=<?= (int)$reset_sent_at ?>*1000;const countdown=document.getElementById('countdown'),resend=document.getElementById('resend-button'),resendTimer=document.getElementById('resend-timer');function tick(){const now=Date.now(),left=Math.max(0,expiresAt-now),sec=Math.floor(left/1000);countdown.textContent=String(Math.floor(sec/60)).padStart(2,'0')+':'+String(sec%60).padStart(2,'0');const wait=Math.max(0,45000-(now-resendAt));if(wait){resend.disabled=true;resendTimer.textContent='Resend available in '+Math.ceil(wait/1000)+'s';}else{resend.disabled=false;resendTimer.textContent='';}}tick();setInterval(tick,1000);</script>
</body></html>
