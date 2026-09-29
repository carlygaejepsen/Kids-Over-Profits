/**
 * Network map: the timeline (Phase 4.3).
 *
 * A lens on whatever board is showing, not a board of its own. Switched on
 * from the toolbar, a year slider sits at the foot of the stage, and the
 * board shows how it stood that year:
 *
 *   - a place or company operating that year (start <= year <= end, open
 *     ends inclusive) is drawn as always;
 *   - one with known years that was not operating is drawn faded;
 *   - one with no known years is left out, and the note under the slider
 *     says how many (the owner's call, 2026-09-29);
 *   - a person has no years of their own and follows their places: drawn
 *     when one was operating, faded when their dated places were not,
 *     left out when none of their places has known years;
 *   - what the reader asked for (the trail, the starter view's names) stays
 *     whatever its years, so opening a name never empties the board.
 *
 * What is left out does not depend on the year, so the board is laid out
 * once when the timeline opens and the slider only changes the fading.
 * The store decides each name's state (store.yearState) and leaves out the
 * unknowns; the renderer draws the faded set under any hover
 * (renderer.setFaded). The year rides in the link as year=1995.
 *
 * Arrow keys step a year, Page Up and Page Down a decade, Escape closes.
 */
(function (root) {
    'use strict';

    /* The note is read out once the slider rests, not on every step. */
    var ANNOUNCE_MS = 400;

    function create(options) {
        var store = options.store;
        var renderer = options.renderer;
        var focus = options.focus;
        var el = options.elements || {};
        var refresh = options.refresh || function () {};
        /* A new year only fades and unfades; the board stays laid out. */
        var redraw = options.redraw || refresh;
        var onChange = options.onChange || function () {};
        var announce = options.announce || function () {};
        var win = options.window || root;
        if (!store || !renderer || !el.panel || !el.range) return null;

        var open = false;
        var timer = 0;
        /* Where the slider was when the timeline was last closed, so turning
         * it back on returns to the same year. */
        var lastYear = null;

        /* The reader's own names, kept through the timeline (store.yearKeep). */
        store.yearKeep = function () {
            var ids = focus ? focus.chain() : [];
            if (!ids.length) {
                ids = store.seeds().map(function (node) { return node.id; });
                var viewRoot = store.viewRoot();
                if (viewRoot && ids.indexOf(viewRoot) === -1) ids.push(viewRoot);
            }
            return ids;
        };

        function setRange() {
            var range = store.yearRange();
            el.range.min = String(range.min);
            el.range.max = String(range.max);
            el.range.step = '1';
            return range;
        }

        function note(year) {
            var counts = store.yearCounts(year);
            var kept = store.yearKeep().filter(function (id) {
                var node = store.node(id);
                return node && store.yearState(node, year) === 'unknown';
            }).map(function (id) { return store.node(id).name; });
            var text = counts.on + ' of ' + counts.dated + ' dated places operating. ' +
                counts.unknown + ' with no known years left out.';
            if (kept.length) {
                text += ' Kept as opened, years unknown: ' + kept.slice(0, 2).join(', ') +
                    (kept.length > 2 ? ' and ' + (kept.length - 2) + ' more' : '') + '.';
            }
            return text;
        }

        function paint() {
            renderer.setFaded(open ? store.fadedIds() : null);
            refresh();
        }

        function showYear(year, quiet, first) {
            store.setYear(year);
            var shown = store.year;
            el.range.value = String(shown);
            if (el.output) el.output.textContent = String(shown);
            el.range.setAttribute('aria-valuetext', String(shown));
            if (first) {
                paint();
            } else {
                renderer.setFaded(store.fadedIds());
                redraw();
            }
            if (el.note) el.note.textContent = note(shown);
            if (!quiet) {
                if (timer) win.clearTimeout(timer);
                timer = win.setTimeout(function () {
                    timer = 0;
                    announce(store.year + '. ' + note(store.year));
                }, ANNOUNCE_MS);
            }
            onChange();
        }

        function setOpen(next, year) {
            next = !!next;
            if (next === open && (year === undefined || year === store.year)) return;
            if (next) {
                var range = setRange();
                open = true;
                el.panel.hidden = false;
                if (el.toggle) el.toggle.setAttribute('aria-pressed', 'true');
                var start = year !== undefined && year !== null ? year : (lastYear !== null ? lastYear : range.max);
                showYear(start, true, true);
                announce('Timeline on, ' + store.year + '. ' + note(store.year));
            } else {
                lastYear = store.year;
                open = false;
                el.panel.hidden = true;
                if (el.toggle) el.toggle.setAttribute('aria-pressed', 'false');
                store.setYear(null);
                paint();
                announce('Timeline off. The whole board again.');
                onChange();
            }
        }

        el.range.addEventListener('input', function () {
            showYear(Number(el.range.value));
        });
        el.range.addEventListener('keydown', function (event) {
            var key = event.key;
            if (key === 'PageUp' || key === 'PageDown') {
                event.preventDefault();
                showYear((store.year || 0) + (key === 'PageUp' ? 10 : -10));
            } else if (key === 'Escape' || key === 'Esc') {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                if (el.toggle && el.toggle.focus) el.toggle.focus();
            }
        });
        if (el.toggle) {
            el.toggle.addEventListener('click', function () {
                setOpen(!open);
                if (open && el.range.focus) el.range.focus();
            });
        }

        return {
            isOpen: function () { return open; },
            year: function () { return open ? store.year : null; },
            setOpen: setOpen,
            setYear: function (year) {
                if (open) showYear(year, true);
                else setOpen(true, year);
            },
            /* The trail moved: the kept names and the note follow it. */
            sync: function () {
                if (!open) return;
                renderer.setFaded(store.fadedIds());
                if (el.note) el.note.textContent = note(store.year);
            },
            note: function () { return open ? note(store.year) : ''; }
        };
    }

    root.KOPNetworkTimeline = { create: create };
})(typeof self !== 'undefined' ? self : this);
