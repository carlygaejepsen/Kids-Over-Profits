#!/usr/bin/env node
/**
 * Work out which wiki index entries still need a real page written, and (re)write
 * the two lists the site uses to show "needs creation" vs "complete".
 *
 * An entry NEEDS CREATION when it has no real content. That happens two ways:
 *   1. A stub markdown file — only the title, "## Section" headers, horizontal
 *      rules, the "Last revised by" footer, and/or placeholders like
 *      "No information is known."
 *   2. No markdown file at all — the program is listed in the wiki index
 *      (js/data/reddit-wiki/programs-*.json) but was never created
 *      (e.g. "St. Mary's Home for Boys" / stmarysboys).
 *
 * So this scan is INDEX-DRIVEN: it starts from every slug in the index and keeps
 * the ones that lack a real markdown page, rather than only looking at the files
 * that happen to exist. (The earlier file-only scan missed no-file entries, and
 * an even earlier word-count scan missed short stub templates.) Completion by
 * database submission is layered on at request time by api/wiki-stubs.php /
 * the front end — this only decides which entries have real *page* content.
 *
 * Outputs (kept in sync — the front end uses whichever it can reach):
 *   js/data/reddit-wiki/empty-slugs.json     authoritative slug list; fetched by
 *                                            the browser, and the basis below
 *   markdown_output/empty_files_updated.md   filename form read by wiki-stubs.php;
 *                                            real stub filenames where one exists,
 *                                            else a synthetic `index_<slug>.md`
 *                                            line (used only as a slug source —
 *                                            nothing fetches it by name)
 *
 * Usage:
 *   node scripts/scan-wiki-stubs.js            # rewrite both outputs
 *   node scripts/scan-wiki-stubs.js --dry-run  # report only, write nothing
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const MD_DIR = path.join(ROOT, 'markdown_output');
const WIKI_DIR = path.join(ROOT, 'js', 'data', 'reddit-wiki');
const LIST_FILE = path.join(MD_DIR, 'empty_files_updated.md');
const SLUGS_JSON = path.join(WIKI_DIR, 'empty-slugs.json');

// Anything past this many characters of real prose means a page is NOT a stub.
const STUB_CHAR_THRESHOLD = 25;

const BOILERPLATE = [
    /^no information (is )?(known|available)\.?$/i,
    /^nothing is known\.?$/i,
    /^unknown\.?$/i,
    /^n\/a\.?$/i,
    /^tbd\.?$/i,
    /^page title$/i,
];

/** Derive the wiki slug from a markdown filename, matching api/wiki-stubs.php. */
function slugFromFilename(name) {
    name = path.basename(name);
    if (name.indexOf('index_') === 0) {
        return name.replace(/\.md$/i, '').slice('index_'.length).replace(/_+$/, '').toLowerCase();
    }
    if (/^[a-z0-9\-]+\.md$/i.test(name)) {
        return name.replace(/\.md$/i, '').toLowerCase();
    }
    return null; // underscore-containing non-index names (active-programs_*) aren't slugged
}

