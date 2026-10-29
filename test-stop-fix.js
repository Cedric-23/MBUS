const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Test fixWindowsAwarePattern on current Stop hook
const cmd = hooks.hooks.Stop[0].hooks[0].command;

// The fix function
function fixWindowsAwarePattern(command) {
  let fixed = command.replace(
    /replace\(\/\\\\\/g/,
    "replace(/\\\\\\\\/g"
  );
  return fixed;
}

const fixed = fixWindowsAwarePattern(cmd);
console.log('Original replace pattern in JSON:');
const idx = cmd.indexOf('replace');
if (idx >= 0) console.log(cmd.substring(idx, idx+30));

console.log('Fixed replace pattern in JSON:');
const newIdx = fixed.indexOf('replace');
if (newIdx >= 0) console.log(fixed.substring(newIdx, newIdx+30));

console.log('Changed:', cmd !== fixed);