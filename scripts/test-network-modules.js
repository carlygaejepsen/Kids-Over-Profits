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
 * Covers store, canvas, viewport, focus, filters, search (ranking and
 * opening), the drawer and URL state.
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
        const width = String(b.t).length * 6;
        const align = b.align || 'center';
        const x0 = align === 'center' ? b.x - width / 2 : (align === 'left' ? b.x : b.x - width);
        return { t: b.t, x0, x1: x0 + width, y0: b.y, y1: b.y + LINE };
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
    const badgeCalls = [];
    const captionCalls = [];
    const yearsCalls = [];

    const ctx = {
        setTransform() {}, clearRect() {}, save() {}, restore() {},
        beginPath: bump('beginPath'), closePath() {},
        moveTo: bump('moveTo'), lineTo: bump('lineTo'), arc: bump('arc'),
        quadraticCurveTo() {}, arcTo() {}, setLineDash() {},
        fill: bump('fill'), stroke: bump('stroke'),
        fillText: (t, x, y) => {
            ops.fillText++;
            /* The "+N" off-screen pill is text too, but it is not a name. */
            if (/^\+\d+$/.test(String(t))) { badgeCalls.push(t); return; }
            /* What a line says is written in italic on the line; it is not a
             * name either. */
            if (String(ctx.font || '').indexOf('italic') === 0) { captionCalls.push(t); return; }
            /* The years line under a name is drawn at its own smaller size;
             * it belongs to the name above it and is not a label of its own. */
            if (String(ctx.font || '').indexOf('9.5px') === 0) { yearsCalls.push(t); return; }
            labelCalls.push('text:' + t);
            /* textAlign matters: a label that could not fit below its node is
             * drawn beside it, left or right aligned, and its box is then on
             * one side of x rather than straddling it. */
            labelBoxes.push({ t, x, y, align: ctx.textAlign || 'center' });
        },
        strokeText: (t) => { ops.strokeText++; labelCalls.push('halo:' + t); },
        measureText: (t) => ({ width: String(t).length * 6 })
    };

    const listeners = new Map();
    /* The stage the map believes it has. Mutable so the same modules can be
     * driven at a phone width without building a second sandbox. */
    const stage = { width: WIDTH, height: HEIGHT };
    const canvas = {
        style: {},
        width: 0,
        height: 0,
        getContext: () => ctx,
        getBoundingClientRect: () => ({ left: 0, top: 0, width: stage.width, height: stage.height }),
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
        path.join('js', 'network-map', 'search.js'),
        path.join('js', 'network-map', 'drawer.js'),
        path.join('js', 'network-map', 'url-state.js'),
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
        labelCalls, labelBoxes, badgeCalls, yearsCalls, captionCalls,
        /* The stage the map believes it has, so the same modules can be run
         * at a phone width without a second sandbox. */
        setStage: (width, height) => { stage.width = width; stage.height = height; },
        resetOps: () => {
            Object.keys(ops).forEach((k) => { ops[k] = 0; });
            labelCalls.length = 0;
            labelBoxes.length = 0;
            badgeCalls.length = 0;
            captionCalls.length = 0;
            yearsCalls.length = 0;
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
        document: doc, buildRail, mediaListeners, labelCalls, labelBoxes, badgeCalls, yearsCalls, captionCalls, resetOps, setStage
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
        sandbox.KOPNetworkStore.statusBucket('closed or rebranded') === 'closed' &&
        sandbox.KOPNetworkStore.statusBucket('closed') === 'closed' &&
        sandbox.KOPNetworkStore.statusBucket('rebranded') === 'rebranded',
        'status bucketing does not match the three filter checkboxes');

    /* The default connections are ownership, staff, family and membership. Board seats,
     * referrals, survivors and "other" are a checkbox away: on by default
     * they put Alcoholics Anonymous and Bill Lane's companies in Synanon's
     * view. A name whose only connections are those drops off until they
     * are turned on. */
    const defaultCats = store.filters.categories;
    check(['corporate', 'leadership', 'staff', 'clinical', 'admissions', 'unknown', 'family', 'membership']
        .every((c) => defaultCats[c]) &&
        ['board', 'referral', 'survivor', 'other'].every((c) => !defaultCats[c]),
        'the default connection types are not ownership, staff, family and membership: ' + Object.keys(defaultCats).join(', '));
    const defaultView = store.visible();
    check(defaultView.edges.every((e) => defaultCats[e.category]),
        'the default view drew a connection type that is off by default');

    /* With every connection type on, the view is the whole map. The
     * slider's floor is zero connections, so the unconnected names - Judge
     * Rotenberg, IECA, Accelerated Christian Education - are on it like
     * everything else. */
    graph.meta.categories.forEach((c) => { store.filters.categories[c] = true; });
    store.touch();
    const all = store.visible();
    check(all.nodes.length === graph.nodes.length,
        'with every connection type on, the map shows ' + all.nodes.length + ' of ' + graph.nodes.length + ' nodes',
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
    /* Curated, not ranked: at least one of them is not among that many
     * best-connected nodes. */
    const ranked = store.visible().nodes.map((n) => n.degree).sort((a, b) => b - a);
    const cutoff = ranked[Math.min(seeds.length, ranked.length) - 1];
    check(seeds.some((n) => n.degree < cutoff),
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

    /* The lines say what they are, and never over a name. */
    const captions = renderer.edgeCaptions || [];
    check(captions.length > 0 && captionCalls.length === captions.length,
        'the WWASPS view wrote ' + captions.length + ' captions on its lines',
        captions.length + ' of ' + hubScene.edges.length + ' lines in the WWASPS view are captioned');
    const overName = captions.filter((c) => (renderer.labelHits || []).some((l) => l.box &&
        c.box[0] < l.box[2] && c.box[2] > l.box[0] && c.box[1] < l.box[3] && c.box[3] > l.box[1]));
    check(overName.length === 0, 'a caption is written over a name: ' + (overName[0] && overName[0].text));
    check(captions.every((c) => c.box[0] >= 0 && c.box[2] <= renderer.width && c.box[1] >= 0 && c.box[3] <= renderer.height),
        'a caption runs off the canvas');

    /* A name wider than its cell hangs over the edges of it, which is fine
     * in the middle of the board and not fine in the outermost column. */
    const clipped = labelBoxes.filter((b) => {
        const width = String(b.t).length * 6;
        const align = b.align || 'center';
        const x0 = align === 'center' ? b.x - width / 2 : (align === 'left' ? b.x : b.x - width);
        return x0 < 0 || x0 + width > renderer.width;
    });
    check(clipped.length === 0,
        clipped.length + ' names run off the edge of the canvas: ' +
        clipped.slice(0, 2).map((b) => b.t).join(', '));

    /* A trace never crosses a node it does not connect. Routes are
     * published by the renderer with their points; every straight leg is
     * checked against every other node's box - shape, clearance and label -
     * because a line through a name reads as a relationship with it. */
    let crossings = 0;
    let legs = 0;
    const routes = renderer.routes || [];
    const boxes = renderer.blockers || [];
    routes.forEach((route) => {
        const a = route.edge.source._i;
        const b = route.edge.target._i;
        for (let i = 1; i < route.pts.length; i++) {
            const p = route.pts[i - 1];
            const q = route.pts[i];
            legs++;
            const x0 = Math.min(p[0], q[0]) + 0.5, x1 = Math.max(p[0], q[0]) - 0.5;
            const y0 = Math.min(p[1], q[1]) + 0.5, y1 = Math.max(p[1], q[1]) - 0.5;
            boxes.forEach((box, j) => {
                if (j === a || j === b) return;
                if (x1 > box[0] && x0 < box[2] && y1 > box[1] && y0 < box[3]) crossings++;
            });
        }
    });
    check(routes.length > 10 && legs > routes.length, 'too few routes to test the router');
    check(crossings === 0,
        crossings + ' route legs cross a node they do not connect',
        routes.length + ' routes, ' + legs + ' legs, none through a node they do not connect');

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
    /* Staff one step from the click sit beside it in the centre row
     * (0592dd5); the rest of the staff go beneath the programmes. */
    const staff = others.filter((n) => bandOf(n) === 3 &&
        !(hubNeighbours.indexOf(n.id) !== -1 && Math.abs(yOf(n) - rootY) < 1)).map(yOf);
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
    /* Opening a node puts it and everyone it touches on the map - not the
     * organisations the map opened with, which belong to the opening view -
     * and then opens out any person among them, because a name with one
     * line back to whatever revealed it hides the thing worth knowing about
     * them. */
    const expected = new Set([hub.id].concat(neighbours.map((l) => l.other.id)));
    [...expected].forEach((id) => {
        const node = store.node(id);
        if (node && node.kind === 'person') {
            store.neighbours(id, true).forEach((l) => expected.add(l.other.id));
        }
    });
    /* ...and whoever owned any programme in it, one step up. */
    [...expected].forEach((id) => {
        if (store.node(id).kind !== 'facility') return;
        store.neighbours(id, true).forEach((l) => {
            if (l.other.kind === 'parent' && l.edge.category === 'corporate' &&
                (!l.outgoing || l.edge.direction === 'none') && l.edge.direction !== 'renamed') expected.add(l.other.id);
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

    /* A company's view is its own connections and where its people turn
     * up, nothing further out. Synanon's view used to bring sixteen names:
     * the owner rule ran for companies and in both directions, pulling in
     * CEDU's owners and Leadership Dynamics' holdings, and Universal Health
     * Services rode in on the chain as an opening organisation. */
    const synanon = store.node('synanon');
    if (synanon) {
        focus.clear();
        flushFrames();
        focus.select(synanon);
        flushFrames();
        const synView = focus.scene();
        ['universal-health-services', 'the-brown-schools', 'holiday-magic', 'mind-dynamics'].forEach((id) => {
            check(!synView.nodeIds[id], "Synanon's view brought in " + id + ', which it does not connect to');
        });
        /* Membership is lineage and reads as a line: AA above Dederich
         * above Synanon, with AA the name in its row nearest Dederich. */
        const aa = store.node('alcoholics-anonymous');
        const chuck = store.node('charles-chuck-dederich');
        check(synView.nodeIds[aa.id] && synView.nodeIds[chuck.id],
            "Synanon's view left out Dederich's membership of AA");
        if (synView.nodeIds[aa.id] && synView.nodeIds[chuck.id]) {
            const pa = focus.positionOf(aa), pc = focus.positionOf(chuck), ps = focus.positionOf(synanon);
            check(pa.y < pc.y && pc.y < ps.y,
                'AA, Dederich and Synanon are not drawn top to bottom',
                'AA above Dederich above Synanon');
            const rowmates = synView.nodes.filter((n) => Math.abs(focus.positionOf(n).y - pa.y) < 1);
            const nearest = rowmates.sort((a, b) =>
                Math.abs(focus.positionOf(a).x - pc.x) - Math.abs(focus.positionOf(b).x - pc.x))[0];
            check(nearest.id === aa.id,
                'AA is not lined up over Dederich: ' + nearest.name + ' is nearer him in that row');
        }
        focus.clear();
        flushFrames();
        focus.select(hub);
        flushFrames();
    }

    /* A person with one line back to whatever revealed them hides the thing
     * worth knowing: which programmes they turn up at. Whenever a name
     * surfaces, everywhere it connects to surfaces with it. */
    /* The people this applies to are the ones one step from what was
     * clicked. A person who arrives through another person's expansion does
     * not expand in turn (focus.js, visibleIds), or one well-connected name
     * would pull in the whole board. */
    const direct1 = new Set(store.neighbours(hub.id, true).map((l) => l.other.id));
    const surfaced = focused.nodes.filter((n) => n.kind === 'person' && direct1.has(n.id) &&
        store.neighbours(n.id, true).length > 1);
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

    /* The view frames what it settled - unless framing all of it would have
     * cost names, in which case it zooms in and leaves the rest a pan away.
     * That is the only licence to leave a node off the stage, so it is
     * checked against the renderer: the whole-block frame must really drop
     * names, and the clicked node must still be on the stage. */
    const offStage = focused.nodes.filter((n) => {
        const p = focus.positionOf(n);
        const sxp = p.x * t.k + t.x;
        const syp = p.y * t.k + t.y;
        return sxp < -1 || sxp > WIDTH + 1 || syp < -1 || syp > HEIGHT + 1;
    }).length;
    if (offStage > 0) {
        const pts = focused.nodes.map((n) => { const p = focus.positionOf(n); return { x: p.x, y: p.y, r: n.r }; });
        const whole = viewport.frameOf(pts, 70);
        const wouldDrop = renderer.dropsAt(focused.nodes, (n) => focus.positionOf(n), whole.k, whole.x, whole.y);
        check(wouldDrop > 0,
            'the focused view left ' + offStage + ' of its own nodes off the stage though framing all of it drops no names');
        const hp = focus.positionOf(hub);
        check(hp.x * t.k + t.x >= 0 && hp.x * t.k + t.x <= WIDTH && hp.y * t.k + t.y >= 0 && hp.y * t.k + t.y <= HEIGHT,
            'the clicked node is off the stage');
        notes.push('framing all ' + focused.nodes.length + ' would drop ' + wouldDrop +
            ' names, so ' + offStage + ' are left a pan away');
    }

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
    check(store.filters.minDegree === 0 && store.visible().nodes.length === defaultView.nodes.length,
        'Reset filters did not restore the default map');
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
    check(labels.indexOf('Closed') !== -1 && labels.indexOf('Rebranded (carried on under another name)') !== -1 && labels.indexOf('NATSAP member') !== -1,
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

    /* ------------------------------------------- the fix list of 2026-09-17 -- */

    /* 2b.1. David Gilcrease was reported as showing two connections out of
     * five. The two are exactly the edges that cross a board group, which is
     * what the cross-group toggle leaves visible, so the toggle was the
     * suspect. Two things have to hold: opening the person who reveals him
     * brings all five, and Reset filters puts the toggle back. */
    focus.clear();
    flushFrames();
    const courtney = store.node('jeannie-courtney');
    const gilcrease = store.node('david-gilcrease');
    check(!!courtney && !!gilcrease, 'the Gilcrease probe nodes are missing from the graph');
    const gilcreaseEdges = store.neighbours(gilcrease.id, true);
    check(gilcreaseEdges.length === 5,
        'David Gilcrease has ' + gilcreaseEdges.length + ' connections in the data, the report was about five',
        'David Gilcrease: ' + gilcreaseEdges.length + ' connections in graph.json');
    focus.select(courtney);
    flushFrames();
    const courtneyScene = focus.scene();
    check(courtneyScene.nodeIds[gilcrease.id],
        'opening Jeannie Courtney did not surface David Gilcrease at all');
    const missingLinks = gilcreaseEdges.filter((l) => !courtneyScene.nodeIds[l.other.id]);
    check(missingLinks.length === 0,
        'opening Jeannie Courtney showed ' + (gilcreaseEdges.length - missingLinks.length) +
        ' of the five Gilcrease connections, missing ' +
        missingLinks.map((l) => l.other.name).join(', '),
        'a surfaced person brings all ' + gilcreaseEdges.length + ' of their connections');

    store.setFilter('crossRegionOnly', true);
    store.resetFilters();
    check(store.filters.crossRegionOnly === false,
        'Reset filters left the cross-group toggle on, which is what hid three of the five');

    /* A node with connections that are not on screen says so, rather than
     * dragging them in: an owner still brings only itself, and the count is
     * how the reader knows there is more behind it. */
    const hidden = courtneyScene.hidden || {};
    let wrongCount = 0;
    courtneyScene.nodes.forEach((n) => {
        const off = store.neighbours(n.id, true)
            .filter((l) => !courtneyScene.nodeIds[l.other.id]).length;
        if ((hidden[n.id] || 0) !== off) wrongCount++;
    });
    check(wrongCount === 0, wrongCount + ' nodes report the wrong number of connections off screen');
    const marked = Object.keys(hidden).length;
    check(marked > 0,
        'nothing on screen reports a connection off screen, so the mark is untested',
        marked + ' of ' + courtneyScene.nodes.length + ' nodes carry a hidden-connection count');
    check(!hidden[courtney.id],
        'the node that was clicked reports hidden connections, but its own are all on screen');
    renderer.setScene(courtneyScene);
    resetOps();
    renderer.draw();
    check(badgeCalls.length === marked,
        'the renderer drew ' + badgeCalls.length + ' off-screen counts for ' + marked + ' marked nodes',
        'each of the ' + marked + ' marked nodes carries a +N pill');
    check(badgeCalls.every((t) => /^\+[1-9]\d*$/.test(t)), 'an off-screen pill reads something other than +N');

    /* 2b.2 Search. Ranking first, with no document: a name that starts
     * with what was typed beats one where a later word does, which beats a
     * match inside a word; the name beats an alias at the same level. */
    const Search = sandbox.KOPNetworkSearch;
    check(!!Search, 'search.js did not load');
    const top = (q) => Search.rank(store.nodes, q).map((h) => h.node.id);
    check(top('provo canyon')[0] === 'provo-canyon-school',
        'searching "provo canyon" does not put Provo Canyon School first: ' + top('provo canyon').slice(0, 3).join(', '));
    check(top('PROVO   Canyon!')[0] === 'provo-canyon-school', 'case and punctuation change the search result');
    check(Search.rank(store.nodes, 'a').length <= Search.LIMIT, 'search returned more than the listbox shows');
    check(Search.rank(store.nodes, '   ').length === 0, 'an empty search returned results');
    check(Search.rank(store.nodes, 'zzqqxx').length === 0, 'nonsense matched a name');
    const levels = Search.rank(store.nodes, 'academy').map((h) => h.level);
    check(levels.every((l, i) => i === 0 || l >= levels[i - 1]), 'search results are not ordered by match level');
    /* A word start beats a match in the middle of a word. */
    const synth = [
        { id: 'mid', name: 'Hillcrest', aliases: [], degree: 50 },
        { id: 'word', name: 'Blue Crest Ranch', aliases: [], degree: 1 },
        { id: 'pre', name: 'Crest Academy', aliases: [], degree: 1 },
        { id: 'alias', name: 'Somewhere Else', aliases: ['Crest Hall'], degree: 99 }
    ];
    check(Search.rank(synth, 'crest').map((h) => h.node.id).join(',') === 'pre,alias,word,mid',
        'ranking order is ' + Search.rank(synth, 'crest').map((h) => h.node.id).join(',') +
        ', expected prefix, alias prefix, word start, inside a word',
        'search ranks prefix, then alias prefix, then word start, then inside a word');
    check(Search.rank(synth, 'crest hall')[0].via === 'Crest Hall', 'an alias match does not say which alias');

    /* Picking a result that is not on the board puts it there with its
     * connections, the way a click would. */
    focus.clear();
    flushFrames();
    const target = store.node('provo-canyon-school');
    check(!focus.scene().nodeIds[target.id], 'the search target is already on the opening view, so the test proves nothing');
    const picked = Search.rank(store.nodes, 'provo canyon school')[0].node;
    focus.select(picked);
    flushFrames();
    const afterPick = focus.scene();
    check(afterPick.nodeIds[target.id] &&
        store.neighbours(target.id, true).every((l) => afterPick.nodeIds[l.other.id]),
        'a searched name did not arrive with its connections');

    /* Enter on the typed text opens every match together, in expand mode. */
    focus.clear();
    flushFrames();
    focus.setMode('focus');
    const many = Search.rank(store.nodes, 'aspen').map((h) => h.node);
    check(many.length > 1, 'fewer than two names match "aspen", so opening several is untested');
    focus.openAll(many);
    flushFrames();
    const together = focus.scene();
    check(focus.mode() === 'expand', 'opening several names did not switch to expand mode');
    check(many.every((n) => together.nodeIds[n.id]),
        'opening several matches left some of them off the board',
        'Enter opens all ' + many.length + ' "aspen" matches together');
    focus.setMode('focus');
    focus.clear();
    flushFrames();

    /* 2b.8 The drawer: whatever was opened last, its profile, and every
     * connection as a button that follows it. */
    const Drawer = sandbox.KOPNetworkDrawer;
    check(!!Drawer, 'drawer.js did not load');
    const drawerConfig = {
        directoryUrl: 'https://example.test/location-index/',
        facilityUrls: {},
        memorialUrl: 'https://example.test/memorial/'
    };
    const linkedFacility = store.nodes.find((n) => n.kind === 'facility' && n.facilityId);
    const unlinkedFacility = store.nodes.find((n) => n.kind === 'facility' && !n.facilityId);
    const aPerson = store.nodes.find((n) => n.kind === 'person');
    check(!!linkedFacility && !!unlinkedFacility, 'no facility with and without a record to test the profile link');
    drawerConfig.facilityUrls[linkedFacility.facilityId] = 'https://example.test/facility/probe/';
    const own = Drawer.profileFor(linkedFacility, drawerConfig);
    check(own && own.url === 'https://example.test/facility/probe/' && own.own,
        'a facility with a page does not link to it');
    const searchLink = Drawer.profileFor(unlinkedFacility, drawerConfig);
    check(searchLink && searchLink.url.indexOf('https://example.test/location-index/?search=') === 0 && !searchLink.own,
        'a facility with no page does not fall back to a location-index search');
    check(searchLink.url.indexOf('tti-program-index') === -1, 'the drawer sends a facility to the program index');
    check(Drawer.profileFor(aPerson, drawerConfig) === null, 'a person got a profile link to a search that finds nothing');

    const drawerEl = doc.createElement('aside');
    drawerEl.hidden = true;
    const drawerBody = doc.createElement('div');
    const drawerClose = doc.createElement('button');
    const drawer = Drawer.create({
        store, focus, config: drawerConfig, document: doc,
        drawer: drawerEl, body: drawerBody, close: drawerClose
    });
    focus.clear();
    flushFrames();
    drawer.update();
    check(drawerEl.hidden === true, 'the drawer is open with nothing selected');
    focus.select(hub);
    flushFrames();
    drawer.update();
    check(drawerEl.hidden === false && drawer.shownId() === hub.id, 'the drawer did not open on the selected name');
    const drawerButtons = drawerBody.querySelectorAll('.kop-network__drawer-link');
    check(drawerButtons.length === store.neighbours(hub.id, true).length,
        'the drawer lists ' + drawerButtons.length + ' connections for ' + hub.name +
        ', which has ' + store.neighbours(hub.id, true).length,
        'the drawer lists all ' + drawerButtons.length + ' connections of ' + hub.name);
    const follow = drawerButtons[0];
    follow.dispatch('click');
    flushFrames();
    drawer.update();
    check(focus.chain()[focus.chain().length - 1] === follow.getAttribute('data-id'),
        'a connection button in the drawer did not follow the connection');
    check(drawer.shownId() === follow.getAttribute('data-id'), 'the drawer did not move to the name it followed');
    drawerClose.dispatch('click');
    check(drawerEl.hidden === true, 'the close button did not close the drawer');
    drawer.update();
    check(drawerEl.hidden === true, 'the drawer reopened on the same name after being closed');
    focus.clear();
    flushFrames();

    /* URL state: the trail and the mode round-trip through the hash, and a
     * link someone was sent opens what it names. */
    const Url = sandbox.KOPNetworkUrlState;
    check(!!Url, 'url-state.js did not load');
    check(Url.format([], 'focus') === '', 'the opening view writes a hash');
    check(Url.format(['a', 'b c'], 'focus') === '#open=a,b%20c', 'a focus trail formats as ' + Url.format(['a', 'b c'], 'focus'));
    const round = Url.parse(Url.format(['provo-canyon-school', 'wwasps'], 'expand'));
    check(round.ids.join(',') === 'provo-canyon-school,wwasps' && round.mode === 'expand',
        'the hash does not round-trip: ' + JSON.stringify(round));
    check(Url.parse('#open=%E0%A4%A').ids.length === 0, 'a mangled hash threw or opened something');
    check(Url.parse('#mode=sideways').mode === null, 'an unknown mode was accepted');

    const fakeLocation = { href: 'https://example.test/network-map/', hash: '' };
    const fakeHistory = {
        replaceState(state, title, url) {
            fakeLocation.href = url;
            fakeLocation.hash = url.indexOf('#') === -1 ? '' : url.slice(url.indexOf('#'));
        }
    };
    const url = Url.create({ focus, location: fakeLocation, history: fakeHistory });
    focus.clear();
    flushFrames();
    focus.select(hub);
    flushFrames();
    url.write();
    check(fakeLocation.hash === '#open=' + hub.id, 'opening a name did not write it to the hash: ' + fakeLocation.hash);
    focus.clear();
    flushFrames();
    url.write();
    check(fakeLocation.hash === '', 'the opening view left a trail in the hash');

    fakeLocation.hash = '#open=wwasps,not-a-real-node,' + hub.id + '&mode=expand';
    url.read();
    flushFrames();
    check(focus.chain().join(',') === 'wwasps,' + hub.id && focus.mode() === 'expand',
        'a shared link did not open what it named: ' + focus.chain().join(',') + ' in ' + focus.mode(),
        'a shared link reopens its trail and skips a name the board no longer has');
    focus.setMode('focus');
    focus.clear();
    flushFrames();

    /* 2b.4 In expand mode the newest click is framed with its own
     * connections, not lost in a corner of everything opened so far. */
    focus.clear();
    flushFrames();
    focus.setMode('expand');
    focus.select(hub);
    flushFrames();
    const wholeK = viewport.transform.k;
    const second = focus.scene().nodes.find((n) => n.id !== hub.id && store.neighbours(n.id, true).length > 3);
    focus.select(second);
    flushFrames();
    const tx = viewport.transform;
    const onStageNow = (n) => {
        const p = focus.positionOf(n);
        const x = p.x * tx.k + tx.x;
        const y = p.y * tx.k + tx.y;
        return x >= -1 && x <= WIDTH + 1 && y >= -1 && y <= HEIGHT + 1;
    };
    const ownNodes = [second].concat(store.neighbours(second.id, true).map((l) => l.other))
        .filter((n) => focus.scene().nodeIds[n.id]);
    const ownOff = ownNodes.filter((n) => !onStageNow(n));
    /* The same licence as the focus view: connections may be left a pan
     * away only when framing all of them would cost names, and the click
     * itself stays on the stage. */
    let ownDrop = 0;
    if (ownOff.length) {
        const ownPts = ownNodes.map((n) => { const p = focus.positionOf(n); return { x: p.x, y: p.y, r: n.r }; });
        const ownFrame = viewport.frameOf(ownPts, 70);
        ownDrop = renderer.dropsAt(focus.scene().nodes, (n) => focus.positionOf(n), ownFrame.k, ownFrame.x, ownFrame.y);
    }
    check(ownOff.length === 0 || (ownDrop > 0 && onStageNow(second)),
        'in expand mode ' + ownOff.length + ' connections of the latest click are off the stage' +
            (onStageNow(second) ? ' though framing them drops no names' : ', the click among them'),
        ownOff.length
            ? 'expand mode keeps ' + second.name + ' on the stage; framing its ' + ownNodes.length +
                ' would drop ' + ownDrop + ' names, so ' + ownOff.length + ' are a pan away'
            : 'expand mode frames ' + second.name + ' and its connections at zoom ' + tx.k.toFixed(2));
    check(tx.k >= Math.min(wholeK, 1) * 0.5, 'expand mode zoomed right out on the second click');

    /* 2b.3 Reset view lays the board out again, so a dragged node goes
     * back into its cell. */
    focus.setMode('focus');
    focus.clear();
    flushFrames();
    focus.select(hub);
    flushFrames();
    const cellBefore = Object.assign({}, focus.positionOf(hub));
    const dt = viewport.transform;
    const at0 = { x: cellBefore.x * dt.k + dt.x, y: cellBefore.y * dt.k + dt.y };
    fire('pointerdown', 1, at0.x, at0.y);
    fire('pointermove', 1, at0.x + 60, at0.y + 30);
    fire('pointerup', 1, at0.x + 60, at0.y + 30);
    check(Math.abs(focus.positionOf(hub).x - cellBefore.x) > 1, 'the drag did not move the node, so reset is untested');
    focus.reframe();
    flushFrames();
    const cellAfter = focus.positionOf(hub);
    check(Math.abs(cellAfter.x - cellBefore.x) < 1 && Math.abs(cellAfter.y - cellBefore.y) < 1,
        'Reset view left a dragged node where it was dropped',
        'Reset view puts a dragged node back in its cell');
    focus.clear();
    flushFrames();

    /* 2b.5 to 2b.7: the fields the build now supplies reach the map. */
    const rebrandedNodes = store.nodes.filter((n) => n.status === 'rebranded');
    check(rebrandedNodes.length > 0, 'no node is rebranded, so the build did not derive the status');
    check(store.nodes.every((n) => ['open', 'closed', 'rebranded', 'unknown'].indexOf(n.status) !== -1),
        'a node has a status outside the four the rail offers');
    store.toggleIn('statuses', 'rebranded', false);
    check(store.visible().nodes.every((n) => n.status !== 'rebranded'), 'unchecking Rebranded left a rebranded node');
    check(store.visible().nodes.some((n) => n.status === 'closed'), 'unchecking Rebranded also hid the closed ones');
    store.resetFilters();

    const withYears = store.nodes.filter((n) => n.years);
    const withDeaths = store.nodes.filter((n) => n.deaths > 0);
    check(withYears.length > 100, 'only ' + withYears.length + ' nodes carry years', withYears.length + ' nodes carry years');
    check(withDeaths.length > 0, 'no node carries a death count', withDeaths.length + ' nodes carry a memorial death count');

    /* A dated node draws its years under its name, and a node with deaths
     * draws the red ring. */
    const dated = withYears.find((n) => n.kind === 'facility') || withYears[0];
    focus.clear();
    flushFrames();
    focus.select(dated);
    flushFrames();
    resetOps();
    renderer.draw();
    check(yearsCalls.indexOf(dated.years) !== -1,
        dated.name + ' is on the board without its years (' + dated.years + ')',
        dated.name + ' shows ' + dated.years + ' under its name');
    /* Every name on the stage is drawn with its years line; what the
     * legibility floor leaves off the stage is a pan away. */
    const datedLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    const dt4 = viewport.transform;
    const datedOnStage = focus.scene().nodes.filter((n) => {
        const p = focus.positionOf(n);
        const x = p.x * dt4.k + dt4.x;
        const y = p.y * dt4.k + dt4.y;
        return x >= 0 && x <= WIDTH && y >= 0 && y <= HEIGHT;
    });
    const unnamed = datedOnStage.filter((n) => datedLabels.indexOf(n.name) === -1);
    check(unnamed.length === 0,
        'with years drawn, ' + unnamed.length + ' of ' + datedOnStage.length + ' names on the stage were dropped: ' +
        unnamed.slice(0, 3).map((n) => n.name).join(', '));
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap with years lines: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));
    focus.clear();
    flushFrames();

    /* 2b.10 Starter views: the map can open on another curated list, and
     * a link carries the choice. */
    check(store.views()[0].key === 'default', 'the first view is not the default headline');
    const savedMeta = store.meta.views;
    store.meta.views = [store.views()[0], { key: 'probe', label: 'Probe', ids: ['provo-canyon-school', 'wwasps'] }];
    check(store.setView('probe') === true && store.view === 'probe', 'switching to a starter view failed');
    focus.restore([], 'focus');
    flushFrames();
    const probeOpen = focus.scene().nodes.map((n) => n.id).sort().join(',');
    check(probeOpen === 'provo-canyon-school,wwasps',
        'the probe view opened on ' + probeOpen, 'a starter view opens on its own organisations');
    check(store.setView('nonsense') === false && store.view === 'default', 'an unknown view was not refused');
    check(Url.format([], 'focus', 'probe') === '#view=probe', 'a starter view does not reach the hash');
    check(Url.parse('#view=probe').view === 'probe' && Url.parse('#view=Bad View').view === null,
        'the view in a hash does not parse, or a bad key was accepted');
    store.meta.views = savedMeta;
    store.setView('default');
    focus.restore([], 'focus');
    flushFrames();

    /* The Brown Schools and CEDU used to be drawn on top of each other: two
     * wide-labelled companies the force settle packed 52 units apart. The
     * band layout gives each a cell, and ownership order puts the acquirer
     * in the row above what it bought (e1188). */
    focus.clear();
    flushFrames();
    const brown = store.node('the-brown-schools');
    const cedu = store.node('cedu-family-of-services');
    check(!!brown && !!cedu, 'the Brown Schools / CEDU probe nodes are missing');
    focus.select(cedu);
    flushFrames();
    const bcScene = focus.scene();
    check(bcScene.nodeIds[brown.id], 'opening CEDU did not bring The Brown Schools, which acquired it');
    const pb = focus.positionOf(brown);
    const pc = focus.positionOf(cedu);
    check(pb.y < pc.y,
        'The Brown Schools is not in a row above CEDU, which it acquired',
        'The Brown Schools sits a row above CEDU (' + Math.round(pc.y - pb.y) + ' units)');
    check(Math.hypot(pb.x - pc.x, pb.y - pc.y) > brown.r + cedu.r + 20,
        'The Brown Schools and CEDU are drawn on top of each other');
    resetOps();
    renderer.draw();
    const bcLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    check(bcLabels.indexOf(brown.name) !== -1 && bcLabels.indexOf(cedu.name) !== -1,
        'one of The Brown Schools and CEDU lost its name');

    /* Every name is drawn at a phone width too: the rule is that a node is
     * never on screen unnamed, and 375 px is where the packer is under the
     * most pressure. */
    focus.clear();
    flushFrames();
    setStage(375, 640);
    renderer.resize();
    focus.reframe();
    flushFrames();
    resetOps();
    renderer.draw();
    const phoneScene = focus.scene();
    const phoneLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    check(phoneLabels.length === phoneScene.nodes.length,
        'at 375 px the opening view named ' + phoneLabels.length + ' of ' + phoneScene.nodes.length,
        'the opening view names all ' + phoneLabels.length + ' organisations at 375 px');
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap at 375 px: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

    focus.select(store.node('wwasps'));
    flushFrames();
    resetOps();
    renderer.draw();
    const phoneHub = focus.scene();

    /* A 43-node neighbourhood cannot fit on a phone at a size anybody can
     * read, and names are drawn at a fixed size whatever the zoom, so framing
     * the whole block would squeeze the rows together until names had to be
     * dropped. The map holds the zoom where the rows still clear each other
     * and lets the reader pan instead: what is off the stage is off the
     * stage, but everything on it is named. */
    const phoneT = viewport.transform;
    const onStage = phoneHub.nodes.filter((n) => {
        const p = focus.positionOf(n);
        const sxp = p.x * phoneT.k + phoneT.x;
        const syp = p.y * phoneT.k + phoneT.y;
        return sxp >= 0 && sxp <= 375 && syp >= 0 && syp <= 640;
    });
    const phoneHubLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    check(onStage.length > 0, 'nothing is on the stage at 375 px');
    onStage.forEach((n) => {
        check(phoneHubLabels.indexOf(n.name) !== -1,
            'at 375 px ' + n.name + ' is on the stage without its name');
    });
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap at 375 px on the grid: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null),
        'at 375 px ' + onStage.length + ' of ' + phoneHub.nodes.length + ' nodes are on the stage, all named');

    /* And the zoom is one somebody can read, not the 0.28 that framing the
     * whole block produced. */
    check(phoneT.k >= 0.33,
        'the phone view zoomed out to ' + phoneT.k.toFixed(2) + ', where names cannot clear each other');

    /* What was clicked is what the stage is centred on. */
    check(onStage.some((n) => n.id === 'wwasps'),
        'the node that was clicked is not on the stage at 375 px');

    setStage(WIDTH, HEIGHT);
    renderer.resize();
    focus.clear();
    flushFrames();
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
