#!/usr/bin/env node
/**
 * Network map embed tests (Phase 4.1)
 *
 * A facility page carries a slice of the graph, cut in PHP
 * (kop_network_map_slice_from_graph in inc/network-map.php), and paints it
 * with the map's own modules (js/network-map/embed.js). The slice is only
 * right if it holds what the map shows when that name is opened, so this
 * cuts every slice with the PHP and holds each one to js/network-map/focus.js
 * on the whole graph:
 *
 *   - the same names on the board, folded into lines the same way;
 *   - the same "+N" on every name (offSlice makes up the connections the
 *     slice left out);
 *   - the same positions once the view has settled.
 *
 * Then the page path: embed.js against a stub figure paints once, unhides
 * the figure, draws the root and every name without labels colliding,
 * keeps the wheel for the page, and a click on a name builds the map link
 * (Ctrl-click the profile page).
 *
 *   node scripts/test-network-embed.js [--php=<php.exe>] [--only=<id>,<id>]
 *
 * --php defaults to $KOP_PHP, then the php.exe bundled with Flywheel Local,
 * then "php". About a minute for the 536 facility names.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');
const { buildSandbox, collidingLabels } = require('./test-network-modules.js');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');

const failures = [];
const notes = [];
function check(condition, message, note) {
    if (!condition) failures.push(message);
    else if (note) notes.push(note);
}

function arg(name) {
    const hit = process.argv.find((a) => a.startsWith('--' + name + '='));
    return hit ? hit.slice(name.length + 3) : '';
}

function findPhp() {
    if (arg('php')) return arg('php');
    if (process.env.KOP_PHP) return process.env.KOP_PHP;
    const base = path.join(process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local'),
        'Programs', 'Local', 'resources', 'extraResources', 'lightning-services');
    try {
        const dirs = fs.readdirSync(base).filter((d) => /^php-8\./.test(d)).sort().reverse();
        for (const d of dirs) {
            const exe = path.join(base, d, 'bin', 'win32', 'php.exe');
            if (fs.existsSync(exe)) return exe;
        }
    } catch (e) { /* not on this machine */ }
    return 'php';
}

/** What a focused view is: names on the board, names folded into lines, the "+N" on each. */
function describe(focus) {
    const scene = focus.scene();
    const hidden = {};
    Object.keys(scene.hidden || {}).sort().forEach((id) => { hidden[id] = scene.hidden[id]; });
    return {
        nodes: scene.nodes.map((n) => n.id).sort(),
        folded: Object.keys(scene.folded || {}).sort(),
        hidden,
        positions: scene.nodes.reduce((acc, n) => {
            const p = focus.positionOf(n);
            acc[n.id] = [p.x, p.y];
            return acc;
        }, {}),
        edges: scene.edges.length
    };
}

