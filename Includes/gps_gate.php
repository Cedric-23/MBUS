<?php
/**
 * MBUS location gate.
 *
 * The dashboard is only reachable after the browser has granted location access.
 * The acknowledgement is stored in a first-party cookie so PHP can enforce the
 * gate before any dashboard HTML is rendered.
 */

const MBUS_GPS_COOKIE = 'mbus_gps';

function mbus_gps_enabled(): bool
{
    return ($_COOKIE[MBUS_GPS_COOKIE] ?? '') === '1';
}

/** Default landing page for a role. */
function mbus_role_home(string $role): string
{
    return match (strtolower($role)) {
        'operator' => 'Operator/operator_dashboard.php',
        'admin' => 'Admin/admin_dashboard.php',
        default => 'Commuter/commuter_dashboard.php',
    };
}

/** Only allow in-app destinations, never an open redirect. */
function mbus_safe_next(?string $next, string $role): string
{
    $next = is_string($next) ? trim($next) : '';
    if ($next !== '' && preg_match('#^(Commuter|Operator|Admin)/[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%\-]*)?$#', $next)) {
        return $next;
    }
    return mbus_role_home($role);
}

/** Redirect to the gate when location has not been granted for this browser. */
function mbus_require_gps(string $next): void
{
    if (mbus_gps_enabled() || PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = preg_replace('#/(Commuter|Operator|Admin)/[^/]*$#', '', $script);
    header('Location: ' . ($base ?: '') . '/gps.php?next=' . rawurlencode($next));
    exit();
}
