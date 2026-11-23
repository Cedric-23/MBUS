const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const payment = read('Commuter/payment.php');
const mobileCss = read('Assets/css/commuter_mobile.css');
const commuterProfile = read('Commuter/profile.php');
const operatorProfile = read('Operator/profile.php');

const layoutStart = payment.indexOf('<div class="payment-layout">');
const sidebarStart = payment.indexOf('<div class="payment-panel payment-panel-sidebar">');
const mainClose = payment.indexOf('</main>', layoutStart);
assert.ok(layoutStart >= 0 && sidebarStart > layoutStart && mainClose > sidebarStart, 'payment summary sidebar must be inside the payment layout and before main closes');
const layoutMarkup = payment.slice(layoutStart, mainClose);
let divDepth = 0;
for (const match of layoutMarkup.matchAll(/<\/?div\b[^>]*>/gi)) {
  if (match[0].startsWith('</')) divDepth -= 1;
  else divDepth += 1;
  assert.ok(divDepth >= 0, 'payment divs must not close before opening');
}
assert.equal(divDepth, 0, 'payment main/layout/panels must have balanced div structure');
assert.match(mobileCss, /@media\s*\(max-width:\s*767px\)\s*\{\s*\.payment-page \.payment-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s, 'one-column payment layout should be mobile-only');
assert.match(mobileCss, /@media\s*\(min-width:\s*768px\)[\s\S]*?#paymentPageMain\s*\{\s*max-width:\s*1120px\s*!important\s*;[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(280px,\s*350px\)/, 'desktop payment layout should have a wide main and a second summary column');

assert.match(commuterProfile, /profile-avatar[^>]*>[\s\S]*?avatar\.php\?v=/, 'commuter profile header must use the database-backed avatar endpoint');
assert.match(commuterProfile, /data-fallback=/, 'commuter profile header must retain an initial fallback');

assert.match(operatorProfile, /in_array\(strtolower\(\(string\)\(\$_SESSION\['user_type'\] \?\? ''\)\), \['driver', 'conductor'\], true\)/, 'staff profile must stay role-protected');
assert.match(operatorProfile, /csrf_validate_token\(\)/, 'operator profile updates must validate CSRF');
assert.match(operatorProfile, /mbus_check_full_name\(/);
assert.match(operatorProfile, /mbus_check_phone\(/);
assert.match(operatorProfile, /filter_var\(\$formValues\['email'\], FILTER_VALIDATE_EMAIL\)/, 'operator email changes should use the same basic format check as sign-in');
assert.match(operatorProfile, /LOWER\(email\) = LOWER\(\?\)/, 'operator email must be checked for case-insensitive duplicates');
for (const field of ['full_name', 'email', 'phone_number']) assert.match(operatorProfile, new RegExp(`name="${field}"`), `operator profile must edit ${field}`);
const operatorEmailInput = operatorProfile.match(/<input[^>]*id="operatorEmail"[^>]*>/)?.[0] ?? '';
assert.ok(operatorEmailInput, 'operator profile email field must be present');
assert.doesNotMatch(operatorEmailInput, /\b(?:readonly|disabled)\b/, 'operator profile email field must remain editable');
assert.match(operatorProfile, /operator-phone-prefix[^>]*>\+63<\/span>/, 'operator phone input must use the same +63 prefix as sign-up');
assert.match(operatorProfile, /maxlength="14" minlength="10" pattern="9\[0-9\]\{9\}"/, 'operator phone input must accept the same ten-digit national number as sign-up (extra length lets a pasted 09... number be trimmed by script)');
assert.ok(operatorProfile.includes("preg_replace('/^0/', '', (string)$formValues['phone_number'])"), 'stored 09-format phone numbers must display as ten digits after the +63 prefix');
assert.match(operatorProfile, /\$_SESSION\['full_name'\] =/);
assert.match(operatorProfile, /\$_SESSION\['email'\] =/);

for (const file of [
  'Operator/operator_dashboard.php',
  'Operator/manage_trips.php',
  'Operator/passengers.php',
  'Operator/scan.php',
  'Includes/pickup_staff_view.php',
]) {
  const source = read(file);
  assert.match(source, /href="profile\.php"[^>]*role="menuitem"[^>]*>[\s\S]*?My Profile/, `${file} must link to operator profile from the account menu`);
}

console.log('PASS: MBUS-16 desktop payment layout, MBUS-17 avatar parity, and MBUS-19 operator profile edit flow');
