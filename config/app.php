<?php

function mbus_is_production(): bool
{
    return strtolower(getenv('MBUS_ENV') ?: '') === 'production';
}

function mbus_timezone(): string
{
    $timezone = getenv('MBUS_TIMEZONE') ?: 'Asia/Manila';
    return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Manila';
}

/**
 * Date boundaries in the app timezone (Asia/Manila) as 'Y-m-d H:i:s' strings.
 *
 * schedule.departure_time is stored as a local timestamp, so filters such as
 * "today" or "this week" must be computed from the app clock. The database's
 * NOW()/CURRENT_DATE follow the DB session timezone, which can be UTC behind a
 * connection pooler and then disagree with the stored local times.
 * The values are server-generated (never user input), safe to inline in SQL.
 */
function mbus_local_bounds(): array
{
    $now = new DateTimeImmutable('now');
    $today = $now->setTime(0, 0, 0);
    $week = $today->modify('monday this week'); // ISO week starts Monday
    $fmt = fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

    return [
        'now' => $fmt($now),
        'today' => $fmt($today),
        'tomorrow' => $fmt($today->modify('+1 day')),
        'day_after' => $fmt($today->modify('+2 days')),
        'week_start' => $fmt($week),
        'next_week' => $fmt($week->modify('+7 days')),
        'week_after_next' => $fmt($week->modify('+14 days')),
    ];
}

function mbus_bootstrap(): void
{
    if (mbus_is_production()) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('log_errors', '1');

        // Only set session config if session hasn't started yet
        if (session_status() === PHP_SESSION_NONE) {
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_secure', '1');
            }
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_samesite', 'Strict');
        }
    } else {
        // Development settings
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_samesite', 'Lax');
        }
    }
}

mbus_bootstrap();
date_default_timezone_set(mbus_timezone());
