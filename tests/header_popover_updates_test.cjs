const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const header = read('Assets/js/app-header.js');
const notifications = read('Assets/js/notifications.js');
const staffNav = read('Includes/staff_nav.php');
const adminMenu = read('Includes/admin_menu.php');

assert.match(header, /function closeNotifications\(\)/);
assert.match(header, /closeNotifications\(\);[\s\S]*?menu\.classList\.toggle\('open'\)/);
assert.match(notifications, /profile\.classList\.remove\('open'\)/);
assert.match(notifications, /link\.href = n\.href \|\| targetFor\(n\.message\)/);
assert.match(notifications, /schedule change|trip removed|trip cancelled/);
assert.match(staffNav, /\$links\['updates'\] = \['announcements\.php', 'fa-bullhorn', 'Updates'\]/);
assert.match(adminMenu, /announcements\.php.*Updates/);

for (const file of fs.readdirSync(path.join(root, 'Commuter')).filter(name => name.endsWith('.php'))) {
    const html = read(`Commuter/${file}`);
    if (html.includes('class="profile-menu"')) {
        assert.match(html, /href="announcements\.php" role="menuitem"[^>]*>[\s\S]*?Updates/, `${file} profile menu lacks Updates`);
    }
}

console.log('PASS header popover exclusivity, notification redirection, and Updates profile links');
