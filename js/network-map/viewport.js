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
    /* How far from a node's edge still counts as hitting it. In CSS pixels,
     * so a small node stays tappable on a phone however far out the map is
     * zoomed. */
    var HIT_SLOP = 8;
    /* Movement under this many pixels between down and up is a click, not a
     * drag. Fingers wobble; mice do not. */
    var CLICK_SLOP = 4;

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

        var viewport = { transform: transform };

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

        function rebuildTree() {
            if (!root.d3 || !root.d3.quadtree) { tree = null; return; }
            tree = root.d3.quadtree()
                .x(function (d) { return d.x; })
                .y(function (d) { return d.y; })
                .addAll(scene.nodes);
        }
        viewport.rebuildTree = rebuildTree;

        /**
         * The node under a canvas-local point, or null. The quadtree finds the
         * nearest within a generous world-space radius; the shape test that
         * follows is a circle of the node's own radius plus the slop, which is
         * close enough for a diamond or a hexagon and much cheaper than the
         * real thing.
         */
        function nodeAt(px, py) {
            if (!tree || !scene.nodes.length) return null;
            var world = toWorld(px, py);
            var slop = HIT_SLOP / transform.k;
            var found = tree.find(world.x, world.y, maxRadius + slop);
            if (!found) return null;
            var dx = found.x - world.x;
            var dy = found.y - world.y;
            var reach = found.r + slop;
            return (dx * dx + dy * dy) <= reach * reach ? found : null;
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
         * Frame a set of nodes, or the whole scene. Used for Reset view and,
         * from step 4, for settling on a focused neighbourhood.
         */
        viewport.fit = function (nodes, padding) {
            var list = nodes && nodes.length ? nodes : scene.nodes;
            if (!list.length || !renderer.width) return;
            var pad = padding === undefined ? 48 : padding;

            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            for (var i = 0; i < list.length; i++) {
                var n = list[i];
                if (n.x - n.r < minX) minX = n.x - n.r;
                if (n.y - n.r < minY) minY = n.y - n.r;
                if (n.x + n.r > maxX) maxX = n.x + n.r;
                if (n.y + n.r > maxY) maxY = n.y + n.r;
            }
            var bw = Math.max(1, maxX - minX);
            var bh = Math.max(1, maxY - minY);
            var k = clamp(Math.min(
                (renderer.width - pad * 2) / bw,
                (renderer.height - pad * 2) / bh
            ), MIN_ZOOM, MAX_ZOOM);

            transform.k = k;
            transform.x = renderer.width / 2 - ((minX + maxX) / 2) * k;
            transform.y = renderer.height / 2 - ((minY + maxY) / 2) * k;
            scheduleDraw();
            onChange();
        };

        /** Put a node in the middle without changing the zoom. */
        viewport.centreOn = function (node) {
            if (!node) return;
            transform.x = renderer.width / 2 - node.x * transform.k;
            transform.y = renderer.height / 2 - node.y * transform.k;
            scheduleDraw();
            onChange();
        };

        viewport.setTransform = function (k, x, y) {
            transform.k = clamp(k, MIN_ZOOM, MAX_ZOOM);
            transform.x = x;
            transform.y = y;
            scheduleDraw();
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
                dragNode.x += dx / transform.k;
                dragNode.y += dy / transform.k;
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
            zoomAbout(transform.k * Math.exp(-delta * 0.0015), point.x, point.y);
        }

        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', onPointerUp);
        canvas.addEventListener('pointercancel', onPointerUp);
        canvas.addEventListener('pointerleave', onPointerLeave);
        canvas.addEventListener('wheel', onWheel, { passive: false });
        canvas.style.cursor = 'grab';

        viewport.destroy = function () {
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', onPointerUp);
            canvas.removeEventListener('pointercancel', onPointerUp);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            canvas.removeEventListener('wheel', onWheel);
            if (frame) root.cancelAnimationFrame(frame);
        };

        return viewport;
    }

    root.KOPNetworkViewport = { create: create, MIN_ZOOM: MIN_ZOOM, MAX_ZOOM: MAX_ZOOM };
})(typeof self !== 'undefined' ? self : this);
