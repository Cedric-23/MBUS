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

    $res = mbus_db_prepare($conn, "SELECT 1 FROM users WHERE $norm = ? AND user_id <> ? LIMIT 1", [$digits, $exclude_user_id]);
    if ($res === false) return null;
    if (mbus_db_num_rows($res) > 0) return true;

    // Pending applications reserve the number too; a missing table just means none are pending.
    $pending = [
        "SELECT 1 FROM operator_application WHERE $norm = ? AND application_status::text = 'Pending' LIMIT 1",
        "SELECT 1 FROM conductor_application WHERE $norm = ? AND application_status = 'Pending' LIMIT 1",
    ];
    foreach ($pending as $sql) {
        $r = mbus_db_prepare($conn, $sql, [$digits]);
        if ($r !== false && mbus_db_num_rows($r) > 0) return true;
    }
    return false;
}
