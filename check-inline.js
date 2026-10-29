const marketplaceResolve = require('C:/Users/Admin/.claude/plugins/marketplaces/ecc/scripts/lib/resolve-ecc-root.js');
const INLINE_RESOLVE = marketplaceResolve.INLINE_RESOLVE;

console.log('INLINE_RESOLVE length:', INLINE_RESOLVE.length);

// Find all replace patterns
let idx = INLINE_RESOLVE.indexOf('replace');
let count = 0;
while (idx >= 0) {
  const snippet = INLINE_RESOLVE.substring(idx, idx + 30);
  let bsCount = 0;
  for (let i = 0; i < snippet.length; i++) {
    if (snippet[i] === '\\') bsCount++;
  }
  console.log(`Pattern ${count}: ${bsCount} backslashes`);
  console.log(`  JSON: ${JSON.stringify(snippet)}`);
  idx = INLINE_RESOLVE.indexOf('replace', idx + 1);
  count++;
}

// Find all match patterns
idx = INLINE_RESOLVE.indexOf('match');
count = 0;
while (idx >= 0) {
  const snippet = INLINE_RESOLVE.substring(idx, idx + 40);
  let bsCount = 0;
  for (let i = 0; i < snippet.length; i++) {
    if (snippet[i] === '\\') bsCount++;
  }
  console.log(`Match ${count}: ${bsCount} backslashes`);
  console.log(`  JSON: ${JSON.stringify(snippet)}`);
  idx = INLINE_RESOLVE.indexOf('match', idx + 1);
  count++;
}