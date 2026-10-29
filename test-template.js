// Test template literal escaping
const s1 = `\`\\\\/\\\``;  // 4 backslashes in template literal source
const s2 = `\`\\\\\\\\/\\\``; // 8 backslashes in template literal source

console.log('4 in source:', s1.length, 'chars, backslashes:', (s1.match(/\\/g) || []).length);
console.log('8 in source:', s2.length, 'chars, backslashes:', (s2.match(/\\/g) || []).length);
console.log('s1:', JSON.stringify(s1));
console.log('s2:', JSON.stringify(s2));