const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Check a Stop hook command
const hook = hooks.hooks.Stop[0].hooks[0];
const cmd = hook.command;

// Apply the fix
const fixed = cmd.replace(/replace\(\/\\\\\/g/, "replace(/\\\\\\\\/g");

console.log("--- Testing parse of fixed command ---");
try {
  const match = fixed.match(/node -e "(.+?)"/);
  if (match) {
    console.log("Extracted code length:", match[1].length);
    new Function(match[1]);
    console.log("PARSE OK!");
  }
} catch(e) {
  console.log("PARSE FAIL:", e.message);
}