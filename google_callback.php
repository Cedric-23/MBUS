<?php
/* Step 2: Google sends the visitor back here with a one-time code. */
session_start();
include "config/db_connect.php";
include "Includes/activity_log.php";
require_once __DIR__ . '/Includes/google_auth.php';

function google_fail(string $code): void { header('Location: login.php?google=' . $code); exit(); }

$g = mbus_google_client();
if (!$g['ready']) google_fail('unavailable');
$state = (string)($_GET['state'] ?? '');
$expected = (string)($_SESSION['google_state'] ?? '');
unset($_SESSION['google_state']);
if ($expected === '' || !hash_equals($expected, $state)) google_fail('failed');
if (isset($_GET['error']) || empty($_GET['code'])) google_fail('cancelled');

$tok = mbus_google_http('https://oauth2.googleapis.com/token', [
    'code' => (string)$_GET['code'], 'client_id' => $g['id'], 'client_secret' => $g['secret'],
    'redirect_uri' => $g['redirect'], 'grant_type' => 'authorization_code',
]);
if (!$tok || empty($tok['access_token'])) google_fail('failed');
$info = mbus_google_http('https://openidconnect.googleapis.com/v1/userinfo', null, $tok['access_token']);
$email = strtolower(trim((string)($info['email'] ?? '')));
if (!$info || $email === '' || empty($info['email_verified'])) google_fail('failed');
$name = trim((string)($info['name'] ?? '')) ?: strstr($email, '@', true);
$name = mb_substr($name, 0, 100);

$dashboards = ['commuter' => 'Commuter/commuter_dashboard.php', 'driver' => 'Operator/operator_dashboard.php', 'conductor' => 'Conductor/conductor_dashboard.php'];
$aliases = ['passenger' => 'commuter', 'operator' => 'driver'];

$r = mbus_db_prepare($conn, "SELECT * FROM users WHERE LOWER(email) = ?", [$email]);
$user = ($r !== false && mbus_db_num_rows($r) > 0) ? mbus_db_fetch_assoc($r) : null;

if (!$user) {
    // First time with Google: make a commuter account (Google already verified the email).
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $ok = mbus_db_prepare($conn, "INSERT INTO users (full_name, email, password, user_type, email_verified_at) VALUES (?, ?, ?, 'Commuter', NOW())", [$name, $email, $hash]);
    if ($ok === false) google_fail('failed');
    $r = mbus_db_prepare($conn, "SELECT * FROM users WHERE LOWER(email) = ?", [$email]);
    $user = $r !== false ? mbus_db_fetch_assoc($r) : null;
    if (!$user) google_fail('failed');
} elseif (empty($user['email_verified_at'])) {
    // Google proved they own this address, so mark it verified.
    mbus_db_prepare($conn, "UPDATE users SET email_verified_at = NOW(), registration_code = NULL WHERE user_id = ?", [$user['user_id']]);
}

$type = strtolower(trim((string)($user['user_type'] ?? '')));
$type = $aliases[$type] ?? $type;
if (!isset($dashboards[$type])) google_fail('notallowed');   // admins sign in with their password only

session_regenerate_id(true);
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['user_type'] = $type;
$_SESSION['profile_picture'] = $user['profile_picture'] ?? 'default.png';
$_SESSION['login_fails'] = ['n' => 0, 'until' => 0];
log_activity($conn, $user['user_id'], ACTION_LOGIN, "User logged in with Google");
header('Location: ' . $dashboards[$type]);
exit();
