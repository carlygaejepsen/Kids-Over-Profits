const fs = require('fs');
const path = require('path');

const FILE_PATH = 'markdown_output/active-programs_acadiahealth.md';

const content = fs.readFileSync(FILE_PATH, 'utf8');
console.log(`Read ${content.length} chars from ${FILE_PATH}`);

// Mocking the split logic
const sections = content.split(/^##\s+/gm);
const targetSection = sections.find(s => s.includes('Open Acadia'));

if (!targetSection) {
    console.error('Target section not found!');
    process.exit(1);
}

const firstLineEnd = targetSection.indexOf('\n');
const body = targetSection.substring(firstLineEnd);

console.log(`Scanning body (${body.length} chars)...`);
console.log(`Body start: ${body.substring(0, 50).replace(/\n/g, '\\n')}`);

// Exact regex from scan-organizations.js
const index = body.indexOf('Cedar Crest');
if (index !== -1) {
    const snippet = body.substring(index - 10, index + 50);
    console.log(`Snippet around "Cedar Crest":`);
    console.log(JSON.stringify(snippet));
}

const linkRegex = /[[^\}\]]+]\[\(([^)]+)\)\]/g;

let match;
let count = 0;
while ((match = linkRegex.exec(body)) !== null) {
    console.log(`Match: ${match[1]} -> ${match[2]}`);
    count++;
}

console.log(`Found ${count} matches.`);
