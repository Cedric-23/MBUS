const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const adminNotificationPages = fs.readdirSync(path.join(root, 'Admin'))
  .filter(file => file.endsWith('.php'))
  .filter(file => read(`Admin/${file}`).includes('Assets/js/notifications.js'));
for (const file of adminNotificationPages) {
  const html = read(`Admin/${file}`);
  assert.match(html, /meta name="csrf-token"/, `Admin/${file} needs a CSRF token for Clear all`);
}
assert.doesNotMatch(read('Admin/manage_routes.php'), /Existing routes|fare editing only|Route details fixed/);
assert.doesNotMatch(read('Admin/manage_schedules.php'), /Past departure dates are not accepted/);
assert.doesNotMatch(read('Operator/my_bus.php'), /Changing seats updates the bus's seat count/);
console.log('admin_notification_and_copy_test: PASS');
