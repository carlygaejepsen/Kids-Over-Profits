/**
 * Network map: renderer.
 *
 * Drawing only. It knows nothing about pointers, filters or selection; it is
 * handed a scene (flat arrays of nodes and edges from the store), a
 * transform, and an emphasis object, and it paints.
 *
 * World coordinates are projected by hand rather than by transforming the
 * context, because the two things that would otherwise fight us are stroke
 * width and font size: both have to stay constant on screen while the map
 * zooms from 0.1 to 6. Projecting per node also gives the hover gather in
 * step 4 a natural place to add a display offset without touching the stored
 * positions.
 *
 * Edges are bucketed by style once per scene, so a frame is a dozen
 * beginPath calls rather than thirteen hundred style changes.
 */
(function (root) {
    'use strict';

    var SURFACE = '#F2EEDF'; /* --kop-sand, matching the stage background. */
    var INK = 'rgba(0, 4, 53, ';
    var FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

    /* Colour mode one: what a node is. Teal, navy and orange carry the three
     * kinds that make up 96% of the map; trade groups get a chartreuse
     * outline on sand rather than a fill, and the long tail is grey, nudged
     * apart just enough to tell a state agency from a church. */
    var KIND_COLOURS = {
        person: '#33A7B5',
        facility: '#000080',
        parent: '#EF9034',
        association: '#F2EEDF',
        government: '#4A5568',
        church: '#7D7A6B',
        other: '#9A9A9A'
    };
    var KIND_OUTLINE = {
        association: '#B2E102'
    };

    /* Colour mode two: who owns it. Indexed by position in meta.chains, so a
     * chain keeps its colour when the view is filtered. Ten chains exist
     * today; the list runs to twelve so a board export can add two before
     * anyone has to think about it. Everything with no recorded owner is
     * grey, which is most of the map and should read as unremarkable. */
    var CHAIN_COLOURS = [
        '#000080', '#33A7B5', '#EF9034', '#FE8088', '#FC8ED6', '#AEE0ED',
        '#B6E3D4', '#ECF385', '#000435', '#B2E102', '#8C6239', '#5B7FA6'
    ];
    var CHAIN_NONE = '#A8A294';

    /* Edge styling.
     *
     * The board records three things about a connection that the eye should
     * be able to read without opening anything: what kind of relationship it
     * was, which way it ran, and whether it joined two people.
     *
     * Direction outranks category, because "became" and "acquired" are the
     * two statements on this map that are wrong if you read them backwards.
     * Both carry an arrowhead and their own colour: a rebrand is one
     * programme continuing under another name, an acquisition is one company
     * taking another, and a visitor should not have to work out which from a
     * grey line. Every other connection is undirected and has no arrow,
     * which is itself the honest signal that the record does not say who
     * came first.
     */
    var EDGE_STYLES = {
        /* X became Y. Teal, because it is a continuation rather than a
         * transaction, and the same teal the map uses for people is not in
         * play between two organisations. */
        rebrand:    { colour: '#1E7F8C', width: 2.2, dash: null, arrow: true, label: 'Became' },
        /* X acquired Y. */
        acquired:   { colour: '#C96A12', width: 2.2, dash: null, arrow: true, label: 'Acquired' },
        corporate:  { colour: 'rgba(0, 4, 53, 0.72)', width: 1.8, dash: null, label: 'Ownership' },
        /* Married, divorced, siblings: the only edges on the map that join
         * two people to each other rather than a person to a programme, and
         * the ones a reader is most likely to be looking for. */
        family:     { colour: '#D6455A', width: 2, dash: [6, 3], label: 'Family' },
        survivor:   { colour: '#B5359B', width: 1.8, dash: null, label: 'Survivor account' },
        board:      { colour: 'rgba(0, 4, 53, 0.55)', width: 1.4, dash: [2, 2], label: 'Board member' },
        leadership: { colour: 'rgba(0, 4, 53, 0.52)', width: 1.4, dash: null, label: 'Leadership' },
        clinical:   { colour: 'rgba(0, 64, 96, 0.46)', width: 1.2, dash: null, label: 'Clinical staff' },
        referral:   { colour: 'rgba(120, 70, 0, 0.5)', width: 1.3, dash: [4, 3], label: 'Referral' },
        unknown:    { colour: 'rgba(0, 4, 53, 0.34)', width: 1, dash: [1, 3], label: 'Unrecorded' },
        admissions: { colour: 'rgba(0, 4, 53, 0.44)', width: 1.15, dash: [5, 2], label: 'Admissions' },
        staff:      { colour: 'rgba(0, 4, 53, 0.42)', width: 1.15, dash: null, label: 'Other staff' },
        /* "Other" would read as the node kind of the same name in the key. */
        _default:   { colour: 'rgba(0, 4, 53, 0.42)', width: 1.15, dash: null, label: 'Other connection' }
    };

    var CROSS_STYLE = { colour: '#EF9034', width: 1.8, dash: null };

    /* Labels. Everything on the map is named.
     *
     * That used to be reckless, when the map drew all nine hundred nodes and
     * a degree threshold was the only thing keeping the names readable. It
     * is now the only honest rule: what is on screen is a handful of
     * organisations the map opened with plus whatever the visitor has opened
     * since, so every one of them is there because somebody asked for it,
     * and an unnamed dot is no use to the person who asked.
     *
     * Collision is what limits the count instead. Where two names cannot
     * both fit, the better-connected one wins and the other is dropped
     * rather than smeared over it.
     */
    var LABEL_SIZE = 11.5;
    var LABEL_SIZE_HOVER = 13;
    var LABEL_LINE = 13;
    /* Bucket size for the collision grid, in screen pixels. */
    var LABEL_CELL = 48;
    /* Breathing room around each label's box. Boxes that merely touch still
     * read as one another's neighbours, so the gap is part of the rule. */
    var LABEL_PAD_X = 5;
    var LABEL_PAD_Y = 3;

    /* Edges still pull back a little at a wide view, but only a little.
     * They used to fade hard, which was the right answer when the map drew
     * all thirteen hundred of them at once; now that the level of detail
     * keeps the count down, a faint line is just a relationship nobody can
     * see, and the relationships are the point of the map. */
    var EDGE_FADE_MIN = 0.72;
    var EDGE_FADE_FROM = 0.2;
    var EDGE_FADE_TO = 1;

    function edgeFadeFor(k) {
        if (k >= EDGE_FADE_TO) return 1;
        var t = (k - EDGE_FADE_FROM) / (EDGE_FADE_TO - EDGE_FADE_FROM);
        if (t < 0) t = 0;
        return EDGE_FADE_MIN + (1 - EDGE_FADE_MIN) * t;
    }

    /* Relative luminance of a hex colour, memoised. Used to decide how hard
     * a node's outline has to work: navy needs almost none, pale spring
     * yellow sits close enough to the sand background to need a firm one. */
    var luminanceCache = Object.create(null);
    function luminance(hex) {
        if (luminanceCache[hex] !== undefined) return luminanceCache[hex];
        var m = /^#([0-9a-f]{6})$/i.exec(hex);
        var value = 0.5;
        if (m) {
            var n = parseInt(m[1], 16);
            value = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255;
        }
        return (luminanceCache[hex] = value);
    }

    /* Which way a connection runs is the first thing about it, so it is the
     * first thing checked. */
    function styleFor(edge, crossRegion) {
        if (crossRegion) return CROSS_STYLE;
        if (edge.direction === 'renamed') return EDGE_STYLES.rebrand;
        if (edge.direction === 'acquirer') return EDGE_STYLES.acquired;
        return EDGE_STYLES[edge.category] || EDGE_STYLES._default;
    }

    function styleKey(style) {
        return style.colour + '|' + style.width + '|' + (style.dash ? style.dash.join(',') : '') +
            '|' + (style.arrow ? 'a' : '');
    }

    /**
     * A head at the target end, set back so it sits against the node rather
     * than under it. Drawn per edge rather than batched, which is affordable
     * because only the hundred-odd directed edges have one.
     */
    function drawArrow(ctx, ax, ay, bx, by, backoff, size) {
        var dx = bx - ax;
        var dy = by - ay;
        var len = Math.hypot(dx, dy);
        if (!len) return;
        var ux = dx / len;
        var uy = dy / len;
        var tipX = bx - ux * backoff;
        var tipY = by - uy * backoff;
        var baseX = tipX - ux * size;
        var baseY = tipY - uy * size;
        var wing = size * 0.5;
        ctx.beginPath();
        ctx.moveTo(tipX, tipY);
        ctx.lineTo(baseX - uy * wing, baseY + ux * wing);
        ctx.lineTo(baseX + uy * wing, baseY - ux * wing);
        ctx.closePath();
        ctx.fill();
    }

    /* Node outlines: shape traced at the origin, caller has translated. */
    function traceShape(ctx, kind, x, y, r) {
        var i, a;
        switch (kind) {
            case 'facility': {
                /* Rounded square, area roughly matched to a circle of r. */
                var s = r * 1.78;
                var half = s / 2;
                var rad = Math.min(r * 0.32, half);
                ctx.moveTo(x - half + rad, y - half);
                ctx.lineTo(x + half - rad, y - half);
                ctx.quadraticCurveTo(x + half, y - half, x + half, y - half + rad);
                ctx.lineTo(x + half, y + half - rad);
                ctx.quadraticCurveTo(x + half, y + half, x + half - rad, y + half);
                ctx.lineTo(x - half + rad, y + half);
                ctx.quadraticCurveTo(x - half, y + half, x - half, y + half - rad);
                ctx.lineTo(x - half, y - half + rad);
                ctx.quadraticCurveTo(x - half, y - half, x - half + rad, y - half);
                break;
            }
            case 'parent': {
                var d = r * 1.3;
                ctx.moveTo(x, y - d);
                ctx.lineTo(x + d, y);
                ctx.lineTo(x, y + d);
                ctx.lineTo(x - d, y);
                break;
            }
            case 'association': {
                var h = r * 1.16;
                for (i = 0; i < 6; i++) {
                    a = (Math.PI / 3) * i - Math.PI / 2;
                    ctx[i ? 'lineTo' : 'moveTo'](x + h * Math.cos(a), y + h * Math.sin(a));
                }
                break;
            }
            case 'government':
            case 'church': {
                var t = r * 1.42;
                for (i = 0; i < 3; i++) {
                    a = (2 * Math.PI / 3) * i - Math.PI / 2;
                    ctx[i ? 'lineTo' : 'moveTo'](x + t * Math.cos(a), y + t * Math.sin(a));
                }
                break;
            }
            default:
                ctx.moveTo(x + r, y);
                ctx.arc(x, y, r, 0, Math.PI * 2);
                return;
        }
        ctx.closePath();
    }

    /**
     * How firmly a node's outline has to work. Navy needs almost none; pale
     * spring yellow sits close enough to the sand background to need a firm
     * one. Trade groups are the exception: a chartreuse outline on sand is
     * how that kind is drawn at all.
     */
    function outlineFor(kind, fill, byKind) {
        if (byKind && KIND_OUTLINE[kind]) return KIND_OUTLINE[kind];
        return INK + (luminance(fill) > 0.62 ? '0.72)' : '0.45)');
    }

    /**
     * One node, at one place, at one size. The map and the legend both come
     * through here, so a swatch cannot drift from the thing it describes.
     *
     * spec is mutated and reused by the draw loop rather than allocated per
     * node, so it must not be held on to.
     */
    function paintNode(ctx, spec, x, y, r, alpha) {
        ctx.beginPath();
        traceShape(ctx, spec.kind, x, y, r);

        if (spec.status === 'closed') {
            /* Hollow: closed or rebranded. */
            ctx.globalAlpha = alpha;
            ctx.fillStyle = SURFACE;
            ctx.fill();
            ctx.strokeStyle = spec.fill === SURFACE
                ? (KIND_OUTLINE[spec.kind] || INK + '0.6)')
                : spec.fill;
            ctx.lineWidth = 2;
            ctx.stroke();
        } else {
            /* Open solid; status unrecorded the same shape at 55%, so "we do
             * not know" reads as faded rather than as closed. */
            ctx.globalAlpha = alpha * (spec.status === 'unknown' ? 0.55 : 1);
            ctx.fillStyle = spec.fill;
            ctx.fill();
            ctx.globalAlpha = alpha;
            ctx.strokeStyle = spec.outline;
            ctx.lineWidth = 1;
            ctx.stroke();
        }

        /* NATSAP membership: a thin chartreuse ring outside the shape, so it
         * reads on a filled and a hollow node alike. */
        if (spec.natsap) {
            ctx.beginPath();
            ctx.arc(x, y, r + 2.5, 0, Math.PI * 2);
            ctx.strokeStyle = '#B2E102';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        }

        ctx.globalAlpha = 1;
    }

    /**
     * Paint one legend swatch into its own small canvas. Same shapes, same
     * status marks, same NATSAP ring as the map itself.
     */
    function swatch(element, spec) {
        if (!element || !element.getContext) return;
        var dpr = Math.min(root.devicePixelRatio || 1, 2);
        var size = 18;
        element.width = Math.round(size * dpr);
        element.height = Math.round(size * dpr);
        var ctx = element.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, size, size);
        paintNode(ctx, {
            kind: spec.kind || 'person',
            status: spec.status || 'open',
            natsap: !!spec.natsap,
            fill: spec.fill,
            outline: spec.outline || outlineFor(spec.kind, spec.fill, spec.byKind)
        }, size / 2, size / 2, 6, 1);
    }

    /* A uniform grid over the placed label boxes. Labels cluster, so a
     * straight pairwise check would be quadratic in the worst view; this
     * only ever compares against boxes in the same neighbourhood. */
    function gridCells(box, visit) {
        var x0 = Math.floor(box[0] / LABEL_CELL);
        var x1 = Math.floor(box[2] / LABEL_CELL);
        var y0 = Math.floor(box[1] / LABEL_CELL);
        var y1 = Math.floor(box[3] / LABEL_CELL);
        for (var cx = x0; cx <= x1; cx++) {
            for (var cy = y0; cy <= y1; cy++) {
                if (visit(cx + ',' + cy)) return true;
            }
        }
        return false;
    }

    function fitsInGrid(grid, box) {
        return !gridCells(box, function (key) {
            var bucket = grid[key];
            if (!bucket) return false;
            for (var i = 0; i < bucket.length; i++) {
                var other = bucket[i];
                if (box[0] < other[2] && box[2] > other[0] &&
                    box[1] < other[3] && box[3] > other[1]) return true;
            }
            return false;
        });
    }

    function occupyGrid(grid, box) {
        gridCells(box, function (key) {
            (grid[key] = grid[key] || []).push(box);
            return false;
        });
    }

    /**
     * A short length of line in a legend row, drawn with the same style the
     * map uses, arrowhead and all. A key that guesses at its own colours is
     * worse than none.
     */
    function edgeSwatch(element, style) {
        if (!element || !element.getContext) return;
        var dpr = Math.min(root.devicePixelRatio || 1, 2);
        var w = 26;
        var h = 18;
        element.width = Math.round(w * dpr);
        element.height = Math.round(h * dpr);
        var ctx = element.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        ctx.strokeStyle = style.colour;
        ctx.lineWidth = style.width;
        ctx.setLineDash(style.dash || []);
        ctx.beginPath();
        ctx.moveTo(1, h / 2);
        ctx.lineTo(style.arrow ? w - 8 : w - 1, h / 2);
        ctx.stroke();
        ctx.setLineDash([]);
        if (style.arrow) {
            ctx.fillStyle = style.colour;
            drawArrow(ctx, 1, h / 2, w - 1, h / 2, 0, 8);
        }
    }

    function create(canvas) {
        var ctx = canvas.getContext('2d');

        var renderer = {
            /* Shared with the viewport, which owns panning and zooming and
             * mutates this object in place. */
            transform: { k: 1, x: 0, y: 0 },
            colourMode: 'kind',
            crossRegionMode: false,
            /* Set by focus.js.
             *   hoverId    the node under the pointer, ringed and labelled
             *   near       id set that stays lit; everything else drops to dim
             *   nearEdges  edge id set that stays lit
             *   dim        alpha for everything outside near, 0.15 by default
             *   offsets    id to [dx, dy] world-space display offset: the
             *              hover gather, which never touches stored positions
             */
            emphasis: { hoverId: null },
            width: 0,
            height: 0,
            dpr: 1
        };

        var scene = { nodes: [], edges: [] };
        var buckets = [];
        var chainIndex = null;
        var frameStamp = 0;
        /* Reused by the draw loop so a frame does not allocate one spec per
         * node; paintNode never holds on to it. */
        var scratch = { kind: '', status: '', natsap: false, fill: '', outline: '' };

        /** The store's chain-to-index map, so colour mode two can be resolved. */
        renderer.useChainIndex = function (index) {
            chainIndex = index;
        };

        /**
         * Where a node currently lives, in world coordinates. The whole map
         * answers with the node itself, since the settled layout is what is
         * on screen; a focused neighbourhood answers with its own re-settled
         * position, which is why this is a function and not a field. The
         * returned object is the live one, so a drag can write to it.
         */
        var positionOf = function (node) { return node; };
        renderer.setPositionSource = function (fn) {
            positionOf = fn || function (node) { return node; };
        };

        renderer.setScene = function (next) {
            scene = next || { nodes: [], edges: [] };
            rebuildBuckets();
        };

        renderer.setColourMode = function (mode) {
            renderer.colourMode = mode === 'chain' ? 'chain' : 'kind';
        };

        /* Only affects how edges are drawn; the store decides which ones
         * survive the filter. */
        renderer.setCrossRegionMode = function (on) {
            renderer.crossRegionMode = !!on;
            rebuildBuckets();
        };

        renderer.setEmphasis = function (next) {
            renderer.emphasis = next || { hoverId: null };
        };

        function rebuildBuckets() {
            var byKey = Object.create(null);
            buckets = [];
            for (var i = 0; i < scene.edges.length; i++) {
                var edge = scene.edges[i];
                var style = styleFor(edge, renderer.crossRegionMode);
                var key = styleKey(style);
                var bucket = byKey[key];
                if (!bucket) {
                    bucket = byKey[key] = { style: style, edges: [] };
                    buckets.push(bucket);
                }
                bucket.edges.push(edge);
            }
            /* Thin and faint first, so an ownership line is never hidden
             * under an unrecorded one. */
            buckets.sort(function (a, b) { return a.style.width - b.style.width; });
        }

        /* --------------------------------------------------------- sizing -- */

        /**
         * Match the backing store to the CSS box and the device pixel ratio.
         * Returns true when anything changed, so the caller knows to redraw.
         */
        renderer.resize = function () {
            var rect = canvas.getBoundingClientRect();
            var dpr = Math.min(root.devicePixelRatio || 1, 2);
            var w = Math.max(1, Math.round(rect.width));
            var h = Math.max(1, Math.round(rect.height));
            if (w === renderer.width && h === renderer.height && dpr === renderer.dpr) return false;
            renderer.width = w;
            renderer.height = h;
            renderer.dpr = dpr;
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
            return true;
        };

        /* -------------------------------------------------------- colours -- */

        renderer.colourFor = function (node) {
            if (renderer.colourMode === 'chain') {
                if (!node.chain || !chainIndex) return CHAIN_NONE;
                var i = chainIndex[node.chain];
                if (i === undefined) return CHAIN_NONE;
                return CHAIN_COLOURS[i % CHAIN_COLOURS.length];
            }
            return KIND_COLOURS[node.kind] || KIND_COLOURS.other;
        };


        /* ------------------------------------------------------- painting -- */

        renderer.draw = function () {
            var t = renderer.transform;
            var k = t.k;
            var w = renderer.width;
            var h = renderer.height;

            ctx.setTransform(renderer.dpr, 0, 0, renderer.dpr, 0, 0);
            ctx.clearRect(0, 0, w, h);

            if (!scene.nodes.length) return;

            var emphasis = renderer.emphasis || {};
            var hoverId = emphasis.hoverId || null;
            var near = emphasis.near || null;
            var nearEdges = emphasis.nearEdges || null;
            var offsets = emphasis.offsets || null;
            var dim = emphasis.dim === undefined ? 0.15 : emphasis.dim;
            var i, node;

            /* Screen positions once per frame, reused by edges, nodes and
             * labels. Nodes carry their index so an edge can find its ends
             * without a lookup table; the frame stamp is what makes a stale
             * index from an earlier scene detectable rather than silently
             * pointing at the wrong node. */
            frameStamp++;
            var sx = new Float64Array(scene.nodes.length);
            var sy = new Float64Array(scene.nodes.length);
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                node._i = i;
                node._frame = frameStamp;
                var p = positionOf(node);
                var off = offsets ? offsets[node.id] : null;
                sx[i] = (off ? p.x + off[0] : p.x) * k + t.x;
                sy[i] = (off ? p.y + off[1] : p.y) * k + t.y;
            }

            var edgeFade = edgeFadeFor(k);

            /* --- edges ---
             *
             * Each style bucket is stroked once at full alpha and once dimmed,
             * rather than per edge, so hover costs one extra path per style
             * instead of thirteen hundred alpha changes. With no emphasis set
             * the dim pass is skipped and this is the step 3 single pass. */
            var pad = 64;
            for (var b = 0; b < buckets.length; b++) {
                var style = buckets[b].style;
                var list = buckets[b].edges;
                ctx.strokeStyle = style.colour;
                ctx.lineWidth = style.width;
                ctx.setLineDash(style.dash || []);

                for (var pass = 0; pass < 2; pass++) {
                    var lit = pass === 0;
                    if (!lit && !near) break;
                    /* A hovered node's own connections stay at full strength
                     * however far out the map is: they are the answer to the
                     * question the pointer just asked. */
                    ctx.globalAlpha = near ? (lit ? 1 : dim * edgeFade) : edgeFade;
                    ctx.beginPath();
                    var drew = false;
                    for (i = 0; i < list.length; i++) {
                        var edge = list[i];
                        if (edge.source._frame !== frameStamp || edge.target._frame !== frameStamp) continue;
                        if (near) {
                            /* An edge is lit when it is one of the hovered
                             * node's own connections, not merely when both its
                             * ends happen to be lit: a line between two
                             * neighbours is not what was hovered. */
                            var isLit = nearEdges ? !!nearEdges[edge.id]
                                : (!!near[edge.sourceId] && !!near[edge.targetId]);
                            if (isLit !== lit) continue;
                        }
                        var a = edge.source._i;
                        var c = edge.target._i;
                        var ax = sx[a], ay = sy[a], cx = sx[c], cy = sy[c];
                        /* Both ends off the same side means the line cannot
                         * cross the viewport, which is most of them zoomed in. */
                        if ((ax < -pad && cx < -pad) || (ax > w + pad && cx > w + pad)) continue;
                        if ((ay < -pad && cy < -pad) || (ay > h + pad && cy > h + pad)) continue;
                        ctx.moveTo(ax, ay);
                        ctx.lineTo(cx, cy);
                        drew = true;
                    }
                    if (drew) ctx.stroke();
                }
            }
            ctx.setLineDash([]);

            /* --- direction ---
             *
             * "Became" and "acquired" are the two statements on this map
             * that are wrong if you read them backwards, so they get a head
             * at the target end. Everything else is undirected and has
             * none, which is the honest signal that the record does not say
             * who came first. */
            for (b = 0; b < buckets.length; b++) {
                if (!buckets[b].style.arrow) continue;
                ctx.fillStyle = buckets[b].style.colour;
                var arrows = buckets[b].edges;
                for (i = 0; i < arrows.length; i++) {
                    var directed = arrows[i];
                    if (directed.source._frame !== frameStamp || directed.target._frame !== frameStamp) continue;
                    var si = directed.source._i;
                    var ti = directed.target._i;
                    if (sx[ti] < -pad || sx[ti] > w + pad || sy[ti] < -pad || sy[ti] > h + pad) continue;
                    ctx.globalAlpha = near ? (nearEdges && nearEdges[directed.id] ? 1 : dim * edgeFade) : edgeFade;
                    drawArrow(ctx, sx[si], sy[si], sx[ti], sy[ti],
                        Math.max(2.5, directed.target.r * k) + 1.5,
                        Math.max(5, Math.min(11, 7 * Math.sqrt(k))));
                }
            }
            ctx.globalAlpha = 1;

            /* --- nodes --- */
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                /* A node is a mark on a map, not a shape to get lost in.
                 * Below about two and a half pixels it stops reading as a
                 * shape at all; above twenty-two it stops being a mark and
                 * starts being a picture of a diamond, which is what the
                 * opening view of six organisations would otherwise draw. */
                var r = Math.min(22, Math.max(2.5, node.r * k));
                var x = sx[i], y = sy[i];
                if (x + r < 0 || x - r > w || y + r < 0 || y - r > h) continue;

                var fill = renderer.colourFor(node);
                var hovered = node.id === hoverId;
                /* Dimming is a multiplier, so a status-unrecorded node that is
                 * also off the neighbourhood ends up fainter than either rule
                 * would make it alone, which is the right reading of both. */
                var lit = !near || !!near[node.id];

                scratch.kind = node.kind;
                scratch.status = node.status;
                scratch.natsap = node.natsap;
                scratch.fill = fill;
                scratch.outline = outlineFor(node.kind, fill, renderer.colourMode === 'kind');
                paintNode(ctx, scratch, x, y, r, lit ? 1 : dim);

                if (hovered) {
                    ctx.beginPath();
                    ctx.arc(x, y, r + 5, 0, Math.PI * 2);
                    ctx.strokeStyle = '#000435';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }
            }

            /* --- labels ---
             *
             * Two passes, not one per label. Each label strokes a halo in the
             * surface colour before filling its text, and drawing them one at
             * a time means the next label's halo paints over the last one's
             * text: in a gathered neighbourhood, where names land close
             * together, labels visibly disappear. Every halo is laid down
             * first, then every glyph on top.
             *
             * Which labels, and in what order, is decided before either pass.
             */
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.lineJoin = 'round';

            var candidates = [];
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                var isHover = node.id === hoverId;
                /* With a neighbourhood lit, its names are the whole point and
                 * everything else is background: labelling the dimmed nodes
                 * too would bury the answer in the thing it was picked out
                 * of. */
                if (near && !near[node.id]) continue;
                var lx = sx[i];
                var ly = sy[i] + Math.max(1.5, node.r * k) + 3;
                if (lx < -140 || lx > w + 140 || ly < -20 || ly > h + 20) continue;
                candidates.push({ node: node, x: lx, y: ly, hover: isHover });
            }

            /* Most connected first, so when two labels cannot both fit it is
             * the smaller name that goes. The hovered node outranks
             * everything. */
            candidates.sort(function (a, b) {
                if (a.hover !== b.hover) return a.hover ? -1 : 1;
                return b.node.degree - a.node.degree;
            });

            var grid = Object.create(null);
            var drawn = [];
            for (i = 0; i < candidates.length; i++) {
                var entry = candidates[i];
                var size = entry.hover ? LABEL_SIZE_HOVER : LABEL_SIZE;
                var bold = entry.node.degree >= 8;
                entry.font = (bold ? '600 ' : '') + size + 'px ' + FONT;

                var half = textWidth(ctx, entry.node, entry.font, size) / 2;
                var box = [
                    entry.x - half - LABEL_PAD_X,
                    entry.y - LABEL_PAD_Y,
                    entry.x + half + LABEL_PAD_X,
                    entry.y + LABEL_LINE + LABEL_PAD_Y
                ];

                /* Two names on top of each other are worse than one name: the
                 * pair is unreadable and neither can be trusted to belong to
                 * the node under it. A label that cannot fit is dropped, in
                 * a lit neighbourhood as much as anywhere else - the way to
                 * read every name in a cluster is to click it, which
                 * re-settles the neighbourhood with room for all of them. */
                if (!fitsInGrid(grid, box)) continue;
                occupyGrid(grid, box);
                drawn.push(entry);
            }

            ctx.strokeStyle = 'rgba(242, 238, 223, 0.92)';
            ctx.lineWidth = 3;
            for (i = 0; i < drawn.length; i++) {
                ctx.font = drawn[i].font;
                ctx.strokeText(drawn[i].node.name, drawn[i].x, drawn[i].y);
            }
            ctx.fillStyle = '#000435';
            for (i = 0; i < drawn.length; i++) {
                ctx.font = drawn[i].font;
                ctx.fillText(drawn[i].node.name, drawn[i].x, drawn[i].y);
            }
        };

        /* Measuring text is not free and a name never changes, so each node
         * carries its width at the base size and the other size is scaled
         * from it. */
        function textWidth(ctx2, node, font, size) {
            if (node._labelW === undefined) {
                ctx2.font = LABEL_SIZE + 'px ' + FONT;
                node._labelW = ctx2.measureText(node.name).width;
            }
            return node._labelW * (size / LABEL_SIZE);
        }

        return renderer;
    }

    root.KOPNetworkCanvas = {
        styleFor: styleFor,
        edgeSwatch: edgeSwatch,
        edgeFadeFor: edgeFadeFor,
        swatch: swatch,
        outlineFor: outlineFor,
        create: create,
        KIND_COLOURS: KIND_COLOURS,
        KIND_OUTLINE: KIND_OUTLINE,
        CHAIN_COLOURS: CHAIN_COLOURS,
        CHAIN_NONE: CHAIN_NONE,
        EDGE_STYLES: EDGE_STYLES,
        SURFACE: SURFACE
    };
})(typeof self !== 'undefined' ? self : this);
