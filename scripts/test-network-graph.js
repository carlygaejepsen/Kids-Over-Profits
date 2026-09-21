#!/usr/bin/env node
/**
 * Network graph invariants
 *
 * Guards js/data/network/graph.json against the ways this pipeline can quietly
 * go wrong: a truncated CSV export, an override that renames a kind out of the
 * allowed set, a facility id that no longer exists, an edge pointing at a node
 * that was dropped.
 *
 * Usage: node scripts/test-network-graph.js
 * Run it after every `node scripts/build-network-graph.js`.
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const GRAPH_FILE = path.join(ROOT, 'js', 'data', 'network', 'graph.json');
const SQLITE_FILE = path.join(ROOT, 'tmp', 'prod.sqlite');

/* Floors, not targets. The board only ever grows; a build that comes in under
 * these almost certainly read a truncated or half-written CSV. Raise them when
 * the board genuinely gains a lot of rows. */
const MIN_NODES = 850;
const MIN_EDGES = 1200;
const MIN_FACILITY_MATCHES = 300;

const KINDS = ['person', 'facility', 'parent', 'association', 'government', 'church', 'other'];
const CATEGORIES = [
    'corporate', 'family', 'survivor', 'referral', 'board',
    'leadership', 'clinical', 'admissions', 'staff', 'membership', 'other', 'unknown'
];
const DIRECTIONS = ['none', 'acquirer', 'renamed'];
/* The build splits the board's "closed or rebranded" in two (2b.5). */
const STATUSES = ['', 'open', 'closed', 'rebranded'];
/* "1971-2004", "from 1998", "until 2004" or a single year (2b.6). */
const YEARS_RE = /^(?:(?:1[89]|20)\d\d(?:-(?:1[89]|20)\d\d)?|(?:from|until) (?:1[89]|20)\d\d)$/;
const DROPPED_REGIONS = ['Key'];

const failures = [];
const notes = [];

function check(condition, message) {
    if (!condition) failures.push(message);
}

