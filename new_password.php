<?php
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/auth_validation.php';

$email = strtolower(trim((string)($_SESSION['reset_email'] ?? '')));
$verified_at = (int)($_SESSION['reset_verified_at'] ?? 0);
if ($email === '' || $verified_at < 1 || time() - $verified_at > 10 * 60) {
    unset($_SESSION['reset_verified_at']);
    header('Location: forgot_password.php'); exit();
}
$error = '';
$password_error = null;
$confirm_error = null;
$password = '';
$confirm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_token()) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $password_error = mbus_validate_password($password);
        if ($confirm === '') $confirm_error = 'Confirm your new password.';
        elseif (!hash_equals($password, $confirm)) $confirm_error = 'Passwords do not match.';
        if ($password_error !== null || $confirm_error !== null) {
            $error = 'Please fix the highlighted fields.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updated = mbus_db_prepare($conn, 'UPDATE users SET password = ?, reset_code = NULL, reset_expiry = NULL WHERE LOWER(email) = LOWER(?)', [$hashed, $email]);
            if ($updated === false) {
                $error = 'Unable to update your password right now. Please try again.';
            } else {
                unset($_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_expires_at'], $_SESSION['reset_attempts'], $_SESSION['reset_verified_at']);
                header('Location: login.php?reset=success', true, 303); exit();
            }
        }
    }
}
function new_password_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create a new password | MBUS</title>
<link rel="stylesheet" href="Assets/css/global/font.css"><link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
        <link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/><link rel="stylesheet" href="Assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/Assets/css/auth.css') ?>"><link rel="stylesheet" href="Assets/css/reset.css?v=<?= @filemtime(__DIR__ . '/Assets/css/reset.css') ?>"><script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
</head><body class="auth-reset">
<div class="auth-hero-m"><a class="auth-back" href="login.php" aria-label="Back to login"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a><h1>Create a new password</h1><p>Choose a strong password you haven’t used before.</p></div>
<div class="left-container"><div class="left-container-child-1"><div class="logo-wordmark"><img src="Assets/images/mbus_logo.png" alt="MBUS"></div></div><div class="left-container-child-2"><div class="lc-heading"><h1>Travel smarter.</h1><h1>Travel on time.</h1></div><div class="lc-sub-heading"><p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p></div></div><img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true"><div class="lc-circle-1"></div><div class="lc-circle-2"></div></div>
<main class="right-container"><div class="form-container"><header class="form-header"><div class="form-header-text"><h1>Create a new password</h1><p>Choose a strong password you haven’t used before.</p></div></header>
<?php if($error): ?><div class="form-alert error" role="alert"><?= new_password_e($error) ?></div><?php endif; ?>
<form method="post" class="form-body reset-form" novalidate><?php csrf_field(); ?>
<div class="form-field password-input<?= $password_error ? ' is-invalid' : '' ?>"><label for="password">New password</label><div class="password-control"><input id="password" name="password" type="password" placeholder="Enter your new password" autocomplete="new-password" maxlength="128" autofocus <?= $password_error ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>><i class="input-icon" data-lucide="lock-keyhole" aria-hidden="true"></i><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"></button></div><?php if($password_error): ?><span class="field-error" id="password-error"><?= new_password_e($password_error) ?></span><?php endif; ?><ul class="password-requirements" id="password-requirements"><li data-rule="length">At least 8 characters</li><li data-rule="upper">One uppercase letter</li><li data-rule="lower">One lowercase letter</li><li data-rule="number">One number</li></ul></div>
<div class="form-field confirm-password-input<?= $confirm_error ? ' is-invalid' : '' ?>"><label for="confirm_password">Confirm new password</label><div class="password-control"><input id="confirm_password" name="confirm_password" type="password" placeholder="Re-enter your new password" autocomplete="new-password" maxlength="128" <?= $confirm_error ? 'aria-invalid="true" aria-describedby="confirm-error"' : '' ?>><i class="input-icon" data-lucide="lock-keyhole" aria-hidden="true"></i><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"></button></div><?php if($confirm_error): ?><span class="field-error" id="confirm-error"><?= new_password_e($confirm_error) ?></span><?php endif; ?></div>
<button class="submit-button" type="submit" name="reset">Update password</button>
</form><footer class="reset-footer"><p><a href="login.php">Back to sign in</a></p></footer>
</div></main>
<script src="Assets/js/password-toggle.js"></script><script>if(window.lucide)lucide.createIcons();const p=document.getElementById('password');const rules={length:v=>v.length>=8,upper:v=>/[A-Z]/.test(v),lower:v=>/[a-z]/.test(v),number:v=>/\d/.test(v)};function updateRules(){Object.entries(rules).forEach(([k,fn])=>document.querySelector('[data-rule="'+k+'"]').classList.toggle('met',fn(p.value)));}p.addEventListener('input',updateRules);updateRules();</script>
<script src="Assets/js/submit_feedback.js"></script>
</body></html>
