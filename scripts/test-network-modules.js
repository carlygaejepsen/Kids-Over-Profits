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
 * opening), the drawer, URL state, and paths between two names (the store's
 * routes, the route view and its drawer list), and the keyboard on the stage.
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

/**
 * What a name is joined to on screen: { others, lines }, read off the scene
 * rather than the store. A person who only joins two places is drawn as the
 * line between them (focus.js, foldConnectors), so a programme's connections
 * on screen are not its edges in the data: its staff are lines, and the
 * places they lead to are its neighbours.
 */
function joinedOnScreen(scene, id) {
    const others = new Map();
    const lines = [];
    scene.edges.forEach((e) => {
        if (e.sourceId !== id && e.targetId !== id) return;
        lines.push(e);
        const other = e.sourceId === id ? e.target : e.source;
        others.set(other.id, other);
    });
    return { others: [...others.values()], lines };
}

/** On the map, as a name or as a line between two of them. */
function onMap(scene, id) {
    return !!(scene.nodeIds[id] || (scene.folded && scene.folded[id]));
}

function check(condition, message, note) {
    /* KOP_TEST_TRACE=1 prints each check as it runs, to find a hang. */
    if (process.env.KOP_TEST_TRACE) process.stderr.write((condition ? 'ok   ' : 'FAIL ') + message.slice(0, 100) + '\n');
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

    /* The small lines a bubble carries under its name: the years, the name
     * the place traded under before, or the two on one line. They belong to
     * the name above them and are not labels of their own, so the overlap
     * checks must not count them as names. Built from strings rather than
     * written as a literal, to keep the character classes free of escapes. */
    const YEARS_PART = '(from |until )?[0-9]{4}( ?[-\u2013] ?[0-9]{4})?';
    const OLD_NAME_PART = '(formerly|now|also) .+';
    const SUB_LINE = new RegExp('^(' + YEARS_PART + '( \u00b7 ' + OLD_NAME_PART + ')?|' +
        OLD_NAME_PART + ')$');
    /* And the same line cut to the room the name left it. */
    const SUB_LINE_CUT = new RegExp('^(' + YEARS_PART + '|(formerly|now|also) ).*\u2026$');

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
            /* A sub-line belongs to the name above it, not to itself. */
            if (SUB_LINE.test(String(t)) || SUB_LINE_CUT.test(String(t))) { yearsCalls.push(t); return; }
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
        /* What the Key remembers between visits. A real one can throw or come
         * back empty, and the module has to survive both, so the stub is the
         * plain case and the throwing case is the module's own try/catch. */
        localStorage: (() => {
            const kept = new Map();
            return {
                getItem: (k) => (kept.has(k) ? kept.get(k) : null),
                setItem: (k, v) => { kept.set(k, String(v)); },
                removeItem: (k) => { kept.delete(k); },
                clear: () => { kept.clear(); }
            };
        })(),
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
        path.join('js', 'network-map', 'connection.js'),
        path.join('js', 'network-map', 'search.js'),
        path.join('js', 'network-map', 'drawer.js'),
        path.join('js', 'network-map', 'path.js'),
        path.join('js', 'network-map', 'keys.js'),
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
            style: {},
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

        const railToggle = el('button');
        railToggle.setAttribute('id', 'kop-network-filters-toggle');
        railToggle.setAttribute('aria-expanded', 'false');
        root.appendChild(railToggle);

        return { rail, railToggle, regionsToggle, regionsPanel, legend };
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

    /* Every connection type is on until the visitor turns one off. Board
     * seats, referrals, survivors and "other" used to start hidden, and
     * every one of those lines joins a person to a programme or a company:
     * the map is about relationships, so it does not open with any hidden. */
    const defaultCats = store.filters.categories;
    check(graph.meta.categories.every((c) => defaultCats[c]),
        'a connection type is hidden by default: ' +
        graph.meta.categories.filter((c) => !defaultCats[c]).join(', '),
        'all ' + graph.meta.categories.length + ' connection types are on by default');
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
    renderer.useBoardColours(graph.meta);
    check(renderer.resize() === true && canvas.width === WIDTH * 2 && canvas.height === HEIGHT * 2,
        'resize did not scale the backing store to the device pixel ratio');

    let hovered = null;
    let selected = null;
    const lineEvents = { hovered: null, selected: null, selections: 0, popup: null };
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
        },
        /* What the pointer last said about a line; the popup is wired on
         * further down, where the lines are tested. */
        /* As app.js wires them: the circle on a line, when the pointer is
         * on one, rides along as the last argument. */
        onHoverEdge: (edge, point, marker) => {
            lineEvents.hovered = edge;
            if (lineEvents.popup) lineEvents.popup.hover(edge, point, marker);
        },
        onSelectEdge: (edge, point, event, marker) => {
            lineEvents.selected = edge;
            lineEvents.selections++;
            if (lineEvents.popup) lineEvents.popup.pin(edge, point, marker);
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

    /* A name is filled by its status, as the board's key has it: pale
     * yellow open, grey closed or rebranded, white unrecorded. Never by
     * what it is - a company is not orange. */
    const FILLS = sandbox.KOPNetworkCanvas.STATUS_FILLS;
    const openOne = store.nodes.find((n) => n.status === 'open');
    const closedOne = store.nodes.find((n) => n.status === 'closed');
    const unknownOne = store.nodes.find((n) => n.status === 'unknown');
    check(openOne && renderer.colourFor(openOne) === FILLS.open && FILLS.open.toUpperCase() === '#FAEBA1',
        'an open place is not filled the board\'s pale yellow');
    check(closedOne && renderer.colourFor(closedOne) === FILLS.closed && FILLS.closed === FILLS.rebranded,
        'a closed place and a rebranded one are filled differently');
    check(unknownOne && renderer.colourFor(unknownOne) === '#FFFFFF',
        'a place of unrecorded status is not white');
    check(renderer.colourFor(hub) === FILLS[hub.status],
        hub.name + ' is filled by something other than its status');
    /* A chain the board coloured takes the board's colour on its lines; the
     * palette is only for a chain the board left black. */
    check(renderer.chainColour(hub.chain) === (graph.meta.chainColours[hub.chain] ||
        sandbox.KOPNetworkCanvas.CHAIN_COLOURS[store.chainIndex[hub.chain]]),
        'chain colouring did not use the board colour for ' + hub.chain);
    check(renderer.chainColour('') === sandbox.KOPNetworkCanvas.CHAIN_NONE,
        'no recorded owner was given a chain colour');

    /* A name's border is its group's colour, so the border and the lines
     * leaving it say the same thing; a name in no group keeps the plain
     * dark ink, and a colour too pale to read as an outline is darkened
     * rather than swapped, so the hue still names the group. */
    const INK = sandbox.KOPNetworkCanvas.OUTLINE;
    const borderInk = sandbox.KOPNetworkCanvas.borderInk;
    check(renderer.clusterInk(hub) === borderInk(renderer.chainColour(hub.chain)),
        hub.name + " is not outlined in its own company's colour");
    const loner = graph.nodes.find((n) => !n.chain && !(n.regions || []).length);
    check(!loner || renderer.clusterInk(store.node(loner.id)) === INK,
        'a name in no group was given a border colour anyway');
    const luma = (c) => {
        const m = /^rgb\((\d+),(\d+),(\d+)\)$/.exec(c) ||
            [null, parseInt(c.slice(1, 3), 16), parseInt(c.slice(3, 5), 16), parseInt(c.slice(5, 7), 16)];
        return (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) / 255;
    };
    const pale = sandbox.KOPNetworkCanvas.CHAIN_COLOURS.filter((c) => luma(c) > 0.55);
    check(pale.length > 0 && pale.every((c) => luma(borderInk(c)) <= 0.53),
        'a pale company colour is used as a border as it is, where it cannot be seen',
        pale.length + " of the palette's colours are darkened to be read as a border");
    check(sandbox.KOPNetworkCanvas.BORDER_BUBBLE >= 2,
        'a coloured border is drawn too thin to read as a colour');

    /* --------------------------------------------------------- viewport -- */

    let point = screenOf(hub);
    check(viewport.nodeAt(point.x, point.y) === hub, 'hit testing missed a node at its own centre');
    check(viewport.nodeAt(point.x + 3, point.y + 3) === hub,
        'hit slop did not keep a small node tappable at a wide zoom');
    /* Empty space is found, not assumed: at the whole map a corner can hold
     * a node or a name. Scan in from the top left for the first point where
     * nothing is drawn, then check hit testing agrees it is empty. */
    const emptySpot = () => {
        for (let y = 2; y < renderer.height; y += 7) {
            for (let x = 2; x < renderer.width; x += 7) {
                if (viewport.nodeAt(x, y) !== null) continue;
                const clear = scene.nodes.every((n) => {
                    const p = screenOf(n);
                    return Math.hypot(p.x - x, p.y - y) > 24;
                });
                if (clear) return { x, y };
            }
        }
        return null;
    };
    const blank = emptySpot();
    check(!!blank, 'no empty point anywhere on the whole map to test against');
    check(!blank || viewport.nodeAt(blank.x, blank.y) === null, 'hit testing found a node in empty space');

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
    const panFrom = emptySpot() || { x: 5, y: 5 };
    fire('pointerdown', 1, panFrom.x, panFrom.y);
    fire('pointermove', 1, panFrom.x + 40, panFrom.y + 20);
    fire('pointerup', 1, panFrom.x + 40, panFrom.y + 20);
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

    /* The map opens on one organisation already opened (2d.1): the first
     * screen is a major cluster, what a click on it would leave, not a
     * handful of names to pick from. Which one is curated in
     * network-overrides.json (headline, one name), because it is an
     * editorial judgement that no count reproduces; the owner chose
     * Universal Health Services on 2026-09-22. */
    const seeds = store.seeds();
    check(seeds.length === 1 && seeds[0].id === 'universal-health-services',
        'the map opens on ' + seeds.map((n) => n.name).join(', ') + ', expected Universal Health Services alone',
        'opens on: ' + seeds.map((n) => n.name).join(', '));
    check(graph.meta.headline && graph.meta.headline.length === seeds.length,
        'the opening view is not coming from the curated list');
    check(store.viewRoot() === 'universal-health-services',
        'the default view does not carry its organisation as the root');
    /* Curated, not ranked: it is not the best-connected name. */
    const ranked = store.visible().nodes.map((n) => n.degree).sort((a, b) => b - a);
    check(seeds[0].degree < ranked[0],
        'the opening view is just the best-connected node, not a curated one');

    /* What a click on it would show, no more and no less: the root, its
     * connections, the places of its people, the owners of its programmes.
     * With no trail, though: nothing to Start over from and a clean hash. */
    const opening = focus.scene();
    const openingCount = opening.nodes.length;
    check(focus.chain().length === 0, 'the opening view has a trail');
    check(opening.nodeIds['universal-health-services'], 'the opening view does not hold the organisation it opens on');
    check(openingCount > 10,
        'the opening view is ' + openingCount + ' names, not a cluster');
    focus.select(store.node('universal-health-services'));
    flushFrames();
    const clicked = focus.scene().nodes.map((n) => n.id).sort().join(',');
    focus.clear();
    flushFrames();
    const openedOn = focus.scene().nodes.map((n) => n.id).sort().join(',');
    check(clicked === openedOn,
        'the opening view is not what a click on its organisation shows',
        'the map opens on Universal Health Services and its ' + (openingCount - 1) + ' connected names, as a click would');
    /* The promise: a name nobody asked for is not on the map at all.
     * (WWASPS is: Provo Canyon School is UHS-owned and has a WWASPS line,
     * and a programme always brings its owners.) */
    const famous = store.node('synanon');
    check(!opening.nodeIds[famous.id],
        'a node nobody has opened or searched for is on the opening map');

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

    /* Every starter view opens as one connected picture: each name on it
     * has a drawn line to the rest, under the filters the view opens with.
     * The build joins them (connectView); this checks the map honours it,
     * including a view that has to turn a hidden connection type on. */
    store.views().filter((v) => v.key !== 'default').forEach((view) => {
        store.resetFilters();
        store.setView(view.key);
        focus.restore([], 'focus');
        const scene = focus.scene();
        /* A person in the view who does nothing there but join its places
         * is drawn as the line between them, not as a name. */
        const asLines = view.ids.filter((id) => scene.folded[id]).length;
        const leftOut = view.ids.filter((id) => !onMap(scene, id));
        check(leftOut.length === 0,
            'the ' + view.key + ' view left out ' + leftOut.length + ' of its ' + view.ids.length + ' nodes: ' +
            leftOut.slice(0, 3).join(', '));
        /* The people in a view bring their places like anyone, so a view can
         * hold more than its list - but nothing else: whatever is extra is
         * a programme or company one of its people connects to. */
        const viewPeople = view.ids.filter((id) => store.node(id) && store.node(id).kind === 'person');
        const theirPlaces = new Set();
        viewPeople.forEach((id) => store.neighbours(id, true).forEach((l) => theirPlaces.add(l.other.id)));
        const uninvited = scene.nodes.filter((n) => view.ids.indexOf(n.id) === -1 && !theirPlaces.has(n.id));
        check(uninvited.length === 0,
            'the ' + view.key + ' view drew ' + uninvited.length + ' names that are neither on its list nor a place of its people: ' +
            uninvited.slice(0, 3).map((n) => n.name).join(', '));
        const homeless = viewPeople.filter((id) =>
            store.neighbours(id, true).some((l) => l.other.kind !== 'person' && !onMap(scene, l.other.id)));
        check(homeless.length === 0,
            'the ' + view.key + ' view shows ' + homeless.length + ' people without all of their places');
        const around = {};
        scene.edges.forEach((e) => {
            (around[e.sourceId] = around[e.sourceId] || []).push(e.targetId);
            (around[e.targetId] = around[e.targetId] || []).push(e.sourceId);
        });
        const first = view.ids.filter((id) => scene.nodeIds[id])[0];
        const reached = new Set([first]);
        const queue = [first];
        while (queue.length) {
            (around[queue.shift()] || []).forEach((id) => {
                if (!reached.has(id)) { reached.add(id); queue.push(id); }
            });
        }
        const loose = scene.nodes.filter((n) => !reached.has(n.id)).map((n) => n.name);
        check(loose.length === 0,
            'the ' + view.key + ' view has names with no drawn line to the rest: ' + loose.slice(0, 5).join(', '),
            'the ' + view.key + ' view is one connected picture of ' + scene.nodes.length + ' names' +
            (asLines ? ', ' + asLines + ' people folded into lines' : ''));
    });
    store.resetFilters();
    store.setView('default');
    focus.restore([], 'focus');

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
    const hubLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    /* Every programme a view's people lead to is on the board now, so a
     * hub's view can be bigger than the stage; what does not fit is a pan
     * away. The promise is about what is on the stage: none of it unnamed. */
    const hubOnStage = hubScene.nodes.filter((n) => {
        const p = focus.positionOf(n);
        const x = p.x * viewport.transform.k + viewport.transform.x;
        const y = p.y * viewport.transform.k + viewport.transform.y;
        return x >= 0 && x <= WIDTH && y >= 0 && y <= HEIGHT;
    });
    const hubUnnamed = hubOnStage.filter((n) => hubLabels.indexOf(n.name) === -1);
    check(hubOnStage.length >= hubScene.nodes.length / 2 && hubUnnamed.length === 0,
        'the grid left ' + hubUnnamed.length + ' of the ' + hubOnStage.length + ' names on the stage unnamed (' +
        hubScene.nodes.length + ' in the view): ' + hubUnnamed.slice(0, 3).map((n) => n.name).join(', '),
        'a ' + hubScene.nodes.length + '-node neighbourhood names all ' + hubOnStage.length + ' on the stage');
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap on the grid: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

    /* The board draws a company's connections in the company's colour, and
     * the map does too; the kinds of connection keep their own dashes. */
    const orange = graph.meta.chainColours.WWASPS;
    /* A line to another company's place (Provo Canyon School is UHS's) is
     * neither company's, and stays the plain ink of its kind. */
    const otherCompany = (n) => n.kind !== 'person' &&
        ((n.chain && n.chain !== 'WWASPS') || (!n.chain && graph.meta.chainColours[(n.regions || [])[0]] &&
            n.regions[0] !== 'WWASPS'));
    const wwaspsLines = hubScene.edges.filter((e) =>
        (e.sourceId === 'wwasps' || e.targetId === 'wwasps') && !['family', 'survivor'].includes(e.category) &&
        e.direction === 'none' && e.category !== 'membership' &&
        !otherCompany(e.sourceId === 'wwasps' ? e.target : e.source));
    const crossLine = hubScene.edges.find((e) => (e.sourceId === 'wwasps' || e.targetId === 'wwasps') &&
        e.direction === 'none' && otherCompany(e.sourceId === 'wwasps' ? e.target : e.source));
    check(!crossLine || renderer.styleOf(crossLine).colour !== orange,
        'a line from WWASPS to another company is drawn in WWASPS colour');
    const inColour = wwaspsLines.filter((e) => renderer.styleOf(e).colour === orange);
    check(wwaspsLines.length > 0 && inColour.length === wwaspsLines.length,
        inColour.length + ' of ' + wwaspsLines.length + ' WWASPS lines are drawn in the board colour ' + orange,
        'all ' + wwaspsLines.length + ' WWASPS lines drawn in its board colour');
    const dashed = hubScene.edges.find((e) => e.category === 'admissions' || e.category === 'board');
    if (dashed) {
        check(!!renderer.styleOf(dashed).dash, 'a company-coloured ' + dashed.category + ' line lost its dash');
    }
    const member = store.edges.find((e) => e.category === 'membership');
    check(!member || renderer.styleOf(member).colour === graph.meta.membershipColour,
        'membership lines are not in the board NATSAP colour');

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
    const clippedNames = () => labelBoxes.filter((b) => {
        const width = String(b.t).length * 6;
        const align = b.align || 'center';
        const x0 = align === 'center' ? b.x - width / 2 : (align === 'left' ? b.x : b.x - width);
        return x0 < 0 || x0 + width > renderer.width;
    });
    /* That is a promise about a board the stage holds. With every place its
     * people lead to on it, this hub's board is wider than the stage and the
     * edge of the stage falls mid-board, where a name half in view is how a
     * reader knows to pan. So it is checked here only when the board fits,
     * and always on a view that does. */
    if (hubOnStage.length === hubScene.nodes.length) {
        check(clippedNames().length === 0,
            clippedNames().length + ' names run off the edge of the canvas: ' +
            clippedNames().slice(0, 2).map((b) => b.t).join(', '));
    }
    const smallHub = store.node('academy-at-ivy-ridge');
    focus.clear();
    flushFrames();
    focus.select(smallHub);
    flushFrames();
    resetOps();
    renderer.draw();
    const smallScene = focus.scene();
    const smallFits = smallScene.nodes.every((n) => {
        const p = focus.positionOf(n);
        const x = p.x * viewport.transform.k + viewport.transform.x;
        const y = p.y * viewport.transform.k + viewport.transform.y;
        return x >= 0 && x <= WIDTH && y >= 0 && y <= HEIGHT;
    });
    check(smallFits, 'the ' + smallHub.name + ' view does not fit the stage, so the outermost column is untested');
    check(clippedNames().length === 0,
        clippedNames().length + ' names run off the edge of the canvas: ' +
        clippedNames().slice(0, 2).map((b) => b.t).join(', '),
        'no name in the ' + smallScene.nodes.length + '-name ' + smallHub.name + ' view runs off the canvas');
    focus.clear();
    flushFrames();
    focus.select(store.node('wwasps'));
    flushFrames();
    resetOps();
    renderer.draw();

    /* A line never crosses a node it does not connect. Routes are
     * published by the renderer with their points; every leg is checked
     * against every other node's box - shape, clearance and label - by
     * where it actually runs, because a line through a name reads as a
     * relationship with it. */
    const hitsBox = sandbox.KOPNetworkCanvas.segmentHitsBox;
    check(hitsBox([0, 0], [10, 10], [4, 4, 6, 6]) && !hitsBox([0, 0], [10, 0], [4, 4, 6, 6]) &&
        !hitsBox([0, 0], [3, 3], [4, 4, 6, 6]) && hitsBox([0, 5], [10, 5], [4, 4, 6, 6]),
        'the segment-box test is wrong');
    let crossings = 0;
    let legs = 0;
    let straight = 0;
    const routes = renderer.routes || [];
    const boxes = renderer.blockers || [];
    routes.forEach((route) => {
        const a = route.edge.source._i;
        const b = route.edge.target._i;
        if (route.pts.length === 2) straight++;
        for (let i = 1; i < route.pts.length; i++) {
            legs++;
            /* Judged twice: by the renderer's own segment test, and by
             * walking the leg two pixels at a time, so a bug in the one
             * cannot hide a crossing from the other. */
            const p = route.pts[i - 1], q = route.pts[i];
            const steps = Math.max(1, Math.ceil(Math.hypot(q[0] - p[0], q[1] - p[1]) / 2));
            boxes.forEach((box, j) => {
                if (j === a || j === b) return;
                let walked = false;
                for (let s = 0; s <= steps && !walked; s++) {
                    const x = p[0] + (q[0] - p[0]) * s / steps, y = p[1] + (q[1] - p[1]) * s / steps;
                    walked = x > box[0] + 1 && x < box[2] - 1 && y > box[1] + 1 && y < box[3] - 1;
                }
                if (hitsBox(p, q, box) || walked) {
                    crossings++;
                    if (crossings === 1) {
                        /* Routed again here, at rest: a straight line the
                         * router would bend was served from a frame in
                         * motion; one it would not bend is the router's. */
                        const again = sandbox.KOPNetworkCanvas.routeEdge(
                            route.pts[0][0], route.pts[0][1], route.pts[route.pts.length - 1][0], route.pts[route.pts.length - 1][1],
                            boxes, a, b, false);
                        notes.push('first crossing: ' + route.edge.source.name + ' to ' + route.edge.target.name +
                            ' leg ' + i + ' of ' + (route.pts.length - 1) + ' through ' +
                            JSON.stringify(box.map(Math.round)) + (hitsBox(p, q, box) ? ' (slab)' : ' (walk only)') +
                            '; routed again at rest it has ' + (again.length - 1) + ' legs');
                        /* KOP_TEST_DUMP=<file> saves the geometry for a router experiment. */
                        if (process.env.KOP_TEST_DUMP) {
                            require('fs').writeFileSync(process.env.KOP_TEST_DUMP, JSON.stringify({
                                a, b, A: route.pts[0], B: route.pts[route.pts.length - 1], boxes, names: hubScene.nodes.map((n) => n.name)
                            }));
                        }
                    }
                }
            });
        }
    });
    check(routes.length > 10, 'too few routes to test the router');
    check(crossings === 0,
        crossings + ' route legs cross a node they do not connect',
        routes.length + ' routes, ' + legs + ' legs, none through a node they do not connect');
    /* A line bends for one of two reasons and no others: a name is in its
     * way, or it is a long line that would otherwise run through the
     * middle of the crowd instead of round it. A short line always goes
     * straight - bowing those would say two names are further apart than
     * they are - so anything bent is either long enough to bow or blocked.
     */
    const BOW_MIN = sandbox.KOPNetworkCanvas.BOW_MIN_LENGTH;
    const shortBends = routes.filter((route) => {
        if (route.pts.length < 3) return false;
        const p = route.pts[0], q = route.pts[route.pts.length - 1];
        if (Math.hypot(q[0] - p[0], q[1] - p[1]) >= BOW_MIN) return false;
        /* Blocked is the other good reason, so only an unblocked short
         * line that bent anyway is a fault. */
        return boxes.every((box, j) =>
            j === route.edge.source._i || j === route.edge.target._i || !hitsBox(p, q, box));
    });
    check(shortBends.length === 0,
        shortBends.length + ' short lines bend with nothing in their way, the first from ' +
        (shortBends[0] && shortBends[0].edge.source.name),
        'every line under ' + BOW_MIN + 'px goes straight unless a name is in its way');
    /* And enough of them do go straight that the view still reads as
     * lines between names rather than as a maze. */
    check(straight >= routes.length * 0.25,
        'only ' + straight + ' of ' + routes.length + ' lines are straight',
        straight + ' of ' + routes.length + ' lines are straight');
    /* A line leaves its name on whichever side faces the other end, so
     * the lines of a busy view leave from every side, not only the bottom. */
    const sides = { top: 0, bottom: 0, left: 0, right: 0 };
    routes.forEach((route) => {
        const box = renderer.blockers[route.edge.source._i];
        const p = route.pts[0], q = route.pts[1];
        const dx = q[0] - p[0], dy = q[1] - p[1];
        const hw = (box[2] - box[0]) / 2, hh = (box[3] - box[1]) / 2;
        if (Math.abs(dx) * hh > Math.abs(dy) * hw) sides[dx > 0 ? 'right' : 'left']++;
        else sides[dy > 0 ? 'bottom' : 'top']++;
    });
    check(sides.top > 0 && sides.left > 0 && sides.right > 0 && sides.bottom > 0,
        'lines only ever leave from ' + JSON.stringify(sides),
        'lines leave from every side: ' + JSON.stringify(sides));

    /* And a name with more lines than one edge of it has room for shares
     * its whole rim out, rather than landing them three deep on the edge
     * that happens to face their other ends.
     *
     * The spreading itself is checked on its own first, because in a
     * drawn view the thing that would hide a bug in it - ports that quietly
     * stayed where they were - looks exactly like a view with no crowding. */
    const spreadPorts = sandbox.KOPNetworkCanvas.spreadPorts;
    const PORT_GAP = sandbox.KOPNetworkCanvas.PORT_GAP;
    const gapsOf = (list, per) => list.map((v, i) =>
        (i === list.length - 1 ? list[0] + per : list[i + 1]) - v);
    const heaped = [100, 101, 102, 140, 141, 142, 143];
    const heapedWas = heaped.slice();
    spreadPorts(heaped, 400, PORT_GAP);
    check(gapsOf(heaped, 400).every((g) => g >= PORT_GAP - 0.5),
        'crowded ports were left ' + JSON.stringify(gapsOf(heaped, 400).map(Math.round)) + ' apart',
        'seven ports heaped on 40px of a 400px rim end up ' + PORT_GAP + 'px apart');
    check(heaped.every((v, i) => i === 0 || v > heaped[i - 1]) &&
        heaped.every((v, i) => Math.abs(v - heapedWas[i]) < 200),
        'spreading ports reordered them, so two lines would cross on the rim');
    const roomy = [0, 100, 200, 300];
    const roomyWas = roomy.slice();
    spreadPorts(roomy, 400, PORT_GAP);
    check(roomy.every((v, i) => v === roomyWas[i]),
        'ports with room to spare were moved anyway, so a line no longer points at its own other end');
    /* More lines than the rim has room for: they take less each rather
     * than more of the rim. The rim must not close up, because a closed
     * ring is an even ring, and an even ring is decided by nothing - a
     * port on the far side from its own other end is worse than a port
     * crowded against its neighbour. */
    const tooMany = new Array(60).fill(10);
    spreadPorts(tooMany, 400, PORT_GAP);
    const tooManyGaps = gapsOf(tooMany, 400);
    const slack = Math.max(...tooManyGaps);
    check(Math.min(...tooManyGaps) > 1 && slack > 400 * 0.2,
        'sixty lines close the 400px rim up (widest gap left: ' + Math.round(slack) + 'px), ' +
        'so their ports are an even ring and say nothing about where they go',
        'sixty lines on a 400px rim stay apart and leave ' + Math.round(slack) + 'px of it free');

    /* Then in the view: the busiest name on screen uses more than the one
     * edge its lines all face. */
    const portsBy = new Map();
    routes.forEach((route) => {
        const pair = (renderer.ports || {})[route.edge.id];
        if (!pair) return;
        [[route.edge.source._i, pair[0]], [route.edge.target._i, pair[1]]].forEach((end) => {
            if (!end[1]) return;
            if (!portsBy.has(end[0])) portsBy.set(end[0], []);
            portsBy.get(end[0]).push(end[1]);
        });
    });
    const sideAt = (box, pt) => {
        const dx = (pt[0] - (box[0] + box[2]) / 2) / ((box[2] - box[0]) / 2 || 1);
        const dy = (pt[1] - (box[1] + box[3]) / 2) / ((box[3] - box[1]) / 2 || 1);
        return Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'bottom' : 'top');
    };
    let busiest = -1;
    portsBy.forEach((list, idx) => {
        if (busiest < 0 || list.length > portsBy.get(busiest).length) busiest = idx;
    });
    check(busiest >= 0 && portsBy.get(busiest).length >= 6, 'no name busy enough to test the rim on');
    const busySides = new Set(portsBy.get(busiest).map((pt) => sideAt(renderer.blockers[busiest], pt)));
    /* And however crowded, a port still faces its own other end: the
     * spreading moves it off the exact bearing, never onto the far side,
     * which would leave the line to double back under the name it just
     * left. */
    let turned = 0;
    let worstTurn = 0;
    routes.forEach((route) => {
        const pair = (renderer.ports || {})[route.edge.id];
        if (!pair) return;
        [[route.edge.source._i, route.edge.target._i, pair[0]],
            [route.edge.target._i, route.edge.source._i, pair[1]]].forEach((end) => {
            if (!end[2]) return;
            const box = renderer.blockers[end[0]];
            const far = renderer.blockers[end[1]];
            const cx = (box[0] + box[2]) / 2, cy = (box[1] + box[3]) / 2;
            const toEnd = Math.atan2((far[1] + far[3]) / 2 - cy, (far[0] + far[2]) / 2 - cx);
            const toPort = Math.atan2(end[2][1] - cy, end[2][0] - cx);
            let off = Math.abs(toPort - toEnd) * 180 / Math.PI;
            if (off > 180) off = 360 - off;
            if (off > worstTurn) worstTurn = off;
            if (off > 90) turned++;
        });
    });
    check(turned === 0,
        turned + ' ports face away from their own other end, the worst by ' +
        Math.round(worstTurn) + ' degrees',
        'no port is turned more than ' + Math.round(worstTurn) + ' degrees off its own other end');

    check(busySides.size >= 2,
        hubScene.nodes[busiest].name + "'s " + portsBy.get(busiest).length +
        ' lines all leave the same edge',
        hubScene.nodes[busiest].name + "'s " + portsBy.get(busiest).length +
        ' lines leave from ' + [...busySides].join(', '));

    /* The board fills the stage: a block near the stage's own shape, not
     * a strip across it or a column down it, and not knotted into one
     * corner. */
    const tf = viewport.transform;
    const sxs = hubScene.nodes.map((n) => focus.positionOf(n).x * tf.k + tf.x);
    const sys = hubScene.nodes.map((n) => focus.positionOf(n).y * tf.k + tf.y);
    const spanW = (Math.max(...sxs) - Math.min(...sxs)) / renderer.width;
    const spanH = (Math.max(...sys) - Math.min(...sys)) / renderer.height;
    check(spanW >= 0.5 && spanH >= 0.5,
        'the WWASPS view uses ' + Math.round(spanW * 100) + '% of the stage across and ' +
        Math.round(spanH * 100) + '% down',
        'the WWASPS view spans ' + Math.round(spanW * 100) + '% of the stage across and ' +
        Math.round(spanH * 100) + '% down');

    /* The map reads downwards the way the owner's board does: a company
     * above what it owns and a programme above what it was renamed to,
     * whichever cluster each end landed in. */
    const bandOf = (n) => {
        if (n.kind === 'parent' || n.kind === 'association') return 0;
        if (n.kind === 'person') return 1;
        return 2;
    };
    const yOf = (n) => focus.positionOf(n).y;
    const ownershipOrder = (scene) => {
        const out = { pairs: 0, wrong: [] };
        scene.edges.forEach((e) => {
            if (e.category !== 'corporate' || bandOf(e.source) === 1 || bandOf(e.target) === 1) return;
            let upper = null;
            if (e.direction === 'renamed' || bandOf(e.source) === bandOf(e.target)) {
                if (e.direction === 'renamed' || bandOf(e.source) === 0) upper = e.source;
            } else {
                upper = bandOf(e.source) === 0 ? e.source : e.target;
            }
            if (!upper) return;
            const lower = upper === e.source ? e.target : e.source;
            out.pairs++;
            if (yOf(upper) >= yOf(lower)) out.wrong.push(upper.name + ' over ' + lower.name);
        });
        return out;
    };
    const wwOrder = ownershipOrder(hubScene);
    /* A rule the settle holds as a rule, not a guarantee: a nudge among
     * forces gives way where the room runs out. */
    check(wwOrder.pairs > 10 && wwOrder.wrong.length <= wwOrder.pairs * 0.1,
        wwOrder.wrong.length + ' of ' + wwOrder.pairs + ' ownerships in the WWASPS view are drawn upside down: ' +
        wwOrder.wrong.slice(0, 3).join(', '),
        'all ' + wwOrder.pairs + ' ownerships and renames in the WWASPS view read top to bottom');
    const wwasps = store.node('wwasps');
    const ownedBelow = hubScene.edges.filter((e) => e.sourceId === 'wwasps' && e.category === 'corporate' &&
        e.target.kind === 'facility');
    check(ownedBelow.length > 10 && ownedBelow.filter((e) => yOf(e.target) <= yOf(wwasps)).length <= 1,
        'a programme WWASPS owned is drawn level with it or above it');

    /* A click draws its own connections in close around it: every one of
     * them on the stage, none further off than the stage is wide. Clustered
     * without that, the furthest of Provo Canyon School's connections sat
     * a stage away and seven were a pan off it. */
    const wwOwn = store.neighbours('wwasps', true).map((l) => l.other).filter((n) => hubScene.nodeIds[n.id]);
    const wwP = focus.positionOf(wwasps);
    const wwFar = Math.max(...wwOwn.map((n) =>
        Math.hypot(focus.positionOf(n).x - wwP.x, focus.positionOf(n).y - wwP.y)));
    const wwOff = wwOwn.filter((n) => {
        const p = focus.positionOf(n);
        const x = p.x * tf.k + tf.x;
        const y = p.y * tf.k + tf.y;
        return x < 0 || x > renderer.width || y < 0 || y > renderer.height;
    });
    check(wwOwn.length > 10 && wwOff.length === 0 && wwFar <= WIDTH * 0.75,
        wwOff.length + " of WWASPS's " + wwOwn.length + ' connections are off the stage, the furthest ' +
        Math.round(wwFar) + 'px away',
        'all ' + wwOwn.length + " of WWASPS's connections on the stage, the furthest " + Math.round(wwFar) + 'px away');

    /* A click is a yoyo: the clicked name swells and its connections are
     * reeled in from where they were, overshoot and settle. In motion
     * straight after the click, still once it has run, and never at all
     * under reduced motion. */
    focus.clear();
    flushFrames();
    focus.select(wwasps);
    const reeling = focus.offsets();
    check(!!reeling && Object.keys(reeling).length > 10,
        'a click does not reel its connections in: nothing is in motion after it',
        'a click sets ' + Object.keys(reeling || {}).length + ' names in motion');
    flushFrames();
    check(!focus.offsets(), 'the yoyo never settles: names are still offset once it has run');
    motion.reduced = true;
    focus.clear();
    flushFrames();
    focus.select(wwasps);
    check(!focus.offsets(), 'under reduced motion a click still sets names moving');
    motion.reduced = false;
    flushFrames();

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
    check(ownedAbove <= Math.ceil(ownershipPairs * 0.15),
        ownedAbove + ' of ' + ownershipPairs + ' companies are drawn below a company they own',
        'every company sits above the companies it owns (' + ownershipPairs + ' pairs)');
    const provoOrder = ownershipOrder(ownView);
    check(provoOrder.wrong.length <= Math.ceil(provoOrder.pairs * 0.15),
        provoOrder.wrong.length + ' of ' + provoOrder.pairs + ' ownerships in the ' + hub.name +
        ' view are drawn upside down: ' + provoOrder.wrong.slice(0, 3).join(', '));

    /* Clusters keep lines short. Every company along the top and every
     * programme along the bottom, the layout this replaced, put the median
     * line in this view at half a stage; gathered round what it connects
     * to, what belongs together is drawn together. */
    const xOf = (n) => focus.positionOf(n).x;
    const spans = ownView.edges.map((e) =>
        Math.hypot(xOf(e.source) - xOf(e.target), yOf(e.source) - yOf(e.target))).sort((a, b) => a - b);
    const medianSpan = spans[Math.floor(spans.length / 2)];
    const shortShare = spans.filter((d) => d <= 260).length / spans.length;
    /* A fan round the click is roomier than packed rows were: the median
     * line is under half a stage, and a fair share of them close.
     *
     * The floor was a quarter when it was written on 2026-09-21, and past
     * names cost none of it, because they fold onto a line the name already
     * had. Other names, on 2026-09-23, took it to 19.7% (23 of 117): 21
     * names carry one and have no years to fold it into, so their bubbles
     * gained a line, and a name that gains a line pushes its neighbours
     * away. Measured by running this file against the commit before the
     * change, in a tree of its own: 25% there, 19.7% here.
     *
     * That is a trade the owner asked for and not drift, so the number is
     * written down rather than quietly followed downwards: the floor sits
     * below what is measured, so the check still catches a collapse, and a
     * drop past it means something other than this changed. */
    check(medianSpan <= 450 && shortShare >= 0.18,
        'lines in the ' + hub.name + ' view are long: median ' + Math.round(medianSpan) + 'px, ' +
        Math.round(shortShare * 100) + '% within 260px',
        'lines in the ' + hub.name + ' view: median ' + Math.round(medianSpan) + 'px, ' +
        Math.round(shortShare * 100) + '% within 260px');
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
    const texts = labelCalls.filter((c) => c.startsWith('text:'));
    /* Names sit inside their bubbles, so there is no halo to order. What
     * matters while hovering is that the lit neighbourhood keeps its names:
     * its bubbles claim their room before the dimmed background does. */
    const hoverJoined = joinedOnScreen(focus.scene(), hub.id);
    const litOnStage = hoverJoined.others.filter((n) => {
        const p = at(n);
        const off = (focus.offsets() || {})[n.id] || [0, 0];
        const x = (p.x + off[0]) * viewport.transform.k + viewport.transform.x;
        const y = (p.y + off[1]) * viewport.transform.k + viewport.transform.y;
        return x >= 0 && x <= WIDTH && y >= 0 && y <= HEIGHT;
    });
    const litUnnamed = litOnStage.filter((n) => texts.indexOf('text:' + n.name) === -1);
    check(texts.length > 1 && litUnnamed.length <= Math.floor(litOnStage.length / 10),
        'a gathered neighbourhood lost ' + litUnnamed.length + ' of ' + litOnStage.length + ' names: ' +
        litUnnamed.slice(0, 3).map((n) => n.name).join(', '));
    check(texts.indexOf('text:' + hub.name) !== -1 || texts[0] === 'text:' + hub.name,
        'the hovered node itself went unlabelled');

    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap while hovering: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null));

    const lit = renderer.emphasis.near;
    check(!!lit && lit[hub.id] === true, 'hovering did not light the hovered node');
    check(Object.keys(lit).length === hoverJoined.others.length + 1,
        'hover lit ' + Object.keys(lit).length + ' nodes, expected ' + (hoverJoined.others.length + 1),
        'hover lights ' + hub.name + ' and the ' + hoverJoined.others.length + ' names it has a line to');
    check(Object.keys(renderer.emphasis.nearEdges).length === hoverJoined.lines.length,
        'hover lit the wrong number of connections');
    check(renderer.emphasis.dim > 0 && renderer.emphasis.dim < 0.3,
        'hover did not drop the rest of the map to a dim alpha');

    /* --- the gather --- */

    /* A crowd does not gather. With every programme its staff lead to on
     * the board, this hub has more lines than there is room to pull in, and
     * hover is lighting alone: gathered, its names landed on each other. */
    flushFrames();
    check(hoverJoined.others.length <= 36 || focus.offsets() === null,
        'a hub with ' + hoverJoined.others.length + ' lines still gathered them in',
        hub.name + ' has ' + hoverJoined.others.length + ' lines; hovering it lights them and moves nothing');

    /* So the gather is checked on the busiest name in the view that does:
     * a handful of lines, which is the case it exists for. */
    const gatherScene = focus.scene();
    const gatherer = gatherScene.nodes
        .filter((n) => n.id !== hub.id)
        .map((n) => ({ node: n, joined: joinedOnScreen(gatherScene, n.id).others }))
        .filter((e) => e.joined.length >= 3 && e.joined.length <= 36)
        .sort((a, b) => b.joined.length - a.joined.length)[0];
    check(!!gatherer, 'nothing in the ' + hub.name + ' view has a handful of lines, so the gather is untested');
    focus.hover(null);
    flushFrames();
    focus.hover(gatherer.node);
    flushFrames();
    const gathered = focus.offsets();
    check(!!gathered, 'the gather produced no offsets');
    check(!gathered[gatherer.node.id], 'the hovered node moved; only its neighbours should');

    let pulledIn = 0;
    let tooClose = 0;
    gatherer.joined.forEach((other) => {
        const off = gathered[other.id];
        if (!off) return;
        const p = at(other);
        const c = at(gatherer.node);
        const was = Math.hypot(p.x - c.x, p.y - c.y);
        const now = Math.hypot(p.x + off[0] - c.x, p.y + off[1] - c.y);
        if (now < was) pulledIn++;
        if (now < gatherer.node.r + other.r) tooClose++;
    });
    check(pulledIn > 0, 'the gather moved nothing toward the hovered node',
        'the gather pulls in ' + pulledIn + ' of the ' + gatherer.joined.length + ' names ' +
        gatherer.node.name + ' has a line to');
    check(tooClose === 0, tooClose + ' neighbours were gathered inside the node they gathered to');

    /* The gather is display-only: the settled layout must be untouched. */
    check(hub.x === mapX && hub.y === mapY, 'the gather moved a stored position');

    /* And the pointer has to be able to reach a node where it is drawn. */
    const moved = gatherer.joined.find((n) => gathered[n.id]);
    const movedOffset = gathered[moved.id];
    const movedScreen = {
        x: (at(moved).x + movedOffset[0]) * t.k + t.x,
        y: (at(moved).y + movedOffset[1]) * t.k + t.y
    };
    check(viewport.nodeAt(movedScreen.x, movedScreen.y) === moved,
        'a gathered node could not be clicked where it was drawn');
    const staleScreen = { x: at(moved).x * t.k + t.x, y: at(moved).y * t.k + t.y };
    /* A bubble is wider than the step a gather takes, so the spot a node
     * left is often still inside its own bubble, and clickable because it
     * is drawn there. Only a spot the bubble has left must stop answering. */
    const movedHit = (renderer.labelHits || []).find((e) => e.node === moved);
    const stillCovered = movedHit && staleScreen.x >= movedHit.box[0] && staleScreen.x <= movedHit.box[2] &&
        staleScreen.y >= movedHit.box[1] && staleScreen.y <= movedHit.box[3];
    check(stillCovered || viewport.nodeAt(staleScreen.x, staleScreen.y) !== moved,
        'a gathered node was still clickable at the position it had left');

    /* --- leaving eases back --- */

    focus.hover(null);
    flushFrames();
    check(focus.offsets() === null, 'the gather did not let go when the pointer left');
    check(renderer.emphasis.near === null, 'the dimming outlasted the hover');

    /* --- reduced motion is dimming alone --- */

    motion.reduced = true;
    focus.hover(gatherer.node);
    flushFrames();
    check(focus.offsets() === null, 'the gather ran under prefers-reduced-motion');
    check(!!renderer.emphasis.near && renderer.emphasis.near[gatherer.node.id],
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
    /* The organisation the map opened on has no claim on a later view:
     * it is there only if a line joins it to what was opened. */
    Object.keys(seedIds).forEach((id) => {
        check(!focused.nodeIds[id] || linked.has(id),
            'opening a node kept the organisation the map opened on with nothing connecting it');
    });
    /* Opening a node puts it and everyone it touches on the map - not the
     * organisations the map opened with, which belong to the opening view -
     * and then opens out any person among them, because a name with one
     * line back to whatever revealed it hides the thing worth knowing about
     * them. Every place, however many that comes to: the person is drawn as
     * the line to it, so a place held back would be a connection with
     * nothing on screen to say it exists. */
    const expected = new Set([hub.id].concat(neighbours.map((l) => l.other.id)));
    [...expected].forEach((id) => {
        const node = store.node(id);
        if (node && node.kind === 'person') {
            store.neighbours(id, true).forEach((l) => expected.add(l.other.id));
        }
    });
    /* ...and nobody is on the map without their places, whoever brought
     * them: a person who arrived as somebody's brother brings the
     * programmes he ran, though not the people he knows in turn. */
    [...expected].forEach((id) => {
        const node = store.node(id);
        if (node && node.kind === 'person') {
            store.neighbours(id, true).forEach((l) => { if (l.other.kind !== 'person') expected.add(l.other.id); });
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
    /* ...minus whatever that left stranded, and with the people who only
     * join places counted as the lines they are drawn as. */
    [...expected].forEach((id) => {
        if (id !== hub.id && !linked.has(id) && !focused.folded[id]) expected.delete(id);
    });
    const asLines = [...expected].filter((id) => focused.folded[id]);
    check(focused.nodes.length + asLines.length === expected.size,
        'opening ' + hub.name + ' showed ' + focused.nodes.length + ' names and ' + asLines.length +
        ' people as lines, expected ' + expected.size + ' between them',
        'opening ' + hub.name + ': ' + focused.nodes.length + ' names, ' + asLines.length +
        ' people folded into lines, ' + focused.edges.length + ' lines');
    check(focused.nodes.every((n) => expected.has(n.id)),
        'opening a node put something on the map that nobody asked for');

    /* The fold itself. Nobody drawn as a line is also drawn as a name; every
     * one of them is on some line, with the role they held at each end; and
     * nobody left as a name had two places they could have joined. */
    const carried = new Set();
    focused.edges.forEach((e) => (e.via || []).forEach((v) => {
        carried.add(v.person.id);
        check((v.at[e.sourceId] || []).length > 0 && (v.at[e.targetId] || []).length > 0,
            v.person.name + ' is on the line between ' + e.source.name + ' and ' + e.target.name +
            ' without a recorded connection to both');
    }));
    check(asLines.length > 0 && asLines.every((id) => !focused.nodeIds[id] && carried.has(id)),
        'a folded person is missing from the lines, or is still drawn as a name',
        'all ' + asLines.length + ' folded people are carried on a line');
    const unfolded = focused.nodes.filter((n) => {
        if (n.kind !== 'person' || n.id === hub.id) return false;
        const joined = joinedOnScreen(focused, n.id).others;
        return joined.length >= 2 && joined.every((o) => o.kind !== 'person');
    });
    check(unfolded.length === 0,
        unfolded.length + ' people who only join places are still drawn as names: ' +
        unfolded.slice(0, 3).map((n) => n.name).join(', '));
    check(store.edges.every((e) => !e.via),
        'folding wrote its passengers onto the edges the store holds');
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
        /* Membership is lineage and reads as a line: Dederich belonged to
         * AA and founded Synanon, so AA sits above Synanon and the line
         * between them is Dederich - who is on it, not beside it. */
        const aa = store.node('alcoholics-anonymous');
        const chuck = store.node('charles-chuck-dederich');
        const lineage = synView.edges.filter((e) =>
            (e.sourceId === aa.id && e.targetId === synanon.id) ||
            (e.sourceId === synanon.id && e.targetId === aa.id))[0];
        check(synView.nodeIds[aa.id] && !!lineage &&
            (lineage.via || []).some((v) => v.person.id === chuck.id),
            "Synanon's view left out Dederich's membership of AA");
        check(!synView.nodeIds[chuck.id] && synView.folded[chuck.id],
            'Dederich is still drawn as a name between AA and Synanon');
        if (synView.nodeIds[aa.id] && lineage) {
            const pa = focus.positionOf(aa), ps = focus.positionOf(synanon);
            check(pa.y < ps.y,
                'AA and Synanon are not drawn top to bottom',
                'AA above Synanon, joined by a line that is Dederich');
            const said = sandbox.KOPNetworkConnection.describe([lineage], (e) => renderer.styleOf(e), sandbox.KOPNetworkCanvas);
            const dederich = said && said.items.filter((item) => item.kind === 'person' && item.node === chuck)[0];
            check(!!dederich && dederich.places.length === 2 &&
                dederich.places.every((place) => place.name === aa.name || place.name === synanon.name),
                'the line between AA and Synanon does not say it is Dederich',
                'hovering it says: ' + (dederich ? dederich.name + ' - ' +
                    dederich.places.map((place) => place.name + ': ' + (place.role || 'role not recorded')).join(', ') : ''));
        }
        focus.clear();
        flushFrames();
        focus.select(hub);
        flushFrames();
    }

    /* A person with one line back to whatever revealed them hides the thing
     * worth knowing: which programmes they turn up at. Whenever a name
     * surfaces, everywhere it connects to surfaces with it - always, since
     * the person is drawn as the line to it and there is nothing else on
     * screen to say the place exists. */
    /* The people this applies to are the ones one step from what was
     * clicked. A person who arrives through another person's expansion does
     * not expand in turn (focus.js, visibleIds), or one well-connected name
     * would pull in the whole board. */
    const direct1 = new Set(store.neighbours(hub.id, true).map((l) => l.other.id));
    const surfaced = store.neighbours(hub.id, true).map((l) => l.other)
        .filter((n, i, all) => all.indexOf(n) === i)
        .filter((n) => n.kind === 'person' && onMap(focused, n.id) &&
            store.neighbours(n.id, true).length > 1);
    check(surfaced.length > 0, 'no person surfaced when opening ' + hub.name + ', so the rule is untested');
    let heldBack = 0, unjoined = 0;
    surfaced.forEach((person) => {
        store.neighbours(person.id, true).forEach((link) => {
            if (!onMap(focused, link.other.id)) { heldBack++; return; }
            /* And where the person is a line, it is a line from what was
             * clicked to that place. */
            if (!focused.folded[person.id] || link.other.id === hub.id) return;
            const joined = focused.edges.some((e) => (e.via || []).some((v) => v.person === person) &&
                ((e.sourceId === hub.id && e.targetId === link.other.id) ||
                 (e.targetId === hub.id && e.sourceId === link.other.id)));
            if (!joined) unjoined++;
        });
    });
    check(heldBack === 0,
        heldBack + ' places a surfaced person connects to were left off the map',
        surfaced.length + ' people surfaced, and every place they lead to with them');
    check(unjoined === 0,
        unjoined + ' places a folded person leads to have no line to ' + hub.name + ' carrying them');
    /* Nobody drawn as a line is counted as missing on the places they join. */
    const miscounted = focused.nodes.filter((n) => {
        const off = store.neighbours(n.id, true).filter((l) => !onMap(focused, l.other.id)).length;
        return (focused.hidden[n.id] || 0) !== off;
    });
    check(miscounted.length === 0,
        miscounted.length + ' names carry a +N that counts a person drawn as a line: ' +
        miscounted.slice(0, 3).map((n) => n.name).join(', '));
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
    /* As a name, or as the line a person who only joins places is drawn as. */
    check(nextNeighbours.every((id) => onMap(twoDeep, id)),
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
    check(hubOwn.every((id) => onMap(union, id)) && nextNeighbours.every((id) => onMap(union, id)),
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
    check(reopened.nodes.length === openingCount,
        'clearing left ' + reopened.nodes.length + ' nodes, expected the ' +
        openingCount + ' the map opens on');
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

    /* The Key opens itself on a first visit. A panel nobody opens explains
     * nothing, and nothing else on the page says there is one to open. */
    check(shell.rail.hidden === false,
        'the Key stayed shut on a first visit, so a first-time reader never sees it');
    check(sandbox.localStorage.getItem('kop-network-key-seen') === '1',
        'the Key did not write down that it had been shown');
    /* Shut again, so the checks below see the map as a returning reader does. */
    shell.railToggle.dispatch('click');

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

    /* The key is the board's: what a fill means, the blue name, the
     * ellipse, the company colours on the lines. No kind colours. */
    let labels = legendLabels();
    const legendScene = focus.scene();
    const statusesShowing = new Set(legendScene.nodes.map((n) => n.status));
    check(labels.indexOf('Open') !== -1 && labels.indexOf('Closed') !== -1,
        'the legend does not say what the fills mean; it shows ' + JSON.stringify(labels.slice(0, 4)));
    check((statusesShowing.has('rebranded')) === (labels.indexOf('Rebranded (carried on under another name)') !== -1) &&
        (statusesShowing.has('unknown')) === (labels.indexOf('Status unrecorded') !== -1),
        'the legend lists a status that is nowhere on screen, or misses one that is');
    check((legendScene.nodes.some((n) => n.natsap)) === (labels.indexOf('NATSAP member (name in blue)') !== -1),
        'the legend and the map disagree about whether a NATSAP member is showing');
    check(labels.indexOf('Facilities') === -1 && labels.indexOf('Companies') === -1,
        'the legend still lists kinds as if they had colours');
    check(legendMarks().length === labels.length,
        'the legend has ' + legendMarks().length + ' swatches for ' + labels.length + ' rows',
        'legend: ' + labels.length + ' rows');
    check(legendMarks().every((c) => c.width > 0),
        'a legend swatch was never painted');

    /* It lists what is in view, not what exists. */
    const peopleShowing = legendScene.nodes.some((n) => n.kind === 'person');
    check(peopleShowing === (labels.indexOf('Person') !== -1),
        'the legend and the map disagree about whether any people are showing');
    const chainsShowing = new Set(legendScene.nodes.map((n) => n.chain).filter(Boolean));
    check(labels.indexOf('UHS') !== -1 && chainsShowing.has('UHS'),
        'the legend does not list the company whose lines are in view');
    check(labels.filter((l) => chainsShowing.has(l)).length === chainsShowing.size,
        'the legend lists companies that are not on screen');
    /* 2d.9 The two marks a line can carry. Nothing else on the page says
     * what an arrowhead means, or that the circles strung along a line are
     * the people who were at both ends, and each row shows only when that
     * mark is actually on screen. */
    const painter = sandbox.KOPNetworkCanvas;
    const ARROW_ROW = 'An arrowhead points from the owner to what it owned.';
    const PERSON_ROW = 'A circle on a line is someone who was at both ends. Click it for the name.';
    const arrowShowing = legendScene.edges.some((e) => painter.styleFor(e, false).arrow);
    const peopleOnLines = legendScene.edges.some((e) => painter.peopleOf(e).length > 0);
    check(arrowShowing === (labels.indexOf(ARROW_ROW) !== -1),
        'the legend and the map disagree about whether an arrowhead is on screen');
    check(peopleOnLines === (labels.indexOf(PERSON_ROW) !== -1),
        'the legend and the map disagree about whether a line carries people',
        'the key explains the marks on a line: arrowhead ' + (arrowShowing ? 'yes' : 'no') +
            ', people ' + (peopleOnLines ? 'yes' : 'no'));
    if (arrowShowing || peopleOnLines) {
        check(shell.legend.querySelectorAll('li')
            .filter((li) => li.className.indexOf('kop-network__legend-row--wrap') !== -1).length > 0,
            'a row that explains a mark is not marked to wrap, so it will be cut off at the panel edge');
    }

    const openedRows = labels;

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

    /* And the round trip: shown once, the Key stays shut on the next visit;
     * a reader it has no record of - a new browser, a private window, a
     * cleared store - gets it open again, which is the safe way round. */
    rail.start();
    check(shell.rail.hidden === true,
        'the Key opened again over the map for a reader who has already been shown it');
    sandbox.localStorage.clear();
    rail.start();
    check(shell.rail.hidden === false,
        'the Key stayed shut for a reader it has no record of',
        'the Key opens on a first visit and stays shut once it has been shown');
    shell.railToggle.dispatch('click');

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
    /* A click zooms in on its own connections, so a marked node further out
     * can be a pan away; every marked node on the stage carries its pill. */
    const bt = viewport.transform;
    const markedOnStage = Object.keys(hidden).filter((id) => {
        const p = focus.positionOf(store.node(id));
        const x = p.x * bt.k + bt.x;
        const y = p.y * bt.k + bt.y;
        return x >= 0 && x <= renderer.width && y >= 0 && y <= renderer.height;
    }).length;
    /* A "+N" pill on a line (the people it has no room for) is text of
     * the same shape; the renderer says how many of those it drew. */
    const morePills = (renderer.markers || []).filter((m) => m.kind === 'more').length;
    check(markedOnStage > 0 && badgeCalls.length === markedOnStage + morePills,
        'the renderer drew ' + badgeCalls.length + ' off-screen counts for ' + markedOnStage + ' marked nodes on the stage' +
        (morePills ? ' and ' + morePills + ' +N pills on lines' : ''),
        'each of the ' + markedOnStage + ' marked nodes on the stage carries a +N pill');
    check(badgeCalls.every((t) => /^\+[1-9]\d*$/.test(t)), 'an off-screen pill reads something other than +N');

    /* 2b.11 Lines. Two places that shared a member of staff are joined by one
     * line and the person is on it, not beside it: hover the line and a
     * popup says who; click it and the popup stays, with the person a
     * button. Everything else a line records reads the same way. */
    focus.clear();
    flushFrames();
    const lineHub = store.node('second-nature');
    focus.select(lineHub);
    flushFrames();
    resetOps();
    renderer.draw();
    const lineScene = focus.scene();
    /* A spot along a line that is on no name and where the pointer is on
     * that line. Lines out of one node leave along a shared run, and a
     * pointer on the run is on only one of them, so a line answers where it
     * runs alone - which for some is off the stage. */
    const spotOn = (line) => {
        const samePairAs = (e) => !!e && ((e.sourceId === line.sourceId && e.targetId === line.targetId) ||
            (e.sourceId === line.targetId && e.targetId === line.sourceId));
        /* By id: the scene is worked out afresh each time it is asked for, so
         * a folded line is a new object every time and only its id holds. */
        const route = (renderer.routes || []).find((r) => r.edge.id === line.id);
        if (!route) return null;
        for (let i = 1; i < route.pts.length; i++) {
            for (let f = 0.1; f < 1; f += 0.1) {
                const x = route.pts[i - 1][0] + (route.pts[i][0] - route.pts[i - 1][0]) * f;
                const y = route.pts[i - 1][1] + (route.pts[i][1] - route.pts[i - 1][1]) * f;
                if (x < 0 || x > WIDTH || y < 0 || y > HEIGHT) continue;
                if (viewport.nodeAt(x, y)) continue;
                /* A circle on the line is the person, not the line. */
                if (viewport.markerAt(x, y)) continue;
                if (samePairAs(viewport.edgeAt(x, y))) return { x, y };
            }
        }
        return null;
    };
    const hubLines = lineScene.edges.filter((e) => e.provenance === 'fold' &&
        (e.sourceId === lineHub.id || e.targetId === lineHub.id));
    const peopleLine = hubLines.find((e) => !!spotOn(e)) || hubLines[0];
    check(!!peopleLine, 'opening ' + lineHub.name + ' folded nobody into a line, so the lines are untested');
    if (peopleLine) {
        const Connection = sandbox.KOPNetworkConnection;
        const carriedHere = peopleLine.via.map((v) => v.person);

        /* Drawn as its own kind of line, and named in the key as one. A line
         * the build drew from the staff list says the same thing, so it is
         * drawn the same way. */
        const peopleStyle = renderer.styleOf(peopleLine);
        check(peopleStyle.label === 'Shared people' && !!peopleStyle.dash,
            'a line that stands for a person is drawn like ' + peopleStyle.label);
        const listLine = store.edges.find((e) => e.provenance === 'staff-list' && e.roles[0] === 'worked at both');
        check(!listLine || sandbox.KOPNetworkCanvas.styleFor(listLine, false).label === 'Shared people',
            'a staff-list line between two places is not drawn as shared people');
        check(!listLine || sandbox.KOPNetworkCanvas.peopleOn(listLine).length > 0,
            'a staff-list line does not give up the names in its text');

        /* Who is a hover away: no folded name is written on the board. */
        const foldedNames = Object.keys(lineScene.folded).map((id) => store.node(id).name);
        const written = captionCalls.filter((c) => foldedNames.some((name) => String(c).indexOf(name) !== -1));
        check(written.length === 0, 'a folded person is written along their line: ' + written[0]);
        check(labelCalls.every((c) => foldedNames.indexOf(c.slice(5)) === -1),
            'a folded person is still drawn as a name');

        /* The line can be pointed at: somewhere along it is a spot that is
         * on no name, and the pointer there is on this line. */
        const samePair = (e) => !!e && ((e.sourceId === peopleLine.sourceId && e.targetId === peopleLine.targetId) ||
            (e.sourceId === peopleLine.targetId && e.targetId === peopleLine.sourceId));
        const spot = spotOn(peopleLine);
        check(!!spot, 'no spot along the line between ' + peopleLine.source.name + ' and ' +
            peopleLine.target.name + ' answers to the pointer');

        const popupStage = doc.createElement('div');
        let lineAnnounced = '';
        const popup = Connection.create({
            stage: popupStage, focus, renderer, document: doc,
            announce: (text) => { lineAnnounced = text; }
        });
        lineEvents.popup = popup;
        check(!!popup && popup.element.hidden === true, 'the popup is on screen before anything is hovered');

        if (spot && popup) {
            const buttonsIn = (node) => node.querySelectorAll('button');

            /* Hover: the popup names the person and both places, the line is
             * drawn heavier, and nothing in it takes a click. */
            fire('pointermove', 1, spot.x, spot.y);
            check(samePair(lineEvents.hovered), 'moving onto a line did not report it');
            check(popup.element.hidden === false, 'hovering a line did not bring up the popup');
            const hoverText = popup.element.textContent;
            check(carriedHere.every((person) => hoverText.indexOf(person.name) !== -1),
                'the popup does not name ' + carriedHere.map((person) => person.name).join(', '),
                'hovering the line from ' + lineHub.name + ' to ' +
                (peopleLine.sourceId === lineHub.id ? peopleLine.target.name : peopleLine.source.name) +
                ' names ' + carriedHere.map((person) => person.name).join(', '));
            check(hoverText.indexOf(peopleLine.source.name) !== -1 && hoverText.indexOf(peopleLine.target.name) !== -1,
                'the popup does not say which two places the line joins');
            check(buttonsIn(popup.element).length === 0 && popup.element.getAttribute('data-pinned') === 'false',
                'a hovered popup offers buttons the pointer cannot reach');
            check(!!renderer.emphasis.hoverEdges && renderer.emphasis.hoverEdges[peopleLine.id] === true,
                'the hovered line is not marked for the renderer');
            check(!renderer.emphasis.near, 'hovering a line dimmed the rest of the map');
            const marked = renderer.emphasis;
            resetOps();
            renderer.setEmphasis({ hoverId: null });
            renderer.draw();
            const strokesPlain = ops.stroke;
            resetOps();
            renderer.setEmphasis(marked);
            renderer.draw();
            check(ops.stroke === strokesPlain + 2, 'the hovered line is not stroked again over the rest');

            /* Over a name, the name wins and the popup goes. */
            const lp = focus.positionOf(lineHub);
            fire('pointermove', 1, lp.x * viewport.transform.k + viewport.transform.x,
                lp.y * viewport.transform.k + viewport.transform.y);
            check(lineEvents.hovered === null && popup.element.hidden === true,
                'the popup stayed up with the pointer on a name');
            check(!renderer.emphasis.hoverEdges, 'the line stayed marked after the pointer left it');
            focus.hover(null);
            flushFrames();
            renderer.draw();

            /* Click: pinned, announced, and the person is a button that opens
             * them - as a name, since what is clicked never folds. */
            fire('pointerdown', 1, spot.x, spot.y);
            fire('pointerup', 1, spot.x, spot.y);
            check(samePair(lineEvents.selected) && popup.pinned(), 'clicking a line did not pin its popup');
            check(carriedHere.every((person) => lineAnnounced.indexOf(person.name) !== -1),
                'a pinned popup was not announced: ' + lineAnnounced);
            fire('pointermove', 1, 2, 2);
            check(popup.element.hidden === false, 'a pinned popup left with the pointer');
            const openers = buttonsIn(popup.element);
            check(openers.length === carriedHere.length,
                'a pinned popup has ' + openers.length + ' buttons for ' + carriedHere.length + ' people');
            if (openers.length) {
                openers[0].dispatch('click');
                flushFrames();
                const opened = focus.chain()[focus.chain().length - 1];
                check(opened === carriedHere[0].id, 'the button in the popup did not open ' + carriedHere[0].name);
                check(popup.element.hidden === true && !popup.pinned(), 'the popup outlived the view it described');
                const personView = focus.scene();
                check(personView.nodeIds[carriedHere[0].id] && !personView.folded[carriedHere[0].id],
                    carriedHere[0].name + ' was clicked and is still drawn as a line',
                    carriedHere[0].name + ', opened from the line, is a name with ' +
                    joinedOnScreen(personView, carriedHere[0].id).others.length + ' places round them');
            }

            /* A click on nothing puts a pinned popup away. */
            focus.clear();
            flushFrames();
            focus.select(lineHub);
            flushFrames();
            renderer.draw();
            popup.pin(focus.scene().edges.find(samePair), spot);
            check(popup.pinned(), 'the popup could not be pinned again');
            const before = lineEvents.selections;
            let empty = null;
            for (let x = 3; x < WIDTH && !empty; x += 7) {
                if (!viewport.nodeAt(x, 3) && !viewport.edgeAt(x, 3)) empty = { x, y: 3 };
            }
            if (empty) {
                fire('pointerdown', 1, empty.x, empty.y);
                fire('pointerup', 1, empty.x, empty.y);
                check(lineEvents.selections === before + 1 && lineEvents.selected === null && !popup.pinned(),
                    'a click on empty stage left the popup pinned');
            }

            /* 2b.11b The people on a line are on it: one small circle each
             * across the middle of the line, and a "+N" pill for the ones
             * the line has no room for. Every circle and pill sits on the
             * stage and on no name. Hover a circle and the popup names
             * that person alone, with their role at each end; click it and
             * they are a button. The pill names the rest. */
            popup.hide();
            focus.clear();
            flushFrames();
            focus.select(lineHub);
            flushFrames();
            resetOps();
            renderer.draw();
            const Canvas = sandbox.KOPNetworkCanvas;
            const markers = renderer.markers || [];
            const peopleLines = (renderer.routes || []).filter((r) => Canvas.peopleOf(r.edge).length > 0);
            check(peopleLines.length > 0, 'no line in ' + lineHub.name + "'s view stands for people");
            const markedLines = {};
            markers.forEach((m) => { (markedLines[m.edge.id] = markedLines[m.edge.id] || []).push(m); });
            let wrongCount = 0;
            let tooMany = 0;
            let onName = 0;
            let offStage = 0;
            let pills = 0;
            peopleLines.forEach((r) => {
                const ms = markedLines[r.edge.id] || [];
                if (!ms.length) return;
                const n = Canvas.peopleOf(r.edge).length;
                const circles = ms.filter((m) => m.kind === 'person');
                const more = ms.filter((m) => m.kind === 'more');
                const carried = circles.length + more.reduce((s, m) => s + m.people.length, 0);
                if (carried !== n || more.length > 1) wrongCount++;
                if (circles.length > Canvas.MARKER_MAX) tooMany++;
                pills += more.length;
                ms.forEach((m) => {
                    if (m.box[0] < 0 || m.box[2] > WIDTH || m.box[1] < 0 || m.box[3] > HEIGHT) offStage++;
                    if (renderer.labelHits.some((b) => m.box[0] < b.box[2] && m.box[2] > b.box[0] &&
                        m.box[1] < b.box[3] && m.box[3] > b.box[1])) onName++;
                });
            });
            const linesMarked = Object.keys(markedLines).length;
            /* Most lines, not merely one: a short line can be left with room
             * for none and fold every face into its pill, which is right,
             * but a view where that happened generally would be hiding its
             * people behind pills. */
            check(linesMarked >= peopleLines.length * 0.5,
                'only ' + linesMarked + ' of ' + peopleLines.length + ' lines in ' + lineHub.name +
                    "'s view carry their people as circles",
                linesMarked + ' of ' + peopleLines.length + ' lines with people carry circles, ' +
                markers.filter((m) => m.kind === 'person').length + ' circles and ' + pills + ' +N pills');
            check(wrongCount === 0, wrongCount + ' lines carry a different number of people than they stand for');
            check(tooMany === 0, tooMany + ' lines carry more than ' + Canvas.MARKER_MAX + ' circles');
            check(onName === 0 && offStage === 0,
                onName + ' circles or pills sit on a name and ' + offStage + ' are off the stage');
            check(captionCalls.every((c) => !/[0-9]+ people$/.test(String(c))),
                'a line still says "N people" in words as well as circles');

            /* Hover a circle: that person alone, on the line they are on. */
            const circle = markers.find((m) => m.kind === 'person' && !viewport.nodeAt(m.x, m.y));
            check(!!circle, 'no circle can be pointed at');
            if (circle) {
                const who = circle.people[0];
                const others = Canvas.peopleOf(circle.edge).filter((p) => p.key !== who.key);
                fire('pointermove', 1, circle.x, circle.y);
                const circleText = popup.element.textContent;
                check(popup.element.hidden === false && circleText.indexOf(who.name) !== -1,
                    'hovering a circle did not bring up ' + who.name);
                check(lineEvents.hovered === circle.edge,
                    'hovering a circle did not report the line it is on');
                check(others.every((p) => circleText.indexOf(p.name) === -1),
                    'the popup for one circle names the other people on the line');
                check(circleText.indexOf(circle.edge.source.name) !== -1 && circleText.indexOf(circle.edge.target.name) !== -1,
                    'the popup for a circle does not say which two places the person joins');
                check(renderer.emphasis.hoverMarker === circle.key,
                    'the hovered circle is not marked for the renderer');
                fire('pointerdown', 1, circle.x, circle.y);
                fire('pointerup', 1, circle.x, circle.y);
                check(popup.pinned(), 'clicking a circle did not pin its popup');
                const circleButtons = popup.element.querySelectorAll('button');
                check(circleButtons.length === (who.node ? 1 : 0),
                    'a pinned circle has ' + circleButtons.length + ' buttons for one person');
                popup.hide();
                fire('pointermove', 1, 2, 2);
                check(!renderer.emphasis.hoverMarker, 'the circle stayed marked after the pointer left it');
            }

            /* The pill: the people the line had no room for. Second Nature's
             * view rarely needs one, so a busier view is opened for it. */
            let pill = markers.find((m) => m.kind === 'more' && !viewport.nodeAt(m.x, m.y));
            let pillHub = lineHub;
            if (!pill) {
                popup.hide();
                pillHub = store.node('provo-canyon-school');
                focus.clear();
                flushFrames();
                focus.select(pillHub);
                flushFrames();
                resetOps();
                renderer.draw();
                pill = (renderer.markers || []).find((m) => m.kind === 'more' && !viewport.nodeAt(m.x, m.y));
            }
            check(!!pill, 'no line in ' + pillHub.name + "'s view needs a +N pill, so the pill is untested");
            if (pill) {
                const pillLine = (renderer.markers || []).filter((m) => m.edge === pill.edge);
                const pillCircles = pillLine.filter((m) => m.kind === 'person').length;
                check(pillCircles + pill.people.length === Canvas.peopleOf(pill.edge).length,
                    'the pill on the line from ' + pill.edge.source.name + ' hides the wrong people: ' +
                        pillCircles + ' drawn and ' + pill.people.length + ' hidden of ' +
                        Canvas.peopleOf(pill.edge).length);
                fire('pointermove', 1, pill.x, pill.y);
                const pillText = popup.element.textContent;
                check(popup.element.hidden === false && pill.people.every((p) => pillText.indexOf(p.name) !== -1),
                    'hovering a +N pill does not name the ' + pill.people.length + ' people behind it');
                check(badgeCalls.indexOf('+' + pill.people.length) !== -1,
                    'the pill does not read +' + pill.people.length);
                popup.hide();
                fire('pointermove', 1, 2, 2);
            }
            check(true, '', pill ? 'in ' + pillHub.name + "'s view a +N pill hides " + pill.people.length +
                ' people on the line from ' + pill.edge.source.name + ' to ' + pill.edge.target.name
                : '');
        }

        /* What the record says of two names directly reads the same way. */
        const owned = lineScene.edges.find((e) => e.category === 'corporate' && e.direction === 'none' && !e.via);
        if (owned) {
            const saidOwned = Connection.describe([owned], (e) => renderer.styleOf(e), sandbox.KOPNetworkCanvas);
            check(!!saidOwned && saidOwned.items.length === 1 && saidOwned.items[0].kind === 'record' &&
                saidOwned.items[0].label === 'Ownership',
                'an ownership line does not say it is one',
                'an ownership line says: ' + saidOwned.text);
        }
        const became = store.edges.find((e) => e.direction === 'renamed');
        const saidBecame = Connection.describe([became], (e) => renderer.styleOf(e), sandbox.KOPNetworkCanvas);
        check(saidBecame.items[0].text === became.source.name + ' became ' + became.target.name,
            'a rename line does not say which became which');
        lineEvents.popup = null;
    }

    /* 2b.12 The rule the map stands on: it is about relationships, so a
     * person on it is never there without every programme and company they
     * connect to - as a name or as a line, whoever brought them, whatever
     * kind of connection it is. Checked from every name on the board, not
     * from a sample: the gap this closes was in 68 views out of 1,258, the
     * ones where a person arrived second-hand as somebody's relative. */
    store.resetFilters();
    const hiddenTypes = store.edges.filter((e) => !store.filters.categories[e.category]);
    check(hiddenTypes.length === 0,
        hiddenTypes.length + ' connections are hidden by the filters the map opens with');
    const wasReduced = motion.reduced;
    motion.reduced = true;
    let viewsChecked = 0;
    const homelessIn = [];
    store.nodes.forEach((rootNode) => {
        if (!rootNode.degree) return;
        focus.restore([rootNode.id], 'focus');
        const view = focus.scene();
        if (!view.nodes.length) return;
        viewsChecked++;
        const people = view.nodes.filter((n) => n.kind === 'person').map((n) => n.id)
            .concat(Object.keys(view.folded));
        people.forEach((id) => {
            store.neighbours(id, false).forEach((l) => {
                if (l.other.kind === 'person' || onMap(view, l.other.id)) return;
                homelessIn.push(rootNode.name + ': ' + store.node(id).name + ' without ' + l.other.name);
            });
        });
    });
    motion.reduced = wasReduced;
    check(viewsChecked > 1000 && homelessIn.length === 0,
        homelessIn.length + ' times a person is on the map without a place they connect to, e.g. ' +
        homelessIn.slice(0, 3).join('; '),
        'in all ' + viewsChecked + ' views, every person on the map has every programme and company they connect to');
    focus.restore([], 'focus');
    flushFrames();

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
    const target = store.node('synanon');
    check(!focus.scene().nodeIds[target.id], 'the search target is already on the opening view, so the test proves nothing');
    const picked = Search.rank(store.nodes, 'synanon')[0].node;
    focus.select(picked);
    flushFrames();
    const afterPick = focus.scene();
    check(afterPick.nodeIds[target.id] &&
        store.neighbours(target.id, true).every((l) => onMap(afterPick, l.other.id)),
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
    /* A place that changed its name carries the old one: on the board under
     * the current name, in the drawer in full, and in the search box, which
     * is where a reader who only knows the old name goes first. */
    const renamed = store.nodes.filter((n) => (n.formerNames || []).length)
        .sort((a, b) => (b.degree || 0) - (a.degree || 0))[0];
    check(!!renamed, 'no node carries a past name, so past names are untested');
    if (renamed) {
        const wasCalled = renamed.formerNames[0];
        focus.clear();
        flushFrames();
        focus.select(renamed);
        flushFrames();
        drawer.update();
        const aliasLines = drawerBody.querySelectorAll('p')
            .filter((p) => p.className === 'kop-network__drawer-aliases')
            .map((p) => p.textContent);
        check(aliasLines.some((line) => line.indexOf('Formerly') === 0 && line.indexOf(wasCalled) !== -1),
            'the drawer does not say what ' + renamed.name + ' was called before; it says ' +
                JSON.stringify(aliasLines));
        check(!aliasLines.some((line) => line.indexOf('Also called') === 0 && line.indexOf(wasCalled) !== -1),
            'the drawer lists ' + renamed.name + "'s past name as merely another name for it");

        const found = sandbox.KOPNetworkSearch.rank(store.nodes, wasCalled, 8);
        check(found.length > 0 && found[0].node.id === renamed.id,
            'searching the old name "' + wasCalled + '" does not find ' + renamed.name,
            'searching "' + wasCalled + '" finds ' + renamed.name);

        resetOps();
        renderer.draw();
        const drawnSub = yearsCalls.find((t) => String(t).indexOf('formerly') !== -1);
        check(!!drawnSub,
            'no name on the board says what it was called before, though ' + renamed.name + ' was ' + wasCalled,
            'the board says: ' + drawnSub);

        /* Other names go on the board too, but initials do not: PCS tells a
         * reader of Provo Canyon School nothing, and neither does a shorter
         * way of saying the name above it. Both stay in the drawer and in
         * search, where they still find the place. */
        const painter2 = sandbox.KOPNetworkCanvas;
        check(painter2.isAbbreviation('PCS') && painter2.isAbbreviation('SCISU'),
            'initials are not recognised as initials');
        check(!painter2.isAbbreviation('Trails Academy') && !painter2.isAbbreviation('Camp E-Ma-Laku'),
            'a real name was taken for initials');
        let droppedAbbrev = 0;
        let keptOther = 0;
        store.nodes.forEach((n) => {
            const all = (n.otherNames || []).concat(n.aliases || []);
            if (!all.length) return;
            const board = painter2.boardOtherNames(n);
            board.forEach((name) => {
                check(!painter2.isAbbreviation(name),
                    n.name + ' carries the initials "' + name + '" on the board');
                check(String(name).toLowerCase() !== String(n.name).toLowerCase(),
                    n.name + ' carries its own name as another name on the board');
            });
            droppedAbbrev += all.length - board.length;
            keptOther += board.length;
        });
        check(droppedAbbrev > 0 && keptOther > 0,
            'the board either shows every other name or none of them: ' + keptOther + ' kept, ' +
                droppedAbbrev + ' dropped',
            keptOther + ' other names go on the board; ' + droppedAbbrev +
                ' initials and short forms stay in the drawer');
        /* And what the board drops is still findable. */
        const pcs = store.nodes.filter((n) => (n.otherNames || []).indexOf('PCS') !== -1)[0];
        if (pcs) {
            const byInitials = sandbox.KOPNetworkSearch.rank(store.nodes, 'PCS', 5);
            check(byInitials.length > 0 && byInitials[0].node.id === pcs.id,
                'searching the initials PCS no longer finds ' + pcs.name);
        }
        focus.clear();
        flushFrames();
        focus.select(hub);
        flushFrames();
        drawer.update();
    }

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

    /* ---------------------------------------------- paths between two names -- */

    /* Phase 3. store.paths: every route between two names, shortest first,
     * over the filtered lines, never through a trade association. A block of
     * its own, so its names cannot collide with the rest of run(). */
    {
    focus.setMode('focus');
    focus.clear();
    flushFrames();
    store.resetFilters();
    const lineBetween = (a, b) => store.edgesBetween(a, b).length > 0;
    const gil = store.node('david-gilcrease');
    const syn = store.node('synanon');
    check(!!gil && !!syn, 'the path fixture (David Gilcrease, Synanon) is not on the board');
    const routes = store.paths(gil.id, syn.id);
    check(routes.length > 0, 'no route found from David Gilcrease to Synanon',
        routes.length + ' routes from David Gilcrease to Synanon, ' +
        routes[0].hops + ' to ' + routes[routes.length - 1].hops + ' steps');
    check(routes.every((r) => r.ids[0] === gil.id && r.ids[r.ids.length - 1] === syn.id),
        'a route does not run from the first name to the second');
    check(routes.every((r) => r.hops === r.ids.length - 1 && r.hops <= store.PATH_MAX_HOPS),
        'a route is longer than the limit, or miscounts its steps');
    check(routes.every((r) => r.ids.every((id, i) => i === 0 || lineBetween(r.ids[i - 1], id))),
        'a route takes a step the map has no line for');
    check(routes.every((r) => new Set(r.ids).size === r.ids.length), 'a route visits a name twice');
    check(routes.every((r, i) => i === 0 || r.hops >= routes[i - 1].hops), 'routes are not shortest first');
    check(new Set(routes.map((r) => r.ids.join('>'))).size === routes.length, 'the same route is listed twice');
    check(routes.length <= store.PATH_LIMIT, 'more routes than the limit came back');
    check(routes.every((r) => r.ids.slice(1, -1).every((id) => store.node(id).kind !== 'association')),
        'a route passes through a trade association');

    /* The shortest really is: breadth first by hand, under the same rule. */
    const bfsHops = (fromId, toId) => {
        const seenAt = { [fromId]: 0 };
        const q = [fromId];
        while (q.length) {
            const at = q.shift();
            if (at === toId) return seenAt[at];
            store.neighbours(at, true).forEach((l) => {
                const o = l.other;
                if (seenAt[o.id] !== undefined) return;
                if (o.id !== toId && o.kind === 'association') return;
                seenAt[o.id] = seenAt[at] + 1;
                q.push(o.id);
            });
        }
        return -1;
    };
    check(routes[0].hops === bfsHops(gil.id, syn.id),
        'the first route is ' + routes[0].hops + ' steps; the shortest is ' + bfsHops(gil.id, syn.id));
    const back = store.paths(syn.id, gil.id);
    check(back.length === routes.length && back[0].hops === routes[0].hops,
        'the routes from B to A are not the routes from A to B');

    /* Two members of one trade group are not joined by belonging to it... */
    const natsap = store.node('natsap');
    if (natsap) {
        const members = store.neighbours(natsap.id, true).map((l) => l.other);
        let viaOnly = null;
        for (let i = 0; i < members.length && !viaOnly; i++) {
            for (let j = i + 1; j < members.length && !viaOnly; j++) {
                const open = store.paths(members[i].id, members[j].id, { throughAssociations: true, shortestOnly: true });
                if (open.length && open[0].hops === 2 && open.every((r) => r.ids[1] === natsap.id)) {
                    viaOnly = [members[i], members[j]];
                }
            }
        }
        if (viaOnly) {
            const kept = store.paths(viaOnly[0].id, viaOnly[1].id);
            check(kept.every((r) => r.ids.indexOf(natsap.id) === -1),
                viaOnly[0].name + ' and ' + viaOnly[1].name + ' are joined through NATSAP membership',
                viaOnly[0].name + ' and ' + viaOnly[1].name + ' share only NATSAP, and no route uses it');
        }
        /* ...but a trade group asked for by name is found. */
        check(store.paths(natsap.id, syn.id).length > 0, 'a trade association cannot be the end of a route');
    }

    /* Nothing joins a name with no connections, a name to itself, or a name
     * the board does not have; and a hidden connection type is not used. */
    const loner = store.nodes.find((n) => n.isolated);
    check(!loner || store.paths(syn.id, loner.id).length === 0, 'a route reached a name with no connections');
    check(store.paths(syn.id, syn.id).length === 0, 'a name has a route to itself');
    check(store.paths(syn.id, 'not-a-real-node').length === 0, 'a route reached a name the board does not have');
    const usedCategories = new Set();
    routes[0].ids.forEach((id, i) => {
        if (i) store.edgesBetween(routes[0].ids[i - 1], id).forEach((e) => usedCategories.add(e.category));
    });
    usedCategories.forEach((c) => store.toggleIn('categories', c, false));
    const without = store.paths(gil.id, syn.id);
    check(without.every((r) => r.ids.join('>') !== routes[0].ids.join('>')),
        'a route still uses connection types that are switched off');
    store.resetFilters();

    /* The worst case is two hubs; it has to stay instant on a phone. */
    const hubsByDegree = store.nodes.slice().sort((a, b) => b.degree - a.degree).slice(0, 12);
    const pathClock = Date.now();
    hubsByDegree.forEach((a) => hubsByDegree.forEach((b) => { if (a !== b) store.paths(a.id, b.id); }));
    const perPair = (Date.now() - pathClock) / (hubsByDegree.length * (hubsByDegree.length - 1));
    check(perPair < 50, 'a route between two hubs takes ' + perPair.toFixed(1) + ' ms',
        'routes between the 12 busiest names: ' + perPair.toFixed(2) + ' ms a pair');

    /* The route on the board: its names, in order, the lines between each
     * and the next, and nothing else - no folding, nobody's other places. */
    const longest = routes[routes.length - 1];
    check(focus.showPath(longest.ids) === true, 'a route the store found could not be shown');
    flushFrames();
    resetOps();
    renderer.draw();
    const routeScene = focus.scene();
    check(focus.isPath() && focus.mode() === 'path', 'showing a route did not enter path mode');
    check(focus.chain().join(',') === longest.ids.join(','), 'the trail is not the route');
    check(routeScene.nodes.length === longest.ids.length && longest.ids.every((id) => routeScene.nodeIds[id]),
        'the route view shows ' + routeScene.nodes.length + ' names for a route of ' + longest.ids.length);
    check(Object.keys(routeScene.folded).length === 0, 'somebody on a route was folded into a line');
    const stepIndex = {};
    longest.ids.forEach((id, i) => { stepIndex[id] = i; });
    check(routeScene.edges.length >= longest.hops &&
        routeScene.edges.every((e) => Math.abs(stepIndex[e.sourceId] - stepIndex[e.targetId]) === 1),
        'the route view draws a line that is not a step of the route');
    /* In order along one line: a row left to right, or a column top to bottom. */
    const routePts = longest.ids.map((id) => focus.positionOf(store.node(id)));
    const inRow = routePts.every((p) => Math.abs(p.y - routePts[0].y) < 0.5) &&
        routePts.every((p, i) => i === 0 || p.x > routePts[i - 1].x);
    const inColumn = routePts.every((p) => Math.abs(p.x - routePts[0].x) < 0.5) &&
        routePts.every((p, i) => i === 0 || p.y > routePts[i - 1].y);
    check(inRow || inColumn, 'the route is not laid out in order along one line',
        'a ' + longest.hops + '-step route is drawn as a ' + (inRow ? 'row' : 'column') + ' of ' + longest.ids.length);
    const routeNames = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    longest.ids.forEach((id) => {
        check(routeNames.indexOf(store.node(id).name) !== -1, store.node(id).name + ' is on the route without its name');
    });
    check(collidingLabels(labelBoxes).length === 0, 'names on the route overlap');
    const routeT = viewport.transform;
    check(routePts.every((p) => {
        const x = p.x * routeT.k + routeT.x;
        const y = p.y * routeT.k + routeT.y;
        return x >= 0 && x <= WIDTH && y >= 0 && y <= HEIGHT;
    }), 'part of the route is off the stage');
    /* Every trace is straight: one leg, crossing nobody. */
    check((renderer.routes || []).length >= longest.hops && renderer.routes.every((r) => r.pts.length === 2),
        'a step of the route is drawn with a bend, or not drawn');
    check(/^Route from David Gilcrease to Synanon, \d steps/.test(announced), 'the route was not announced: ' + announced);

    /* A short route fits in a row; the same code at a phone width stacks it. */
    const direct = store.paths(gil.id, 'wwasps', { shortestOnly: true })[0];
    check(!!direct && direct.hops === 1, 'David Gilcrease to WWASPS should be one step');
    focus.showPath(direct.ids);
    flushFrames();
    const directPts = direct.ids.map((id) => focus.positionOf(store.node(id)));
    check(Math.abs(directPts[0].y - directPts[1].y) < 0.5 && directPts[1].x > directPts[0].x,
        'a two-name route on a wide stage is not a row');
    /* A row is framed by where its names are centred, and the names at its
     * two ends are as wide on screen at any zoom: both have to be on the
     * stage whole, not cut off by its edges. */
    const rowRoute = store.paths('sequel-youth-and-family-services', 'youth-services-international', { shortestOnly: true })[0];
    const ROW_STAGE = 1300;
    setStage(ROW_STAGE, HEIGHT);
    renderer.resize();
    focus.showPath(rowRoute.ids);
    flushFrames();
    const rowT = viewport.transform;
    const rowXs = rowRoute.ids.map((id) => focus.positionOf(store.node(id)).x);
    check(rowXs.every((x, i) => i === 0 || x > rowXs[i - 1]),
        'three long names on a ' + ROW_STAGE + ' px stage are not a row, so the row framing is untested');
    rowRoute.ids.forEach((id) => {
        const node = store.node(id);
        const at = focus.positionOf(node).x * rowT.k + rowT.x;
        const half = renderer.labelBox(node).width / 2;
        check(at - half >= 0 && at + half <= ROW_STAGE,
            node.name + ' hangs off the stage in a row route: ' + Math.round(at - half) + ' to ' + Math.round(at + half));
    });
    setStage(375, 640);
    renderer.resize();
    focus.showPath(longest.ids);
    flushFrames();
    resetOps();
    renderer.draw();
    const phonePts = longest.ids.map((id) => focus.positionOf(store.node(id)));
    check(phonePts.every((p, i) => Math.abs(p.x - phonePts[0].x) < 0.5 && (i === 0 || p.y > phonePts[i - 1].y)),
        'a long route at 375 px is not a column');
    check(collidingLabels(labelBoxes).length === 0, 'names on the route overlap at 375 px');
    setStage(WIDTH, HEIGHT);
    renderer.resize();

    /* A route the map has no line for is refused, and nothing changes. */
    focus.showPath(longest.ids);
    flushFrames();
    check(focus.showPath([gil.id, syn.id]) === false && focus.chain().join(',') === longest.ids.join(','),
        'a route with a missing step was shown, or replaced the one on the board');

    /* A crumb cuts the route back; cut back to one name it is that name's view. */
    focus.truncateTo(2);
    flushFrames();
    check(focus.isPath() && focus.scene().nodes.length === 3, 'a crumb did not cut the route back to its third name');
    focus.truncateTo(0);
    flushFrames();
    check(!focus.isPath() && focus.mode() === 'focus' && focus.chain().join(',') === gil.id,
        'a route cut back to one name is still a route');
    check(store.neighbours(gil.id, true).every((l) => onMap(focus.scene(), l.other.id)),
        'leaving a route did not open the name that was left');

    /* A click on the route leaves it for that name, in Focus. */
    focus.setMode('expand');
    focus.showPath(longest.ids);
    flushFrames();
    const midStep = store.node(longest.ids[1]);
    focus.select(midStep);
    flushFrames();
    check(focus.mode() === 'focus' && !focus.isPath() && focus.chain()[focus.chain().length - 1] === midStep.id,
        'clicking a name on a route did not open it in focus mode');
    check(store.neighbours(midStep.id, true).every((l) => onMap(focus.scene(), l.other.id)),
        'the name clicked on a route did not arrive with its connections');

    /* A filter that takes a step away takes the route with it, not the page. */
    focus.showPath(routes[0].ids);
    flushFrames();
    usedCategories.forEach((c) => store.toggleIn('categories', c, false));
    focus.refresh();
    flushFrames();
    check(!focus.isPath(), 'a route survived the filter that removed its lines');
    store.resetFilters();
    focus.clear();
    flushFrames();
    check(focus.mode() === 'focus', 'starting over from a route left the map in path mode');

    /* The address bar carries a route, and a link opens one. */
    check(Url.parse(Url.format(longest.ids, 'path')).mode === 'path', 'the hash does not round-trip a route');
    focus.showPath(longest.ids);
    flushFrames();
    url.write();
    check(fakeLocation.hash === '#open=' + longest.ids.join(',') + '&mode=path', 'a route was not written to the hash: ' + fakeLocation.hash);
    focus.clear();
    flushFrames();
    fakeLocation.hash = '#open=' + longest.ids.join(',') + '&mode=path';
    url.read();
    flushFrames();
    check(focus.isPath() && focus.chain().join(',') === longest.ids.join(','), 'a shared link did not reopen its route');
    /* A link whose route the board no longer has opens its last name instead. */
    fakeLocation.hash = '#open=' + gil.id + ',' + syn.id + '&mode=path';
    url.read();
    flushFrames();
    check(!focus.isPath() && focus.chain()[focus.chain().length - 1] === syn.id,
        'a link to a route that no longer holds did not fall back to a name');
    focus.clear();
    flushFrames();

    /* path.js: the form's find(), and the route written out in the drawer. */
    const PathUi = sandbox.KOPNetworkPath;
    check(!!PathUi, 'path.js did not load');
    const pathMessage = doc.createElement('p');
    const pathUi = PathUi.create({ store, focus, document: doc, elements: { message: pathMessage } });
    check(pathUi.find(gil, gil).length === 0 && /same name/.test(pathMessage.textContent), 'the same name twice was not refused');
    check(pathUi.find(syn, loner).length === 0 && /No route/.test(pathMessage.textContent), 'no route was not reported');
    check(!focus.isPath(), 'a search that found nothing changed the board');
    const foundRoutes = pathUi.find(gil, syn);
    flushFrames();
    check(foundRoutes.length === routes.length && focus.isPath() &&
        focus.chain().join(',') === routes[0].ids.join(','), 'finding routes did not put the shortest on the board');

    const told = PathUi.describeRoute(store, routes[0].ids, sandbox.KOPNetworkConnection, sandbox.KOPNetworkCanvas);
    check(told.length === routes[0].ids.length && told[told.length - 1].joins.length === 0 &&
        told.slice(0, -1).every((step) => step.joins.length > 0),
        'a step of the route says nothing about what joins it to the next',
        'route, written out: ' + told.map((t) => t.node.name + (t.joins.length ? ' [' + t.joins[0] + ']' : '')).join(' > '));

    const routeDrawerEl = doc.createElement('aside');
    routeDrawerEl.hidden = true;
    const routeDrawerBody = doc.createElement('div');
    const routeDrawer = Drawer.create({
        store, focus, config: drawerConfig, document: doc,
        drawer: routeDrawerEl, body: routeDrawerBody, close: doc.createElement('button'),
        renderPath: (body) => pathUi.renderInto(body)
    });
    routeDrawer.update();
    const stepButtons = routeDrawerBody.querySelectorAll('.kop-network__route-step');
    check(routeDrawerEl.hidden === false && stepButtons.length === routes[0].ids.length,
        'the drawer does not list the route: ' + stepButtons.length + ' steps for ' + routes[0].ids.length + ' names');
    const routeButtons = routeDrawerBody.querySelectorAll('.kop-network__drawer-list')[0].querySelectorAll('.kop-network__drawer-link');
    check(routeButtons.length === routes.length, 'the drawer offers ' + routeButtons.length + ' of ' + routes.length + ' routes');
    check(routeButtons.filter((b) => b.getAttribute('aria-current') === 'true').length === 1,
        'the drawer does not mark which route is on the board');
    routeButtons[routeButtons.length - 1].dispatch('click');
    flushFrames();
    check(focus.chain().join(',') === routes[routes.length - 1].ids.join(','), 'choosing another route in the drawer did not show it');
    routeDrawer.update();
    routeDrawerBody.querySelectorAll('.kop-network__route-step')[1].querySelector('.kop-network__drawer-link').dispatch('click');
    flushFrames();
    routeDrawer.update();
    check(!focus.isPath() && routeDrawer.shownId() === routes[routes.length - 1].ids[1],
        'a name in the written-out route did not open, or the drawer did not follow it');
    focus.setMode('focus');
    focus.clear();
    flushFrames();
    }

    /* ------------------------------------------------ the keyboard on the stage -- */

    /* Step 7. The canvas is one element standing for every name on it, so
     * the arrow keys move a cursor from name to name, the cursor previews
     * the way a pointer's hover does, and Enter opens what it is on. */
    {
    const Keys = sandbox.KOPNetworkKeys;
    check(!!Keys, 'keys.js did not load');

    /* The nearest name in the arrow's direction, on a small board. */
    const board = [
        { id: 'a', x: 0, y: 0 }, { id: 'b', x: 100, y: 0 }, { id: 'c', x: 200, y: 0 },
        { id: 'd', x: 0, y: 100 }, { id: 'e', x: 110, y: 100 }
    ];
    const at = (id) => board.find((p) => p.id === id);
    const go = (id, dir) => { const n = Keys.nextInDirection(board, at(id), dir); return n ? n.id : null; };
    const RIGHT = [1, 0], LEFT = [-1, 0], UP = [0, -1], DOWN = [0, 1];
    check(go('a', RIGHT) === 'b' && go('b', RIGHT) === 'c', 'right does not walk along the row');
    /* Left and right carry on into the next row, in reading order, so the
     * two of them reach every name; only the first and last have an edge. */
    check(go('c', RIGHT) === 'd' && go('d', LEFT) === 'c', 'right at the end of a row does not carry on into the next');
    check(go('e', RIGHT) === null && go('a', LEFT) === null, 'an arrow found a name where there is none');
    check(go('a', DOWN) === 'd', 'down does not go to the name below');
    check(go('b', DOWN) === 'e', 'down from b went to ' + go('b', DOWN) + ', not the name nearly under it');
    check(go('e', UP) === 'b', 'up from e went to ' + go('e', UP));
    check(go('a', UP) === null && go('b', UP) === null, 'a name level with the cursor counted as above it');

    /* On the real map. A canvas of its own, so the stub's one-listener-per-
     * event canvas keeps the viewport's. */
    let said = '';
    let prevented = 0;
    const press = (key) => keys.key({ key, preventDefault() { prevented++; } });
    focus.setMode('focus');
    focus.clear();
    flushFrames();
    const keys = Keys.create({
        canvas: { addEventListener() {} }, focus, viewport, renderer,
        announce: (text) => { said = text; }
    });

    /* The opening view has no head, so the cursor starts nearest the middle. */
    press('ArrowRight');
    const openingIds = focus.scene().nodeIds;
    check(!!keys.cursor() && openingIds[keys.cursor()], 'the first arrow on the opening view did not put the cursor on a name');
    check(prevented === 1, 'an arrow key was left to scroll the page');

    focus.select(hub);
    flushFrames();
    press('ArrowDown');
    check(keys.cursor() === hub.id, 'the cursor did not start on what was opened: ' + keys.cursor());
    check(said.indexOf(hub.name) === 0 && /connections on the map/.test(said) && /Enter/.test(said),
        'the cursor did not say where it is: ' + said);
    check(!!renderer.emphasis.near && renderer.emphasis.near[hub.id], 'the cursor does not preview the name it is on');

    /* Each arrow lands on a name that really is that way on screen. */
    const screenOf = (id) => {
        const p = focus.positionOf(store.node(id));
        const t = viewport.transform;
        return { x: p.x * t.k + t.x, y: p.y * t.k + t.y };
    };
    [['ArrowDown', 0, 1], ['ArrowRight', 1, 0], ['ArrowUp', 0, -1], ['ArrowLeft', -1, 0]].forEach(([key, dx, dy]) => {
        const before = keys.cursor();
        const from = screenOf(before);
        press(key);
        flushFrames();
        if (keys.cursor() === before) return;
        /* Measured before any pan the move caused, so compare world order. */
        const a = focus.positionOf(store.node(before));
        const b = focus.positionOf(store.node(keys.cursor()));
        check((b.x - a.x) * dx + (b.y - a.y) * dy > 0,
            key + ' moved the cursor from ' + before + ' to ' + keys.cursor() + ', which is not that way');
        check(focus.scene().nodeIds[keys.cursor()], key + ' put the cursor on a name that is not on the map');
        void from;
    });

    /* Every name on a view can be reached with the arrows, from where the
     * cursor starts. Checked on the opening view and the busiest names. */
    const reachable = (scene, startId) => {
        const t = viewport.transform;
        const pts = scene.nodes.map((n) => {
            const p = focus.positionOf(n);
            return { id: n.id, x: p.x * t.k + t.x, y: p.y * t.k + t.y };
        });
        const byId = {};
        pts.forEach((p) => { byId[p.id] = p; });
        const seen = { [startId]: true };
        const q = [startId];
        while (q.length) {
            const cur = byId[q.shift()];
            [RIGHT, LEFT, UP, DOWN].forEach((dir) => {
                const n = Keys.nextInDirection(pts, cur, dir);
                if (n && !seen[n.id]) { seen[n.id] = true; q.push(n.id); }
            });
        }
        return scene.nodes.filter((n) => !seen[n.id]);
    };
    const busiest = store.nodes.slice().sort((a, b) => b.degree - a.degree).slice(0, 40);
    let stranded = 0;
    let walked = 0;
    const strandedIn = [];
    busiest.forEach((node) => {
        focus.clear();
        focus.select(node);
        flushFrames();
        const scene = focus.scene();
        const lost = reachable(scene, node.id);
        walked += scene.nodes.length;
        if (lost.length) { stranded += lost.length; strandedIn.push(node.name + ' (' + lost.map((n) => n.name).slice(0, 3).join(', ') + ')'); }
    });
    check(stranded === 0, stranded + ' names cannot be reached with the arrow keys: ' + strandedIn.slice(0, 4).join('; '),
        'the arrow keys reach all ' + walked + ' names across the 40 busiest views');

    /* Enter opens the name under the cursor. */
    focus.clear();
    focus.select(hub);
    flushFrames();
    press('ArrowDown');
    press('ArrowDown');
    flushFrames();
    const under = keys.cursor();
    check(under && under !== hub.id, 'the cursor did not leave the opened name, so Enter is untested');
    press('Enter');
    flushFrames();
    check(focus.chain()[focus.chain().length - 1] === under, 'Enter did not open the name under the cursor');

    /* A cursor name that is off the stage is brought onto it. */
    const t0 = viewport.transform;
    viewport.setTransform(t0.k, t0.x - 5000, t0.y);
    press('ArrowLeft');
    const back = screenOf(keys.cursor());
    check(back.x >= 0 && back.x <= WIDTH && back.y >= 0 && back.y <= HEIGHT,
        'the cursor is on a name that is off the stage');

    /* Plus and minus zoom; zero puts the view back. */
    focus.reframe();
    flushFrames();
    const framedK = viewport.transform.k;
    press('+');
    check(viewport.transform.k > framedK, 'plus did not zoom in');
    press('-');
    press('-');
    check(viewport.transform.k < framedK, 'minus did not zoom out');
    press('0');
    flushFrames();
    check(Math.abs(viewport.transform.k - framedK) < 1e-6, 'zero did not reset the view');

    /* A shortcut with a modifier belongs to the browser. */
    const kBefore = viewport.transform.k;
    keys.key({ key: '+', ctrlKey: true, preventDefault() { prevented = -99; } });
    check(viewport.transform.k === kBefore && prevented !== -99, 'Ctrl-plus was taken from the browser');

    focus.hover(null);
    focus.setMode('focus');
    focus.clear();
    flushFrames();
    }

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

    /* 2d.10 Fit to screen is the other half of that pair: it moves the
     * camera and nothing else, so a reader who has zoomed too far in gets
     * the whole board back without the board rearranging itself under
     * them. */
    focus.select(hub);
    flushFrames();
    const fitLayout = {};
    focus.scene().nodes.forEach((n) => { fitLayout[n.id] = Object.assign({}, focus.positionOf(n)); });
    viewport.setTransform(sandbox.KOPNetworkViewport.MAX_ZOOM, 0, 0);
    flushFrames();
    check(!viewport.everythingInView(),
        'zoomed to the ceiling in a corner, the map still says everything is on the stage');
    focus.fitAll();
    flushFrames();
    check(viewport.everythingInView(),
        'Fit to screen left part of the map off the stage');
    check(focus.scene().nodes.every((n) => {
        const was = fitLayout[n.id];
        const now = focus.positionOf(n);
        return was && Math.abs(now.x - was.x) < 0.001 && Math.abs(now.y - was.y) < 0.001;
    }), 'Fit to screen moved the names as well as the camera',
        'Fit to screen brings all ' + focus.scene().nodes.length +
            ' names back on the stage and moves nothing');

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
    check(yearsCalls.some((t) => String(t).indexOf(dated.years) === 0),
        dated.name + ' is on the board without its years (' + dated.years + '); the lines under ' +
            'the names are ' + JSON.stringify(yearsCalls.slice(0, 4)),
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
    /* The opening view is a click's cluster (2d.1), so on a phone it is
     * framed as a click is: the organisation and its own connections on
     * the stage at a legible zoom, the rest a pan away, and everything on
     * the stage named. */
    const phoneScene = focus.scene();
    const phoneLabels = labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    const openT = viewport.transform;
    const openOnStage = phoneScene.nodes.filter((n) => {
        const p = focus.positionOf(n);
        const sxp = p.x * openT.k + openT.x;
        const syp = p.y * openT.k + openT.y;
        return sxp >= 0 && sxp <= 375 && syp >= 0 && syp <= 640;
    });
    check(openOnStage.length >= 8, 'only ' + openOnStage.length + ' of the opening view is on the stage at 375 px');
    check(openOnStage.some((n) => n.id === 'universal-health-services'),
        'at 375 px the organisation the map opens on is off the stage');
    openOnStage.forEach((n) => {
        check(phoneLabels.indexOf(n.name) !== -1,
            'at 375 px the opening view has ' + n.name + ' on the stage without its name');
    });
    check(collidingLabels(labelBoxes).length === 0,
        'labels overlap at 375 px: ' + JSON.stringify(collidingLabels(labelBoxes)[0] || null),
        'at 375 px the opening view has ' + openOnStage.length + ' of ' + phoneScene.nodes.length + ' names on the stage, all named');

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
