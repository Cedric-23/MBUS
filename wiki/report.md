# Final Report — MBUS Login Page Security Audit

## Summary
- Ange completed a 12-issue security audit of `raw_sources/MBUS/MBUS/login.php` and its dependencies (2 blockers, 5 major, 5 minor). No code was modified during QA.
- Dennice analyzed the issues and documented 10 fixes in `wiki/fixes.md` (2 HIGH, 3 MEDIUM, 5 LOW), all currently pending implementation.
- The original Netflix-clone plan requirements were not verifiable against MBUS code; the audit was a standalone code-level security review.

## Requirement Status

| Req | Description | Status | Notes |
|-----|-------------|--------|-------|
| R1 | Authenticate user with email + password | **NOT VERIFIABLE** | No MBUS UI/API spec in vault to trace against |
| R2 | CSRF protection on login POST | **PASS** | `csrf_field()` + `csrf_validate_token()` present |
| R3 | Password stored/verified as hash | **PASS** | `password_verify()` used correctly |
| R4 | SQL injection safe on login lookup | **PASS** | Prepared statement via `mbus_db_prepare` |
| R5 | Session fixation defence on login | **PASS** | `session_regenerate_id(true)` called |
| R6 | Generic auth failure message | **FAIL** | Distinct "User not found" / "Incorrect password" |
| R7 | Brute-force / rate limiting on login | **FAIL** | No implementation found |
| R8 | Role-based redirect + session hardening | **FAIL** | Session populated before role validated |
| R9 | Session cookie hardening (HttpOnly/Secure/SameSite) | **FAIL** | Config applied after `session_start()` |
| R10 | DB error messages not leaked to client | **FAIL** | `die('Connection failed: ' . $e->getMessage())` |
| R11 | Activity log write injection-safe | **PASS (with caveat)** | Manual escaping used instead of bound params |

## What Each Member Did

| Member | Task | Deliverable | Outcome |
|--------|------|-------------|---------|
| **Ashuri** | Planned login page check for Netflix clone | `wiki/plan.md` | Plan created; scope shifted to MBUS audit |
| **Cedric** | Researched MBUS login implementation | `raw_sources/login-page-research.md` | Documented auth flow, tech stack, 15+ risks |
| **Fuji** | (Not engaged — no MBUS UI spec in vault) | — | Skipped; Netflix clone UI specs exist in `wiki/ui-components.md` |
| **Ash** | (Not engaged — no MBUS API spec in vault) | — | Skipped; Netflix clone API specs exist in `wiki/backend-api.md` |
| **Ange** | Security audit of MBUS `login.php` | `wiki/qa-report.md` | 12 issues found (2 blocker, 5 major, 5 minor) |
| **Dennice** | Analyzed issues & proposed fixes | `wiki/fixes.md` | 10 fixes documented (2 HIGH, 3 MEDIUM, 5 LOW) — all pending |

## Open Issues

### Blockers (from QA Report)
1. **ISSUE-1** — Session hardening never takes effect (`session_start()` before config)
2. **ISSUE-2** — Passwords trimmed before verification (`trim($_POST['password'])`)

### Major
3. **ISSUE-3** — User enumeration via distinct error messages
4. **ISSUE-4** — No brute-force protection or rate limiting
5. **ISSUE-5** — Session populated before role validated
6. **ISSUE-6** — Database error details leaked to client
7. **ISSUE-7** — Missing `isset` guard on `$_POST['email']`/`$_POST['password']`

### Minor
8. **ISSUE-8** — Activity log uses string concatenation (not prepared statements)
9. **ISSUE-9** — No server-side email format validation
10. **ISSUE-10** — Email lookup is case-sensitive and unnormalized
11. **ISSUE-11** — CSRF token not rotated after successful authentication
12. **ISSUE-12** — Password hash never re-hashed on successful login

### Additional from Dennice (Fixes)
- Hardcoded SMTP credentials in `send_reset_code.php`
- Weak password policy (min 8 chars only)
- Password toggle UX broken on mobile (mouse events vs touch)
- Account lockout mechanism missing
- CSRF single pattern only (no double-submit cookie)
- MySQL→PostgreSQL translation layer technical debt

## How to Run

> **Note:** The MBUS project is a standalone PHP/PostgreSQL application in `raw_sources/MBUS/MBUS/`. It is not the Netflix clone described in `wiki/backend-api.md` and `wiki/ui-components.md`.

1. **Prerequisites**: Docker, PHP 8.x, PostgreSQL (or Supabase), Composer
2. **Configure environment**:
   ```bash
   cd raw_sources/MBUS/MBUS
   cp .env.example .env  # Set SUPABASE_DB_* variables
   ```
3. **Start with Docker**:
   ```bash
   docker build -t mbus .
   docker run -p 8080:80 --env-file .env mbus
   ```
4. **Access**: `http://localhost:8080/login.php`
5. **Run tests**: No automated test suite exists. Manual verification per QA test cases in `wiki/qa-report.md`.

## Next Steps

1. **Prioritize fixes** (Ashuri → Ash):
   - [ ] **HIGH**: Fix ISSUE-3 (generic error messages) + ISSUE-4 (rate limiting)
   - [ ] **HIGH**: Fix ISSUE-1 (session hardening order) + ISSUE-2 (password trim)
   - [ ] **MEDIUM**: Fix ISSUE-5 (role check before session), ISSUE-6 (error leakage), ISSUE-7 (input guards)
   - [ ] **MEDIUM**: Convert activity log to prepared statements (ISSUE-8)
   - [ ] **LOW**: Email validation, case normalization, CSRF rotation, hash upgrade, password toggle UX, secure cookies, account lockout

2. **Assign to Ash (Backend Developer)** to implement fixes in `raw_sources/MBUS/MBUS/`

3. **Re-audit** after implementation (Ange) to verify all 12 issues resolved

4. **Document MBUS-specific specs** in wiki if this project continues (Fuji for UI, Ash for API)

---
*Report generated by Ashuri (Project Lead) — 2026-11-11*