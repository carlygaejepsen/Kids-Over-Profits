/**
 * Network map: the filter rail and the legend.
 *
 * The rail is already real HTML when this runs - the template renders every
 * checkbox from graph.json's meta block - so this binds what is there to the
 * store rather than building controls. Nothing here decides what a filter
 * means; the store owns that, and these are its switches.
 *
 * The legend is here too because it is the rail's other half: it says what
 * the marks on the map mean, and it lists only what the rail has left in
 * view. Its swatches are painted by the renderer's own node painter, so a
 * swatch cannot drift from the thing it describes.
 *
 * Checkbox changes apply at once. The connections slider does not: its
 * output updates on every input event, but the filter itself waits for a
 * short pause, because a focused trail re-settles when the view changes and
 * restarting that force run sixty times a second would make the map boil
 * while the thumb is moving.
 */
(function (root) {
    'use strict';

    var SLIDER_PAUSE_MS = 140;
    /* Matches the breakpoint in css/network-map.css, where the key panel
     * narrows to fit a phone's stage. */
    var NARROW = '(max-width: 900px)';

    var STATUS_LABELS = {
        open: 'Open',
        closed: 'Closed',
        rebranded: 'Rebranded (carried on under another name)',
        unknown: 'Status unrecorded'
    };

    function create(options) {
        var store = options.store;
        var renderer = options.renderer;
        var document_ = options.document || root.document;
        /* Recompute and redraw. The chain gets first refusal inside it, so a
         * filter change that moves the ground under a focused trail is the
         * app's problem to resolve, not the rail's. */
        var apply = options.onChange || function () {};
        var sceneOf = options.scene || function () { return store.visible(); };
        var announce = options.announce || function () {};

        var filters = {};
        var sliderTimer = 0;
        var kindLabels = null;

        function byId(id) { return document_.getElementById(id); }
        function byName(name) {
            return Array.prototype.slice.call(
                document_.querySelectorAll('input[name="' + name + '"]'));
        }

        /**
         * The visitor-facing wording for a filter value, taken from the rail
         * the template rendered. PHP owns that vocabulary; reading it back
         * here means the legend and the rail cannot disagree, and adding a
         * kind to the board export needs no change in this file.
         */
        function labelsFrom(name) {
            var map = Object.create(null);
            byName(name).forEach(function (input) {
                var span = input.parentNode && input.parentNode.querySelector('span');
                map[input.value] = span ? span.textContent.trim() : input.value;
            });
            return map;
        }

        var groups = [
            { name: 'kop-network-kind', filter: 'kinds' },
            { name: 'kop-network-category', filter: 'categories' },
            { name: 'kop-network-status', filter: 'statuses' },
            { name: 'kop-network-chain', filter: 'chains' },
            { name: 'kop-network-region', filter: 'regions' }
        ];

        var natsap = byId('kop-network-natsap-only');
        var crossRegion = byId('kop-network-cross-region');
        var degree = byId('kop-network-degree');
        var degreeOut = byId('kop-network-degree-out');
        var resetButton = byId('kop-network-reset-filters');
        var legend = byId('kop-network-legend');
        var rail = byId('kop-network-rail');
        var railToggle = byId('kop-network-filters-toggle');
        var regionsToggle = document_.querySelector('.kop-network__legend-toggle');

        kindLabels = labelsFrom('kop-network-kind');
        /* The page no longer renders the kind checkboxes, so the wording
         * comes from the legend element instead, still printed by PHP. */
        if (!Object.keys(kindLabels).length && legend && legend.getAttribute('data-kind-labels')) {
            try {
                var printed = JSON.parse(legend.getAttribute('data-kind-labels'));
                Object.keys(printed).forEach(function (k) { kindLabels[k] = printed[k]; });
            } catch (e) { /* raw kind names are a fallback, not a failure */ }
        }

        /* ----------------------------------------------------- rail state -- */

        groups.forEach(function (group) {
            byName(group.name).forEach(function (input) {
                input.addEventListener('change', function () {
                    store.toggleIn(group.filter, input.value, input.checked);
                    applyNow();
                });
            });
        });

        if (natsap) {
            natsap.addEventListener('change', function () {
                store.setFilter('natsapOnly', natsap.checked);
                applyNow();
            });
        }

        if (crossRegion) {
            crossRegion.addEventListener('change', function () {
                store.setFilter('crossRegionOnly', crossRegion.checked);
                /* The surviving edges are the point of that screen, so they
                 * are drawn in orange whatever their category. */
                renderer.setCrossRegionMode(crossRegion.checked);
                applyNow();
            });
        }

        if (degree) {
            degree.addEventListener('input', function () {
                writeDegreeOutput(parseInt(degree.value, 10) || 0);
                if (sliderTimer) root.clearTimeout(sliderTimer);
                sliderTimer = root.setTimeout(function () {
                    sliderTimer = 0;
                    store.setFilter('minDegree', parseInt(degree.value, 10) || 0);
                    applyNow();
                }, SLIDER_PAUSE_MS);
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function () {
                store.resetFilters();
                renderer.setCrossRegionMode(false);
                filters.syncFromStore();
                applyNow();
                announce('Filters reset.');
            });
        }

        /* Zero means no minimum, which is a different statement from "one",
         * and "any" is how a visitor reads it. */
        function writeDegreeOutput(value) {
            if (degreeOut) degreeOut.textContent = value > 0 ? String(value) : 'any';
        }

        function applyNow() {
            apply();
            filters.renderLegend();
            reportCount();
        }

        function reportCount() {
            var scene = sceneOf();
            announce(scene.nodes.length + ' names and ' + scene.edges.length +
                ' connections shown.');
        }

        /**
         * Push the store's state back into the controls. Reset filters needs
         * it, and so will restoring a shared link in step 6.
         */
        filters.syncFromStore = function () {
            var state = store.filters;
            if (!state) return;
            groups.forEach(function (group) {
                byName(group.name).forEach(function (input) {
                    input.checked = !!state[group.filter][input.value];
                });
            });
            if (natsap) natsap.checked = !!state.natsapOnly;
            if (crossRegion) crossRegion.checked = !!state.crossRegionOnly;
            if (degree) degree.value = String(state.minDegree);
            writeDegreeOutput(state.minDegree);
        };

        /* -------------------------------------------------------- legend -- */

        /* `wide` gives the mark the room a line needs; `wrap` lets a row
         * that explains something run onto a second line rather than be cut
         * off at the panel's edge. */
        function row(label, count, wide, wrap) {
            var item = document_.createElement('li');
            item.className = wrap
                ? 'kop-network__legend-row kop-network__legend-row--wrap'
                : 'kop-network__legend-row';
            var mark = document_.createElement('canvas');
            mark.className = wide
                ? 'kop-network__legend-mark kop-network__legend-mark--line'
                : 'kop-network__legend-mark';
            mark.width = wide ? 26 : 18;
            mark.height = 18;
            mark.setAttribute('aria-hidden', 'true');
            var text = document_.createElement('span');
            text.className = 'kop-network__legend-label';
            text.textContent = label;
            item.appendChild(mark);
            item.appendChild(text);
            if (count !== undefined) {
                var tally = document_.createElement('span');
                tally.className = 'kop-network__count';
                tally.textContent = String(count);
                item.appendChild(tally);
            }
            return { item: item, mark: mark };
        }

        function section(title) {
            var heading = document_.createElement('p');
            heading.className = 'kop-network__legend-heading';
            heading.textContent = title;
            return heading;
        }

        /**
         * Only what is on screen. A legend that lists ten chains when the
         * rail has left two showing is describing a map nobody is looking at.
         *
         * The key is the board's own: a name's fill says whether the place
         * is open, and a blue name is a NATSAP member. Kinds are told by
         * shape - people in ellipses - and by the drawer; there is no
         * colour-by-kind, because a board of coloured blocks was what the
         * owner asked to have taken away.
         */
        filters.renderLegend = function () {
            if (!legend) return;
            legend.textContent = '';
            var scene = sceneOf();
            if (!scene.nodes.length) return;
            var painter = root.KOPNetworkCanvas;

            var has = function (test) { return scene.nodes.some(test); };
            var tally = function (test) { return scene.nodes.filter(test).length; };

            var keyList = document_.createElement('ul');
            keyList.className = 'kop-network__legend-list';
            legend.appendChild(section('What the names mean'));
            [
                { label: STATUS_LABELS.open, status: 'open', count: true,
                    when: function (n) { return n.status === 'open'; } },
                { label: STATUS_LABELS.closed, status: 'closed', count: true,
                    when: function (n) { return n.status === 'closed'; } },
                { label: STATUS_LABELS.rebranded, status: 'rebranded', count: true,
                    when: function (n) { return n.status === 'rebranded'; } },
                { label: STATUS_LABELS.unknown, status: 'unknown', count: true,
                    when: function (n) { return n.status === 'unknown'; } },
                { label: 'NATSAP member (name in blue)', status: 'unknown', natsap: true,
                    when: function (n) { return !!n.natsap; } },
                { label: 'Person', kind: 'person', status: 'unknown',
                    when: function (n) { return n.kind === 'person'; } },
                { label: 'Deaths recorded in the memorial', status: 'unknown', deaths: true,
                    when: function (n) { return n.deaths > 0; } }
            ].forEach(function (entry) {
                if (!has(entry.when)) return;
                var built = row(entry.label, entry.count ? tally(entry.when) : undefined);
                keyList.appendChild(built.item);
                painter.swatch(built.mark, {
                    kind: entry.kind || 'facility',
                    status: entry.status,
                    natsap: !!entry.natsap,
                    deaths: !!entry.deaths
                });
            });

            /* The "+N" pill, only when something on screen carries one. */
            if (scene.hidden && Object.keys(scene.hidden).length) {
                var pill = row('Connections not on the map yet. Click the name to bring them in.',
                    undefined, false, true);
                keyList.appendChild(pill.item);
                var pc = pill.mark.getContext && pill.mark.getContext('2d');
                if (pc) {
                    pc.fillStyle = '#000435';
                    pc.beginPath();
                    pc.arc(6, 9, 5, Math.PI / 2, Math.PI * 1.5);
                    pc.arc(12, 9, 5, Math.PI * 1.5, Math.PI / 2);
                    pc.closePath();
                    pc.fill();
                    pc.fillStyle = '#FFFFFF';
                    pc.font = '600 8px sans-serif';
                    pc.textAlign = 'center';
                    pc.textBaseline = 'middle';
                    pc.fillText('+', 9, 9.5);
                }
            }
            legend.appendChild(keyList);

            /* Whose lines. A company's lines to its own places and people
             * are drawn in the colour the board gave it, so the chains in
             * view are listed by that colour, in the board's order. */
            var chains = [];
            var chainSeen = Object.create(null);
            scene.nodes.forEach(function (node) {
                var chain = node.chain;
                if (!chain || chainSeen[chain]) return;
                chainSeen[chain] = true;
                chains.push(chain);
            });
            chains.sort(function (a, b) { return store.chainColourIndex(a) - store.chainColourIndex(b); });
            if (chains.length) {
                var chainList = document_.createElement('ul');
                chainList.className = 'kop-network__legend-list';
                legend.appendChild(section('Whose lines'));
                chains.forEach(function (chain) {
                    var built = row(chain, undefined, true);
                    chainList.appendChild(built.item);
                    painter.edgeSwatch(built.mark, { colour: chainColour(chain), width: 2, dash: null });
                });
                legend.appendChild(chainList);
            }

            /* The connections. A line on this map says what kind of
             * relationship was recorded and, for the two that would be wrong
             * read backwards, which way it ran - so the key names them. Only
             * the kinds actually on screen are listed, in their own inks:
             * the company colour is the row above's business. */
            var seen = Object.create(null);
            var order = [];
            scene.edges.forEach(function (edge) {
                var style = painter.styleFor(edge, renderer.crossRegionMode);
                var label = style.label || 'Other';
                if (seen[label]) return;
                /* Membership is the one kind drawn in an ink of its own -
                 * the board's colour for belonging to a trade group, the
                 * same for every such line - rather than in the colour of
                 * whichever company the line touches. So the key shows that
                 * ink; the company colours stay the business of the row
                 * above. */
                if (edge.category === 'membership' && !renderer.crossRegionMode && renderer.styleOf) {
                    style = renderer.styleOf(edge);
                }
                seen[label] = style;
                order.push(label);
            });
            if (!order.length) return;

            var edgeList = document_.createElement('ul');
            edgeList.className = 'kop-network__legend-list';
            legend.appendChild(section('Connections shown'));
            order.forEach(function (label) {
                var built = row(label, undefined, true);
                edgeList.appendChild(built.item);
                painter.edgeSwatch(built.mark, seen[label]);
            });
            legend.appendChild(edgeList);

            /* What a line carries. The rows above name the kinds of
             * connection; these two say how to read the marks drawn on a
             * line, which nothing else on the page explains: the arrowhead
             * on the two directed kinds, and the circle a line wears for
             * each person it stands for. Each row appears only when that
             * mark is on screen. */
            var arrowed = order.some(function (label) { return !!seen[label].arrow; });
            var carried = scene.edges.some(function (edge) {
                return painter.peopleOf(edge).length > 0;
            });
            if (arrowed || carried) {
                var marksList = document_.createElement('ul');
                marksList.className = 'kop-network__legend-list';
                legend.appendChild(section('What a line carries'));
                if (arrowed) {
                    var arrowRow = row('An arrowhead points from the owner to what it owned.',
                        undefined, true, true);
                    marksList.appendChild(arrowRow.item);
                    painter.edgeSwatch(arrowRow.mark, {
                        colour: painter.OUTLINE, width: 2, dash: null, arrow: true
                    });
                }
                if (carried) {
                    var personRow = row('A circle on a line is someone who was at both ends. Click it for the name.',
                        undefined, true, true);
                    marksList.appendChild(personRow.item);
                    painter.personSwatch(personRow.mark);
                }
                legend.appendChild(marksList);
            }
        };

        /* The board's colour for the chain, so the key matches the map. */
        function chainColour(chain) {
            if (renderer.chainColour) return renderer.chainColour(chain);
            var index = store.chainColourIndex(chain);
            if (index < 0) return root.KOPNetworkCanvas.CHAIN_NONE;
            var palette = root.KOPNetworkCanvas.CHAIN_COLOURS;
            return palette[index % palette.length];
        }

        /* ---------------------------------------------------- the rail UI -- */

        if (regionsToggle) {
            var regionsPanel = byId(regionsToggle.getAttribute('aria-controls'));
            regionsToggle.addEventListener('click', function () {
                var open = regionsToggle.getAttribute('aria-expanded') === 'true';
                regionsToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                if (regionsPanel) regionsPanel.hidden = open;
            });
        }

        /**
         * The rail is the key now: a panel folded into a corner of the stage,
         * closed at every width, with the Key button as the way in. It opens
         * over the map rather than beside it, so the stage keeps its size and
         * nothing re-lays out when it opens or closes.
         */
        var narrow = root.matchMedia ? root.matchMedia(NARROW) : null;

        /*
         * Open the first time, closed every time after.
         *
         * A key nobody opens explains nothing, and a first-time reader does
         * not know there is one; a reader who has already read it wants the
         * map, not the panel over it. So the Key opens itself once and
         * remembers that it has. A private window throws on localStorage and
         * a blocked one can come back empty, so both sides are wrapped and
         * the panel simply opens again for a reader we cannot remember -
         * which is the safe way round.
         */
        var SEEN_KEY = 'kop-network-key-seen';

        function keySeen() {
            try {
                return root.localStorage && root.localStorage.getItem(SEEN_KEY) === '1';
            } catch (e) {
                return false;
            }
        }

        function markKeySeen() {
            try {
                if (root.localStorage) root.localStorage.setItem(SEEN_KEY, '1');
            } catch (e) { /* private window: it opens again next time. */ }
        }

        /* Set by start(), once the data has landed: the panel has nothing
         * to show before then. */
        var railOpen = false;

        function syncRail() {
            if (!rail) return;
            rail.hidden = !railOpen;
            if (railToggle) {
                railToggle.setAttribute('aria-expanded', railOpen ? 'true' : 'false');
            }
        }

        if (railToggle) {
            railToggle.addEventListener('click', function () {
                railOpen = !railOpen;
                markKeySeen();
                syncRail();
                if (railOpen && rail) {
                    var first = rail.querySelector('input, button');
                    if (first) first.focus();
                }
            });
        }

        /* Escape folds the key away and hands focus back to its button. */
        if (rail && railToggle) {
            rail.addEventListener('keydown', function (event) {
                if (!railOpen || (event.key !== 'Escape' && event.key !== 'Esc')) return;
                railOpen = false;
                syncRail();
                railToggle.focus();
            });
        }

        if (narrow) {
            var onBreakpoint = function () {
                /* Crossing the breakpoint resizes the panel against a new
                 * stage; close it rather than leave it covering the map. */
                railOpen = false;
                syncRail();
            };
            if (narrow.addEventListener) narrow.addEventListener('change', onBreakpoint);
            else if (narrow.addListener) narrow.addListener(onBreakpoint);
        }

        filters.syncRail = syncRail;

        /** Called once the data has landed and the first scene exists. */
        filters.start = function () {
            filters.syncFromStore();
            filters.renderLegend();
            /* Shown once counts as seen, whether or not it is ever clicked. */
            railOpen = !keySeen();
            if (railOpen) markKeySeen();
            syncRail();
        };

        return filters;
    }

    root.KOPNetworkFilters = { create: create };
})(typeof self !== 'undefined' ? self : this);
