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

        var focus = { };

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
        function visibleIds() {
            var ids = Object.create(null);
            var live = store.visible().nodeIds;

            store.seeds().forEach(function (node) {
                if (live[node.id]) ids[node.id] = true;
            });

            chain.forEach(function (id) {
                if (!live[id]) return;
                ids[id] = true;
                store.neighbours(id, true).forEach(function (link) {
                    ids[link.other.id] = true;
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
             * Object.keys takes a snapshot, so this opens people out by one
             * step and stops: a person reached through another person's
             * expansion does not expand in turn. People are the cheap case to
             * do this for - median degree two, most seven - but a rule that
             * walked outwards without a stop would not stay cheap. */
            Object.keys(ids).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'person') return;
                store.neighbours(id, true).forEach(function (link) {
                    ids[link.other.id] = true;
                });
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
                var connected = Object.create(null);
                edges.forEach(function (edge) {
                    connected[edge.sourceId] = true;
                    connected[edge.targetId] = true;
                });
                /* What was clicked always stays, even where the filters have
                 * taken away everything it connected to. */
                chain.forEach(function (id) { connected[id] = true; });

                var kept = Object.create(null);
                nodes = nodes.filter(function (node) {
                    if (!connected[node.id]) return false;
                    kept[node.id] = true;
                    return true;
                });
                ids = kept;
            }

            return { nodes: nodes, edges: edges, nodeIds: ids, degrees: visible.degrees };
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
            chain.push(node.id);
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

            var settled = settleLayout(scene, 70);
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
         * node currently appears, so the animation that follows is a
         * rearrangement of what is on screen rather than a cut.
         */
        function settleLayout(scene, gridPadding) {
            var d3 = root.d3;
            var byId = Object.create(null);
            var points = scene.nodes.map(function (node) {
                var p = positionOf(node);
                var point = {
                    id: node.id, r: node.r, x: p.x, y: p.y,
                    /* Carried onto the simulation node so the collision force
                     * and the grid agree about how much room this name
                     * takes. */
                    space: spaceFor(node),
                    label: Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH)
                };
                byId[node.id] = point;
                return point;
            });

            if (d3 && d3.forceSimulation && points.length > 1) {
                var links = scene.edges.map(function (edge) {
                    return { source: edge.sourceId, target: edge.targetId, category: edge.category };
                });
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

            var overhang = gridLayout(points, gridPadding);

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
        function gridLayout(points, padding) {
            var n = points.length;
            if (!n || !renderer.width) return 0;

            var boardW = Math.max(120, renderer.width - padding * 2);
            var boardH = Math.max(120, renderer.height - padding * 2);

            /* The widest name would give one very long label a veto over the
             * whole grid, so the column width is set by the upper quartile
             * and the few longer names are allowed to run into their
             * neighbours' margins. */
            var widths = points.map(function (p) { return p.label; }).sort(function (a, b) { return a - b; });
            var wide = widths[Math.min(widths.length - 1, Math.floor(widths.length * 0.88))];
            var cellNeeds = wide + COLUMN_GUTTER;

            /* Enough columns to look like the stage, but never so many that a
             * name cannot fit in one. */
            var byShape = Math.max(1, Math.round(Math.sqrt(n * (boardW / boardH))));
            var byLabel = Math.max(1, Math.floor(boardW / cellNeeds));
            var cols = Math.max(1, Math.min(byShape, byLabel, n));
            var rows = Math.ceil(n / cols);

            var cellW = boardW / cols;
            var cellH = boardH / rows;

            /* Rows off the settled layout, top to bottom; each row left to
             * right. The forces decided who sits near whom; the grid only
             * decides where that lands. */
            var order = points.slice().sort(function (a, b) { return a.y - b.y; });
            var placed = 0;
            for (var row = 0; row < rows; row++) {
                var band = order.slice(placed, placed + cols);
                if (!band.length) break;
                band.sort(function (a, b) { return a.x - b.x; });
                /* A short last row is centred rather than left-aligned, so
                 * the block does not end on a ragged edge. */
                var indent = (cols - band.length) * cellW / 2;
                for (var col = 0; col < band.length; col++) {
                    band[col].x = -boardW / 2 + indent + (col + 0.5) * cellW;
                    band[col].y = -boardH / 2 + (row + 0.5) * cellH;
                }
                placed += band.length;
            }

            /* A name wider than its cell hangs over the edges of it. That is
             * fine in the middle of the board, where the neighbouring cell
             * has room to spare, and not fine in the outermost column, where
             * it hangs over the edge of the canvas and gets cut in half.
             * Report the overhang so the frame can allow for it. */
            var widest = widths[widths.length - 1];
            return Math.max(0, (widest - cellW) / 2);
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
            viewport.fit(scene.nodes, padding);
            viewport.rebuildTree();
            viewport.scheduleDraw();
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
