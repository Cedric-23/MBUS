const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

function countBackslashesInRegex(command) {
  const idx = command.indexOf('replace');
  if (idx < 0) return -1;
  const snippet = command.substring(idx, idx + 20);
  let count = 0;
  for (let i = 0; i < snippet.length; i++) {
    if (snippet[i] === '\\') count++;
  }
  return count;
}

// Check PreToolUse
const preHook = hooks.hooks.PreToolUse[0].hooks[0];
const preCount = countBackslashesInRegex(preHook.command);
console.log('PreToolUse backslashes in regex:', preCount);

// Check Stop
const stopHook = hooks.hooks.Stop[0].hooks[0];
const stopCount = countBackslashesInRegex(stopHook.command);
console.log('Stop backslashes in regex:', stopCount);

// Check PostToolUse
const postHook = hooks.hooks.PostToolUse[0].hooks[0];
const postCount = countBackslashesInRegex(postHook.command);
console.log('PostToolUse backslashes in regex:', postCount);

// Check SessionEnd
const sessionHook = hooks.hooks.SessionEnd[0].hooks[0];
const sessionCount = countBackslashesInRegex(sessionHook.command);
console.log('SessionEnd backslashes in regex:', sessionCount);

console.log('\nAll hooks should have 4 backslashes in regex (8 in JSON) for the fix.');