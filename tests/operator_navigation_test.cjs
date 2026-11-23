/* Staff navigation keeps Scan centered, separates Conductor payments from Driver/Operator tools, and retires Live. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const operatorExpected = ['Home', 'Trips', 'Scan', 'Riders', 'Waiting'];
const conductorOperatorExpected = ['Home', 'Trips', 'Scan', 'Payments', 'Waiting'];
const conductorHomeExpected = ['Home', 'Trips', 'Scan', 'Waiting', 'Payments'];

function bottomNav(html, page, role) {
    const match = html.match(/<nav class="bottom-nav"[^>]*>([\s\S]*?)<\/nav>/);
    assert.ok(match, `${page} has no bottom navigation`);
    let source = match[1];
    const branch = /<\?php if\(\$isConductor\): \?>([\s\S]*?)<\?php else: \?>([\s\S]*?)<\?php endif; \?>/g;
    source = source.replace(branch, (_all, conductor, operator) => role === 'conductor' ? conductor : operator);
    return [...source.matchAll(/<span>([^<]*)<\/span>/g)].map(m => m[1].trim());
}

const operatorPages = [
    'Operator/operator_dashboard.php',
    'Operator/manage_trips.php',
    'Operator/scan.php',
    'Operator/passengers.php',
    'Operator/profile.php',
];
for (const page of operatorPages) {
    const html = read(page);
    assert.deepEqual(bottomNav(html, page, 'operator'), operatorExpected,
        `${page} Operator navigation order should keep Scan centered`);
    const nav = html.match(/<nav class="bottom-nav"[^>]*>([\s\S]*?)<\/nav>/)[1];
    assert.doesNotMatch(nav, /live_map\.php|<span>Live<\/span>/, `${page} must not include Live`);
}

for (const page of ['Operator/manage_trips.php', 'Operator/scan.php']) {
    assert.deepEqual(bottomNav(read(page), page, 'conductor'), conductorOperatorExpected,
        `${page} Conductor navigation should place Scan in the center and include Payments`);
}
assert.deepEqual(bottomNav(read('Conductor/conductor_dashboard.php'), 'Conductor/conductor_dashboard.php', 'conductor'),
    conductorHomeExpected);
assert.deepEqual(bottomNav(read('Operator/payments.php'), 'Operator/payments.php', 'conductor'), conductorHomeExpected);

const waiting = read('Includes/pickup_staff_view.php');
assert.match(waiting, /\['scan\.php', 'fa-qrcode', 'Scan'\]/);
assert.match(waiting, /\['passengers\.php', 'fa-users', 'Riders'\]/);
assert.match(waiting, /\['payments\.php', 'fa-wallet', 'Payments'\]/);
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

console.log('PASS: role-specific staff navigation keeps Scan centered; GCash is Conductor-only; Conductor has no bus-customization links');
