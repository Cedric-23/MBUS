<?php
/*
 * Signed ticket tokens for the boarding QR code.
 *
 * A token identifies one commuter's booking on one schedule: "v1.<schedule_id>.<user_id>.<signature>".
 * The signature is an HMAC, so a QR cannot be forged or edited to point at someone else's booking.
 * Set MBUS_QR_SECRET in the environment to use your own key; otherwise one is derived from the DB password.
 */

function mbus_qr_secret(): string
{
    $secret = getenv('MBUS_QR_SECRET');
    if ($secret !== false && $secret !== '') {
        return $secret;
    }
    return hash('sha256', 'mbus-ticket-qr|' . (getenv('SUPABASE_DB_PASSWORD') ?: '') . '|' . (getenv('SUPABASE_DB_USER') ?: ''));
}

function mbus_ticket_token(int $schedule_id, int $user_id): string
{
    $payload = "v1.$schedule_id.$user_id";
    return $payload . '.' . substr(hash_hmac('sha256', $payload, mbus_qr_secret()), 0, 24);
}

/** Returns ['schedule_id' => int, 'user_id' => int] for a valid token, or null. */
function mbus_verify_ticket_token(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^v1\.(\d+)\.(\d+)\.([0-9a-f]{24})$/', $token, $m)) {
        return null;
    }
    $expected = substr(hash_hmac('sha256', "v1.$m[1].$m[2]", mbus_qr_secret()), 0, 24);
    if (!hash_equals($expected, $m[3])) {
        return null;
    }
    return ['schedule_id' => (int)$m[1], 'user_id' => (int)$m[2]];
}
