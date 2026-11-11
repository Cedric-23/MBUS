# Login Page Research - MBUS Project

## Overview
This document summarizes the research findings for the existing login page implementation in the MBUS (Morong - SBMA Bus Reservation) system. The research covers the current auth flow, tech stack, data entities, and key risks.

---

## Confirmed Requirements

### Core Auth Features
1. **User Login** - Email/password authentication with role-based redirect
2. **User Registration** - Full name, email, phone (Philippines format 09XXXXXXXXX), password
3. **Forgot Password Flow** - 4-step process:
   - Enter email → Send 6-digit code via email → Verify code → Set new password
4. **Session Management** - PHP sessions with regeneration on login
5. **CSRF Protection** - Token-based validation with 1-hour expiry
6. **Activity Logging** - Audit trail for login, registration, password changes

### Role-Based Redirects
| User Type | Redirect Target |
|-----------|-----------------|
| `commuter` | `Commuter/commuter_dashboard.php` |
| `operator` | `Operator/operator_dashboard.php` |
| `admin` | `Admin/admin_dashboard.php` |

### Security Requirements
- Prepared statements for all DB queries (SQL injection prevention)
- Password hashing via `password_hash()` / `password_verify()` (bcrypt default)
- CSRF tokens on all forms
- Session fixation prevention via `session_regenerate_id(true)`
- Input sanitization with `htmlspecialchars()` on output
- Rate limiting: Not currently implemented (identified risk)

---

## Data Entities

### Users Table (`users`)
```sql
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone_number VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    user_type VARCHAR(50) NOT NULL
);
```

**Fields used in auth:**
- `user_id` - Primary key, stored in session
- `email` - Unique, used for login lookup
- `password` - Bcrypt hash
- `user_type` - Enum-like: 'Commuter', 'Operator', 'Admin' (stored as-is, lowercased in session)
- `reset_code` - 6-digit code for password reset (added via migration)
- `reset_expiry` - Timestamp for code expiry (15 min)

