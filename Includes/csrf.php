<?php
// CSRF Protection Helper Functions

/**
 * Generate CSRF token and store in session
 */
function csrf_generate_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();

    return $token;
}

/**
 * Get existing CSRF token or generate new one
 */
function csrf_get_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return csrf_generate_token();
    }

    // Regenerate token if it's older than 1 hour
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        return csrf_generate_token();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token from POST request
 */
function csrf_validate_token(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }

    // Check if token is expired (1 hour)
    if (isset($_SESSION['csrf_token_time']) && time() - $_SESSION['csrf_token_time'] > 3600) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Output CSRF token as hidden input field
 */
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_get_token()) . '">';
}

/**
 * Validate CSRF token and die if invalid
 */
function csrf_verify_or_die(): void
{
    if (!csrf_validate_token()) {
        http_response_code(403);
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
}
?>
