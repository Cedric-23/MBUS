const assert = require('assert'); const fs = require('fs');
const read = f => fs.readFileSync(f, 'utf8');
for (const f of ['Admin/manage_buses.php', 'Admin/manage_operators.php']) { const s = read(f); assert(s.includes('id="opClear"') && s.includes('id="opCount"'), f); }
const rp = read('Operator/reports.php');
assert(/\$where = \[\$assignment\]/.test(rp), 'analytics must be limited to the staff member\'s own buses');
assert(/\$reports\['gcash'\] = 'GCash payments'/.test(rp) && /isConductor/.test(rp));
assert(!/mbus_admin_/.test(rp), 'admin-only helpers must not be used in staff analytics');
const nav = read('Includes/staff_nav.php');
assert(/reports\.php', 'fa-chart-line', 'Analytics'/.test(nav), 'every driver and conductor menu needs the Analytics link');
const gs = read('Operator/gcash_account.php');
const pay = read('Operator/payments.php');
assert(/'driver'/.test(gs) && /gcash_pending/.test(gs), 'GCash account setup belongs to the driver');
assert(!/save_settings|gcash_qr/.test(pay) && /'conductor'/.test(pay), 'payment review stays with the conductor');
console.log('staff analytics ok');
for (const f of ['Operator/operator_dashboard.php', 'Operator/my_bus.php', 'Operator/profile.php', 'Operator/reports.php', 'Operator/passengers.php', 'Includes/pickup_staff_view.php'])
  assert(/mbus_staff_profile_links\(/.test(read(f)) && /mbus_staff_bottom_nav\(/.test(read(f)), f + ' must use the shared staff menu so every driver page shows GCash Account, Scan and Riders');
assert(/'waiting'\s*=> \['waiting\.php'/.test(nav), 'shared nav must include Waiting');
const adminGc = read('Admin/gcash_approval.php');
assert(!/sms|webhook|auto-confirm/i.test(adminGc), 'admin GCash page must not show the SMS auto-confirm feature');
assert(/http_response_code\(410\)/.test(read('api/gcash_sms_webhook.php')), 'SMS webhook must stay switched off');
assert(!/Commuters pay into this account/.test(gs), 'driver GCash page must not show the extra note');
const wjs = read('Assets/js/staff_waiting.js');
assert(/tripRoutes\[trip\.schedule_id\]/.test(wjs) && /forEach\(ensureTripRoute\)/.test(wjs), 'Waiting map must load every trip route so drop-off pins show without picking a bus');
