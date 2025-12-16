const text = `
|**Program Name**|**Year Opened**|**Location(s)**|**HEAL Information**|
| [**Cedar Crest Hospital & RTC**](https://www.reddit.com/r/troubledteens/wiki/index/cedarcrest) | - | - | - |
| [**Harbor Oaks Hospital**](https://www.reddit.com/r/troubledteens/wiki/index/harboroaks) | - | - | - |
`;

const linkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;
let match;
console.log(`Testing regex: ${linkRegex}`);
console.log(`Text length: ${text.length}`);

while ((match = linkRegex.exec(text)) !== null) {
    console.log(`Match found:`);
    console.log(`  Full: ${match[0]}`);
    console.log(`  Name: ${match[1]}`);
    console.log(`  URL:  ${match[2]}`);
}
