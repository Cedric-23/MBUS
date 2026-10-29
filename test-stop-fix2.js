const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Test fixWindowsAwarePattern on current Stop hook
const cmd = hooks.hooks.Stop[0].hooks[0].command;

// Find the replace part
const idx = cmd.indexOf('replace');
if (idx >= 0) {
  const snippet = cmd.substring(idx, idx+30);
  console.log('Snippet raw:', snippet);
  console.log('Snippet JSON:', JSON.stringify(snippet));

  // Check each character
  for (let i = 0; i < snippet.length; i++) {
    const c = snippet[i];
    if (c === '\\' || c === '/') {
      console.log(`  [${i}] '${c}' (charCode: ${c.charCodeAt(0)})`);
    }
  }
}

// Test regex
const testRegex = /replace\(\/\\\\\/g/;
console.log('\nRegex:', testRegex);
console.log('Test match:', snippet.match(testRegex));

// What we're looking for
console.log('\nLooking for: replace(/\\\\/g');
console.log('In JSON that would be: replace(/\\\\\\\\/g');