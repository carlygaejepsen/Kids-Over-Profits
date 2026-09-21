#!/usr/bin/env node
/**
 * Build Network Layout
 *
 * Runs the force simulation once, here, and writes settled positions to
 * layout.json. The map page then paints a finished picture on first load
 * instead of watching 907 nodes shake themselves apart for three seconds.
 *
 * Doing it here rather than in the browser buys three things: the page is
 * still under prefers-reduced-motion with nothing to disable, filtering can
 * hide nodes without the survivors drifting, and phones do no physics.
 *
 * Architecture:
 * - js/data/network/graph.json   = input, built by build-network-graph.js
 * - js/data/network/layout.json  = build output, committed
 * - js/vendor/d3-force.bundle.min.js = the simulation, loaded in a vm
 *
 * The vendor bundle is a browser UMD file. It is evaluated in a bare vm
 * context so it takes its global branch and attaches `d3` there; the
 * CommonJS branch would require d3-quadtree and this repo has no
 * node_modules. Rebuild the bundle from these four modules on cdn.jsdelivr,
 * concatenated in this order: d3-dispatch, d3-quadtree, d3-timer, d3-force.
 *
 * Positions are reproducible. Every node is seeded from its Miro coordinate
 * so the simulation never calls its random source for placement, ticks are
 * run by hand rather than on a timer, and output is rounded to one decimal.
 * Rebuilding without changing graph.json rewrites the same bytes.
 *
 * Additions do not move what is already placed. Every node the last
 * layout.json positioned is pinned where it was, at the size it was, and only
 * the new ones are simulated, starting from the middle of whatever they
 * connect to that is already on the map. Every opened view starts its own
 * settle from these positions, so a fresh layout after adding a handful of
 * names reshuffled every view on the map, including the many that gained
 * nothing (2026-09-21). Run with --fresh after a new board export, when the
 * whole picture should be settled again from the Miro coordinates.
 *
 * Usage: node scripts/build-network-layout.js [--fresh]
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');
const GRAPH_FILE = path.join(DATA_DIR, 'graph.json');
const OUTPUT_FILE = path.join(DATA_DIR, 'layout.json');
const BUNDLE_FILE = path.join(ROOT, 'js', 'vendor', 'd3-force.bundle.min.js');

/* Default alphaDecay brings alpha to alphaMin in almost exactly 300 ticks,
 * so this is the natural stopping point rather than an arbitrary one. */
const TICKS = 300;

/* How far apart a link wants its ends. Ownership is a tight, factual bond
 * and reads best as a short edge; an employment history is looser and needs
 * room, otherwise every facility a therapist ever worked at gets dragged
 * into one knot. */
const LINK_DISTANCE = {
    corporate: 45,
    family: 45,
    board: 70,
    leadership: 70,
    clinical: 100,
    admissions: 95,
    referral: 95,
    staff: 100,
    survivor: 95,
    other: 95,
    unknown: 95
};
const DEFAULT_DISTANCE = 95;

/* Radius bounds in layout units. The floor keeps a one-edge person clickable
 * on a phone; the ceiling stops WWASPS from becoming a planet. */
const MIN_RADIUS = 4;
const MAX_RADIUS = 16;

/* Weak anchors. Strong enough that the settled map still resembles the board
 * the research team knows by heart, weak enough that the forces can fix the
 * places where the board is just "wherever there was space". */
const BOARD_PULL = 0.05;
const CHAIN_PULL = 0.04;

/* Isolated nodes have no edges, so the simulation has nothing to say about
 * them. They get a tidy row under the map instead of a random scatter. */
const ISOLATED_GAP = 46;
const ISOLATED_MARGIN = 70;

