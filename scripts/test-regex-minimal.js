const snippet = "**|\r\n| [**Cedar Crest Hospital & RTC**](https://www.reddit.c";
const regex = /[[^\]]+\]\(([^)]+)\)/g;
console.log(`Testing snippet: ${JSON.stringify(snippet)}`);
console.log(`Regex: ${regex}`);
const match = regex.exec(snippet);
console.log('Match:', match);
