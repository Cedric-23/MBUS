/* Staff navigation keeps Scan centered, gives Riders to Conductors, and retires Live. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const driverExpected = ['Home', 'My Bus'];
const conductorOperatorExpected = ['Home', 'Trips', 'Scan', 'Riders', 'Waiting'];
const conductorHomeExpected = ['Home', 'Trips', 'Scan', 'Riders', 'Waiting'];

function bottomNav(html, page, role) {
    const match = html.match(/<nav class="bottom-nav"[^>]*>([\s\S]*?)<\/nav>/);
    assert.ok(match, `${page} has no bottom navigation`);
    let source = match[1];
    const branch = /<\?php if\(\$isConductor\): \?>([\s\S]*?)<\?php else: \?>([\s\S]*?)<\?php endif; \?>/g;
    source = source.replace(branch, (_all, conductor, operator) => role === 'conductor' ? conductor : operator);
    return [...source.matchAll(/<span>([^<]*)<\/span>/g)].map(m => m[1].trim());
}

assert.deepEqual(bottomNav(read('Operator/operator_dashboard.php'), 'Operator/operator_dashboard.php', 'driver'), driverExpected,
    'Driver dashboard navigation should expose only Driver tools');
for (const page of ['Operator/profile.php']) {
    const html = read(page);
    assert.deepEqual(bottomNav(html, page, 'driver'), driverExpected,
        `${page} Driver navigation should expose only Driver tools`);
    const nav = html.match(/<nav class="bottom-nav"[^>]*>([\s\S]*?)<\/nav>/)[1];
    assert.doesNotMatch(nav, /live_map\.php|<span>Live<\/span>/, `${page} must not include Live`);
}

for (const page of ['Operator/manage_trips.php', 'Operator/scan.php']) {
    assert.deepEqual(bottomNav(read(page), page, 'conductor'), conductorOperatorExpected,
        `${page} Conductor navigation should place Scan in the center and include Riders`);
}
assert.deepEqual(bottomNav(read('Operator/passengers.php'), 'Operator/passengers.php', 'conductor'), conductorOperatorExpected);
assert.deepEqual(bottomNav(read('Conductor/conductor_dashboard.php'), 'Conductor/conductor_dashboard.php', 'conductor'),
    conductorHomeExpected);
assert.deepEqual(bottomNav(read('Operator/payments.php'), 'Operator/payments.php', 'conductor'), conductorHomeExpected);

for (const page of ['Conductor/conductor_dashboard.php', 'Operator/manage_trips.php', 'Operator/scan.php', 'Operator/passengers.php', 'Operator/payments.php']) {
    const html = read(page);
    const primary = html.match(/<nav class="menu-top"[^>]*>([\s\S]*?)<\/nav>/)?.[1] ?? '';
    assert.doesNotMatch(primary, /payments\.php|GCash Payments|<span>Payments<\/span>/,
        `${page} Conductor primary navigation must not include Payments`);
}

const waiting = read('Includes/pickup_staff_view.php');
assert.match(waiting, /\['scan\.php', 'fa-qrcode', 'Scan'\]/);
assert.match(waiting, /\['passengers\.php', 'fa-users', 'Riders'\]/);
assert.doesNotMatch(waiting, /\['my_bus\\.php', 'fa-bus-simple', 'My Bus'\]/);
assert.match(waiting, /<span>Waiting<\/span>/);

const scan = read('Operator/scan.php');
assert.match(scan, /'method' => \$isConductor \?/);
assert.match(scan, /'reference' => \$isConductor \?/);
assert.match(scan, /if\(\$isConductor\): \?><dt>GCash ref/);
assert.ok(read('Operator/payments.php').includes("strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor'"));
assert.ok(read('Operator/payment_receipt.php').includes("strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor'"));
assert.doesNotMatch(read('Conductor/conductor_dashboard.php'), /my_bus\.php|bus_photo\.php/);

const legacy = read('Operator/live_map.php');
assert.match(legacy, /header\('Location: scan\.php', true, 302\)/);
assert.match(legacy, /strtolower\(\(string\)\(\$_SESSION\['user_type'\]/);
assert.doesNotMatch(legacy, /navigator\.geolocation|Live operations/);

console.log('PASS: Driver keeps My Bus; Conductor owns operational navigation; GCash is Conductor-only');
