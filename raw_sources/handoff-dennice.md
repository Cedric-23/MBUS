# Handoff to Dennice — from Ange's QA Report

Source: [[../wiki/qa-report]] (MBUS `login.php` security audit, notes-only — no code changed).

## Scope
`raw_sources/MBUS/MBUS/login.php` + includes: `config/db_connect.php`, `config/app.php`, `config/load_env.php`, `config/mysqli_compat.php`, `Includes/activity_log.php`, `Includes/csrf.php`.

## Bugs to fix (by severity)

### Blocker
1. **ISSUE-1** — `login.php:3` starts the session *before* `mbus_bootstrap()` runs, so `session.cookie_httponly`, `use_strict_mode`, `SameSite`, `Secure` ini settings never apply. Fix: bootstrap/config include must come before `session_start()`.
2. **ISSUE-2** — `login.php:18` `trim($_POST['password'])` alters the password before `password_verify`; passwords with leading/trailing spaces become unmatchable. Fix: remove the trim.

### Major
3. **ISSUE-3** — Distinct "User not found" vs "Incorrect password" messages (login.php:57,61) allow user enumeration. Fix: single generic message.
4. **ISSUE-4** — No brute-force/rate limiting anywhere. Fix: per-IP/per-account throttle or lockout.
5. **ISSUE-5** — `$_SESSION` is populated (login.php:33-35) *before* the `user_type` role check (login.php:40-54); invalid role leaves an authenticated session alive. Fix: validate role first, write session after.
6. **ISSUE-6** — `config/db_connect.php:28-31` `die('Connection failed: ' . $e->getMessage())` leaks DB details to the client (plus hardcoded fallback creds at lines 7-11). Fix: generic error + `error_log`.
7. **ISSUE-7** — No `isset` guard on `$_POST['email']`/`password` (login.php:17-18) → warnings, or `TypeError` fatal (500) on array input. Fix: validate presence/type first.

### Minor
8. **ISSUE-8** — `Includes/activity_log.php:9-18` builds SQL by concatenation instead of `mbus_db_prepare`.
9. **ISSUE-9** — No server-side email format validation (login.php:17).
10. **ISSUE-10** — Email lookup is case-sensitive (login.php:21-22); normalise to lowercase, avoid `SELECT *`.
11. **ISSUE-11** — CSRF token not rotated after successful login (login.php:31; `csrf.php` single reusable token, 1h lifetime).
12. **ISSUE-12** — No `password_needs_update()` re-hash on successful login (login.php:28).

## Passing (do not break)
Prepared statement on login lookup, `password_verify`, `hash_equals` CSRF compare, `session_regenerate_id(true)`, `htmlspecialchars` on rendered errors.

Related: [[../wiki/report]], [[../wiki/fixes]], [[../wiki/log]]
