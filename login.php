<?php

session_start();
include __DIR__ . "/config/db_connect.php";
include __DIR__ . "/Includes/activity_log.php";
include __DIR__ . "/Includes/csrf.php";
include_once __DIR__ . "/Includes/validation.php";

$staff_portal = defined('MBUS_STAFF_PORTAL') && MBUS_STAFF_PORTAL === true;

/* Local escape helper (distinct name so it can't clash with your includes) */
function login_e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/*
 * Account types are normalised to the names your dashboards already use.
 * register.php calls them "passenger"/"driver"; the rest of the system uses
 * "commuter"/"operator".
 */
$type_aliases = [
    'passenger' => 'commuter',
    'driver'    => 'operator',
];

$dashboards = [
    'commuter'  => 'Commuter/commuter_dashboard.php',
    'operator'  => 'Operator/operator_dashboard.php',
    'admin'     => 'Admin/admin_dashboard.php',
    'conductor' => 'Conductor/conductor_dashboard.php',
];

$csrf_error    = '';
$login_error   = '';
$login_success = '';

if (isset($_GET['registered'])) {
    $login_success = 'Account verified successfully. You can now sign in.';
} elseif (isset($_GET['reset'])) {
    $login_success = 'Password changed! Your password was updated successfully. Sign in with your new password.';
}

if (isset($_GET['google'])) {
    $gmsg = ['unavailable' => 'Google sign-in is not set up yet.', 'cancelled' => 'Google sign-in was cancelled.',
             'notallowed' => 'This account type cannot sign in with Google. Use your email and password.'];
    $login_error = $gmsg[$_GET['google']] ?? 'Google sign-in failed. Please try again.';
}

$field_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_validate_token()) {
        $csrf_error = 'Security validation failed. Please reload the page and try again.';
    } else {
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');   // never trimmed

        $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? ['n' => 0, 'until' => 0];
        if ($email === '') $field_errors['email'] = 'Enter your email address.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $field_errors['email'] = 'That does not look like an email address (example: juan@gmail.com).';
        if ($password === '') $field_errors['password'] = 'Enter your password.';

        if ($field_errors) {
            $login_error = 'Please fix the highlighted fields.';
        } elseif ($_SESSION['login_fails']['until'] > time()) {
            $login_error = 'Too many wrong attempts. Try again in ' . ($_SESSION['login_fails']['until'] - time()) . ' seconds.';
        } else {
            $result = mbus_db_prepare($conn, "SELECT * FROM users WHERE LOWER(email) = ?", [$email]);

            if ($result === false) {
                error_log("Login query failed: " . mbus_db_error($conn));
                $login_error = 'Login is temporarily unavailable. Please try again in a moment.';
            } elseif (mbus_db_num_rows($result) > 0) {
                $user = mbus_db_fetch_assoc($result);

                if (!empty($user['password']) && password_verify($password, $user['password'])) {
                    $type = strtolower(trim($user['user_type'] ?? ''));
                    $type = $type_aliases[$type] ?? $type;

                    if ($staff_portal && !in_array($type, ['operator', 'conductor'], true)) {
                        $login_error = 'This portal is for approved driver, operator, and conductor accounts.';
                    } elseif ($type === 'commuter' && empty($user['email_verified_at'])) {
                        $login_error = 'Please verify your email address before signing in. Check your inbox for the 6-digit code.';
                    } elseif (!isset($dashboards[$type])) {
                        $login_error = 'This account type cannot sign in here. Contact an admin.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id']         = $user['user_id'];
                        $_SESSION['full_name']       = $user['full_name'];
                        $_SESSION['user_type']       = $type;
                        $_SESSION['profile_picture'] = $user['profile_picture'] ?? 'default.png';
                        $_SESSION['login_fails']     = ['n' => 0, 'until' => 0];

                        log_activity($conn, $user['user_id'], ACTION_LOGIN, "User logged in successfully");
                        header("Location: " . $dashboards[$type]);
                        exit();
                    }
                } else {
                    $field_errors['password'] = 'Incorrect password. Check it and try again, or use Forgot password.';
                    $login_error = 'Incorrect password.';
                }
            } else {
                // No account yet: is there an application waiting or rejected?
                $note = '';
                $q = mbus_db_prepare($conn, "SELECT application_status::text AS s, rejection_reason AS r FROM operator_application WHERE LOWER(email) = ? ORDER BY submitted_at DESC LIMIT 1", [$email]);
                $row = $q !== false ? mbus_db_fetch_assoc($q) : null;
                if (!$row) {
                    $q = mbus_db_prepare($conn, "SELECT application_status AS s, rejection_reason AS r FROM conductor_application WHERE LOWER(email) = ? ORDER BY submitted_at DESC LIMIT 1", [$email]);
                    $row = $q !== false ? mbus_db_fetch_assoc($q) : null;
                }
                if ($row && $row['s'] === 'Pending') {
                    $note = 'Your application is still being reviewed by an admin. You can log in once it is approved.';
                } elseif ($row && $row['s'] === 'Rejected') {
                    $note = 'Your application was not approved' . (!empty($row['r']) ? ': ' . $row['r'] : '.') . ' You can apply again with correct details.';
                }
                if ($note !== '') {
                    $login_error = $note;
                } else {
                    $field_errors['email'] = 'No account found with this email. Check the spelling or create an account.';
                    $login_error = 'No account found.';
                }
            }

            if (isset($field_errors['password']) || isset($field_errors['email'])) {
                $f = &$_SESSION['login_fails']; $f['n']++;
                if ($f['n'] >= 5) { $f['until'] = time() + 60; $f['n'] = 0; $login_error = 'Too many wrong attempts. Try again in 60 seconds.'; }
                unset($f);
            }
        }
    }
}

