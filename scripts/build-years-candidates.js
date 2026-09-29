#!/usr/bin/env node
/**
 * Build js/data/network/years-candidates.json from the research results.
 *
 *   node scripts/build-years-candidates.js [dir]
 *
 * dir (default tmp/years-research) holds batch-N.json, the undated names
 * sent out for research, and result-N.json, what came back: for each name
 * a start and end year, still operating or not, a confidence, sources with
 * a quoted sentence each, and a note.
 *
 * The owner reviews the file on KOP Data Tools > Map Years
 * (inc/network-years.php). Nothing reaches the map until a year is
 * accepted there. This script holds each proposal to its sources: a year
 * that no quote contains is dropped and the note says so, a name that has
 * since gained years in graph.json is left out, and a year outside 1800 to
 * this year is dropped.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const dir = path.resolve(ROOT, process.argv[2] || 'tmp/years-research');
const OUT = path.join(ROOT, 'js', 'data', 'network', 'years-candidates.json');
const graph = JSON.parse(fs.readFileSync(path.join(ROOT, 'js', 'data', 'network', 'graph.json'), 'utf8'));
const byId = new Map(graph.nodes.map((n) => [n.id, n]));
const THIS_YEAR = new Date().getFullYear();

const asked = new Map();
const results = new Map();
fs.readdirSync(dir).sort().forEach((file) => {
    const list = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
    if (/^batch-\d+\.json$/.test(file)) list.forEach((item) => asked.set(item.id, item));
    if (/^result-\d+\.json$/.test(file)) list.forEach((item) => { if (item && item.id) results.set(item.id, item); });
});

const stats = { asked: asked.size, answered: 0, start: 0, end: 0, nothing: 0, dropped: 0, dated: 0, missing: [] };
const out = [];
asked.forEach((item, id) => {
    const node = byId.get(id);
    if (!node) return;
    if (node.years) { stats.dated++; return; }
    const r = results.get(id);
    if (!r) { stats.missing.push(item.name); return; }
    stats.answered++;
    const sources = (Array.isArray(r.sources) ? r.sources : [])
        .filter((s) => s && /^https?:\/\//.test(String(s.url || '')))
        .map((s) => ({ url: String(s.url), quote: String(s.quote || '').replace(/\s+/g, ' ').trim().slice(0, 400) }))
        .slice(0, 4);
    const quoted = sources.map((s) => s.quote).join(' ');
    const notes = [String(r.note || '').trim()].filter(Boolean);
    const year = (value, label) => {
        const y = Number(value) || 0;
        if (!y) return null;
        if (y < 1800 || y > THIS_YEAR) { notes.push(label + ' ' + y + ' dropped: out of range.'); stats.dropped++; return null; }
        if (quoted.indexOf(String(y)) === -1) { notes.push(label + ' ' + y + ' dropped: no quote contains it.'); stats.dropped++; return null; }
        return y;
    };
    let start = year(r.start, 'Opening year');
    let end = r.stillOperating === true ? null : year(r.end, 'Closing year');
    if (start && end && end < start) { notes.push('Closing year ' + end + ' is before the opening year; dropped.'); end = null; stats.dropped++; }
    if (start) stats.start++;
    if (end) stats.end++;
    if (!start && !end) stats.nothing++;
    const confidence = ['high', 'medium', 'low'].indexOf(r.confidence) !== -1 ? r.confidence : 'low';
    out.push({
        id,
        name: node.name,
        kind: node.kind,
        place: item.place || '',
        start,
        end,
        stillOperating: r.stillOperating === true,
        confidence: start || end ? confidence : 'low',
        sources,
        note: notes.join(' ')
    });
});

out.sort((a, b) => a.name.localeCompare(b.name));
fs.writeFileSync(OUT, JSON.stringify(out, null, 1) + '\n');
console.log('years-candidates.json: ' + out.length + ' names (' + stats.asked + ' asked, ' + stats.dated + ' dated since)');
console.log('  opening year: ' + stats.start + ', closing year: ' + stats.end + ', nothing found: ' + stats.nothing +
    ', years dropped for want of a quote or range: ' + stats.dropped);
if (stats.missing.length) console.log('  no result yet for ' + stats.missing.length + ': ' + stats.missing.slice(0, 8).join(', '));
