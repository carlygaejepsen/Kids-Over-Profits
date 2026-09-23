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

    var SURFACE = '#FFFFFF'; /* The stage is white, as the board's frames are. */
    var FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

    /* Nodes are drawn the way the board's key draws them: a box with a thin
     * dark outline, filled by status - pale yellow for a place still open,
     * grey for one closed or carried on under another name, white where the
     * record does not say - with the name in dark ink, or in blue for a
     * NATSAP member. What a thing is (a person, a programme, a company) is
     * told by its shape and by the drawer, never by a coloured background:
     * the orange and navy blocks this used to draw were the thing the
     * board's owner asked to have back the way it was. */
    var STATUS_FILLS = { open: '#FAEBA1', closed: '#E6E6E6', rebranded: '#E6E6E6', unknown: '#FFFFFF' };
    var OUTLINE = '#1A1A1A';
    var INK_TEXT = '#1A1A1A';
    var NATSAP_INK = '#0B2BF0';

    /* Company colours, for the lines: the board's own where it gave one
     * (meta.chainColours), this palette for a chain it left black. Indexed
     * by position in meta.chains, so a chain keeps its colour when the view
     * is filtered. Everything with no recorded owner is grey. */
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
        rebrand:    { colour: '#1E7F8C', width: 3, dash: null, arrow: true, label: 'Became' },
        /* X acquired Y. */
        acquired:   { colour: '#C96A12', width: 3, dash: null, arrow: true, label: 'Acquired' },
        corporate:  { colour: 'rgba(26, 26, 26, 0.8)', width: 1.8, dash: null, label: 'Ownership' },
        /* Married, divorced, siblings: the only edges on the map that join
         * two people to each other rather than a person to a programme, and
         * the ones a reader is most likely to be looking for. */
        family:     { colour: '#D6455A', width: 2, dash: [2, 3], label: 'Family' },
        survivor:   { colour: '#B5359B', width: 1.8, dash: null, label: 'Survivor account' },
        board:      { colour: 'rgba(26, 26, 26, 0.6)', width: 1.4, dash: [1, 3], label: 'Board member' },
        leadership: { colour: 'rgba(26, 26, 26, 0.6)', width: 1.4, dash: null, label: 'Leadership' },
        clinical:   { colour: 'rgba(26, 26, 26, 0.5)', width: 1.2, dash: null, label: 'Clinical staff' },
        referral:   { colour: 'rgba(120, 70, 0, 0.5)', width: 1.3, dash: [4, 3], label: 'Referral' },
        unknown:    { colour: 'rgba(26, 26, 26, 0.34)', width: 1, dash: [1, 3], label: 'Unrecorded' },
        admissions: { colour: 'rgba(26, 26, 26, 0.5)', width: 1.15, dash: [5, 2], label: 'Admissions' },
        staff:      { colour: 'rgba(26, 26, 26, 0.5)', width: 1.15, dash: null, label: 'Other staff' },
        /* Dashed the way the board's key draws a professional association. */
        membership: { colour: 'rgba(26, 26, 26, 0.55)', width: 1.3, dash: [5, 5], label: 'Member' },
        /* Two places joined by somebody who worked at both. The person is
         * not a name on the board; they are this line, and hovering it says
         * who. Dash-dot, so it reads as a different kind of statement from a
         * line the record draws between the two places themselves. */
        people:     { colour: 'rgba(26, 26, 26, 0.65)', width: 1.6, dash: [9, 3, 2, 3], label: 'Shared people' },
        /* "Other" would read as the node kind of the same name in the key. */
        _default:   { colour: 'rgba(26, 26, 26, 0.5)', width: 1.15, dash: null, label: 'Other connection' }
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
    var LABEL_SIZE = 12;
    var LABEL_SIZE_HOVER = 13.5;
    var LABEL_LINE = 13;
    /* Bucket size for the collision grid, in screen pixels. */
    var LABEL_CELL = 48;

    /* Bubbles. A name sits inside its node with this much room around it,
     * and two bubbles keep at least BUBBLE_GAP apart on every side. */
    var BUBBLE_PAD_X = 9;
    var BUBBLE_PAD_Y = 5;
    var BUBBLE_GAP = 4;
    /* A bubble's outline is thin and dark, as on the board; a dot's thinner
     * still. The memorial ring steps out to clear it. */
    var BORDER_BUBBLE = 1.5;
    var BORDER_DOT = 1;
    var RING_DEATHS = 4.5;
    var RING_HOVER = 8;
    /* A node that cannot hold its name is drawn as a dot this size, in
     * screen pixels: big enough to read as a person or a place, small
     * enough not to crowd what does have room. */
    var DOT_MIN = 4.5;
    var DOT_MAX = 9;
    /* The dot where a line lands on a bubble. */
    var PORT_R = 3;
    /* How long a list of old names may run before it is given as the first
     * name and a count. The line is cut to the bubble in any case, and
     * "formerly Sequel TSI Owens Cross R…" says less than "formerly
     * Sequel TSI Owens Cross Rds +1", which at least admits there is more. */
    var FORMER_CHARS = 34;
    /* How far a sub-line may stretch its bubble past the name's own width;
     * see baseWidth. */
    var SUB_STRETCH = 1;

    /**
     * The small lines a bubble carries under its name, in reading order.
     *
     * The years of operation, and then the name the place traded under
     * before, which is the one thing a reader searching for an old name
     * cannot otherwise see on the board: the record holds it, the drawer
     * says it, and until now the map did not. Where a place has more than
     * one old name the line names the first and counts the rest, and the
     * drawer lists them all.
     */
    function subLines(node) {
        if (node._subLines !== undefined) return node._subLines;
        var lines = [];
        if (node.years) lines.push(node.years);

        var former = node.formerNames || [];
        var text = '';
        if (former.length) {
            text = 'formerly ' + former.join(', ');
            if (text.length > FORMER_CHARS) {
                text = 'formerly ' + former[0];
                if (former.length > 1) text += ' +' + (former.length - 1);
            }
        } else if (node.currentName) {
            text = 'now ' + node.currentName;
        }

        if (text) {
            /* Where the name already carries its years, the old name joins
             * that line. It never starts a third: a bubble one line taller
             * is a bubble its neighbours must be pushed away from, and that
             * cost falls on every name in the view, not only the one with a
             * history. Two names in the Provo Canyon School view were enough
             * to pull eight ownership pairs out of order when they split.
             * Nothing is lost by folding: the line is cut to the bubble
             * either way, and the drawer has the whole of it. */
            if (lines.length) lines[0] += ' \u00b7 ' + text;
            else lines.push(text);
        }

        node._subLines = lines;
        return lines;
    }

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

    /* The people a line stands for, drawn on it: one small circle each,
     * side by side across the middle of the line, and where the line has
     * no room for all of them a "+N" pill for the rest. Screen pixels, so
     * a circle is the same size however far out the view is. */
    var MARKER_R = 5;
    var MARKER_GAP = 4;
    var MARKER_MAX = 5;
    var MARKER_SIZE = 8.5;
    /* A strip of markers keeps this clear of the bubble at either end. */
    var MARKER_END_PAD = 10;
    /* Where along a line the strip is tried: the middle first. */
    var MARKER_SPOTS = [0.5, 0.42, 0.58, 0.34, 0.66, 0.26, 0.74];

    /**
     * Whether a line between two places stands for the people who worked at
     * both: one the map folded a person into (focus.js, foldConnectors), or
     * one the build drew from the staff list or the staff moves, where the
     * person was never a node to begin with.
     */
    function isPeopleLine(edge) {
        if (edge.category === 'people') return true;
        var roles = edge.roles || [];
        return (edge.provenance === 'staff-list' || edge.provenance === 'staff-movement') &&
            (roles[0] === 'worked at both' || roles[0] === 'staff moved');
    }

    /**
     * The people a line stands for: the ones folded into it, each with
     * their node, then the ones the staff list names in its text ("X worked
     * at both A and B; Y ..."), who were never nodes. Each is
     * { key, name, node }, and the key is what connection.js keys its
     * items by, so a marker can ask the popup for one person.
     */
    function peopleOf(edge) {
        var people = (edge.via || []).map(function (v) {
            return { key: v.person.id, name: v.person.name, node: v.person };
        });
        var roles = edge.roles || [];
        if ((edge.provenance === 'staff-list' || edge.provenance === 'staff-movement') &&
            (roles[0] === 'worked at both' || roles[0] === 'staff moved')) {
            String(edge.raw || '').split('; ').forEach(function (part) {
                var name = part.split(/ \(| worked at | moved from /)[0].trim();
                if (!name) return;
                for (var i = 0; i < people.length; i++) if (people[i].name === name) return;
                people.push({ key: name, name: name, node: null });
            });
        }
        return people;
    }

    /** The names a line stands for. */
    function peopleOn(edge) {
        return peopleOf(edge).map(function (p) { return p.name; });
    }

    /**
     * The words to write on a line: the relationship as the board wrote it
     * ("cofounder/CEO", "rebrand"). Nothing for a line that stands for
     * people: they are drawn on it as circles, one each, which say how
     * many better than a count would. Nothing either for a line the board
     * left unlabelled: the build files those as "affiliated", which is its
     * reading, not the board's words.
     */
    function edgeLabelText(edge) {
        var roles = edge.roles || [];
        var raw = String(edge.raw || '');
        var text = '';
        if (isPeopleLine(edge)) {
            text = '';
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

    /* Is the point inside the box? */
    function inBox(p, box) {
        return p[0] >= box[0] && p[0] <= box[2] && p[1] >= box[1] && p[1] <= box[3];
    }

    /* Along the segment from p (inside the box) towards q, the fraction at
     * which it leaves the box. More than 1 when q is inside too. */
    function exitT(p, q, box) {
        var dx = q[0] - p[0], dy = q[1] - p[1];
        var t = Infinity;
        if (dx > 0) t = Math.min(t, (box[2] - p[0]) / dx);
        else if (dx < 0) t = Math.min(t, (box[0] - p[0]) / dx);
        if (dy > 0) t = Math.min(t, (box[3] - p[1]) / dy);
        else if (dy < 0) t = Math.min(t, (box[1] - p[1]) / dy);
        return t;
    }

    /**
     * Where the people on a line go: a strip of `n` circles across the
     * middle of the line, and a "+N" pill for those there is no room for.
     *
     * The line runs from the centre of one bubble to the centre of the
     * other, so each leg is first cut back to the part outside the two
     * bubbles. The longest leg is tried first, at its middle and then
     * further along either way, with as many circles as the line can
     * hold, then fewer and a pill; every circle and the pill has to sit on
     * the stage, clear of every name and shape, and clear of whatever the
     * frame has already placed in the collision grid. Nothing is drawn on
     * a line that has no room even for the pill alone: the line still
     * answers to the pointer as a whole.
     *
     * Returns { circles: [[x, y], ...], more: { x, y, w, h, count } | null }
     * or null, and claims what it placed in the grid.
     */
    function placeMarkers(ctx2, pts, boxA, boxB, n, grid, blockers, w, h) {
        var legs = [];
        for (var li = 1; li < pts.length; li++) {
            var p = pts[li - 1], q = pts[li];
            var t0 = 0, t1 = 1;
            if (inBox(p, boxA)) t0 = exitT(p, q, boxA);
            if (inBox(q, boxB)) t1 = 1 - exitT(q, p, boxB);
            if (t0 < 0) t0 = 0;
            if (t1 > 1) t1 = 1;
            if (!(t1 > t0)) continue;
            var x0 = p[0] + (q[0] - p[0]) * t0, y0 = p[1] + (q[1] - p[1]) * t0;
            var x1 = p[0] + (q[0] - p[0]) * t1, y1 = p[1] + (q[1] - p[1]) * t1;
            var len = Math.hypot(x1 - x0, y1 - y0);
            if (len < MARKER_END_PAD * 2 + MARKER_R * 2) continue;
            legs.push({ x0: x0, y0: y0, ux: (x1 - x0) / len, uy: (y1 - y0) / len, len: len });
        }
        if (!legs.length) return null;
        legs.sort(function (a, b) { return b.len - a.len; });

        var pillH = MARKER_SIZE + 5;
        for (var m = Math.min(n, MARKER_MAX); m >= 0; m--) {
            var more = n - m;
            if (!m && !more) break;
            var pillW = more ? Math.max(pillH, ctx2.measureText('+' + more).width + 8) : 0;
            var strip = m * MARKER_R * 2 + Math.max(0, m - 1) * MARKER_GAP +
                (more ? (m ? MARKER_GAP : 0) + pillW : 0);
            for (var lj = 0; lj < legs.length; lj++) {
                var leg = legs[lj];
                if (strip > leg.len - MARKER_END_PAD * 2) continue;
                for (var sj = 0; sj < MARKER_SPOTS.length; sj++) {
                    var centre = leg.len * MARKER_SPOTS[sj];
                    var from = centre - strip / 2;
                    if (from < MARKER_END_PAD || from + strip > leg.len - MARKER_END_PAD) continue;
                    var circles = [];
                    var boxes = [];
                    var cursor = from;
                    var at = function (d) { return [leg.x0 + leg.ux * d, leg.y0 + leg.uy * d]; };
                    for (var c = 0; c < m; c++) {
                        var cp = at(cursor + MARKER_R);
                        circles.push(cp);
                        boxes.push([cp[0] - MARKER_R - 1, cp[1] - MARKER_R - 1, cp[0] + MARKER_R + 1, cp[1] + MARKER_R + 1]);
                        cursor += MARKER_R * 2 + MARKER_GAP;
                    }
                    var pill = null;
                    if (more) {
                        var pp = at(cursor + pillW / 2);
                        pill = { x: pp[0], y: pp[1], w: pillW, h: pillH, count: more };
                        boxes.push([pp[0] - pillW / 2, pp[1] - pillH / 2, pp[0] + pillW / 2, pp[1] + pillH / 2]);
                    }
                    var ok = true;
                    for (var bi = 0; bi < boxes.length && ok; bi++) {
                        var bx = boxes[bi];
                        if (bx[0] < 0 || bx[2] > w || bx[1] < 0 || bx[3] > h) { ok = false; break; }
                        if (!fitsInGrid(grid, bx)) { ok = false; break; }
                        for (var ki = 0; ki < blockers.length; ki++) {
                            var kb = blockers[ki];
                            if (bx[0] < kb[2] && bx[2] > kb[0] && bx[1] < kb[3] && bx[3] > kb[1]) { ok = false; break; }
                        }
                    }
                    if (!ok) continue;
                    for (var oi = 0; oi < boxes.length; oi++) occupyGrid(grid, boxes[oi]);
                    return { circles: circles, boxes: boxes, more: pill };
                }
            }
        }
        return null;
    }

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

    /* Which way a connection runs is the first thing about it, so it is the
     * first thing checked. */
    /* The categories that keep their own colour whatever company they sit
     * in: a family tie or a survivor's account. The rest, a rebrand and a
     * takeover included, are drawn in the colour the board gives the
     * company, as the board draws them, with their own width, dash and
     * arrowhead, so the key still tells the kinds of connection apart. */
    var OWN_COLOUR = { family: true, survivor: true };
    var companyStyles = Object.create(null);

    /* colourOf(edge) is the board colour the line should carry, or ''. */
    function styleFor(edge, crossRegion, colourOf) {
        if (crossRegion) return CROSS_STYLE;
        if (edge.direction === 'renamed') return EDGE_STYLES.rebrand;
        if (edge.direction === 'acquirer') return EDGE_STYLES.acquired;
        var base = isPeopleLine(edge) ? EDGE_STYLES.people
            : (EDGE_STYLES[edge.category] || EDGE_STYLES._default);
        if (OWN_COLOUR[edge.category] || !colourOf) return base;
        var colour = colourOf(edge);
        if (!colour) return base;
        var key = base.label + '|' + colour;
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

    /* Corner radius where a line turns, in screen pixels. */
    var TRACE_RADIUS = 7;
    /* Clear ring left around a node, so a line passing it is visibly
     * passing rather than arriving. */
    var TRACE_CLEARANCE = 4;
    /* How far outside that ring a detour turns its corner. */
    var DETOUR_MARGIN = 2;
    /* How far around a line's two ends the router looks for a way past, in
     * screen pixels, and the most corners it will weigh. */
    var DETOUR_REACH = 220;
    var DETOUR_CORNERS = 24;
    /* And, when that fails, one wider search before giving up. */
    var DETOUR_CORNERS_WIDE = 160;
    /* A view with more names than this is a field of dots, where a
     * detour round each is a scribble and costs more than the frame has:
     * its lines go straight. */
    var DETOUR_MAX_NODES = 160;
    /* And a line with more boxes than this within reach is in a crowd the
     * search cannot afford either. */
    var DETOUR_MAX_NEAR = 80;
    /* Two lines between the same pair sit this far apart. */
    var PARALLEL_GAP = 5;

    /**
     * Whether a straight line from p to q enters the box: a slab test, so a
     * diagonal is judged by where it actually runs and not by the box
     * around it. The box is shrunk by half a pixel so a line that only
     * grazes an edge is not a hit.
     */
    function segmentHitsBox(p, q, box) {
        var x0 = p[0], y0 = p[1];
        var dx = q[0] - x0, dy = q[1] - y0;
        var lo = 0, hi = 1, r, s, tmp;
        if (dx === 0) {
            if (x0 < box[0] + 0.5 || x0 > box[2] - 0.5) return false;
        } else {
            r = (box[0] + 0.5 - x0) / dx;
            s = (box[2] - 0.5 - x0) / dx;
            if (r > s) { tmp = r; r = s; s = tmp; }
            if (r > lo) lo = r;
            if (s < hi) hi = s;
            if (lo > hi) return false;
        }
        if (dy === 0) {
            if (y0 < box[1] + 0.5 || y0 > box[3] - 0.5) return false;
        } else {
            r = (box[1] + 0.5 - y0) / dy;
            s = (box[3] - 0.5 - y0) / dy;
            if (r > s) { tmp = r; r = s; s = tmp; }
            if (r > lo) lo = r;
            if (s < hi) hi = s;
        }
        return lo <= hi;
    }

    /**
     * Route one connection: a straight line from centre to centre, which is
     * the shortest path there is and leaves each bubble on whichever side
     * faces the other end - and, only where that line would run through
     * somebody else's name, the shortest way round.
     *
     * A line through a name reads as a connection to it, so a route never
     * crosses a node it does not join. The way round is found on the
     * corners of the boxes in the way: every corner is a place the line
     * can turn, a leg between two of them counts if it clears every box,
     * and Dijkstra over that little graph gives the shortest chain of
     * legs. The first pass uses only the boxes the straight line hits; if
     * their corners are themselves boxed in, everything within reach of
     * the two ends joins the search. A line the search cannot get past
     * goes straight and crosses, which the layout is meant never to ask
     * for.
     *
     * Right-angled traces in the gutters between rows were tried first and
     * dropped: every line left its node from the bottom, and a line to the
     * next name over went down, along and back up.
     *
     * Points are in screen pixels. skipA and skipB index the two ends' own
     * boxes, which a line is allowed to leave and enter.
     */
    function routeEdge(ax, ay, bx, by, blockers, skipA, skipB, straightOnly) {
        var a = [ax, ay], b = [bx, by];
        if (straightOnly) return [a, b];
        var lo = [Math.min(ax, bx) - DETOUR_REACH, Math.min(ay, by) - DETOUR_REACH];
        var hi = [Math.max(ax, bx) + DETOUR_REACH, Math.max(ay, by) + DETOUR_REACH];
        /* Only the boxes near the line can be in its way, or its detour's. */
        var near = [];
        var nearIndex = [];
        for (var i = 0; i < blockers.length; i++) {
            var bb = blockers[i];
            if (bb[2] < lo[0] || bb[0] > hi[0] || bb[3] < lo[1] || bb[1] > hi[1]) continue;
            near.push(bb);
            nearIndex.push(i);
        }
        var clear = function (p, q, skipP, skipQ) {
            for (var j = 0; j < near.length; j++) {
                var idx = nearIndex[j];
                if (idx === skipP || idx === skipQ) continue;
                if (segmentHitsBox(p, q, near[j])) return false;
            }
            return true;
        };
        if (clear(a, b, skipA, skipB)) return [a, b];
        if (near.length > DETOUR_MAX_NEAR) return [a, b];

        var inWay = [];
        var others = [];
        for (var j = 0; j < near.length; j++) {
            if (nearIndex[j] === skipA || nearIndex[j] === skipB) continue;
            others.push(near[j]);
            if (segmentHitsBox(a, b, near[j])) inWay.push(near[j]);
        }
        var path = detour(a, b, cornersOf(inWay), clear, skipA, skipB);
        if (!path && others.length > inWay.length) {
            path = detour(a, b, cornersOf(others, a, b, DETOUR_CORNERS), clear, skipA, skipB);
        }
        if (!path && others.length * 4 > DETOUR_CORNERS) {
            path = detour(a, b, cornersOf(others, a, b, DETOUR_CORNERS_WIDE), clear, skipA, skipB);
        }
        return path || [a, b];
    }

    /* The turning points a detour can use: each box's four corners, set
     * out past its clearance. Capped at `keep`, nearest the straight line
     * between the two ends first: a corner beside the line is a way past
     * whatever blocks it, a corner beside an end is usually in the crowd
     * the end sits in. */
    function cornersOf(boxes, a, b, keep) {
        var m = DETOUR_MARGIN;
        var out = [];
        for (var i = 0; i < boxes.length; i++) {
            var bb = boxes[i];
            out.push([bb[0] - m, bb[1] - m], [bb[2] + m, bb[1] - m], [bb[0] - m, bb[3] + m], [bb[2] + m, bb[3] + m]);
        }
        if (keep && out.length > keep) {
            /* Costed once each, not in the comparator: this sort was most
             * of the router's time. */
            var dx = b[0] - a[0], dy = b[1] - a[1];
            var len2 = dx * dx + dy * dy || 1;
            for (var c = 0; c < out.length; c++) {
                var p = out[c];
                var t = ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / len2;
                t = t < 0 ? 0 : (t > 1 ? 1 : t);
                p.cost = Math.hypot(p[0] - (a[0] + dx * t), p[1] - (a[1] + dy * t));
            }
            out.sort(function (p, q) { return p.cost - q.cost; });
            out.length = keep;
        }
        return out;
    }

    /* The shortest chain of clear legs from a to b through the corners:
     * A* on a graph small enough to check its legs as it goes, the
     * straight-line distance left as the estimate, so the corners the
     * line would never use are never settled. Vertex 0 is a, 1 is b. */
    function detour(a, b, corners, clear, skipA, skipB) {
        var n = corners.length + 2;
        if (n < 3) return null;
        var pts = [a, b].concat(corners);
        var dist = new Float64Array(n);
        var guess = new Float64Array(n);
        var prev = new Int32Array(n);
        var done = new Uint8Array(n);
        for (var i = 0; i < n; i++) {
            dist[i] = Infinity;
            prev[i] = -1;
            guess[i] = Math.hypot(pts[i][0] - b[0], pts[i][1] - b[1]);
        }
        dist[0] = 0;
        for (;;) {
            var u = -1;
            var best = Infinity;
            for (var c = 0; c < n; c++) {
                if (done[c] || dist[c] === Infinity) continue;
                var f = dist[c] + guess[c];
                if (f < best) { best = f; u = c; }
            }
            if (u < 0) return null;
            if (u === 1) break;
            done[u] = 1;
            for (var v = 1; v < n; v++) {
                if (done[v] || v === u) continue;
                var d = dist[u] + Math.hypot(pts[v][0] - pts[u][0], pts[v][1] - pts[u][1]);
                if (d >= dist[v]) continue;
                /* A leg may pass through its own end's bubble and no other. */
                if (!clear(pts[u], pts[v], u === 0 ? skipA : -1, v === 1 ? skipB : -1)) continue;
                dist[v] = d;
                prev[v] = u;
            }
        }
        var path = [];
        for (var at = 1; at !== -1; at = prev[at]) path.push(pts[at]);
        path.reverse();
        return tidy(path);
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
                var cross = (q[0] - o[0]) * (p[1] - q[1]) - (q[1] - o[1]) * (p[0] - q[0]);
                if (Math.abs(cross) < 0.5) { out[out.length - 1] = p; continue; }
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

    /* Node outlines for a node too crowded to hold its name: a circle for a
     * person, a rounded square for everything else. Kinds are told apart by
     * colour, and in a bubble by the name inside it; the diamonds, hexagons
     * and triangles this used to draw were a second code nobody read. */
    function traceShape(ctx, kind, x, y, r) {
        if (kind === 'person') {
            ctx.moveTo(x + r, y);
            ctx.arc(x, y, r, 0, Math.PI * 2);
            return;
        }
        var half = r * 0.89;
        pathRounded(ctx, x - half, y - half, half * 2, half * 2, Math.min(r * 0.34, half));
    }

    /* A rounded rectangle path. arcTo keeps it to the calls every canvas has. */
    function pathRounded(c, x, y, width, height, radius) {
        radius = Math.max(0, Math.min(radius, width / 2, height / 2));
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

    /* A bubble's outline, grown by `inflate` on every side: an ellipse for
     * a person, a softly rounded box for an organisation, as the board's
     * key draws them. A canvas without ellipse() gets a pill. */
    function traceBubble(ctx, kind, box, inflate) {
        var x0 = box[0] - inflate, y0 = box[1] - inflate;
        var width = box[2] - box[0] + inflate * 2;
        var height = box[3] - box[1] + inflate * 2;
        if (kind === 'person' && ctx.ellipse) {
            ctx.moveTo(x0 + width, y0 + height / 2);
            ctx.ellipse(x0 + width / 2, y0 + height / 2, width / 2, height / 2, 0, 0, Math.PI * 2);
            ctx.closePath();
            return;
        }
        pathRounded(ctx, x0, y0, width, height, kind === 'person' ? height / 2 : 6 + inflate);
    }

    /* The board's dark ink for a name, blue for a NATSAP member. */
    function inkOn(spec) {
        return spec.natsap ? NATSAP_INK : INK_TEXT;
    }

    /* A line's colour at full strength. Staff lines are drawn translucent so
     * a busy view stays calm, but the point where one lands has to show. */
    function solid(colour) {
        var m = /^rgba\(([^,]+),([^,]+),([^,]+),[^)]+\)$/.exec(String(colour).replace(/\s+/g, ''));
        return m ? 'rgb(' + m[1] + ',' + m[2] + ',' + m[3] + ')' : colour;
    }

    /* The dot where a line lands on a bubble. */
    function drawPort(ctx, x, y, colour) {
        ctx.beginPath();
        ctx.arc(x, y, PORT_R, 0, Math.PI * 2);
        ctx.fillStyle = solid(colour);
        ctx.fill();
        ctx.strokeStyle = SURFACE;
        ctx.lineWidth = 1.2;
        ctx.stroke();
    }

    /**
     * Where a route crosses the rim of one of its ends. The route starts at
     * the centre of its source and finishes at the centre of its target, so
     * walking in from the right end, the first point outside the box marks
     * the leg that crosses it. `from` is that outside point, which is the
     * direction an arrowhead arrives from. Null when the whole route sits
     * inside the box.
     */
    function rimPoint(pts, box, fromEnd) {
        var n = pts.length;
        for (var s = 1; s < n; s++) {
            var p = fromEnd ? pts[n - s] : pts[s - 1];
            var q = fromEnd ? pts[n - s - 1] : pts[s];
            if (q[0] >= box[0] && q[0] <= box[2] && q[1] >= box[1] && q[1] <= box[3]) continue;
            var t = 1;
            var dx = q[0] - p[0], dy = q[1] - p[1];
            if (q[0] > box[2] && dx) t = Math.min(t, (box[2] - p[0]) / dx);
            if (q[0] < box[0] && dx) t = Math.min(t, (box[0] - p[0]) / dx);
            if (q[1] > box[3] && dy) t = Math.min(t, (box[3] - p[1]) / dy);
            if (q[1] < box[1] && dy) t = Math.min(t, (box[1] - p[1]) / dy);
            t = Math.max(0, t);
            var hit = [p[0] + dx * t, p[1] + dy * t];
            hit.from = q;
            return hit;
        }
        return null;
    }

    /* Fill and outline for one node, whatever its shape. The path must
     * already be traced. Filled by status and outlined in the board's dark
     * ink; a rebrand is dashed, because the place carried on under another
     * name and the dash says "continues elsewhere". */
    function fillOutline(ctx, spec, alpha, heavy) {
        ctx.globalAlpha = alpha;
        ctx.fillStyle = STATUS_FILLS[spec.status] || STATUS_FILLS.unknown;
        ctx.fill();
        ctx.strokeStyle = OUTLINE;
        ctx.lineWidth = heavy ? BORDER_BUBBLE : BORDER_DOT;
        if (spec.status === 'rebranded' && ctx.setLineDash) ctx.setLineDash(heavy ? [5, 3] : [3, 2]);
        ctx.stroke();
        if (ctx.setLineDash) ctx.setLineDash([]);
    }

    /**
     * One node as a dot, at one place, at one size. The legend comes
     * through here too, so a swatch cannot drift from the thing it
     * describes.
     *
     * spec is mutated and reused by the draw loop rather than allocated per
     * node, so it must not be held on to.
     */
    function paintNode(ctx, spec, x, y, r, alpha) {
        ctx.beginPath();
        traceShape(ctx, spec.kind, x, y, r);
        fillOutline(ctx, spec, alpha);

        /* Deaths recorded in the memorial: a firm red ring outside the shape.
         * A warning mark, so a true red rather than the coral accent. */
        if (spec.deaths) {
            ctx.beginPath();
            ctx.arc(x, y, r + 3, 0, Math.PI * 2);
            ctx.strokeStyle = DEATH_RED;
            ctx.lineWidth = 2;
            ctx.stroke();
        }

        /* A dot has no name to turn blue, so membership is the ink of a
         * small ring instead. */
        if (spec.natsap) {
            ctx.beginPath();
            ctx.arc(x, y, r + (spec.deaths ? 5.5 : 2.5), 0, Math.PI * 2);
            ctx.strokeStyle = NATSAP_INK;
            ctx.lineWidth = 1.5;
            ctx.stroke();
        }

        ctx.globalAlpha = 1;
    }

    /** The same, as a bubble around a box on screen. */
    function paintBubble(ctx, spec, box, alpha) {
        ctx.beginPath();
        traceBubble(ctx, spec.kind, box, 0);
        fillOutline(ctx, spec, alpha, true);

        if (spec.deaths) {
            ctx.beginPath();
            traceBubble(ctx, spec.kind, box, RING_DEATHS);
            ctx.strokeStyle = DEATH_RED;
            ctx.lineWidth = 2.5;
            ctx.stroke();
        }
        ctx.globalAlpha = 1;
    }

    /**
     * Paint one legend swatch into its own small canvas. Same shapes, same
     * status fills, same memorial ring as the map itself. A NATSAP member's
     * name is blue on the map; a swatch has no name, so it shows the ink.
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
            kind: spec.kind || 'facility',
            status: spec.status || 'unknown',
            natsap: false,
            deaths: !!spec.deaths
        }, size / 2, size / 2, spec.kind === 'person' ? 6 : 7, 1);
        if (spec.natsap) {
            ctx.beginPath();
            ctx.moveTo(size / 2 - 4, size / 2 + 0.5);
            ctx.lineTo(size / 2 + 4, size / 2 + 0.5);
            ctx.strokeStyle = NATSAP_INK;
            ctx.lineWidth = 2;
            ctx.stroke();
        }
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

    /**
     * The circle a line wears for one of the people it stands for, on a short
     * length of that line: the stage-coloured disc that cuts the line, then
     * the circle in the hole, exactly as the map draws it.
     */
    function personSwatch(element) {
        if (!element || !element.getContext) return;
        var dpr = Math.min(root.devicePixelRatio || 1, 2);
        var w = 26;
        var h = 18;
        element.width = Math.round(w * dpr);
        element.height = Math.round(h * dpr);
        var ctx = element.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);

        var line = EDGE_STYLES.people;
        ctx.strokeStyle = line.colour;
        ctx.lineWidth = line.width;
        ctx.setLineDash(line.dash || []);
        ctx.beginPath();
        ctx.moveTo(1, h / 2);
        ctx.lineTo(w - 1, h / 2);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.beginPath();
        ctx.arc(w / 2, h / 2, MARKER_R + 2.5, 0, Math.PI * 2);
        ctx.fillStyle = SURFACE;
        ctx.fill();
        ctx.beginPath();
        ctx.arc(w / 2, h / 2, MARKER_R, 0, Math.PI * 2);
        ctx.fillStyle = STATUS_FILLS.unknown;
        ctx.fill();
        ctx.strokeStyle = OUTLINE;
        ctx.lineWidth = BORDER_BUBBLE;
        ctx.stroke();
    }

    function create(canvas) {
        var ctx = canvas.getContext('2d');

        var renderer = {
            /* Shared with the viewport, which owns panning and zooming and
             * mutates this object in place. */
            transform: { k: 1, x: 0, y: 0 },
            crossRegionMode: false,
            /* Set by focus.js.
             *   hoverId    the node under the pointer, ringed and labelled
             *   near       id set that stays lit; everything else drops to dim
             *   nearEdges  edge id set that stays lit
             *   dim        alpha for everything outside near, 0.15 by default
             *   offsets    id to [dx, dy] world-space display offset: the
             *              hover gather, which never touches stored positions
             *   hoverEdges edge id set under the pointer, drawn heavier
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
        /* Edge id to its place among the lines joining the same pair; see
         * rebuildBuckets. */
        var parallelOf = Object.create(null);
        var chainIndex = null;
        var frameStamp = 0;
        /* Bumped whenever the scene changes, so cached routes are dropped. */
        var sceneStamp = 0;
        /* Last frame's routes, keyed by what they depend on; see draw(). */
        var routeMemo = { sig: '', pts: Object.create(null) };
        /* The zoom last drawn, and the frames still to wait after it
         * changes before the lines are routed again; see draw(). */
        var lastK = 0;
        var settleFrames = 0;
        var SETTLE_FRAMES = 6;
        /* Reused by the draw loop so a frame does not allocate one spec per
         * node; paintNode never holds on to it. */
        var scratch = { kind: '', status: '', natsap: false, deaths: 0 };

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

        /* Only affects how edges are drawn; the store decides which ones
         * survive the filter. */
        renderer.setCrossRegionMode = function (on) {
            renderer.crossRegionMode = !!on;
            rebuildBuckets();
        };

        /* Kept for the layout, which still describes its rows; lines no
         * longer run in the gutters between them, so nothing here reads it. */
        renderer.setGrid = function () {};

        /* Node id to a size multiplier: the name that was just clicked is
         * drawn bigger than the rest, and swells into it. */
        var grow = Object.create(null);
        renderer.setGrow = function (next) {
            grow = next || Object.create(null);
        };

        /** A node's dot radius on screen at zoom k, for a node drawn
         * without its bubble. A grown node scales with it. */
        function dotR(node, k) {
            var g = grow[node.id] || 1;
            return Math.min(DOT_MAX, Math.max(DOT_MIN, node.r * k)) * g;
        }

        /**
         * The box a node's bubble takes on screen, centred on (cx, cy): the
         * name, the years under it where there are any, and the padding
         * round both. A person's pill is a little wider, because its round
         * ends take room the name cannot use.
         */
        /**
         * The width a bubble is sized to: its name, and its years, which are
         * a short bounded stamp. Nothing else widens it.
         *
         * A former name is as long as a name, and a bubble grown to hold one
         * pushes every neighbour away - the cost of an annotation falling on
         * the whole view rather than on the name that carries it. Measured
         * against the board, that cost was real: six of seventy lines stopped
         * running straight. So the old name takes the room the name leaves
         * and is cut to fit, with the whole of it in the drawer.
         */
        function baseWidth(node) {
            if (node._baseW === undefined) {
                var nameW = textWidth(ctx, node, null, LABEL_SIZE) * (node.degree >= 8 ? 1.08 : 1);
                ctx.font = YEARS_SIZE + 'px ' + FONT;
                /* The name and its years size the bubble, exactly as they
                 * always have: a short name with a long span of years is
                 * still as wide as the years. */
                var core = Math.max(nameW, node.years ? ctx.measureText(node.years).width : 0);
                var subW = 0;
                var subs = subLines(node);
                for (var s = 0; s < subs.length; s++) {
                    subW = Math.max(subW, ctx.measureText(subs[s]).width);
                }
                /* The old name takes the room they leave, and a little more;
                 * past SUB_STRETCH of that it is cut, with the whole of it in
                 * the drawer. Measured against the board, letting it stretch
                 * the bubble freely put six of seventy lines off straight -
                 * the cost of one name's annotation falling on every name in
                 * the view. */
                node._baseW = Math.max(core, Math.min(subW, core * SUB_STRETCH));
            }
            return node._baseW;
        }

        /**
         * A sub-line cut to the room the name leaves it, with an ellipsis
         * where it had to be cut. The cut is worked out once per node and
         * line and then held: the text does not change, and the ratio of
         * room to text does not either, because both scale together.
         */
        function fitSub(node, index, text, room) {
            if (!node._fitSub) node._fitSub = [];
            if (node._fitSub[index] !== undefined) return node._fitSub[index];
            var fitted = text;
            if (ctx.measureText(text).width > room) {
                var cut = text.length;
                while (cut > 1 && ctx.measureText(text.slice(0, cut) + '\u2026').width > room) cut--;
                fitted = text.slice(0, cut).replace(/[ ,]+$/, '') + '\u2026';
            }
            node._fitSub[index] = fitted;
            return fitted;
        }

        function bubbleBox(node, cx, cy, scale) {
            var subs = subLines(node);
            var hh = ((LABEL_LINE + subs.length * YEARS_LINE) / 2 + BUBBLE_PAD_Y) * scale;
            var hw = (baseWidth(node) / 2 + BUBBLE_PAD_X) * scale;
            var nameW = baseWidth(node);
            /* An ellipse holds a box only well inside its axes: with the
             * name's half-height at 0.57 of the ellipse's, the half-width
             * has to be the name's over 0.82. */
            if (node.kind === 'person') hw = Math.max(hh * 1.4, (nameW / 2) / 0.82 + BUBBLE_PAD_X * 0.5 * scale);
            return [cx - hw, cy - hh, cx + hw, cy + hh];
        }

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

            /* Two lines between the same pair - one owns the other and they
             * shared a campus, say - would lie on top of each other, so each
             * is set a little to one side. The side is fixed by the pair,
             * not by which end each line calls its source. */
            var pairs = Object.create(null);
            scene.edges.forEach(function (e) {
                var key = e.sourceId < e.targetId ? e.sourceId + '|' + e.targetId : e.targetId + '|' + e.sourceId;
                (pairs[key] = pairs[key] || []).push(e);
            });
            parallelOf = Object.create(null);
            Object.keys(pairs).forEach(function (key) {
                var list = pairs[key];
                if (list.length < 2) return;
                list.forEach(function (e, i) {
                    parallelOf[e.id] = { at: (i - (list.length - 1) / 2) * PARALLEL_GAP, flip: e.sourceId > e.targetId };
                });
            });
            sceneStamp++;
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

        /* A node's fill: its status, as on the board's key. */
        renderer.colourFor = function (node) {
            return STATUS_FILLS[node.status] || STATUS_FILLS.unknown;
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

            /* --- bubbles ---
             *
             * Every node is drawn as a bubble with its name inside it, sized
             * to the name: a pill for a person, a rounded box for an
             * organisation. A name hung under a small shape made the shape
             * the smallest thing on the map and left the reader matching
             * names to marks; inside the bubble there is nothing to match.
             *
             * Bubbles are placed before anything is routed, most connected
             * first, into the same collision grid the captions use later. One
             * that cannot sit clear of the others is drawn as a plain dot
             * instead of on top of them - which the layout's zoom rule
             * (dropsAt) makes sure does not happen at the zoom it chooses.
             */
            var labelGrid = Object.create(null); /* not "grid": a var here hoists over draw() and would shadow the layout grid the router reads */
            var bubbles = new Array(scene.nodes.length);
            var order = [];
            for (i = 0; i < scene.nodes.length; i++) {
                bubbles[i] = null;
                if (sx[i] < -300 || sx[i] > w + 300 || sy[i] < -60 || sy[i] > h + 60) continue;
                order.push(i);
            }
            order.sort(function (a, b) {
                var ha = scene.nodes[a].id === hoverId, hb = scene.nodes[b].id === hoverId;
                if (ha !== hb) return ha ? -1 : 1;
                /* With a neighbourhood lit, its bubbles claim their room
                 * before the dimmed background does. */
                if (near) {
                    var la = !!near[scene.nodes[a].id], lb = !!near[scene.nodes[b].id];
                    if (la !== lb) return la ? -1 : 1;
                }
                return scene.nodes[b].degree - scene.nodes[a].degree;
            });
            var drawn = [];
            for (var oi = 0; oi < order.length; oi++) {
                i = order[oi];
                node = scene.nodes[i];
                var scale = (grow[node.id] || 1) * (node.id === hoverId ? LABEL_SIZE_HOVER / LABEL_SIZE : 1);
                var box = bubbleBox(node, sx[i], sy[i], scale);
                var room = [box[0] - BUBBLE_GAP, box[1] - BUBBLE_GAP, box[2] + BUBBLE_GAP, box[3] + BUBBLE_GAP];
                if (!fitsInGrid(labelGrid, room)) continue;
                occupyGrid(labelGrid, room);
                bubbles[i] = { node: node, box: box, scale: scale };
                drawn.push(bubbles[i]);
            }
            /* Published for hit testing: the whole bubble is the node. */
            renderer.labelHits = drawn;

            /* The box a node takes on screen: its bubble, or its dot. */
            var extent = new Array(scene.nodes.length);
            for (i = 0; i < scene.nodes.length; i++) {
                if (bubbles[i]) {
                    extent[i] = bubbles[i].box;
                } else {
                    var dr = dotR(scene.nodes[i], k);
                    extent[i] = [sx[i] - dr, sy[i] - dr, sx[i] + dr, sy[i] + dr];
                }
            }

            /* What a line must not cross: every node's box, with its
             * clearance. In screen space, one box per node, indexed like
             * the scene. */
            var blockers = new Array(scene.nodes.length);
            for (i = 0; i < scene.nodes.length; i++) {
                var ex = extent[i];
                blockers[i] = [ex[0] - TRACE_CLEARANCE, ex[1] - TRACE_CLEARANCE,
                    ex[2] + TRACE_CLEARANCE, ex[3] + TRACE_CLEARANCE];
            }
            renderer.blockers = blockers;

            /* A route depends on where the boxes sit relative to each
             * other, not on where the stage is looking, so a pan reuses
             * last frame's routes shifted along; only a zoom, a motion or a
             * change of scene routes again. The key is every box's place
             * relative to the stage's translation, to the half pixel. */
            /* A zoom moves every box every frame. Lines go straight while
             * it runs, and once the zoom has held for a few frames one more
             * draw is asked for, which routes them where they have landed.
             * The redraw is the viewport's to give (renderer.redraw). */
            var zooming = k !== lastK;
            lastK = k;
            if (zooming && renderer.redraw && root.requestAnimationFrame) {
                var wasWaiting = settleFrames > 0;
                settleFrames = SETTLE_FRAMES;
                if (!wasWaiting) {
                    var tick = function () {
                        if (--settleFrames > 0) { root.requestAnimationFrame(tick); return; }
                        renderer.redraw();
                    };
                    root.requestAnimationFrame(tick);
                }
            }
            /* Names in motion (a click's yoyo, a hover's gather, a zoom in
             * progress) move every frame, and a detour worked out for one
             * frame is wrong the next: lines go straight until they settle,
             * and are routed once, where they land. A view with more names
             * than DETOUR_MAX_NODES is a field of dots and goes straight
             * throughout. */
            var straightOnly = scene.nodes.length > DETOUR_MAX_NODES || !!offsets || zooming;
            /* The flag is part of the key: routes drawn straight for a
             * frame in motion must not be served to the frame at rest. */
            var sigParts = [k, sceneStamp, straightOnly ? 1 : 0];
            for (i = 0; i < scene.nodes.length; i++) {
                var ex2 = extent[i];
                sigParts.push(Math.round((ex2[0] - t.x) * 2), Math.round((ex2[1] - t.y) * 2),
                    Math.round((ex2[2] - t.x) * 2), Math.round((ex2[3] - t.y) * 2));
            }
            var sig = sigParts.join(',');
            if (routeMemo.sig !== sig) routeMemo = { sig: sig, pts: Object.create(null) };
            var memo = routeMemo.pts;
            var routeFor = function (edge, a, c) {
                var cached = memo[edge.id];
                if (!cached) {
                    var ax = sx[a], ay = sy[a], cx = sx[c], cy = sy[c];
                    var par = parallelOf[edge.id];
                    if (par) {
                        var len = Math.hypot(cx - ax, cy - ay) || 1;
                        var nx = -(cy - ay) / len * par.at * (par.flip ? -1 : 1);
                        var ny = (cx - ax) / len * par.at * (par.flip ? -1 : 1);
                        ax += nx; ay += ny; cx += nx; cy += ny;
                    }
                    var pts = routeEdge(ax, ay, cx, cy, blockers, a, c, straightOnly);
                    cached = memo[edge.id] = pts.map(function (p) { return [p[0] - t.x, p[1] - t.y]; });
                }
                return cached.map(function (p) { return [p[0] + t.x, p[1] + t.y]; });
            };
            var routes = [];

            /* --- edges ---
             *
             * Each style bucket is stroked once at full alpha and once dimmed,
             * rather than per edge, so hover costs one extra path per style
             * instead of thirteen hundred alpha changes. With no emphasis set
             * the dim pass is skipped and this is the step 3 single pass.
             *
             * Lines run to the centre of each end and the bubble is painted
             * over them, so a line always meets its bubble: there is no gap
             * between the end of a line and the thing it connects to. */
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
                        var route = { pts: routeFor(edge, a, c), edge: edge, style: style, lit: lit };
                        strokeRoute(ctx, route.pts);
                        routes.push(route);
                        drew = true;
                    }
                    if (drew) ctx.stroke();
                }
            }
            ctx.setLineDash([]);
            ctx.globalAlpha = 1;
            renderer.routes = routes;

            /* --- the line under the pointer ---
             *
             * Stroked again, heavier, over a band of the stage colour, so it
             * lifts off whatever it runs alongside. Nothing else changes:
             * the pointer crosses a dozen lines on its way anywhere, and a
             * map that dimmed for each one would never stop flickering. */
            var hoverEdges = emphasis.hoverEdges || null;
            if (hoverEdges) {
                for (i = 0; i < routes.length; i++) {
                    if (!hoverEdges[routes[i].edge.id]) continue;
                    var hs = routes[i].style;
                    ctx.beginPath();
                    strokeRoute(ctx, routes[i].pts);
                    ctx.strokeStyle = SURFACE;
                    ctx.lineWidth = hs.width + 6;
                    ctx.stroke();
                    ctx.beginPath();
                    strokeRoute(ctx, routes[i].pts);
                    ctx.strokeStyle = solid(hs.colour);
                    ctx.lineWidth = hs.width + 1.6;
                    ctx.setLineDash(hs.dash || []);
                    ctx.stroke();
                    ctx.setLineDash([]);
                }
            }

            /* --- nodes --- */
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                var ext = extent[i];
                if (ext[2] < 0 || ext[0] > w || ext[3] < 0 || ext[1] > h) continue;

                var hovered = node.id === hoverId;
                /* Dimming is a multiplier, so a status-unrecorded node that is
                 * also off the neighbourhood ends up fainter than either rule
                 * would make it alone, which is the right reading of both. */
                var nodeLit = !near || !!near[node.id];
                var alpha = nodeLit ? 1 : dim;

                scratch.kind = node.kind;
                scratch.status = node.status;
                scratch.natsap = node.natsap;
                scratch.deaths = node.deaths;

                var bubble = bubbles[i];
                if (!bubble) {
                    var r = dotR(node, k);
                    paintNode(ctx, scratch, sx[i], sy[i], r, alpha);
                    if (hovered) {
                        ctx.beginPath();
                        ctx.arc(sx[i], sy[i], r + 5, 0, Math.PI * 2);
                        ctx.strokeStyle = OUTLINE;
                        ctx.lineWidth = 2;
                        ctx.stroke();
                    }
                    continue;
                }

                var bb3 = bubble.box;
                paintBubble(ctx, scratch, bb3, alpha);
                if (hovered) {
                    ctx.beginPath();
                    traceBubble(ctx, node.kind, bb3, RING_HOVER);
                    ctx.strokeStyle = OUTLINE;
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }

                /* The name, and under it the years and the name the place
                 * traded under before where the record has them, in
                 * whichever of navy or white reads on the fill. */
                var ink = inkOn(scratch);
                var size = LABEL_SIZE * bubble.scale;
                var subs = subLines(node);
                var nameY = (bb3[1] + bb3[3]) / 2 - subs.length * YEARS_LINE * bubble.scale / 2;
                ctx.globalAlpha = alpha;
                ctx.fillStyle = ink;
                ctx.font = (node.degree >= 8 ? '600 ' : '') + size + 'px ' + FONT;
                ctx.fillText(node.name, sx[i], nameY + 0.5);
                if (subs.length) {
                    ctx.globalAlpha = alpha * 0.8;
                    ctx.font = (YEARS_SIZE * bubble.scale) + 'px ' + FONT;
                    var room = baseWidth(node) * bubble.scale;
                    for (var si = 0; si < subs.length; si++) {
                        ctx.fillText(fitSub(node, si, subs[si], room), sx[i],
                            nameY + (LABEL_LINE / 2 + YEARS_LINE / 2 + si * YEARS_LINE) * bubble.scale);
                    }
                }
                ctx.globalAlpha = 1;
            }

            /* --- where each line meets its ends ---
             *
             * A dot in the line's own colour on the rim of the bubble, at
             * both ends, so every connection visibly lands on something even
             * where two lines arrive side by side. A directed line gets its
             * arrowhead at the far end instead: "became" and "acquired" are
             * the two statements on this map that are wrong if you read them
             * backwards. */
            for (i = 0; i < routes.length; i++) {
                var rt = routes[i];
                var redge = rt.edge;
                var rstyle = styleFor(redge, renderer.crossRegionMode, lineColour);
                ctx.globalAlpha = near ? (rt.lit ? 1 : dim * edgeFade) : edgeFade;
                ctx.fillStyle = rstyle.colour;
                var pts = rt.pts;
                var start = rimPoint(pts, extent[redge.source._i], false);
                var end = rimPoint(pts, extent[redge.target._i], true);
                if (start) drawPort(ctx, start[0], start[1], rstyle.colour);
                if (rstyle.arrow && end) {
                    var before = end.from;
                    drawArrow(ctx, before[0], before[1], end[0], end[1], 0,
                        Math.max(6, Math.min(11, 7 * Math.sqrt(k))));
                } else if (end) {
                    drawPort(ctx, end[0], end[1], rstyle.colour);
                }
            }
            ctx.globalAlpha = 1;

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
                    var eb = extent[i];
                    var bx = bubbles[i] ? eb[2] - 4 : sx[i] + (eb[2] - sx[i]) * 0.75;
                    var by = bubbles[i] ? eb[1] : sy[i] - (sy[i] - eb[1]) * 0.75;
                    /* Judged by the name's own centre: a wide bubble half
                     * off the stage still carries its count. */
                    if (sx[i] < -20 || sx[i] > w + 20 || sy[i] < -20 || sy[i] > h + 20) continue;
                    var text = '+' + extra;
                    var bw = Math.max(BADGE_SIZE + 4, ctx.measureText(text).width + 6);
                    var bh = BADGE_SIZE + 4;
                    ctx.beginPath();
                    roundedRect(ctx, bx - bw / 2, by - bh / 2, bw, bh, bh / 2);
                    ctx.fillStyle = '#000435';
                    ctx.fill();
                    ctx.strokeStyle = SURFACE;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillText(text, bx, by + 0.5);
                    badges++;
                }
            }
            renderer.badgesDrawn = badges;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            /* --- the people on a line ---
             *
             * A line that stands for people carries them: one small circle
             * per person across its middle, drawn like a person's bubble
             * with the name left out, and a "+N" pill where the line has
             * no room for them all. Pointing at a circle asks the popup
             * about that one person; the pill, about the rest. Placed into
             * the same collision grid as the names, after them, so a name
             * always wins, and before the captions, so a caption never
             * lands on a circle. With a neighbourhood lit, only its own
             * lines carry their people. */
            var markers = [];
            var hoverMarker = emphasis.hoverMarker || null;
            ctx.font = '600 ' + MARKER_SIZE + 'px ' + FONT;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            for (i = 0; i < routes.length; i++) {
                var mroute = routes[i];
                var medge = mroute.edge;
                if (near && !(nearEdges ? nearEdges[medge.id]
                    : (near[medge.sourceId] && near[medge.targetId]))) continue;
                var people = peopleOf(medge);
                if (!people.length) continue;
                var placed = placeMarkers(ctx, mroute.pts, extent[medge.source._i], extent[medge.target._i],
                    people.length, labelGrid, blockers, w, h);
                if (!placed) continue;
                for (var ci = 0; ci < placed.circles.length; ci++) {
                    var cpt = placed.circles[ci];
                    var mkey = medge.id + '#' + ci;
                    markers.push({
                        kind: 'person', key: mkey, edge: medge, people: [people[ci]],
                        x: cpt[0], y: cpt[1], r: MARKER_R, box: placed.boxes[ci]
                    });
                    /* A disc of the stage colour first, so the line is cut
                     * either side of the circle rather than running through
                     * it. */
                    ctx.beginPath();
                    ctx.arc(cpt[0], cpt[1], MARKER_R + 2.5, 0, Math.PI * 2);
                    ctx.fillStyle = SURFACE;
                    ctx.fill();
                    ctx.beginPath();
                    ctx.arc(cpt[0], cpt[1], MARKER_R, 0, Math.PI * 2);
                    ctx.fillStyle = STATUS_FILLS.unknown;
                    ctx.fill();
                    ctx.strokeStyle = OUTLINE;
                    ctx.lineWidth = BORDER_BUBBLE;
                    ctx.stroke();
                    if (hoverMarker === mkey) {
                        ctx.beginPath();
                        ctx.arc(cpt[0], cpt[1], MARKER_R + 3.5, 0, Math.PI * 2);
                        ctx.strokeStyle = OUTLINE;
                        ctx.lineWidth = 2;
                        ctx.stroke();
                    }
                }
                if (placed.more) {
                    var pm = placed.more;
                    var pkey = medge.id + '#more';
                    markers.push({
                        kind: 'more', key: pkey, edge: medge, people: people.slice(placed.circles.length),
                        x: pm.x, y: pm.y, r: pm.h / 2, box: placed.boxes[placed.boxes.length - 1]
                    });
                    ctx.beginPath();
                    roundedRect(ctx, pm.x - pm.w / 2, pm.y - pm.h / 2, pm.w, pm.h, pm.h / 2);
                    ctx.fillStyle = hoverMarker === pkey ? '#000080' : '#000435';
                    ctx.fill();
                    ctx.strokeStyle = SURFACE;
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillText('+' + pm.count, pm.x, pm.y + 0.5);
                }
            }
            renderer.markers = markers;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

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
                    ctx.fillStyle = 'rgba(26, 26, 26, 0.8)';
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
         * How many names would be dropped if these nodes were drawn at this
         * transform: the bubble pass run dry, with the same order and the
         * same collision grid, and no hover. Nodes off the stage do not
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
                if (cx < -300 || cx > w + 300 || cy < -60 || cy > h + 60) continue;
                entries.push({ node: node, cx: cx, cy: cy });
            }
            entries.sort(function (a, b) { return b.node.degree - a.node.degree; });
            var grid = Object.create(null);
            var dropped = 0;
            for (var j = 0; j < entries.length; j++) {
                var entry = entries[j];
                /* Drawn grown, measured grown: what was clicked is bigger. */
                var box = bubbleBox(entry.node, entry.cx, entry.cy, grow[entry.node.id] || 1);
                var room = [box[0] - BUBBLE_GAP, box[1] - BUBBLE_GAP, box[2] + BUBBLE_GAP, box[3] + BUBBLE_GAP];
                if (!fitsInGrid(grid, room)) {
                    /* Only a name that would have been on the stage counts. */
                    if (entry.cx >= 0 && entry.cx <= w && entry.cy >= 0 && entry.cy <= h) dropped++;
                    continue;
                }
                occupyGrid(grid, room);
            }
            return dropped;
        };

        /**
         * The line under a canvas point, or null: the nearest route drawn
         * last frame that passes within `slop` pixels. Routes are measured
         * as the straight legs they were planned as; the rounding on a
         * corner is a few pixels and well inside any slop a pointer needs.
         * With a neighbourhood lit, a dimmed line is not there to be hit.
         *
         * A company's lines share a trunk down the gutter, by design, so
         * on the trunk the pointer is on several at once. The one whose far
         * end is nearest the pointer wins: moving along a trunk walks
         * through the places it serves in the order they branch off, and
         * the heavier stroke on the hovered line shows which one it is.
         */
        renderer.edgeAt = function (px, py, slop) {
            var list = renderer.routes || [];
            var hits = [];
            var nearest = Infinity;
            for (var i = 0; i < list.length; i++) {
                if (list[i].lit === false) continue;
                var pts = list[i].pts;
                var d = Infinity;
                for (var j = 1; j < pts.length; j++) {
                    var ax = pts[j - 1][0], ay = pts[j - 1][1];
                    var dx = pts[j][0] - ax, dy = pts[j][1] - ay;
                    var len2 = dx * dx + dy * dy;
                    var t = len2 ? ((px - ax) * dx + (py - ay) * dy) / len2 : 0;
                    t = t < 0 ? 0 : (t > 1 ? 1 : t);
                    d = Math.min(d, Math.hypot(px - (ax + dx * t), py - (ay + dy * t)));
                }
                if (d > slop) continue;
                var first = pts[0], last = pts[pts.length - 1];
                hits.push({
                    edge: list[i].edge, d: d,
                    end: Math.min(Math.hypot(px - first[0], py - first[1]), Math.hypot(px - last[0], py - last[1]))
                });
                if (d < nearest) nearest = d;
            }
            var best = null;
            for (var h = 0; h < hits.length; h++) {
                /* Lines a pixel or two apart are the same line to a pointer. */
                if (hits[h].d > nearest + 2) continue;
                /* Later routes are drawn over earlier ones, so a tie goes
                 * to the one on top. */
                if (!best || hits[h].end <= best.end) best = hits[h];
            }
            return best ? best.edge : null;
        };

        /**
         * The circle or "+N" pill under a canvas point, or null: the nearest
         * marker drawn last frame within `slop` pixels of its edge. Asked
         * before edgeAt, since a marker sits on its line and the more
         * particular answer wins.
         */
        renderer.markerAt = function (px, py, slop) {
            var list = renderer.markers || [];
            var best = null;
            var nearest = Infinity;
            for (var i = 0; i < list.length; i++) {
                var m = list[i];
                var d;
                if (m.kind === 'person') {
                    d = Math.hypot(px - m.x, py - m.y) - m.r;
                } else {
                    var b = m.box;
                    d = Math.hypot(Math.max(b[0] - px, 0, px - b[2]), Math.max(b[1] - py, 0, py - b[3]));
                }
                if (d <= slop && d < nearest) { best = m; nearest = d; }
            }
            return best;
        };

        /**
         * The on-screen box a node's bubble takes, with the gap it keeps
         * from its neighbours: width and height in pixels. focus.js uses it
         * to work out the lowest zoom at which neighbouring bubbles still
         * clear each other, so the two cannot disagree about what a name
         * needs.
         */
        renderer.labelBox = function (node) {
            var box = bubbleBox(node, 0, 0, 1);
            return {
                width: box[2] - box[0] + BUBBLE_GAP * 2,
                height: box[3] - box[1] + BUBBLE_GAP * 2
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
        LABEL_PITCH: LABEL_LINE + BUBBLE_PAD_Y * 2 + BUBBLE_GAP * 2,
        /* And how much more a name with a years line needs. */
        YEARS_LINE: YEARS_LINE,
        /* The small lines under a name, so a layout working without a live
         * renderer budgets the same room the painter will use. */
        subLines: subLines,
        styleFor: styleFor,
        isPeopleLine: isPeopleLine,
        peopleOn: peopleOn,
        peopleOf: peopleOf,
        MARKER_MAX: MARKER_MAX,
        edgeSwatch: edgeSwatch,
        personSwatch: personSwatch,
        edgeFadeFor: edgeFadeFor,
        swatch: swatch,
        segmentHitsBox: segmentHitsBox,
        routeEdge: routeEdge,
        create: create,
        STATUS_FILLS: STATUS_FILLS,
        OUTLINE: OUTLINE,
        NATSAP_INK: NATSAP_INK,
        CHAIN_COLOURS: CHAIN_COLOURS,
        CHAIN_NONE: CHAIN_NONE,
        EDGE_STYLES: EDGE_STYLES,
        SURFACE: SURFACE
    };
})(typeof self !== 'undefined' ? self : this);
