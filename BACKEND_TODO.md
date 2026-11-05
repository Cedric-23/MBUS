# MBUS Backend Stabilization TODO

This checklist tracks the backend deep-dive so fixes are verified instead of guessed.

## Phase 1 — Audit

- [x] Map application entry points, session/authentication checks, role gates, and logout behavior.
- [x] Audit database connection, query helpers, schema assumptions, and error handling.
- [x] Audit Commuter flows: schedules, reservation creation, seat availability, cancellation, payment, tickets, history, and notifications.
- [x] Audit Operator flows: assigned trips, passenger lists, status updates, boarding, filters, and notifications.
- [x] Audit Admin flows: buses, routes, schedules, operators, verification, applications, analytics, and notifications.
- [x] Identify dead routes, missing files, inconsistent table/column names, unsafe input handling, and broken redirects.

## Phase 2 — Implementation

- [x] Fix the canonical `schedule`/operator-assignment query mismatch in Operator trip and passenger workflows.
- [x] Fix notification timestamp queries to use the schema's `notification_date` column.
- [x] Scope single-notification mutations to the logged-in user.
- [x] Remove the undefined `mbus_db_execute()` calls from notification APIs and return database failure responses.
- [x] Secure commuter history deletion with session authentication, ownership, and historical-status checks.
- [x] Correct Admin analytics payment status and PostgreSQL month-boundary queries.
- [x] Correct Commuter reservation capacity math to use `buses.capacity` and the aggregate reserved count.
- [x] Remove hardcoded 28-seat rendering and reject invalid/out-of-capacity seat selections server-side.
- [x] Make the notification-read API use prepared ownership-scoped updates and controlled database errors.
- [x] Make notification retrieval and page actions fail safely when database operations fail.
- [x] Make reservation expiry intervals PostgreSQL-compatible and preserve the reserved-seat list for rendering.
- [x] Reject malformed seat tokens and make reservation inserts fail safely instead of reporting false success.
- [x] Make multi-seat reservation creation atomic and add database uniqueness constraints for active seats.
- [ ] Complete payment integrity, including server-side fare validation and payment-reference/idempotency rules.
- [ ] Complete shared role guards, API CSRF coverage, secure uploads, and reset-token hardening.

## Phase 3 — Review

- [x] Run a review agent over the changed backend files without modifications.
- [x] Reconcile every review finding before commit.
- [x] Verify no frontend-only files changed unintentionally.

## Phase 4 — Test

- [ ] Run PHP syntax checks if PHP is available. *(Not available in this sandbox: `php` is not installed.)*
- [x] Run static route/link and include checks.
- [x] Run JavaScript syntax checks for touched scripts where applicable.
- [ ] Run repository-provided tests or build checks if available. *(No repository test suite was present.)*
- [ ] Run focused database/query tests where the environment supports them. *(No PostgreSQL client/server is available in this sandbox.)*
- [x] Run `git diff --check` and inspect the final diff.
- [x] Record sandbox limitations, such as unavailable PHP/database services, explicitly.

## Phase 5 — Delivery

- [ ] Commit with a focused message.
- [ ] Push to branch `ash`.
- [ ] Verify the remote branch hash and clean working tree.
