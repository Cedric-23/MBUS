<?php
/* Google sign-in (OAuth 2.0 authorization-code flow). Needs GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the environment. */

function mbus_google_client(): array {
    $id = trim((string)getenv('GOOGLE_CLIENT_ID'));
    $secret = trim((string)getenv('GOOGLE_CLIENT_SECRET'));
    $redirect = trim((string)getenv('GOOGLE_REDIRECT_URI'));
    if ($redirect === '') {
        $https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $redirect = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/google_callback.php';
    }
    return ['id' => $id, 'secret' => $secret, 'redirect' => $redirect, 'ready' => $id !== '' && $secret !== ''];
}

/** POST (form) or GET JSON helper. Returns decoded array or null. */
function mbus_google_http(string $url, ?array $form = null, ?string $bearer = null): ?array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    if ($form !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $body = curl_exec($ch);
    curl_close($ch);
    $d = is_string($body) ? json_decode($body, true) : null;
    return is_array($d) ? $d : null;
}
