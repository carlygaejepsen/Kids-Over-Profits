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

    /* The four statuses the filter rail offers. The build now splits the
     * board's single "closed or rebranded" into "closed" and "rebranded"
     * (scripts/build-network-graph.js, deriveRebrands); an older graph.json
     * still carrying the combined string reads as closed. Empty means nobody
     * has recorded one. This is the only place that decides the bucket. */
    function statusBucket(raw) {
        var s = String(raw == null ? '' : raw).trim().toLowerCase();
        if (s === '') return 'unknown';
        if (s.indexOf('open') === 0) return 'open';
        if (s === 'rebranded') return 'rebranded';
        if (s.indexOf('closed') === 0 || s.indexOf('rebrand') !== -1) return 'closed';
        return 'unknown';
    }

    /* The connections the map shows until the visitor turns one off: all of
     * them. The map is about relationships, so none is hidden to start with.
     * Board seats, referrals, survivors and "other" used to be a checkbox
     * away, to keep a view down to ownership and staff - but every one of
     * those 55 lines joins a person to a programme or a company, which is
     * exactly what the map exists to show, and a person drawn without one of
     * their places reads as someone who was never there. The rail still has
     * a checkbox for each. */
    var DEFAULT_CATEGORIES = ['corporate', 'leadership', 'staff', 'clinical', 'admissions', 'unknown',
        'family', 'membership', 'board', 'referral', 'survivor', 'other'];

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
                    /* "1971-2004", "from 1998"; '' where nothing records it. */
                    years: raw.years || '',
                    /* Deaths the memorial records for this name. */
                    deaths: raw.deaths || 0,
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
                    crossesRegion: !!raw.crossesRegion,
                    /* "profile" for a connection the facility or operator
                     * profile records and the board does not; '' for the
                     * board's own. */
                    provenance: raw.provenance || ''
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
         * Everything on, slider at zero. The rail's checkboxes are set from this
         * when the map starts, and the markup matches it so the first paint
         * does too.
         */
        store.defaultFilters = function () {
            var meta = store.meta || {};
            var categories = (meta.categories || []).filter(function (c) {
                return DEFAULT_CATEGORIES.indexOf(c) !== -1;
            });
            return {
                kinds: toSet(meta.kinds || []),
                categories: toSet(categories.length ? categories : (meta.categories || [])),
                statuses: toSet(['open', 'closed', 'rebranded', 'unknown']),
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
            /* Callable before the data lands, so the page can render its
             * shell and its empty stage without a guard at every call site. */
            if (!store.ready || !store.filters) {
                return { nodes: [], edges: [], nodeIds: Object.create(null), degrees: Object.create(null) };
            }

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

        /* ---------------------------------------------------------- paths -- */

        /**
         * How two names are connected: every route between them, shortest
         * first, as [{ ids: [from, ..., to], hops }].
         *
         * The trail answers this only for someone who already suspects the
         * route, a click at a time. This walks the graph instead - over the
         * filtered edge set, so a connection type the visitor has turned off
         * is not used to join anything.
         *
         * A route never passes through a trade association. Thirty-one names
         * are NATSAP members, so "both belong to NATSAP" would join half the
         * map in two steps and bury the routes that say something: a shared
         * owner, a therapist who worked at both. An association asked for as
         * one of the two ends is still found. (The build's starter views
         * follow the same rule; see connectView.)
         *
         * A route is a list of names, not of lines: two names joined by three
         * records are one step. Shortest first means by length and then as
         * found, which is stable for a given graph.
         *
         * Distance to the target is worked out once, breadth first, and the
         * search only ever steps to a name that can still reach the target in
         * the hops it has left. That is what keeps a walk out of Sequel's 88
         * connections from costing anything: without it six hops from a hub
         * is millions of dead ends. The step budget is the seatbelt behind
         * that, not the mechanism.
         */
        var PATH_MAX_HOPS = 6;
        var PATH_LIMIT = 50;
        var PATH_BUDGET = 400000;
        store.PATH_MAX_HOPS = PATH_MAX_HOPS;
        store.PATH_LIMIT = PATH_LIMIT;

        store.paths = function (fromId, toId, options) {
            options = options || {};
            var maxHops = options.maxHops || PATH_MAX_HOPS;
            var limit = options.limit || PATH_LIMIT;
            var vis = store.visible();
            if (!vis.nodeIds[fromId] || !vis.nodeIds[toId]) return [];
            if (fromId === toId) return [];

            var adjacent = Object.create(null);
            vis.edges.forEach(function (edge) {
                var a = edge.sourceId, b = edge.targetId;
                if (a === b) return;
                var listA = adjacent[a] = adjacent[a] || [];
                var listB = adjacent[b] = adjacent[b] || [];
                if (listA.indexOf(b) === -1) listA.push(b);
                if (listB.indexOf(a) === -1) listB.push(a);
            });

            /* May a route pass through this name? The two ends always may. */
            function through(id) {
                if (id === fromId || id === toId) return true;
                var node = store.nodeById[id];
                return !!node && (options.throughAssociations || node.kind !== 'association');
            }

            /* Hops from every name to the target, through allowed names only. */
            var dist = Object.create(null);
            dist[toId] = 0;
            var queue = [toId];
            while (queue.length) {
                var at = queue.shift();
                if (dist[at] >= maxHops) continue;
                (adjacent[at] || []).forEach(function (other) {
                    if (dist[other] !== undefined || !through(other)) return;
                    dist[other] = dist[at] + 1;
                    queue.push(other);
                });
            }
            if (dist[fromId] === undefined) return [];

            var found = [];
            var budget = PATH_BUDGET;
            var trail = [fromId];
            var onTrail = Object.create(null);
            onTrail[fromId] = true;

            /* Routes of exactly `length` hops, so each round adds the next
             * longest and the list comes out shortest first. */
            function walk(id, left) {
                if (found.length >= limit || budget <= 0) return;
                if (left === 0) {
                    if (id === toId) found.push({ ids: trail.slice(), hops: trail.length - 1 });
                    return;
                }
                var next = adjacent[id] || [];
                for (var i = 0; i < next.length; i++) {
                    var other = next[i];
                    if (onTrail[other] || dist[other] === undefined || dist[other] > left - 1) continue;
                    /* The target ends a route; it is never a step on the way. */
                    if (other === toId && left !== 1) continue;
                    budget--;
                    onTrail[other] = true;
                    trail.push(other);
                    walk(other, left - 1);
                    trail.pop();
                    onTrail[other] = false;
                    if (found.length >= limit || budget <= 0) return;
                }
            }

            for (var length = dist[fromId]; length <= maxHops; length++) {
                walk(fromId, length);
                if (found.length >= limit || budget <= 0) break;
                /* Only the shortest routes, when that is all that was asked. */
                if (options.shortestOnly && found.length) break;
            }
            return found;
        };

        /** The filtered lines between two names, whichever end each was drawn from. */
        store.edgesBetween = function (a, b) {
            return store.neighbours(a, true).filter(function (link) {
                return link.other.id === b;
            }).map(function (link) { return link.edge; });
        };

        /**
         * The organisations the map opens on, as nodes.
         *
         * Nine hundred names at once is a hairball whatever the layout does
         * with them, so the map starts with a handful of the networks that
         * shaped the industry and everything else stays off screen until it
         * is asked for - by clicking something already on the map, or by
         * searching for it by name.
         *
         * Which ones is a curated list in network-overrides.json, resolved
         * to ids by the build. It has to be: influence and prevalence are an
         * editorial judgement that no count reproduces. Synanon has six
         * recorded connections and belongs at the top; plenty of nodes with
         * thirty do not.
         *
         * Falls back to the best-connected organisations when the list is
         * missing, so a board export without one still opens on something
         * sensible rather than on nothing.
         */
        /* Which of the build's starter views the map opens on (2b.10).
         * "default" is the curated headline. */
        store.view = 'default';

        /** The views the build offers, [{key, label, ids}], default first. */
        store.views = function () {
            var views = (store.meta && store.meta.views) || [];
            return views.length ? views : [{ key: 'default', label: 'The largest networks', ids: (store.meta && store.meta.headline) || [] }];
        };

        /** Switch the opening organisations; false for a view that does not exist. */
        store.setView = function (key) {
            var view = store.views().filter(function (v) { return v.key === key; })[0];
            store.view = view ? key : 'default';
            /* A view joined up through a connection type the map hides by
             * default (Teen Challenge's only line out is a referral) turns
             * that type on, or its route would be on screen without its
             * lines. */
            if (view && view.show && store.filters) {
                view.show.forEach(function (category) {
                    store.toggleIn('categories', category, true);
                });
            }
            return !!view;
        };

        store.seeds = function () {
            var current = store.views().filter(function (v) { return v.key === store.view; })[0];
            var ids = current ? current.ids : ((store.meta && store.meta.headline) || []);
            var out = [];
            var seen = Object.create(null);
            ids.forEach(function (id) {
                var node = store.nodeById[id];
                if (node && !seen[id]) { seen[id] = true; out.push(node); }
            });
            if (out.length) return out;

            return store.nodes.filter(function (n) {
                return n.kind === 'parent' || n.kind === 'association';
            }).sort(function (a, b) {
                return b.degree - a.degree;
            }).slice(0, 8);
        };

        /**
         * The organisation the current view opens on opened out, or null
         * for a view that is a list of names shown side by side. The build
         * sets it on the default view when the headline is one name
         * (2d.1); focus.js treats it as a click with no trail.
         */
        store.viewRoot = function () {
            var current = store.views().filter(function (v) { return v.key === store.view; })[0];
            var id = current && current.root;
            return id && store.nodeById[id] ? id : null;
        };

        store.seedIds = function () {
            var set = Object.create(null);
            store.seeds().forEach(function (node) { set[node.id] = true; });
            return set;
        };

        /** Position in meta.chains, or -1 for "no recorded owner". */
        store.chainColourIndex = function (chain) {
            if (!chain) return -1;
            var i = store.chainIndex[chain];
            return i === undefined ? -1 : i;
        };

        return store;
    }

    var api = { create: create, statusBucket: statusBucket, DEFAULT_CATEGORIES: DEFAULT_CATEGORIES };

    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.KOPNetworkStore = api;
})(typeof self !== 'undefined' ? self : this);
