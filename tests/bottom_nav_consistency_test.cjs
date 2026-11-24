/* Every role page uses one cache-busted shared mobile navigation style. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function phpFiles(dir) {
    return fs.readdirSync(path.join(root, dir), { withFileTypes: true }).flatMap(entry => {
        const relative = path.join(dir, entry.name);
        if (entry.isDirectory()) return phpFiles(relative);
        return entry.isFile() && entry.name.endsWith('.php') ? [relative] : [];
    });
}

const navPages = ['Admin', 'Operator', 'Commuter', 'Includes']
    .flatMap(phpFiles)
    .filter(file => file !== 'Includes/staff_nav.php' && read(file).includes('<nav class="bottom-nav"'));
assert.ok(navPages.length > 0, 'expected to find role pages with bottom navigation');
for (const page of navPages) {
    const source = read(page);
    assert.match(source, /bottom-nav\.css\?v=.*filemtime/,
        `${page} must load the cache-busted shared navigation stylesheet`);
}

const sharedCss = read('Assets/css/bottom-nav.css');
assert.match(sharedCss, /left:\s*12px;[\s\S]*right:\s*12px;[\s\S]*bottom:\s*calc\(10px \+ env\(safe-area-inset-bottom\)\)/,
    'shared mobile navigation should use the same floating placement as Home');
assert.match(sharedCss, /--bottom-nav-height:\s*58px/);
assert.match(sharedCss, /border-radius:\s*20px;[\s\S]*backdrop-filter:\s*saturate\(180%\) blur\(12px\)/,
    'shared mobile navigation should keep a compact glass treatment');
assert.match(sharedCss, /\.bottom-nav-item\.active::after\s*\{\s*display:\s*none;/,
    'shared active state should match the Home navigation');
assert.doesNotMatch(read('Assets/css/ui2026.css'), /Floating bottom navigation/,
    'navigation appearance must not be overridden only on pages that happen to load ui2026.css');

console.log(`PASS shared floating navigation is loaded by all ${navPages.length} pages with a cache-busted stylesheet`);
