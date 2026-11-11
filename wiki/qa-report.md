# QA Report — MBUS `login.php` Security Audit

Scope: `raw_sources/MBUS/MBUS/login.php` and every file it includes:
`config/db_connect.php` → `config/app.php`, `config/load_env.php`, `config/mysqli_compat.php`;
`Includes/activity_log.php`; `Includes/csrf.php`.

No code was changed. Notes only.

Note on pipeline gaps: this is a standalone (SOLO) task. `wiki/ui-components.md` and `wiki/backend-api.md` in this vault describe a *different* project (Netflix clone) and do **not** cover the MBUS login flow, so they could not be used as a comparison baseline. The MBUS requirements file (`raw_sources/requirements.md`) and a dedicated MBUS spec were **not present** in the vault, so this report is a code-level security audit rather than a requirement-by-requirement PASS/FAIL trace.

## Requirements Trace

| Req | UI covers it? | API covers it? | Result |
|---|---|---|---|
| R1 — Authenticate user with email + password | n/a (no MBUS UI spec in vault) | n/a (no MBUS API spec in vault) | NOT VERIFIABLE |
| R2 — CSRF protection on login POST | yes (`csrf_field()` in form, `csrf_validate_token()` in handler) | yes (`Includes/csrf.php`) | PASS |
| R3 — Password stored/verified as hash | yes (`password_verify`) | yes (hash column read from `users`) | PASS |
| R4 — SQL injection safe on login lookup | n/a | yes (prepared statement `mbus_db_prepare`) | PASS |
| R5 — Session fixation defence on login | n/a | yes (`session_regenerate_id(true)`) | PASS |
| R6 — Generic auth failure message | n/a | no (distinct "User not found" / "Incorrect password") | FAIL |
| R7 — Brute-force / rate limiting on login | n/a | no implementation found | FAIL |
| R8 — Role-based redirect + session hardening | n/a | partial (session populated before role validated) | FAIL |
| R9 — Session cookie hardening (HttpOnly/Secure/SameSite/strict mode) | n/a | no (settings applied after session already started) | FAIL |
| R10 — DB error messages not leaked to client | n/a | no (`die('Connection failed: ' . $e->getMessage())`) | FAIL |
| R11 — Activity log write is injection-safe | n/a | partial (manual escaping, not bound params) | PASS (with ISSUE-8 caveat) |

## Issues

### ISSUE-1 — Session hardening never takes effect (session started before config)
- **Severity:** blocker
- **Where:** `raw_sources/MBUS/MBUS/login.php:3` (`session_start()`); `raw_sources/MBUS/MBUS/config/app.php:17-31` (`mbus_bootstrap()` guards on `session_status() === PHP_SESSION_NONE`)
- **Expected:** `session.cookie_httponly=1`, `session.use_strict_mode=1`, `session.cookie_samesite`, and (in production) `session.cookie_secure=1` are applied before the session starts.
- **Actual:** `login.php` calls `session_start()` on line 3, and only includes `config/db_connect.php` (→ `app.php` → `mbus_bootstrap()`) on line 4. By then `session_status()` is already `PHP_SESSION_ACTIVE`, so every `ini_set` in `mbus_bootstrap()` is skipped. The session cookie is therefore emitted without `HttpOnly`, without `SameSite`, and (in production) without `Secure`, and `use_strict_mode` is off so a client-supplied session ID is accepted.
- **How to reproduce:**
  1. Request `login.php` over HTTP.
  2. Inspect the `Set-Cookie: PHPSESSID=...` response header.
  3. Observe there is no `HttpOnly`, no `SameSite`, and no `Secure` attribute.
  4. Additionally, send a request with an attacker-chosen `Cookie: PHPSESSID=deadbeef` and observe it is accepted rather than rejected/regenerated.

### ISSUE-2 — Passwords are trimmed before verification
- **Severity:** blocker
- **Where:** `raw_sources/MBUS/MBUS/login.php:18` — `$password = trim($_POST['password']);`
- **Expected:** The password is verified exactly as the user entered/registered it; whitespace is significant.
- **Actual:** `trim()` strips leading/trailing whitespace, so a registered password `" pass123 "` can never be logged into (the stored hash was made from the untrimmed value). Conversely, ` "pass123"` and `"pass123"` are treated as identical at login, so the effective password space is smaller than what was registered.
- **How to reproduce:**
  1. Register or seed a user whose password hash was generated from `" secret "`.
  2. Log in with `secret` (no spaces).
  3. Observe "Incorrect password" even though the correct credential was entered.
  4. Confirm the same user *can* log in with `secret`, proving whitespace was silently discarded.

