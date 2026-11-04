# MBUS UI/UX Redesign TODO

## Completed

- [x] Audit Commuter, Admin, and Operator navigation and responsive behavior.
- [x] Add an upper-right account/profile menu to each role dashboard.
- [x] Add visible account identity, role label, profile access where available, notifications where available, and logout.
- [x] Add keyboard, Escape, outside-click, and ARIA behavior for the profile menu.
- [x] Restore the original brighter MBUS blue navigation treatment instead of the darker navy override.
- [x] Load shared header/navigation styling consistently across role pages.
- [x] Remove obsolete hamburger/overlay controls from Admin and Operator home dashboards.
- [x] Remove stale notification-dropdown JavaScript that referenced deleted dashboard markup.
- [x] Add the missing Operator notifications page and correct its role-specific links/styles.
- [x] Make Operator active-trip and passenger-status dashboard actions functional.
- [x] Correct favicon paths.
- [x] Fix malformed CSS brace structure in shared responsive and Operator trip styles.
- [x] Replace invalid Admin analytics links with valid destinations.

## Validation

- [x] `git diff --check`
- [x] Shared JavaScript syntax check with Node.
- [x] CSS brace-balance checks for shared and role navigation styles.
- [x] Dashboard script-tag and profile-menu structure checks.
- [x] Role navigation target and Operator notification-page existence checks.
- [ ] Browser session test with authenticated Admin, Operator, and Commuter accounts at 320px–1440px. PHP is not installed in the sandbox, so this requires the project runtime or deployed preview.
