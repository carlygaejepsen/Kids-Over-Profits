#!/usr/bin/env node
/**
 * Scan markdown_output/ for stub wiki pages and (re)write empty_files_updated.md.
 *
 * A "stub" is a wiki markdown page with no real content — only the title,
 * section headers, horizontal rules, the "Last revised by" footer, and/or
 * boilerplate placeholders like "No information is known." These pages still
 * need to be written, so api/wiki-stubs.php serves them as `emptySlugs`
 * (entries the index browser shows as "needs work" rather than "complete").
 *
 * Why this exists: the original list was produced by a one-off scan that keyed
 * on word count. Stubs whose template was shorter than ~60 words (short names /
 * fewer sections) fell under the threshold and were mis-flagged as complete,
 * and stubs created after that scan were never added. This detector is
 * content-based (it strips structural lines and boilerplate, then measures what
 * is left), so it is independent of name/title length.
 *
 * Outputs (both must stay in sync — the front end uses whichever it can reach):
 *   markdown_output/empty_files_updated.md   read server-side by wiki-stubs.php
 *   js/data/reddit-wiki/empty-slugs.json     static fallback fetched by the
 *                                            browser when the API is unavailable
 *
 * Usage:
 *   node scripts/scan-wiki-stubs.js            # rewrite both outputs
 *   node scripts/scan-wiki-stubs.js --dry-run  # report only, write nothing
 */

const fs = require('fs');
const path = require('path');

const MD_DIR = path.join(__dirname, '..', 'markdown_output');
const LIST_FILE = path.join(MD_DIR, 'empty_files_updated.md');
const SLUGS_JSON = path.join(__dirname, '..', 'js', 'data', 'reddit-wiki', 'empty-slugs.json');

/**
 * Derive the wiki slug from a markdown filename exactly as api/wiki-stubs.php
 * does, so the static JSON and the API agree. Returns null for names php skips.
 */
function slugFromFilename(name) {
    name = path.basename(name);
    if (name.indexOf('index_') === 0) {
        return name.replace(/\.md$/i, '').slice('index_'.length).replace(/_+$/, '').toLowerCase();
    }
    if (/^[a-z0-9\-]+\.md$/i.test(name)) {
        return name.replace(/\.md$/i, '').toLowerCase();
    }
    return null; // underscore-containing non-index names (e.g. active-programs_*) are not slugged
}

// Lines that count as boilerplate placeholders, not real content.
const BOILERPLATE = [
    /^no information (is )?(known|available)\.?$/i,
    /^nothing is known\.?$/i,
    /^unknown\.?$/i,
    /^n\/a\.?$/i,
    /^tbd\.?$/i,
    /^page title$/i,
];

// Anything past this many characters of real prose means the page is NOT a stub.
const STUB_CHAR_THRESHOLD = 25;

/**
 * Return the meaningful prose left after removing structural markdown and
 * boilerplate. Empty/near-empty result => the page is a stub.
 */
function meaningfulContent(text) {
    const kept = [];
    let titleConsumed = false;
    for (const rawLine of text.split(/\r?\n/)) {
        const t = rawLine.trim();
        if (!t) continue;
        if (/^#{1,6}\s/.test(t)) continue;          // markdown header (## Section)
        if (/^[-*_]{3,}$/.test(t)) continue;         // horizontal rule
        if (/^last revised by/i.test(t)) continue;   // wiki footer

        // The first non-structural line is usually the bare page title
        // (e.g. "Starlight"). Consume it once if it reads like a name, not a
        // sentence, so a long facility name never counts as "content".
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

    const files = fs
        .readdirSync(MD_DIR)
        .filter((f) => f.endsWith('.md') && f !== path.basename(LIST_FILE));

    const stubs = [];
    for (const f of files) {
        const content = meaningfulContent(fs.readFileSync(path.join(MD_DIR, f), 'utf8'));
        if (content.length < STUB_CHAR_THRESHOLD) stubs.push(f);
    }
    stubs.sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));

    // Compare against the existing list (normalized to bare filenames) for reporting.
    let previous = [];
    if (fs.existsSync(LIST_FILE)) {
        previous = fs
            .readFileSync(LIST_FILE, 'utf8')
            .split(/\r?\n/)
            .map((r) => r.replace(/^[\s﻿\-]+/, '').trim())
            .filter(Boolean)
            .map((n) => path.basename(n));
    }
    const prevSet = new Set(previous.map((n) => n.toLowerCase()));
    const stubSet = new Set(stubs.map((n) => n.toLowerCase()));
    const added = stubs.filter((s) => !prevSet.has(s.toLowerCase()));
    const removed = previous.filter((p) => !stubSet.has(p.toLowerCase()));

    console.log(`Scanned ${files.length} markdown pages.`);
    console.log(`Detected ${stubs.length} stub pages.`);
    console.log(`  + ${added.length} new (were missing / mis-flagged as complete)`);
    console.log(`  - ${removed.length} dropped (no longer a stub, or stale "ghost" entry)`);

    // Derive the deduped slug set for the static JSON fallback.
    const slugs = [...new Set(stubs.map(slugFromFilename).filter(Boolean))].sort((a, b) =>
        a.localeCompare(b)
    );
    const rel = (p) => path.relative(path.join(__dirname, '..'), p);

    if (dryRun) {
        if (added.length) console.log('\nWould add:\n  ' + added.join('\n  '));
        if (removed.length) console.log('\nWould remove:\n  ' + removed.join('\n  '));
        console.log(`\nWould write ${stubs.length} entries to ${rel(LIST_FILE)}`);
        console.log(`Would write ${slugs.length} slugs to ${rel(SLUGS_JSON)}`);
        console.log('\n(dry run — nothing written)');
        return;
    }

    const out = stubs.map((n) => `- ${n}`).join('\n') + '\n';
    fs.writeFileSync(LIST_FILE, out);
    console.log(`\nWrote ${stubs.length} entries to ${rel(LIST_FILE)}`);

    fs.writeFileSync(
        SLUGS_JSON,
        JSON.stringify({ generated: new Date().toISOString(), count: slugs.length, slugs }) + '\n'
    );
    console.log(`Wrote ${slugs.length} slugs to ${rel(SLUGS_JSON)}`);
}

main();
