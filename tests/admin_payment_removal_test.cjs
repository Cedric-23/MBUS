/* Admin manual payment review is removed without deleting payment data or touching commuter checkout. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const menu = read('Includes/admin_menu.php');
assert.doesNotMatch(menu, /GCash payments|payment_verifications|paymongo_checkout/i,
    'shared Admin account menu must not expose or query the retired payment-verification console');
assert.match(menu, /gcash_approval\.php/, 'Admin menu links the GCash account approval page');
assert.match(menu, /verifications\.php/, 'ID approvals remain in the Admin account menu');
assert.match(menu, /operator_applications\.php/, 'operator applications remain in the Admin account menu');

for (const file of ['Admin/payment_verifications.php', 'Admin/payment_receipt.php']) {
    const page = read(file);
    assert.match(page, /session_start\(\)/, `${file} must inspect the existing session`);
    assert.match(page, /\$_SESSION\['user_type'\].*admin/, `${file} must keep the Admin role guard`);
    assert.match(page, /header\('Location: admin_dashboard\.php'\)/,
        `${file} must redirect old Admin URLs to the dashboard`);
    assert.doesNotMatch(page, /db_connect|SELECT\s|UPDATE\s|INSERT\s/i,
        `${file} must not expose payment review or receipt data`);
}

const commuterPayment = read('Commuter/payment.php');
assert.match(commuterPayment, /paymongo_create_gcash_checkout/,
    'commuter checkout is intentionally preserved while MBUS chooses its payment model');
assert.match(commuterPayment, /start_gcash/,
    'the existing commuter fallback is intentionally unchanged in this Admin-only cleanup');

console.log('PASS Admin payment-review console and direct review URLs are disabled; commuter payment flow and data remain untouched');
