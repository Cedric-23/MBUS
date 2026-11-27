<?php
/* Time-based one-time passwords (RFC 6238) for Google Authenticator, Microsoft Authenticator, Authy and similar apps. */

function mbus_totp_new_secret(int $bytes = 20): string
{
    return mbus_totp_base32(random_bytes($bytes));
}

function mbus_totp_base32(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function mbus_totp_base32_decode(string $s): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) {
        $p = strpos($alphabet, $c);
        if ($p !== false) $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function mbus_totp_code(string $secret, ?int $time = null): string
{
    $counter = intdiv($time ?? time(), 30);
    $hash = hash_hmac('sha1', pack('J', $counter), mbus_totp_base32_decode($secret), true);
    $o = ord($hash[19]) & 0xF;
    $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code and one step either side, to allow for clock drift. */
function mbus_totp_verify(string $secret, string $code, ?int $time = null): bool
{
    if (!preg_match('/^\d{6}$/', $code) || $secret === '') return false;
    $time ??= time();
    foreach ([0, -30, 30] as $skew) {
        if (hash_equals(mbus_totp_code($secret, $time + $skew), $code)) return true;
    }
    return false;
}

function mbus_totp_uri(string $secret, string $account, string $issuer = 'MBUS'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