function run() {
    const graph = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'graph.json'), 'utf8'));
    const layout = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'layout.json'), 'utf8'));
    const only = arg('only') ? arg('only').split(',') : [];

    const php = findPhp();
    let slices;
    try {
        const out = execFileSync(php, ['-n', '-d', 'memory_limit=1G', path.join(ROOT, 'scripts', 'network-slices.php')].concat(only),
            { maxBuffer: 256 * 1024 * 1024, encoding: 'utf8' });
        slices = JSON.parse(out);
    } catch (error) {
        failures.push('could not cut the slices with ' + php + ': ' + (error.message || error).split('\n')[0]);
        return;
    }
    const roots = Object.keys(slices).filter((id) => id.indexOf('view:') !== 0);
    const viewKeys = Object.keys(slices).filter((id) => id.indexOf('view:') === 0).map((id) => id.slice(5));
    const expected = only.length ? only.filter((id) => id.indexOf('view:') !== 0).length
        : graph.nodes.filter((n) => n.facilityId).length;
    check(roots.length === expected, 'the PHP cut ' + roots.length + ' slices for ' + expected + ' facility names');
    if (!only.length) {
        check(viewKeys.length === graph.meta.views.length,
            'the PHP cut ' + viewKeys.length + ' view slices for ' + graph.meta.views.length + ' starter views');
    }

    const box = buildSandbox();
    const { sandbox } = box;
    /* No settle animation, as for a reader who asked for less motion: the
     * positions compared are where the view comes to rest either way. */
    box.motion.reduced = true;
    sandbox.KOP_NETWORK_EMBED_MANUAL = true;
    vm.runInContext(fs.readFileSync(path.join(ROOT, 'js', 'network-map', 'embed.js'), 'utf8'), sandbox,
        { filename: 'js/network-map/embed.js' });
    check(!!sandbox.KOPNetworkEmbed, 'embed.js did not define KOPNetworkEmbed');

    /* id is a name to open, or view:<key> for a starter view as the map
     * opens on it. */
    function focused(store, id) {
        const renderer = sandbox.KOPNetworkCanvas.create(box.canvas);
        const viewport = sandbox.KOPNetworkViewport.create({ canvas: box.canvas, renderer });
        const focus = sandbox.KOPNetworkFocus.create({ store, renderer, viewport });
        renderer.useChainIndex(store.chainIndex);
        renderer.useBoardColours(store.meta);
        renderer.resize();
        if (id.indexOf('view:') === 0) {
            store.setView(id.slice(5));
            focus.start();
        } else {
            focus.select(store.node(id));
        }
        box.flushFrames();
        box.runTimers();
        box.flushFrames();
        const seen = describe(focus);
        if (focus.destroy) focus.destroy();
        viewport.destroy();
        return seen;
    }

    const full = sandbox.KOPNetworkStore.create();
    full.hydrate(graph, layout);

    /* ------------------------------------------ every slice against the map -- */

    let biggest = { bytes: 0, id: '' };
    let same = 0;
    const moved = [];
    roots.forEach((id) => {
        const slice = slices[id];
        if (!slice) {
            failures.push('no slice for ' + id);
            return;
        }
        const bytes = JSON.stringify(slice).length;
        if (bytes > biggest.bytes) biggest = { bytes, id };
        check(slice.root === id && slice.nodes.some((n) => n.id === id), id + ': the slice does not hold its root');
        const ids = new Set(slice.nodes.map((n) => n.id));
        check(slice.edges.every((e) => ids.has(e.source) && ids.has(e.target)), id + ': a line in the slice has an end outside it');
        check(slice.nodes.every((n) => full.node(n.id)) && slice.edges.every((e) => full.edgeById[e.id]),
            id + ': the slice names something graph.json does not have');
        check(slice.nodes.every((n) => slice.layout.positions[n.id]), id + ': a name in the slice has no position');
        check((slice.more > 0) === (full.neighbours(id).length > 40), id + ': the cap note does not match the cap');

        compare(id, slice);
    });

    /* The starter views, as the map opens on each (the history hub's
     * preview). The whole store goes back to the default view after. */
    viewKeys.forEach((key) => {
        const slice = slices['view:' + key];
        if (!slice) {
            failures.push('no slice for the ' + key + ' view');
            return;
        }
        check(slice.view === key && slice.meta.views.length === 1 && slice.meta.views[0].key === key,
            key + ': the view slice does not carry its view as its only one');
        const ids = new Set(slice.nodes.map((n) => n.id));
        check(slice.edges.every((e) => ids.has(e.source) && ids.has(e.target)), key + ': a line in the view slice has an end outside it');
        compare('view:' + key, slice);
        full.setView('default');
    });

    function compare(id, slice) {
        const small = sandbox.KOPNetworkStore.create();
        small.hydrate(slice, slice.layout);
        const want = focused(full, id);
        const got = focused(small, id);
        const a = JSON.stringify([want.nodes, want.folded, want.hidden, want.edges]);
        const b = JSON.stringify([got.nodes, got.folded, got.hidden, got.edges]);
        if (a !== b) {
            const missing = want.nodes.filter((n) => got.nodes.indexOf(n) === -1);
            const extra = got.nodes.filter((n) => want.nodes.indexOf(n) === -1);
            const badges = Object.keys(want.hidden).concat(Object.keys(got.hidden))
                .filter((n) => want.hidden[n] !== got.hidden[n]);
            failures.push(id + ': the embed shows a different board from the map' +
                (missing.length ? '; missing ' + missing.slice(0, 5).join(', ') : '') +
                (extra.length ? '; extra ' + extra.slice(0, 5).join(', ') : '') +
                (badges.length ? '; "+N" differs on ' + badges.slice(0, 5).join(', ') : '') +
                (want.edges !== got.edges ? '; ' + got.edges + ' lines, map ' + want.edges : ''));
            return;
        }
        const drift = want.nodes.filter((n) => {
            const p = want.positions[n];
            const q = got.positions[n];
            return Math.abs(p[0] - q[0]) > 0.5 || Math.abs(p[1] - q[1]) > 0.5;
        });
        if (drift.length) moved.push(id + ' (' + drift.length + ')');
        else same++;
    }
    check(moved.length === 0,
        moved.length + ' slices settle to other positions than the map: ' + moved.slice(0, 8).join(', '));
    notes.push(same + ' of ' + (roots.length + viewKeys.length) + ' slices draw the board the map draws, name for name and place for place');
    check(biggest.bytes < 120 * 1024, 'the largest slice, ' + biggest.id + ', is ' + Math.round(biggest.bytes / 1024) + ' KB',
        'largest slice: ' + biggest.id + ', ' + Math.round(biggest.bytes / 1024) + ' KB');

    /* ------------------------------------------------------ the page path -- */

    const pageRoot = slices['provo-canyon-school'] ? 'provo-canyon-school' : roots[0];
    const slice = JSON.parse(JSON.stringify(slices[pageRoot]));
    slice.mapUrl = 'https://kidsoverprofits.org/network-map/';
    slice.urls = [[pageRoot, 'https://kidsoverprofits.org/facility/' + pageRoot + '/']];

    const listeners = {};
    const button = (attrs) => ({
        attrs,
        getAttribute: (k) => (k in attrs ? attrs[k] : null),
        addEventListener: (type, fn) => { (listeners[attrs.name] = listeners[attrs.name] || []).push(fn); }
    });
    const zoomIn = button({ name: 'in', 'data-kop-embed-zoom': 'in' });
    const zoomOut = button({ name: 'out', 'data-kop-embed-zoom': 'out' });
    const fit = button({ name: 'fit' });
    const attrs = {};
    const figure = {
        hidden: true,
        parentNode: null,
        setAttribute: (k, v) => { attrs[k] = String(v); },
        getAttribute: (k) => (k in attrs ? attrs[k] : null),
        querySelector: (sel) => ({
            '.kop-network-embed__data': { textContent: JSON.stringify(slice) },
            'canvas': box.canvas,
            '[data-kop-embed-fit]': fit,
            '.kop-network-embed__stage': null
        })[sel] || null,
        querySelectorAll: (sel) => (sel === '[data-kop-embed-zoom]' ? [zoomIn, zoomOut] : [])
    };

    const went = [];
    box.resetOps();
    const parts = sandbox.KOPNetworkEmbed.start(figure, {
        window: sandbox,
        navigate: (url, newTab) => { went.push([url, newTab]); }
    });
    box.flushFrames();
    box.runTimers();
    box.flushFrames();
    check(!!parts, 'embed.js did not start on a figure holding a slice');
    if (!parts) return;
    check(figure.hidden === false && attrs['data-state'] === 'ready', 'the figure is still hidden after the embed started');

    box.resetOps();
    parts.renderer.draw();
    const scene = parts.focus.scene();
    const drawn = box.labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
    check(box.ops.fillText > 0 && drawn.length > 0, 'the embed painted nothing');
    const rootName = parts.store.node(pageRoot).name;
    check(drawn.indexOf(rootName) !== -1, 'the embed did not draw ' + rootName);
    /* The view is framed at a zoom the names can be read at, not shrunk
     * until everything fits, so some names sit off the stage until the
     * reader pans or presses Fit, as on the map. Those on it are named. */
    const t = parts.renderer.transform;
    const onStage = scene.nodes.filter((n) => {
        const p = parts.focus.positionOf(n);
        const x = p.x * t.k + t.x;
        const y = p.y * t.k + t.y;
        return x >= 0 && x <= parts.renderer.width && y >= 0 && y <= parts.renderer.height;
    });
    const unnamed = onStage.filter((n) => drawn.indexOf(n.name) === -1).map((n) => n.name);
    check(onStage.some((n) => n.id === pageRoot), rootName + ' is not on the stage the embed opens on');
    check(unnamed.length === 0, 'the embed drew names on the stage without their labels: ' + unnamed.slice(0, 5).join(', '),
        pageRoot + ': ' + onStage.length + ' of ' + scene.nodes.length + ' names on the opening stage, every one labelled');
    const clashes = collidingLabels(box.labelBoxes);
    check(clashes.length === 0, 'labels collide in the embed: ' + JSON.stringify(clashes[0] || null));

    /* The wheel is the page's. */
    let wheelCaught = true;
    try { box.fire('wheel', 1, 10, 10, { deltaY: 100, deltaMode: 0 }); } catch (e) { wheelCaught = false; }
    check(!wheelCaught, 'the embed caught the wheel; a reader scrolling past it would zoom it');

    /* A click on a name opens it on the full map; Ctrl-click its profile. */
    const hit = (parts.renderer.labelHits || []).find((entry) => entry.node.id !== pageRoot);
    check(!!hit, 'no drawn label to click in the embed');
    if (hit) {
        const bx = (hit.box[0] + hit.box[2]) / 2;
        const by = (hit.box[1] + hit.box[3]) / 2;
        box.fire('pointerdown', 1, bx, by);
        box.fire('pointerup', 1, bx, by);
        check(went.length === 1 && went[0][0] === 'https://kidsoverprofits.org/network-map/#open=' + encodeURIComponent(hit.node.id) && !went[0][1],
            'a click on ' + hit.node.name + ' went to ' + JSON.stringify(went[0] || null));
    }
    const own = (parts.renderer.labelHits || []).find((entry) => entry.node.id === pageRoot);
    if (own) {
        went.length = 0;
        const bx = (own.box[0] + own.box[2]) / 2;
        const by = (own.box[1] + own.box[3]) / 2;
        box.fire('pointerdown', 1, bx, by, { ctrlKey: true });
        box.fire('pointerup', 1, bx, by, { ctrlKey: true });
        check(went.length === 1 && went[0][0] === slice.urls[0][1] && went[0][1] === true,
            'Ctrl-click on ' + rootName + ' went to ' + JSON.stringify(went[0] || null) + ', not its profile in a new tab');
    }

    /* The buttons. */
    const k0 = parts.renderer.transform.k;
    listeners['in'].forEach((fn) => fn());
    listeners['in'].forEach((fn) => fn());
    const k1 = parts.renderer.transform.k;
    check(k1 > k0, 'the + button did not zoom in');
    (listeners['fit'] || []).forEach((fn) => fn());
    check(parts.renderer.transform.k < k1, 'Fit did not frame the names again after a zoom in');

    /* The history hub's preview: a starter view, with a still that shows
     * until the canvas has painted and goes when it has. */
    const viewSlice = slices['view:historical'];
    if (viewSlice) {
        const still = { hidden: false };
        const stage = { hidden: true };
        const fitButton = button({ name: 'fit2' });
        fitButton.hidden = true;
        const pAttrs = {};
        const preview = {
            hidden: false,
            parentNode: null,
            setAttribute: (k, v) => { pAttrs[k] = String(v); },
            getAttribute: (k) => (k in pAttrs ? pAttrs[k] : null),
            querySelector: (sel) => ({
                '.kop-network-embed__data': { textContent: JSON.stringify(viewSlice) },
                'canvas': box.canvas,
                '[data-kop-embed-fit]': fitButton,
                '.kop-network-embed__stage': stage,
                '.kop-network-embed__still': still
            })[sel] || null,
            querySelectorAll: () => []
        };
        box.resetOps();
        const shown = sandbox.KOPNetworkEmbed.start(preview, { window: sandbox, navigate: () => {} });
        box.flushFrames();
        box.runTimers();
        box.flushFrames();
        check(!!shown && pAttrs['data-state'] === 'ready', 'the Historical preview did not start');
        check(still.hidden === true && stage.hidden === false && fitButton.hidden === false,
            'the preview did not swap its still for the canvas (still ' + still.hidden + ', stage ' + stage.hidden + ')');
        if (shown) {
            box.resetOps();
            shown.renderer.draw();
            const names = box.labelCalls.filter((c) => c.startsWith('text:')).map((c) => c.slice(5));
            check(names.indexOf('Synanon') !== -1 && names.indexOf('The Seed') !== -1,
                'the Historical preview did not draw Synanon and The Seed',
                'the Historical preview draws ' + shown.focus.scene().nodes.length + ' names, Synanon and The Seed among them');
            check(shown.focus.chain().length === 0, 'the preview opened a trail instead of the view');
        }
        /* And a view slice that names a view the store lacks leaves the still. */
        const bad = JSON.parse(JSON.stringify(viewSlice));
        bad.view = 'no-such-view';
        still.hidden = false;
        stage.hidden = true;
        preview.querySelector = ((q) => (sel) => (sel === '.kop-network-embed__data' ? { textContent: JSON.stringify(bad) } : q(sel)))(preview.querySelector);
        check(sandbox.KOPNetworkEmbed.start(preview, { window: sandbox }) === null && still.hidden === false && stage.hidden === true,
            'a preview that could not start did not put its still back');
    }

    /* A figure with no data stays hidden and says nothing. */
    const empty = { hidden: true, setAttribute() {}, querySelector: () => null, querySelectorAll: () => [] };
    check(sandbox.KOPNetworkEmbed.start(empty, { window: sandbox }) === null && empty.hidden === true,
        'a figure without data was unhidden');
}

const started = Date.now();
try {
    run();
} catch (error) {
    failures.push('threw: ' + (error && error.stack ? error.stack : error));
}
notes.forEach((note) => console.log('  note: ' + note));
console.log('  (' + Math.round((Date.now() - started) / 1000) + ' s)');
if (!failures.length) {
    console.log('network embed tests: PASS');
    process.exit(0);
}
console.error('network embed tests: FAIL');
failures.slice(0, 40).forEach((f) => console.error('  - ' + f));
if (failures.length > 40) console.error('  ... and ' + (failures.length - 40) + ' more');
process.exit(1);
