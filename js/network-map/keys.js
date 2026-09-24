/**
 * Network map: the keyboard on the stage.
 *
 * The canvas is one focusable element standing for every name drawn on it,
 * so the keyboard needs a cursor of its own: the arrow keys move it from
 * name to name, Enter opens the name it is on, and the map says where it is
 * through the live region. The canvas's label had promised exactly this
 * since the page shell was written, with only Escape ever built.
 *
 * The cursor is the hover. Moving it onto a name does what a pointer resting
 * there does - the name and everything it connects to light up, the rest
 * dims - so a keyboard user gets the same preview before committing, and
 * there is no second highlight to draw or keep in step.
 *
 * The board is laid out in rows, and the arrows follow them. Left and right
 * walk along a row and carry on into the next one, in reading order, so the
 * two of them alone reach every name on the view: nearest-in-that-direction
 * for all four arrows left names stranded (Browning Distance Learning Academy
 * in WWASPS's view could not be reached from anywhere). Up and down go to the
 * nearest name above or below, which is what the eye expects of them.
 * A name a pan away is brought onto the stage when the cursor reaches it.
 *
 *   arrows      move between names          + / -   zoom
 *   Enter       open the name               0       reset the view
 *   Escape      start over (bound in app.js)
 *
 * The drawer lists every connection as a button, so the map was already
 * usable without a pointer; this makes the picture itself reachable.
 * nextInDirection() is DOM-free for scripts/test-network-modules.js.
 */
