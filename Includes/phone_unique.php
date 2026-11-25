<?php
/**
 * True when another account (or a pending application) already uses this phone number.
 * Compares digits only, so 0917 123 4567 and 09171234567 count as the same number.
 * Returns null if the main users check could not run, so callers can fail safe.
 */
function mbus_phone_in_use($conn, string $phone, int $exclude_user_id = 0): ?bool
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 12 && strpos($digits, '63') === 0) $digits = '0' . substr($digits, 2);
    if ($digits === '') return false;
    $norm = "regexp_replace(COALESCE(phone_number,''), '\\D', '', 'g')";

    // An account still waiting for its email code never finished signing up, so it doesn't hold the number.
    $res = mbus_db_prepare($conn, "SELECT 1 FROM users WHERE $norm = ? AND user_id <> ? AND NOT (email_verified_at IS NULL AND registration_code IS NOT NULL) LIMIT 1", [$digits, $exclude_user_id]);
    if ($res === false) return null;
    if (mbus_db_num_rows($res) > 0) return true;

    // Pending applications reserve the number too; a missing table just means none are pending.
    $pending = [
        "SELECT 1 FROM operator_application WHERE $norm = ? AND application_status::text = 'Pending' AND email_verified = TRUE LIMIT 1",
        "SELECT 1 FROM conductor_application WHERE $norm = ? AND application_status = 'Pending' AND email_verified = TRUE LIMIT 1",
    ];
    foreach ($pending as $sql) {
        $r = mbus_db_prepare($conn, $sql, [$digits]);
        if ($r !== false && mbus_db_num_rows($r) > 0) return true;
    }
    return false;
}

/**
 * Drops commuter sign-ups that never got their email code and share this email or phone,
 * so someone who backs out of verification can register again with the same details.
 */
function mbus_purge_unverified_signups($conn, string $email, string $phone): void
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 12 && strpos($digits, '63') === 0) $digits = '0' . substr($digits, 2);
    $norm = "regexp_replace(COALESCE(phone_number,''), '\\D', '', 'g')";
    $unverified = "email_verified_at IS NULL AND registration_code IS NOT NULL AND LOWER(user_type) = 'commuter'";
    if ($email !== '') mbus_db_prepare($conn, "DELETE FROM users WHERE $unverified AND LOWER(email) = LOWER(?)", [$email]);
    if ($digits !== '') mbus_db_prepare($conn, "DELETE FROM users WHERE $unverified AND $norm = ?", [$digits]);
}
