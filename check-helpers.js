const helpers = require('C:/Users/Admin/.claude/plugins/marketplaces/ecc/scripts/lib/install-targets/helpers.js');
const INLINE_WSL_CONVERSION = helpers.INLINE_WSL_CONVERSION;

console.log('INLINE_WSL_CONVERSION length:', INLINE_WSL_CONVERSION.length);

// Find all replace patterns
let idx = INLINE_WSL_CONVERSION.indexOf('replace');
let count = 0;
while (idx >= 0) {
  const snippet = INLINE_WSL_CONVERSION.substring(idx, idx + 30);
  let bsCount = 0;
  for (let i = 0; i < snippet.length; i++) {
    if (snippet[i] === '\\') bsCount++;
  }
  console.log(`Pattern ${count}: ${bsCount} backslashes`);
  console.log(`  JSON: ${JSON.stringify(snippet)}`);
  idx = INLINE_WSL_CONVERSION.indexOf('replace', idx + 1);
  count++;
}

// Find all match patterns
idx = INLINE_WSL_CONVERSION.indexOf('match');
count = 0;
while (idx >= 0) {
  const snippet = INLINE_WSL_CONVERSION.substring(idx, idx + 40);
  let bsCount = 0;
  for (let i = 0; i < snippet.length; i++) {
    if (snippet[i] === '\\') bsCount++;
  }
  console.log(`Match ${count}: ${bsCount} backslashes`);
  console.log(`  JSON: ${JSON.stringify(snippet)}`);
  idx = INLINE_WSL_CONVERSION.indexOf('match', idx + 1);
  count++;
}