#!/usr/bin/env node
/**
 * Network layout invariants
 *
 * Guards js/data/network/layout.json against the ways a force simulation can
 * quietly produce something unusable: a node that drifted to NaN, a pair of
 * nodes sitting on top of each other so one can never be clicked, a blown-up
 * bounding box that leaves the map mostly empty space, or a layout left over
 * from an older graph.json.
 *
 * Usage: node scripts/test-network-layout.js
 * Run it after every `node scripts/build-network-layout.js`.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');
const GRAPH_FILE = path.join(DATA_DIR, 'graph.json');
const LAYOUT_FILE = path.join(DATA_DIR, 'layout.json');

/* Must match build-network-layout.js. Duplicated rather than imported so the
 * test fails loudly if the build changes them without thinking. */
const MIN_RADIUS = 4;
const MAX_RADIUS = 16;

/* Positions are rounded to one decimal, so two nodes the collision force
 * separated exactly can land a rounding step inside each other. Anything
 * worse than this is the simulation failing, not arithmetic. */
const OVERLAP_TOLERANCE = 0.5;

/* The board export spans about 2000 x 1600. The forces spread that out, but
 * a run that escapes this box has a force misconfigured, not a big graph. */
const MAX_EXTENT = 12000;

const failures = [];
const notes = [];

function check(condition, message) {
    if (!condition) failures.push(message);
}

function load(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function run() {
    if (!fs.existsSync(LAYOUT_FILE)) {
        failures.push('js/data/network/layout.json is missing, run scripts/build-network-layout.js');
        return;
    }
    if (!fs.existsSync(GRAPH_FILE)) {
        failures.push('js/data/network/graph.json is missing, run scripts/build-network-graph.js');
        return;
    }

    const layout = load(LAYOUT_FILE);
    const graph = load(GRAPH_FILE);

    check(layout && typeof layout === 'object' && layout.positions && layout.meta,
        'layout.json has no meta/positions shape');
    if (failures.length) return;

    /* A layout settled from a different graph puts nodes in the wrong place
     * without any obvious symptom, so treat it as a hard failure. */
    check(layout.meta.sourceHash === graph.meta.sourceHash,
        'layout was built from graph ' + layout.meta.sourceHash +
        ' but graph.json is now ' + graph.meta.sourceHash +
        ', rerun scripts/build-network-layout.js');

    /* Exactly the graph's nodes, no more and no fewer. */
    const graphIds = new Set(graph.nodes.map(function (n) { return n.id; }));
    const layoutIds = Object.keys(layout.positions);
    const missing = graph.nodes.filter(function (n) { return !layout.positions[n.id]; });
    const extra = layoutIds.filter(function (id) { return !graphIds.has(id); });
    check(missing.length === 0,
        missing.length + ' graph nodes have no position, first: ' +
        missing.slice(0, 3).map(function (n) { return n.id; }).join(', '));
    check(extra.length === 0,
        extra.length + ' positions belong to nodes not in the graph, first: ' +
        extra.slice(0, 3).join(', '));

    const points = [];
    layoutIds.forEach(function (id) {
        const p = layout.positions[id];
        const ok = p && Number.isFinite(p.x) && Number.isFinite(p.y) && Number.isFinite(p.r);
        check(ok, 'node ' + id + ' has a non-finite position: ' + JSON.stringify(p));
        if (!ok) return;
        check(p.r >= MIN_RADIUS - 0.05 && p.r <= MAX_RADIUS + 0.05,
            'node ' + id + ' has radius ' + p.r + ', outside ' + MIN_RADIUS + '..' + MAX_RADIUS);
        points.push({ id: id, x: p.x, y: p.y, r: p.r });
    });

    /* Every node must be clickable, which means no node buried under another.
     * O(n squared) over 907 nodes is a few hundred thousand comparisons and
     * runs in well under a second. */
    let overlaps = 0;
    let worst = null;
    for (let i = 0; i < points.length; i++) {
        for (let j = i + 1; j < points.length; j++) {
            const a = points[i];
            const b = points[j];
            const gap = Math.hypot(a.x - b.x, a.y - b.y) - (a.r + b.r);
            if (gap < -OVERLAP_TOLERANCE) {
                overlaps++;
                if (!worst || gap < worst.gap) worst = { a: a.id, b: b.id, gap: gap };
            }
        }
    }
    check(overlaps === 0,
        overlaps + ' node pairs overlap, worst is ' +
        (worst ? worst.a + ' and ' + worst.b + ' by ' + Math.abs(worst.gap).toFixed(1) : 'unknown'));

    /* A sane bounding box: real area, and not flung to the horizon. */
    const extent = layout.meta.extent || {};
    const width = extent.maxX - extent.minX;
    const height = extent.maxY - extent.minY;
    check(Number.isFinite(width) && width > 100, 'layout extent width is ' + width);
    check(Number.isFinite(height) && height > 100, 'layout extent height is ' + height);
    check(width < MAX_EXTENT && height < MAX_EXTENT,
        'layout extent is ' + Math.round(width) + ' x ' + Math.round(height) +
        ', larger than the ' + MAX_EXTENT + ' sanity bound');

    /* The recorded extent must actually contain the points it describes. */
    if (points.length) {
        const minX = Math.min.apply(null, points.map(function (p) { return p.x; }));
        const maxX = Math.max.apply(null, points.map(function (p) { return p.x; }));
        const minY = Math.min.apply(null, points.map(function (p) { return p.y; }));
        const maxY = Math.max.apply(null, points.map(function (p) { return p.y; }));
        check(Math.abs(minX - extent.minX) < 1 && Math.abs(maxX - extent.maxX) < 1 &&
            Math.abs(minY - extent.minY) < 1 && Math.abs(maxY - extent.maxY) < 1,
            'meta.extent does not match the positions it describes');
    }

    const graphIsolated = graph.nodes.filter(function (n) { return n.isolated; }).length;
    check(layout.meta.isolated === graphIsolated,
        'layout says ' + layout.meta.isolated + ' isolated nodes, graph has ' + graphIsolated);
    check(layout.meta.nodes === graph.nodes.length,
        'layout says ' + layout.meta.nodes + ' nodes, graph has ' + graph.nodes.length);

    notes.push(points.length + ' nodes positioned, ' + layout.meta.isolated + ' isolated, ' +
        Math.round(width) + ' x ' + Math.round(height) + ' extent, ' +
        layout.meta.ticks + ' ticks');
}

run();

notes.forEach(function (note) { console.log('  note: ' + note); });
if (failures.length === 0) {
    console.log('network layout invariants: PASS');
    process.exit(0);
}
console.error('network layout invariants: FAIL');
failures.slice(0, 40).forEach(function (failure) { console.error('  - ' + failure); });
if (failures.length > 40) console.error('  ... and ' + (failures.length - 40) + ' more');
process.exit(1);
