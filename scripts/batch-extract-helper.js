/**
 * Batch Extract Helper
 *
 * This script helps you extract programs from multiple state pages.
 * Copy this into your console on each state page to extract programs.
 */

// List of all state page URLs (from the active-programs index)
const STATE_PAGES = [
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-alabama',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-alaska',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-arizona',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-arkansas',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-california',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-colorado',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-connecticut',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-delaware',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-florida',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-georgia',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-hawaii',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-idaho',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-illinois',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-indiana',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-iowa',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-kansas',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-kentucky',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-louisiana',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-maine',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-maryland',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-massachusetts',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-michigan',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-minnesota',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-mississippi',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-missouri',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-montana',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-nebraska',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-nevada',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-newhampshire',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-newjersey',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-newmexico',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-newyork',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-northcarolina',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-northdakota',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-ohio',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-oklahoma',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-oregon',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-pennsylvania',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-rhodeisland',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-southcarolina',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-southdakota',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-tennessee',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-texas',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-utah',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-vermont',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-virginia',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-washington',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-westvirginia',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-wisconsin',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-wyoming',
    'https://www.reddit.com/r/troubledteens/wiki/index/active-programs/active-programs-nonusa'
];

console.log('=== State Pages to Visit ===');
console.log(`Total: ${STATE_PAGES.length} pages\n`);
console.log('Copy these URLs and visit each one to extract programs:\n');
STATE_PAGES.forEach((url, i) => {
    const state = url.split('-').pop();
    console.log(`${i + 1}. ${state.toUpperCase().padEnd(15)} → ${url}`);
});

console.log('\n=== Instructions ===');
console.log('1. Open each URL above');
console.log('2. Run the extraction script from scripts/extract-links-from-page.js');
console.log('3. Copy the JSON output');
console.log('4. Add to your tti-program-links.json file');
console.log('5. After visiting all pages, run: node scripts/rebuild-search-index.js');