$has_error = ($csrf_error !== '' || $login_error !== '');
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>MBUS - Sign In</title>
        <link rel="stylesheet" href="Assets/css/global/font.css"/>
        <link rel="stylesheet" href="Assets/css/main/register.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/register.css') ?>"/>
        <link rel="stylesheet" href="Assets/css/main/auth_modern.css?v=<?= @filemtime(__DIR__ . '/Assets/css/main/auth_modern.css') ?>"/>
        <script src="https://unpkg.com/lucide@0.462.0/dist/umd/lucide.min.js"></script>
        <style>
            .form-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
            .form-alert.error   { background: #fdecea; color: #b42318; border: 1px solid #f5c2be; }
            .form-alert.success { background: #e7f6ec; color: #1a7f37; border: 1px solid #b7e4c7; display:flex; gap:10px; align-items:flex-start; font-weight:600; line-height:1.4; }
            .form-alert.success::before { content:"\2713"; flex:0 0 22px; height:22px; border-radius:50%; background:#1a7f37; color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; margin-top:1px; }

            /* Login has single-column fields, so stack them full width */
            .login-form .form-contact-input,
            .login-form .form-password-input { display: block; width: 100%; margin-bottom: 16px; }
            .login-form .form-email-input,
            .login-form .password-input { width: 100%; }
            .login-form .login-options { display: flex; justify-content: flex-end; margin-bottom: 16px; font-size: 14px; }
            .login-form .login-options a { color: #086FCF; text-decoration: none; }
            .login-form input[aria-invalid="true"] { border-color: #d92d20; }
            .login-form .form-email-input,.login-form .password-input{position:relative}
        </style>
    </head>
    <body class="auth-login">
        <div class="auth-hero-m auth-hero-brand">
            <h1>Welcome back</h1>
            <p>Sign in to manage your trips and bookings.</p>
            <img class="auth-hero-logo" src="Assets/images/mbus_logo.png" alt="MBUS">
            <img class="auth-hero-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true">
        </div>
        <div class="left-container">
            <div class="left-container-child-1">
                <div class="logo-wordmark">
                    <img src="Assets/images/mbus_logo.png" alt="logo">
                </div>
            </div>
            <div class="left-container-child-2">
                <div class="lc-heading">
                    <h1>Travel smarter.</h1>
                    <h1>Travel on time.</h1>
                </div>
                <div class="lc-sub-heading">
                    <p>Your dependable way to reserve seats, manage trips, and travel with confidence.</p>
                </div>
            </div>
            <img class="auth-art" src="Assets/images/auth_bus.svg" alt="" aria-hidden="true">
            <div class="lc-circle-1"></div>
            <div class="lc-circle-2"></div>
        </div>
        <div class="right-container">
            <div class="form-container">
                <div class="auth-tabs" role="tablist">
                    <a href="login.php" class="on" role="tab">Login</a>
                    <a href="register.php?type=<?php echo $staff_portal ? 'driver' : 'passenger'; ?>" class="" role="tab">Register</a>
                </div>
                <div class="form-header">
                    <div class="form-header-text">
                        <h1>Welcome back</h1>
                        <p>Sign in to manage your trips and bookings.</p>
                    </div>
                </div>

                <?php if ($csrf_error): ?>
                    <div class="form-alert error" role="alert"><?= login_e($csrf_error) ?></div>
                <?php endif; ?>
                <?php if ($login_error): ?>
                    <div class="form-alert error" role="alert"><?= login_e($login_error) ?></div>
                <?php endif; ?>
                <?php if ($login_success && !$has_error): ?>
                    <div class="form-alert success" role="status"><?= login_e($login_success) ?></div>
                <?php endif; ?>

                <form action="login.php" class="form-body login-form" method="POST" novalidate>
                    <?php csrf_field(); ?>

                    <div class="passenger active" id="login">
                        <div class="form-contact-input">
                            <div class="form-email-input">
                                <label>Email Address</label>
                                <input name="email" type="email" placeholder="juan@example.com"
                                       value="<?= login_e($_POST['email'] ?? '') ?>"
                                       autocapitalize="none" autocorrect="off" spellcheck="false"
                                       autocomplete="email" autofocus
                                       <?= isset($field_errors['email']) ? 'aria-invalid="true"' : '' ?> maxlength="254"/>
                                <i class="input-icon" data-lucide="mail"></i>
                                <?= mbus_field_error($field_errors, 'email') ?>
                            </div>
                        </div>
                        <div class="form-password-input">
                            <div class="password-input">
                                <label>Password</label>
                                <div class="password-control">
                                    <input name="password" type="password" placeholder="Enter your password"
                                           autocomplete="current-password"
                                           <?= isset($field_errors['password']) ? 'aria-invalid="true"' : '' ?> maxlength="72"/>
                                    <i class="input-icon" data-lucide="lock"></i>
                                    <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"></button>
                                </div>
                                <?= mbus_field_error($field_errors, 'password') ?>
                            </div>
                        </div>

                        <div class="login-options">
                            <a href="forgot_password.php">Forgot password?</a>
                        </div>

                        <button class="submit-button" id="login-submit" type="submit">Sign in</button>

                        <?php if (!$staff_portal): ?><div class="auth-or"><span>Or login with</span></div>
                        <a class="google-btn" href="google_login.php">
                            <svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.6z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.9 2.3-8.3 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
                            Continue with Google
                        </a><?php endif; ?>

                        

                        <div class="footer"></div>
                    </div>
                </form>
            </div>
        </div>

        <script>
            if (window.lucide) lucide.createIcons();

            // Loading state on submit (the server doesn't depend on the button's name/value)
            var form = document.querySelector('.login-form');
            var submitBtn = document.getElementById('login-submit');
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing in…';
            });
            // Re-enable if the user comes back with the browser's Back button
            window.addEventListener('pageshow', function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Sign in';
            });
        </script>
        <script src="Assets/js/password-toggle.js"></script>
    <script src="Assets/js/submit_feedback.js"></script>
</body>
</html>
