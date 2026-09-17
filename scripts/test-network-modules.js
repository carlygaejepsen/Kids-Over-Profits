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

    /* A frame queue on a virtual clock rather than a synchronous
     * requestAnimationFrame. Running callbacks inline would break the code
     * under test in two ways that say nothing about the browser: a draw
     * scheduled inside its own callback would leave the "already scheduled"
     * guard permanently set, and an animation reading performance.now()
     * would see a clock that never moves and either never finish or divide
     * by a frozen span. flushFrames() drains it the way a browser would. */
    let clock = 0;
    let rafId = 0;
    let queue = [];
    const motion = { reduced: false };

    const sandbox = {
        console, Math, Date, JSON, Object, Array, Number, String, Boolean, Error,
        Float64Array, Promise, isNaN, isFinite, parseFloat, parseInt, Infinity, NaN,
        setTimeout, clearTimeout, setInterval, clearInterval,
        devicePixelRatio: 2,
        performance: { now: () => clock },
        requestAnimationFrame: (fn) => { queue.push({ id: ++rafId, fn }); return rafId; },
        cancelAnimationFrame: (id) => { queue = queue.filter((entry) => entry.id !== id); },
        matchMedia: (query) => ({
            media: query,
            matches: motion.reduced && /prefers-reduced-motion/.test(query),
            addListener() {}, removeListener() {},
            addEventListener() {}, removeEventListener() {}
        })
    };
    sandbox.self = sandbox;
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);

    const files = [
        path.join('js', 'vendor', 'd3-force.bundle.min.js'),
        path.join('js', 'network-map', 'store.js'),
        path.join('js', 'network-map', 'canvas.js'),
        path.join('js', 'network-map', 'viewport.js'),
        path.join('js', 'network-map', 'focus.js')
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

    /** Run queued frames until nothing is scheduled, as a browser would. */
    function flushFrames(limit) {
        const max = limit || 600;
        let frames = 0;
        while (queue.length && frames < max) {
            clock += 16.7;
            const batch = queue;
            queue = [];
            batch.forEach((entry) => entry.fn(clock));
            frames++;
        }
        return frames;
    }

    return {
        sandbox, canvas, ops, fire, motion, flushFrames,
        pending: () => queue.length,
        resetOps: () => Object.keys(ops).forEach((k) => { ops[k] = 0; })
    };
}

/* ------------------------------------------------------------------ run -- */

