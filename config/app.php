<?php

function mbus_is_production(): bool
{
    return strtolower(getenv('MBUS_ENV') ?: '') === 'production';
}

function mbus_bootstrap(): void
{
    if (mbus_is_production()) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('log_errors', '1');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
    }
}

mbus_bootstrap();
