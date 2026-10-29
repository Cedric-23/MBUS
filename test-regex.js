const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Check a Stop hook command
const hook = hooks.hooks.Stop[0].hooks[0];
const cmd = hook.command;

// Find the replace part
const idx = cmd.indexOf("replace");
if (idx >= 0) {
  const snippet = cmd.substring(idx-5, idx+30);
  console.log("Snippet:", JSON.stringify(snippet));
  console.log("Raw:", snippet);
}

// Test regex on the actual command
console.log("\n--- Testing regex on actual command ---");
const match = cmd.match(/replace\(\/\\\\\/g/);
console.log("Match result:", match);

// Test replacement
const fixed = cmd.replace(/replace\(\/\\\\\/g/, "replace(/\\\\\\\\/g");
const newIdx = fixed.indexOf("replace");
if (newIdx >= 0) {
  console.log("Fixed snippet:", fixed.substring(newIdx-5, newIdx+30));
}

// Verify the fixed version parses
console.log("\n--- Testing parse ---");
try {
  const fullMatch = fixed.match(/node -e "(.+?)"(?: node|$)/);
  if (fullMatch) {
    new Function(fullMatch[1]);
    console.log("PARSE OK!");
  }
} catch(e) {
  console.log("PARSE FAIL:", e.message);
}