function load(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function unref(handle) {
    if (handle && typeof handle.unref === 'function') handle.unref();
    return handle;
}

/* Load the browser bundle in a context with no module or exports binding,
 * which is what makes its UMD wrapper take the global branch.
 *
 * d3-force starts a d3-timer the moment a simulation is constructed, before
 * we get the chance to stop it, so the context has to offer the timer
 * globals a browser would. They are unref'd: nothing here should keep the
 * process alive once the ticks are done. requestAnimationFrame is
 * deliberately absent so d3-timer falls back to setTimeout. */
function loadD3() {
    if (!fs.existsSync(BUNDLE_FILE)) {
        throw new Error('missing vendor bundle at js/vendor/d3-force.bundle.min.js');
    }
    const context = vm.createContext({
        Date: Date,
        performance: { now: function () { return 0; } },
        clearTimeout: clearTimeout,
        clearInterval: clearInterval,
        setTimeout: function (fn, delay) {
            return unref(setTimeout(fn, delay));
        },
        setInterval: function (fn, delay) {
            return unref(setInterval(fn, delay));
        }
    });
    vm.runInContext(fs.readFileSync(BUNDLE_FILE, 'utf8'), context, { filename: 'd3-force.bundle.min.js' });
    if (!context.d3 || typeof context.d3.forceSimulation !== 'function') {
        throw new Error('vendor bundle did not export forceSimulation');
    }
    return context.d3;
}

/* Importance spans roughly 8k to 150k, so a linear radius would make almost
 * every node the same size. Log first, then stretch the observed range over
 * the radius range, so the sizes separate whatever scale the next board
 * export arrives on. */
function assignRadii(nodes) {
    const logs = nodes.map(function (n) { return Math.log(Math.max(1, n.importance || 1)); });
    const lo = Math.min.apply(null, logs);
    const hi = Math.max.apply(null, logs);
    const span = hi - lo;
    nodes.forEach(function (node, i) {
        const t = span > 0 ? (logs[i] - lo) / span : 0;
        node.r = MIN_RADIUS + t * (MAX_RADIUS - MIN_RADIUS);
    });
}

/* Pull every node gently back toward where the board author put it. */
function forceBoard(strength) {
    let nodes = [];
    function force(alpha) {
        const k = strength * alpha;
        for (let i = 0; i < nodes.length; i++) {
            const node = nodes[i];
            if (!node.board) continue;
            node.vx += (node.board.x - node.x) * k;
            node.vy += (node.board.y - node.y) * k;
        }
    }
    force.initialize = function (n) { nodes = n; };
    return force;
}

/* Pull each chain toward its own centre of mass so ownership groups read as
 * groups. Recomputed per tick because the centroid moves with them. */
function forceChain(strength) {
    let nodes = [];
    function force(alpha) {
        const sums = new Map();
        for (let i = 0; i < nodes.length; i++) {
            const node = nodes[i];
            if (!node.chain) continue;
            let acc = sums.get(node.chain);
            if (!acc) { acc = { x: 0, y: 0, n: 0 }; sums.set(node.chain, acc); }
            acc.x += node.x; acc.y += node.y; acc.n++;
        }
        const k = strength * alpha;
        for (let i = 0; i < nodes.length; i++) {
            const node = nodes[i];
            if (!node.chain) continue;
            const acc = sums.get(node.chain);
            if (!acc || acc.n < 2) continue;
            node.vx += (acc.x / acc.n - node.x) * k;
            node.vy += (acc.y / acc.n - node.y) * k;
        }
    }
    force.initialize = function (n) { nodes = n; };
    return force;
}

function round1(value) {
    return Math.round(value * 10) / 10;
}

function build(fresh) {
    const graph = load(GRAPH_FILE);
    const d3 = loadD3();
    const before = !fresh && fs.existsSync(OUTPUT_FILE) ? load(OUTPUT_FILE).positions || {} : {};

    /* Work on copies: d3 adds vx/vy and rewrites link endpoints to objects. */
    const all = graph.nodes.map(function (n) {
        const hasBoard = n.board && typeof n.board.x === 'number' && typeof n.board.y === 'number';
        return {
            id: n.id,
            chain: n.chain || '',
            importance: n.importance || 1,
            isolated: !!n.isolated,
            board: hasBoard ? { x: n.board.x, y: n.board.y } : null,
            x: hasBoard ? n.board.x : 0,
            y: hasBoard ? n.board.y : 0
        };
    });
    assignRadii(all);

    /* Pinned where the last layout left them. A new node starts at the middle
     * of its placed neighbours, nudged apart by its index so two newcomers
     * on the same neighbours do not start on top of each other; a new node
     * with none keeps its board seed. */
    const placedBefore = new Map();
    all.forEach(function (node) {
        const was = before[node.id];
        if (!was) return;
        node.x = node.fx = was.x;
        node.y = node.fy = was.y;
        node.r = was.r;
        placedBefore.set(node.id, node);
    });
    let added = 0;
    if (placedBefore.size) {
        const around = new Map();
        graph.edges.forEach(function (e) {
            [[e.source, e.target], [e.target, e.source]].forEach(function (pair) {
                if (placedBefore.has(pair[0]) || !placedBefore.has(pair[1])) return;
                if (!around.has(pair[0])) around.set(pair[0], []);
                around.get(pair[0]).push(placedBefore.get(pair[1]));
            });
        });
        all.forEach(function (node, i) {
            if (placedBefore.has(node.id)) return;
            added++;
            const near = around.get(node.id);
            if (!near) return;
            node.x = near.reduce(function (s, n) { return s + n.x; }, 0) / near.length + 12 * Math.cos(i);
            node.y = near.reduce(function (s, n) { return s + n.y; }, 0) / near.length + 12 * Math.sin(i);
        });
    }

    const simulated = all.filter(function (n) { return !n.isolated; });
    const isolated = all.filter(function (n) { return n.isolated; });
    const inSim = new Set(simulated.map(function (n) { return n.id; }));

    const links = graph.edges
        .filter(function (e) { return inSim.has(e.source) && inSim.has(e.target); })
        .map(function (e) {
            return { source: e.source, target: e.target, category: e.category };
        });

    const sim = d3.forceSimulation(simulated)
        .force('link', d3.forceLink(links)
            .id(function (d) { return d.id; })
            .distance(function (l) {
                return LINK_DISTANCE[l.category] || DEFAULT_DISTANCE;
            }))
        /* Repulsion scaled by radius, so a big hub clears a big space. */
        .force('charge', d3.forceManyBody().strength(function (d) {
            return -22 * (d.r / MIN_RADIUS);
        }))
        .force('collide', d3.forceCollide().radius(function (d) {
            return d.r + 5;
        }).iterations(2))
        .force('board', forceBoard(BOARD_PULL))
        .force('chain', forceChain(CHAIN_PULL))
        .stop();

    for (let i = 0; i < TICKS; i++) sim.tick();

    /* The collision force shares a push between the two nodes it separates,
     * and a pinned node takes none of its share, so a newcomer can finish
     * the ticks still sitting on the edge of one. Step each newcomer out of
     * anything it overlaps, the whole way, in a fixed order. */
    if (placedBefore.size) {
        const loose = simulated.filter(function (n) { return !placedBefore.has(n.id); });
        for (let round = 0; round < 50; round++) {
            let moved = false;
            loose.forEach(function (a) {
                simulated.forEach(function (b) {
                    if (a === b) return;
                    const dx = a.x - b.x;
                    const dy = a.y - b.y;
                    const dist = Math.hypot(dx, dy);
                    const clear = a.r + b.r + 5;
                    if (dist >= clear) return;
                    const ux = dist > 0 ? dx / dist : 1;
                    const uy = dist > 0 ? dy / dist : 0;
                    a.x = b.x + ux * clear;
                    a.y = b.y + uy * clear;
                    moved = true;
                });
            });
            if (!moved) break;
        }
    }

    /* Park the isolated nodes in a row below everything that settled. */
    let bottom = 0;
    let left = 0;
    if (simulated.length) {
        bottom = Math.max.apply(null, simulated.map(function (n) { return n.y + n.r; }));
        left = Math.min.apply(null, simulated.map(function (n) { return n.x - n.r; }));
    }
    isolated.forEach(function (node, i) {
        if (placedBefore.has(node.id)) return;
        node.x = left + i * ISOLATED_GAP;
        node.y = bottom + ISOLATED_MARGIN;
    });

    const positions = {};
    all.forEach(function (node) {
        positions[node.id] = {
            x: round1(node.x),
            y: round1(node.y),
            r: round1(node.r)
        };
    });

    const xs = all.map(function (n) { return n.x; });
    const ys = all.map(function (n) { return n.y; });
    const extent = {
        minX: round1(Math.min.apply(null, xs)),
        minY: round1(Math.min.apply(null, ys)),
        maxX: round1(Math.max.apply(null, xs)),
        maxY: round1(Math.max.apply(null, ys))
    };

    const output = {
        meta: {
            /* Ties this layout to the graph it was settled from, so the page
             * and the tests can notice a stale pair. No timestamp: rebuilds
             * must stay byte-identical. */
            sourceHash: graph.meta && graph.meta.sourceHash ? graph.meta.sourceHash : null,
            ticks: TICKS,
            nodes: all.length,
            simulated: simulated.length,
            isolated: isolated.length,
            extent: extent
        },
        positions: positions
    };

    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(output, null, 2) + '\n', 'utf8');

    console.log('Wrote ' + path.relative(ROOT, OUTPUT_FILE) + ':');
    console.log('  ' + all.length + ' nodes (' + simulated.length + ' simulated, ' +
        isolated.length + ' isolated), ' + links.length + ' links, ' + TICKS + ' ticks');
    console.log('  ' + (placedBefore.size ? placedBefore.size + ' kept where they were, ' + added + ' new placed around them'
        : 'settled fresh from the board'));
    console.log('  extent x ' + extent.minX + '..' + extent.maxX +
        ', y ' + extent.minY + '..' + extent.maxY);
}

if (require.main === module) {
    try {
        build(process.argv.indexOf('--fresh') !== -1);
    } catch (err) {
        console.error('Layout build failed: ' + err.message);
        process.exit(1);
    }
}

module.exports = { build: build };
