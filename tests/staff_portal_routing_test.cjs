/* Regression checks for shared MBUS authentication and the separate staff host.
 * Run: node tests/staff_portal_routing_test.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const login = read('login.php');
const registration = read('register.php');
const dockerfile = read('Dockerfile.staff');
const apache = read('docker/staff-portal.conf');
const staffLogin = read('StaffPortal/login.php');
const staffRegistration = read('StaffPortal/register.php');
const staffHealth = read('StaffPortal/health.php');

assert.match(dockerfile, /COPY \. \/var\/www\/html\//);
assert.match(dockerfile, /docker\/staff-portal\.conf/);
assert.match(apache, /DocumentRoot \/var\/www\/html\/StaffPortal/);
assert.match(staffLogin, /define\('MBUS_STAFF_PORTAL', true\)/);
assert.match(staffRegistration, /define\('MBUS_STAFF_PORTAL', true\)/);
assert.match(staffRegistration, /if \(!isset\(\$_GET\['type'\]\).*?\$_GET\['type'\] = 'conductor';/s);
assert.match(staffHealth, /http_response_code\(200\)/);

const aliases = [...apache.matchAll(/^\s*Alias\s+(\S+)\s+(\S+)\s*$/gm)];
assert.ok(aliases.length >= 11, `expected explicit dashboard and dependency aliases, found ${aliases.length}`);
for (const [, route, target] of aliases) {
    const relativeTarget = target.replace(/^\/var\/www\/html\//, '');
    assert.ok(fs.existsSync(path.join(root, relativeTarget)), `alias ${route} points to missing target ${target}`);
}

for (const route of [
    '/Assets/', '/Admin/', '/Operator/', '/Conductor/', '/terms.php', '/privacy.php',
    '/avatar.php', '/id_image.php', '/gps.php', '/logout.php',
    '/api/live_tracking.php', '/api/gcash_sms_webhook.php'
]) {
assert.ok(apache.includes(`Alias ${route} `), `missing Apache alias for ${route}`);
}
assert.match(apache, /Require all denied/);
assert.doesNotMatch(apache, /Alias \/Commuter\//);
assert.doesNotMatch(apache, /Alias \/login\.php/);
assert.doesNotMatch(apache, /Alias \/register\.php/);

assert.match(login, /defined\('MBUS_STAFF_PORTAL'\) && MBUS_STAFF_PORTAL === true/);
assert.match(login, /!in_array\(\$type, \['driver', 'conductor', 'admin'\], true\)/);
assert.match(login, /href="register\.php\?type=<\?php echo \$staff_portal \? 'conductor' : 'passenger'; \?>" class="" role="tab">Register/);
const footer = login.match(/<div class="footer">([\s\S]*?)<\/div>/)?.[1] ?? '';
assert.doesNotMatch(footer, /Apply as Driver\/Operator|apply as Conductor|New to MBUS staff/);

assert.match(registration, /\$allowed_types\s*=\s*\$staff_portal \? \["conductor", "driver"\] : \["passenger"\]/);
assert.match(registration, /mbus-registrations\.onrender\.com\/register\.php\?type=/);
assert.match(registration, /elseif \(\$invalid_type\)/);
assert.match(registration, /staff-registration/);
assert.doesNotMatch(registration, /Not a conductor\?\s*<a[^>]*>Apply as Driver\/Operator<\/a>/);
assert.match(registration, /if \(!\$staff_portal\):[\s\S]*?href="register\.php" class="on" role="tab">Register/);
assert.match(registration, /if \(!\$staff_portal\):[\s\S]*?else:[\s\S]*?register\.php\?type=conductor[\s\S]*?register\.php\?type=driver/);

console.log('PASS staff dashboard aliases, public-route allowlist, shared login role routing, and service-specific applications');
