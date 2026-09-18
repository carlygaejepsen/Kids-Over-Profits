/**
 * Network map: viewport.
 *
 * Pan, zoom, pinch, node drag and hit testing. It owns the transform object
 * the renderer draws with, mutating it in place rather than handing the
 * renderer a new one each frame.
 *
 * Pointer events throughout, so mouse, pen and touch take the same path and
 * there is no separate touch handler to keep in step. One pointer down on
 * empty space pans; one pointer down on a node drags it and pins it for the
 * session; two pointers pinch about their midpoint; the wheel zooms about
 * the cursor.
 *
 * Hit testing is a d3-quadtree over current positions, rebuilt when the
 * scene changes or a drag ends rather than per frame. Every input schedules
 * a single requestAnimationFrame draw, so a burst of thirty wheel events
 * costs one paint.
 */
(function (root) {
    'use strict';

    var MIN_ZOOM = 0.1;
    var MAX_ZOOM = 6;
    /* How far from a node's edge still counts as hitting it, in CSS pixels.
     * Kept small: at a wide view the nodes are a couple of pixels across and
     * nine apart, so a generous slop means the pointer is always over
     * something and never over the thing you meant - you cannot tell what
     * you are about to hover before you hover it. Touch gets its own, larger
     * allowance, because a fingertip really is that wide. */
    var HIT_SLOP = 3;
    var HIT_SLOP_TOUCH = 9;
    /* Movement under this many pixels between down and up is a click, not a
     * drag. Fingers wobble; mice do not. */
    var CLICK_SLOP = 4;
    /* Zoom per wheel notch. The map spans 0.1 to 6, and at the old gain it
     * took roughly thirty notches to cross that - far too much work to get
     * from the whole board down to a readable cluster. */
    var WHEEL_GAIN = 0.004;
    /* Double click or double tap steps in about the pointer. */
    var DOUBLE_STEP = 2;

    function clamp(v, lo, hi) {
        return v < lo ? lo : (v > hi ? hi : v);
    }

    function create(options) {
        var canvas = options.canvas;
        var renderer = options.renderer;
        var transform = renderer.transform;

        var onHover = options.onHover || function () {};
        var onSelect = options.onSelect || function () {};
        var onChange = options.onChange || function () {};

        /* Where a node currently is, and where it currently *appears*. The
         * base position is the live object, so a drag writes straight to it:
         * on the whole map that is the node and dragging pins it, in a
         * focused neighbourhood it is that view's own coordinates and the
         * settled layout is left alone.
         *
         * Offsets are the hover gather, which is display-only. They have to
         * be honoured here and not just in the renderer, or a gathered
         * neighbour would run away from the pointer that reached for it. */
        var positionOf = options.positionOf || function (node) { return node; };
        var offsetsOf = options.offsets || function () { return null; };

        var scene = { nodes: [], edges: [] };
        var tree = null;
        /* The largest radius in the scene bounds the quadtree search, so a
         * big hub is not missed in favour of a small node nearer the
         * pointer. */
        var maxRadius = 16;

        /* Live pointers by id, in canvas-local CSS pixels. */
        var pointers = Object.create(null);
        var pointerCount = 0;
        var mode = null; /* 'pan' | 'drag' | 'pinch' */
        var dragNode = null;
        /* Distance travelled since pointerdown, to tell a click from a drag. */
        var moved = 0;
        var pinch = null;
        var hoverId = null;
        var frame = 0;
        /* Set by the last pointer down: a finger needs more room than a
         * mouse, and the same map has to serve both. */
        var coarse = false;

        var viewport = { transform: transform };

        /* focus.js is built after the viewport and owns both of these, so
         * they can be handed over once rather than passed at construction. */
        viewport.setPositionSource = function (fn) {
            positionOf = fn || function (node) { return node; };
            rebuildTree();
        };
        viewport.setOffsetSource = function (fn) {
            offsetsOf = fn || function () { return null; };
        };

        /* ------------------------------------------------------ geometry -- */

        function localPoint(event) {
            var rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        function toWorld(px, py) {
            return { x: (px - transform.x) / transform.k, y: (py - transform.y) / transform.k };
        }
        viewport.toWorld = toWorld;

        function toScreen(wx, wy) {
            return { x: wx * transform.k + transform.x, y: wy * transform.k + transform.y };
        }
        viewport.toScreen = toScreen;

        /* --------------------------------------------------------- scene -- */

        viewport.setScene = function (next) {
            scene = next || { nodes: [], edges: [] };
            maxRadius = 8;
            for (var i = 0; i < scene.nodes.length; i++) {
                if (scene.nodes[i].r > maxRadius) maxRadius = scene.nodes[i].r;
            }
            rebuildTree();
            /* A node can be filtered out from under the pointer. */
            if (hoverId && !nodeById(hoverId)) setHover(null);
        };

        /* The tree indexes base positions, so a running gather does not
         * invalidate it sixty times a second. The handful of nodes the
         * gather has moved are checked separately, in nodeAt. */
        function rebuildTree() {
            if (!root.d3 || !root.d3.quadtree) { tree = null; return; }
            tree = root.d3.quadtree()
                .x(function (d) { return positionOf(d).x; })
                .y(function (d) { return positionOf(d).y; })
                .addAll(scene.nodes);
        }
        viewport.rebuildTree = rebuildTree;

        function hits(node, wx, wy, slop, offsets) {
            var p = positionOf(node);
            var off = offsets ? offsets[node.id] : null;
            var dx = (off ? p.x + off[0] : p.x) - wx;
            var dy = (off ? p.y + off[1] : p.y) - wy;
            var reach = node.r + slop;
            return (dx * dx + dy * dy) <= reach * reach;
        }

        /**
         * The node under a canvas-local point, or null. The shape test is a
         * circle of the node's own radius plus the slop, which is close
         * enough for a diamond or a hexagon and much cheaper than the real
         * outline.
         *
         * Gathered nodes are checked first and by hand. There are at most a
         * few dozen of them, and they are the ones whose drawn position the
         * tree does not know about, so a node the pointer is visibly over is
         * found even mid-gather.
         */
        function nodeAt(px, py) {
            if (!scene.nodes.length) return null;
            var world = toWorld(px, py);
            var slop = (coarse ? HIT_SLOP_TOUCH : HIT_SLOP) / transform.k;
            var offsets = offsetsOf();

            if (offsets) {
                var best = null;
                var bestDistance = Infinity;
                for (var i = 0; i < scene.nodes.length; i++) {
                    var node = scene.nodes[i];
                    if (!offsets[node.id]) continue;
                    if (!hits(node, world.x, world.y, slop, offsets)) continue;
                    var p = positionOf(node);
                    var d = Math.hypot(p.x + offsets[node.id][0] - world.x,
                        p.y + offsets[node.id][1] - world.y);
                    if (d < bestDistance) { bestDistance = d; best = node; }
                }
                if (best) return best;
            }

            if (!tree) return null;
            var found = tree.find(world.x, world.y, maxRadius + slop);
            if (!found) return null;
            /* Already ruled out above, and its tree position is not where it
             * is drawn, so it must not win on the stale one. */
            if (offsets && offsets[found.id]) return null;
            return hits(found, world.x, world.y, slop, null) ? found : null;
        }
        viewport.nodeAt = nodeAt;

        /* ------------------------------------------------------ painting -- */

        /** One paint per frame however many inputs arrived. */
        function scheduleDraw() {
            if (frame) return;
            frame = root.requestAnimationFrame(function () {
                frame = 0;
                renderer.draw();
            });
        }
        viewport.scheduleDraw = scheduleDraw;

        viewport.resize = function () {
            if (renderer.resize()) scheduleDraw();
        };

        /* --------------------------------------------------------- hover -- */

        function setHover(id) {
            if (hoverId === id) return;
            hoverId = id;
            canvas.style.cursor = id ? 'pointer' : 'grab';
            onHover(id ? nodeById(id) : null);
            scheduleDraw();
        }

        function nodeById(id) {
            for (var i = 0; i < scene.nodes.length; i++) {
                if (scene.nodes[i].id === id) return scene.nodes[i];
            }
            return null;
        }

        viewport.hovered = function () { return hoverId; };

        /* ------------------------------------------------------- zooming -- */

        /** Zoom to k, keeping the world point under (px, py) where it is. */
        function zoomAbout(k, px, py) {
            var next = clamp(k, MIN_ZOOM, MAX_ZOOM);
            if (next === transform.k) return;
            var world = toWorld(px, py);
            transform.k = next;
            transform.x = px - world.x * next;
            transform.y = py - world.y * next;
            scheduleDraw();
            onChange();
        }
        viewport.zoomAbout = zoomAbout;

        viewport.zoomBy = function (factor) {
            zoomAbout(transform.k * factor, renderer.width / 2, renderer.height / 2);
        };

        /**
         * The transform that would frame these points, without applying it.
         * Points are plain {x, y, r} in world coordinates, so a focused
         * neighbourhood can work out where it is going before it starts
         * moving and tween the view and the nodes together.
         */
        viewport.frameOf = function (points, padding, maxZoom) {
            if (!points || !points.length || !renderer.width) return null;
            var pad = padding === undefined ? 48 : padding;
            /* Framing six organisations would otherwise zoom to the ceiling
             * and blow them up into blobs. A view has a sensible closest
             * distance as well as a widest one. */
            var ceiling = maxZoom === undefined ? MAX_ZOOM : Math.min(MAX_ZOOM, maxZoom);

            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            for (var i = 0; i < points.length; i++) {
                var p = points[i];
                var r = p.r || 0;
                if (p.x - r < minX) minX = p.x - r;
                if (p.y - r < minY) minY = p.y - r;
                if (p.x + r > maxX) maxX = p.x + r;
                if (p.y + r > maxY) maxY = p.y + r;
            }
            var k = clamp(Math.min(
                (renderer.width - pad * 2) / Math.max(1, maxX - minX),
                (renderer.height - pad * 2) / Math.max(1, maxY - minY)
            ), MIN_ZOOM, ceiling);

            return {
                k: k,
                x: renderer.width / 2 - ((minX + maxX) / 2) * k,
                y: renderer.height / 2 - ((minY + maxY) / 2) * k
            };
        };

        /** Frame a set of nodes, or the whole scene, at their drawn positions. */
        viewport.fit = function (nodes, padding, maxZoom) {
            var list = nodes && nodes.length ? nodes : scene.nodes;
            if (!list.length) return;
            var offsets = offsetsOf();
            var points = list.map(function (node) {
                var p = positionOf(node);
                var off = offsets ? offsets[node.id] : null;
                return { x: off ? p.x + off[0] : p.x, y: off ? p.y + off[1] : p.y, r: node.r };
            });
            var framed = viewport.frameOf(points, padding, maxZoom);
            if (framed) viewport.setTransform(framed.k, framed.x, framed.y);
        };

        /** Put a node in the middle without changing the zoom. */
        viewport.centreOn = function (node) {
            if (!node) return;
            var p = positionOf(node);
            transform.x = renderer.width / 2 - p.x * transform.k;
            transform.y = renderer.height / 2 - p.y * transform.k;
            scheduleDraw();
            onChange();
        };

        viewport.setTransform = function (k, x, y) {
            transform.k = clamp(k, MIN_ZOOM, MAX_ZOOM);
            transform.x = x;
            transform.y = y;
            scheduleDraw();
            onChange();
        };

        /* ------------------------------------------------------ pointers -- */

        function pointerList() {
            return Object.keys(pointers).map(function (id) { return pointers[id]; });
        }

        function beginPinch() {
            var both = pointerList();
            if (both.length < 2) return;
            mode = 'pinch';
            dragNode = null;
            pinch = {
                distance: Math.hypot(both[0].x - both[1].x, both[0].y - both[1].y) || 1,
                cx: (both[0].x + both[1].x) / 2,
                cy: (both[0].y + both[1].y) / 2,
                k: transform.k
            };
        }

        function onPointerDown(event) {
            var point = localPoint(event);
            coarse = event.pointerType === 'touch' || event.pointerType === 'pen';
            if (!pointers[event.pointerId]) pointerCount++;
            pointers[event.pointerId] = point;

            if (pointerCount >= 2) {
                beginPinch();
                return;
            }

            canvas.setPointerCapture(event.pointerId);
            moved = 0;
            dragNode = nodeAt(point.x, point.y);
            mode = dragNode ? 'drag' : 'pan';
            canvas.style.cursor = dragNode ? 'grabbing' : 'grabbing';
        }

        function onPointerMove(event) {
            var point = localPoint(event);
            var previous = pointers[event.pointerId];

            if (!previous) {
                /* No button held: this is a hover. */
                if (!mode) {
                    var over = nodeAt(point.x, point.y);
                    setHover(over ? over.id : null);
                }
                return;
            }

            var dx = point.x - previous.x;
            var dy = point.y - previous.y;
            pointers[event.pointerId] = point;
            moved += Math.abs(dx) + Math.abs(dy);

            if (mode === 'pinch') {
                var both = pointerList();
                if (both.length < 2 || !pinch) return;
                var distance = Math.hypot(both[0].x - both[1].x, both[0].y - both[1].y) || 1;
                var cx = (both[0].x + both[1].x) / 2;
                var cy = (both[0].y + both[1].y) / 2;
                /* Pan by the midpoint's travel, then zoom about where it now
                 * is, so a two-finger drag that also spreads does both. */
                transform.x += cx - pinch.cx;
                transform.y += cy - pinch.cy;
                pinch.cx = cx;
                pinch.cy = cy;
                zoomAbout(pinch.k * (distance / pinch.distance), cx, cy);
                scheduleDraw();
                return;
            }

            if (mode === 'drag' && dragNode) {
                /* Writes through to whichever coordinates are on screen: the
                 * node's own on the whole map, where this also pins it for
                 * the session, or the focused view's, where the settled
                 * layout underneath is left alone. */
                var live = positionOf(dragNode);
                live.x += dx / transform.k;
                live.y += dy / transform.k;
                dragNode.pinned = true;
                scheduleDraw();
                return;
            }

            if (mode === 'pan') {
                transform.x += dx;
                transform.y += dy;
                scheduleDraw();
                onChange();
            }
        }

        function onPointerUp(event) {
            var point = pointers[event.pointerId] || localPoint(event);
            if (pointers[event.pointerId]) {
                delete pointers[event.pointerId];
                pointerCount = Math.max(0, pointerCount - 1);
            }
            if (canvas.hasPointerCapture && canvas.hasPointerCapture(event.pointerId)) {
                canvas.releasePointerCapture(event.pointerId);
            }

            if (mode === 'pinch') {
                /* A finger lifted mid-pinch: whatever is left starts a fresh
                 * pan rather than inheriting the pinch's midpoint. */
                pinch = null;
                mode = pointerCount ? 'pan' : null;
                if (!pointerCount) canvas.style.cursor = 'grab';
                return;
            }

            var wasDrag = mode === 'drag';
            var target = dragNode;
            mode = null;
            dragNode = null;
            pinch = null;
            canvas.style.cursor = hoverId ? 'pointer' : 'grab';

            if (moved <= CLICK_SLOP) {
                var hit = target || nodeAt(point.x, point.y);
                if (hit) onSelect(hit, event);
            } else if (wasDrag) {
                /* Positions moved, so the index over them is stale. */
                rebuildTree();
            }
        }

        function onPointerLeave() {
            if (!mode) setHover(null);
        }

        function onWheel(event) {
            event.preventDefault();
            var point = localPoint(event);
            /* deltaMode 1 is lines, 2 is pages; normalise both to pixels so a
             * Firefox wheel tick is not forty times a Chrome one. */
            var delta = event.deltaY * (event.deltaMode === 1 ? 16 : (event.deltaMode === 2 ? 400 : 1));
            zoomAbout(transform.k * Math.exp(-delta * WHEEL_GAIN), point.x, point.y);
        }

        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', onPointerUp);
        canvas.addEventListener('pointercancel', onPointerUp);
        canvas.addEventListener('pointerleave', onPointerLeave);
        function onDoubleClick(event) {
            event.preventDefault();
            var point = localPoint(event);
            /* Shift is the usual "and back out again" on a map. */
            var factor = event.shiftKey ? 1 / DOUBLE_STEP : DOUBLE_STEP;
            zoomAbout(transform.k * factor, point.x, point.y);
        }

        canvas.addEventListener('wheel', onWheel, { passive: false });
        canvas.addEventListener('dblclick', onDoubleClick);
        canvas.style.cursor = 'grab';

        viewport.destroy = function () {
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('pointercancel', onPointerUp);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            canvas.removeEventListener('wheel', onWheel);
            canvas.removeEventListener('dblclick', onDoubleClick);
            if (frame) root.cancelAnimationFrame(frame);
        };

        return viewport;
    }

    root.KOPNetworkViewport = { create: create, MIN_ZOOM: MIN_ZOOM, MAX_ZOOM: MAX_ZOOM };
})(typeof self !== 'undefined' ? self : this);
