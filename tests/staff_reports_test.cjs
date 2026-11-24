const assert = require('assert'); const fs = require('fs');
for (const f of ['Admin/manage_buses.php','Admin/manage_operators.php']) { const s = fs.readFileSync(f,'utf8'); assert(s.includes('id="opClear"') && s.includes('id="opCount"'), f); }
console.log('ok');
const rp = fs.readFileSync('Operator/reports.php', 'utf8');
assert(/\$where = \[\$assignment\]/.test(rp), 'reports must be limited to the staff member\'s own buses');
assert(/\['gcash'\] = 'GCash payments'/.test(rp) && /isConductor/.test(rp));
for (const f of ['Operator/operator_dashboard.php','Operator/my_bus.php','Operator/manage_trips.php','Operator/passengers.php','Operator/payments.php','Operator/scan.php','Operator/profile.php','Conductor/conductor_dashboard.php'])
  assert(/reports\.php" role="menuitem"/.test(fs.readFileSync(f, 'utf8')), f + ' needs the Analytics menu link');
assert(!/mbus_admin_/.test(rp), 'admin-only helpers must not be used in staff reports');
console.log('reports ok');