### Activity Logs Table (`activity_logs`)
```sql
CREATE TABLE activity_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Logged Actions (from `activity_log.php`):**
- `ACTION_LOGIN` - User logged in
- `ACTION_LOGOUT` - User logged out
- `ACTION_REGISTER` - New registration
- `ACTION_PASSWORD_CHANGE` - Password reset
- Plus 20+ other domain-specific actions

---

## Current Tech Stack

### Backend
| Component | Technology | Version/Details |
|-----------|------------|-----------------|
| Language | PHP | 8.x (implied by syntax) |
| Database | PostgreSQL (Supabase) | Hosted on `aws-1-ap-south-1.pooler.supabase.com` |
| DB Driver | PDO (pgsql) | With MySQL→PostgreSQL SQL translation layer |
| Email | PHPMailer | SMTP (Gmail default) |
| Session | PHP Native | File-based (default) |
| CSRF | Custom | Session-based tokens, 1hr TTL |

### Frontend
| Component | Technology | Notes |
|-----------|------------|-------|
| CSS Framework | Custom CSS Variables + Responsive Framework | Uses `var(--color-*)`, `var(--space-*)`, `var(--radius-*)` |
| Fonts | Google Fonts (Inter) | Weights 300-700 |
| Icons | Font Awesome 6.5.0 | CDN |
| JS | Vanilla ES6 | Inline in PHP files |

### Infrastructure
- **Container**: Docker (Dockerfile present)
- **Web Server**: Apache (`.htaccess` present)
- **Env Config**: `.env` file loaded via custom `load_env.php`

---

## Auth Flow Diagrams

### Login Flow
```
1. User accesses login.php
2. GET → Returns login form with CSRF token
3. User submits email/password
4. POST → Validate CSRF token
5. Query users table by email (prepared statement)
6. If found → password_verify(password, hash)
7. If valid → session_regenerate_id(), set session vars
8. Log activity (ACTION_LOGIN)
9. Redirect based on user_type
10. If invalid → Show error message
```

### Forgot Password Flow
```
1. forgot_password.php → User enters email
2. POST → Validate email exists in users table
3. Generate 6-digit code, set expiry = NOW + 15 min
4. Store code/expiry in users table
5. Store email/code in session
6. Redirect to send_reset_code.php
7. send_reset_code.php → PHPMailer sends email via SMTP
8. Redirect to verify_code.php after 2s
9. verify_code.php → User enters 6-digit code
10. POST → Validate against DB (code + expiry)
11. If valid → Set $_SESSION['verified'] = true
12. Redirect to new_password.php
13. new_password.php → User enters new password 2x
14. POST → Validate match + length >= 8
15. Hash password, update users table, clear reset_code/expiry
16. session_destroy()
17. Redirect to login.php after 2s
```

---

## Key Risks & Issues

### High Severity
| Risk | Impact | Location |
|------|--------|----------|
| **No Rate Limiting** | Brute force attacks on login/forgot password | `login.php`, `forgot_password.php` |
| **Email Enumeration** | "User not found" vs "Incorrect password" reveals valid emails | `login.php` lines 60-61 |
| **No Password Policy** | Only min 8 chars, no complexity requirements | `register.php`, `new_password.php` |
| **Session Storage** | Default file-based sessions don't scale | `config/db_connect.php` (no session handler) |

### Medium Severity
| Risk | Impact | Location |
|------|--------|----------|
| **Hardcoded SMTP Credentials** | `send_reset_code.php` has placeholder credentials | Lines 17-22, 24 |
| **No HTTPS Enforcement** | No HSTS, secure cookie flags | All PHP files |
| **CSRF Token in Session Only** | No double-submit cookie pattern | `Includes/csrf.php` |
| **Error Messages Leak Info** | "User not found" / "Email not found" | `login.php`, `forgot_password.php` |
| **No Multi-Factor Auth** | Single factor only | Entire auth system |

### Low Severity
| Risk | Impact | Location |
|------|--------|----------|
| **Inline JS/CSS** | Harder to maintain, CSP issues | All auth PHP files |
| **No Remember Me** | UX friction for returning users | `login.php` |
| **Phone Format Rigid** | Only PH format (09XXXXXXXXX) | `register.php` line 23 |
| **No Account Lockout** | After failed attempts | Not implemented |

### Architecture Concerns
1. **MySQL→PostgreSQL Translation Layer** (`mysqli_compat.php`) - Technical debt, potential bugs
2. **Mixed Concerns** - PHP logic + HTML + JS + CSS in single files
3. **No API Layer** - Traditional server-rendered, not SPA-ready
4. **Supabase Direct Connection** - No connection pooling (uses PgBouncer via pooler)

---

## Files Analyzed

| File | Purpose |
|------|---------|
| `login.php` | Main login page + handler |
| `login.css` | Login page styles (mobile-first, responsive) |
| `register.php` | Registration page + handler |
| `forgot_password.php` | Step 1: Enter email for reset |
| `send_reset_code.php` | Step 2: Send email via PHPMailer |
| `verify_code.php` | Step 3: Verify 6-digit code |
| `new_password.php` | Step 4: Set new password |
| `config/db_connect.php` | Supabase PostgreSQL connection |
| `config/mysqli_compat.php` | MySQL→PG SQL translation |
| `config/load_env.php` | .env file loader |
| `Includes/csrf.php` | CSRF token generation/validation |
| `Includes/activity_log.php` | Audit logging helper |
| `supabase/01_schema.sql` | Core schema |
| `supabase/05_add_activity_logs.sql` | Activity logs table |

---

## Recommendations for Fuji (Frontend) & Ash (Backend)

### For Fuji (UI Components)
- Document current form structure in `wiki/ui-components.md`
- Note: Labels hidden via CSS (placeholder-only), consider accessibility impact
- Password toggle uses `onmousedown/onmouseup/onmouseleave` - consider `click` + state
- Loading state on submit button (spinner + visually-hidden text)
- Responsive breakpoints: 479px, 480px, 768px, landscape mobile
- High contrast & reduced motion media queries present

### For Ash (Backend API)
- Document current endpoints in `wiki/backend-api.md`
- Current "API" is form POST to same PHP file
- Consider extracting to `/api/auth/login`, `/api/auth/register`, etc.
- Supabase Auth integration would replace custom auth
- Session handling needs upgrade for distributed deployment

---

## Handoff
Research complete. Ready for Fuji (UI specs) and Ash (API specs) to work in parallel.

---
**Researcher:** Cedric
**Date:** 2026-11-11
**Deliverable:** `raw_sources/login-page-research.md`