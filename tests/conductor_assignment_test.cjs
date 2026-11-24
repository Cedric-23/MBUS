const fs = require('fs');
const assert = require('assert');

const admin = fs.readFileSync('Admin/manage_buses.php', 'utf8');
const trips = fs.readFileSync('Operator/manage_trips.php', 'utf8');
const notify = fs.readFileSync('Assets/js/notifications.js', 'utf8');

assert(admin.includes("require_once \"../Includes/notify.php\""), 'admin bus assignment must load notifications');
assert(admin.includes('SELECT conductor_id FROM conductor_bus_assignments'), 'assignment must read the actual previous conductor');
assert(admin.includes('mbus_notify($conn, $conductor_id'), 'new conductor must be notified');
assert(admin.includes('mbus_notify($conn, $actual_conductor_id'), 'replaced conductor must be notified');
assert(admin.includes('mbus_notify($conn, $actual_operator_id'), 'assigned driver must be notified of conductor changes');
assert(admin.includes('if($conductor_id !== $actual_conductor_id)'), 'unchanged assignments must not notify');
assert(/user_type.*conductor/i.test(trips), 'Trips page must remain conductor-only');
assert(trips.includes("$staffRole = 'conductor'") && trips.includes('mbus_staff_assignment_condition($staffRole'), 'trip status updates must use conductor assignment authorization');
assert(notify.includes('assigned as conductor'), 'assignment notification messages must have a redirect rule');
assert(notify.includes("../Operator/manage_trips.php"), 'conductor assignment notifications must redirect to Trips');
console.log('conductor_assignment_test: PASS');
