<?php

/**
 * Shared validation rules for account and operator/driver forms.
 * These rules mirror the current users table limits and the Philippine mobile format
 * used by the application, so the browser and server reject the same values.
 */
function mbus_normalize_email(mixed $value): string
{
    return strtolower(trim((string)$value));
}

function mbus_validate_email(string $email): ?string
{
    if ($email === '') {
        return 'Email address is required.';
    }

    if (strlen($email) > 100) {
        return 'Email address must be 100 characters or fewer.';
    }

    // Require a real-looking domain (for example, name@example.com), not arbitrary text.
    if (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || !preg_match('/^[^@\s]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,63}$/', $email)
    ) {
        return 'Enter a valid email address, such as name@example.com.';
    }

    return null;
}

function mbus_validate_phone(string $phone): ?string
{
    if ($phone === '') {
        return 'Phone number is required.';
    }

    if (!preg_match('/^\d+$/', $phone)) {
        return 'Phone number can contain digits only.';
    }

    if (!preg_match('/^09\d{9}$/', $phone)) {
        return 'Phone number must be exactly 11 digits and start with 09 (for example, 09123456789).';
    }

    return null;
}

/** Normalize Philippine mobile input to the database format 09XXXXXXXXX. */
function mbus_normalize_phone(mixed $value): string
{
    $phone = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (str_starts_with($phone, '63') && strlen($phone) === 12) {
        return '0' . substr($phone, 2);
    }
    if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
        return '0' . $phone;
    }
    return $phone;
}

function mbus_validate_full_name(string $name): ?string
{
    if ($name === '') {
        return 'Full name is required.';
    }

    if (strlen($name) > 100) {
        return 'Full name must be 100 characters or fewer.';
    }

    if (!preg_match("/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u", $name)) {
        return 'Full name may contain letters, spaces, apostrophes, periods, and hyphens only.';
    }

    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) < 2) {
        return 'Enter at least your first name and last name.';
    }

    return null;
}

function mbus_validate_password(string $password, int $minimum = 8): ?string
{
    if ($password === '') {
        return 'Password is required.';
    }

    if (strlen($password) < $minimum) {
        return "Password must be at least {$minimum} characters.";
    }

    if (strlen($password) > 128) {
        return 'Password must be 128 characters or fewer.';
    }

    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include an uppercase letter, lowercase letter, number, and symbol.';
    }

    return null;
}

function mbus_auth_error_attr(?string $error): string
{
    return $error !== null ? ' aria-invalid="true"' : '';
}
