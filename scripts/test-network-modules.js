#!/usr/bin/env node
/**
 * Network map module tests
 *
 * Runs the browser modules against the real graph.json and layout.json in
 * plain Node, so the map's arithmetic is checked without opening a browser:
 * which nodes survive a filter, whether a frame paints anything, whether a
 * click lands on the node under the cursor, and whether zooming keeps the
 * point under the pointer where it was.
 *
 * There is no jsdom in this repo and no reason to add one. The three modules
 * touch a small, known slice of the DOM - a canvas element, its 2D context,
 * pointer and wheel listeners, requestAnimationFrame - so that slice is
 * stubbed here and the modules are evaluated in a vm context against it. The
 * 2D context records call counts rather than rasterising, which is enough to
 * tell "drew 904 nodes" from "drew nothing".
 *
 * Usage: node scripts/test-network-modules.js
 * Run it after editing anything in js/network-map/.
 *
 * Covers store, canvas and viewport. Search ranking and URL state join it
 * when those modules land.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');

/* A stage big enough to frame the whole map, so the fit assertions have
 * something realistic to work with. */
const WIDTH = 1000;
const HEIGHT = 700;

const failures = [];
const notes = [];

function check(condition, message, note) {
    if (!condition) failures.push(message);
    else if (note) notes.push(note);
}

/* --------------------------------------------------------- the DOM stub -- */

function buildSandbox() {
    const ops = {
        fill: 0, stroke: 0, fillText: 0, strokeText: 0,
        beginPath: 0, arc: 0, moveTo: 0, lineTo: 0
    };
    const bump = (name) => () => { ops[name]++; };

    const ctx = {
        setTransform() {}, clearRect() {}, save() {}, restore() {},
        beginPath: bump('beginPath'), closePath() {},
        moveTo: bump('moveTo'), lineTo: bump('lineTo'), arc: bump('arc'),
        quadraticCurveTo() {}, setLineDash() {},
        fill: bump('fill'), stroke: bump('stroke'),
        fillText: bump('fillText'), strokeText: bump('strokeText'),
        measureText: (t) => ({ width: String(t).length * 6 })
    };

    const listeners = new Map();
    const canvas = {
        style: {},
        width: 0,
        height: 0,
        getContext: () => ctx,
        getBoundingClientRect: () => ({ left: 0, top: 0, width: WIDTH, height: HEIGHT }),
        addEventListener: (type, fn) => listeners.set(type, fn),
        removeEventListener: (type) => listeners.delete(type),
        setPointerCapture() {},
        releasePointerCapture() {},
        hasPointerCapture: () => false
    };

    const sandbox = {
        console, Math, Date, JSON, Object, Array, Number, String, Boolean, Error,
        Float64Array, Promise, isNaN, parseFloat, parseInt, Infinity, NaN,
        setTimeout, clearTimeout, setInterval, clearInterval, performance,
        devicePixelRatio: 2,
        /* Synchronous, so a scheduled draw has happened by the time the
         * assertion after it runs. */
        requestAnimationFrame: (fn) => { fn(); return 1; },
        cancelAnimationFrame() {}
    };
    sandbox.self = sandbox;
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);

    const files = [
        path.join('js', 'vendor', 'd3-force.bundle.min.js'),
        path.join('js', 'network-map', 'store.js'),
        path.join('js', 'network-map', 'canvas.js'),
        path.join('js', 'network-map', 'viewport.js')
    ];
    files.forEach(function (rel) {
        vm.runInContext(fs.readFileSync(path.join(ROOT, rel), 'utf8'), sandbox, { filename: rel });
    });

    /** Dispatch a pointer or wheel event at canvas-local coordinates. */
    function fire(type, pointerId, x, y, extra) {
        const fn = listeners.get(type);
        if (!fn) throw new Error('no listener registered for ' + type);
        fn(Object.assign({ pointerId, clientX: x, clientY: y, preventDefault() {} }, extra));
    }

    return { sandbox, canvas, ops, fire, resetOps: () => Object.keys(ops).forEach((k) => { ops[k] = 0; }) };
}

/* ------------------------------------------------------------------ run -- */