### ISSUE-3 — User enumeration via distinct error messages
- **Severity:** major
- **Where:** `raw_sources/MBUS/MBUS/login.php:57` (`'Incorrect password'`) and `raw_sources/MBUS/MBUS/login.php:61` (`'User not found'`)
- **Expected:** A single generic message (e.g. "Invalid email or password") for both cases.
- **Actual:** The two branches emit distinguishable text that is rendered to the page (`login.php:106-111`), letting an attacker script valid-email discovery by watching for "User not found".
- **How to reproduce:**
  1. POST the login form with `email=nobody@example.com` and any password → response shows "User not found".
  2. POST with a known-registered email and a wrong password → response shows "Incorrect password".
  3. The two responses differ, confirming enumeration.

### ISSUE-4 — No brute-force protection or rate limiting
- **Severity:** major
- **Where:** `raw_sources/MBUS/MBUS/login.php:11-64` (the whole POST handler); no lockout/failure counter exists in `login.php`, `Includes/csrf.php`, or `Includes/activity_log.php`.
- **Expected:** Failed attempts are throttled (per-IP/per-account delay, exponential backoff, or account lockout).
- **Actual:** Every POST performs an unbounded DB query and `password_verify`. An attacker can attempt unlimited passwords per second.
- **How to reproduce:**
  1. Send 200 sequential POSTs for a known email with wrong passwords.
  2. Observe every request is processed and a different error is returned only because the password is wrong — there is no delay, no 429, and no lockout.

### ISSUE-5 — Session populated before the role is validated (auth/ownership gap)
- **Severity:** major
- **Where:** `raw_sources/MBUS/MBUS/login.php:33-35` (session write) vs `login.php:40-54` (role branch)
- **Expected:** `$_SESSION['user_id']` etc. are only written after the role is confirmed to be one of `commuter` / `operator` / `admin`.
- **Actual:** The session is written with the user id, name, and type *before* the role is checked. If `user_type` is missing, NULL, or any unexpected value, the `else` branch sets `$login_error = 'Invalid user type'` but leaves a fully populated, authenticated session alive and the user on the login page with no redirect. Any later page that checks only `$_SESSION['user_id']` will treat this as a logged-in user with an unvalidated/empty role.
- **How to reproduce:**
  1. Set `users.user_type` for a test account to `guest` (or NULL) directly in the DB.
  2. Log in with valid credentials.
  3. Observe the error "Invalid user type" but `$_SESSION['user_id']` is already set (verify with a temporary `var_dump($_SESSION)` or by hitting an authenticated page that only checks `user_id`).
  4. The session persists and is never destroyed.

### ISSUE-6 — Database error details leaked to the client
- **Severity:** major
- **Where:** `raw_sources/MBUS/MBUS/config/db_connect.php:28-31` — `die('Connection failed: ' . $e->getMessage());`
- **Expected:** A generic failure message to the user; the detailed error only in `error_log`.
- **Actual:** The raw PDO exception message (host, port, dbname, and possibly auth details) is printed to the browser. The same file also ships hardcoded fallback credentials/host (`db_connect.php:7-11`, default Supabase host and user `postgres.xeczvnheaixpfattbwsk`).
- **How to reproduce:**
  1. Point `SUPABASE_DB_HOST`/`SUPABASE_DB_PASSWORD` at an unreachable host (or unset the password so the `die()` on line 13 fires).
  2. Load `login.php`.
  3. Observe the connection failure message revealing internal connection details in the response body.

### ISSUE-7 — Missing `isset` guard on `$_POST['email']` / `$_POST['password']`
- **Severity:** major
- **Where:** `raw_sources/MBUS/MBUS/login.php:17-18`
- **Expected:** Validate presence and type of the submitted fields before use.
- **Actual:** The handler only checks `isset($_POST['login'])`. If the submit button is present but `email` or `password` is absent, `trim($_POST['email'])` triggers an "Undefined array key" warning (and `trim(null)` is deprecated on PHP 8.1+). If a field is submitted as an array (`email[]=x`), `trim()` receives an array and raises a `TypeError`, producing a fatal error / 500 instead of a validation message.
- **How to reproduce:**
  1. POST only `login=1&csrf_token=<valid>` (omit `email`/`password`) → PHP warning about undefined key.
  2. POST `login=1&csrf_token=<valid>&email[]=a&password=x` → `TypeError` fatal error.

