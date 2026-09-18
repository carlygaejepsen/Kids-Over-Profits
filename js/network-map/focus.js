/**
 * Network map: hover preview and the focus chain.
 *
 * The map has two states. On the whole map, hovering a node previews its
 * neighbourhood: the node and everything it connects to stay lit, the rest
 * drops to fifteen percent, and the neighbours pull in toward the hovered
 * node so the cluster visibly gathers. Clicking commits: the rest of the map
 * goes away entirely, the neighbourhood re-settles to fill the viewport, and
 * the node is pushed onto a chain.
 *
 * Clicking a neighbour from there extends the chain rather than replacing
 * it, so the view becomes "Lichfield, then Cross Creek, then whoever ran
 * it" - the trail the researcher actually walked. Everything on the chain
 * stays on screen with its own neighbours; the chain is the query.
 *
 * Two things never change here: the stored layout and the store's filters.
 * The gather is a display offset, and the focused view has its own set of
 * coordinates, so leaving a focus puts the map back exactly as it was.
 *
 * The re-settle is the one place the vendor bundle earns its passage to the
 * browser. A focused neighbourhood is a few dozen nodes, so its layout is
 * computed in one synchronous run and then animated to, rather than left
 * running as a live simulation: the destination is known before anything
 * moves, which is what lets the viewport tween to its new frame at the same
 * time instead of chasing a wobble.
 */