function run() {
    if (!fs.existsSync(GRAPH_FILE)) {
        failures.push('graph.json is missing. Run node scripts/build-network-graph.js first.');
        return;
    }
    const graph = JSON.parse(fs.readFileSync(GRAPH_FILE, 'utf8'));
    const nodes = graph.nodes || [];
    const edges = graph.edges || [];

    check(nodes.length >= MIN_NODES, 'only ' + nodes.length + ' nodes, expected at least ' + MIN_NODES);
    check(edges.length >= MIN_EDGES, 'only ' + edges.length + ' edges, expected at least ' + MIN_EDGES);

    /* ids unique and referenced */
    const byId = new Map();
    nodes.forEach(function (node) {
        if (byId.has(node.id)) failures.push('duplicate node id: ' + node.id);
        byId.set(node.id, node);
    });

    nodes.forEach(function (node) {
        check(Boolean(node.name), 'node ' + node.id + ' has no name');
        check(KINDS.indexOf(node.kind) !== -1, 'node ' + node.id + ' has kind "' + node.kind + '"');
        check(Array.isArray(node.regions) && node.regions.length > 0, 'node ' + node.id + ' has no region');
        (node.regions || []).forEach(function (region) {
            check(DROPPED_REGIONS.indexOf(region) === -1, 'node ' + node.id + ' is in dropped frame ' + region);
        });
        check(typeof node.board === 'object' && Number.isFinite(node.board.x) && Number.isFinite(node.board.y),
            'node ' + node.id + ' has no usable board position');
        check(node.rawStatus === undefined && node.kindWeak === undefined,
            'node ' + node.id + ' still carries a build-only field');
        check(STATUSES.indexOf(node.status) !== -1, 'node ' + node.id + ' has status "' + node.status + '"');
        check(node.years === undefined || YEARS_RE.test(node.years),
            'node ' + node.id + ' has years "' + node.years + '"');
        check(node.years === undefined || node.kind !== 'person',
            'person ' + node.id + ' carries years of operation');
        check(node.deaths === undefined || (Number.isInteger(node.deaths) && node.deaths > 0),
            'node ' + node.id + ' has death count "' + node.deaths + '"');
    });

    /* Where one end of a rebrand is still open, the other end is the name
     * that was dropped, so it reads "rebranded", never plain "closed". The
     * statuses override is the one sanctioned exception. */
    let overrides = {};
    try {
        overrides = JSON.parse(fs.readFileSync(path.join(ROOT, 'js', 'data', 'network', 'network-overrides.json'), 'utf8'));
    } catch (err) { overrides = {}; }
    const overridden = overrides.statuses || {};
    edges.forEach(function (edge) {
        if (edge.direction !== 'renamed') return;
        const a = byId.get(edge.source);
        const b = byId.get(edge.target);
        if (!a || !b) return;
        [[a, b], [b, a]].forEach(function (pair) {
            if (pair[0].status !== 'open' || overridden[pair[1].name]) return;
            check(pair[1].status !== 'closed',
                'rebrand ' + edge.id + ': ' + pair[1].name + ' is "closed" though ' + pair[0].name + ' carried on');
        });
    });
    notes.push(nodes.filter(function (n) { return n.status === 'rebranded'; }).length + ' rebranded, ' +
        nodes.filter(function (n) { return n.years; }).length + ' with years, ' +
        nodes.filter(function (n) { return n.deaths; }).length + ' with deaths');

    const edgeIds = new Set();
    edges.forEach(function (edge) {
        if (edgeIds.has(edge.id)) failures.push('duplicate edge id: ' + edge.id);
        edgeIds.add(edge.id);
        check(byId.has(edge.source), 'edge ' + edge.id + ' points at missing source ' + edge.source);
        check(byId.has(edge.target), 'edge ' + edge.id + ' points at missing target ' + edge.target);
        check(edge.source !== edge.target, 'edge ' + edge.id + ' is a self-loop');
        check(CATEGORIES.indexOf(edge.category) !== -1, 'edge ' + edge.id + ' has category "' + edge.category + '"');
        check(DIRECTIONS.indexOf(edge.direction) !== -1, 'edge ' + edge.id + ' has direction "' + edge.direction + '"');
        check(Array.isArray(edge.roles), 'edge ' + edge.id + ' has no roles array');
        /* The board's own edges carry no provenance; the build's additions
         * say where they came from (2b.12, 2b.11). */
        check(edge.provenance === undefined || edge.provenance === 'profile' || edge.provenance === 'staff-movement' ||
            edge.provenance === 'staff-list',
            'edge ' + edge.id + ' has provenance "' + edge.provenance + '"');
        check(edge.provenance !== 'staff-movement' || /moved|worked at/.test(edge.raw || ''),
            'staff-movement edge ' + edge.id + ' does not say who moved');
        check(edge.provenance !== 'staff-list' || /\((staff list|Sequel\/TSI\/YSI\/Vivant staff sheet)\)$/.test(edge.raw || ''),
            'staff-list edge ' + edge.id + ' does not say where it came from');
    });

    /* a person is never the target of a person-to-organisation edge */
    edges.forEach(function (edge) {
        const source = byId.get(edge.source), target = byId.get(edge.target);
        if (!source || !target) return;
        check(!(source.kind !== 'person' && target.kind === 'person'),
            'edge ' + edge.id + ' stores the organisation before the person');
    });

    /* degree agrees with the edge list */
    const degree = new Map();
    edges.forEach(function (edge) {
        degree.set(edge.source, (degree.get(edge.source) || 0) + 1);
        degree.set(edge.target, (degree.get(edge.target) || 0) + 1);
    });
    nodes.forEach(function (node) {
        check(node.degree === (degree.get(node.id) || 0),
            'node ' + node.id + ' records degree ' + node.degree + ' but has ' + (degree.get(node.id) || 0) + ' edges');
    });

    /* facility links still resolve */
    const linked = nodes.filter(function (node) { return node.facilityId !== null && node.facilityId !== undefined; });
    check(linked.length >= MIN_FACILITY_MATCHES,
        'only ' + linked.length + ' facility links, expected at least ' + MIN_FACILITY_MATCHES);

    if (fs.existsSync(SQLITE_FILE)) {
        try {
            const { DatabaseSync } = require('node:sqlite');
            const db = new DatabaseSync(SQLITE_FILE, { readOnly: true });
            const live = new Set(db.prepare('SELECT id FROM facilities_v2').all().map(function (r) { return r.id; }));
            db.close();
            linked.forEach(function (node) {
                check(live.has(node.facilityId),
                    'node ' + node.id + ' links to facility ' + node.facilityId + ', which is not in facilities_v2');
            });
        } catch (err) {
            notes.push('could not verify facility ids: ' + err.message);
        }
    } else {
        notes.push('tmp/prod.sqlite absent, facility ids not verified');
    }

    notes.push(nodes.length + ' nodes, ' + edges.length + ' edges, ' + linked.length + ' facility links');

    /* What the board is missing and the overrides add has to arrive. A name
     * typed a hair differently only shows up in the QA report otherwise, and
     * the additions are now large enough to lose a row in. */
    const byName = new Map(nodes.map(function (n) { return [n.name, n]; }));
    const drawn = new Set();
    edges.forEach(function (edge) {
        const a = byId.get(edge.source);
        const b = byId.get(edge.target);
        if (a && b) {
            drawn.add(a.name + ' -> ' + b.name);
            drawn.add(b.name + ' -> ' + a.name);
        }
    });
    (overrides.nodes || []).forEach(function (node) {
        check(byName.has(node.name), 'overrides.nodes: ' + node.name + ' never reached the graph');
        check(byName.has(node.near), 'overrides.nodes: ' + node.name + ' is placed near "' + node.near + '", which is not a node');
    });
    (overrides.edges || []).forEach(function (edge) {
        check(drawn.has(edge.from + ' -> ' + edge.to),
            'overrides.edges: ' + edge.from + ' -> ' + edge.to + ' was not drawn');
    });
    notes.push((overrides.nodes || []).length + ' override nodes and ' +
        (overrides.edges || []).length + ' override edges all drawn');
}

run();

notes.forEach(function (note) { console.log('  note: ' + note); });
if (failures.length === 0) {
    console.log('network graph invariants: PASS');
    process.exit(0);
}
console.error('network graph invariants: FAIL');
failures.slice(0, 40).forEach(function (failure) { console.error('  - ' + failure); });
if (failures.length > 40) console.error('  ... and ' + (failures.length - 40) + ' more');
process.exit(1);
