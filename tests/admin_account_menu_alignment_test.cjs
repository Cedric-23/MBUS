/* Admin account-menu icons reserve a shared slot so every label starts at the same position. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');

const css = fs.readFileSync(path.join(root, 'Assets/css/app-header.css'), 'utf8');
assert.match(css, /\.profile-menu a\s*>\s*i\s*\{[^}]*flex:\s*0 0 1\.25rem;[^}]*width:\s*1\.25rem;[^}]*text-align:\s*center;/s,
    'account-menu icons should use a fixed centered slot so labels align');

const appFiles = ['Admin', 'Operator', 'Includes'].flatMap(folder =>
    fs.readdirSync(path.join(root, folder), { withFileTypes: true })
        .filter(entry => entry.isFile() && entry.name.endsWith('.php'))
        .map(entry => path.join(folder, entry.name)));
const cssLinks = appFiles.flatMap(file => {
    const text = fs.readFileSync(path.join(root, file), 'utf8');
    return [...text.matchAll(/<link\b[^>]*app-header\.css[^>]*>/g)].map(match => ({ file, tag: match[0] }));
});
assert.ok(cssLinks.length > 0, 'app pages should load the shared account-menu stylesheet');
for (const { file, tag } of cssLinks) {
    assert.match(tag, /app-header\.css\?v=/,
        `${file} should version the stylesheet so cached CSS updates reach the live Admin UI`);
}

const applications = fs.readFileSync(path.join(root, 'Admin/operator_applications.php'), 'utf8');
assert.doesNotMatch(applications, /Create an admin account|add_admin|name="add_admin"/i,
    'Operator Applications must not expose an extra Admin account-creation path');

console.log('PASS Admin account-menu labels align, shared CSS is cache-busted, and extra Admin creation is removed');