/** Pull the index slug out of a reddit wiki url, e.g. .../index/stmarysboys/ -> stmarysboys */
function slugFromUrl(url) {
    const m = String(url || '').match(/index\/([^/#?]+)/i);
    return m ? decodeURIComponent(m[1]).toLowerCase() : null;
}

/** Characters of real prose left after stripping structure and placeholders. */
function meaningfulContent(text) {
    const kept = [];
    let titleConsumed = false;
    for (const rawLine of text.split(/\r?\n/)) {
        const t = rawLine.trim();
        if (!t) continue;
        if (/^#{1,6}\s/.test(t)) continue;          // markdown header (## Section)
        if (/^[-*_]{3,}$/.test(t)) continue;         // horizontal rule
        if (/^last revised by/i.test(t)) continue;   // wiki footer
        // First non-structural line is usually the bare page title; skip it once
        // so a long facility name never counts as content.
        if (!titleConsumed && kept.length === 0 && !/[.!?]/.test(t) && t.split(/\s+/).length <= 12) {
            titleConsumed = true;
            continue;
        }
        if (BOILERPLATE.some((re) => re.test(t))) continue;
        const stripped = t
            .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1') // [text](url) -> text
            .replace(/[*_`>#|]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (stripped) kept.push(stripped);
    }
    return kept.join(' ');
}

function main() {
    const dryRun = process.argv.includes('--dry-run');
    const rel = (p) => path.relative(ROOT, p);

    // 1. Every slug in the wiki index (the universe of programs), with a name.
    const indexNames = new Map(); // slug -> display name
    for (const f of fs.readdirSync(WIKI_DIR).filter((f) => /^programs-.*\.json$/.test(f))) {
        let data;
        try {
            data = JSON.parse(fs.readFileSync(path.join(WIKI_DIR, f), 'utf8'));
        } catch {
            continue;
        }
        for (const p of data.programs || []) {
            const slug = slugFromUrl(p.url);
            if (slug && !indexNames.has(slug)) indexNames.set(slug, p.name || slug);
        }
    }

    // 2. Classify markdown files: which slugs have REAL content vs a stub file.
    const realSlugs = new Set();
    const stubFilesBySlug = new Map(); // slug -> [filenames]
    const files = fs.readdirSync(MD_DIR).filter((f) => f.endsWith('.md') && f !== path.basename(LIST_FILE));
    for (const f of files) {
        const slug = slugFromFilename(f);
        if (!slug) continue;
        const isReal = meaningfulContent(fs.readFileSync(path.join(MD_DIR, f), 'utf8')).length >= STUB_CHAR_THRESHOLD;
        if (isReal) {
            realSlugs.add(slug);
        } else {
            if (!stubFilesBySlug.has(slug)) stubFilesBySlug.set(slug, []);
            stubFilesBySlug.get(slug).push(f);
        }
    }

    // 3. Empty = (index slugs without a real page) ∪ (stub-file slugs not in index).
    //    A real file always wins, so a slug with both a real and a stub file is NOT empty.
    const emptySlugs = new Set();
    let noFileCount = 0;
    let stubFileCount = 0;
    for (const slug of indexNames.keys()) {
        if (realSlugs.has(slug)) continue;
        emptySlugs.add(slug);
        if (stubFilesBySlug.has(slug)) stubFileCount++;
        else noFileCount++;
    }
    let extraStubFiles = 0; // stub files (e.g. sub-pages) not present in the index
    for (const slug of stubFilesBySlug.keys()) {
        if (realSlugs.has(slug) || emptySlugs.has(slug)) continue;
        emptySlugs.add(slug);
        extraStubFiles++;
    }

    const slugs = [...emptySlugs].sort((a, b) => a.localeCompare(b));

    // Filename form for empty_files_updated.md: prefer a real stub filename,
    // otherwise synthesize an index_<slug>.md line (slug source only).
    const fileForSlug = (slug) => {
        const have = stubFilesBySlug.get(slug);
        if (have && have.length) return have.find((f) => f.startsWith('index_')) || have[0];
        return `index_${slug}.md`;
    };
    const filenames = slugs.map(fileForSlug).sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));

    // Report against the previous slug list.
    let prevSlugs = [];
    try {
        prevSlugs = JSON.parse(fs.readFileSync(SLUGS_JSON, 'utf8')).slugs || [];
    } catch {
        /* none yet */
    }
    const prevSet = new Set(prevSlugs.map((s) => String(s).toLowerCase()));
    const added = slugs.filter((s) => !prevSet.has(s));
    const removed = prevSlugs.filter((s) => !emptySlugs.has(String(s).toLowerCase()));

    console.log(`Index slugs: ${indexNames.size} | real pages: ${realSlugs.size} | markdown files: ${files.length}`);
    console.log(`Needs creation: ${slugs.length}`);
    console.log(`  ${stubFileCount} stub file · ${noFileCount} no file (e.g. stmarysboys) · ${extraStubFiles} stub sub-pages not in index`);
    console.log(`  + ${added.length} newly flagged vs current list · - ${removed.length} dropped`);

    if (dryRun) {
        if (added.length) console.log('\nNewly flagged (sample):\n  ' + added.slice(0, 40).join('\n  ') + (added.length > 40 ? `\n  …and ${added.length - 40} more` : ''));
        if (removed.length) console.log('\nDropped:\n  ' + removed.join('\n  '));
        console.log('\n(dry run — nothing written)');
        return;
    }

    fs.writeFileSync(LIST_FILE, filenames.map((n) => `- ${n}`).join('\n') + '\n');
    fs.writeFileSync(
        SLUGS_JSON,
        JSON.stringify({ generated: new Date().toISOString(), count: slugs.length, slugs }) + '\n'
    );
    console.log(`\nWrote ${filenames.length} entries to ${rel(LIST_FILE)}`);
    console.log(`Wrote ${slugs.length} slugs to ${rel(SLUGS_JSON)}`);
}

main();
