# MBUS Login Page - Bug Fixes Report

**Date:** 2026-11-11
**Analyzed by:** Dennice (Debugger)
**Scope:** Login page (`login.php`) and related auth files

---

## Issues Found & Fixes

### 1. Email Enumeration Vulnerability — HIGH
**Files:** `login.php` (lines 60-61), `forgot_password.php` (line 37)
**Problem:** Different error messages reveal whether an email exists:
- `login.php`: "User not found" vs "Incorrect password"
- `forgot_password.php`: "Email not found" (only shown when email doesn't exist)

**Fix:** Use generic error message for both cases.

**Before (login.php lines 56-62):**
```php
} else {
    $login_error = 'Incorrect password';
}

} else {
    $login_error = 'User not found';
}
```

**After:**
```php
} else {
    $login_error = 'Invalid email or password';
}

} else {
    $login_error = 'Invalid email or password';
}
```

**Before (forgot_password.php line 37):**
```php
$error = 'Email not found';
```

**After:**
```php
$error = 'If this email exists, a reset code has been sent';
```

---

### 2. No Rate Limiting on Auth Endpoints — HIGH
**Files:** `login.php`, `forgot_password.php`
**Problem:** No protection against brute-force attacks on login and password reset.

**Fix:** Implement rate limiting using a simple approach (store attempts in session or database).

**Add to login.php (after session_start):**
```php
// Rate limiting: max 5 attempts per 15 minutes
$attempts_key = 'login_attempts_' . $_SERVER['REMOTE_ADDR'];
$attempts_time_key = 'login_attempts_time_' . $_SERVER['REMOTE_ADDR'];

if (isset($_SESSION[$attempts_time_key]) && time() - $_SESSION[$attempts_time_key] > 900) {
    $_SESSION[$attempts_key] = 0;
    unset($_SESSION[$attempts_time_key]);
}

if (isset($_SESSION[$attempts_key]) && $_SESSION[$attempts_key] >= 5) {
    $login_error = 'Too many login attempts. Please try again in 15 minutes.';
    // Skip authentication logic
} else {
    // ... existing authentication logic ...
    if ($login_error) {
        $_SESSION[$attempts_key] = ($_SESSION[$attempts_key] ?? 0) + 1;
        $_SESSION[$attempts_time_key] = time();
    } else {
        unset($_SESSION[$attempts_key], $_SESSION[$attempts_time_key]);
    }
}
```

---

### 3. Hardcoded SMTP From Address — MEDIUM
**File:** `send_reset_code.php` (line 24)
**Problem:** `setFrom` uses hardcoded 'YOUR_EMAIL@gmail.com' instead of env variable.

**Before:**
```php
$mail->setFrom('YOUR_EMAIL@gmail.com', 'MBUS System');
```

**After:**
```php
$mail->setFrom(
    getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME') ?: 'noreply@mbus.local',
    getenv('SMTP_FROM_NAME') ?: 'MBUS System'
);
```

---

### 4. Weak Password Policy — MEDIUM
**Files:** `register.php` (line 27), `new_password.php`
**Problem:** Only enforces minimum 8 characters, no complexity requirements.

**Fix:** Add password strength validation.

**Add to register.php (after line 27):**
```php
} elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters.';
} elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $error = 'Password must contain uppercase, lowercase, and number.';
} else {
```

---

### 5. SQL Injection Risk in Activity Log — MEDIUM
**File:** `Includes/activity_log.php` (lines 9-18)
**Problem:** Uses `mbus_db_escape` + string interpolation instead of prepared statements.

**Before:**
```php
function log_activity($conn, $user_id, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $user_id = mbus_db_escape($conn, $user_id);
    $action = mbus_db_escape($conn, $action);
    $description = mbus_db_escape($conn, $description);
    $ip_address = mbus_db_escape($conn, $ip_address);
    $user_agent = mbus_db_escape($conn, $user_agent);
    
    mbus_db_query($conn,"
        INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
        VALUES ('$user_id', '$action', '$description', '$ip_address', '$user_agent')
    ");
}
```

**After:**
```php
function log_activity($conn, $user_id, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Use prepared statement
    mbus_db_prepare($conn, "
        INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ", [$user_id, $action, $description, $ip_address, $user_agent]);
}
```

---

### 6. Password Toggle UX Issue on Mobile — LOW
**Files:** `login.php` (lines 142-150), `register.php` (lines 154-162, 179-188)
**Problem:** Uses `onmousedown/onmouseup/onmouseleave` which doesn't work well on touch devices.

**Before:**
```html
<button 
    type="button" 
    class="password-toggle" 
    aria-label="Toggle password visibility"
    aria-pressed="false"
    onmousedown="showPassword('password')"
    onmouseup="hidePassword('password')"
    onmouseleave="hidePassword('password')"
>
    <i class="fa-solid fa-eye" aria-hidden="true"></i>
</button>
```

**After:**
```html
<button 
    type="button" 
    class="password-toggle" 
    aria-label="Toggle password visibility"
    aria-pressed="false"
    onclick="togglePassword('password')"
>
    <i class="fa-solid fa-eye" aria-hidden="true"></i>
</button>
```

**And update JS:**
```javascript
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const toggle = field.parentElement.querySelector('.password-toggle');
    if (field && toggle) {
        const isVisible = field.type === 'text';
        field.type = isVisible ? 'password' : 'text';
        toggle.innerHTML = isVisible 
            ? '<i class="fa-solid fa-eye" aria-hidden="true"></i>'
            : '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>';
        toggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
    }
}
```

---

### 7. Missing Secure Session Cookie Flags — LOW
**File:** `config/app.php` (or add to `config/db_connect.php`)
**Problem:** No HTTPS enforcement, secure cookie flags not set.

**Fix:** Add session configuration at application start.

**Add to config/app.php or top of login.php:**
```php
// Secure session configuration (must be before session_start())
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Only works on HTTPS
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
```

---

### 8. No Account Lockout Mechanism — LOW
**Files:** `login.php`, `forgot_password.php`
**Problem:** No temporary account lockout after repeated failures.

**Fix:** Track failed attempts per email in database, lock after 5 failures for 30 minutes.

---

### 9. CSRF Token: Single Pattern Only — LOW
**File:** `Includes/csrf.php`
**Problem:** Only session-based token, no double-submit cookie pattern for better CSRF protection.

**Fix:** Consider adding a double-submit cookie pattern for critical forms.

---

### 10. MySQL→PostgreSQL Translation Layer Technical Debt — LOW
**File:** `config/mysqli_compat.php`
**Problem:** Complex regex-based SQL translation layer introduces potential bugs.

**Fix:** Migrate to native PostgreSQL queries over time. Document as technical debt.

---

## Summary

| Severity | Count | Issues |
|----------|-------|--------|
| HIGH | 2 | Email Enumeration, No Rate Limiting |
| MEDIUM | 3 | Hardcoded SMTP, Weak Password Policy, SQL Injection Risk |
| LOW | 5 | Password Toggle UX, Secure Cookies, Account Lockout, CSRF Pattern, Translation Layer |

---

## Status

- [ ] **HIGH** Email Enumeration - Fix login.php and forgot_password.php error messages
- [ ] **HIGH** Rate Limiting - Add rate limiting to login.php and forgot_password.php
- [ ] **MEDIUM** Hardcoded SMTP - Fix send_reset_code.php setFrom
- [ ] **MEDIUM** Password Policy - Add complexity requirements to register.php and new_password.php
- [ ] **MEDIUM** SQL Injection Risk - Convert activity_log.php to prepared statements
- [ ] **LOW** Password Toggle UX - Fix mobile touch handling in login.php and register.php
- [ ] **LOW** Secure Session Cookies - Add session config
- [ ] **LOW** Account Lockout - Implement per-email lockout
- [ ] **LOW** CSRF Double-Submit - Enhance csrf.php
- [ ] **LOW** Translation Layer - Document as technical debt

---

**Next Step:** Hand off to Ashuri for prioritization and assignment to Ash (Backend) for implementation.