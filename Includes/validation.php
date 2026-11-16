<?php
/** Shared sign-up checks. Each check returns an error message, or '' when the value is fine. */

function mbus_check_name(string &$name, string $label = 'Full name'): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return "$label is required.";
    if (mb_strlen($name) < 2) return "$label is too short (at least 2 letters).";
    if (mb_strlen($name) > 100) return "$label is too long (100 characters at most).";
    if (!preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $name)) return "$label can only have letters, spaces, apostrophes, periods and hyphens (no numbers or symbols).";
    return '';
}

/** Accepts 09XXXXXXXXX, 9XXXXXXXXX, 639XXXXXXXXX or +63 9XX XXX XXXX and stores it as 09XXXXXXXXX. */
function mbus_check_phone(string &$phone): string {
    $raw = trim($phone);
    if ($raw === '') return 'Phone number is required.';
    $digits = preg_replace('/[\s\-()]/', '', $raw);
    if (preg_match('/[^\d+]/', $digits)) return 'Phone number can only contain numbers.';
    $digits = ltrim($digits, '+');
    if (preg_match('/^639\d{9}$/', $digits)) $digits = '0' . substr($digits, 2);
    elseif (preg_match('/^9\d{9}$/', $digits)) $digits = '0' . $digits;
    $phone = $digits;
    if (strlen($digits) !== 11) return 'Phone number must be 11 digits (you entered ' . strlen($digits) . '). Example: 09123456789.';
    if (substr($digits, 0, 2) !== '09') return 'Phone number must start with 09. Example: 09123456789.';
    return '';
}

function mbus_check_password(string $pw, ?string $confirm = null): string {
    if ($pw === '') return 'Password is required.';
    if (strlen($pw) < 8) return 'Password is too short (at least 8 characters).';
    if (strlen($pw) > 72) return 'Password is too long (72 characters at most).';
    if (!preg_match('/[A-Z]/', $pw)) return 'Password needs at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $pw)) return 'Password needs at least one lowercase letter.';
    if (!preg_match('/\d/', $pw)) return 'Password needs at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return 'Password needs at least one symbol.';
    if ($confirm !== null && $pw !== $confirm) return 'Passwords do not match.';
    return '';
}

/**
 * Real-address checks: format, a domain that can receive mail, no throwaway inboxes, common typo hints.
 * Normalizes $email to lowercase. DNS is only trusted when a control lookup works, so a DNS outage never blocks sign-up.
 */
function mbus_check_email(string &$email): string {
    $email = strtolower(trim($email));
    if ($email === '') return 'Email is required.';
    if (strlen($email) > 254) return 'Email is too long.';
    if (preg_match('/\s/', $email)) return 'Email cannot contain spaces.';
    if (substr_count($email, '@') !== 1) return 'Email needs one @ sign, like name@gmail.com.';
    [$local, $domain] = explode('@', $email);
    if ($local === '' || strlen($local) > 64) return 'Email is missing the part before the @.';
    if ($domain === '' || strpos($domain, '.') === false) return 'Email domain looks incomplete (for example gmail.com).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\.[a-z]{2,}$/', $domain) || strpos($email, '..') !== false) {
        return 'That email address is not valid. Check it for typos.';
    }
    $typos = ['gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmaill.com' => 'gmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'hotmal.com' => 'hotmail.com', 'hotmial.com' => 'hotmail.com', 'outlok.com' => 'outlook.com'];
    if (isset($typos[$domain])) return "Did you mean $local@{$typos[$domain]}?";
    $disposable = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com',
        'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mailnesia.com', 'tempmailo.com', 'mohmal.com'];
    if (in_array($domain, $disposable, true)) return 'Temporary email addresses are not allowed. Use your own email.';
    if (function_exists('checkdnsrr')) {
        static $dnsWorks = null;
        if ($dnsWorks === null) $dnsWorks = @checkdnsrr('gmail.com.', 'MX');
        if ($dnsWorks && !@checkdnsrr($domain . '.', 'MX') && !@checkdnsrr($domain . '.', 'A')) {
            return "We couldn't find a mail server for @$domain. Check the email for typos.";
        }
    }
    return '';
}

function mbus_check_license_number(string &$v): string {
    $v = strtoupper(trim($v));
    if ($v === '') return 'License number is required.';
    if (!preg_match('/^[A-Z0-9][A-Z0-9\- ]{4,19}$/', $v)) return 'License number must be 5 to 20 letters, numbers or hyphens (example: N01-23-456789).';
    return '';
}

function mbus_check_plate(string &$v): string {
    $v = strtoupper(preg_replace('/\s+/', ' ', trim($v)));
    if ($v === '') return 'Plate number is required.';
    if (!preg_match('/^[A-Z0-9][A-Z0-9\- ]{3,11}$/', $v)) return 'Plate number must be 4 to 12 letters or numbers (example: ABC 1234).';
    return '';
}

function mbus_field_error(array $errors, string $key): string {
    return isset($errors[$key]) ? '<small class="field-error" id="' . htmlspecialchars($key) . '-error" role="alert">' . htmlspecialchars($errors[$key]) . '</small>' : '';
}

/** Full name must be at least two words (first and last name). Returns '' when OK. */
function mbus_check_full_name(string &$name): string {
    $e = mbus_check_name($name);
    if ($e !== '') return $e;
    if (count(array_filter(explode(' ', $name), fn($w) => preg_match('/\p{L}/u', $w))) < 2) return 'Enter your first and last name (for example: Juan Dela Cruz).';
    return '';
}
