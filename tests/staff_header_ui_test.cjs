/* Regression coverage for consistent staff header controls and compact Admin schedules. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const driver = read('Operator/operator_dashboard.php');
const conductor = read('Conductor/conductor_dashboard.php');
const schedule = read('Admin/manage_schedules.php');
const scheduleCss = read('Assets/css/admin/admin_ui.css');
const staffCss = read('Assets/css/staff_modern.css');
const notifications = read('Assets/js/notifications.js');
const endpoint = read('Operator/notifications.php');

for (const [name, page, endpointPath] of [
  ['Driver', driver, 'notifications.js'],
  ['Conductor', conductor, 'notifications.js'],
]) {
  assert.match(page, /class="notif-btn"/, `${name} dashboard needs a commuter-style bell button`);
  assert.match(page, /class="profile-trigger"/, `${name} dashboard needs the commuter-style profile control`);
  assert.match(page, new RegExp(endpointPath.replace('.', '\\.')), `${name} dashboard needs the notifications script`);
  assert.match(page, /meta name="csrf-token"/, `${name} dashboard needs a CSRF token for marking notifications read`);
}
assert.doesNotMatch(driver, /Open Waiting/, 'Driver dashboard should not show the Open Waiting callout');
assert.match(driver, /href="waiting\.php"|mbus_staff_bottom_nav\(/, 'Driver Waiting remains accessible from navigation');
assert.match(conductor, /MBUS_NOTIFICATIONS_ENDPOINT/, 'Conductor should use the Operator-relative notification endpoint');
assert.match(endpoint, /driver.*operator.*conductor|operator.*conductor.*driver/s, 'staff notification endpoint should allow split staff roles');
assert.match(notifications, /window\.MBUS_NOTIFICATIONS_ENDPOINT/, 'notification script should support a page-specific endpoint');
assert.match(schedule, /<span>Route<\/span><span>Seats<\/span>/, 'Admin schedule list should omit the redundant Bus column');
assert.doesNotMatch(schedule, /<div class="ad-c bus">/, 'Admin schedule rows should omit the redundant Bus cell');
assert.match(schedule, /name="bus_id"/, 'Admin schedule form must still assign a bus when creating or editing');
assert.match(scheduleCss, /grid-template-columns:130px minmax\(0,1\.8fr\) 150px 110px 84px/, 'schedule grid should match the reduced column count');
assert.match(staffCss, /menu-top a\{box-sizing:border-box;min-height:48px;height:48px/, 'staff sidebar nav items should have a shared height');
assert.match(staffCss, /bottom-nav-item\{min-height:52px;height:52px/, 'staff bottom nav items should have a shared height');

console.log('PASS staff commuter-style header icons, Driver waiting-card removal, consistent nav sizing, and compact Admin schedules');
