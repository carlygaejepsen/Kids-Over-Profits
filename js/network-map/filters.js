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
    /* Matches the breakpoint in css/network-map.css, where the rail stops
     * being a column and becomes a sheet over the map. */
    var NARROW = '(max-width: 900px)';

    var STATUS_LABELS = {
        open: 'Open',
        closed: 'Closed or rebranded',
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
        var colourMode = byId('kop-network-colour-mode');
        var rail = byId('kop-network-rail');
        var railToggle = byId('kop-network-filters-toggle');
        var regionsToggle = document_.querySelector('.kop-network__legend-toggle');

        kindLabels = labelsFrom('kop-network-kind');

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

        function row(label, count) {
            var item = document_.createElement('li');
            item.className = 'kop-network__legend-row';
            var mark = document_.createElement('canvas');
            mark.className = 'kop-network__legend-mark';
            mark.width = 18;
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
         */
        filters.renderLegend = function () {
            if (!legend) return;
            legend.textContent = '';
            var scene = sceneOf();
            if (!scene.nodes.length) return;

            var byChain = renderer.colourMode === 'chain';
            var list = document_.createElement('ul');
            list.className = 'kop-network__legend-list';

            var counts = Object.create(null);
            var order = [];
            scene.nodes.forEach(function (node) {
                var key = byChain ? (node.chain || '') : node.kind;
                if (counts[key] === undefined) { counts[key] = 0; order.push(key); }
                counts[key]++;
            });

            /* Board order for chains so the colours run in the same sequence
             * as the rail; most-common-first for kinds, which has no order of
             * its own to honour. */
            if (byChain) {
                order.sort(function (a, b) {
                    if (a === '') return 1;
                    if (b === '') return -1;
                    return store.chainColourIndex(a) - store.chainColourIndex(b);
                });
            } else {
                order.sort(function (a, b) { return counts[b] - counts[a]; });
            }

            legend.appendChild(section(byChain ? 'Who owns it' : 'What it is'));
            order.forEach(function (key) {
                var label = byChain
                    ? (key || 'No recorded owner')
                    : (kindLabels[key] || key);
                var built = row(label, counts[key]);
                list.appendChild(built.item);
                root.KOPNetworkCanvas.swatch(built.mark, {
                    kind: byChain ? 'person' : key,
                    status: 'open',
                    fill: byChain ? chainColour(key) : renderer.colourFor({ kind: key, chain: '' }),
                    byKind: !byChain
                });
            });
            legend.appendChild(list);

            /* The marks that are not colour. Nothing on this map is encoded by
             * colour alone, and this is where that is spelled out. */
            var keyList = document_.createElement('ul');
            keyList.className = 'kop-network__legend-list';
            legend.appendChild(section('How to read it'));
            [
                { label: STATUS_LABELS.open, status: 'open' },
                { label: STATUS_LABELS.closed, status: 'closed' },
                { label: STATUS_LABELS.unknown, status: 'unknown' },
                { label: 'NATSAP member', status: 'open', natsap: true }
            ].forEach(function (entry) {
                var built = row(entry.label);
                keyList.appendChild(built.item);
                root.KOPNetworkCanvas.swatch(built.mark, {
                    kind: 'facility',
                    status: entry.status,
                    natsap: !!entry.natsap,
                    fill: byChain ? root.KOPNetworkCanvas.CHAIN_NONE : root.KOPNetworkCanvas.KIND_COLOURS.facility,
                    byKind: false
                });
            });
            legend.appendChild(keyList);
        };

        function chainColour(chain) {
            var index = store.chainColourIndex(chain);
            if (index < 0) return root.KOPNetworkCanvas.CHAIN_NONE;
            var palette = root.KOPNetworkCanvas.CHAIN_COLOURS;
            return palette[index % palette.length];
        }

        if (colourMode) {
            colourMode.addEventListener('change', function () {
                renderer.setColourMode(colourMode.value);
                filters.renderLegend();
                options.redraw && options.redraw();
            });
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
         * Below the breakpoint the rail is a sheet over the map, so it has to
         * start closed or it covers the thing it filters. Above it the rail is
         * a column and is always there. Pulled forward from step 7 because
         * without it the narrow layout ships unusable.
         */
        var narrow = root.matchMedia ? root.matchMedia(NARROW) : null;
        var railOpen = false;

        function syncRail() {
            if (!rail) return;
            var isNarrow = narrow ? narrow.matches : false;
            rail.hidden = isNarrow && !railOpen;
            if (railToggle) {
                railToggle.setAttribute('aria-expanded', (isNarrow && railOpen) ? 'true' : 'false');
            }
        }

        if (railToggle) {
            railToggle.addEventListener('click', function () {
                railOpen = !railOpen;
                syncRail();
                if (railOpen && rail) {
                    var first = rail.querySelector('input, button');
                    if (first) first.focus();
                }
            });
        }

        if (narrow) {
            var onBreakpoint = function () {
                /* Widening the window should not leave the rail shut. */
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
            syncRail();
        };

        return filters;
    }

    root.KOPNetworkFilters = { create: create };
})(typeof self !== 'undefined' ? self : this);
