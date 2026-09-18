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

/**
 * Pairs of drawn labels whose boxes touch. Widths are measured the way the
 * stub context measures them, which is what the renderer placed them with.
 * Two names on top of each other are worse than one name: the pair is
 * unreadable and neither can be trusted to belong to the node under it.
 */
function collidingLabels(boxes) {
    const LINE = 13;
    const rects = boxes.map((b) => {
        const half = (String(b.t).length * 6) / 2;
        return { t: b.t, x0: b.x - half, x1: b.x + half, y0: b.y, y1: b.y + LINE };
    });
    const clashes = [];
    for (let i = 0; i < rects.length; i++) {
        for (let j = i + 1; j < rects.length; j++) {
            const a = rects[i];
            const b = rects[j];
            if (a.x0 < b.x1 && a.x1 > b.x0 && a.y0 < b.y1 && a.y1 > b.y0) clashes.push([a.t, b.t]);
        }
    }
    return clashes;
}

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
    /* Label draws in order, so the two-pass halo can be checked, and their
     * placed positions, so overlap can be. */
    const labelCalls = [];
    const labelBoxes = [];

    const ctx = {
        setTransform() {}, clearRect() {}, save() {}, restore() {},
        beginPath: bump('beginPath'), closePath() {},
        moveTo: bump('moveTo'), lineTo: bump('lineTo'), arc: bump('arc'),
        quadraticCurveTo() {}, setLineDash() {},
        fill: bump('fill'), stroke: bump('stroke'),
        fillText: (t, x, y) => { ops.fillText++; labelCalls.push('text:' + t); labelBoxes.push({ t, x, y }); },
        strokeText: (t) => { ops.strokeText++; labelCalls.push('halo:' + t); },
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
    let timerId = 0;
    let timers = [];
    /* Media state the tests drive: prefers-reduced-motion and the narrow
     * breakpoint where the filter rail becomes a sheet over the map. */
    const motion = { reduced: false, narrow: false };
    const mediaListeners = [];

    const sandbox = {
        console, Math, Date, JSON, Object, Array, Number, String, Boolean, Error,
        Float64Array, Promise, isNaN, isFinite, parseFloat, parseInt, Infinity, NaN,
        setInterval, clearInterval,
        devicePixelRatio: 2,
        performance: { now: () => clock },
        requestAnimationFrame: (fn) => { queue.push({ id: ++rafId, fn }); return rafId; },
        cancelAnimationFrame: (id) => { queue = queue.filter((entry) => entry.id !== id); },
        /* The slider defers its filter until the thumb pauses, so the timer
         * queue is driven by the test rather than by the wall clock. */
        setTimeout: (fn, ms) => { timers.push({ id: ++timerId, fn, at: clock + (ms || 0) }); return timerId; },
        clearTimeout: (id) => { timers = timers.filter((entry) => entry.id !== id); },
        matchMedia: (query) => ({
            media: query,
            /* A getter, not a snapshot: the modules hold the list and read
             * matches later, as they do in a browser. */
            get matches() {
                if (/prefers-reduced-motion/.test(query)) return motion.reduced;
                if (/max-width/.test(query)) return motion.narrow;
                return false;
            },
            addListener(fn) { mediaListeners.push(fn); },
            removeListener() {},
            addEventListener(type, fn) { mediaListeners.push(fn); },
            removeEventListener() {}
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
        path.join('js', 'network-map', 'focus.js'),
        path.join('js', 'network-map', 'filters.js')
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

    /* ---------------------------------------------------------- the DOM -- */

    /* Enough of an element to bind a filter rail to. Not a DOM
     * implementation: it supports the handful of selectors and properties
     * filters.js actually uses, and nothing else. If a module reaches for
     * something that is not here it throws, which is the point. */
    const allElements = [];
    function el(tag, props) {
        const node = {
            tagName: tag.toUpperCase(),
            children: [],
            attrs: Object.create(null),
            listeners: Object.create(null),
            className: '',
            value: '',
            checked: false,
            hidden: false,
            disabled: false,
            width: 0,
            height: 0,
            parentNode: null,
            _text: '',
            appendChild(child) { child.parentNode = node; node.children.push(child); return child; },
            setAttribute(name, value) { node.attrs[name] = String(value); },
            getAttribute(name) { return node.attrs[name] === undefined ? null : node.attrs[name]; },
            addEventListener(type, fn) { (node.listeners[type] = node.listeners[type] || []).push(fn); },
            removeEventListener() {},
            dispatch(type) {
                (node.listeners[type] || []).forEach((fn) => fn({ target: node, preventDefault() {} }));
            },
            getContext: () => ctx,
            focus() { node.focused = true; },
            querySelector(sel) { return node.querySelectorAll(sel)[0] || null; },
            querySelectorAll(sel) {
                const parts = sel.split(',').map((s) => s.trim());
                const out = [];
                const walk = (current) => {
                    current.children.forEach((child) => {
                        if (parts.some((part) => matches(child, part))) out.push(child);
                        walk(child);
                    });
                };
                walk(node);
                return out;
            }
        };
        Object.defineProperty(node, 'textContent', {
            get() {
                if (node.children.length) return node.children.map((c) => c.textContent).join('');
                return node._text;
            },
            set(value) { node.children.length = 0; node._text = String(value); }
        });
        Object.assign(node, props || {});
        allElements.push(node);
        return node;
    }

    /** tag, .class, tag[name="value"] - all that the modules ask for. */
    function matches(node, selector) {
        const attr = /^([a-z]*)\[([a-z-]+)="([^"]*)"\]$/i.exec(selector);
        if (attr) {
            if (attr[1] && node.tagName !== attr[1].toUpperCase()) return false;
            return node.getAttribute(attr[2]) === attr[3];
        }
        if (selector.charAt(0) === '.') return node.className.split(/\s+/).indexOf(selector.slice(1)) !== -1;
        return node.tagName === selector.toUpperCase();
    }

    const root = el('div');
    const document_ = {
        getElementById(id) {
            return allElements.find((node) => node.getAttribute('id') === id) || null;
        },
        createElement: (tag) => el(tag),
        querySelector(sel) { return root.querySelector(sel); },
        querySelectorAll(sel) { return root.querySelectorAll(sel); }
    };

    /**
     * The rail the template renders, built from the same meta block PHP
     * reads, so the names, values and label wording match what ships.
     */
    function buildRail(meta, labelFor) {
        const group = (name, values) => values.forEach((value) => {
            const label = el('label', { className: 'kop-network__check' });
            const input = el('input', { checked: true, value: String(value) });
            input.setAttribute('name', name);
            input.setAttribute('type', 'checkbox');
            label.appendChild(input);
            const span = el('span');
            span.textContent = labelFor(name, value);
            label.appendChild(span);
            rail.appendChild(label);
        });

        const rail = el('aside', { className: 'kop-network__rail' });
        rail.setAttribute('id', 'kop-network-rail');
        root.appendChild(rail);

        group('kop-network-kind', meta.kinds);
        group('kop-network-category', meta.categories);
        group('kop-network-status', ['open', 'closed', 'unknown']);
        group('kop-network-chain', [''].concat(meta.chains));
        group('kop-network-region', meta.regions);

        const add = (tag, id, props) => {
            const node = el(tag, props);
            node.setAttribute('id', id);
            rail.appendChild(node);
            return node;
        };
        add('input', 'kop-network-natsap-only', { checked: false });
        add('input', 'kop-network-cross-region', { checked: false });
        add('input', 'kop-network-degree', { value: '0' });
        add('output', 'kop-network-degree-out');
        add('button', 'kop-network-reset-filters');

        const regionsToggle = el('button', { className: 'kop-network__legend-toggle' });
        regionsToggle.setAttribute('aria-expanded', 'false');
        regionsToggle.setAttribute('aria-controls', 'kop-network-regions');
        rail.appendChild(regionsToggle);
        const regionsPanel = el('div', { hidden: true });
        regionsPanel.setAttribute('id', 'kop-network-regions');
        rail.appendChild(regionsPanel);

        const legend = el('div');
        legend.setAttribute('id', 'kop-network-legend');
        root.appendChild(legend);

        const colour = el('select', { value: 'kind' });
        colour.setAttribute('id', 'kop-network-colour-mode');
        root.appendChild(colour);

        const railToggle = el('button');
        railToggle.setAttribute('id', 'kop-network-filters-toggle');
        railToggle.setAttribute('aria-expanded', 'false');
        root.appendChild(railToggle);

        return { rail, railToggle, regionsToggle, regionsPanel, legend, colour };
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

    /** Fire every timer that is due, as the browser would once idle. */
    function runTimers() {
        let fired = 0;
        while (timers.length && fired < 100) {
            const due = timers.slice().sort((a, b) => a.at - b.at);
            timers = [];
            clock = Math.max(clock, due[due.length - 1].at);
            due.forEach((entry) => { entry.fn(); fired++; });
        }
        return fired;
    }

    return {
        sandbox, canvas, ops, fire, motion, flushFrames, runTimers,
        document: document_, buildRail, mediaListeners,
        pending: () => queue.length,
        labelCalls, labelBoxes,
        resetOps: () => {
            Object.keys(ops).forEach((k) => { ops[k] = 0; });
            labelCalls.length = 0;
            labelBoxes.length = 0;
        }
    };
}

/* ------------------------------------------------------------------ run -- */

function run() {
    const graph = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'graph.json'), 'utf8'));
    const layout = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'layout.json'), 'utf8'));

    /* Names the data has no connection for at all: three today, and the
     * slider's floor of zero is what keeps them on the map. */
    const unconnected = graph.nodes.filter((n) => n.degree === 0).length;

    const {
        sandbox, canvas, ops, fire, motion, flushFrames, runTimers,
        document: doc, buildRail, mediaListeners, labelCalls, labelBoxes, resetOps
    } = buildSandbox();
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
    /* Labels are limited by collision, not by a degree threshold: where two
     * names cannot both fit, the better-connected one wins. */
    const drawnNames = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    check(drawnNames.length > 5 && drawnNames.length <= scene.nodes.length,
        'a dense scene drew ' + drawnNames.length + ' labels for ' + scene.nodes.length + ' nodes',
        'a 907-node scene fits ' + drawnNames.length + ' names without overlap');
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));
    check(drawnNames.indexOf('WWASPS') !== -1,
        'the best-connected node lost its label to a smaller one');

    /* Edges pull back at a wide view, but only a little: a relationship
     * nobody can see is not context, it is a missing fact. */
    const wideFade = sandbox.KOPNetworkCanvas.edgeFadeFor(0.25);
    check(wideFade >= 0.6 && wideFade < 1 && sandbox.KOPNetworkCanvas.edgeFadeFor(1.5) === 1,
        'edges are drawn at ' + wideFade + ' of full strength at a wide view');

    /* Direction outranks category: "became" and "acquired" are the two
     * statements here that are wrong read backwards, so they are the two
     * that carry an arrow. */
    const rebrand = store.edges.find((e) => e.direction === 'renamed');
    const bought = store.edges.find((e) => e.direction === 'acquirer');
    const plain = store.edges.find((e) => e.direction === 'none' && e.category === 'corporate');
    const family = store.edges.find((e) => e.category === 'family');
    check(!!rebrand && !!bought && !!plain && !!family, 'the graph lost one of the edge kinds under test');
    const styleOf = (e) => sandbox.KOPNetworkCanvas.styleFor(e, false);
    check(styleOf(rebrand).arrow === true, 'a rebrand is drawn without a direction');
    check(styleOf(bought).arrow === true, 'an acquisition is drawn without a direction');
    check(!styleOf(plain).arrow && !styleOf(family).arrow,
        'an undirected connection was given an arrow it cannot justify');
    check(styleOf(rebrand).colour !== styleOf(bought).colour,
        'a rebrand and an acquisition are drawn the same');
    check(styleOf(rebrand).colour !== styleOf(plain).colour,
        'a rebrand is drawn the same as plain ownership');
    /* Married, divorced, siblings: the only edges joining two people. */
    check(styleOf(family).colour !== styleOf(plain).colour &&
        styleOf(family).colour !== sandbox.KOPNetworkCanvas.EDGE_STYLES._default.colour,
        'relationships between people are drawn like everything else');

    /* Arrows are painted, not just configured. */
    resetOps();
    viewport.setTransform(1.2, renderer.width / 2, renderer.height / 2);
    viewport.centreOn(rebrand.target);
    const fillsBefore = ops.fill;
    renderer.draw();
    check(ops.fill > fillsBefore, 'nothing was filled on a frame holding a directed edge');

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

    /* A name is part of its node: a tap on the text selects the thing it
     * names. The renderer publishes the boxes it drew, so this uses the box
     * of a label that is actually on screen. */
    renderer.draw();
    const named = (renderer.labelHits || []).find((entry) => entry.node.id === 'wwasps');
    check(!!named, 'WWASPS has no drawn label to tap');
    if (named) {
        const bx = (named.box[0] + named.box[2]) / 2;
        const by = (named.box[1] + named.box[3]) / 2;
        selected = null;
        fire('pointerdown', 1, bx, by);
        fire('pointerup', 1, bx, by);
        check(selected === named.node, 'a tap on a name did not select its node');
        /* And the text is hoverable, so the cursor tells you it is. */
        fire('pointermove', 9, bx, by);
        check(hovered === named.node, 'hovering a name did not light its node');
        fire('pointermove', 9, 2, 2);
    }

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

    /* --- the opening view --- */

    /* The map opens on a handful of the networks that shaped the industry
     * and nothing else. Which ones is curated in network-overrides.json,
     * because influence and prevalence are an editorial judgement that no
     * count reproduces: Synanon has six recorded connections and belongs at
     * the top; plenty of nodes with thirty do not. */
    const seeds = store.seeds();
    check(seeds.length > 0 && seeds.length <= 12,
        'the map opens on ' + seeds.length + ' organisations',
        'opens on: ' + seeds.map((n) => n.name).join(', '));
    check(graph.meta.headline && graph.meta.headline.length === seeds.length,
        'the opening view is not coming from the curated list');
    ['WWASPS', 'Synanon', 'Teen Challenge'].forEach((name) => {
        check(seeds.some((n) => n.name === name), 'the map does not open on ' + name);
    });
    check(seeds.some((n) => n.degree < 8),
        'the opening view is just the best-connected nodes, not a curated list');

    const opening = focus.scene();
    check(opening.nodes.length === seeds.length,
        'the opening view draws ' + opening.nodes.length + ' nodes, expected ' + seeds.length);
    /* The promise: a name nobody asked for is not on the map at all. */
    const famous = store.node('provo-canyon-school');
    check(!opening.nodeIds[famous.id],
        'a node nobody has opened or searched for is on the opening map');
    check(opening.nodes.every((n) => store.seedIds()[n.id]),
        'the opening view holds something that is not one of the organisations it opens on');

    /* Everything on the map is named: what is on screen is there because
     * somebody asked for it, and an unnamed dot is no use to them. */
    focus.start();
    flushFrames();
    resetOps();
    renderer.draw();
    const openingLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    const openingNames = focus.scene().nodes.map((n) => n.name);
    check(openingLabels.length === openingNames.length,
        'the opening view drew ' + openingLabels.length + ' labels for ' +
        openingNames.length + ' organisations',
        'opening view names all ' + openingLabels.length + ' of its organisations');
    openingNames.forEach((name) => {
        check(openingLabels.indexOf(name) !== -1, 'the opening view left ' + name + ' unnamed');
    });
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap in the opening view: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

    /* A shallow view takes a block nearer the shape of the stage, so the
     * fit can zoom in and fill it, rather than one long row with the rest
     * of the stage empty above and below. */
    const openingRows = new Set(focus.scene().nodes.map((n) => Math.round(focus.positionOf(n).y))).size;
    /* Six names wrap to two rows at this stage width on their own; the
     * shallow rule is what takes them to a block near the shape of the
     * stage, which is several. */
    check(openingRows >= 3,
        'the opening view is ' + openingRows + ' row(s) of ' + focus.scene().nodes.length + ' across an empty stage',
        'the opening view fills ' + openingRows + ' rows');
    /* And not a single column either: a block, not a line in either
     * direction. */
    const perRow = new Map();
    focus.scene().nodes.forEach((n) => {
        const key = Math.round(focus.positionOf(n).y);
        perRow.set(key, (perRow.get(key) || 0) + 1);
    });
    check(Math.max(...perRow.values()) >= 2,
        'the opening view is a single column, one name per row');

    /* The grid exists so that every name fits: a force layout packs the
     * well-connected into a knot and leaves the corners empty, so names
     * collide in the middle of a mostly blank stage. Opening a hub is the
     * case that used to fail. */
    focus.select(store.node('wwasps'));
    flushFrames();
    resetOps();
    renderer.draw();
    const hubScene = focus.scene();
    const hubLabels = labelCalls.filter((c) => c.startsWith('text:'));
    check(hubLabels.length === hubScene.nodes.length,
        'the grid named ' + hubLabels.length + ' of ' + hubScene.nodes.length + ' nodes',
        'a ' + hubScene.nodes.length + '-node neighbourhood fits every name');
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap on the grid: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

    /* A name wider than its cell hangs over the edges of it, which is fine
     * in the middle of the board and not fine in the outermost column. */
    const clipped = labelBoxes.filter((b) => {
        const half = (String(b.t).length * 6) / 2;
        return b.x - half < 0 || b.x + half > renderer.width;
    });
    check(clipped.length === 0,
        clipped.length + ' names run off the edge of the canvas: ' +
        clipped.slice(0, 2).map((b) => b.t).join(', '));

    /* Every node in its own cell, spread over the stage rather than knotted
     * into one corner of it. */
    const gp = hubScene.nodes.map((n) => focus.positionOf(n));
    const gx = gp.map((p) => p.x);
    const gy = gp.map((p) => p.y);
    const midX = (Math.max(...gx) + Math.min(...gx)) / 2;
    const midY = (Math.max(...gy) + Math.min(...gy)) / 2;
    const quads = [0, 0, 0, 0];
    gp.forEach((p) => { quads[(p.x > midX ? 1 : 0) + (p.y > midY ? 2 : 0)]++; });
    const worst = Math.max(...quads) / Math.max(1, Math.min(...quads));
    check(worst <= 2.5,
        'the layout is lopsided: ' + quads.join('/') + ' nodes per quadrant',
        'nodes per quadrant: ' + quads.join('/'));

    /* The map is a hierarchy around the clicked node's row. Above it, the
     * people who ran things and, above them, the companies; below it, the
     * programmes and then everyone else. Roots sit in the centre whatever
     * they are. Within a band, rings run outwards from the centre row. */
    const bandOf = (n) => {
        if (n.kind === 'parent' || n.kind === 'association') return 0;
        if (n.kind === 'person') {
            const by = n.degreeByCategory || {};
            return ((by.leadership || 0) + (by.board || 0) + (by.corporate || 0)) > 0 ? 1 : 3;
        }
        if (n.kind === 'facility') return 2;
        return 4;
    };
    const hubNeighbours = store.neighbours('wwasps', true)
        .map((l) => l.other.id)
        .filter((id) => hubScene.nodeIds[id]);
    const rootY = focus.positionOf(store.node('wwasps')).y;
    const yOf = (n) => focus.positionOf(n).y;
    const others = hubScene.nodes.filter((n) => n.id !== 'wwasps');
    const band = (b) => others.filter((n) => bandOf(n) === b).map(yOf);
    const companies = band(0);
    const command = band(1);
    const programmes = band(2);
    const staff = band(3);
    check(companies.length && command.length && programmes.length,
        'too few bands on screen to test the hierarchy');
    check(Math.max(...companies) < Math.min(...command),
        'a company is drawn below a member of corporate staff');
    check(Math.max(...command) < rootY, 'corporate staff are not above the clicked node');
    check(Math.min(...programmes) > rootY, 'a programme is drawn above the clicked node');
    if (staff.length) {
        check(Math.min(...staff) > Math.max(...programmes),
            'other staff are drawn among the programmes rather than beneath them');
    }
    check(Math.min(...companies) === Math.min(...others.map(yOf)),
        'the companies are not at the top of the map');

    /* Rings outward: a node revealed by a parent in the same band is never
     * nearer the centre row than that parent. Companies are ordered by
     * ownership instead, which is checked separately below. */
    const parentsOf = (n) => store.neighbours(n.id, true).map((l) => l.other)
        .filter((o) => hubScene.nodeIds[o.id] && o.id !== 'wwasps' && bandOf(o) === bandOf(n));
    let wrappedBack = 0;
    others.forEach((n) => {
        if (bandOf(n) === 0) return;
        const inward = parentsOf(n).filter((o) => hubNeighbours.indexOf(o.id) !== -1);
        if (!inward.length || hubNeighbours.indexOf(n.id) !== -1) return;
        const dist = Math.abs(yOf(n) - rootY);
        if (inward.some((o) => dist < Math.abs(yOf(o) - rootY) - 1)) wrappedBack++;
    });
    check(wrappedBack === 0,
        wrappedBack + ' second-degree nodes sit nearer the centre row than the node that revealed them',
        'rings run outwards from the centre row in every band');

    /* Inside the company band, a company that owns another on screen sits
     * above it. Read off corporate edges between two companies, source
     * owning target as the board records them; rebrands are not ownership.
     * Checked on a facility's view, where the ownership chain runs several
     * companies deep. */
    focus.clear();
    flushFrames();
    focus.select(hub);
    flushFrames();
    const ownView = focus.scene();
    const companiesHere = new Set(ownView.nodes
        .filter((n) => n.id !== hub.id && bandOf(n) === 0).map((n) => n.id));
    let ownedAbove = 0;
    let ownershipPairs = 0;
    ownView.edges.forEach((e) => {
        if (e.category !== 'corporate' || e.direction === 'renamed') return;
        if (!companiesHere.has(e.sourceId) || !companiesHere.has(e.targetId)) return;
        ownershipPairs++;
        if (yOf(store.node(e.sourceId)) >= yOf(store.node(e.targetId))) ownedAbove++;
    });
    check(ownershipPairs > 0, 'no ownership between two companies is on screen, so the rule is untested');
    check(ownedAbove === 0,
        ownedAbove + ' of ' + ownershipPairs + ' companies are drawn below a company they own',
        'every company sits above the companies it owns (' + ownershipPairs + ' pairs)');
    focus.clear();
    flushFrames();
    focus.select(store.node('wwasps'));
    flushFrames();

    /* Whoever owned a programme is never left off: ownership is the question
     * this map exists to answer. */
    focus.clear();
    flushFrames();
    const facility = store.node('casa-grande-academy');
    focus.select(facility);
    flushFrames();
    const owned = focus.scene();
    const owners = store.neighbours(facility.id, true)
        .filter((l) => l.other.kind === 'parent' && l.edge.category === 'corporate');
    check(owners.length > 0, 'the probe facility has no recorded owner, so the rule is untested');
    owners.forEach((l) => {
        check(owned.nodeIds[l.other.id], 'opening a facility left out its owner ' + l.other.name);
    });

    /* The owner of what was clicked is a neighbour of it and would be on
     * screen anyway, so the rule is only really doing work for the
     * programmes that arrived on somebody's coat-tails. Those are the ones
     * to check. */
    focus.clear();
    flushFrames();
    focus.select(hub);
    flushFrames();
    const viaStaff = focus.scene();
    const direct = new Set(store.neighbours(hub.id, true).map((l) => l.other.id));
    const arrived = viaStaff.nodes.filter((n) =>
        n.kind === 'facility' && !direct.has(n.id) && n.id !== hub.id);
    check(arrived.length > 2, 'too few programmes arrived indirectly to test the ownership rule');
    let unowned = 0;
    arrived.forEach((n) => {
        const owns = store.neighbours(n.id, true)
            .filter((l) => l.other.kind === 'parent' && l.edge.category === 'corporate');
        if (owns.length && !owns.some((l) => viaStaff.nodeIds[l.other.id])) unowned++;
    });
    check(unowned === 0,
        unowned + ' programmes are on screen with a recorded owner that is not',
        'all ' + arrived.length + ' programmes that arrived indirectly show who owned them');

    /* But an owner brings only itself. Two facilities owned by the same
     * company are not each other's business; somebody who worked at both
     * is, and that is the only thing that puts a second one on screen. */
    let sisters = 0;
    owners.forEach((l) => {
        store.neighbours(l.other.id, true).forEach((sib) => {
            if (sib.other.id === facility.id) return;
            if (sib.other.kind !== 'facility') return;
            if (!owned.nodeIds[sib.other.id]) return;
            /* Allowed only if a person on screen worked at both. */
            const shared = store.neighbours(sib.other.id, true).some((s) =>
                s.other.kind === 'person' && owned.nodeIds[s.other.id] &&
                store.neighbours(s.other.id, true).some((t) => t.other.id === facility.id));
            if (!shared) sisters++;
        });
    });
    check(sisters === 0,
        sisters + ' sister facilities came along with the owner, without a shared member of staff',
        'opening a facility brings its owner and no sister facilities');

    focus.clear();
    flushFrames();

    /* --- hover previews --- */

    const mapX = hub.x;
    const mapY = hub.y;
    const neighbours = store.neighbours(hub.id, true);

    /* Hovering only means anything for a node that is on the map, so open
     * it first. Positions inside an opened view are its own, not the
     * board's, so everything below reads them through positionOf. */
    focus.select(hub);
    flushFrames();
    const at = (node) => focus.positionOf(node);

    focus.hover(hub);
    flushFrames();
    /* Reset after the gather has settled: flushing runs its own frames, and
     * this assertion is about the order within one of them. */
    resetOps();
    renderer.draw();
    const halos = labelCalls.filter((c) => c.startsWith('halo:'));
    const texts = labelCalls.filter((c) => c.startsWith('text:'));
    /* Each label strokes a halo in the surface colour before filling its
     * text. Drawn one at a time, the next halo paints over the last label,
     * and in a gathered neighbourhood - where names land close together -
     * labels visibly disappear. Every halo has to be laid down first. */
    const lastHalo = labelCalls.map((c) => c.startsWith('halo:')).lastIndexOf(true);
    const firstText = labelCalls.findIndex((c) => c.startsWith('text:'));
    check(halos.length === texts.length && halos.length > 0,
        'hover drew ' + halos.length + ' halos for ' + texts.length + ' labels');
    check(lastHalo < firstText,
        'a label halo was drawn after a label, so it erases the name before it');
    check(texts.length > 1 && texts.length <= store.neighbours(hub.id, true).length + 1,
        'a gathered neighbourhood drew ' + texts.length + ' names');
    check(texts.indexOf('text:' + hub.name) !== -1 || texts[0] === 'text:' + hub.name,
        'the hovered node itself went unlabelled');

    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap while hovering: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

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
        const p = at(link.other);
        const c = at(hub);
        const was = Math.hypot(p.x - c.x, p.y - c.y);
        const now = Math.hypot(p.x + off[0] - c.x, p.y + off[1] - c.y);
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
        x: (at(moved).x + movedOffset[0]) * t.k + t.x,
        y: (at(moved).y + movedOffset[1]) * t.k + t.y
    };
    check(viewport.nodeAt(movedScreen.x, movedScreen.y) === moved,
        'a gathered node could not be clicked where it was drawn');
    const staleScreen = { x: at(moved).x * t.k + t.x, y: at(moved).y * t.k + t.y };
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

    check(focus.chain().length === 1 && focus.chain()[0] === hub.id,
        'selecting a node did not start the trail');
    check(announced.indexOf(hub.name) === 0, 'the commit was not announced by name');

    const focused = focus.scene();
    const seedIds = store.seedIds();
    /* Once something is open, anything with no line to it is dropped: the
     * organisations the map opened with are leftovers unless they turn out
     * to connect to what was asked for. */
    const linked = new Set();
    focused.edges.forEach((e) => { linked.add(e.sourceId); linked.add(e.targetId); });
    check(focused.nodes.every((n) => linked.has(n.id) || n.id === hub.id),
        'an opened view kept a node with nothing connecting it to anything');
    check(Object.keys(seedIds).some((id) => !focused.nodeIds[id]),
        'opening a node left every one of the opening organisations on screen, connected or not');
    /* Opening a node puts it and everyone it touches on the map, on top of
     * the organisations the map opened with - and then opens out any person
     * among them, because a name with one line back to whatever revealed it
     * hides the thing worth knowing about them. */
    const expected = new Set([hub.id].concat(neighbours.map((l) => l.other.id)).concat(Object.keys(seedIds)));
    [...expected].forEach((id) => {
        const node = store.node(id);
        if (node && node.kind === 'person') {
            store.neighbours(id, true).forEach((l) => expected.add(l.other.id));
        }
    });
    /* ...and whoever owned any of it, one step up. */
    [...expected].forEach((id) => {
        store.neighbours(id, true).forEach((l) => {
            if (l.other.kind === 'parent' && l.edge.category === 'corporate') expected.add(l.other.id);
        });
    });
    /* ...minus whatever that left stranded. */
    [...expected].forEach((id) => {
        if (id !== hub.id && !linked.has(id)) expected.delete(id);
    });
    check(focused.nodes.length === expected.size,
        'opening ' + hub.name + ' showed ' + focused.nodes.length + ' nodes, expected ' + expected.size,
        'opening ' + hub.name + ': ' + focused.nodes.length + ' nodes, ' + focused.edges.length + ' edges');
    check(focused.nodes.every((n) => expected.has(n.id)),
        'opening a node put something on the map that nobody asked for');
    check(focused.nodes.length < whole.nodes.length, 'opening a node showed the whole graph');

    /* A person with one line back to whatever revealed them hides the thing
     * worth knowing: which programmes they turn up at. Whenever a name
     * surfaces, everywhere it connects to surfaces with it. */
    const surfaced = focused.nodes.filter((n) => n.kind === 'person' && store.neighbours(n.id, true).length > 1);
    check(surfaced.length > 0, 'no person surfaced when opening ' + hub.name + ', so the rule is untested');
    let hiddenPlaces = 0;
    surfaced.forEach((person) => {
        store.neighbours(person.id, true).forEach((link) => {
            if (!focused.nodeIds[link.other.id]) hiddenPlaces++;
        });
    });
    check(hiddenPlaces === 0,
        hiddenPlaces + ' places a surfaced person connects to were left off the map',
        surfaced.length + ' people surfaced, all their programmes with them');
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
    check(focus.chain().length === 2, 'clicking a neighbour did not add a step to the trail');
    /* The trail is a history, not a union: the view is now the neighbour's
     * own connections, and the first step stays only because it is one. */
    const twoDeep = focus.scene();
    check(twoDeep.nodeIds[next.id], 'the view is not centred on what was just clicked');
    const nextNeighbours = store.neighbours(next.id, true).map((l) => l.other.id);
    check(nextNeighbours.every((id) => twoDeep.nodeIds[id]),
        'clicking a node did not show all of its own connections',
        'two steps deep: ' + twoDeep.nodes.length + ' nodes around ' + next.name);
    /* Whatever else is on screen got there by the view's own rules - a
     * person opening out, an owner coming along - and so has a line to
     * something. Nothing is left over from the previous view unattached. */
    const attached = new Set();
    twoDeep.edges.forEach((e) => { attached.add(e.sourceId); attached.add(e.targetId); });
    const stranded = twoDeep.nodes.filter((n) => n.id !== next.id && !attached.has(n.id));
    check(stranded.length === 0,
        stranded.length + ' nodes carried over from the previous view with no line to anything');

    /* Stronger than "has a line to something": everything on screen is
     * reachable from what was clicked. Two of the opening organisations
     * share an edge and used to survive on it, unconnected to the view. */
    const reachable = new Set([next.id]);
    const adjacency = new Map();
    twoDeep.edges.forEach((e) => {
        if (!adjacency.has(e.sourceId)) adjacency.set(e.sourceId, []);
        if (!adjacency.has(e.targetId)) adjacency.set(e.targetId, []);
        adjacency.get(e.sourceId).push(e.targetId);
        adjacency.get(e.targetId).push(e.sourceId);
    });
    const pending2 = [next.id];
    while (pending2.length) {
        const at = pending2.shift();
        (adjacency.get(at) || []).forEach((o) => { if (!reachable.has(o)) { reachable.add(o); pending2.push(o); } });
    }
    const island = twoDeep.nodes.filter((n) => !reachable.has(n.id));
    check(island.length === 0,
        island.length + ' nodes on screen are not reachableable from what was clicked: ' +
        island.slice(0, 3).map((n) => n.name).join(', '));

    /* Expand mode: the trail is a union, and each click adds a
     * neighbourhood to the board instead of replacing it. */
    focus.setMode('expand');
    flushFrames();
    check(focus.mode() === 'expand', 'the mode did not switch');
    const union = focus.scene();
    const hubOwn = store.neighbours(hub.id, true).map((l) => l.other.id);
    check(hubOwn.every((id) => union.nodeIds[id]) && nextNeighbours.every((id) => union.nodeIds[id]),
        'expand mode does not show both neighbourhoods at once',
        'expand mode: ' + union.nodes.length + ' nodes for two clicks');
    check(union.nodes.length >= twoDeep.nodes.length,
        'expand mode showed fewer nodes than focus mode did for the same trail');
    focus.setMode('focus');
    flushFrames();
    check(focus.scene().nodes.length === twoDeep.nodes.length,
        'switching back to focus did not restore the focused view');

    focus.truncateTo(0);
    flushFrames();
    check(focus.chain().length === 1 && focus.chain()[0] === hub.id,
        'truncating to the first crumb did not go back to it');

    focus.clear();
    flushFrames();
    check(focus.chain().length === 0, 'clearing did not leave the trail empty');
    check(hub.x === mapX && hub.y === mapY, 'the stored layout was disturbed');
    const reopened = focus.scene();
    check(reopened.nodes.length === store.seeds().length,
        'clearing left ' + reopened.nodes.length + ' nodes, expected the ' +
        store.seeds().length + ' the map opens on');
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

    /* ---------------------------------------------------------- filters -- */

    /* The wording PHP puts in the rail, which the legend reads back rather
     * than keeping a second copy of. */
    const KIND_WORDS = {
        person: 'People', facility: 'Facilities', parent: 'Parent companies',
        association: 'Trade groups', government: 'Government bodies',
        church: 'Churches', other: 'Other'
    };
    const shell = buildRail(graph.meta, (name, value) => {
        if (name === 'kop-network-kind') return KIND_WORDS[value] || value;
        if (name === 'kop-network-chain') return value || 'No recorded owner';
        return String(value);
    });

    store.resetFilters();
    /* The opening view is all parent companies, so open one first or the
     * legend has a single kind to talk about. */
    focus.select(hub);
    flushFrames();
    let applied = 0;
    const rail = sandbox.KOPNetworkFilters.create({
        store,
        renderer,
        document: doc,
        announce: () => {},
        onChange: () => {
            applied++;
            const next = focus.scene();
            renderer.setScene(next);
            viewport.setScene(next);
        },
        scene: () => focus.scene(),
        redraw: () => {}
    });
    rail.start();

    const kindBox = (kind) => doc.querySelectorAll('input[name="kop-network-kind"]')
        .find((input) => input.value === kind);

    /* --- checkboxes reach the store --- */

    const peopleBox = kindBox('person');
    peopleBox.checked = false;
    peopleBox.dispatch('change');
    check(!store.filters.kinds.person && applied === 1,
        'unchecking a kind did not reach the store');
    check(store.visible().nodes.every((n) => n.kind !== 'person'),
        'the view still holds people after the kind was unchecked');
    peopleBox.checked = true;
    peopleBox.dispatch('change');
    check(store.filters.kinds.person === true, 're-checking a kind did not restore it');

    const closedBox = doc.querySelectorAll('input[name="kop-network-status"]')
        .find((input) => input.value === 'closed');
    closedBox.checked = false;
    closedBox.dispatch('change');
    check(!store.filters.statuses.closed && store.visible().nodes.every((n) => n.status !== 'closed'),
        'the status checkbox did not filter by status');
    closedBox.checked = true;
    closedBox.dispatch('change');

    const natsapBox = doc.getElementById('kop-network-natsap-only');
    natsapBox.checked = true;
    natsapBox.dispatch('change');
    check(store.filters.natsapOnly === true && store.visible().nodes.every((n) => n.natsap),
        'the NATSAP toggle did not reach the store');
    natsapBox.checked = false;
    natsapBox.dispatch('change');

    const crossBox = doc.getElementById('kop-network-cross-region');
    crossBox.checked = true;
    crossBox.dispatch('change');
    check(store.filters.crossRegionOnly === true, 'the cross-group toggle did not reach the store');
    check(renderer.crossRegionMode === true,
        'the cross-group toggle did not tell the renderer to draw those edges as the subject');
    crossBox.checked = false;
    crossBox.dispatch('change');
    check(renderer.crossRegionMode === false, 'the renderer stayed in the cross-group style');

    /* --- the slider waits for the thumb to stop --- */

    const slider = doc.getElementById('kop-network-degree');
    const sliderOut = doc.getElementById('kop-network-degree-out');
    check(sliderOut.textContent === 'any', 'the slider reads "' + sliderOut.textContent + '" at zero, not "any"');

    const beforeSlider = store.filters.minDegree;
    slider.value = '4';
    slider.dispatch('input');
    check(sliderOut.textContent === '4', 'the slider output did not follow the thumb');
    check(store.filters.minDegree === beforeSlider,
        'the slider applied its filter mid-drag instead of waiting for a pause');
    runTimers();
    check(store.filters.minDegree === 4, 'the slider never applied its filter after the pause');

    /* Dragging across several values must not queue several applications. */
    const appliedBefore = applied;
    ['5', '6', '7'].forEach((value) => { slider.value = value; slider.dispatch('input'); });
    runTimers();
    check(applied === appliedBefore + 1,
        'a slider drag applied ' + (applied - appliedBefore) + ' times, expected once');
    check(store.filters.minDegree === 7, 'the slider settled on the wrong value');

    /* --- reset puts the rail and the store back together --- */

    doc.getElementById('kop-network-reset-filters').dispatch('click');
    check(store.filters.minDegree === 0 && store.visible().nodes.length === graph.nodes.length,
        'Reset filters did not restore the whole map');
    check(slider.value === '0' && sliderOut.textContent === 'any',
        'Reset filters left the slider showing its old value');
    check(doc.querySelectorAll('input[name="kop-network-kind"]').every((i) => i.checked),
        'Reset filters left a kind unchecked in the rail');
    check(renderer.crossRegionMode === false, 'Reset filters left the cross-group edge styling on');

    /* --- the legend --- */

    const legendLabels = () => shell.legend.querySelectorAll('span')
        .filter((s) => s.className === 'kop-network__legend-label')
        .map((s) => s.textContent);
    const legendMarks = () => shell.legend.querySelectorAll('canvas');

    let labels = legendLabels();
    check(labels.indexOf('Facilities') !== -1,
        'the legend does not use the rail wording; it shows ' + JSON.stringify(labels.slice(0, 3)));
    check(labels.indexOf('Closed or rebranded') !== -1 && labels.indexOf('NATSAP member') !== -1,
        'the legend does not say what the non-colour marks mean');
    check(legendMarks().length === labels.length,
        'the legend has ' + legendMarks().length + ' swatches for ' + labels.length + ' rows',
        'legend: ' + labels.length + ' rows in kind mode');
    check(legendMarks().every((c) => c.width > 0),
        'a legend swatch was never painted');

    /* It lists what is in view, not what exists. */
    check(legendLabels().indexOf('Churches') === -1,
        'the legend lists a kind that is nowhere on screen');
    const peopleShowing = focus.scene().nodes.some((n) => n.kind === 'person');
    check(peopleShowing === (legendLabels().indexOf('People') !== -1),
        'the legend and the map disagree about whether any people are showing');

    /* Colour mode switches what the legend is about. */
    shell.colour.value = 'chain';
    shell.colour.dispatch('change');
    labels = legendLabels();
    check(renderer.colourMode === 'chain', 'the colour-mode select did not reach the renderer');
    check(labels.indexOf('UHS') !== -1 && labels.indexOf('No recorded owner') !== -1,
        'the chain legend does not list the chains in view',
        'legend: ' + labels.length + ' rows in chain mode');
    check(labels.indexOf('Facilities') === -1, 'the chain legend still lists the kinds');
    shell.colour.value = 'kind';
    shell.colour.dispatch('change');

    /* The legend describes exactly the kinds on screen, no more. */
    const openedRows = legendLabels();
    const kindsShowing = new Set(focus.scene().nodes.map((n) => KIND_WORDS[n.kind]));
    check(openedRows.filter((l) => Object.values(KIND_WORDS).indexOf(l) !== -1).length === kindsShowing.size,
        'the legend lists kinds that are not on screen',
        'legend: ' + openedRows.length + ' rows with ' + kindsShowing.size + ' kinds showing');

    /* Going back to the opening view narrows it again. */
    focus.clear();
    flushFrames();
    rail.renderLegend();
    check(legendLabels().length < openedRows.length,
        'the legend did not narrow when the map went back to its opening view');

    /* --- the rail's own controls --- */

    shell.regionsToggle.dispatch('click');
    check(shell.regionsToggle.getAttribute('aria-expanded') === 'true' && shell.regionsPanel.hidden === false,
        'the board-grouping section did not open');
    shell.regionsToggle.dispatch('click');
    check(shell.regionsToggle.getAttribute('aria-expanded') === 'false' && shell.regionsPanel.hidden === true,
        'the board-grouping section did not close again');

    /* The rail starts closed at every width: as a permanent column it took
     * width the labels need, and the filters are a second-order tool on a
     * map that grows by clicking. */
    motion.narrow = false;
    rail.syncRail();
    check(shell.rail.hidden === true, 'the rail is open by default on a wide screen');
    motion.narrow = true;
    rail.syncRail();
    check(shell.rail.hidden === true, 'the rail covers the map on a narrow screen');
    shell.railToggle.dispatch('click');
    check(shell.rail.hidden === false && shell.railToggle.getAttribute('aria-expanded') === 'true',
        'the Filters button did not open the rail');
    shell.railToggle.dispatch('click');
    check(shell.rail.hidden === true && shell.railToggle.getAttribute('aria-expanded') === 'false',
        'the Filters button did not close the rail');

    shell.railToggle.dispatch('click');
    motion.narrow = false;
    mediaListeners.forEach((fn) => fn({ matches: false }));
    check(shell.rail.hidden === true, 'crossing the breakpoint left a sheet open as a column');

    store.resetFilters();
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
