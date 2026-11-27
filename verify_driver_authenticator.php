<?php
/*
 * Driver application verification with an authenticator app (TOTP) instead of an email code.
 * The application stays hidden from the admin until the applicant enters a valid 6-digit code.
 */
session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/Includes/csrf.php';
require_once __DIR__ . '/Includes/notify.php';
require_once __DIR__ . '/Includes/totp.php';

$email = strtolower(trim((string)($_SESSION['staff_verify_email'] ?? '')));
if ($email === '' || ($_SESSION['staff_verify_type'] ?? '') !== 'driver') {
    header('Location: register.php?type=driver');
    exit();
}

$error = '';
$res = mbus_db_prepare($conn, "SELECT application_id, full_name, totp_secret, verification_code_attempts, verification_code_expiry
    FROM operator_application WHERE LOWER(email) = LOWER(?) AND email_verified = FALSE ORDER BY application_id DESC LIMIT 1", [$email]);
$app = $res === false ? false : mbus_db_fetch_assoc($res);
if (!$app || empty($app['totp_secret'])) {
    header('Location: register.php?type=driver', true, 303);
    exit();
}

if (isset($_POST['verify_application'])) {
    $attempts = (int)$app['verification_code_attempts'];
    $lockedUntil = $app['verification_code_expiry'] ? strtotime((string)$app['verification_code_expiry']) : 0;
    if ($attempts >= 5 && $lockedUntil > time()) {
        $error = 'Too many incorrect attempts. Try again in ' . max(1, (int)ceil(($lockedUntil - time()) / 60)) . ' minute(s).';
    } elseif (!csrf_validate_token()) {
        $error = 'Security validation failed. Please try again.';
    } else {
        if ($attempts >= 5) $attempts = 0;   // the lock has run out
        $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit code from your authenticator app.';
        } elseif (!mbus_totp_verify((string)$app['totp_secret'], $code)) {
            $attempts++;
            $lock = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+10 minutes')) : null;
            mbus_db_prepare($conn, "UPDATE operator_application SET verification_code_attempts = ?, verification_code_expiry = ? WHERE application_id = ? AND email_verified = FALSE", [$attempts, $lock, $app['application_id']]);
            $error = $attempts >= 5 ? 'Too many incorrect attempts. Try again in 10 minutes.' : 'That code is incorrect. ' . (5 - $attempts) . ' attempts remain.';
        } else {
            $ok = mbus_db_prepare($conn, "UPDATE operator_application SET email_verified = TRUE, email_verified_at = NOW(), verification_code_attempts = 0, verification_code_expiry = NULL, totp_secret = NULL WHERE application_id = ? AND email_verified = FALSE", [$app['application_id']]);
            if ($ok === false) {
                $error = 'Unable to verify right now. Please try again.';
            } else {
                mbus_notify_admins($conn, 'New driver/operator application from ' . $app['full_name'] . '. Review it in Staff applications.');
                unset($_SESSION['staff_verify_email'], $_SESSION['staff_verify_type']);
                header('Location: login.php', true, 303);
                exit();
            }
        }
    }
}

$secret = (string)$app['totp_secret'];
$uri = mbus_totp_uri($secret, $email);
$spaced = trim(chunk_split($secret, 4, ' '));
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set up your authenticator | MBUS</title>
<link rel="stylesheet" href="Assets/css/global/font.css"><link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
<link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/><link rel="stylesheet" href="Assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/Assets/css/auth.css') ?>"><link rel="stylesheet" href="Assets/css/reset.css?v=<?= @filemtime(__DIR__ . '/Assets/css/reset.css') ?>"><script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
</head><body class="auth-reset">
<div class="auth-hero-m"><h1>Set up your authenticator</h1><p>Scan the QR code, then enter the code.</p></div>
<div class="left-container"><div class="left-container-child-1"><div class="logo-wordmark"><img src="Assets/images/mbus_logo_light.png" alt="MBUS"></div></div><div class="left-container-child-2"><div class="lc-heading"><h1>Travel smarter.</h1><h1>Travel on time.</h1></div><div class="lc-sub-heading"><p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p></div></div><img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true"><div class="lc-circle-1"></div><div class="lc-circle-2"></div></div>
<main class="right-container"><div class="form-container"><header class="form-header"><div class="form-header-text"><h1>Set up your authenticator</h1><p>Your driver application goes to the admin only after you confirm it with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy).</p></div></header>
<?php if ($error): ?><div class="form-alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<ol class="totp-steps">
<li>Open your authenticator app and add a new account.</li>
<li>Scan this QR code, or enter the key by hand.</li>
<li>Type the 6-digit code the app shows.</li></ol>
<div class="totp-box"><div id="totp-qr" class="totp-qr" role="img" aria-label="QR code for your authenticator app"></div>
<p class="totp-key"><span>Setup key</span><code id="totp-key"><?php echo $h($spaced); ?></code></p>
<p class="totp-open"><a href="<?php echo $h($uri); ?>">Open in authenticator app</a> (on this phone)</p></div>
<form method="post" action="verify_driver_authenticator.php" class="form-body reset-form" novalidate>
<input type="hidden" name="csrf_token" value="<?php echo $h(csrf_get_token()); ?>">
<div class="form-field"><label for="code">Authenticator code</label><div class="reset-input"><input id="code" name="code" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]{6,7}" required placeholder="000000" autofocus aria-describedby="code-hint"><i class="input-icon" data-lucide="shield-check" aria-hidden="true"></i></div><span class="field-hint" id="code-hint">The code changes every 30 seconds. It locks for 10 minutes after five wrong attempts.</span></div>
<button class="submit-button" type="submit" name="verify_application">Verify and submit application</button>
</form>
<footer class="reset-footer"><p style="margin-top:14px"><a href="register.php?type=driver">Back to application</a> &middot; <a href="login.php">Back to sign in</a></p></footer>
</div></main>
<style>
.totp-steps{margin:0 0 14px;padding-left:20px;color:#475569;font-size:.9rem;line-height:1.5}
.totp-box{display:flex;flex-direction:column;align-items:center;gap:8px;margin:0 0 16px;padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
.totp-qr{width:176px;height:176px}.totp-qr svg,.totp-qr img{width:100%;height:100%;display:block}
.totp-key{margin:0;text-align:center;font-size:.8rem;color:#64748b}.totp-key span{display:block}
.totp-key code{display:block;margin-top:2px;font-size:1rem;letter-spacing:.08em;color:#0f172a;word-break:break-all;user-select:all}
.totp-open{margin:0;font-size:.82rem;color:#64748b}
</style>
<script src="Assets/js/qrcode.js"></script>
<script>
if (window.lucide) lucide.createIcons();
(function () {
    var box = document.getElementById('totp-qr');
    try { var q = qrcode(0, 'M'); q.addData(<?php echo json_encode($uri); ?>); q.make(); box.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }); }
    catch (e) { box.hidden = true; }
})();
</script>
<script>try{localStorage.removeItem("mbus_reg_draft_v1")}catch(e){}</script>
<script src="Assets/js/submit_feedback.js"></script>
</body>
</html>
