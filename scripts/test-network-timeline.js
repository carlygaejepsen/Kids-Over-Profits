#!/usr/bin/env node
/**
 * Network map timeline tests (Phase 4.3)
 *
 *   node scripts/test-network-timeline.js
 *
 * The store's reading of years (store.yearsOf, yearState, yearRange,
 * setYear, fadedIds), the rule that the board the timeline leaves does not
 * depend on the year, the renderer's faded set, the link's year=, the list's
 * faded rows, and timeline.js against stub elements. A few seconds; the
 * module suite (test-network-modules.js) still covers everything else.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { buildSandbox } = require('./test-network-modules.js');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'network');
const failures = [];
const notes = [];
function check(condition, message, note) {
    if (!condition) failures.push(message);
    else if (note) notes.push(note);
}

function run() {
    const graph = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'graph.json'), 'utf8'));
    const layout = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'layout.json'), 'utf8'));
    const box = buildSandbox();
    const { sandbox } = box;
    box.motion.reduced = true;
    ['timeline.js', 'list.js', 'url-state.js'].forEach((file) => {
        vm.runInContext(fs.readFileSync(path.join(ROOT, 'js', 'network-map', file), 'utf8'), sandbox, { filename: file });
    });

    const store = sandbox.KOPNetworkStore.create();
    store.hydrate(graph, layout);

    /* ------------------------------------------------ the four formats -- */

    const fake = (years, kind) => ({ id: 'x' + Math.random(), years, kind: kind || 'facility' });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    check(same(store.yearsOf(fake('1971-2004')), { start: 1971, end: 2004 }), '"1971-2004" did not read as 1971 to 2004');
    check(same(store.yearsOf(fake('from 1971')), { start: 1971, end: null }), '"from 1971" did not read as open-ended');
    check(same(store.yearsOf(fake('until 2004')), { start: null, end: 2004 }), '"until 2004" did not read as open at the start');
    check(same(store.yearsOf(fake('1998')), { start: 1998, end: 1998 }), '"1998" did not read as the one year');
    check(store.yearsOf(fake('')) === null, 'no years read as some years');
    const unread = graph.nodes.filter((n) => n.years && !store.yearsOf(store.node(n.id)));
    check(unread.length === 0, 'years the store cannot read: ' + unread.slice(0, 5).map((n) => n.years).join(', '),
        graph.nodes.filter((n) => n.years).length + ' names carry years, every one readable');

    /* Both open ends are inclusive, and the edges of a range are in it. */
    check(store.yearState(fake('1971-2004'), 1971) === 'on' && store.yearState(fake('1971-2004'), 2004) === 'on' &&
        store.yearState(fake('1971-2004'), 1970) === 'off' && store.yearState(fake('1971-2004'), 2005) === 'off',
        'a closed range is not inclusive at both ends');
    check(store.yearState(fake('from 1971'), 2026) === 'on' && store.yearState(fake('from 1971'), 1960) === 'off',
        '"from 1971" is not operating to today');
    check(store.yearState(fake('until 2004'), 1900) === 'on' && store.yearState(fake('until 2004'), 2005) === 'off',
        '"until 2004" is not operating from the start of the range');
    check(store.yearState(fake(''), 1995) === 'unknown', 'a name with no years is not unknown');

    /* No person carries years; people follow their places. */
    check(graph.nodes.every((n) => n.kind !== 'person' || !n.years), 'a person carries years of their own');
    const provo = store.node('provo-canyon-school');
    check(store.yearState(provo, 1985) === 'on', 'Provo Canyon School (from 1971) is not operating in 1985');
    const person = graph.nodes.find((n) => n.kind === 'person' &&
        store.adjacency[n.id].some((l) => l.other.id === 'provo-canyon-school'));
    check(!!person && store.yearState(store.node(person.id), 1985) === 'on',
        (person ? person.name : 'nobody') + ' at Provo Canyon School is not shown in 1985');

    const range = store.yearRange();
    check(range.min % 10 === 0 && range.max === new Date().getFullYear(),
        'the range is ' + JSON.stringify(range), 'the slider runs ' + range.min + ' to ' + range.max);

    /* ------------------------------------ the board does not move with it -- */

    const whole = store.visible().nodes.length;
    store.setYear(1995);
    const in1995 = store.visible();
    const ids1995 = in1995.nodes.map((n) => n.id).sort().join(',');
    check(in1995.nodes.length < whole, 'the timeline left nothing out');
    check(in1995.nodes.every((n) => store.yearState(n, 1995) !== 'unknown'),
        'the timeline kept a name with no known years that nobody opened');
    const rev = store.revision();
    store.setYear(1960);
    check(store.revision() === rev, 'a new year changed the board, not just the fading');
    check(store.visible().nodes.map((n) => n.id).sort().join(',') === ids1995,
        'the names on the board depend on the year; the board would be laid out again at every step');
    const faded = store.fadedIds();
    check(Object.keys(faded).every((id) => store.yearState(store.node(id), 1960) === 'off') && faded['synanon'] === undefined,
        'the faded set holds a name that was operating or has no years');
    const counts = store.yearCounts(1960);
    notes.push('1960: ' + counts.on + ' of ' + counts.dated + ' dated places operating, ' + counts.unknown + ' left out; board of ' +
        in1995.nodes.length + ' names of ' + whole);
    store.setYear(3000);
    check(store.year === range.max, 'a year past the range was not brought to its end');
    store.setYear(null);
    check(store.visible().nodes.length === whole, 'switching the timeline off did not bring the whole board back');

    /* What the reader opened stays whatever its years. */
    store.yearKeep = () => ['universal-health-services'];
    store.setYear(1995);
    check(store.visible().nodeIds['universal-health-services'], 'the name the reader opened was left out for having no years');
    store.yearKeep = null;
    store.setYear(null);

    /* ------------------------------------------------------- the link -- */

    const U = sandbox.KOPNetworkUrlState;
    check(U.parse('#open=provo-canyon-school&year=1995').year === 1995, 'year= was not read from the link');
    check(U.parse('#year=95').year === null, 'a year that is not four digits was read');
    check(U.format(['provo-canyon-school'], 'focus', null, false, null, false, 1995) === '#open=provo-canyon-school&year=1995',
        'year= was not written to the link');
    check(U.format([], 'focus', null, false, null, false, null) === '', 'a plain link grew a year');

    /* ------------------------------------------ timeline.js on the page -- */

    const renderer = sandbox.KOPNetworkCanvas.create(box.canvas);
    const viewport = sandbox.KOPNetworkViewport.create({ canvas: box.canvas, renderer });
    const focus = sandbox.KOPNetworkFocus.create({ store, renderer, viewport });
    renderer.resize();
    focus.start();
    box.flushFrames();
    const stub = (props) => Object.assign({
        hidden: true, value: '', attrs: {}, listeners: {},
        setAttribute(k, v) { this.attrs[k] = String(v); },
        addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
        fire(type, extra) { (this.listeners[type] || []).forEach((fn) => fn(Object.assign({ preventDefault() {}, stopPropagation() {} }, extra))); },
        focus() {}
    }, props || {});
    const els = { toggle: stub(), panel: stub(), range: stub(), output: stub(), note: stub() };
    let refreshes = 0;
    let redraws = 0;
    let changes = 0;
    const said = [];
    const tl = sandbox.KOPNetworkTimeline.create({
        store, renderer, focus, window: sandbox, elements: els,
        refresh: () => { refreshes++; },
        redraw: () => { redraws++; },
        onChange: () => { changes++; },
        announce: (t) => said.push(t)
    });
    check(!!tl && !tl.isOpen(), 'the timeline did not start closed');
    els.toggle.fire('click');
    check(tl.isOpen() && els.panel.hidden === false && els.toggle.attrs['aria-pressed'] === 'true',
        'the Timeline button did not open the slider');
    check(els.range.min === String(range.min) && els.range.max === String(range.max), 'the slider was not given the range');
    check(store.year === range.max && refreshes === 1, 'the timeline did not open on this year with one refresh');
    check(/Kept as opened, years unknown: Universal Health Services/.test(els.note.textContent),
        'the note does not say Universal Health Services is kept though undated: ' + els.note.textContent);
    check(!/Universal Health Services, Universal Health Services/.test(els.note.textContent),
        'the note names Universal Health Services twice (the view names it and roots on it)');
    els.range.value = '1985';
    els.range.fire('input');
    check(store.year === 1985 && els.output.textContent === '1985', 'the slider did not set the year');
    check(refreshes === 1 && redraws === 1, 'a new year laid the board out again instead of redrawing it');
    check(renderer.faded && Object.keys(renderer.faded).length === Object.keys(store.fadedIds()).length,
        'the renderer was not given the year\'s faded names');
    els.range.fire('keydown', { key: 'PageDown' });
    check(store.year === 1975, 'Page Down did not step back a decade');
    check(tl.year() === 1975, 'the link would not carry the year');
    box.runTimers();
    check(said.some((t) => /^1975\. /.test(t)), 'the year was not read out once the slider rested');
    els.range.fire('keydown', { key: 'Escape' });
    check(!tl.isOpen() && els.panel.hidden && store.year === null && renderer.faded === null,
        'Escape did not close the timeline and bring the whole board back');
    tl.setYear(1990);
    check(tl.isOpen() && store.year === 1990, 'a link with year= did not open the timeline on that year');

    /* A drawn frame with names faded paints them, and the lit ones at full ink. */
    box.resetOps();
    renderer.draw();
    check(box.ops.fillText > 0, 'nothing drew with the timeline on');

    /* The list says which names were not operating. */
    const scene = focus.scene();
    const rows = sandbox.KOPNetworkList.rows(store, scene, {});
    const fadedRows = rows.names.filter((r) => r.faded);
    check(fadedRows.every((r) => /not operating in 1990/.test(r.shownAs)),
        'a faded list row does not say it was not operating');
    check(rows.names.filter((r) => !r.faded).every((r) => !/not operating/.test(r.shownAs)),
        'an operating list row says it was not operating');
    notes.push('opening board in 1990: ' + scene.nodes.length + ' names, ' + fadedRows.length + ' faded in the list');
    tl.setOpen(false);
}

try {
    run();
} catch (error) {
    failures.push('threw: ' + (error && error.stack ? error.stack : error));
}
notes.forEach((n) => console.log('  note: ' + n));
if (!failures.length) {
    console.log('network timeline tests: PASS');
    process.exit(0);
}
console.error('network timeline tests: FAIL');
failures.forEach((f) => console.error('  - ' + f));
process.exit(1);
