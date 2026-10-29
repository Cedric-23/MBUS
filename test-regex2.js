const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Check a Stop hook command
const hook = hooks.hooks.Stop[0].hooks[0];
const cmd = hook.command;

// Apply the fix
const fixed = cmd.replace(/replace\(\/\\\\\/g/, "replace(/\\\\\\\\/g");

console.log("--- Testing parse of fixed command ---");
try {
  const fullMatch = fixed.match(/node -e "(.+?)"(?: node|$)/);
  if (fullMatch) {
    console.log("Extracted code length:", fullMatch[1].length);
    new Function(fullMatch[1]);
    console.log("PARSE OK!");
  } else {
    console.log("No match for -e pattern");
  }
} catch(e) {
  console.log("PARSE FAIL:", e.message);
}