(function (root) {
    'use strict';

    /* How far along its existing line to the hovered node a neighbour
     * travels. A third is enough to read as gathering; pulling along the
     * existing direction rather than snapping onto a ring means a neighbour
     * stays roughly where the eye last saw it. */
    var GATHER = 0.33;
    /* Never closer than this, in world units, on top of the two radii, so a
     * gather cannot stack neighbours on the node they are gathering to. */
    var GATHER_CLEARANCE = 6;

    var GATHER_IN_MS = 170;
    var GATHER_OUT_MS = 130;
    /* How much room a node needs to itself while the simulation arranges
     * things: enough for its own shape, and enough for its name, which is
     * drawn centred underneath and is almost always the wider of the two.
     * Settling on radius alone packs the shapes neatly and leaves the labels
     * on top of each other.
     *
     * Nothing rescales the result afterwards. The view is fitted to whatever
     * the simulation produced, which is what keeps a six-organisation
     * opening view and a thirty-node neighbourhood both on the stage; node
     * radius is clamped on the way to the screen so a small view cannot blow
     * its shapes up into blobs.
     */
    /* A label's width, in world units. Names are drawn centred under their
     * node at about 11.5px, a little over six pixels a character. */
    var LABEL_CHAR_WIDTH = 6.4;
    var LABEL_HALF_PER_CHAR = LABEL_CHAR_WIDTH / 2;
    /* Clear space between one column's names and the next. */
    var COLUMN_GUTTER = 26;
    /* The bands, top to bottom. */
    var TIER_COMPANY = 0;
    var TIER_COMMAND = 1;
    var TIER_PROGRAMME = 2;
    var TIER_STAFF = 3;
    var TIER_OTHER = 4;

    /* Clear space between one row's names and the next. Tight, because
     * rows are what a tall map spends its height on and every row that does
     * not fit is a name that does not appear. */
    var ROW_GUTTER = 16;
    /* The most spare width a name is given when a row is spread to fill
     * the stage, in world units. */
    var SPREAD_MAX = 220;

    /* What a cell costs when it sits closer to the middle of the board than
     * the node that revealed it. Large enough to be a rule rather than a
     * preference, finite so that a crowded board still places everything. */
    var INWARD_PENALTY = 100000;
    /* Height of the label that hangs under a node. */
    var LABEL_ROOM = 18;

    /**
     * How much room a node needs to itself: enough for its own shape, and
     * enough for its name, which is drawn centred underneath and is almost
     * always the wider of the two. Settling on radius alone packs the shapes
     * neatly and leaves the labels on top of each other.
     */
    function spaceFor(node) {
        var label = String(node.name || '').length * LABEL_HALF_PER_CHAR;
        return Math.max(node.r + 8, label);
    }

    /* Matches build-network-layout.js. A focused view is a different problem
     * from the whole board, so the numbers are not identical, but a
     * connection type that pulls tight there should pull tight here. */
    var LINK_DISTANCE = {
        corporate: 70, family: 70, board: 95, leadership: 95,
        clinical: 130, admissions: 125, referral: 125,
        staff: 130, survivor: 125, other: 125, unknown: 125
    };
    var DEFAULT_DISTANCE = 125;
    var SETTLE_TICKS = 220;

    function easeOut(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    function prefersReducedMotion() {
        return !!(root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function create(options) {
        var store = options.store;
        var renderer = options.renderer;
        var viewport = options.viewport;
        var onChange = options.onChange || function () {};
        var announce = options.announce || function () {};

        /* Ids the visitor has clicked through, oldest first. */
        var chain = [];
        /* Focused coordinates, id to a mutable {x, y}. Null on the whole
         * map, where the settled layout is what is on screen. */
        var layout = null;
        /* Display-only gather offsets, id to [dx, dy]. */
        var offsets = null;
        var gather = null;
        var gatherFrame = 0;
        var settleFrame = 0;
        var hoverId = null;

        /* The cell geometry of the current layout, or null before one has
         * been laid out. */
        var grid = null;

        var focus = { };
        focus.grid = function () { return grid; };

        /* ----------------------------------------------------- positions -- */

        function positionOf(node) {
            if (layout) {
                var p = layout[node.id];
                if (p) return p;
            }
            return node;
        }
        focus.positionOf = positionOf;
        focus.offsets = function () { return offsets; };

        renderer.setPositionSource(positionOf);
        viewport.setPositionSource(positionOf);
        viewport.setOffsetSource(focus.offsets);

        focus.chain = function () { return chain.slice(); };

        /* --------------------------------------------------- neighbourhood -- */

        /** The hovered node plus everyone it connects to, within the filters. */
        function neighbourhoodOf(id) {
            var near = Object.create(null);
            var nearEdges = Object.create(null);
            near[id] = true;
            store.neighbours(id, true).forEach(function (link) {
                near[link.other.id] = true;
                nearEdges[link.edge.id] = true;
            });
            return { near: near, nearEdges: nearEdges };
        }

        /**
         * Every node on screen: the organisations the map opens on, plus
         * everything the visitor has opened and everyone those touch.
         *
         * Nothing else is drawn. A name the visitor has not asked for is not
         * on the map at all - not faint, not small, absent - so the first
         * glance is a handful of networks rather than nine hundred dots, and
         * every name after that arrived because someone went looking for it.
         */
        /**
         * What the view is centred on. In focus mode, the last thing clicked:
         * the trail is a history, and clicking a person in a company's view
         * gives you that person's connections rather than laying them over
         * the company's. In expand mode, everything clicked: the trail is a
         * union, and each click adds a neighbourhood to the board. The
         * crumbs are the way back in either.
         */
        var mode = 'focus';
        function currentRoots() {
            if (!chain.length) return [];
            return mode === 'expand' ? chain.slice() : [chain[chain.length - 1]];
        }
        focus.mode = function () { return mode; };
        focus.setMode = function (next) {
            next = next === 'expand' ? 'expand' : 'focus';
            if (next === mode) return;
            mode = next;
            if (chain.length) enterFocus();
            onChange();
        };

        function visibleIds() {
            var live = store.visible().nodeIds;

            /* What the visitor actually asked for: everything opened, and
             * everyone those touch. The organisations the map opened with
             * are added at the end, because the rules below are about the
             * question being asked and not about the background. */
            var asked = Object.create(null);
            currentRoots().forEach(function (id) {
                if (!live[id]) return;
                asked[id] = true;
                store.neighbours(id, true).forEach(function (link) {
                    asked[link.other.id] = true;
                });
            });

            /* A person on their own says nothing. The fact worth having about
             * someone on this map is which programmes they turn up at - the
             * therapist who appears at four schools in a row, the director
             * whose next job is the company that bought the last one - and a
             * name sitting alone with a single line back to whatever revealed
             * it hides exactly that. So when a person surfaces, everywhere
             * they connect to surfaces with them.
             *
             * This is also the only thing that puts a second programme on
             * screen beside the first. Two facilities owned by the same
             * company are not each other's business; somebody who worked at
             * both is.
             *
             * Object.keys takes a snapshot, so this opens people out by one
             * step and stops: a person reached through another person's
             * expansion does not expand in turn. People are the cheap case to
             * do this for - median degree two, most seven - but a rule that
             * walked outwards without a stop would not stay cheap. */
            Object.keys(asked).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'person') return;
                store.neighbours(id, true).forEach(function (link) {
                    asked[link.other.id] = true;
                });
            });

            /* Whoever owned it is never left off. Ownership is the question
             * this map exists to answer, so a programme on screen without the
             * company behind it is the one gap worth closing automatically.
             *
             * Programmes only, and owners only: the edge has to run from a
             * company into the programme. Run for every name on screen and in
             * either direction, it pulled in whatever a company owned and
             * whoever owned it - opening Synanon brought CEDU's owners and
             * Leadership Dynamics' holdings, four companies nobody asked
             * about.
             *
             * One step only: the company that owned this place, not the
             * company that owned that company and so on - Provo Canyon School
             * walks up through ten organisations if you let it, which answers
             * a question nobody asked and buries the one they did. And owners
             * only, never their other holdings: a company's remaining
             * programmes are its business with them, not this facility's. */
            Object.keys(asked).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'facility') return;
                store.neighbours(id, true).forEach(function (link) {
                    if (link.other.kind !== 'parent') return;
                    if (link.edge.category !== 'corporate') return;
                    if (link.outgoing || link.edge.direction === 'renamed') return;
                    asked[link.other.id] = true;
                });
            });

            /* The organisations the map opens on belong to the opening view
             * only. Kept once something is opened, any of them a few edges
             * away rode in on the chain - Universal Health Services in
             * Synanon's view, through CEDU's owner. */
            var ids = Object.create(null);
            if (!chain.length) {
                store.seeds().forEach(function (node) {
                    if (live[node.id]) ids[node.id] = true;
                });
            }
            Object.keys(asked).forEach(function (id) {
                if (live[id]) ids[id] = true;
            });
            return ids;
        }

        /**
         * The scene to draw: those nodes, and every edge between them. That
         * is deliberately more than the opened nodes' own connections -
         * seeing that two of someone's programmes also connect to each other
         * is the point of putting them on screen together.
         */
        focus.scene = function () {
            var visible = store.visible();
            var ids = visibleIds();
            var nodes = visible.nodes.filter(function (node) { return ids[node.id]; });
            var edges = visible.edges.filter(function (edge) {
                return ids[edge.sourceId] && ids[edge.targetId];
            });

            /* Once something has been opened, the map is about that. The
             * organisations it opened with have no bearing on the question
             * being asked unless they turn out to connect to it, and left on
             * screen they are just names taking up cells in the grid with no
             * line to anything - the reader has to work out for themselves
             * that they are leftovers rather than part of the answer.
             *
             * The opening view itself is exempt: six organisations with two
             * connections between them would come down to two.
             */
            if (chain.length) {
                /* Reachable from what was clicked, along the edges on screen.
                 * "Has a line to something" was not enough: two of the
                 * opening organisations share an edge with each other and
                 * sailed through on it, sitting in a person's view with no
                 * connection to the person. What was clicked always stays,
                 * even where the filters have taken away everything around
                 * it. */
                var adjacent = Object.create(null);
                edges.forEach(function (edge) {
                    (adjacent[edge.sourceId] = adjacent[edge.sourceId] || []).push(edge.targetId);
                    (adjacent[edge.targetId] = adjacent[edge.targetId] || []).push(edge.sourceId);
                });
                var reach = Object.create(null);
                var queue = [];
                currentRoots().forEach(function (id) {
                    if (ids[id]) { reach[id] = true; queue.push(id); }
                });
                while (queue.length) {
                    var at = queue.shift();
                    (adjacent[at] || []).forEach(function (other) {
                        if (!reach[other]) { reach[other] = true; queue.push(other); }
                    });
                }
                nodes = nodes.filter(function (node) { return reach[node.id]; });
                edges = edges.filter(function (edge) { return reach[edge.sourceId] && reach[edge.targetId]; });
                ids = reach;
            }

            /* What is on screen is not everything a node touches, and an
             * owner deliberately brings only itself, so a node can sit there
             * with connections the reader cannot see. Rather than dragging
             * them in - which is what turns opening one company into a
             * screenful of its other holdings - each node reports how many
             * connections it has off screen, and the renderer marks it with
             * the count. The reader can see there is more behind a name, and
             * clicking it is what brings it.
             */
            var hidden = Object.create(null);
            nodes.forEach(function (node) {
                var off = 0;
                store.neighbours(node.id, true).forEach(function (link) {
                    if (!ids[link.other.id]) off++;
                });
                if (off) hidden[node.id] = off;
            });

            return {
                nodes: nodes, edges: edges, nodeIds: ids,
                degrees: visible.degrees, hidden: hidden
            };
        };

        /** Has the visitor opened anything, or is this still the opening view? */
        focus.isFocused = function () { return chain.length > 0; };

        /* --------------------------------------------------------- hover -- */

        focus.hover = function (node) {
            var id = node ? node.id : null;
            if (id === hoverId) return;
            hoverId = id;

            if (!id) {
                releaseGather();
                applyEmphasis();
                return;
            }

            var hood = neighbourhoodOf(id);
            gather = {
                id: id,
                near: hood.near,
                nearEdges: hood.nearEdges,
                targets: gatherTargets(id, hood.near),
                progress: gather && gather.progress ? gather.progress : 0,
                direction: 1
            };
            /* Under reduced motion the gather is skipped entirely and hover
             * is dimming alone, which loses nothing factual. */
            if (prefersReducedMotion()) {
                gather.progress = 0;
                gather.targets = null;
                offsets = null;
                applyEmphasis();
                viewport.scheduleDraw();
                return;
            }
            runGather();
        };

        /**
         * Where each neighbour would sit fully gathered, as an offset. The
         * clamp stops a neighbour of a big hub from being pulled inside it:
         * a third of a short line can be further than the two radii allow.
         */
        function gatherTargets(id, near) {
            var centre = positionOf(store.node(id));
            var hub = store.node(id);
            var targets = Object.create(null);
            Object.keys(near).forEach(function (other) {
                if (other === id) return;
                var node = store.node(other);
                if (!node) return;
                var p = positionOf(node);
                var dx = centre.x - p.x;
                var dy = centre.y - p.y;
                var distance = Math.hypot(dx, dy);
                if (!distance) return;
                var floor = hub.r + node.r + GATHER_CLEARANCE;
                var travel = Math.min(distance * GATHER, Math.max(0, distance - floor));
                if (travel <= 0) return;
                targets[other] = [dx / distance * travel, dy / distance * travel];
            });
            return targets;
        }

        function runGather() {
            if (gatherFrame) root.cancelAnimationFrame(gatherFrame);
            var start = root.performance ? root.performance.now() : Date.now();
            var from = gather.progress;
            var to = gather.direction > 0 ? 1 : 0;
            var span = gather.direction > 0 ? GATHER_IN_MS : GATHER_OUT_MS;
            var duration = Math.max(1, span * Math.abs(to - from));

            var step = function (now) {
                if (!gather) { gatherFrame = 0; return; }
                var t = Math.min(1, (now - start) / duration);
                gather.progress = from + (to - from) * easeOut(t);
                writeOffsets();
                viewport.scheduleDraw();
                if (t < 1) {
                    gatherFrame = root.requestAnimationFrame(step);
                    return;
                }
                gatherFrame = 0;
                if (to === 0) {
                    gather = null;
                    offsets = null;
                    applyEmphasis();
                    viewport.scheduleDraw();
                }
            };
            applyEmphasis();
            gatherFrame = root.requestAnimationFrame(step);
        }

        function writeOffsets() {
            if (!gather || !gather.targets) { offsets = null; return; }
            var p = gather.progress;
            if (p <= 0) { offsets = null; return; }
            var next = Object.create(null);
            var targets = gather.targets;
            Object.keys(targets).forEach(function (id) {
                next[id] = [targets[id][0] * p, targets[id][1] * p];
            });
            offsets = next;
        }

        /** Let go now, without easing. For when the view is changing anyway. */
        function dropGather() {
            if (gatherFrame) { root.cancelAnimationFrame(gatherFrame); gatherFrame = 0; }
            gather = null;
            offsets = null;
        }

        /** Ease everything back and let go, rather than snapping. */
        function releaseGather() {
            if (!gather) return;
            if (!gather.targets || prefersReducedMotion()) {
                dropGather();
                viewport.scheduleDraw();
                return;
            }
            gather.direction = -1;
            /* The lighting goes now; only the positions ease. Holding the dim
             * until the motion finished would leave the map looking busy
             * after the pointer had already left. */
            gather.near = null;
            gather.nearEdges = null;
            runGather();
        }

        function applyEmphasis() {
            renderer.setEmphasis({
                hoverId: hoverId,
                /* Hover always lights what the node touches and drops the
                 * rest back. This used to be suppressed once something had
                 * been opened, on the reasoning that the neighbourhood was
                 * already the only thing on screen - which stopped being
                 * true when the map started opening on a curated few and
                 * growing from there. */
                near: (gather && gather.near) ? gather.near : null,
                nearEdges: (gather && gather.nearEdges) ? gather.nearEdges : null,
                offsets: offsets,
                dim: 0.15
            });
        }

        /* --------------------------------------------------------- chain -- */

        focus.select = function (node) {
            if (!node) return;
            if (chain.length && chain[chain.length - 1] === node.id) return;
            /* In expand mode a node already on the trail is already on the
             * board; clicking it again adds nothing. */
            if (mode === 'expand' && chain.indexOf(node.id) !== -1) return;
            chain.push(node.id);
            enterFocus();
        };

        /**
         * Several names at once, side by side: what Enter in the search box
         * does with more than one match. Focus mode shows one root at a time,
         * so this switches to expand, where the trail is a union - and says
         * so, because the toggle the visitor set has just changed under them.
         */
        focus.openAll = function (nodes) {
            var ids = (nodes || []).filter(Boolean).map(function (n) { return n.id; });
            if (!ids.length) return;
            if (ids.length === 1) { focus.select(nodes[0]); return; }
            mode = 'expand';
            ids.forEach(function (id) {
                if (chain.indexOf(id) === -1) chain.push(id);
            });
            enterFocus();
            announce(ids.length + ' names opened together. The map is now in Expand mode.');
        };

        /**
         * Put a whole trail back at once: a shared link, or the page reloaded.
         * Unknown ids are dropped (a board export can rename a node), and an
         * empty result is the opening view. Nothing is announced beyond what
         * entering the view announces.
         */
        focus.restore = function (ids, nextMode) {
            if (nextMode === 'expand' || nextMode === 'focus') mode = nextMode;
            var live = store.visible().nodeIds;
            var seen = Object.create(null);
            chain = (ids || []).filter(function (id) {
                if (seen[id] || !store.node(id) || !live[id]) return false;
                seen[id] = true;
                return true;
            });
            if (!chain.length) {
                layout = null;
                showOpeningView();
                onChange();
                return;
            }
            enterFocus();
        };

        focus.truncateTo = function (index) {
            if (index < 0 || index >= chain.length) return;
            if (index === chain.length - 1) return;
            chain = chain.slice(0, index + 1);
            enterFocus();
        };

        focus.clear = function () {
            if (!chain.length) return;
            resetToWholeMap();
        };

        /**
         * Back to the precomputed view. Unconditional, because it is also the
         * way out of a chain the filters have emptied, where the chain has
         * already been discarded by the time we get here.
         */
        function resetToWholeMap() {
            chain = [];
            layout = null;
            hoverId = null;
            stopSettle();
            dropGather();
            /* The position source is the same function throughout; it reads
             * layout, which is now null. Re-handing it to the viewport is
             * what rebuilds the hit index over the restored coordinates. */
            viewport.setPositionSource(positionOf);
            renderer.setEmphasis({ hoverId: null });

            showOpeningView();
            announce('Back to the opening view: ' +
                focus.scene().nodes.length + ' organisations. Click one to open it.');
            onChange();
        }

        /**
         * The opening view, settled to fill the stage. The handful of
         * organisations the map starts on sit far apart on the board, so
         * drawn where they lie they would be a few specks around a lot of
         * empty sand.
         */
        function showOpeningView() {
            /* Back to the start: the next click settles from what is on screen. */
            seedKey = null;
            var scene = focus.scene();
            renderer.setScene(scene);
            viewport.setScene(scene);
            if (!scene.nodes.length) return;
            if (!renderer.width) return; /* no stage yet; the resize observer calls back */
            var opening = settleLayout(scene, 90);
            applyLayout(scene, opening.positions, 90 + opening.overhang);
        }
        focus.start = showOpeningView;

        /**
         * Lay the current view out again for the stage as it is now.
         *
         * A settled view is sized to the stage, so the stage changing size
         * invalidates it: the arrangement was computed for a different box.
         * This is also what catches the first paint, where the canvas is
         * measured before the page has finished settling and the opening
         * view ends up framed for a stage that never existed.
         */
        focus.reframe = function () {
            if (!store.ready) return;
            if (chain.length) enterFocus();
            else showOpeningView();
        };

        /* Where the settle for the current trail started from. Laying the
         * same trail out again (Reset view, a resize, a filter) starts from
         * the same place, so a dragged node goes back to its cell instead of
         * seeding a different arrangement from where it was dropped. */
        var seedKey = null;
        var seed = null;

        function enterFocus() {
            stopSettle();
            dropGather();
            hoverId = null;

            var scene = focus.scene();
            if (!scene.nodes.length) {
                /* The filters can hide everything the chain points at. Rather
                 * than show a blank stage, fall back to the whole map. */
                resetToWholeMap();
                return;
            }

            var key = mode + ':' + chain.join(',');
            if (key !== seedKey) {
                seedKey = key;
                seed = Object.create(null);
                scene.nodes.forEach(function (node) {
                    var p = positionOf(node);
                    seed[node.id] = { x: p.x, y: p.y };
                });
            }
            var settled = settleLayout(scene, 70, seed);
            applyLayout(scene, settled.positions, 70 + settled.overhang);

            renderer.setEmphasis({ hoverId: null });
            renderer.setScene(scene);
            viewport.setScene(scene);

            var head = store.node(chain[chain.length - 1]);
            announce(head
                ? (head.name + ' and ' + (scene.nodes.length - 1) + ' connected names. ' +
                    'Step ' + chain.length + ' of your trail.')
                : (scene.nodes.length + ' names.'));
            onChange();
        }

        /* ------------------------------------------------------ settling -- */

        /**
         * Run the force settle once, synchronously, and return where every
         * node in the focused scene should end up. Seeded from where each
         * node currently appears (or from `from`, where given), so the
         * animation that follows is a rearrangement of what is on screen
         * rather than a cut.
         */
        function settleLayout(scene, gridPadding, from) {
            var d3 = root.d3;
            var byId = Object.create(null);
            var points = scene.nodes.map(function (node) {
                var p = (from && from[node.id]) || positionOf(node);
                var point = {
                    id: node.id, r: node.r, x: p.x, y: p.y,
                    /* Carried onto the simulation node so the collision force
                     * and the grid agree about how much room this name
                     * takes. */
                    space: spaceFor(node),
                    label: Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH),
                    tier: tierOf(node),
                    years: !!node.years
                };
                byId[node.id] = point;
                return point;
            });

            /* The simulation consumes these and so does the grid, which
             * walks them outwards from whatever was opened. d3.forceLink
             * rewrites source and target into node references as a side
             * effect, so the grid reads the ids back off the endpoints
             * rather than trusting the fields it passed in. */
            var links = scene.edges.map(function (edge) {
                return {
                    source: edge.sourceId, target: edge.targetId,
                    category: edge.category, direction: edge.direction
                };
            });

            if (d3 && d3.forceSimulation && points.length > 1) {
                var sim = d3.forceSimulation(points)
                    .force('link', d3.forceLink(links)
                        .id(function (d) { return d.id; })
                        .distance(function (l) { return LINK_DISTANCE[l.category] || DEFAULT_DISTANCE; }))
                    .force('charge', d3.forceManyBody().strength(function (d) {
                        return -120 * (d.r / 8);
                    }))
                    .force('collide', d3.forceCollide().radius(function (d) {
                        return d.space;
                    }).iterations(3))
                    /* Pull to the origin, not to a centre force: forceCenter
                     * shifts the whole cloud every tick and leaves a chain of
                     * two clusters drifting. */
                    .force('x', d3.forceX(0).strength(0.06))
                    .force('y', d3.forceY(0).strength(0.06))
                    .stop();
                for (var i = 0; i < SETTLE_TICKS; i++) sim.tick();
                sim.stop();
            }

            var overhang = gridLayout(points, gridPadding, currentRoots(), links);

            var positions = Object.create(null);
            points.forEach(function (point) {
                /* A simulation that loses a node to NaN would otherwise draw
                 * it nowhere and take the viewport frame with it. */
                positions[point.id] = {
                    x: isFinite(point.x) ? point.x : 0,
                    y: isFinite(point.y) ? point.y : 0
                };
                point.x = positions[point.id].x;
                point.y = positions[point.id].y;
            });
            return { positions: positions, points: points, byId: byId, overhang: overhang };
        }

        /**
         * Lay the settled nodes out on a grid that fills the stage.
         *
         * A force layout arranges by relationship, which is the right input
         * and the wrong output: it packs the well-connected into a knot and
         * leaves the corners of the stage empty, so names collide in the
         * middle of a mostly blank canvas. Measured on a thirty-six node
         * neighbourhood it used 32% of the stage with seventeen nodes in one
         * quadrant and two in another.
         *
         * So the simulation is kept only for the order it produces - what is
         * near what, what is above what - and the nodes are then snapped onto
         * a regular grid. Rows are taken off the settled layout top to
         * bottom and each row is sorted left to right, which preserves the
         * arrangement the forces found while giving every node a cell of its
         * own.
         *
         * Cells are sized so a name fits inside one. That is the whole point:
         * a label can only collide with its neighbour if the cell is narrower
         * than the name, so the grid is built from the labels outwards rather
         * than the nodes outwards.
         *
         * Built in screen pixels, centred on the origin, so the fit that
         * follows lands at a zoom of about one and a cell on the grid is a
         * cell on the screen.
         */
        /**
         * The nodes in rings outwards from the ones that were opened: the
         * roots first, then everything one connection away, then two, and
         * anything the edges do not reach last.
         */
        function ringOrder(points, roots, links) {
            var byId = Object.create(null);
            points.forEach(function (p) { byId[p.id] = p; });

            var adjacent = Object.create(null);
            points.forEach(function (p) { adjacent[p.id] = []; });
            links.forEach(function (link) {
                /* forceLink swapped the ids for node objects on its way
                 * past. */
                var a = link.source && link.source.id !== undefined ? link.source.id : link.source;
                var b = link.target && link.target.id !== undefined ? link.target.id : link.target;
                if (!adjacent[a] || !adjacent[b]) return;
                adjacent[a].push(b);
                adjacent[b].push(a);
            });

            var cx = points.reduce(function (t, p) { return t + p.x; }, 0) / points.length;
            var cy = points.reduce(function (t, p) { return t + p.y; }, 0) / points.length;
            var byAngle = function (a, b) {
                return Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx);
            };

            var seen = Object.create(null);
            var order = [];
            var ring = roots.filter(function (id) { return byId[id] && !seen[id]; })
                .map(function (id) { seen[id] = true; return { node: byId[id], parent: null }; });

            /* Nothing was opened, so start from the best connected thing on
             * screen and let the rest fall out around it. */
            if (!ring.length && points.length) {
                var best = points.slice().sort(function (a, b) {
                    return (adjacent[b.id].length - adjacent[a.id].length);
                })[0];
                seen[best.id] = true;
                ring = [{ node: best, parent: null }];
            }

            while (ring.length) {
                ring.sort(function (a, b) { return byAngle(a.node, b.node); });
                order = order.concat(ring);
                var next = [];
                ring.forEach(function (entry) {
                    adjacent[entry.node.id].forEach(function (id) {
                        if (seen[id] || !byId[id]) return;
                        seen[id] = true;
                        /* Remembered so the node can be placed outwards from
                         * whatever revealed it rather than anywhere in the
                         * next ring. */
                        next.push({ node: byId[id], parent: entry.node.id });
                    });
                });
                ring = next;
            }

            /* Anything the connections never reached. */
            points.forEach(function (p) {
                if (!seen[p.id]) order.push({ node: p, parent: null });
            });
            return order;
        }

        /**
         * Which band of the map a node belongs in, top to bottom.
         *
         * The companies first, then the people who ran them, then the
         * programmes, then everyone else who worked there, then the bodies
         * around the edges of the industry. Ownership and command are what
         * this map is for, so they sit above the places they acted on.
         *
         * "Corporate staff" is read off the connections rather than the
         * person: somebody with a leadership, board or ownership edge was
         * running something, whatever their title. Of the 335 people on the
         * board, 230 have one.
         */
        /** How many tiers this scene actually uses. */
        function tierCount(points) {
            var used = Object.create(null);
            points.forEach(function (p) { used[p.tier] = true; });
            return Math.max(1, Object.keys(used).length);
        }

        function tierOf(node) {
            if (node.kind === 'parent' || node.kind === 'association') return TIER_COMPANY;
            if (node.kind === 'person') {
                var by = node.degreeByCategory || {};
                var runs = (by.leadership || 0) + (by.board || 0) + (by.corporate || 0);
                return runs > 0 ? TIER_COMMAND : TIER_STAFF;
            }
            if (node.kind === 'facility') return TIER_PROGRAMME;
            return TIER_OTHER;
        }

        /**
         * How far each company sits above the bottom of the ownership chain
         * on screen: zero for one that owns nothing else here, one more than
         * the tallest thing it owns otherwise. Ownership is read off
         * corporate edges between two companies, source owning target, as
         * the board records them; a rebrand is a continuation, not
         * ownership, and is skipped. Cycles - the board has a couple - stop
         * the walk rather than looping it.
         */
        function ownershipHeight(points, links) {
            var isCompany = Object.create(null);
            points.forEach(function (p) { if (p.tier === TIER_COMPANY) isCompany[p.id] = true; });

            var owns = Object.create(null);
            links.forEach(function (link) {
                if (link.category !== 'corporate' || link.direction === 'renamed') return;
                var a = link.source && link.source.id !== undefined ? link.source.id : link.source;
                var b = link.target && link.target.id !== undefined ? link.target.id : link.target;
                if (!isCompany[a] || !isCompany[b] || a === b) return;
                (owns[a] = owns[a] || []).push(b);
            });

            var height = Object.create(null);
            var walking = Object.create(null);
            var measure = function (id) {
                if (height[id] !== undefined) return height[id];
                if (walking[id]) return 0;
                walking[id] = true;
                var best = -1;
                (owns[id] || []).forEach(function (other) {
                    best = Math.max(best, measure(other));
                });
                walking[id] = false;
                height[id] = best + 1;
                return height[id];
            };
            Object.keys(isCompany).forEach(measure);
            return height;
        }

        /**
         * Lay the nodes out as a board: rows of cells, the clicked node in
         * the middle, and two things deciding where everything else goes.
         *
         * Vertically the map is a hierarchy. Above the centre row sit the
         * people who ran things and, above them, the companies; below it sit
         * the programmes and, beneath them, everyone else who worked there.
         * Ownership and command are what this map is for, so they sit above
         * the places they acted on, and a company is never drawn below
         * something it owns.
         *
         * Within each of those bands, distance from the centre row is
         * distance from what was clicked. First-degree connections take the
         * rows nearest the centre; second-degree ones the rows beyond them,
         * so an owner's owner is above the owner and a trace runs away from
         * the middle rather than doubling back across it. Within a row,
         * nodes are ordered by where their parent sits, so a child lands
         * near the column that revealed it.
         *
         * These are not competing arrangements. Hierarchy is the vertical
         * axis; distance from the click is how far along it a node goes.
         *
         * Rows are packed by the width each name actually needs, not cut
         * into fixed columns, so every name gets exactly its own room and a
         * row holds as many as the stage is wide enough for. A band's rings
         * are packed one after another into the same run of rows rather than
         * each ring starting a row of its own: with five bands and three
         * rings that fragmentation alone doubled the row count and pushed
         * the block off the bottom of the stage, and a block that has to be
         * scaled down takes its cells below one label wide and drops names.
         * Ring order still never runs backwards within a band.
         */
        function gridLayout(points, padding, roots, links) {
            var n = points.length;
            grid = null;
            if (!n || !renderer.width) return 0;

            var board = Math.max(200, renderer.width - padding * 2);
            var boardH = Math.max(200, renderer.height - padding * 2);
            var tallest = points.reduce(function (t, p) { return Math.max(t, p.r); }, 0);
            var needOf = function (p) { return p.label + COLUMN_GUTTER; };

            /* Who revealed whom, and how many steps out each node is. */
            var order = ringOrder(points, roots, links);
            var parentOf = Object.create(null);
            var ringOf = Object.create(null);
            var isRoot = Object.create(null);
            roots.forEach(function (id) { isRoot[id] = true; });
            order.forEach(function (entry) {
                parentOf[entry.node.id] = entry.parent;
                ringOf[entry.node.id] = entry.parent ? (ringOf[entry.parent] || 0) + 1 : 0;
            });
            var ringFor = function (p) { return isRoot[p.id] ? 0 : Math.max(1, ringOf[p.id] || 1); };
            var bandFor = function (p) { return isRoot[p.id] ? 'centre' : String(p.tier); };

            /* First pass, in ring order across every band, fixes a left to
             * right order so that a child can be placed by its parent's
             * position. Only the order survives into the second pass. */
            var placedX = Object.create(null);
            var anchorX = function (p) {
                var parent = parentOf[p.id];
                if (parent && placedX[parent] !== undefined) return placedX[parent];
                return p.x;
            };
            var byRing = points.slice().sort(function (a, b) { return ringFor(a) - ringFor(b); });
            byRing.forEach(function (p) { placedX[p.id] = anchorX(p); });

            /* Group by band, each band's nodes sorted inner ring first and
             * then by where their parent sits. */
            var bands = Object.create(null);
            points.forEach(function (p) {
                var key = bandFor(p);
                (bands[key] = bands[key] || []).push(p);
            });
            /* Inside the company band, distance from the centre is distance
             * up the ownership chain: a company that owns another on screen
             * sits in a row above it, whatever ring either was revealed in.
             * Clicking a facility reveals its owner at ring one and that
             * owner's subsidiary at ring two, and ring order alone would have
             * drawn the subsidiary above the company that owns it. */
            var height = ownershipHeight(points, links);
            Object.keys(bands).forEach(function (key) {
                var company = key === String(TIER_COMPANY);
                bands[key].sort(function (a, b) {
                    if (company) {
                        var h = height[a.id] - height[b.id];
                        if (h !== 0) return h;
                    }
                    var r = ringFor(a) - ringFor(b);
                    if (r !== 0) return r;
                    var d = placedX[a.id] - placedX[b.id];
                    return d !== 0 ? d : a.x - b.x;
                });
            });

            var packBand = function (nodes, width, breakOn) {
                var rows = [];
                var row = [];
                var used = 0;
                var last;
                nodes.forEach(function (p) {
                    var need = needOf(p);
                    /* Where a band is ordered by something that has to be
                     * read off the rows - ownership, for companies - a change
                     * in it starts a new row, so an owner is never drawn
                     * level with what it owns. */
                    var key = breakOn ? breakOn(p) : undefined;
                    var turn = breakOn && row.length && key !== last;
                    last = key;
                    if (row.length && (turn || used + need > width)) {
                        rows.push(row);
                        row = [];
                        used = 0;
                    }
                    row.push(p);
                    used += need;
                });
                if (row.length) rows.push(row);
                return rows;
            };

            /* Which of the click's own connections can sit beside it in the
             * centre row. Staff and outside bodies have no place in the
             * chain of command, so the hierarchy says nothing about whether
             * they go above or below; put below, they took a row each under
             * the programmes and left the centre row a single name across an
             * empty stage. Fellow leaders go beside a person for the same
             * reason. Companies and programmes keep their bands. */
            var clickedPerson = (bands.centre || []).some(function (p) {
                return p.tier === TIER_COMMAND || p.tier === TIER_STAFF;
            });
            var flanks = function (p) {
                if (isRoot[p.id] || ringFor(p) !== 1) return false;
                if (p.tier === TIER_STAFF || p.tier === TIER_OTHER) return true;
                return clickedPerson && p.tier === TIER_COMMAND;
            };

            /* The centre row: what was clicked in the middle and as many
             * flanking connections either side as the width holds, each
             * going to whichever side is lighter and, where both are even,
             * the side it was settled on. What does not fit stays in its
             * band. */
            var centreRow = function (width, taken) {
                var middle = bands.centre || [];
                var used = middle.reduce(function (t, p) { return t + needOf(p); }, 0);
                if (!middle.length || used > width) return packBand(middle, width);
                var pivot = middle.reduce(function (t, p) { return t + placedX[p.id]; }, 0) / middle.length;
                var left = [];
                var right = [];
                var leftW = 0;
                var rightW = 0;
                [TIER_COMMAND, TIER_STAFF, TIER_OTHER].forEach(function (band) {
                    (bands[String(band)] || []).forEach(function (p) {
                        if (!flanks(p)) return;
                        var need = needOf(p);
                        if (used + need > width) return;
                        var goLeft = leftW === rightW ? placedX[p.id] < pivot : leftW < rightW;
                        if (goLeft) { left.push(p); leftW += need; } else { right.push(p); rightW += need; }
                        used += need;
                        taken[p.id] = true;
                    });
                });
                var byX = function (a, b) { return placedX[a.id] - placedX[b.id]; };
                return [left.sort(byX).concat(middle, right.sort(byX))];
            };

            var stackRows = function (width) {
                var taken = Object.create(null);
                var centre = centreRow(width, taken);
                var rest = function (band) {
                    return (bands[String(band)] || []).filter(function (p) { return !taken[p.id]; });
                };
                var above = [];
                [TIER_COMMAND, TIER_COMPANY].forEach(function (band) {
                    var breakOn = band === TIER_COMPANY
                        ? function (p) { return height[p.id]; }
                        : null;
                    packBand(rest(band), width, breakOn).forEach(function (r) { above.push(r); });
                });
                var below = [];
                [TIER_PROGRAMME, TIER_STAFF, TIER_OTHER].forEach(function (band) {
                    packBand(rest(band), width).forEach(function (r) { below.push(r); });
                });
                return above.slice().reverse().concat(centre, below);
            };

            var rowGutter = ROW_GUTTER;
            /* A name with years under it is a line taller; give every row
             * that room when any name on the board has them, so the rows
             * stay even. */
            var yearsLine = (root.KOPNetworkCanvas && root.KOPNetworkCanvas.YEARS_LINE) || 11;
            var labelRoom = LABEL_ROOM + (points.some(function (p) { return p.years; }) ? yearsLine : 0);
            var rowH = tallest * 2 + labelRoom + rowGutter;

            /* Full width first, since a block wider than the stage is the one
             * thing that costs names. */
            var rowWidth = board;
            var stacked = stackRows(rowWidth);

            /* A shallow view - six organisations, or one person - packed at
             * full width is a single long row with the rest of the stage
             * empty above and below it. Give it a block nearer the shape of
             * the stage instead, so the fit can zoom in and fill. Zooming in
             * only ever makes a cell wider than its name, so nothing is lost
             * by it. */
            if (stacked.length * rowH < boardH * 0.55) {
                var total = points.reduce(function (t, p) { return t + needOf(p); }, 0);
                var widest = points.reduce(function (t, p) { return Math.max(t, needOf(p)); }, 0);
                var aspect = renderer.width / Math.max(1, renderer.height);

                /* Names are indivisible, so a width worked out from area
                 * alone can land just under two names and leave every row
                 * holding one - six organisations in a single column. Try
                 * each width that would give r rows if names packed
                 * perfectly, pack for real at that width, and keep whichever
                 * block comes out nearest the shape of the stage. */
                var bestWidth = rowWidth;
                var bestStack = stacked;
                var bestScore = Infinity;
                var tried = Object.create(null);
                for (var r = 1; r <= n; r++) {
                    var width = Math.max(widest, Math.min(board, Math.ceil(total / r)));
                    if (tried[width]) continue;
                    tried[width] = true;
                    var stack = stackRows(width);
                    var used = stack.reduce(function (t, row) {
                        return Math.max(t, row.reduce(function (w, p) { return w + needOf(p); }, 0));
                    }, 1);
                    var score = Math.abs(Math.log((used / (stack.length * rowH)) / aspect));
                    if (score < bestScore) {
                        bestScore = score;
                        bestWidth = width;
                        bestStack = stack;
                    }
                }
                rowWidth = bestWidth;
                stacked = bestStack;
            }

            /* Tighten before ever letting the fit scale the block down: a
             * block scaled down takes its cells below one label wide and the
             * renderer starts dropping names. Gutters give way first, and
             * only after that does the stage's height set a limit. */
            if (stacked.length * rowH > boardH) {
                /* Not below the point where the gutter still holds a clear
                 * channel for the traces at a zoom of one. */
                rowGutter = 10;
                labelRoom = 15;
                rowH = tallest * 2 + labelRoom + rowGutter;
            }

            /* Use the width the stage has. A block of short rows is framed
             * by its height, and at that zoom the stage shows more width than
             * the rows fill - Rae Ann Knopf's view came out as six rows of
             * one to three names in a strip down the middle, sand either
             * side. Spread each row across the width the frame will show
             * anyway, so the zoom does not change and the names get the room.
             * Only ever wider than packed, so the clearance between names
             * never drops below the gutter, and never more than SPREAD_MAX
             * extra per name, so two names in a row do not end up a stage
             * apart with a trace strung between them. */
            var blockH = stacked.length * rowH;
            var packedW = stacked.reduce(function (t, row) {
                return Math.max(t, row.reduce(function (w, p) { return w + needOf(p); }, 0));
            }, 0);
            /* A block taller than the stage is looked at around a zoom of
             * one, which shows the stage's own width. */
            var shown = blockH <= boardH ? blockH * board / boardH : board;
            var spreadTo = Math.max(packedW, shown);

            var shiftY = (stacked.length - 1) * rowH / 2;
            stacked.forEach(function (row, index) {
                var width = row.reduce(function (t, p) { return t + needOf(p); }, 0);
                /* Spread as space-around: every name gets an equal share of
                 * the spare room, half each side, so a lone name stays
                 * centred and the outer names do not sit on the frame. */
                var extra = Math.min(SPREAD_MAX, Math.max(0, (spreadTo - width) / row.length));
                var x = -(width + extra * row.length) / 2;
                row.forEach(function (p) {
                    var need = needOf(p) + extra;
                    p.x = x + need / 2;
                    p.y = index * rowH - shiftY;
                    x += need;
                });
            });
            rowWidth = Math.max(rowWidth, spreadTo);

            var widestRow = stacked.reduce(function (t, row) { return Math.max(t, row.length); }, 1);
            grid = {
                x0: -rowWidth / 2, y0: -shiftY - rowH / 2,
                cellW: rowWidth / widestRow, cellH: rowH,
                cols: widestRow, rows: stacked.length
            };

            /* Every name has exactly its own width, so nothing hangs over. */
            return 0;
        }

        function stopSettle() {
            if (settleFrame) { root.cancelAnimationFrame(settleFrame); settleFrame = 0; }
        }

        /**
         * Put a settled layout on screen and frame it.
         *
         * Positions are applied outright rather than tweened. Animating them
         * meant the view had to be aimed at where they were going rather
         * than where they were, and every attempt to run those two things on
         * one clock left the frame belonging to whichever finished last - an
         * opened neighbourhood settling correctly and then being looked at
         * through the previous view's zoom, most of it off the edges.
         *
         * The frame is taken from the nodes after they are in place, so it
         * is measured against what is actually drawn and cannot disagree
         * with it. The map paints settled, which is what the whole
         * precomputed layout was for.
         */
        function applyLayout(scene, targets, padding) {
            var next = Object.create(null);
            scene.nodes.forEach(function (node) {
                var target = targets[node.id];
                if (target) next[node.id] = { x: target.x, y: target.y };
            });
            layout = next;
            renderer.setGrid(grid);

            /* Names are drawn at a fixed size whatever the zoom, so framing a
             * block that does not fit means zooming out until the rows are
             * closer together on screen than a name is tall - and then the
             * renderer has to drop names, which is the one thing it must not
             * do. On a stage too small for the whole block, hold the zoom at
             * the point where the rows still clear each other, put what was
             * clicked in the middle, and let the reader pan to the rest. A
             * stage that can hold the block is framed as before.
             */
            var floor = legibleZoom(scene, next);
            var points = scene.nodes.map(function (node) {
                var p = next[node.id] || positionOf(node);
                return { x: p.x, y: p.y, r: node.r };
            });
            var frame = viewport.frameOf(points, padding);
            /* What was just clicked and its own connections: the part of
             * the board the visitor is looking at. */
            var head = chain.length ? store.node(chain[chain.length - 1]) : null;
            var near = null;
            if (head) {
                var ownIds = Object.create(null);
                ownIds[head.id] = true;
                store.neighbours(head.id, true).forEach(function (link) { ownIds[link.other.id] = true; });
                var own = scene.nodes.filter(function (node) { return ownIds[node.id]; }).map(function (node) {
                    var p = next[node.id] || positionOf(node);
                    return { x: p.x, y: p.y, r: node.r };
                });
                near = viewport.frameOf(own, padding);
            }

            /* Names are drawn at a fixed size whatever the zoom, so framing a
             * block too big for the stage zooms out until names collide and
             * the renderer has to drop some - the one thing it must not do.
             * The renderer is asked, at each candidate zoom, how many names
             * it would drop (dropsAt), and the view takes the lowest zoom at
             * which the answer is none: the whole block when that fits,
             * otherwise the click and its own connections, otherwise the
             * click alone at the middle with the rest a pan away. The floor
             * from legibleZoom bounds the search from above: at the floor the
             * rows and neighbours clear each other by construction. */
            var at = function (node) { return next[node.id] || positionOf(node); };
            var mid = function (f) {
                return { x: (renderer.width / 2 - f.x) / f.k, y: (renderer.height / 2 - f.y) / f.k };
            };
            var centredAt = function (k, centre) {
                return { k: k, x: renderer.width / 2 - centre.x * k, y: renderer.height / 2 - centre.y * k };
            };
            var clean = function (t) {
                return !renderer.dropsAt || renderer.dropsAt(scene.nodes, at, t.k, t.x, t.y) === 0;
            };
            /* The lowest zoom in [lo, hi] at which nothing on the stage is
             * dropped, around a fixed centre. Nine halvings is well under a
             * percent of zoom, and each is one dry run of the label pass. */
            var lowestClean = function (lo, hi, centre) {
                if (clean(centredAt(lo, centre))) return lo;
                for (var step = 0; step < 9; step++) {
                    var m = (lo + hi) / 2;
                    if (clean(centredAt(m, centre))) hi = m; else lo = m;
                }
                return hi;
            };

            var chosen = null;
            if (frame && clean(frame)) {
                chosen = null;   /* the whole block fits as it is */
            } else if (frame) {
                var top = Math.max(floor || frame.k, frame.k);
                if (mode === 'expand' && chain.length > 1 && near) {
                    chosen = centredAt(lowestClean(Math.max(near.k, frame.k), Math.max(top, near.k), mid(near)), mid(near));
                } else if (near && near.k >= frame.k) {
                    chosen = centredAt(lowestClean(frame.k, Math.max(top, near.k), mid(near)), mid(near));
                } else {
                    chosen = centredAt(lowestClean(frame.k, top, mid(frame)), mid(frame));
                }
                if (head && chosen) {
                    /* Whatever else had to go off the stage, the click stays on it. */
                    var hp = at(head);
                    var hx = hp.x * chosen.k + chosen.x;
                    var hy = hp.y * chosen.k + chosen.y;
                    if (hx < 0 || hx > renderer.width || hy < 0 || hy > renderer.height) {
                        chosen = centredAt(chosen.k, hp);
                    }
                }
            }

            if (chosen) {
                viewport.setTransform(chosen.k, chosen.x, chosen.y);
            } else if (mode === 'expand' && chain.length > 1 && frame && near && near.k >= frame.k) {
                /* Expand keeps every earlier click on the board, so framing
                 * all of it leaves the newest - the one the visitor is
                 * looking for - small in a corner. Frame what was just
                 * clicked and its own connections instead, and leave the rest
                 * of the board a pan away. */
                viewport.setTransform(near.k, near.x, near.y);
            } else {
                viewport.fit(scene.nodes, padding);
            }
            viewport.rebuildTree();
            viewport.scheduleDraw();
        }

        /**
         * The lowest zoom at which the rows of this layout still leave room
         * for the names between them, or 0 when there is only one row. The
         * pitch is measured off the placed positions rather than the packer's
         * intentions, so it is the spacing actually on screen.
         */
        function legibleZoom(scene, positions) {
            /* The renderer measures the names; asking it rather than
             * estimating keeps the two from drifting apart. */
            var boxOf = renderer.labelBox
                ? function (node) { return renderer.labelBox(node); }
                : function (node) {
                    return { width: labelWidth(node) + 10, height: LABEL_ROOM + (node.years ? 11 : 0) };
                };
            var floor = 0;

            var rows = Object.create(null);
            scene.nodes.forEach(function (node) {
                var p = positions[node.id];
                if (!p) return;
                var y = Math.round(p.y);
                (rows[y] = rows[y] || []).push({ node: node, x: p.x, box: boxOf(node) });
            });
            var keys = Object.keys(rows).map(Number).sort(function (a, b) { return a - b; });

            /* Down the page: a row's labels hang below its nodes, so the gap
             * to the next row has to hold the tallest label in the row. */
            for (var i = 1; i < keys.length; i++) {
                var gap = keys[i] - keys[i - 1];
                if (gap <= 0) continue;
                var tallest = rows[keys[i - 1]].reduce(function (t, e) { return Math.max(t, e.box.height); }, 0);
                floor = Math.max(floor, tallest / gap);
            }

            /* Across a row: zooming out moves nodes together while names keep
             * their size, so two neighbours need half of each name between
             * them. On a narrow stage this is what binds. */
            keys.forEach(function (key) {
                var row = rows[key].slice().sort(function (a, b) { return a.x - b.x; });
                for (var j = 1; j < row.length; j++) {
                    var dx = row[j].x - row[j - 1].x;
                    if (dx <= 0) continue;
                    floor = Math.max(floor, (row[j - 1].box.width + row[j].box.width) / 2 / dx);
                }
            });

            /* Never above 1: a block that fits is framed, not blown up. */
            return Math.min(1, floor);
        }

        /** What one name takes across, in the units the packer measured it in. */
        function labelWidth(node) {
            return Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH);
        }

        /* --------------------------------------------- filters moved under us -- */

        /**
         * The rail can hide something the chain is standing on. Re-derive the
         * scene and settle again rather than leaving the view describing a
         * graph that no longer exists.
         */
        focus.refresh = function () {
            if (!chain.length) return false;
            var live = store.visible().nodeIds;
            chain = chain.filter(function (id) { return !!live[id]; });
            if (!chain.length) {
                resetToWholeMap();
                return false;
            }
            enterFocus();
            return true;
        };

        focus.destroy = function () {
            stopSettle();
            if (gatherFrame) root.cancelAnimationFrame(gatherFrame);
        };

        return focus;
    }

    root.KOPNetworkFocus = { create: create, GATHER: GATHER };
})(typeof self !== 'undefined' ? self : this);
