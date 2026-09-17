/**
 * Network map: data store.
 *
 * Owns the two JSON files, the indexes built from them, and the filter
 * state. Everything else on the page reads the graph through here.
 *
 * The one calculation that matters is the visible subgraph. A node is
 * visible when it passes the kind, status, NATSAP, owner and board-group
 * filters and its degree within the surviving edge set meets the minimum-
 * connections slider; an edge is visible when its category is checked and
 * both of its endpoints survived. Degree is counted once, before nodes are
 * dropped, rather than cascading: dropping a node can only lower its
 * neighbours' degrees, and a cascade would quietly empty the map two or
 * three rounds after the slider moved.
 *
 * The result is cached against a revision counter, so filters can be set in
 * a batch and the recomputation still happens once, when someone asks for it.
 *
 * DOM-free on purpose: scripts/test-network-modules.js requires this file
 * directly in Node.
 */
(function (root) {
    'use strict';

    /* The three statuses the filter rail offers. The board records status as
     * free text with an empty string for "nobody has recorded one", so this
     * is the only place that decides which bucket a row falls in. */
    function statusBucket(raw) {
        var s = String(raw == null ? '' : raw).trim().toLowerCase();
        if (s === '') return 'unknown';
        if (s.indexOf('open') === 0) return 'open';
        if (s.indexOf('closed') === 0 || s.indexOf('rebrand') !== -1) return 'closed';
        return 'unknown';
    }

    function toSet(list) {
        var set = Object.create(null);
        (list || []).forEach(function (v) { set[v] = true; });
        return set;
    }

    function create() {
        var store = {
            meta: null,
            nodes: [],
            edges: [],
            nodeById: Object.create(null),
            edgeById: Object.create(null),
            adjacency: Object.create(null),
            chainIndex: Object.create(null),
            extent: null,
            filters: null,
            ready: false,
            /* Nodes the graph carries but the layout has no position for.
             * Only possible when the two files were built from different
             * board exports; the build test catches that, this is the
             * browser's own seatbelt. */
            unplaced: []
        };

        var revision = 0;
        var cache = null;
        var cacheRevision = -1;

        /* ---------------------------------------------------------- load -- */

        function fetchJson(url) {
            return fetch(url, { credentials: 'same-origin' }).then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status + ' for ' + url);
                return res.json();
            });
        }

        store.load = function (config) {
            if (!config || !config.graphUrl || !config.layoutUrl) {
                return Promise.reject(new Error('Network map config is missing its data URLs.'));
            }
            return Promise.all([
                fetchJson(config.graphUrl),
                fetchJson(config.layoutUrl)
            ]).then(function (both) {
                store.hydrate(both[0], both[1]);
                return store;
            });
        };

        /**
         * Merge graph and layout into one array of node objects and build the
         * indexes. Separate from load() so the tests can hand it fixtures.
         */
        store.hydrate = function (graph, layout) {
            var positions = (layout && layout.positions) || {};

            store.meta = (graph && graph.meta) || {};
            store.nodes = [];
            store.edges = [];
            store.unplaced = [];
            store.nodeById = Object.create(null);
            store.edgeById = Object.create(null);
            store.adjacency = Object.create(null);

            (graph.nodes || []).forEach(function (raw) {
                var pos = positions[raw.id];
                if (!pos) {
                    store.unplaced.push(raw.id);
                    return;
                }
                var node = {
                    id: raw.id,
                    name: raw.name,
                    aliases: raw.aliases || [],
                    kind: raw.kind,
                    status: statusBucket(raw.status),
                    statusRaw: raw.status || '',
                    chain: raw.chain || '',
                    regions: raw.regions || [],
                    natsap: !!raw.natsap,
                    importance: raw.importance || 0,
                    degree: raw.degree || 0,
                    degreeByCategory: raw.degreeByCategory || {},
                    facilityId: raw.facilityId || null,
                    uniqueName: raw.uniqueName || null,
                    dates: raw.dates || '',
                    isolated: !!raw.isolated,
                    /* Settled position. Mutable: dragging a node moves it and
                     * pins it for the session. x0/y0 is the way back. */
                    x: pos.x,
                    y: pos.y,
                    x0: pos.x,
                    y0: pos.y,
                    r: pos.r,
                    pinned: false
                };
                store.nodes.push(node);
                store.nodeById[node.id] = node;
                store.adjacency[node.id] = [];
            });

            (graph.edges || []).forEach(function (raw) {
                var source = store.nodeById[raw.source];
                var target = store.nodeById[raw.target];
                if (!source || !target) return;
                var edge = {
                    id: raw.id,
                    source: source,
                    target: target,
                    sourceId: raw.source,
                    targetId: raw.target,
                    category: raw.category,
                    roles: raw.roles || [],
                    raw: raw.raw || '',
                    direction: raw.direction || 'none',
                    crossesChain: !!raw.crossesChain,
                    crossesRegion: !!raw.crossesRegion
                };
                store.edges.push(edge);
                store.edgeById[edge.id] = edge;
                store.adjacency[source.id].push({ edge: edge, other: target, outgoing: true });
                store.adjacency[target.id].push({ edge: edge, other: source, outgoing: false });
            });

            /* Chain to colour index, taken from the build's sorted list so a
             * chain keeps its colour however the view is filtered. */
            store.chainIndex = Object.create(null);
            (store.meta.chains || []).forEach(function (chain, i) {
                store.chainIndex[chain] = i;
            });

            store.extent = (layout && layout.meta && layout.meta.extent) || computeExtent();
            store.filters = store.defaultFilters();
            store.ready = true;
            revision++;
            return store;
        };

        function computeExtent() {
            if (!store.nodes.length) return { minX: -1, minY: -1, maxX: 1, maxY: 1 };
            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            store.nodes.forEach(function (n) {
                if (n.x - n.r < minX) minX = n.x - n.r;
                if (n.y - n.r < minY) minY = n.y - n.r;
                if (n.x + n.r > maxX) maxX = n.x + n.r;
                if (n.y + n.r > maxY) maxY = n.y + n.r;
            });
            return { minX: minX, minY: minY, maxX: maxX, maxY: maxY };
        }
        store.computeExtent = computeExtent;

        /* ------------------------------------------------------- filters -- */

        /**
         * Everything on, slider at one. The rail renders its checkboxes
         * checked, so this has to agree with the markup or the first
         * interaction would appear to change nothing.
         */
        store.defaultFilters = function () {
            var meta = store.meta || {};
            return {
                kinds: toSet(meta.kinds || []),
                categories: toSet(meta.categories || []),
                statuses: toSet(['open', 'closed', 'unknown']),
                natsapOnly: false,
                /* '' is the "no recorded owner" checkbox: 708 of 907 nodes. */
                chains: toSet((meta.chains || []).concat([''])),
                regions: toSet(meta.regions || []),
                /* Zero, not one. A floor of one connection would hide every
                 * unconnected name by default, and the three the board
                 * records are not obscure: Judge Rotenberg Educational
                 * Center, IECA and Accelerated Christian Education. Nobody
                 * has documented a connection for them yet, which is a gap
                 * in the research, not a reason to leave them off the map.
                 * The rail renders the slider at 0 to match. */
                minDegree: 0,
                crossRegionOnly: false,
                /* Kept visible whatever the slider says, so selecting a node
                 * from search cannot select something the map then hides. */
                keepVisible: null
            };
        };

        store.resetFilters = function () {
            store.filters = store.defaultFilters();
            revision++;
        };

        /** Mark the filters changed. Call after mutating store.filters by hand. */
        store.touch = function () {
            revision++;
        };

        store.setFilter = function (name, value) {
            store.filters[name] = value;
            revision++;
        };

        store.toggleIn = function (name, value, on) {
            var set = store.filters[name];
            if (on) set[value] = true;
            else delete set[value];
            revision++;
        };

        store.revision = function () {
            return revision;
        };

        /* ------------------------------------------- the visible subgraph -- */

        function nodePasses(node, f) {
            if (!f.kinds[node.kind]) return false;
            if (!f.statuses[node.status]) return false;
            if (f.natsapOnly && !node.natsap) return false;
            if (!f.chains[node.chain]) return false;
            /* A node sits in one board frame in practice, but the format
             * allows several; any checked frame keeps it. A node with no
             * frame at all is not hidden by this filter. */
            var regions = node.regions;
            if (!regions.length) return true;
            for (var i = 0; i < regions.length; i++) {
                if (f.regions[regions[i]]) return true;
            }
            return false;
        }

        /**
         * { nodes, edges, nodeIds, degrees }. Recomputed only when the filter
         * revision has moved.
         */
        store.visible = function () {
            if (cache && cacheRevision === revision) return cache;

            var f = store.filters;
            var eligible = [];
            var eligibleIds = Object.create(null);
            var i;

            for (i = 0; i < store.nodes.length; i++) {
                var node = store.nodes[i];
                if (nodePasses(node, f)) {
                    eligible.push(node);
                    eligibleIds[node.id] = true;
                }
            }

            /* Candidate edges: category checked, both ends eligible, and when
             * the staff-migration toggle is on, only edges that leave their
             * board group. crossesRegion is used rather than crossesChain
             * because the chain column is sparse. */
            var candidates = [];
            var degrees = Object.create(null);
            for (i = 0; i < store.edges.length; i++) {
                var edge = store.edges[i];
                if (!f.categories[edge.category]) continue;
                if (f.crossRegionOnly && !edge.crossesRegion) continue;
                if (!eligibleIds[edge.sourceId] || !eligibleIds[edge.targetId]) continue;
                candidates.push(edge);
                degrees[edge.sourceId] = (degrees[edge.sourceId] || 0) + 1;
                degrees[edge.targetId] = (degrees[edge.targetId] || 0) + 1;
            }

            /* A node earns its place by having a visible connection, so the
             * working floor is never below one however low the slider goes.
             * What the slider's zero adds is the names that have no recorded
             * connection at all: three of them today, and not obscure ones.
             *
             * The distinction that matters is between a node the data has
             * nothing for and a node whose connections were just filtered
             * away. Only the first belongs on the map at zero. Without it,
             * turning on the cross-group view would answer "which people
             * moved between board groups" with 451 names and 456 unrelated
             * dots. */
            var min = f.minDegree || 0;
            var floor = min > 1 ? min : 1;
            var keepUnconnected = min < 1;
            var keep = f.keepVisible;
            var nodes = [];
            var nodeIds = Object.create(null);
            for (i = 0; i < eligible.length; i++) {
                var node2 = eligible[i];
                var seen = degrees[node2.id] || 0;
                if (seen >= floor || (keepUnconnected && node2.degree === 0) || node2.id === keep) {
                    nodes.push(node2);
                    nodeIds[node2.id] = true;
                }
            }

            var edges = [];
            for (i = 0; i < candidates.length; i++) {
                if (nodeIds[candidates[i].sourceId] && nodeIds[candidates[i].targetId]) {
                    edges.push(candidates[i]);
                }
            }

            cache = { nodes: nodes, edges: edges, nodeIds: nodeIds, degrees: degrees };
            cacheRevision = revision;
            return cache;
        };

        /* --------------------------------------------------------- reads -- */

        store.node = function (id) {
            return store.nodeById[id] || null;
        };

        /**
         * Everyone this node touches. visibleOnly restricts it to the current
         * filters, which is what the canvas and the chain want; the drawer
         * shows the unfiltered truth.
         */
        store.neighbours = function (id, visibleOnly) {
            var list = store.adjacency[id] || [];
            if (!visibleOnly) return list.slice();
            var vis = store.visible();
            var live = Object.create(null);
            vis.edges.forEach(function (e) { live[e.id] = true; });
            return list.filter(function (link) {
                return live[link.edge.id] && vis.nodeIds[link.other.id];
            });
        };

        /** Position in meta.chains, or -1 for "no recorded owner". */
        store.chainColourIndex = function (chain) {
            if (!chain) return -1;
            var i = store.chainIndex[chain];
            return i === undefined ? -1 : i;
        };

        return store;
    }

    var api = { create: create, statusBucket: statusBucket };

    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.KOPNetworkStore = api;
})(typeof self !== 'undefined' ? self : this);
