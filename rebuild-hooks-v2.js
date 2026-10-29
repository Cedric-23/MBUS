const fs = require('fs');
const path = require('path');

// Load the fixed INLINE_RESOLVE from the marketplace source
const marketplaceResolve = require('C:/Users/Admin/.claude/plugins/marketplaces/ecc/scripts/lib/resolve-ecc-root.js');
const INLINE_RESOLVE = marketplaceResolve.INLINE_RESOLVE;

// Read original marketplace hooks.json
const marketplaceHooksPath = 'C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json';
const original = JSON.parse(fs.readFileSync(marketplaceHooksPath, 'utf8'));

// The fixed WSL conversion regex (8 backslashes for JSON -> shell -> node -e)
const FIXED_WSL_REGEX = 'replace(/\\\\\\\\\\\\\\\\/g,\'/\')';

// Pattern 1: INLINE_RESOLVE pattern (IIFE starting with "(function(){var p=require")
function fixInlineResolvePattern(command) {
  // Extract the script path and args after the inline code
  const match = command.match(/^node -e "(.+?)" (node scripts\/hooks\/.+)$/);
  if (!match) return command;

  const [, , scriptAndArgs] = match;
  return `node -e "${INLINE_RESOLVE}" ${scriptAndArgs}`;
}

// Pattern 2: Windows-aware pattern (starts with "const p=require")
// This pattern has the inline code with WSL conversion AND the const n= line
function fixWindowsAwarePattern(command) {
  // The inline code contains the WSL conversion regex that needs fixing
  // In the JS string (after JSON.parse): .replace(/\\/g,'/')  -> needs to be .replace(/\\\\/g,'/')
  // In JSON: \\\\ -> \\\\\\\\
  let fixed = command.replace(
    /\.replace\(\/\\\\\\\/g,'\/\'\)/g,
    `.replace(/\\\\\\\\\\\\\\\\/g,'/')`
  );
  return fixed;
}

// Process all hooks
function processHooks(hooksObj) {
  for (const eventType of Object.keys(hooksObj.hooks)) {
    for (const matcherGroup of hooksObj.hooks[eventType]) {
      for (const hook of matcherGroup.hooks) {
        const cmd = hook.command;

        if (cmd.includes('INLINE_RESOLVE') || cmd.startsWith('node -e "(function(){var p=require')) {
          // Pattern 1: INLINE_RESOLVE / IIFE pattern
          hook.command = fixInlineResolvePattern(cmd);
        } else if (cmd.startsWith('node -e "const p=require')) {
          // Pattern 2: Windows-aware pattern with const n=
          hook.command = fixWindowsAwarePattern(cmd);
        }
        // Other patterns (like session-start-bootstrap.js) use the INLINE_RESOLVE pattern too
        // but they don't have the run-with-flags.js wrapper
      }
    }
  }
  return hooksObj;
}

const rebuilt = processHooks(original);

// Write to marketplace
fs.writeFileSync(marketplaceHooksPath, JSON.stringify(rebuilt, null, 2));
console.log('Written to marketplace hooks.json');

// Copy to cache
const cachePath = 'C:/Users/Admin/.claude/plugins/cache/ecc/ecc/2.2.3/hooks/hooks.json';
fs.writeFileSync(cachePath, JSON.stringify(rebuilt, null, 2));
console.log('Written to cache hooks.json');

console.log('Done!');

// Verify
let pass = 0, fail = 0;
for (const eventType of Object.keys(rebuilt.hooks)) {
  for (const matcherGroup of rebuilt.hooks[eventType]) {
    for (const hook of matcherGroup.hooks) {
      try {
        const match = hook.command.match(/node -e "(.+?)"(?: node|$)/);
        if (match) {
          new Function(match[1]);
          pass++;
        } else {
          fail++;
          console.log('FAIL pattern:', eventType);
        }
      } catch(e) {
        fail++;
        console.log('FAIL parse:', eventType, e.message.substring(0, 60));
      }
    }
  }
}
console.log('Pass:', pass, 'Fail:', fail);