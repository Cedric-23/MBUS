# MBUS Driver / Operator Portal

This directory is the separate staff-facing website for MBUS driver, operator, and conductor login and registration. It uses the same Supabase PostgreSQL database and shared validation/application tables as the commuter website. Approved accounts still route to the existing Operator or Conductor dashboards.

## Render service

Create a second Render Web Service connected to `Cedric-23/MBUS` and set:

- **Dockerfile Path:** `Dockerfile.staff`
- **Health Check Path:** `/health.php` (or `/login.php` if health checks are not configured)
- **Environment:** copy the existing MBUS database, mail, and application environment variables
- **Branch:** `ash` while testing; use the production branch when ready

The Dockerfile keeps the whole repository available to PHP so the portal can reuse the existing database bootstrap, CSRF protection, validation, mailer, and application-review logic, but Apache exposes only `StaffPortal/` as the public document root.

`StaffPortal/login.php` and `StaffPortal/register.php` mark requests as staff-facing before delegating to the shared handlers. The staff Apache configuration explicitly aliases `/Operator/`, `/Conductor/`, `/Assets/`, the two staff APIs (`/api/live_tracking.php` and `/api/gcash_sms_webhook.php`), and the dashboard dependencies (`/gps.php`, `/logout.php`, `/avatar.php`, and `/id_image.php`). Root application files remain unavailable directly except for the specific legal/profile/GPS/logout endpoints required by staff pages. This lets approved staff reach the existing dashboards without exposing commuter or Admin directories on the staff host.

## Portal behavior

- `/login.php` accepts approved Operator and Conductor accounts and routes them to their existing dashboards.
- `/register.php?type=driver` opens the validated Driver/Operator application wizard.
- `/register.php?type=conductor` opens the Conductor application form.
- Registration does not create staff access immediately; the existing Admin approval flow remains required.
- The commuter-facing `/login.php` no longer displays Driver/Operator or Conductor registration links, but it continues to accept approved staff logins.