(function (root) {
    'use strict';

    var DIRECTIONS = {
        ArrowLeft: [-1, 0], Left: [-1, 0],
        ArrowRight: [1, 0], Right: [1, 0],
        ArrowUp: [0, -1], Up: [0, -1],
        ArrowDown: [0, 1], Down: [0, 1]
    };

    /* How much a step sideways of the arrow's direction costs against a step
     * along it. At 2, a name a row down and slightly across beats one far
     * along the same row only when it is much nearer. */
    var OFF_AXIS = 2;
    /* A name counts as "in that direction" once it is this far along it, in
     * pixels; names level with the cursor are not above or below it. */
    var ALONG_MIN = 4;


    /**
     * Where an arrow takes the cursor from `from`, or null at the edge of
     * the view. `dir` is one of the four [dx, dy] above; points are
     * {id, x, y} in screen pixels. Each arrow goes to the nearest name that
     * way; left and right, finding none, carry on into the next or the
     * previous row in reading order, and a name no arrow would land on is
     * taken in by the name nearest it, so between them the arrows reach every
     * name. Stepping through reading order alone was right for a board of
     * rows; on a cluster the next name in reading order can be anywhere,
     * and an arrow has to go where it points first.
     */
    function nextInDirection(points, from, dir) {
        return adopted(points, from, dir) || plainStep(points, from, dir);
    }

    function plainStep(points, from, dir) {
        var best = nearestThatWay(points, from, dir);
        if (best || dir[0] === 0) return best;
        var order = readingOrder(points);
        for (var i = 0; i < order.length; i++) {
            if (order[i].id === from.id) return order[i + dir[0]] || null;
        }
        return null;
    }

    var ARROWS = [[1, 0], [-1, 0], [0, -1], [0, 1]];

    /**
     * A name no arrow leads to - alone at the edge of a view, nearest to
     * nothing in any arrow's reach (Timothy P. Cole above YSI's programs) -
     * is taken in by the name closest to it, through the arrow that points
     * at it from there. Returns that name when `from` is its host and `dir`
     * is that arrow, else null.
     */
    function adopted(points, from, dir) {
        var led = Object.create(null);
        points.forEach(function (p) {
            ARROWS.forEach(function (arrow) {
                var hit = plainStep(points, p, arrow);
                if (hit) led[hit.id] = true;
            });
        });
        for (var i = 0; i < points.length; i++) {
            var orphan = points[i];
            if (orphan.id === from.id || led[orphan.id]) continue;
            var host = null;
            var hostDistance = Infinity;
            points.forEach(function (p) {
                if (p.id === orphan.id) return;
                var d = Math.hypot(p.x - orphan.x, p.y - orphan.y);
                if (d < hostDistance) { hostDistance = d; host = p; }
            });
            if (!host || host.id !== from.id) continue;
            var dx = orphan.x - from.x;
            var dy = orphan.y - from.y;
            var arrow = Math.abs(dx) >= Math.abs(dy) ? [dx > 0 ? 1 : -1, 0] : [0, dy > 0 ? 1 : -1];
            if (arrow[0] === dir[0] && arrow[1] === dir[1]) return orphan;
        }
        return null;
    }

    /* Names whose centres are within this many pixels of each other
     * vertically are on one row, for the carry-on. */
    var ROW_TOLERANCE = 8;

    /** The points in reading order: row by row, left to right. */
    function readingOrder(points) {
        var sorted = points.slice().sort(function (a, b) { return a.y - b.y || a.x - b.x; });
        var rows = [];
        sorted.forEach(function (p) {
            var row = rows[rows.length - 1];
            if (row && p.y - row.y <= ROW_TOLERANCE) row.points.push(p);
            else rows.push({ y: p.y, points: [p] });
        });
        var out = [];
        rows.forEach(function (row) {
            row.points.sort(function (a, b) { return a.x - b.x; });
            out = out.concat(row.points);
        });
        return out;
    }

    function nearestThatWay(points, from, dir) {
        var best = null;
        var bestScore = Infinity;
        points.forEach(function (p) {
            if (p.id === from.id) return;
            var dx = p.x - from.x;
            var dy = p.y - from.y;
            var along = dx * dir[0] + dy * dir[1];
            if (along < ALONG_MIN) return;
            var across = Math.abs(dx * dir[1]) + Math.abs(dy * dir[0]);
            /* Within a quarter-turn of an up or down arrow; past that it
             * is some other arrow's name. Left and right look narrower,
             * since a name on the row below is the carry-on's business. */
            if (across > along * (dir[0] ? 1 : 3)) return;
            var score = along + across * OFF_AXIS;
            if (score < bestScore) { bestScore = score; best = p; }
        });
        return best;
    }

    var KIND_WORDS = {
        facility: 'program', parent: 'company', person: 'person',
        association: 'trade group', church: 'church', government: 'government body'
    };

    function create(options) {
        var canvas = options.canvas;
        var focus = options.focus;
        var viewport = options.viewport;
        var renderer = options.renderer;
        var announce = options.announce || function () {};
        if (!canvas || !focus || !viewport || !renderer) return null;

        var cursorId = null;
        /* The head the cursor was last placed for: a new click starts the
         * cursor over on what was clicked. */
        var lastHead = null;

        function screenPoints(scene) {
            var t = viewport.transform;
            return scene.nodes.map(function (node) {
                var p = focus.positionOf(node);
                return { id: node.id, node: node, x: p.x * t.k + t.x, y: p.y * t.k + t.y };
            });
        }

        /* Where the cursor starts: what was opened last, or failing that the
         * name nearest the middle of the stage. */
        function startFrom(points, scene) {
            var trail = focus.chain();
            var headId = trail.length ? trail[trail.length - 1] : null;
            if (headId && scene.nodeIds[headId]) {
                return points.filter(function (p) { return p.id === headId; })[0];
            }
            var cx = renderer.width / 2, cy = renderer.height / 2;
            return points.slice().sort(function (a, b) {
                return Math.hypot(a.x - cx, a.y - cy) - Math.hypot(b.x - cx, b.y - cy);
            })[0] || null;
        }

        function describe(point, scene) {
            var node = point.node;
            var lines = 0;
            scene.edges.forEach(function (edge) {
                if (edge.sourceId === node.id || edge.targetId === node.id) lines++;
            });
            var more = scene.hidden && scene.hidden[node.id] ? scene.hidden[node.id] : 0;
            return node.name + ', ' + (KIND_WORDS[node.kind] || node.kind || 'name') +
                (node.years ? ', ' + node.years : '') + '. ' +
                lines + (lines === 1 ? ' connection' : ' connections') + ' on the map' +
                (more ? ', ' + more + ' more not shown' : '') + '. Press Enter to open.';
        }

        function moveTo(point, scene) {
            cursorId = point.id;
            /* A name a pan away comes onto the stage, at the same zoom. */
            var margin = 24;
            if (point.x < margin || point.x > renderer.width - margin ||
                point.y < margin || point.y > renderer.height - margin) {
                viewport.centreOn(point.node);
            }
            focus.hover(point.node);
            announce(describe(point, scene));
        }

        function onKey(event) {
            if (event.altKey || event.ctrlKey || event.metaKey) return;
            var key = event.key;
            var dir = DIRECTIONS[key];

            if (dir) {
                var scene = focus.scene();
                if (!scene.nodes.length) return;
                event.preventDefault();
                var points = screenPoints(scene);
                var trailNow = focus.chain();
                var headNow = trailNow.length ? trailNow[trailNow.length - 1] : null;
                if (headNow !== lastHead) { lastHead = headNow; cursorId = null; }
                var at = cursorId && scene.nodeIds[cursorId]
                    ? points.filter(function (p) { return p.id === cursorId; })[0] : null;
                if (!at) {
                    /* The first arrow puts the cursor down; it does not also move it. */
                    var first = startFrom(points, scene);
                    if (first) moveTo(first, scene);
                    return;
                }
                var next = nextInDirection(points, at, dir);
                if (next) moveTo(next, scene);
                else announce('No name further that way. ' + at.node.name + '.');
                return;
            }

            if (key === 'Enter' || key === ' ' || key === 'Spacebar') {
                var shown = focus.scene();
                if (!cursorId || !shown.nodeIds[cursorId]) return;
                event.preventDefault();
                var target = shown.nodes.filter(function (n) { return n.id === cursorId; })[0];
                focus.hover(null);
                focus.select(target);
                return;
            }

            if (key === '+' || key === '=') { event.preventDefault(); viewport.zoomBy(1.25); return; }
            if (key === '-' || key === '_') { event.preventDefault(); viewport.zoomBy(0.8); return; }
            if (key === '0') { event.preventDefault(); focus.reframe(); }
        }

        canvas.addEventListener('keydown', onKey);
        /* Leaving the stage puts the preview away, as a pointer leaving does. */
        canvas.addEventListener('blur', function () {
            if (cursorId) focus.hover(null);
        });

        return {
            cursor: function () { return cursorId; },
            key: onKey
        };
    }

    root.KOPNetworkKeys = { create: create, nextInDirection: nextInDirection };
})(typeof self !== 'undefined' ? self : this);
