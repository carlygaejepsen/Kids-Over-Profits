#!/usr/bin/env node
/**
 * Check js/shared/program-links.js: a program's own website is only ever
 * linked as its archived copy plus a /go/ link, and an exempt host (archives,
 * this site, Reddit, public records...) keeps a plain link.
 *
 * The exempt list is read from kop_facility_pages_archive_exempt_domains() in
 * inc/facility-pages.php, the same list the page receives at run time.
 *
 * Usage:
 *   node scripts/test-program-links.js [--feed <kop-v1-facilities.json>]
 *
 * With --feed, every operator website and facility profile link in a saved
 * kop/v1/facilities payload goes through the helper too.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'inc', 'facility-pages.php'), 'utf8');
const block = php.match(/function kop_facility_pages_archive_exempt_domains\(\)\s*\{\s*return array\(([\s\S]*?)\);/);
if (!block) {
    console.error('Could not find kop_facility_pages_archive_exempt_domains() in inc/facility-pages.php');
    process.exit(2);
}
const exempt = Array.from(block[1].matchAll(/'([^']+)'/g), (m) => m[1]);

const GO = 'https://kidsoverprofits.org/go/';
const sandbox = {
    window: { KOP_PROGRAM_LINKS: { goBase: GO, exempt }, location: { hostname: 'kidsoverprofits.org' } },
    URL,
    encodeURIComponent,
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(root, 'js', 'shared', 'program-links.js'), 'utf8'), sandbox);
const L = sandbox.window.KOP && sandbox.window.KOP.programLinks;
if (!L || typeof L.html !== 'function') {
    console.error('program-links.js did not expose KOP.programLinks.html');
    process.exit(1);
}

let failures = 0;
const check = (label, ok, detail = '') => {
    if (!ok) failures++;
    if (!ok || process.env.VERBOSE) console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${detail ? '  (' + detail + ')' : ''}`);
};
const hrefs = (html) => Array.from(String(html).matchAll(/href="([^"]*)"/g), (m) => m[1].replace(/&amp;/g, '&'));

/** The invariant: a non-exempt URL yields exactly an archive link and a /go/ link. */
function assertSafe(url, where) {
    const html = L.html(url);
    const links = hrefs(html);
    if (!/^https?:\/\//i.test(String(url).trim())) {
        check(`${where}: non-URL is text only`, links.length === 0, url);
        return 'text';
    }
    if (L.isExempt(url)) {
        check(`${where}: exempt host keeps its plain link`, links.length === 1 && links[0] === String(url).trim(), url);
        return 'exempt';
    }
    const archive = links.filter((h) => h.startsWith('https://web.archive.org/web/'));
    const go = links.filter((h) => h.startsWith(GO + '?u='));
    check(`${where}: program site is archive + /go/ only`,
        links.length === 2 && archive.length === 1 && go.length === 1, `${url} -> ${links.join(' | ')}`);
    check(`${where}: /go/ carries the exact URL`,
        go.length === 1 && decodeURIComponent(go[0].slice((GO + '?u=').length)) === String(url).trim(), url);
    check(`${where}: live link is nofollow noreferrer`,
        /class="kop-live-link"[^>]*rel="nofollow noopener noreferrer"/.test(html), url);
    return 'program';
}

// --- fixed cases ---------------------------------------------------------------
const cases = [
    ['https://www.provocanyon.com/', 'program'],
    ['http://discoveryranchforgirls.com/about', 'program'],
    ['https://web.archive.org/web/2019/http://threesprings.com/', 'exempt'],
    ['https://www.reddit.com/r/troubledteens/wiki/index', 'exempt'],
    ['https://en.wikipedia.org/wiki/Provo_Canyon_School', 'exempt'],
    ['https://www.dhhs.nc.gov/some/report', 'exempt'],
    ['https://www.dhs.state.or.us/licensing', 'exempt'],
    ['https://kidsoverprofits.org/utah/', 'exempt'],
    ['https://notreddit.com.evil.example/', 'program'],
    ['javascript:alert(1)', 'text'],
    ['provocanyon.com', 'text'],
];
cases.forEach(([url, expected]) => {
    const got = assertSafe(url, 'case');
    check(`case ${url} classified as ${expected}`, got === expected, `got ${got}`);
});
check('escapes markup in a URL', !/<script/i.test(L.html('https://evil.example/"><script>alert(1)</script>')));
check('label option is used', L.html('https://example.org/', { label: 'Example' }).includes('>Example (archived)<'));

// --- every link in a saved feed --------------------------------------------------
const feedIdx = process.argv.indexOf('--feed');
if (feedIdx !== -1) {
    const feed = JSON.parse(fs.readFileSync(process.argv[feedIdx + 1], 'utf8'));
    const counts = { program: 0, exempt: 0, text: 0 };
    const urls = new Set();
    Object.values(feed.projects || {}).forEach((project) => {
        const data = project.data || {};
        ((data.operator || {}).websites || []).forEach((u) => urls.add(typeof u === 'string' ? u : (u && u.url) || ''));
        (data.facilities || []).forEach((f) => (f.profileLinks || []).forEach((u) => urls.add(typeof u === 'string' ? u : (u && u.url) || '')));
    });
    urls.forEach((u) => { if (u) counts[assertSafe(u, 'feed')]++; });
    console.log(`Feed links: ${urls.size} distinct (${counts.program} program, ${counts.exempt} exempt, ${counts.text} not a URL)`);
}

console.log(failures ? `${failures} failure(s)` : 'All program-link checks passed');
process.exit(failures ? 1 : 0);
