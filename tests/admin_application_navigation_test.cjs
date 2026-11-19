/* Regression coverage for Admin operator and conductor application navigation/UI. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const operator = read('Admin/operator_applications.php');
const conductor = read('Admin/conductor_applications.php');
const styles = read('Assets/css/admin/admin_application_review.css');
for (const [name, page, alternate] of [
  ['operator', operator, 'conductor_applications.php'],
  ['conductor/driver', conductor, 'operator_applications.php'],
]) {
  assert.match(page, /class="ar-page-nav"/, `${name} review needs a dedicated navigation row`);
  assert.match(page, /href="manage_operators\.php"/, `${name} review needs a return link to Manage Operators`);
  assert.match(page, /Back to Manage Operators/, `${name} review needs a visible, descriptive back label`);
  assert.ok(page.includes(alternate), `${name} review should link to the other application queue`);
  assert.match(page, /admin_application_review\.css/, `${name} review must load the shared responsive styles`);
}
assert.match(conductor, /class="has-sidebar"/, 'driver/conductor review should use the normal Admin navigation shell');
assert.match(conductor, /Filter applications by status/, 'driver/conductor queue should support status filters');
assert.match(conductor, /class="ca-card"/, 'driver/conductor applications should render as responsive review cards');
assert.match(conductor, /class="ca-reject"/, 'rejection reason controls should be grouped and usable');
assert.match(styles, /@media \(max-width: 520px\)/, 'application navigation and review cards need phone layouts');
assert.match(styles, /:focus-visible/, 'navigation and form controls must have visible keyboard focus');

console.log('PASS Admin operator and driver application back navigation, application switching, and responsive review UI');
