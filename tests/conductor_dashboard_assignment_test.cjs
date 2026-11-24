const fs = require('fs');
const assert = require('assert');
const dashboard = fs.readFileSync('Conductor/conductor_dashboard.php', 'utf8');

assert(dashboard.includes('SELECT b.bus_number'), 'conductor dashboard must load assigned bus labels');
assert(dashboard.includes('Assigned bus'), 'dashboard must show assigned bus information');
assert(dashboard.includes("$showingUpcoming = false"), 'dashboard must distinguish today trips from upcoming trips');
assert(dashboard.includes('s.departure_time >= ?'), 'dashboard must query future assigned trips');
assert(dashboard.includes('Upcoming trips'), 'dashboard must label future assigned trips');
console.log('conductor_dashboard_assignment_test: PASS');
