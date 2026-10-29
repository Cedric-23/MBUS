const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

function showReplacePatterns(command) {
  const results = [];
  let idx = command.indexOf('replace');
  while (idx >= 0) {
    const snippet = command.substring(idx, idx + 30);
    // Count backslashes in this specific replace call
    let count = 0;
    for (let i = 0; i < snippet.length; i++) {
      if (snippet[i] === '\\') count++;
    }
    results.push({ snippet, count });
    idx = command.indexOf('replace', idx + 1);
  }
  return results;
}

// Check PreToolUse
const preHook = hooks.hooks.PreToolUse[0].hooks[0];
console.log('PreToolUse replace patterns:');
const prePatterns = showReplacePatterns(preHook.command);
prePatterns.forEach((p, i) => {
  console.log(`  [${i}] Count: ${p.count}`);
  console.log(`      JSON: ${JSON.stringify(p.snippet)}`);
});

// Check Stop
const stopHook = hooks.hooks.Stop[0].hooks[0];
console.log('\nStop replace patterns:');
const stopPatterns = showReplacePatterns(stopHook.command);
stopPatterns.forEach((p, i) => {
  console.log(`  [${i}] Count: ${p.count}`);
  console.log(`      JSON: ${JSON.stringify(p.snippet)}`);
});

// Check PostToolUse
const postHook = hooks.hooks.PostToolUse[0].hooks[0];
console.log('\nPostToolUse replace patterns:');
const postPatterns = showReplacePatterns(postHook.command);
postPatterns.forEach((p, i) => {
  console.log(`  [${i}] Count: ${p.count}`);
  console.log(`      JSON: ${JSON.stringify(p.snippet)}`);
});