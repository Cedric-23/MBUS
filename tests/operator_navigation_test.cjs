/* Operator bottom navigation restores Scan in the center and retires Live. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const expected = ['Home', 'Trips', 'Scan', 'Riders', 'Waiting'];

const pages = [
    'Operator/operator_dashboard.php',
    'Operator/manage_trips.php',
    'Operator/scan.php',
    'Operator/passengers.php',
    'Operator/notifications.php',
    'Operator/profile.php',
];
for (const page of pages) {
    const html = read(page);
    const match = html.match(/<nav class="bottom-nav"[^>]*>([\s\S]*?)<\/nav>/);
    assert.ok(match, `${page} has no operator bottom navigation`);
    const labels = [...match[1].matchAll(/<span>([^<]*)<\/span>/g)].map(m => m[1].trim());
    assert.deepEqual(labels, expected, `${page} navigation order should keep Scan centered`);
    assert.doesNotMatch(match[1], /live_map\.php|<span>Live<\/span>/, `${page} must not include Live`);
}

const waiting = read('Includes/pickup_staff_view.php');
assert.match(waiting, /\['scan\.php', 'fa-qrcode', 'Scan'\]/);
assert.match(waiting, /\['passengers\.php', 'fa-users', 'Riders'\]/);
assert.match(waiting, /<span>Waiting<\/span>/);

const legacy = read('Operator/live_map.php');
assert.match(legacy, /header\('Location: scan\.php', true, 302\)/);
assert.match(legacy, /strtolower\(\(string\)\(\$_SESSION\['user_type'\]/);
assert.doesNotMatch(legacy, /navigator\.geolocation|Live operations/);

console.log('PASS operator navigation is Home / Trips / Scan / Riders / Waiting; legacy Live redirects to Scan');
