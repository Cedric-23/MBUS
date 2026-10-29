const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

const stopHook = hooks.hooks.Stop[0].hooks[0];
const cmd = stopHook.command;

// Find the WSL conversion part
const wslIdx = cmd.indexOf('try{if(o.platform()');
if (wslIdx >= 0) {
  const snippet = cmd.substring(wslIdx, wslIdx + 300);
  console.log('WSL conversion code:');
  console.log(snippet);
  console.log('\nJSON:');
  console.log(JSON.stringify(snippet));
}