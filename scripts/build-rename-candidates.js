#!/usr/bin/env node
/**
 * Build js/data/network/rename-candidates.json from the rename research.
 *
 *   node scripts/build-rename-candidates.js [dir]
 *
 * dir (default tmp/rename-research) holds batch-N.json, the rename families
 * sent out for research (each rename keyed "earlierId>laterId" as the board
 * drew it), and result-N.json, what came back: for each rename the year of
 * the rename, whether the board had the order backwards, a confidence,
 * sources with a quoted sentence each, and a note.
 *
 * The owner reviews them on KOP Data Tools > Map Renames
 * (inc/network-renames.php); nothing reaches the map until a rename is
 * saved there. As with the map years (scripts/build-years-candidates.js),
 * each proposal is held to its sources: a year no quote contains is dropped
 * and the note says so, and a rename no longer in graph.json is left out.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const dir = path.resolve(ROOT, process.argv[2] || 'tmp/rename-research');
const OUT = path.join(ROOT, 'js', 'data', 'network', 'rename-candidates.json');
const graph = JSON.parse(fs.readFileSync(path.join(ROOT, 'js', 'data', 'network', 'graph.json'), 'utf8'));
const THIS_YEAR = new Date().getFullYear();

const lines = new Set(graph.edges
    .filter((e) => (e.roles || []).indexOf('rebrand') !== -1)
    .map((e) => e.source + '>' + e.target));

const asked = new Set();
const results = new Map();
fs.readdirSync(dir).sort().forEach((file) => {
    const list = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
    if (/^batch-\d+\.json$/.test(file)) list.forEach((f) => (f.renames || []).forEach((r) => asked.add(r.key)));
    if (/^result-\d+\.json$/.test(file)) list.forEach((r) => { if (r && r.key) results.set(r.key, r); });
});

const stats = { asked: asked.size, answered: 0, year: 0, swapped: 0, dropped: 0, gone: 0, missing: [] };
const out = [];
asked.forEach((key) => {
    if (!lines.has(key)) { stats.gone++; return; }
    const r = results.get(key);
    if (!r) { stats.missing.push(key); return; }
    stats.answered++;
    const sources = (Array.isArray(r.sources) ? r.sources : [])
        .filter((s) => s && /^https?:\/\//.test(String(s.url || '')))
        // The site's own pages repeat what the board says; they cannot confirm it.
        .filter((s) => !/^https?:\/\/(www\.)?kidsoverprofits\.org\//i.test(String(s.url)))
        .map((s) => ({ url: String(s.url), quote: String(s.quote || '').replace(/\s+/g, ' ').trim().slice(0, 400) }))
        .slice(0, 4);
    const notes = [String(r.note || '').replace(/\s+/g, ' ').trim()].filter(Boolean);
    let year = Number(r.year) || null;
    if (year && (year < 1800 || year > THIS_YEAR)) {
        notes.push('Year ' + year + ' dropped: out of range.');
        year = null; stats.dropped++;
    } else if (year && sources.map((s) => s.quote).join(' ').indexOf(String(year)) === -1) {
        notes.push('Year ' + year + ' dropped: no quote contains it.');
        year = null; stats.dropped++;
    }
    if (year) stats.year++;
    // The card starts swapped on the research's word, so that word needs a quote behind it.
    let swapped = !!r.swapped;
    if (swapped && !sources.some((s) => s.quote)) {
        notes.push('Looked backwards to the researcher, but with no quoted source; order left as drawn.');
        swapped = false;
    }
    if (swapped) stats.swapped++;
    const confidence = ['high', 'medium', 'low'].indexOf(r.confidence) !== -1 ? r.confidence : 'low';
    out.push({ key, year, swapped, confidence: year ? confidence : 'low', sources, note: notes.join(' ') });
});

out.sort((a, b) => a.key.localeCompare(b.key));
fs.writeFileSync(OUT, JSON.stringify(out, null, 1) + '\n');
console.log(`${stats.asked} renames asked, ${stats.answered} answered, ${stats.year} with a sourced year, `
    + `${stats.swapped} found backwards, ${stats.dropped} years dropped, ${stats.gone} no longer on the map.`);
if (stats.missing.length) console.log('No result for: ' + stats.missing.join(', '));
console.log('Wrote ' + path.relative(ROOT, OUT));
