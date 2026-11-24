/* Admin workflows cover both split staff roles and keep the Conductor queue first. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const menu = read('Includes/admin_menu.php');
const conductorApplications = read('Admin/conductor_applications.php');
const driverApplications = read('Admin/operator_applications.php');
const gcash = read('Admin/gcash_approval.php');
const conductorPayments = read('Operator/payments.php');
const dashboard = read('Admin/admin_dashboard.php');
const reports = read('Admin/reports.php');
const notify = read('Includes/notify.php');
const schedules = read('Admin/manage_schedules.php');

assert.match(menu, /href="conductor_applications\.php"/, 'Admin applications should open on Conductors');
assert.match(menu, /href="reports\.php"[^>]*>.*Reports/s, 'Admin profile menu must expose Reports');
assert.match(menu, /conductor_application/, 'Admin application badges should count Conductor applications');
assert.match(conductorApplications, /href="operator_applications\.php"/, 'Drivers must remain accessible from the application tabs');
assert.match(conductorApplications, /class="on" aria-current="page">Conductors/, 'Conductors should be the active first queue');

assert.match(menu, /href="gcash_approval\.php"/, 'Admin account menu must expose GCash account management');
assert.match(notify, /function mbus_notify_schedule_change/, 'schedule changes must have a shared notification helper');
assert.match(notify, /reservation r[\s\S]*operator_bus_assignments[\s\S]*conductor_bus_assignments/, 'schedule changes must notify riders and assigned bus staff');
assert.match(schedules, /mbus_notify_schedule_change\(\$conn\s*,\s*\$before_change\s*,\s*\$after_change/, 'Admin schedule updates must trigger notifications');
for (const link of ['admin_dashboard.php', 'manage_schedules.php', 'manage_buses.php', 'manage_operators.php', 'waiting.php']) {
  assert.match(reports, new RegExp(`href="${link}"[^>]*class="bottom-nav-item"`), `Reports must keep the Admin ${link} navigation button`);
}
assert.match(dashboard, /href="gcash_approval\.php"/, 'Admin dashboard must expose GCash account management directly');
assert.match(gcash, /mbus_gcash_pending\(\$conn\)/, 'Admin GCash page must read pending Conductor changes');
assert.match(gcash, /name="approve"/, 'Admin GCash page must provide approval');
assert.match(gcash, /name="reject"/, 'Admin GCash page must provide rejection');
const driverGcash = read('Operator/gcash_account.php');
assert.match(driverGcash, /mbus_set_setting\(\$conn, 'gcash_pending'/, 'Driver GCash changes must enter the Admin approval queue');
assert.match(driverGcash, /Sent to admin for approval/, 'Driver must be told the account change is pending Admin approval');

assert.match(dashboard, /'Drivers'\s*=>.*LOWER\(user_type\).*driver/s, 'Admin dashboard must count Drivers');
assert.match(dashboard, /'Conductors'\s*=>.*LOWER\(user_type\).*conductor/s, 'Admin dashboard must count Conductors');
assert.match(dashboard, /'Staff'\s*=>\s*0/, 'Admin dashboard must expose a combined staff total');
assert.match(dashboard, /drivers.*conductors/s, 'Admin dashboard must show the role breakdown');

console.log('PASS Admin Conductor-first applications, Driver access, Conductor GCash approval, and split staff dashboard coverage');
