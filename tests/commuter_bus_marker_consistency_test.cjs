const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const waitingCss = read('Assets/css/commuter_waiting_board.css');
const liveCss = read('Assets/css/commuter_live.css');
const pickupCss = read('Assets/css/commuter_pickup.css');
const waitingJs = read('Assets/js/commuter_waiting_board.js');
const liveJs = read('Assets/js/commuter_live.js');
const pickupJs = read('Assets/js/commuter_pickup.js');

function declarations(css, selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&');
    const rule = css.match(new RegExp(escaped + '\\s*\\{([^}]*)\\}'));
    assert.ok(rule, `missing CSS rule for ${selector}`);
    return Object.fromEntries(rule[1].split(';').map(part => part.trim()).filter(Boolean).map(part => {
        const colon = part.indexOf(':');
        return [part.slice(0, colon).trim(), part.slice(colon + 1).trim().replace(/\\s+/g, '').toLowerCase()];
    }));
}

const reference = declarations(waitingCss, '.cwb-bus-pin');
const properties = ['display', 'align-items', 'justify-content', 'gap', 'min-width', 'height', 'padding', 'border', 'border-radius', 'background', 'color', 'box-shadow', 'font', 'white-space'];
for (const [screen, css, selector] of [
    ['Live', liveCss, '.commuter-live-page .board-bus-pin'],
    ['Pickup', pickupCss, '.pk-bus']
]) {
    const actual = declarations(css, selector);
    for (const property of properties) {
        assert.equal(actual[property], reference[property], `${screen} bus marker ${property} should match Waiting`);
    }
}

for (const [screen, source] of [['Waiting', waitingJs], ['Live', liveJs], ['Pickup', pickupJs]]) {
    assert.match(source, /iconSize:\s*\[114,\s*34\]/, `${screen} bus marker should use Waiting's icon footprint`);
    assert.match(source, /iconAnchor:\s*\[57,\s*17\]/, `${screen} bus marker should use Waiting's centered anchor`);
}
assert.match(liveJs, /fa-bus[^']*' \+ escapeHtml/);
assert.match(pickupJs, /fa-bus[^']*' \+ esc/);

console.log('PASS Live and Pickup bus map markers match Waiting in pill styling, icon size and anchor');
