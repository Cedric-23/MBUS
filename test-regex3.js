const fs = require('fs');
const hooks = JSON.parse(fs.readFileSync('C:/Users/Admin/.claude/plugins/marketplaces/ecc/hooks/hooks.json', 'utf8'));

// Check a Stop hook command
const hook = hooks.hooks.Stop[0].hooks[0];
const cmd = hook.command;

// Apply the fix
const fixed = cmd.replace(/replace\(\/\\\\\/g/, "replace(/\\\\\\\\/g");

console.log("First 50 chars of fixed:", fixed.substring(0, 50));
console.log("Last 50 chars of fixed:", fixed.substring(fixed.length - 50));

// Try different regex
const match1 = fixed.match(/node -e "(.+)" node/);
console.log("Match 1 (with node):", match1 ? "YES" : "NO");

const match2 = fixed.match(/node -e "(.+)"$/);
console.log("Match 2 (end):", match2 ? "YES" : "NO");

const match3 = fixed.match(/node -e "(.+?)"/);
console.log("Match 3 (non-greedy):", match3 ? "YES" : "NO");
if (match3) {
  console.log("  Length:", match3[1].length);
}

const match4 = fixed.match(/node -e "([\s\S]+)"/);
console.log("Match 4 (all):", match4 ? "YES" : "NO");
if (match4) {
  console.log("  Length:", match4[1].length);
}