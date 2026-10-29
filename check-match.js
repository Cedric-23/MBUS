const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

function showMatchPatterns(command) {
  const results = [];
  let idx = command.indexOf('match');
  while (idx >= 0) {
    const snippet = command.substring(idx, idx + 40);
    let count = 0;
    for (let i = 0; i < snippet.length; i++) {
      if (snippet[i] === '\\') count++;
    }
    results.push({ snippet, count });
    idx = command.indexOf('match', idx + 1);
  }
  return results;
}

const preHook = hooks.hooks.PreToolUse[0].hooks[0];
console.log('PreToolUse match patterns:');
showMatchPatterns(preHook.command).forEach((p, i) => {
  console.log(`  [${i}] Count: ${p.count}`);
  console.log(`      JSON: ${JSON.stringify(p.snippet)}`);
});

const stopHook = hooks.hooks.Stop[0].hooks[0];
console.log('\nStop match patterns:');
showMatchPatterns(stopHook.command).forEach((p, i) => {
  console.log(`  [${i}] Count: ${p.count}`);
  console.log(`      JSON: ${JSON.stringify(p.snippet)}`);
});