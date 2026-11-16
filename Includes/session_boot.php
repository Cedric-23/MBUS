<?php
/* Loaded before every PHP page (auto_prepend_file in .htaccess).
 * Gives every page the same session settings, whichever order it includes things in,
 * and keeps people signed in for 12 hours instead of PHP's default 24 minutes of inactivity. */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($https) ini_set('session.cookie_secure', '1');
}