### ISSUE-8 — Activity log builds SQL by string concatenation (weaker than parameterized)
- **Severity:** minor
- **Where:** `raw_sources/MBUS/MBUS/Includes/activity_log.php:9-18`
- **Expected:** Bound parameters via `mbus_db_prepare` (as `login.php` correctly does for the user lookup).
- **Actual:** Values are escaped with `mbus_db_escape()` (which wraps `PDO::quote()`) and interpolated into the INSERT string. This is not directly injectable today because `PDO::quote()` doubles single quotes and Postgres uses standard-conforming strings, but it is fragile: any future change to the escaping helper, or a value containing a backslash under a non-standard `standard_conforming_strings` setting, becomes injectable. It also violates the codebase's own pattern of using `mbus_db_prepare` elsewhere.
- **How to reproduce:**
  1. Review `Includes/activity_log.php:15-18` — values appear directly in the SQL string.
  2. `log_activity()` is called from `login.php:38` with a fixed description, so the login path is low risk today, but every other caller passes user-controlled strings (e.g. user agent at `activity_log.php:7`).

### ISSUE-9 — No server-side email format validation
- **Severity:** minor
- **Where:** `raw_sources/MBUS/MBUS/login.php:17`
- **Expected:** Server-side validation of the email format (the HTML `type="email"`/`required` attributes at `login.php:119-126` are browser-side only and trivially bypassed).
- **Actual:** Any string is trimmed and sent to the DB. Invalid input produces a "User not found" message rather than a field-level validation error, and the DB is hit with garbage input.
- **How to reproduce:**
  1. POST `email=not-an-email&password=x`.
  2. Observe no validation message; the request goes straight to the database lookup.

### ISSUE-10 — Email lookup is case-sensitive and unnormalised
- **Severity:** minor
- **Where:** `raw_sources/MBUS/MBUS/login.php:21-22` — `SELECT * FROM users WHERE email = ?`
- **Expected:** Lookup on a normalised (lower-cased/trimmed) address, ideally via a case-insensitive unique index.
- **Actual:** The value is trimmed but not lower-cased, so `User@Example.com` and `user@example.com` are different accounts; a user who typed their address with different casing than at registration is rejected with "User not found". (Also note the SQL uses `SELECT *`, pulling every column of `users` into memory when only `user_id`, `full_name`, `user_type`, `password` are needed.)
- **How to reproduce:**
  1. Register `user@example.com`.
  2. Log in with `USER@example.com`.
  3. Observe "User not found".

### ISSUE-11 — CSRF token is not rotated after successful authentication
- **Severity:** minor
- **Where:** `raw_sources/MBUS/MBUS/login.php:31` (`session_regenerate_id(true)`) and `raw_sources/MBUS/MBUS/Includes/csrf.php:13-17`
- **Expected:** The CSRF token is regenerated when the privilege level changes, so a token captured before login cannot be reused after it.
- **Actual:** `session_regenerate_id(true)` keeps the session payload, so `$_SESSION['csrf_token']` survives the login unchanged. The token is also a single per-session token reused across every form in the app and is not invalidated after a successful use, so it remains replayable for its full 1-hour lifetime (`csrf.php:34`, `csrf.php:55`).
- **How to reproduce:**
  1. Load `login.php` and note the `csrf_token` hidden value.
  2. Log in successfully.
  3. Inspect `$_SESSION['csrf_token']` before and after — it is identical.

### ISSUE-12 — Password hash is never re-hashed on successful login
- **Severity:** minor
- **Where:** `raw_sources/MBUS/MBUS/login.php:28`
- **Expected:** `password_needs_update()` check followed by `password_hash()` to transparently upgrade legacy hashes.
- **Actual:** The hash from `users.password` is used as-is. Accounts stored with a weaker/legacy algorithm keep that hash indefinitely, even after the app moves to `password_hash()` defaults.
- **How to reproduce:**
  1. Store a hash produced with a non-default cost or a legacy scheme for a test user.
  2. Log in successfully.
  3. Inspect `users.password` — it is unchanged.

## Summary

- Blocker: 2 (ISSUE-1 session cookie hardening, ISSUE-2 password trimming)
- Major: 5 (ISSUE-3 enumeration, ISSUE-4 no rate limiting, ISSUE-5 session set before role check, ISSUE-6 error disclosure, ISSUE-7 missing field guards)
- Minor: 5 (ISSUE-8 log SQL concatenation, ISSUE-9 no email validation, ISSUE-10 case-sensitive email, ISSUE-11 CSRF token not rotated, ISSUE-12 no hash upgrade)
- Passes: prepared statement on the login lookup, `password_verify`, `hash_equals` CSRF comparison, `session_regenerate_id(true)`, `htmlspecialchars` on rendered errors.

Related: [[wiki/backend-api.md]], [[wiki/log.md]]
