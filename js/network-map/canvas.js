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

    /* Edge styling by category. width is in screen pixels and dash patterns
     * are scaled the same way, so a dashed line looks dashed at every zoom. */
    var EDGE_STYLES = {
        corporate:  { colour: INK + '0.55)', width: 1.7, dash: null },
        family:     { colour: INK + '0.5)',  width: 1.3, dash: [5, 3] },
        survivor:   { colour: '#FE8088',     width: 1.6, dash: null },
        unknown:    { colour: INK + '0.28)', width: 1.0, dash: [1, 3] },
        _default:   { colour: INK + '0.34)', width: 1.1, dash: null }
    };
    /* The staff-migration view: when the cross-group filter is on, the edges
     * that survive it are the point of the screen, so they are drawn in
     * orange whatever their category. */
    var CROSS_STYLE = { colour: '#EF9034', width: 1.8, dash: null };

    /* Labels. Hubs are named at every zoom; everything else fades in as the
     * map is zoomed into, so a wide view is readable and a close one is
     * informative. */
    var LABEL_ALWAYS_DEGREE = 8;
    var LABEL_ZOOM_MEDIUM = 1.15;
    var LABEL_ZOOM_ALL = 2.2;
    var LABEL_MEDIUM_DEGREE = 3;

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

    function styleFor(edge, crossRegion) {
        if (crossRegion) return CROSS_STYLE;
        return EDGE_STYLES[edge.category] || EDGE_STYLES._default;
    }

    function styleKey(style) {
        return style.colour + '|' + style.width + '|' + (style.dash ? style.dash.join(',') : '');
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
                    ctx.globalAlpha = lit ? 1 : dim;
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
            ctx.globalAlpha = 1;
            ctx.setLineDash([]);

            /* --- nodes --- */
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                var r = Math.max(1.5, node.r * k);
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

            /* --- labels --- */
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.lineJoin = 'round';
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                /* With a neighbourhood lit, its names are the whole point and
                 * everything else is background: labelling the dimmed nodes
                 * too would bury the answer in the thing it was picked out
                 * of. */
                if (near) {
                    if (!near[node.id]) continue;
                } else if (!labelVisible(node, k, hoverId)) {
                    continue;
                }
                var lx = sx[i];
                var ly = sy[i] + Math.max(1.5, node.r * k) + 3;
                if (lx < -120 || lx > w + 120 || ly < -20 || ly > h + 20) continue;

                var size = node.id === hoverId ? 13 : 11.5;
                ctx.font = (node.degree >= LABEL_ALWAYS_DEGREE ? '600 ' : '') + size + 'px ' + FONT;
                /* A halo in the surface colour rather than white, so the text
                 * sits on the map instead of on a white smudge. */
                ctx.strokeStyle = 'rgba(242, 238, 223, 0.9)';
                ctx.lineWidth = 3;
                ctx.strokeText(node.name, lx, ly);
                ctx.fillStyle = '#000435';
                ctx.fillText(node.name, lx, ly);
            }
        };

        function labelVisible(node, k, hoverId) {
            if (node.id === hoverId) return true;
            if (node.degree >= LABEL_ALWAYS_DEGREE) return true;
            if (k >= LABEL_ZOOM_ALL) return true;
            if (k >= LABEL_ZOOM_MEDIUM && node.degree >= LABEL_MEDIUM_DEGREE) return true;
            return false;
        }

        return renderer;
    }

    root.KOPNetworkCanvas = {
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
