<?php
/* Step 1: send the visitor to Google. */
session_start();
require_once __DIR__ . '/Includes/google_auth.php';
$g = mbus_google_client();
if (!$g['ready']) { header('Location: login.php?google=unavailable'); exit(); }
$_SESSION['google_state'] = bin2hex(random_bytes(16));
$q = http_build_query([
    'client_id' => $g['id'], 'redirect_uri' => $g['redirect'], 'response_type' => 'code',
    'scope' => 'openid email profile', 'state' => $_SESSION['google_state'], 'prompt' => 'select_account',
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $q);
exit();
