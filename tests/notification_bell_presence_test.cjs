/*
 * Regression coverage for the global notification bell contract.
 *
 * Every rendered role page gets the same notification button and client script,
 * except Waiting and Live pages, which intentionally keep their focused map/
 * board controls. This test discovers rendered PHP pages so new role pages do
 * not silently bypass the shared header contract.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const roleDirs = [
  ['Admin', 'Admin'],
  ['Commuter', 'Commuter'],
  ['Operator', 'Driver'],
  ['Conductor', 'Conductor'],
];
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const renderedPages = dir => fs.readdirSync(path.join(root, dir))
  .filter(name => name.endsWith('.php'))
  .filter(name => {
    const source = read(path.join(dir, name));
    return /<!DOCTYPE\s+html|<html\b/i.test(source);
  });
const isWaitingOrLive = name => /(?:^|_)(?:waiting|live)(?:_|\.)/i.test(name)
  || /^(?:waiting|live)\.php$/i.test(name);

const errors = [];
for (const [dir, role] of roleDirs) {
  for (const name of renderedPages(dir)) {
    const relative = path.join(dir, name);
    const source = read(relative);
    const excluded = isWaitingOrLive(name);
    const hasBell = /class=["'][^"']*\bnotif-btn\b/.test(source)
      && /fa-bell/.test(source)
      && /aria-label=["']Notifications["']/.test(source);
    const hasScript = /notifications\.js/.test(source);
    const hasBellStyles = /(?:notif_bell\.css|commuter_modern\.css|commuter_dashboard\.css|commuter_sidebar\.css)/.test(source);

    if (excluded) {
      if (hasBell) errors.push(`${relative}: Waiting/Live pages must not render a notification bell`);
      if (hasScript) errors.push(`${relative}: Waiting/Live pages must not load notifications.js`);
      continue;
    }
    if (!hasBell) errors.push(`${relative}: ${role} page needs the shared Notifications bell button/icon`);
    if (!hasScript) errors.push(`${relative}: ${role} page needs Assets/js/notifications.js`);
    if (!hasBellStyles) errors.push(`${relative}: ${role} page needs a notification bell stylesheet`);
  }
}

assert.equal(errors.length, 0, `Notification bell contract violations:\n${errors.join('\n')}`);
console.log('PASS notification bell is present on rendered Admin/Commuter/Driver/Conductor pages and absent on Waiting/Live pages');
