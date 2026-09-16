#!/usr/bin/env node
/**
 * Parity check: the admin form's JS port of the v2 facility normalizer
 * (js/data-form-modules/data-normalizer.js, facilityToV2) must produce the
 * same document as the PHP original (inc/facility-store.php,
 * kop_facility_normalize) for every facility copy in a database dump.
 *
 * Usage:
 *   php scripts/normalize-dump.php --dump <dir> --emit copies.jsonl
 *   node scripts/check-facility-normalizer-parity.js copies.jsonl [--show 5]
 *
 * It also round-trips each PHP document through facilityFromV2 and back
 * through facilityToV2, which must be lossless for the fields the form edits.
 *
 * Exit code 0 when every copy matches.
 */

const fs = require('fs');
const path = require('path');
const readline = require('readline');
const vm = require('vm');

const args = process.argv.slice(2);
const input = args.find((a) => !a.startsWith('--'));
const showIdx = args.indexOf('--show');
const SHOW = showIdx !== -1 ? parseInt(args[showIdx + 1], 10) || 5 : 5;

if (!input || !fs.existsSync(input)) {
    console.error('Usage: node scripts/check-facility-normalizer-parity.js <copies.jsonl> [--show N]');
    process.exit(2);
}

// Load the browser module into a sandbox with a minimal window.
const sandbox = { window: {}, console };
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'data-form-modules', 'data-normalizer.js'), 'utf8');
vm.runInContext(source, sandbox);
const N = sandbox.window.KOP_DataNormalizer;
if (!N || typeof N.facilityToV2 !== 'function') {
    console.error('data-normalizer.js did not expose facilityToV2');
    process.exit(1);
}

/** Canonical JSON: sorted keys, volatile fields removed. */
function canonical(value) {
    if (Array.isArray(value)) return value.map(canonical);
    if (value && typeof value === 'object') {
        const out = {};
        Object.keys(value).sort().forEach((k) => {
            if (k === 'migratedAt') return;
            out[k] = canonical(value[k]);
        });
        return out;
    }
    return value;
}

/** First differing path between two canonical values. */
function firstDiff(a, b, where = '') {
    if (a === b) return null;
    if (typeof a !== typeof b || a === null || b === null || Array.isArray(a) !== Array.isArray(b)) {
        return { path: where || '(root)', php: a, js: b };
    }
    if (typeof a !== 'object') return { path: where || '(root)', php: a, js: b };
    // An empty PHP array decodes as [] where JS keeps {}: same thing.
    const emptyA = Array.isArray(a) ? a.length === 0 : Object.keys(a).length === 0;
    const emptyB = Array.isArray(b) ? b.length === 0 : Object.keys(b).length === 0;
    if (emptyA && emptyB) return null;
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
    for (const k of keys) {
        const d = firstDiff(a[k], b[k], where ? `${where}.${k}` : k);
        if (d) return d;
    }
    return null;
}

async function main() {
    const rl = readline.createInterface({ input: fs.createReadStream(input), crlfDelay: Infinity });
    let total = 0;
    let mismatches = 0;
    let roundTripMismatches = 0;
    const byPath = {};
    const examples = [];

    for await (const line of rl) {
        if (!line.trim()) continue;
        total++;
        const row = JSON.parse(line);
        const php = canonical(row.doc);
        const js = canonical(JSON.parse(JSON.stringify(N.facilityToV2(row.raw, row.opts))));

        const diff = firstDiff(php, js);
        if (diff) {
            mismatches++;
            byPath[diff.path] = (byPath[diff.path] || 0) + 1;
            if (examples.length < SHOW) examples.push({ source: row.source, ...diff });
        }

        // Round trip: v2 -> legacy (form) -> v2 must give the same document.
        const back = canonical(JSON.parse(JSON.stringify(
            N.facilityToV2(N.facilityFromV2(row.doc), { facility_id: row.doc.facility_id })
        )));
        const expected = JSON.parse(JSON.stringify(php));
        // Row-context fields are not part of the facility and do not round trip.
        for (const doc of [back, expected]) {
            doc.provenance.source = '';
            doc.provenance.uniqueName = '';
            doc.provenance.legacyIds = [];
            doc.provenance.kopProfileVersion = null;
            doc.provenance.sourceProjectId = null;
            doc.provenance.linkedFromRef = false;
        }
        const rtDiff = firstDiff(expected, back);
        if (rtDiff) {
            roundTripMismatches++;
            const key = 'round trip: ' + rtDiff.path;
            byPath[key] = (byPath[key] || 0) + 1;
            if (examples.length < SHOW * 2) examples.push({ source: row.source + ' (round trip)', ...rtDiff });
        }
    }

    console.log(`Copies compared:        ${total}`);
    console.log(`PHP vs JS mismatches:   ${mismatches}`);
    console.log(`Round-trip mismatches:  ${roundTripMismatches}`);
    const paths = Object.entries(byPath).sort((a, b) => b[1] - a[1]);
    if (paths.length) {
        console.log('\nBy first differing path:');
        paths.forEach(([p, n]) => console.log(`  ${String(n).padStart(6)}  ${p}`));
        console.log('\nExamples:');
        examples.forEach((e) => {
            console.log(`  ${e.source}  ${e.path}`);
            console.log(`    php: ${JSON.stringify(e.php)}`);
            console.log(`    js:  ${JSON.stringify(e.js)}`);
        });
    }
    process.exit(mismatches === 0 && roundTripMismatches === 0 ? 0 : 1);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
