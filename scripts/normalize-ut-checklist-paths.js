#!/usr/bin/env node
/**
 * Normalize Utah checklist PDF paths in the inspection JSON.
 *
 * The 18 Mar 2026 checklist export stored every
 * inspections[].checklists[].pdf_file as an absolute Windows path from the
 * scraper machine. The readers (inc/rest-api.php and
 * js/inspections/ut_reports.js) basename the value, so links kept working,
 * but the raw strings shipped in the served JSON and crawlers resolved them
 * as URLs. This rewrites each one to the relative form the other Utah files
 * already use: checklists/<basename>.
 *
 * Usage:
 *   node scripts/normalize-ut-checklist-paths.js            # rewrite in place
 *   node scripts/normalize-ut-checklist-paths.js --dry-run  # report only
 *
 * Files: js/data/ut_checklists/ut_reports*.json and js/data/ut_reports*.json.
 * Indentation of each file is preserved (2-space files stay 2-space, compact
 * files stay compact).
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const DRY_RUN = process.argv.includes('--dry-run');
const BACKSLASH = String.fromCharCode(92);

function listTargets() {
    const dirs = [
        path.join(ROOT, 'js', 'data', 'ut_checklists'),
        path.join(ROOT, 'js', 'data')
    ];
    const files = [];
    for (const dir of dirs) {
        if (!fs.existsSync(dir)) continue;
        for (const name of fs.readdirSync(dir)) {
            if (name.startsWith('ut_reports') && name.endsWith('.json')) {
                files.push(path.join(dir, name));
            }
        }
    }
    return files;
}

function basenameAny(value) {
    return value.split('/').pop().split(BACKSLASH).pop();
}

function normalize(value) {
    if (typeof value !== 'string' || value === '') return value;
    const base = basenameAny(value);
    if (!base) return value;
    return 'checklists/' + base;
}

function walk(node, onChange) {
    if (Array.isArray(node)) {
        node.forEach(item => walk(item, onChange));
        return;
    }
    if (!node || typeof node !== 'object') return;
    for (const key of Object.keys(node)) {
        if (key === 'pdf_file') {
            const next = normalize(node[key]);
            if (next !== node[key]) {
                onChange(node[key], next);
                node[key] = next;
            }
        } else {
            walk(node[key], onChange);
        }
    }
}

function detectIndent(text) {
    // The first nested line (right after the opening bracket) carries one
    // indent level; deeper lines would over-count.
    const second = text.split('\n')[1] || '';
    const m = second.match(/^( +)\S/);
    return m ? m[1].length : 0;
}

let totalChanges = 0;
for (const file of listTargets()) {
    const raw = fs.readFileSync(file, 'utf8');
    const data = JSON.parse(raw);
    let changes = 0;
    let sample = null;
    walk(data, (from, to) => {
        changes += 1;
        if (!sample) sample = { from, to };
    });
    const rel = path.relative(ROOT, file);
    if (changes === 0) {
        console.log(rel + ': no changes');
        continue;
    }
    totalChanges += changes;
    console.log(rel + ': ' + changes + ' pdf_file value(s) ' + (DRY_RUN ? 'would change' : 'changed'));
    console.log('  e.g. ' + sample.from + '  ->  ' + sample.to);
    if (DRY_RUN) continue;
    const indent = detectIndent(raw);
    const eol = raw.includes('\r\n') ? '\r\n' : '\n';
    let out = JSON.stringify(data, null, indent || 0);
    if (eol !== '\n') out = out.split('\n').join(eol);
    if (raw.endsWith('\n')) out += eol;
    fs.writeFileSync(file, out, 'utf8');
}
console.log((DRY_RUN ? 'Dry run. ' : '') + 'Total: ' + totalChanges + ' value(s).');