function run() {
    const graph = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'graph.json'), 'utf8'));
    const layout = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'layout.json'), 'utf8'));

    const { sandbox, canvas, ops, fire, resetOps } = buildSandbox();
    const store = sandbox.KOPNetworkStore.create();
    store.hydrate(graph, layout);

    /* ------------------------------------------------------------ store -- */

    check(store.nodes.length === graph.nodes.length && store.edges.length === graph.edges.length,
        'store dropped rows: ' + store.nodes.length + '/' + graph.nodes.length + ' nodes, ' +
        store.edges.length + '/' + graph.edges.length + ' edges');
    check(store.unplaced.length === 0,
        store.unplaced.length + ' nodes have no layout position; rebuild the layout');

    check(sandbox.KOPNetworkStore.statusBucket('') === 'unknown' &&
        sandbox.KOPNetworkStore.statusBucket('Open') === 'open' &&
        sandbox.KOPNetworkStore.statusBucket('closed or rebranded') === 'closed',
        'status bucketing does not match the three filter checkboxes');

    const all = store.visible();
    const isolated = graph.nodes.filter((n) => n.isolated).length;
    /* The slider starts at one connection, so the isolated nodes are out of
     * the default view by design. */
    check(all.nodes.length === graph.nodes.length - isolated,
        'default view shows ' + all.nodes.length + ', expected ' + (graph.nodes.length - isolated),
        'default view: ' + all.nodes.length + ' nodes, ' + all.edges.length + ' edges');
    check(all.edges.length === graph.edges.length,
        'default view lost edges: ' + all.edges.length + '/' + graph.edges.length);

    /* Every visible edge must have both ends visible, at every setting. */
    function endpointsPresent(view) {
        return view.edges.every((e) => view.nodeIds[e.sourceId] && view.nodeIds[e.targetId]);
    }
    check(endpointsPresent(all), 'default view has an edge with a hidden endpoint');

    store.setFilter('minDegree', 6);
    const narrow = store.visible();
    check(narrow.nodes.length > 0 && narrow.nodes.length < all.nodes.length,
        'the minimum-connections slider did not narrow the view',
        'six connections or more: ' + narrow.nodes.length + ' nodes, ' + narrow.edges.length + ' edges');
    check(endpointsPresent(narrow), 'narrowed view has an edge with a hidden endpoint');
    check(narrow.nodes.every((n) => narrow.degrees[n.id] >= 6),
        'a node below the minimum survived the slider');

    store.resetFilters();
    store.setFilter('crossRegionOnly', true);
    const crossing = store.visible();
    check(crossing.edges.every((e) => e.crossesRegion),
        'the cross-group view kept an edge that stays inside one board frame',
        'cross-group only: ' + crossing.nodes.length + ' nodes, ' + crossing.edges.length + ' edges');

    store.resetFilters();
    store.setFilter('natsapOnly', true);
    check(store.visible().nodes.every((n) => n.natsap), 'the NATSAP filter let a non-member through');

    store.resetFilters();
    store.toggleIn('kinds', 'person', false);
    const noPeople = store.visible();
    check(noPeople.nodes.every((n) => n.kind !== 'person'), 'a person survived the kind filter');
    check(endpointsPresent(noPeople), 'kind-filtered view has an edge with a hidden endpoint');

    /* Unchecking every category should leave nodes but no edges, and then the
     * slider's floor of one should empty the map rather than showing a field
     * of unconnected dots. */
    store.resetFilters();
    Object.keys(store.filters.categories).forEach((c) => { delete store.filters.categories[c]; });
    store.touch();
    check(store.visible().edges.length === 0 && store.visible().nodes.length === 0,
        'unchecking every connection type did not empty the view');

    store.resetFilters();
    const hub = store.node('provo-canyon-school');
    check(!!hub, 'the probe node provo-canyon-school is missing from the graph');
    check(store.neighbours(hub.id).length === hub.degree,
        'adjacency disagrees with the stored degree for ' + hub.id);

    /* A node the filters would drop stays visible when it is the selection. */
    store.setFilter('minDegree', 99);
    store.setFilter('keepVisible', hub.id);
    check(store.visible().nodeIds[hub.id] === true, 'keepVisible did not hold the selected node on screen');
    store.resetFilters();

    /* ----------------------------------------------------------- canvas -- */

    const renderer = sandbox.KOPNetworkCanvas.create(canvas);
    renderer.useChainIndex(store.chainIndex);
    check(renderer.resize() === true && canvas.width === WIDTH * 2 && canvas.height === HEIGHT * 2,
        'resize did not scale the backing store to the device pixel ratio');

    let hovered = null;
    let selected = null;
    const viewport = sandbox.KOPNetworkViewport.create({
        canvas,
        renderer,
        onHover: (node) => { hovered = node; renderer.setEmphasis({ hoverId: node ? node.id : null }); },
        onSelect: (node) => { selected = node; }
    });

    const scene = store.visible();
    renderer.setScene(scene);
    viewport.setScene(scene);
    viewport.fit();

    const t = renderer.transform;
    const screenOf = (n) => ({ x: n.x * t.k + t.x, y: n.y * t.k + t.y });
    check(Number.isFinite(t.k) && Number.isFinite(t.x) && Number.isFinite(t.y),
        'fit produced a non-finite transform');

    const outside = scene.nodes.filter((n) => {
        const p = screenOf(n);
        return p.x < -1 || p.x > WIDTH + 1 || p.y < -1 || p.y > HEIGHT + 1;
    }).length;
    check(outside === 0, 'fit left ' + outside + ' nodes off the stage',
        'fit framed ' + scene.nodes.length + ' nodes at zoom ' + t.k.toFixed(3));

    resetOps();
    renderer.draw();
    check(ops.fill >= scene.nodes.length,
        'a frame filled ' + ops.fill + ' shapes for ' + scene.nodes.length + ' nodes');
    check(ops.moveTo >= scene.edges.length,
        'a frame started ' + ops.moveTo + ' line segments for ' + scene.edges.length + ' edges');
    /* Hubs are labelled at every zoom, the rest fade in; a wide view that
     * labels everything is unreadable and one that labels nothing is
     * useless. */
    check(ops.fillText > 10 && ops.fillText < scene.nodes.length / 4,
        'a wide view drew ' + ops.fillText + ' labels',
        'wide view labels ' + ops.fillText + ' of ' + scene.nodes.length + ' nodes');

    renderer.setColourMode('chain');
    check(renderer.colourFor(hub) === sandbox.KOPNetworkCanvas.CHAIN_COLOURS[store.chainIndex[hub.chain]],
        'chain colouring did not follow the build order for ' + hub.chain);
    const ownerless = store.nodes.find((n) => !n.chain);
    check(renderer.colourFor(ownerless) === sandbox.KOPNetworkCanvas.CHAIN_NONE,
        'a node with no recorded owner was given a chain colour');
    renderer.setColourMode('kind');
    check(renderer.colourFor(hub) === sandbox.KOPNetworkCanvas.KIND_COLOURS[hub.kind],
        'kind colouring did not come back');

    /* --------------------------------------------------------- viewport -- */

    let point = screenOf(hub);
    check(viewport.nodeAt(point.x, point.y) === hub, 'hit testing missed a node at its own centre');
    check(viewport.nodeAt(point.x + 3, point.y + 3) === hub,
        'hit slop did not keep a small node tappable at a wide zoom');
    check(viewport.nodeAt(2, 2) === null, 'hit testing found a node in empty space');

    /* Zoomed in, the slop is worth about a world unit, so the search radius
     * has to account for the node's own size or a click well inside a big hub
     * finds nothing. Probe the largest node away from its nearest neighbour,
     * so the only right answer is the hub itself. */
    const biggest = scene.nodes.reduce((a, b) => (b.r > a.r ? b : a));
    let nearest = Infinity;
    let awayX = 1;
    let awayY = 0;
    scene.nodes.forEach((n) => {
        if (n === biggest) return;
        const d = Math.hypot(n.x - biggest.x, n.y - biggest.y);
        if (d < nearest) {
            nearest = d;
            awayX = (biggest.x - n.x) / (d || 1);
            awayY = (biggest.y - n.y) / (d || 1);
        }
    });
    viewport.setTransform(sandbox.KOPNetworkViewport.MAX_ZOOM, 0, 0);
    viewport.centreOn(biggest);
    const reach = Math.min(biggest.r * 0.8, Math.max(0, nearest - 1));
    const inside = { x: biggest.x + awayX * reach, y: biggest.y + awayY * reach };
    const insideScreen = { x: inside.x * t.k + t.x, y: inside.y * t.k + t.y };
    check(viewport.nodeAt(insideScreen.x, insideScreen.y) === biggest,
        'a click inside a large node missed it at close zoom',
        'largest node ' + biggest.name + ' (r ' + biggest.r + ') is hit ' +
        reach.toFixed(1) + ' units off centre at zoom ' + t.k);
    viewport.fit();

    const before = viewport.toWorld(400, 300);
    viewport.zoomAbout(t.k * 3, 400, 300);
    const after = viewport.toWorld(400, 300);
    check(Math.abs(before.x - after.x) < 1e-6 && Math.abs(before.y - after.y) < 1e-6,
        'zooming moved the world point under the cursor');

    viewport.zoomAbout(999, 400, 300);
    check(t.k === sandbox.KOPNetworkViewport.MAX_ZOOM, 'zoom passed its ceiling: ' + t.k);
    viewport.zoomAbout(0.00001, 400, 300);
    check(t.k === sandbox.KOPNetworkViewport.MIN_ZOOM, 'zoom passed its floor: ' + t.k);
    viewport.fit();

    const panX = t.x;
    const panY = t.y;
    fire('pointerdown', 1, 5, 5);
    fire('pointermove', 1, 45, 25);
    fire('pointerup', 1, 45, 25);
    check(Math.abs(t.x - (panX + 40)) < 1e-6 && Math.abs(t.y - (panY + 20)) < 1e-6,
        'a drag on empty space did not pan by the pointer travel');
    check(selected === null, 'a pan was treated as a click');

    point = screenOf(hub);
    fire('pointerdown', 1, point.x, point.y);
    fire('pointerup', 1, point.x, point.y);
    check(selected === hub, 'a tap on a node did not select it');

    /* Dragging moves and pins that node and nothing else, and the hit index
     * has to follow it or the node becomes unclickable where it now sits. */
    const neighbourX = store.node('universal-health-services').x;
    const wasX = hub.x;
    point = screenOf(hub);
    fire('pointerdown', 1, point.x, point.y);
    fire('pointermove', 1, point.x + 60, point.y);
    fire('pointerup', 1, point.x + 60, point.y);
    check(hub.pinned === true, 'dragging a node did not pin it');
    check(Math.abs(hub.x - (wasX + 60 / t.k)) < 1e-6, 'dragging a node moved it by the wrong amount');
    check(store.node('universal-health-services').x === neighbourX, 'dragging one node moved another');
    point = screenOf(hub);
    check(viewport.nodeAt(point.x, point.y) === hub, 'the hit index did not follow a dragged node');
    hub.x = wasX;
    hub.pinned = false;
    viewport.rebuildTree();

    const pinchFrom = t.k;
    fire('pointerdown', 1, 400, 300);
    fire('pointerdown', 2, 500, 300);
    fire('pointermove', 1, 350, 300);
    fire('pointermove', 2, 550, 300);
    fire('pointerup', 1, 350, 300);
    fire('pointerup', 2, 550, 300);
    check(t.k > pinchFrom * 1.5, 'two fingers spreading did not zoom in: ' +
        pinchFrom.toFixed(3) + ' to ' + t.k.toFixed(3));

    const wheelFrom = t.k;
    fire('wheel', 1, 400, 300, { deltaY: -200, deltaMode: 0 });
    check(t.k > wheelFrom, 'the wheel did not zoom in');
    /* Firefox reports lines, not pixels; without normalising, one tick there
     * is a fortieth of one here. */
    const lineFrom = t.k;
    fire('wheel', 1, 400, 300, { deltaY: -3, deltaMode: 1 });
    check(t.k > lineFrom * 1.02, 'a line-mode wheel tick barely moved the zoom');

    viewport.fit();
    point = screenOf(hub);
    fire('pointermove', 9, point.x, point.y);
    check(hovered === hub, 'hovering a node did not report it');
    fire('pointermove', 9, 2, 2);
    check(hovered === null, 'the hover did not clear when the pointer left the node');

    /* A node can be filtered out from under the pointer. */
    fire('pointermove', 9, screenOf(hub).x, screenOf(hub).y);
    const hadHover = hovered === hub;
    store.toggleIn('kinds', 'facility', false);
    const withoutFacilities = store.visible();
    renderer.setScene(withoutFacilities);
    viewport.setScene(withoutFacilities);
    check(hadHover && hovered === null, 'filtering the hovered node away left a stale hover');

    /* An empty scene is reachable from the rail; it must not throw. */
    store.resetFilters();
    store.setFilter('minDegree', 999);
    const empty = store.visible();
    renderer.setScene(empty);
    viewport.setScene(empty);
    renderer.draw();
    viewport.fit();
    check(empty.nodes.length === 0, 'the emptiest filter still showed ' + empty.nodes.length + ' nodes');
}

try {
    run();
} catch (error) {
    failures.push('threw: ' + (error && error.stack ? error.stack : error));
}

notes.forEach(function (note) { console.log('  note: ' + note); });
if (failures.length === 0) {
    console.log('network module tests: PASS');
    process.exit(0);
}
console.error('network module tests: FAIL');
failures.slice(0, 40).forEach(function (failure) { console.error('  - ' + failure); });
if (failures.length > 40) console.error('  ... and ' + (failures.length - 40) + ' more');
process.exit(1);