function run() {
    const graph = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'graph.json'), 'utf8'));
    const layout = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'layout.json'), 'utf8'));

    /* Names the data has no connection for at all: three today, and the
     * slider's floor of zero is what keeps them on the map. */
    const unconnected = graph.nodes.filter((n) => n.degree === 0).length;

    const { sandbox, canvas, ops, fire, motion, flushFrames, resetOps } = buildSandbox();
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
    /* The default view is the whole map. The slider's floor is zero
     * connections, so the unconnected names - Judge Rotenberg, IECA,
     * Accelerated Christian Education - are on it like everything else. */
    check(all.nodes.length === graph.nodes.length,
        'default view shows ' + all.nodes.length + ' of ' + graph.nodes.length + ' nodes',
        'default view: ' + all.nodes.length + ' nodes, ' + all.edges.length + ' edges');
    check(all.edges.length === graph.edges.length,
        'default view lost edges: ' + all.edges.length + '/' + graph.edges.length);
    graph.nodes.filter((n) => n.isolated).forEach((n) => {
        check(all.nodeIds[n.id] === true, 'the default view hides the unconnected node ' + n.id);
    });

    /* Every visible edge must have both ends visible, at every setting. */
    function endpointsPresent(view) {
        return view.edges.every((e) => view.nodeIds[e.sourceId] && view.nodeIds[e.targetId]);
    }
    check(endpointsPresent(all), 'default view has an edge with a hidden endpoint');

    /* One step off the floor drops the unconnected names and nothing else. */
    store.setFilter('minDegree', 1);
    check(store.visible().nodes.length === graph.nodes.length - unconnected,
        'stepping the slider to one should drop exactly the unconnected names');

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
    /* The answer to "who moved between board groups" is the people who did,
     * plus the three names the data has no connections for at all - not
     * every node whose connections this view happened to filter out. */
    const strandedByView = crossing.nodes.filter((n) => !crossing.degrees[n.id] && n.degree > 0);
    check(strandedByView.length === 0,
        'the cross-group view kept ' + strandedByView.length +
        ' nodes whose connections it had just filtered away');

    store.resetFilters();
    store.setFilter('natsapOnly', true);
    check(store.visible().nodes.every((n) => n.natsap), 'the NATSAP filter let a non-member through');

    store.resetFilters();
    store.toggleIn('kinds', 'person', false);
    const noPeople = store.visible();
    check(noPeople.nodes.every((n) => n.kind !== 'person'), 'a person survived the kind filter');
    check(endpointsPresent(noPeople), 'kind-filtered view has an edge with a hidden endpoint');

    /* Unchecking every connection type leaves only the names the data has no
     * connections for; everything else has been filtered down to nothing and
     * goes with its lines. Raising the slider clears those last three too. */
    store.resetFilters();
    Object.keys(store.filters.categories).forEach((c) => { delete store.filters.categories[c]; });
    store.touch();
    check(store.visible().edges.length === 0 && store.visible().nodes.length === unconnected,
        'unchecking every connection type left ' + store.visible().nodes.length +
        ' nodes, expected the ' + unconnected + ' with no recorded connections');
    store.setFilter('minDegree', 1);
    check(store.visible().nodes.length === 0,
        'with no connection types and a minimum of one, the map should be empty');

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
    /* focus.js is created further down, once the viewport has been tested on
     * its own; from then on the pointer drives the chain, as it does on the
     * page. */
    const focusRef = { current: null };
    const viewport = sandbox.KOPNetworkViewport.create({
        canvas,
        renderer,
        onHover: (node) => {
            hovered = node;
            if (focusRef.current) focusRef.current.hover(node);
            else renderer.setEmphasis({ hoverId: node ? node.id : null });
        },
        onSelect: (node) => {
            selected = node;
            if (focusRef.current) focusRef.current.select(node);
        }
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

    /* ------------------------------------------------------------ focus -- */

    store.resetFilters();
    const whole = store.visible();
    renderer.setScene(whole);
    viewport.setScene(whole);
    viewport.fit();

    let announced = '';
    let changes = 0;
    const focus = sandbox.KOPNetworkFocus.create({
        store,
        renderer,
        viewport,
        announce: (text) => { announced = text; },
        onChange: () => { changes++; }
    });
    /* From here the pointer drives the chain, as it does on the page. */
    focusRef.current = focus;

    /* --- hover previews --- */

    const mapX = hub.x;
    const mapY = hub.y;
    const neighbours = store.neighbours(hub.id, true);
    focus.hover(hub);

    const lit = renderer.emphasis.near;
    check(!!lit && lit[hub.id] === true, 'hovering did not light the hovered node');
    check(Object.keys(lit).length === neighbours.length + 1,
        'hover lit ' + Object.keys(lit).length + ' nodes, expected ' + (neighbours.length + 1),
        'hover lights ' + hub.name + ' and its ' + neighbours.length + ' connections');
    check(Object.keys(renderer.emphasis.nearEdges).length === neighbours.length,
        'hover lit the wrong number of connections');
    check(renderer.emphasis.dim > 0 && renderer.emphasis.dim < 0.3,
        'hover did not drop the rest of the map to a dim alpha');

    /* --- the gather --- */

    flushFrames();
    const gathered = focus.offsets();
    check(!!gathered, 'the gather produced no offsets');
    check(!gathered[hub.id], 'the hovered node moved; only its neighbours should');

    let pulledIn = 0;
    let tooClose = 0;
    neighbours.forEach((link) => {
        const off = gathered[link.other.id];
        if (!off) return;
        const was = Math.hypot(link.other.x - hub.x, link.other.y - hub.y);
        const now = Math.hypot(link.other.x + off[0] - hub.x, link.other.y + off[1] - hub.y);
        if (now < was) pulledIn++;
        if (now < hub.r + link.other.r) tooClose++;
    });
    check(pulledIn > 0, 'the gather moved nothing toward the hovered node',
        'the gather pulls in ' + pulledIn + ' of ' + neighbours.length + ' neighbours');
    check(tooClose === 0, tooClose + ' neighbours were gathered inside the node they gathered to');

    /* The gather is display-only: the settled layout must be untouched. */
    check(hub.x === mapX && hub.y === mapY, 'the gather moved a stored position');

    /* And the pointer has to be able to reach a node where it is drawn. */
    const moved = neighbours.map((l) => l.other).find((n) => gathered[n.id]);
    const movedOffset = gathered[moved.id];
    const movedScreen = {
        x: (moved.x + movedOffset[0]) * t.k + t.x,
        y: (moved.y + movedOffset[1]) * t.k + t.y
    };
    check(viewport.nodeAt(movedScreen.x, movedScreen.y) === moved,
        'a gathered node could not be clicked where it was drawn');
    const staleScreen = { x: moved.x * t.k + t.x, y: moved.y * t.k + t.y };
    check(viewport.nodeAt(staleScreen.x, staleScreen.y) !== moved,
        'a gathered node was still clickable at the position it had left');

    /* --- leaving eases back --- */

    focus.hover(null);
    flushFrames();
    check(focus.offsets() === null, 'the gather did not let go when the pointer left');
    check(renderer.emphasis.near === null, 'the dimming outlasted the hover');

    /* --- reduced motion is dimming alone --- */

    motion.reduced = true;
    focus.hover(hub);
    flushFrames();
    check(focus.offsets() === null, 'the gather ran under prefers-reduced-motion');
    check(!!renderer.emphasis.near && renderer.emphasis.near[hub.id],
        'reduced motion lost the hover lighting as well as the movement');
    focus.hover(null);
    motion.reduced = false;
    flushFrames();

    /* --- clicking commits --- */

    focus.select(hub);
    flushFrames();
    check(focus.chain().length === 1 && focus.chain()[0] === hub.id,
        'selecting a node did not start the trail');
    check(announced.indexOf(hub.name) === 0, 'the commit was not announced by name');

    const focused = focus.scene();
    check(focused.nodes.length === neighbours.length + 1,
        'the focused view holds ' + focused.nodes.length + ' nodes, expected ' + (neighbours.length + 1),
        'focus on ' + hub.name + ': ' + focused.nodes.length + ' nodes, ' + focused.edges.length + ' edges');
    check(focused.nodes.length < whole.nodes.length, 'committing did not narrow the map');
    check(focused.edges.every((e) => focused.nodeIds[e.sourceId] && focused.nodeIds[e.targetId]),
        'the focused view kept an edge running off it');

    /* The re-settle writes its own coordinates and leaves the map's alone. */
    const settledPos = focus.positionOf(hub);
    check(settledPos !== hub, 'the focused view is reading the map positions, not its own');
    check(focused.nodes.every((n) => {
        const p = focus.positionOf(n);
        return isFinite(p.x) && isFinite(p.y);
    }), 'the re-settle produced a non-finite position');
    check(hub.x === mapX && hub.y === mapY, 'the re-settle moved a stored position');

    let overlapping = 0;
    for (let a = 0; a < focused.nodes.length; a++) {
        for (let b = a + 1; b < focused.nodes.length; b++) {
            const pa = focus.positionOf(focused.nodes[a]);
            const pb = focus.positionOf(focused.nodes[b]);
            if (Math.hypot(pa.x - pb.x, pa.y - pb.y) < focused.nodes[a].r + focused.nodes[b].r) overlapping++;
        }
    }
    check(overlapping === 0, overlapping + ' pairs overlap in the re-settled neighbourhood');

    /* The view should have framed what it settled. */
    const offStage = focused.nodes.filter((n) => {
        const p = focus.positionOf(n);
        const sxp = p.x * t.k + t.x;
        const syp = p.y * t.k + t.y;
        return sxp < -1 || sxp > WIDTH + 1 || syp < -1 || syp > HEIGHT + 1;
    }).length;
    check(offStage === 0, 'the focused view left ' + offStage + ' of its own nodes off the stage');

    /* --- dragging inside a focus --- */

    const dragTarget = focused.nodes.find((n) => n !== hub);
    const dragBeforeX = dragTarget.x;
    const focusPos = focus.positionOf(dragTarget);
    const focusBeforeX = focusPos.x;
    let dragScreen = { x: focusPos.x * t.k + t.x, y: focusPos.y * t.k + t.y };
    fire('pointerdown', 1, dragScreen.x, dragScreen.y);
    fire('pointermove', 1, dragScreen.x + 40, dragScreen.y);
    fire('pointerup', 1, dragScreen.x + 40, dragScreen.y);
    check(Math.abs(focus.positionOf(dragTarget).x - (focusBeforeX + 40 / t.k)) < 1e-6,
        'dragging inside a focus did not move the node in the focused view');
    check(dragTarget.x === dragBeforeX,
        'dragging inside a focus wrote through to the stored layout');

    /* --- the trail extends, truncates and clears --- */

    const next = focused.nodes.find((n) => n !== hub && store.neighbours(n.id, true).length > 2);
    focus.select(next);
    flushFrames();
    check(focus.chain().length === 2, 'clicking a neighbour replaced the trail instead of extending it');
    const twoDeep = focus.scene();
    check(twoDeep.nodeIds[hub.id] && twoDeep.nodeIds[next.id],
        'the second step dropped the first off the screen');
    check(twoDeep.nodes.length >= focused.nodes.length,
        'extending the trail shrank the view',
        'two steps deep: ' + twoDeep.nodes.length + ' nodes, ' + twoDeep.edges.length + ' edges');

    focus.truncateTo(0);
    flushFrames();
    check(focus.chain().length === 1 && focus.chain()[0] === hub.id,
        'truncating to the first crumb did not go back to it');

    focus.clear();
    flushFrames();
    check(focus.chain().length === 0, 'clearing did not leave the trail empty');
    check(focus.positionOf(hub) === hub, 'clearing did not hand the map back its own positions');
    check(hub.x === mapX && hub.y === mapY, 'the whole map came back in the wrong place');
    check(focus.scene().nodes.length === whole.nodes.length,
        'clearing did not restore the whole map');
    check(changes > 0, 'the chain never reported a change for the breadcrumb to render');

    /* --- the filters can move under a focused trail --- */

    focus.select(hub);
    flushFrames();
    store.toggleIn('kinds', hub.kind, false);
    const stillFocused = focus.refresh();
    flushFrames();
    check(stillFocused === false && focus.chain().length === 0,
        'filtering away the node the trail stands on left the view describing a graph that is gone');
    check(focus.positionOf(hub) === hub, 'falling back to the whole map kept the focused coordinates');

    store.resetFilters();
    focus.destroy();
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
