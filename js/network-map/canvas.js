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
        membership: { colour: 'rgba(0, 4, 53, 0.5)', width: 1.3, dash: [8, 3], label: 'Member' },
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

    /* Where a label may sit, in the order it is tried. Below the node first,
     * because that is where a reader looks for it and where the row packer
     * leaves room; the other three are what a name falls back to rather than
     * being dropped. */
    var LABEL_PLACEMENTS = ['below', 'above', 'right', 'left'];
    var LABEL_PAD_Y = 3;
    /* The years of operation, a smaller second line under a name. */
    var YEARS_SIZE = 9.5;
    var YEARS_LINE = 11;

    /* What each line says, written on the line. Smaller and lighter than a
     * name, and italic, so a relationship never reads as another node. Not
     * drawn on a view with more lines than this: at the whole map two
     * thousand captions would bury everything they were meant to explain. */
    var EDGE_LABEL_SIZE = 9.5;
    var EDGE_LABEL_MAX = 400;
    var EDGE_LABEL_CHARS = 32;

    /**
     * The words to write on a line: the relationship as the board wrote it
     * ("cofounder/CEO", "rebrand"). A line the build added between two
     * places because somebody worked at both says who, since that person
     * is the connection. Nothing for a line the board left unlabelled: the
     * build files those as "affiliated", which is its reading, not the
     * board's words.
     */
    function edgeLabelText(edge) {
        var roles = edge.roles || [];
        var raw = String(edge.raw || '');
        var text = '';
        if ((edge.provenance === 'staff-list' || edge.provenance === 'staff-movement') &&
            (roles[0] === 'worked at both' || roles[0] === 'staff moved')) {
            text = raw.split('; ').map(function (part) {
                return part.split(/ \(| worked at | moved from /)[0];
            }).filter(Boolean).join(', ');
        } else if (edge.provenance) {
            text = roles.join(' / ');
        } else {
            text = raw;
        }
        text = text.replace(/\s+/g, ' ').trim();
        if (text.length > EDGE_LABEL_CHARS) text = text.slice(0, EDGE_LABEL_CHARS - 1).trim() + '\u2026';
        return text;
    }

    /* The memorial ring. Not the coral accent: this is a warning. */
    var DEATH_RED = '#B00020';

    /* The "+N" pill on a node with connections off screen. */
    var BADGE_SIZE = 9;

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
    /* The categories that keep their own colour whatever company they sit
     * in: a rebrand or a takeover has to read as one, and so does a family
     * tie or a survivor's account. The rest are drawn in the colour the
     * board gives the company, with their own width and dash, so the key
     * still tells the kinds of connection apart. */
    var OWN_COLOUR = { rebrand: true, acquired: true, family: true, survivor: true };
    var companyStyles = Object.create(null);

    /* colourOf(edge) is the board colour the line should carry, or ''. */
    function styleFor(edge, crossRegion, colourOf) {
        if (crossRegion) return CROSS_STYLE;
        if (edge.direction === 'renamed') return EDGE_STYLES.rebrand;
        if (edge.direction === 'acquirer') return EDGE_STYLES.acquired;
        var base = EDGE_STYLES[edge.category] || EDGE_STYLES._default;
        if (OWN_COLOUR[edge.category] || !colourOf) return base;
        var colour = colourOf(edge);
        if (!colour) return base;
        var key = (edge.category || '_default') + '|' + colour;
        if (!companyStyles[key]) {
            companyStyles[key] = {
                colour: colour, width: Math.max(1.4, base.width), dash: base.dash,
                arrow: base.arrow, label: base.label
            };
        }
        return companyStyles[key];
    }

    function styleKey(style) {
        return style.colour + '|' + style.width + '|' + (style.dash ? style.dash.join(',') : '') +
            '|' + (style.arrow ? 'a' : '');
    }

    /* Corner radius on an orthogonal run, in screen pixels. */
    var TRACE_RADIUS = 7;
    /* Clear ring left around a node, so a trace passing it is visibly
     * passing rather than arriving. */
    var TRACE_CLEARANCE = 4;

    /**
     * Route one connection the way a track runs on a board: out of the node
     * into the gutter beside its row, along the gutter, up or down a clear
     * column, along the gutter beside the target's row, and in.
     *
     * Straight diagonals between grid cells cross each other at every angle
     * and read as a scribble. Right angles are followable, but a right angle
     * drawn naively is not enough either: a vertical leg at the node's own x
     * runs through every cell in that column between the two rows, and on a
     * map where a line means a recorded relationship that draws relationships
     * nobody recorded - the opening view had Synanon's line to CEDU running
     * straight through WWASPS. So the long legs run only in gutters, and the
     * one vertical channel is checked against every node between the rows
     * and moved sideways until it is clear.
     *
     * Two nodes in the same row route through the gutter below them, which
     * passes behind both labels; labels are drawn last with a halo, so the
     * text stays legible over the line.
     *
     * The gutter is the fallback, not the rule. Every connection was sent
     * down into it, including two names side by side with nothing between
     * them, which drew a line down, along and back up where one straight
     * line would do. So the shortest shape that crosses nobody wins: a
     * straight line along a row or down a column, then a single right
     * angle (out of the side of one node and into the top or bottom of
     * the other, or the other way round), and only then the gutter.
     *
     * Returns the points of the route and the direction of its final leg,
     * which is what the arrowhead follows.
     */
    function routeEdge(ax, ay, bx, by, jitter, geo, skipA, skipB) {
        if (!geo || !geo.rowStep) {
            var dx = bx - ax;
            var dy = by - ay;
            return { pts: [[ax, ay], [bx, by]], dir: Math.abs(dy) >= Math.abs(dx) ? [0, dy < 0 ? -1 : 1] : [dx < 0 ? -1 : 1, 0] };
        }
        var step = geo.rowStep;
        var sameRow = Math.abs(by - ay) < step / 2;
        var direct = function (pts) {
            for (var i = 1; i < pts.length; i++) {
                if (!geo.clearSeg(pts[i - 1], pts[i], skipA, skipB)) return null;
            }
            var from = pts[pts.length - 2];
            var to = pts[pts.length - 1];
            return { pts: tidy(pts), dir: to[1] !== from[1] ? [0, to[1] > from[1] ? 1 : -1] : [to[0] > from[0] ? 1 : -1, 0] };
        };
        var straight = sameRow ? direct([[ax, ay], [bx, ay]])
            : (Math.abs(bx - ax) < 1 ? direct([[ax, ay], [ax, by]]) : null);
        if (straight) return straight;
        if (!sameRow) {
            var bend = direct([[ax, ay], [bx, ay], [bx, by]]) || direct([[ax, ay], [ax, by], [bx, by]]);
            if (bend) return bend;
        }
        var down = sameRow || by > ay;
        var exitY = geo.channelAt(ay + (down ? step / 2 : -step / 2)) + jitter;
        var enterY = sameRow ? exitY : geo.channelAt(by + (down ? -step / 2 : step / 2)) + jitter;

        var pts;
        if (Math.abs(exitY - enterY) < 1) {
            pts = [[ax, ay], [ax, exitY], [bx, exitY], [bx, by]];
        } else {
            /* Arrive straight into the target if that column is clear
             * between the two gutters, otherwise the nearest column that is. */
            var xv = geo.clearX(bx, exitY, enterY, skipA, skipB);
            pts = [[ax, ay], [ax, exitY], [xv, exitY], [xv, enterY], [bx, enterY], [bx, by]];
        }
        return { pts: tidy(pts), dir: [0, by > pts[pts.length - 2][1] ? 1 : -1] };
    }

    /* Drop zero-length legs and merge collinear ones. */
    function tidy(pts) {
        var out = [pts[0]];
        for (var i = 1; i < pts.length; i++) {
            var p = pts[i];
            var q = out[out.length - 1];
            if (Math.abs(p[0] - q[0]) < 0.5 && Math.abs(p[1] - q[1]) < 0.5) continue;
            if (out.length >= 2) {
                var o = out[out.length - 2];
                var collinear = (Math.abs(o[0] - q[0]) < 0.5 && Math.abs(q[0] - p[0]) < 0.5) ||
                    (Math.abs(o[1] - q[1]) < 0.5 && Math.abs(q[1] - p[1]) < 0.5);
                if (collinear) { out[out.length - 1] = p; continue; }
            }
            out.push(p);
        }
        return out;
    }

    /** Append a route to the current path, rounding every corner. */
    function strokeRoute(ctx, pts) {
        var r = TRACE_RADIUS;
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (var i = 1; i < pts.length - 1; i++) {
            var p = pts[i - 1], v = pts[i], n = pts[i + 1];
            var lenIn = Math.hypot(v[0] - p[0], v[1] - p[1]);
            var lenOut = Math.hypot(n[0] - v[0], n[1] - v[1]);
            var rIn = Math.min(r, lenIn / 2);
            var rOut = Math.min(r, lenOut / 2);
            var inX = v[0] + (p[0] - v[0]) / (lenIn || 1) * rIn;
            var inY = v[1] + (p[1] - v[1]) / (lenIn || 1) * rIn;
            var outX = v[0] + (n[0] - v[0]) / (lenOut || 1) * rOut;
            var outY = v[1] + (n[1] - v[1]) / (lenOut || 1) * rOut;
            ctx.lineTo(inX, inY);
            ctx.quadraticCurveTo(v[0], v[1], outX, outY);
        }
        var last = pts[pts.length - 1];
        ctx.lineTo(last[0], last[1]);
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

        if (spec.status === 'closed' || spec.status === 'rebranded') {
            /* Hollow: no longer operating under this name. Closed is a solid
             * outline; rebranded is dashed, because the place carried on
             * under another name and the dash says "continues elsewhere". */
            ctx.globalAlpha = alpha;
            ctx.fillStyle = SURFACE;
            ctx.fill();
            ctx.strokeStyle = spec.fill === SURFACE
                ? (KIND_OUTLINE[spec.kind] || INK + '0.6)')
                : spec.fill;
            ctx.lineWidth = 2;
            if (spec.status === 'rebranded' && ctx.setLineDash) ctx.setLineDash([3, 2]);
            ctx.stroke();
            if (ctx.setLineDash) ctx.setLineDash([]);
        } else {
            /* Open solid; status unrecorded the same shape at 55%, so "we do
             * not know" reads as faded rather than as closed. */
            ctx.globalAlpha = alpha * (spec.status === 'unknown' ? 0.55 : 1);
            ctx.fillStyle = spec.fill;
            ctx.fill();
            ctx.globalAlpha = alpha;
            ctx.strokeStyle = spec.outline;
            ctx.lineWidth = spec.outlineWidth || 1;
            ctx.stroke();
        }

        /* Deaths recorded in the memorial: a firm red ring outside the shape.
         * A warning mark, so a true red rather than the coral accent, and
         * outside the NATSAP ring so a node can carry both. */
        if (spec.deaths) {
            ctx.beginPath();
            ctx.arc(x, y, r + (spec.natsap ? 5 : 3), 0, Math.PI * 2);
            ctx.strokeStyle = DEATH_RED;
            ctx.lineWidth = 2;
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
            deaths: !!spec.deaths,
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
            dpr: 1,
            /* Screen boxes of the labels drawn last frame, with their nodes. */
            labelHits: []
        };

        var scene = { nodes: [], edges: [] };
        var buckets = [];
        var chainIndex = null;
        var frameStamp = 0;
        /* Cell geometry of the current layout, so traces can run along the
         * gutters between rows instead of across the names in them. */
        var grid = null;
        /* Reused by the draw loop so a frame does not allocate one spec per
         * node; paintNode never holds on to it. */
        var scratch = { kind: '', status: '', natsap: false, deaths: 0, fill: '', outline: '' };

        /** The store's chain-to-index map, so colour mode two can be resolved. */
        renderer.useChainIndex = function (index) {
            chainIndex = index;
        };

        /* The board's colours (meta.chainColours and friends). A chain the
         * board gave no colour falls back to the palette. */
        var board = { chainColours: {}, regionChains: {}, membershipColour: '' };
        renderer.useBoardColours = function (meta) {
            board = {
                chainColours: (meta && meta.chainColours) || {},
                regionChains: (meta && meta.regionChains) || {},
                membershipColour: (meta && meta.membershipColour) || ''
            };
            rebuildBuckets();
        };

        renderer.chainColour = function (chain) {
            if (!chain) return CHAIN_NONE;
            if (board.chainColours[chain]) return board.chainColours[chain];
            var i = chainIndex ? chainIndex[chain] : undefined;
            return i === undefined ? CHAIN_NONE : CHAIN_COLOURS[i % CHAIN_COLOURS.length];
        };

        /* The company a node's lines are drawn for: its recorded owner, or
         * failing that the board frame it sits in, which is how the board
         * itself decided a line's colour. People carry none of their own. */
        function companyOf(node) {
            if (!node || node.kind === 'person') return '';
            if (node.chain && board.chainColours[node.chain]) return node.chain;
            var region = node.regions && node.regions[0];
            if (!region) return '';
            if (board.chainColours[region]) return region;
            return board.regionChains[region] || '';
        }

        /* A line between one company's places and people is that company's
         * colour; a line between two companies is neither's, so it stays the
         * plain ink of its kind. */
        function lineColour(edge) {
            if (edge.category === 'membership') return board.membershipColour;
            var a = companyOf(edge.source);
            var b = companyOf(edge.target);
            if (a && b && a !== b) return '';
            var chain = a || b;
            return chain ? board.chainColours[chain] : '';
        }

        /* How a line is drawn, for the key and the tests. */
        renderer.styleOf = function (edge) {
            return styleFor(edge, renderer.crossRegionMode, lineColour);
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

        renderer.setGrid = function (next) {
            grid = next || null;
        };

        renderer.setEmphasis = function (next) {
            renderer.emphasis = next || { hoverId: null };
        };

        function rebuildBuckets() {
            var byKey = Object.create(null);
            buckets = [];
            for (var i = 0; i < scene.edges.length; i++) {
                var edge = scene.edges[i];
                var style = styleFor(edge, renderer.crossRegionMode, lineColour);
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
            if (renderer.colourMode === 'chain') return renderer.chainColour(node.chain);
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

            /* Snap a horizontal run onto the gutter between two rows. Left
             * at the arithmetic midpoint it lands on a row centre whenever
             * the two nodes are an even number of rows apart, and a trace
             * then runs straight through the names in that row - which reads
             * as a connection to them. */
            var geo = null;
            var blockers = [];
            if (grid && grid.cellH) {
                var rowsTop = grid.y0 * k + t.y;
                var rowsStep = grid.cellH * k;

                /* What a vertical leg must not cross: every node's shape,
                 * with its clearance, and the label under it. In screen
                 * space, one box per node, indexed like the scene. */
                ctx.font = LABEL_SIZE + 'px ' + FONT;
                for (i = 0; i < scene.nodes.length; i++) {
                    node = scene.nodes[i];
                    var rr = Math.min(22, Math.max(2.5, node.r * k)) + TRACE_CLEARANCE;
                    var half = Math.max(rr, textWidth(ctx, node, null, LABEL_SIZE) / 2 + LABEL_PAD_X);
                    blockers.push([sx[i] - half, sy[i] - rr, sx[i] + half, sy[i] + rr + LABEL_LINE + LABEL_PAD_Y]);
                }

                /* The clear band between two rows is not centred on the
                 * line between them: labels hang below their nodes, so the
                 * band runs from the bottom of one row's labels to the top of
                 * the next row's shapes. A channel at the geometric midpoint
                 * ran straight through the names in the row above. */
                var maxRk = scene.nodes.reduce(function (t2, n2) {
                    return Math.max(t2, Math.min(22, Math.max(2.5, n2.r * k)));
                }, 0);
                var labelZone = LABEL_LINE + LABEL_PAD_Y;
                var bandPx = rowsStep - 2 * (maxRk + TRACE_CLEARANCE) - labelZone;
                var bandShift = labelZone / 2;
                /* Parallel runs are spread apart only as far as the band
                 * allows; a spread wider than the band puts a run back onto
                 * the labels it was moved off. */
                var jitterScale = Math.max(0, Math.min(1, (bandPx / 2 - 2) / 10));

                geo = {
                    rowStep: rowsStep,
                    jitterScale: jitterScale,
                    channelAt: function (screenY) {
                        return rowsTop + Math.round((screenY - rowsTop) / rowsStep) * rowsStep + bandShift;
                    },
                    /* Whether a straight leg from p to q crosses nobody's
                     * box but its two ends'. */
                    clearSeg: function (p, q, skipA, skipB) {
                        var x0 = Math.min(p[0], q[0]) + 0.5, x1 = Math.max(p[0], q[0]) - 0.5;
                        var y0 = Math.min(p[1], q[1]) + 0.5, y1 = Math.max(p[1], q[1]) - 0.5;
                        for (var j = 0; j < blockers.length; j++) {
                            if (j === skipA || j === skipB) continue;
                            var bb = blockers[j];
                            if (x1 > bb[0] && x0 < bb[2] && y1 > bb[1] && y0 < bb[3]) return false;
                        }
                        return true;
                    },
                    /* The nearest x to the one asked for at which a vertical
                     * leg between the two gutters crosses nobody's cell but
                     * the two ends'. Candidates are the requested x and the
                     * outer edges of every box that leg would hit, nearest
                     * first; the first that is clear wins. */
                    clearX: function (x, y0, y1, skipA, skipB) {
                        var lo = Math.min(y0, y1) + 1;
                        var hi = Math.max(y0, y1) - 1;
                        var inWay = [];
                        for (var j = 0; j < blockers.length; j++) {
                            if (j === skipA || j === skipB) continue;
                            var bb = blockers[j];
                            if (bb[3] > lo && bb[1] < hi) inWay.push(bb);
                        }
                        var free = function (cx) {
                            for (var j2 = 0; j2 < inWay.length; j2++) {
                                if (cx > inWay[j2][0] && cx < inWay[j2][2]) return false;
                            }
                            return true;
                        };
                        if (free(x)) return x;
                        var candidates = [];
                        inWay.forEach(function (bb) {
                            candidates.push(bb[0] - 2);
                            candidates.push(bb[2] + 2);
                        });
                        candidates.sort(function (p, q) { return Math.abs(p - x) - Math.abs(q - x); });
                        for (var c = 0; c < candidates.length; c++) {
                            if (free(candidates[c])) return candidates[c];
                        }
                        return x;
                    }
                };
            }
            renderer.blockers = blockers;
            var routes = [];

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
                        /* Spread the runs of edges sharing a gutter. */
                        var route = routeEdge(ax, ay, cx, cy, ((i % 5) - 2) * 5 * (geo ? geo.jitterScale : 1), geo, a, c);
                        strokeRoute(ctx, route.pts);
                        route.edge = edge;
                        routes.push(route);
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
            renderer.routes = routes;
            for (i = 0; i < routes.length; i++) {
                var directed = routes[i].edge;
                var style2 = styleFor(directed, renderer.crossRegionMode, lineColour);
                if (!style2.arrow) continue;
                var pts = routes[i].pts;
                var tip = pts[pts.length - 1];
                var from = pts[pts.length - 2];
                ctx.fillStyle = style2.colour;
                ctx.globalAlpha = near ? (nearEdges && nearEdges[directed.id] ? 1 : dim * edgeFade) : edgeFade;
                /* The head follows the final leg of the route, which is how
                 * the trace actually arrives, not the straight line between
                 * the two nodes. */
                drawArrow(ctx, from[0], from[1], tip[0], tip[1],
                    Math.max(2.5, Math.min(22, directed.target.r * k)) + 2,
                    Math.max(5, Math.min(11, 7 * Math.sqrt(k))));
            }
            ctx.globalAlpha = 1;

            /* --- node clearances ---
             *
             * A hole punched in the traces around every node, before any node
             * is drawn. A trace that runs past a name would otherwise meet
             * its edge and appear to stop there, which reads as a connection
             * that the data does not have; with a clear ring around the
             * shape the line visibly goes in one side and out the other, and
             * a line that really does end here ends a little short of the
             * node instead of touching it. Boards have done this forever.
             */
            ctx.fillStyle = SURFACE;
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                var clearR = Math.min(22, Math.max(2.5, node.r * k)) + TRACE_CLEARANCE;
                if (sx[i] + clearR < 0 || sx[i] - clearR > w) continue;
                if (sy[i] + clearR < 0 || sy[i] - clearR > h) continue;
                ctx.beginPath();
                ctx.arc(sx[i], sy[i], clearR, 0, Math.PI * 2);
                ctx.fill();
            }

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
                scratch.deaths = node.deaths;
                scratch.fill = fill;
                scratch.outline = outlineFor(node.kind, fill, renderer.colourMode === 'kind');
                scratch.outlineWidth = 1;
                /* A company's own places and the company itself carry its
                 * colour on their border, as on the board. */
                if (renderer.colourMode === 'kind' && node.chain && board.chainColours[node.chain] &&
                    node.kind !== 'person') {
                    scratch.outline = board.chainColours[node.chain];
                    scratch.outlineWidth = 2;
                }
                paintNode(ctx, scratch, x, y, r, lit ? 1 : dim);

                if (hovered) {
                    ctx.beginPath();
                    ctx.arc(x, y, r + 5, 0, Math.PI * 2);
                    ctx.strokeStyle = '#000435';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }
            }

            /* --- connections off screen ---
             *
             * An owner brings only itself, so a node can sit on the board
             * with connections that are not on it. Rather than hide that,
             * each such node carries a small "+N" pill at its upper right:
             * there is more behind this name, and clicking it brings it. Only
             * on lit nodes, since a dimmed one is background while something
             * else is being read.
             */
            var hiddenCounts = scene.hidden || null;
            var badges = 0;
            if (hiddenCounts) {
                ctx.font = '600 ' + BADGE_SIZE + 'px ' + FONT;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                for (i = 0; i < scene.nodes.length; i++) {
                    node = scene.nodes[i];
                    var extra = hiddenCounts[node.id];
                    if (!extra) continue;
                    if (near && !near[node.id]) continue;
                    var br = Math.min(22, Math.max(2.5, node.r * k));
                    var bx = sx[i] + br * 0.75;
                    var by = sy[i] - br * 0.75;
                    if (bx < -20 || bx > w + 20 || by < -20 || by > h + 20) continue;
                    var text = '+' + extra;
                    var bw = Math.max(BADGE_SIZE + 4, ctx.measureText(text).width + 6);
                    var bh = BADGE_SIZE + 4;
                    ctx.beginPath();
                    roundedRect(ctx, bx - bw / 2, by - bh / 2, bw, bh, bh / 2);
                    ctx.fillStyle = '#000435';
                    ctx.fill();
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillText(text, bx, by + 0.5);
                    badges++;
                }
                ctx.textBaseline = 'top';
            }
            renderer.badgesDrawn = badges;

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
                candidates.push({
                    node: node, x: lx, y: ly, hover: isHover,
                    cx: sx[i], cy: sy[i], rr: Math.max(1.5, node.r * k)
                });
            }

            /* Most connected first, so when two labels cannot both fit it is
             * the smaller name that goes. The hovered node outranks
             * everything. */
            candidates.sort(function (a, b) {
                if (a.hover !== b.hover) return a.hover ? -1 : 1;
                return b.node.degree - a.node.degree;
            });

            var labelGrid = Object.create(null); /* not "grid": a var here hoists over draw() and would shadow the layout grid the router reads */
            var drawn = [];
            for (i = 0; i < candidates.length; i++) {
                var entry = candidates[i];
                var size = entry.hover ? LABEL_SIZE_HOVER : LABEL_SIZE;
                var bold = entry.node.degree >= 8;
                entry.font = (bold ? '600 ' : '') + size + 'px ' + FONT;

                var half = textWidth(ctx, entry.node, entry.font, size) / 2;

                /* Two names on top of each other are worse than one name: the
                 * pair is unreadable and neither can be trusted to belong to
                 * the node under it. But a dropped name is a node the reader
                 * cannot identify at all, so before giving one up the label
                 * is tried in the three other places it can sit and still
                 * plainly belong to its node: above it, then to the right,
                 * then to the left. Only a name with nowhere to go is
                 * dropped, and the packer's job is to make sure that does not
                 * happen. */
                var placed = null;
                for (var pi = 0; pi < LABEL_PLACEMENTS.length; pi++) {
                    var spot = placeLabel(entry, half, LABEL_PLACEMENTS[pi]);
                    if (!fitsInGrid(labelGrid, spot.box)) continue;
                    placed = spot;
                    break;
                }
                if (!placed) continue;
                occupyGrid(labelGrid, placed.box);
                entry.x = placed.x;
                entry.y = placed.y;
                entry.align = placed.align;
                entry.box = placed.box;
                drawn.push(entry);
            }
            /* Published for hit testing: a name is part of its node, so a
             * click on the text should land on the thing it names. Only the
             * labels actually drawn count - a dropped label is not on screen
             * to be clicked. */
            renderer.labelHits = drawn;

            ctx.strokeStyle = 'rgba(242, 238, 223, 0.92)';
            ctx.lineWidth = 3;
            for (i = 0; i < drawn.length; i++) {
                ctx.font = drawn[i].font;
                ctx.textAlign = drawn[i].align;
                ctx.strokeText(drawn[i].node.name, drawn[i].x, drawn[i].y);
            }
            ctx.fillStyle = '#000435';
            for (i = 0; i < drawn.length; i++) {
                ctx.font = drawn[i].font;
                ctx.textAlign = drawn[i].align;
                ctx.fillText(drawn[i].node.name, drawn[i].x, drawn[i].y);
            }

            /* Years of operation, where the data has them: a smaller second
             * line in a lighter ink, so the name stays what is read first.
             * Its room was counted in the label's box above. */
            ctx.font = YEARS_SIZE + 'px ' + FONT;
            ctx.fillStyle = 'rgba(0, 4, 53, 0.66)';
            for (i = 0; i < drawn.length; i++) {
                if (!drawn[i].node.years) continue;
                ctx.textAlign = drawn[i].align;
                ctx.fillText(drawn[i].node.years, drawn[i].x, drawn[i].y + LABEL_LINE);
            }
            ctx.textAlign = 'center';

            /* --- what each line says ---
             *
             * After the names, into the same collision grid, so a name always
             * wins: a caption that cannot sit clear of every name and shape
             * is left off rather than drawn over one. Each goes on the
             * longest straight run of its line, a horizontal run first, since
             * that is where the words read along the line they belong to.
             * With a neighbourhood lit, only its own lines are captioned. */
            var captions = [];
            if (renderer.edgeLabels !== false && routes.length <= EDGE_LABEL_MAX) {
                ctx.font = 'italic ' + EDGE_LABEL_SIZE + 'px ' + FONT;
                for (i = 0; i < routes.length; i++) {
                    var captioned = routes[i].edge;
                    if (near && !(nearEdges ? nearEdges[captioned.id]
                        : (near[captioned.sourceId] && near[captioned.targetId]))) continue;
                    var words = edgeLabelText(captioned);
                    if (!words) continue;
                    var tw = ctx.measureText(words).width;
                    var legs = [];
                    var rp = routes[i].pts;
                    for (var li = 1; li < rp.length; li++) {
                        var lx0 = rp[li - 1][0], ly0 = rp[li - 1][1], lx1 = rp[li][0], ly1 = rp[li][1];
                        legs.push({
                            len: Math.hypot(lx1 - lx0, ly1 - ly0),
                            flat: Math.abs(ly1 - ly0) < 0.5,
                            x0: lx0, y0: ly0, x1: lx1, y1: ly1
                        });
                    }
                    legs.sort(function (p1, p2) {
                        return (p2.flat ? 1 : 0) - (p1.flat ? 1 : 0) || p2.len - p1.len;
                    });
                    /* The middle of a run first, then further along it either
                     * way: a name or another caption often sits on the
                     * middle of a busy run and nowhere else. */
                    var spots = [];
                    legs.forEach(function (leg2) {
                        if (leg2.len < (leg2.flat ? tw + 16 : EDGE_LABEL_SIZE * 3)) return;
                        [0.5, 0.35, 0.65, 0.2, 0.8].forEach(function (f) {
                            var sxp = leg2.x0 + (leg2.x1 - leg2.x0) * f;
                            var syp = leg2.y0 + (leg2.y1 - leg2.y0) * f;
                            /* The whole caption stays on its own run. */
                            if (leg2.flat && Math.min(Math.abs(sxp - leg2.x0), Math.abs(sxp - leg2.x1)) < tw / 2 + 6) return;
                            spots.push({ x: sxp, y: syp });
                        });
                    });
                    for (var lj = 0; lj < spots.length; lj++) {
                        var leg = spots[lj];
                        var cbox = [leg.x - tw / 2 - 3, leg.y - EDGE_LABEL_SIZE / 2 - 2,
                            leg.x + tw / 2 + 3, leg.y + EDGE_LABEL_SIZE / 2 + 2];
                        if (cbox[0] < 0 || cbox[2] > w || cbox[1] < 0 || cbox[3] > h) continue;
                        if (!fitsInGrid(labelGrid, cbox)) continue;
                        var onShape = false;
                        for (var bi = 0; bi < blockers.length && !onShape; bi++) {
                            var bb2 = blockers[bi];
                            onShape = cbox[0] < bb2[2] && cbox[2] > bb2[0] && cbox[1] < bb2[3] && cbox[3] > bb2[1];
                        }
                        if (onShape) continue;
                        occupyGrid(labelGrid, cbox);
                        captions.push({ text: words, x: leg.x, y: leg.y, box: cbox, edge: captioned });
                        break;
                    }
                }
                ctx.textBaseline = 'middle';
                for (i = 0; i < captions.length; i++) {
                    var cb = captions[i].box;
                    ctx.globalAlpha = 0.92;
                    ctx.fillStyle = SURFACE;
                    ctx.beginPath();
                    roundedRect(ctx, cb[0], cb[1], cb[2] - cb[0], cb[3] - cb[1], 3);
                    ctx.fill();
                    ctx.globalAlpha = 1;
                    ctx.fillStyle = 'rgba(0, 4, 53, 0.78)';
                    ctx.fillText(captions[i].text, captions[i].x, captions[i].y + 0.5);
                }
                ctx.textBaseline = 'top';
            }
            renderer.edgeCaptions = captions;
        };

        /* A pill path. arcTo keeps it to the calls every canvas has. */
        function roundedRect(c, x, y, width, height, radius) {
            c.moveTo(x + radius, y);
            c.lineTo(x + width - radius, y);
            c.arcTo(x + width, y, x + width, y + radius, radius);
            c.lineTo(x + width, y + height - radius);
            c.arcTo(x + width, y + height, x + width - radius, y + height, radius);
            c.lineTo(x + radius, y + height);
            c.arcTo(x, y + height, x, y + height - radius, radius);
            c.lineTo(x, y + radius);
            c.arcTo(x, y, x + radius, y, radius);
            c.closePath();
        }

        /**
         * One candidate placement: where the text is drawn, how it is
         * aligned, and the box it would occupy. `half` is half the measured
         * width, so a centred label is `half` either side and a left or right
         * aligned one is the full width on one side.
         */
        function placeLabel(entry, half, where) {
            var x = entry.cx;
            var y = entry.y;
            var align = 'center';

            if (where === 'above') {
                y = entry.cy - entry.rr - 3 - LABEL_LINE - (entry.node.years ? YEARS_LINE : 0);
            } else if (where === 'right') {
                x = entry.cx + entry.rr + 4;
                y = entry.cy - LABEL_LINE / 2;
                align = 'left';
            } else if (where === 'left') {
                x = entry.cx - entry.rr - 4;
                y = entry.cy - LABEL_LINE / 2;
                align = 'right';
            }

            var left = align === 'center' ? x - half : (align === 'left' ? x : x - half * 2);
            var right = align === 'center' ? x + half : (align === 'left' ? x + half * 2 : x);
            return {
                x: x,
                y: y,
                align: align,
                /* A name with years under it is a line taller. */
                box: [left - LABEL_PAD_X, y - LABEL_PAD_Y, right + LABEL_PAD_X,
                    y + LABEL_LINE + (entry.node.years ? YEARS_LINE : 0) + LABEL_PAD_Y]
            };
        }

        /**
         * How many names would be dropped if these nodes were drawn at this
         * transform: the label pass run dry, with the same candidates, the
         * same order, the same four placements and the same collision grid,
         * and no hover. Nodes whose label would be off the stage do not
         * count, since panning is how those are read. focus.js uses it to
         * choose a zoom by measuring rather than by estimate, so the rule
         * "never drop a name" is checked against the renderer that has to
         * keep it.
         */
        renderer.dropsAt = function (nodes, positionOf, k, tx, ty) {
            var w = renderer.width;
            var h = renderer.height;
            var entries = [];
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                var p = positionOf(node);
                var cx = p.x * k + tx;
                var cy = p.y * k + ty;
                var rr = Math.max(1.5, node.r * k);
                var ly = cy + rr + 3;
                if (cx < -140 || cx > w + 140 || ly < -20 || ly > h + 20) continue;
                entries.push({ node: node, x: cx, y: ly, cx: cx, cy: cy, rr: rr });
            }
            entries.sort(function (a, b) { return b.node.degree - a.node.degree; });
            var grid = Object.create(null);
            var dropped = 0;
            for (var j = 0; j < entries.length; j++) {
                var entry = entries[j];
                var half = textWidth(ctx, entry.node, null, LABEL_SIZE) / 2;
                var placed = null;
                for (var pi = 0; pi < LABEL_PLACEMENTS.length; pi++) {
                    var spot = placeLabel(entry, half, LABEL_PLACEMENTS[pi]);
                    if (!fitsInGrid(grid, spot.box)) continue;
                    placed = spot;
                    break;
                }
                if (!placed) {
                    /* Only a name that would have been on the stage counts. */
                    if (entry.cx >= 0 && entry.cx <= w && entry.cy >= 0 && entry.cy <= h) dropped++;
                    continue;
                }
                occupyGrid(grid, placed.box);
            }
            return dropped;
        };

        /**
         * The on-screen box a node's label takes when drawn below it: width
         * and height in pixels, padding included. focus.js uses it to work
         * out the lowest zoom at which neighbouring labels still clear each
         * other, so the two cannot disagree about what a name needs.
         */
        renderer.labelBox = function (node) {
            var width = textWidth(ctx, node, null, LABEL_SIZE);
            /* Heavier names are drawn semibold, which runs a little wider. */
            if (node.degree >= 8) width *= 1.08;
            return {
                width: width + LABEL_PAD_X * 2,
                height: LABEL_LINE + (node.years ? YEARS_LINE : 0) + LABEL_PAD_Y * 2
            };
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
        /* How much vertical room one label needs on screen, box and breathing
         * space included. focus.js holds the zoom above the point where two
         * rows come closer than this, because a name that cannot clear the
         * row below it is a name the renderer has to drop. */
        LABEL_PITCH: LABEL_LINE + LABEL_PAD_Y * 2 + 2,
        /* And how much more a name with a years line needs. */
        YEARS_LINE: YEARS_LINE,
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
