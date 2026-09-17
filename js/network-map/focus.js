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
    var SETTLE_MS = 480;

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

        focus.isFocused = function () { return chain.length > 0; };
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

        /** Every node the chain puts on screen: its members and their neighbours. */
        function chainMembers() {
            var ids = Object.create(null);
            chain.forEach(function (id) {
                if (!store.visible().nodeIds[id] && !store.node(id)) return;
                ids[id] = true;
                store.neighbours(id, true).forEach(function (link) {
                    ids[link.other.id] = true;
                });
            });
            return ids;
        }

        /**
         * The scene to draw. The whole map is the store's visible subgraph;
         * a focused chain is the members and every edge between them, which
         * is deliberately more than the chain's own connections - seeing that
         * two of someone's programmes also connect to each other is the
         * point of putting them on screen together.
         */
        focus.scene = function () {
            var visible = store.visible();
            if (!chain.length) return visible;

            var ids = chainMembers();
            var nodes = visible.nodes.filter(function (node) { return ids[node.id]; });
            var edges = visible.edges.filter(function (edge) {
                return ids[edge.sourceId] && ids[edge.targetId];
            });
            return { nodes: nodes, edges: edges, nodeIds: ids, degrees: visible.degrees };
        };

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
                /* Inside a focused view the neighbourhood is already the only
                 * thing on screen, so hover rings and labels rather than
                 * dimming what is left. */
                near: (gather && gather.near && !chain.length) ? gather.near : null,
                nearEdges: (gather && gather.nearEdges && !chain.length) ? gather.nearEdges : null,
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

            var scene = focus.scene();
            renderer.setScene(scene);
            viewport.setScene(scene);
            viewport.fit();
            announce('Back to the whole map: ' + scene.nodes.length + ' names.');
            onChange();
        }

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

            var settled = settleLayout(scene);
            var framed = viewport.frameOf(settled.points, 70);
            animateTo(scene, settled.positions, framed);

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
        function settleLayout(scene) {
            var d3 = root.d3;
            var byId = Object.create(null);
            var points = scene.nodes.map(function (node) {
                var p = positionOf(node);
                var point = { id: node.id, r: node.r, x: p.x, y: p.y };
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
                        return d.r + 10;
                    }).iterations(2))
                    /* Pull to the origin, not to a centre force: forceCenter
                     * shifts the whole cloud every tick and leaves a chain of
                     * two clusters drifting. */
                    .force('x', d3.forceX(0).strength(0.06))
                    .force('y', d3.forceY(0).strength(0.06))
                    .stop();
                for (var i = 0; i < SETTLE_TICKS; i++) sim.tick();
                sim.stop();
            }

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
            return { positions: positions, points: points, byId: byId };
        }

        function stopSettle() {
            if (settleFrame) { root.cancelAnimationFrame(settleFrame); settleFrame = 0; }
        }

        /**
         * Move the nodes and the viewport to the settled layout together. The
         * destination is already known, so both tween on the same clock and
         * the view never chases a moving target.
         */
        function animateTo(scene, targets, framed) {
            var from = Object.create(null);
            scene.nodes.forEach(function (node) {
                var p = positionOf(node);
                from[node.id] = { x: p.x, y: p.y };
            });

            var startTransform = {
                k: viewport.transform.k,
                x: viewport.transform.x,
                y: viewport.transform.y
            };

            var write = function (t) {
                var next = Object.create(null);
                scene.nodes.forEach(function (node) {
                    var a = from[node.id];
                    var b = targets[node.id] || a;
                    next[node.id] = {
                        x: a.x + (b.x - a.x) * t,
                        y: a.y + (b.y - a.y) * t
                    };
                });
                layout = next;
                if (framed) {
                    viewport.transform.k = startTransform.k + (framed.k - startTransform.k) * t;
                    viewport.transform.x = startTransform.x + (framed.x - startTransform.x) * t;
                    viewport.transform.y = startTransform.y + (framed.y - startTransform.y) * t;
                }
            };

            if (prefersReducedMotion()) {
                write(1);
                viewport.rebuildTree();
                viewport.scheduleDraw();
                return;
            }

            var start = root.performance ? root.performance.now() : Date.now();
            var step = function (now) {
                var t = Math.min(1, (now - start) / SETTLE_MS);
                write(easeOut(t));
                viewport.scheduleDraw();
                if (t < 1) {
                    settleFrame = root.requestAnimationFrame(step);
                    return;
                }
                settleFrame = 0;
                /* Positions are final, so the hit index can be built once
                 * rather than sixty times on the way here. */
                viewport.rebuildTree();
            };
            settleFrame = root.requestAnimationFrame(step);
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
