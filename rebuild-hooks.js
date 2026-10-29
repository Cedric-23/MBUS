const fs = require('fs');
const path = require('path');

// Load the INLINE_RESOLVE from the marketplace source using require
const marketplaceResolve = require('C:/Users/Admin/.claude/plugins/marketplaces/ecc/scripts/lib/resolve-ecc-root.js');
const INLINE_RESOLVE = marketplaceResolve.INLINE_RESOLVE;
console.log('INLINE_RESOLVE length:', INLINE_RESOLVE.length);
console.log('Has 8 backslashes:', INLINE_RESOLVE.includes('replace(/\\\\\\\\/g'));
console.log('Sample:', INLINE_RESOLVE.substring(0, 200));
console.log('Contains double quotes:', INLINE_RESOLVE.includes('"'));

// Read current hooks.json
const hooksPath = 'C:\\Users\\Admin\\.claude\\plugins\\marketplaces\\ecc\\hooks\\hooks.json';
const hooks = JSON.parse(fs.readFileSync(hooksPath, 'utf8'));

// Rebuild each hook command using the new INLINE_RESOLVE
function rebuildHooks(hooksObj) {
  for (const eventType of Object.keys(hooksObj.hooks)) {
    for (const matcherGroup of hooksObj.hooks[eventType]) {
      for (const hook of matcherGroup.hooks) {
        // Extract the script path and arguments from the old command
        const oldCommand = hook.command;
        // Pattern: node -e "INLINE_RESOLVE" node scripts/hooks/SCRIPT.js ARGS...
        const match = oldCommand.match(/node -e ".+?" node (scripts\/hooks\/[^ ]+) ?(.*)/);
        if (match) {
          const scriptPath = match[1];
          const args = match[2] || '';
          // Build new command with fixed INLINE_RESOLVE
          hook.command = `node -e "${INLINE_RESOLVE}" node ${scriptPath} ${args}`.trim();
        }
      }
    }
  }
  return hooksObj;
}

const rebuilt = rebuildHooks(hooks);

// Write to marketplace
fs.writeFileSync(hooksPath, JSON.stringify(rebuilt, null, 2));
console.log('Written to marketplace hooks.json');

// Copy to cache
const cachePath = 'C:\\Users\\Admin\\.claude\\plugins\\cache\\ecc\\ecc\\2.2.3\\hooks\\hooks.json';
fs.writeFileSync(cachePath, JSON.stringify(rebuilt, null, 2));
console.log('Written to cache hooks.json');

console.log('Done!');