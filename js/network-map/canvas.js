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
     * NATSAP member. What a thing is (a person, a program, a company) is
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
     * program continuing under another name, an acquisition is one company
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
         * two people to each other rather than a person to a program, and
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
    /* A bubble's outline carries its company's colour (see clusterInk), so
     * it is drawn heavy enough to read as a colour rather than as a hair
     * round the fill: at 1.5px the difference between navy and teal was
     * only visible if you already knew it was there. The memorial ring
     * steps out to clear it. */
    var BORDER_BUBBLE = 2.6;
    var BORDER_DOT = 1.8;
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
     * "formerly Sequel TSI Owens Cross R\u2026" says less than "formerly
     * Sequel TSI Owens Cross Rds +1", which at least admits there is more. */
    var FORMER_CHARS = 34;
    /* How far a sub-line may stretch its bubble past the name's own width;
     * see baseWidth. */
    var SUB_STRETCH = 1;
    /* Longest a name can be and still read as initials rather than a name. */
    var ABBREV_MAX = 8;

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
    /** The words of a name, for comparing one name against another. */
    function wordsOf(name) {
        return String(name).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().split(' ').filter(Boolean);
    }

    /**
     * Whether a name is initials rather than a name: PCS for Provo Canyon
     * School, SCISU for Second Chances in Southern Utah. No lower case and
     * no space, in a few characters, is what an abbreviation looks like.
     *
     * They stay in the drawer and in search, where someone who types PCS
     * still finds the school. They do not go on the board, where they tell
     * a reader nothing the name above them does not already say.
     */
    function isAbbreviation(name) {
        var text = String(name).trim();
        return text.length <= ABBREV_MAX && text.indexOf(' ') === -1 && text.toUpperCase() === text;
    }

    /* Words that carry no name of their own, so their coming and going does
     * not make one name into another. */
    var FILLER = { of: 1, the: 1, and: 1, at: 1, for: 1, in: 1, a: 1, inc: 1, llc: 1 };

    function namingWords(name) {
        return wordsOf(name).filter(function (word) { return !FILLER[word]; });
    }

    /**
     * Whether a word is the initials of a run of words in the name: "djs"
     * for Department of Juvenile Services.
     */
    function initialsOf(word, held) {
        if (word.length < 2) return false;
        for (var start = 0; start + word.length <= held.length; start++) {
            var same = true;
            for (var i = 0; i < word.length; i++) {
                if (held[start + i].charAt(0) !== word.charAt(i)) { same = false; break; }
            }
            if (same) return true;
        }
        return false;
    }

    /**
     * Whether a name is the node's own name said differently rather than
     * another name it went by.
     *
     * It is the same name when it brings no new word: every word of it is
     * already in the name above, or is a shortened form of one ("Dept." for
     * Department, "Cantril" for Cantrill), or spells out the initials of a
     * run of them ("Maryland DJS"). Words that name nothing - of, the, and -
     * are not counted either way.
     *
     * So "J Atkin" under J Ralph Atkin, "CEDU" under CEDU Family of
     * Services and "Three Springs of Duck River" under Three Springs Duck
     * River all stay off the board, while "Green Valley Academy" under
     * WayPoint Academy goes on it.
     */
    /**
     * Whether a word is a shortened form of a longer one: its letters in
     * order, first letter included. "Dept" for Department, which a prefix
     * test misses because department is spelt with an a where dept has a t.
     */
    function isContraction(word, held) {
        if (word.length < 3 || held.length <= word.length || held.charAt(0) !== word.charAt(0)) return false;
        var at = 0;
        for (var i = 0; i < held.length && at < word.length; i++) {
            if (held.charAt(i) === word.charAt(at)) at++;
        }
        return at === word.length;
    }

    function isSameName(name, of) {
        var held = namingWords(of);
        var words = namingWords(name);
        if (!words.length) return true;
        return words.every(function (word) {
            for (var i = 0; i < held.length; i++) {
                if (held[i] === word || isContraction(word, held[i])) return true;
            }
            return initialsOf(word, held);
        });
    }

    /**
     * The other names a node carries that are worth drawing on the board:
     * everything it answers to, less the initials and the shorter ways of
     * saying its own name.
     */
    function boardOtherNames(node) {
        var seen = Object.create(null);
        return (node.otherNames || []).concat(node.aliases || []).filter(function (name) {
            var key = String(name).toLowerCase();
            if (seen[key] || isAbbreviation(name) || isSameName(name, node.name)) return false;
            seen[key] = true;
            return true;
        });
    }

    /** "a, b, c", or the first and a count once that runs too long. */
    function nameList(prefix, names) {
        var text = prefix + ' ' + names.join(', ');
        if (text.length <= FORMER_CHARS) return text;
        return prefix + ' ' + names[0] + (names.length > 1 ? ' +' + (names.length - 1) : '');
    }

    function subLines(node) {
        if (node._subLines !== undefined) return node._subLines;
        var lines = [];
        if (node.years) lines.push(node.years);

        /* What the place was called, then what else it is called. The first
         * is a claim about time and comes first; the second only says the
         * same place answers to another name. */
        var said = [];
        var former = node.formerNames || [];
        if (former.length) said.push(nameList('formerly', former));
        else if (node.currentName) said.push('now ' + node.currentName);
        var also = boardOtherNames(node);
        if (also.length) said.push(nameList('also', also));
        var text = said.join(' \u00b7 ');

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

    /* --- going round the outside ---
     *
     * A route used to be judged on length alone, and a straight line that
     * happened to thread a gap between two names was taken without a
     * second thought. That is how the middle of a cluster filled up: every
     * long connection ran through it, because the shortest way between two
     * names on opposite sides of a hub is across the hub, and the gutters
     * between names are wide enough to let a line through. The result read
     * as a knot with names round it rather than as a structure.
     *
     * So length is no longer the whole cost. A leg is charged extra for
     * every name it passes close to, and a route is searched for even when
     * the straight line is not blocked: a line that brushes past several
     * names now loses to one that swings out round them, as long as the
     * way round is not so much longer that it stops reading as the same
     * connection. Short lines inside one cluster never qualify - bowing
     * those would only say two names are further apart than they are.
     */
    /* How close to a name a line passes before it counts as running
     * through the crowd rather than round it. */
    var CROWD_HALO = 24;
    /* What each name a leg brushes past adds to that leg's length. Two
     * names make a leg cost twice what it measures, which is about where a
     * way round the outside starts to win. */
    var CROWD_WEIGHT = 0.5;
    /* A line shorter than this stays inside one cluster and goes straight. */
    var BOW_MIN_LENGTH = 150;
    /* How many names a clear straight line has to brush past before a way
     * round is worth searching for. */
    var BOW_MIN_CROWD = 2;
    /* The longest a way round may be, against the straight line it
     * replaces. Past this the detour is a different story from the
     * connection it is meant to draw. */
    var BOW_MAX = 2.2;
    /* A crowd this big is not something one line can get round. */
    var BOW_MAX_NEAR = 60;
    /* A bow turns its corners out here rather than at DETOUR_MARGIN: it is
     * not squeezing past one name in the way, it is going round a group,
     * and it should visibly clear them. */
    var BOW_MARGIN = 12;
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
        /* How many names a leg brushes past: the boxes again, each grown
         * by the halo, so a line squeezing between two names is charged
         * for both of them. */
        var crowd = function (p, q, skipP, skipQ) {
            var n = 0;
            for (var j = 0; j < near.length; j++) {
                var idx = nearIndex[j];
                if (idx === skipP || idx === skipQ) continue;
                var bb = near[j];
                if (segmentHitsBox(p, q, [bb[0] - CROWD_HALO, bb[1] - CROWD_HALO,
                    bb[2] + CROWD_HALO, bb[3] + CROWD_HALO])) n++;
            }
            return n;
        };
        var cost = function (p, q, skipP, skipQ) {
            return Math.hypot(q[0] - p[0], q[1] - p[1]) *
                (1 + CROWD_WEIGHT * crowd(p, q, skipP, skipQ));
        };

        if (clear(a, b, skipA, skipB)) return bow(a, b, near, nearIndex, clear, cost, crowd, skipA, skipB);
        if (near.length > DETOUR_MAX_NEAR) return [a, b];

        var inWay = [];
        var others = [];
        for (var j = 0; j < near.length; j++) {
            if (nearIndex[j] === skipA || nearIndex[j] === skipB) continue;
            others.push(near[j]);
            if (segmentHitsBox(a, b, near[j])) inWay.push(near[j]);
        }
        var path = detour(a, b, cornersOf(inWay), clear, skipA, skipB, cost);
        if (!path && others.length > inWay.length) {
            path = detour(a, b, cornersOf(others, a, b, DETOUR_CORNERS), clear, skipA, skipB, cost);
        }
        if (!path && others.length * 4 > DETOUR_CORNERS) {
            path = detour(a, b, cornersOf(others, a, b, DETOUR_CORNERS_WIDE), clear, skipA, skipB, cost);
        }
        return path || [a, b];
    }

    /**
     * A clear straight line, or - where it runs through the crowd rather
     * than round it - the way round.
     *
     * The turning points offered are the corners of the names the line
     * brushes past, set well out, and eight points on the outside of the
     * whole local crowd: its bounding box's corners and the middle of each
     * of its sides. Those eight are what let a route leave the structure
     * altogether and come back on the far side, which the corners of the
     * names in the middle can never do on their own.
     */
    function bow(a, b, near, nearIndex, clear, cost, crowd, skipA, skipB) {
        var straightLen = Math.hypot(b[0] - a[0], b[1] - a[1]);
        if (straightLen < BOW_MIN_LENGTH || near.length > BOW_MAX_NEAR) return [a, b];
        var brushedCount = crowd(a, b, skipA, skipB);
        if (brushedCount < BOW_MIN_CROWD) return [a, b];

        var brushed = [];
        for (var j = 0; j < near.length; j++) {
            var idx = nearIndex[j];
            if (idx === skipA || idx === skipB) continue;
            var bb = near[j];
            if (segmentHitsBox(a, b, [bb[0] - CROWD_HALO, bb[1] - CROWD_HALO,
                bb[2] + CROWD_HALO, bb[3] + CROWD_HALO])) brushed.push(bb);
        }
        var turns = cornersOf(brushed, a, b, DETOUR_CORNERS, BOW_MARGIN).concat(outsideOf(near));
        var path = detour(a, b, turns, clear, skipA, skipB, cost);
        if (!path || path.length < 3) return [a, b];

        var len = 0, spent = 0;
        for (var i = 1; i < path.length; i++) {
            len += Math.hypot(path[i][0] - path[i - 1][0], path[i][1] - path[i - 1][1]);
            spent += cost(path[i - 1], path[i], i === 1 ? skipA : -1, i === path.length - 1 ? skipB : -1);
        }
        if (len > straightLen * BOW_MAX) return [a, b];
        return spent < straightLen * (1 + CROWD_WEIGHT * brushedCount) ? path : [a, b];
    }

    /* Eight points on the outside of a crowd of boxes: the corners of the
     * box round all of them and the middle of each side, set out past the
     * halo so a route through them visibly passes outside the group. */
    function outsideOf(boxes) {
        if (!boxes.length) return [];
        var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        for (var i = 0; i < boxes.length; i++) {
            var bb = boxes[i];
            if (bb[0] < x0) x0 = bb[0];
            if (bb[1] < y0) y0 = bb[1];
            if (bb[2] > x1) x1 = bb[2];
            if (bb[3] > y1) y1 = bb[3];
        }
        var m = BOW_MARGIN;
        x0 -= m; y0 -= m; x1 += m; y1 += m;
        var mx = (x0 + x1) / 2, my = (y0 + y1) / 2;
        return [[x0, y0], [mx, y0], [x1, y0], [x1, my],
            [x1, y1], [mx, y1], [x0, y1], [x0, my]];
    }

    /* The turning points a detour can use: each box's four corners, set
     * out past its clearance. Capped at `keep`, nearest the straight line
     * between the two ends first: a corner beside the line is a way past
     * whatever blocks it, a corner beside an end is usually in the crowd
     * the end sits in. */
    function cornersOf(boxes, a, b, keep, margin) {
        var m = margin === undefined ? DETOUR_MARGIN : margin;
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

    /* The cheapest chain of clear legs from a to b through the corners:
     * A* on a graph small enough to check its legs as it goes, the
     * straight-line distance left as the estimate, so the corners the
     * line would never use are never settled. Vertex 0 is a, 1 is b.
     *
     * `cost` prices one leg. It is never less than the leg's length, so
     * the straight-line estimate stays a lower bound and A* still settles
     * on the cheapest route; left out, a leg costs what it measures. */
    function detour(a, b, corners, clear, skipA, skipB, cost) {
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
                /* A leg may pass through its own end's bubble and no other. */
                var skipP = u === 0 ? skipA : -1;
                var skipQ = v === 1 ? skipB : -1;
                if (dist[u] + Math.hypot(pts[v][0] - pts[u][0], pts[v][1] - pts[u][1]) >= dist[v]) continue;
                if (!clear(pts[u], pts[v], skipP, skipQ)) continue;
                var d = dist[u] + (cost ? cost(pts[u], pts[v], skipP, skipQ)
                    : Math.hypot(pts[v][0] - pts[u][0], pts[v][1] - pts[u][1]));
                if (d >= dist[v]) continue;
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

    /* --- right-angled lines (2026-09-23) ---
     *
     * The owner's verdict on straight lines from centre to centre was that
     * they were hard to follow: diagonals at every angle, crossing and
     * running along each other through the middle of a cluster. What was
     * asked for instead is lines that do not overlap, with room between
     * them, turning at right angles and as few times as they can.
     *
     * So a settled view is routed as a whole, on a grid of lanes LANE
     * pixels apart. Every name is a block on the grid with a clear ring
     * round it; a line leaves a name straight out of any side, travels
     * along the lanes and arrives straight into a side of the other name.
     * Each line is the cheapest way across that grid, where a step costs
     * its length, a turn costs ORTHO_BEND steps, and a lane another line
     * already runs along costs ORTHO_OVERLAP - so a line takes a free lane
     * beside another rather than lying on top of it, and turns only when
     * that is cheaper than going round. Lanes right beside another line
     * or hard against a name cost a little extra, which is what spreads
     * the lines out into the room the layout leaves between rows.
     *
     * The one overlap that is allowed, and made cheap, is lines that share
     * an end: a company's lines to the programs it owns leave it as one
     * trunk and branch off to each, like an organisation chart. That reads
     * as what it is - one name, many connections - where the same trunk
     * shared by two unrelated lines would say they were connected.
     *
     * Lines are routed shortest first, so the short local connections get
     * the straight lanes and the long ones go round them, and then every
     * line still lying on another is taken up and routed again against
     * everything else, once.
     */
    /* Distance between two lanes, in screen pixels. */
    var LANE = 8;
    /* The grid is coarsened past this many cells, so a huge view costs
     * the same as a large one. */
    var ORTHO_MAX_CELLS = 70000;
    /* Clear ring round a name that no line may enter except its own. */
    var ORTHO_CLEAR = 5;
    /* A turn costs this many steps: a line takes a detour of up to this
     * many lanes to save one. */
    var ORTHO_BEND = 10;
    /* Running along a lane another line already holds. */
    var ORTHO_OVERLAP = 40;
    /* Crossing another line. Cheap, because crossings at right angles are
     * easy to read, but not free, so a line does not seek them out. */
    var ORTHO_CROSS = 2;
    /* A lane right beside another line, and a lane against a name. */
    var ORTHO_BESIDE = 0.6;
    var ORTHO_HUG = 0.5;
    /* A lane already carrying a line that shares this line's end. */
    var ORTHO_TRUNK = 0.55;
    /* Leaving a name off the middle of a side costs this much per lane
     * along it: enough to prefer the middle, not enough to turn for it. */
    var ORTHO_OFF_MIDDLE = 0.08;
    /* How far one search may look before the line goes straight. */
    var ORTHO_MAX_EXPAND = 150000;

    /* Directions: 0 up, 1 right, 2 down, 3 left. */
    var DIR_DX = [0, 1, 0, -1];
    var DIR_DY = [-1, 0, 1, 0];

    /**
     * Route every connection of a settled view at right angles.
     *
     * `boxes` is each node's box in screen pixels (index = node index),
     * `kinds` each node's kind (a person is an ellipse, so it is left from
     * near the middle of a side), and `edges` the lines, each
     * { id, a, b } with a and b node indices. Returns id -> points, the
     * first inside box a and the last inside box b, as the rest of the
     * renderer expects of a route; a line with no way through is left out,
     * for the caller to draw some other way.
     */
    function routeOrthogonal(edges, boxes, kinds) {
        var out = Object.create(null);
        if (!edges.length || !boxes.length) return out;
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        var i, j;
        for (i = 0; i < boxes.length; i++) {
            var bx = boxes[i];
            if (!bx) continue;
            if (bx[0] < minX) minX = bx[0];
            if (bx[1] < minY) minY = bx[1];
            if (bx[2] > maxX) maxX = bx[2];
            if (bx[3] > maxY) maxY = bx[3];
        }
        if (!isFinite(minX)) return out;
        var S = LANE;
        var margin = ORTHO_CLEAR + LANE * 5;
        var spanX = maxX - minX + margin * 2, spanY = maxY - minY + margin * 2;
        while ((spanX / S) * (spanY / S) > ORTHO_MAX_CELLS) S += 2;
        margin = ORTHO_CLEAR + S * 5;
        var x0 = minX - margin, y0 = minY - margin;
        var cols = Math.ceil((maxX - minX + margin * 2) / S) + 1;
        var rows = Math.ceil((maxY - minY + margin * 2) / S) + 1;
        var N = cols * rows;
        var C = ORTHO_CLEAR;

        /* Blocked cells, and cells beside a name. */
        var blocked = new Uint8Array(N);
        var hug = new Uint8Array(N);
        for (i = 0; i < boxes.length; i++) {
            var b = boxes[i];
            if (!b) continue;
            var c0 = Math.max(0, Math.ceil((b[0] - C - x0) / S));
            var c1 = Math.min(cols - 1, Math.floor((b[2] + C - x0) / S));
            var r0 = Math.max(0, Math.ceil((b[1] - C - y0) / S));
            var r1 = Math.min(rows - 1, Math.floor((b[3] + C - y0) / S));
            for (var r = r0; r <= r1; r++) {
                for (var c = c0; c <= c1; c++) blocked[r * cols + c] = 1;
            }
            for (r = Math.max(0, r0 - 1); r <= Math.min(rows - 1, r1 + 1); r++) {
                for (c = Math.max(0, c0 - 1); c <= Math.min(cols - 1, c1 + 1); c++) hug[r * cols + c] = 1;
            }
        }

        /* Where a line can leave each name: the first free cell straight
         * out from a side, the point on the rim it starts from, and which
         * way it is heading. Worked out once per name. */
        var portsOf = new Array(boxes.length);
        var portList = function (n) {
            if (portsOf[n]) return portsOf[n];
            var list = [];
            var b = boxes[n];
            if (!b) return (portsOf[n] = list);
            var w = b[2] - b[0], h = b[3] - b[1];
            var cx = (b[0] + b[2]) / 2, cy = (b[1] + b[3]) / 2;
            var person = kinds && kinds[n] === 'person';
            var insetX = person ? w * 0.3 : Math.min(10, w / 4);
            var insetY = person ? h * 0.3 : Math.min(6, h / 4);
            var add = function (c, r, dir, px, py, off) {
                if (c < 0 || r < 0 || c >= cols || r >= rows) return;
                var cell = r * cols + c;
                if (blocked[cell]) return;
                list.push({ cell: cell, dir: dir, x: px, y: py, cost: off * ORTHO_OFF_MIDDLE / S });
            };
            var cA = Math.ceil((b[0] + insetX - x0) / S), cB = Math.floor((b[2] - insetX - x0) / S);
            /* A name narrower than a lane still has its middle one. */
            if (cA > cB) cA = cB = Math.round((cx - x0) / S);
            var rTop = Math.ceil((b[1] - C - y0) / S) - 1;
            var rBot = Math.floor((b[3] + C - y0) / S) + 1;
            for (var c = cA; c <= cB; c++) {
                var px = x0 + c * S;
                var dy = person ? (h / 2) * Math.sqrt(Math.max(0, 1 - Math.pow((px - cx) / (w / 2), 2))) : h / 2;
                add(c, rTop, 0, px, cy - dy + PORT_SINK, Math.abs(px - cx));
                add(c, rBot, 2, px, cy + dy - PORT_SINK, Math.abs(px - cx));
            }
            var rA = Math.ceil((b[1] + insetY - y0) / S), rB = Math.floor((b[3] - insetY - y0) / S);
            if (rA > rB) rA = rB = Math.round((cy - y0) / S);
            var cLeft = Math.ceil((b[0] - C - x0) / S) - 1;
            var cRight = Math.floor((b[2] + C - x0) / S) + 1;
            for (var r = rA; r <= rB; r++) {
                var py = y0 + r * S;
                var dx = person ? (w / 2) * Math.sqrt(Math.max(0, 1 - Math.pow((py - cy) / (h / 2), 2))) : w / 2;
                add(cLeft, r, 3, cx - dx + PORT_SINK, py, Math.abs(py - cy) * 3);
                add(cRight, r, 1, cx + dx - PORT_SINK, py, Math.abs(py - cy) * 3);
            }
            return (portsOf[n] = list);
        };

        /* Lines already down: how many run through each cell, along each
         * axis (0 across, 1 down), and the cells each one took. */
        var occ = [new Uint16Array(N), new Uint16Array(N)];
        var laid = Object.create(null);
        var byNode = new Array(boxes.length);
        /* The lines sharing an end with the one being routed, marked for
         * that search only. */
        var mine = [new Uint16Array(N), new Uint16Array(N)];

        var commit = function (edge, cells, sign) {
            for (var k = 0; k < cells.length; k++) occ[cells[k] & 1][cells[k] >> 1] += sign;
            if (sign > 0) {
                laid[edge.id] = cells;
            } else {
                delete laid[edge.id];
            }
        };
        var markMine = function (edge, sign) {
            var mark = function (n) {
                var list = byNode[n] || [];
                for (var k = 0; k < list.length; k++) {
                    var other = list[k];
                    if (other.id === edge.id || !laid[other.id]) continue;
                    /* A line between the same two names is not a trunk to
                     * share: lying on it would hide one of the two. */
                    if ((other.a === edge.a && other.b === edge.b) || (other.a === edge.b && other.b === edge.a)) continue;
                    var cells = laid[other.id];
                    for (var q = 0; q < cells.length; q++) mine[cells[q] & 1][cells[q] >> 1] += sign;
                }
            };
            mark(edge.a);
            if (edge.b !== edge.a) mark(edge.b);
        };

        var S4 = N * 4;
        var g = new Float64Array(S4 + 1);
        var seen = new Uint32Array(S4 + 1);
        var closed = new Uint32Array(S4 + 1);
        var prev = new Int32Array(S4 + 1);
        var goalDir = new Int8Array(N);
        var goalCost = new Float64Array(N);
        var goalSeen = new Uint32Array(N);
        var gen = 0;
        /* A binary heap of states by estimated total. */
        var heapS = new Int32Array(1024), heapF = new Float64Array(1024), heapN = 0;
        var push = function (s, f) {
            if (heapN === heapS.length) {
                var ns = new Int32Array(heapN * 2), nf = new Float64Array(heapN * 2);
                ns.set(heapS); nf.set(heapF); heapS = ns; heapF = nf;
            }
            var at = heapN++;
            while (at > 0) {
                var up = (at - 1) >> 1;
                if (heapF[up] <= f) break;
                heapS[at] = heapS[up]; heapF[at] = heapF[up]; at = up;
            }
            heapS[at] = s; heapF[at] = f;
        };
        var pop = function () {
            var top = heapS[0];
            var lastS = heapS[--heapN], lastF = heapF[heapN];
            var at = 0;
            for (;;) {
                var l = at * 2 + 1;
                if (l >= heapN) break;
                var rr = l + 1 < heapN && heapF[l + 1] < heapF[l] ? l + 1 : l;
                if (heapF[rr] >= lastF) break;
                heapS[at] = heapS[rr]; heapF[at] = heapF[rr]; at = rr;
            }
            heapS[at] = lastS; heapF[at] = lastF;
            return top;
        };

        /* What a step into `cell` along `axis` costs, lines already down
         * counted. */
        var foreign = function (axis, cell) { return occ[axis][cell] - mine[axis][cell]; };
        var stepCost = function (cell, axis) {
            var cost;
            var theirs = foreign(axis, cell);
            if (theirs > 0) cost = 1 + ORTHO_OVERLAP * theirs;
            else cost = mine[axis][cell] > 0 ? ORTHO_TRUNK : 1;
            if (foreign(1 - axis, cell) > 0) cost += ORTHO_CROSS;
            var side1 = axis === 0 ? cell - cols : cell - 1;
            var side2 = axis === 0 ? cell + cols : cell + 1;
            if ((side1 >= 0 && foreign(axis, side1) > 0) || (side2 < N && foreign(axis, side2) > 0)) cost += ORTHO_BESIDE;
            if (hug[cell]) cost += ORTHO_HUG;
            return cost;
        };

        var search = function (edge) {
            var from = portList(edge.a), to = portList(edge.b);
            if (!from.length || !to.length) return null;
            gen++;
            heapN = 0;
            var gc0 = Infinity, gc1 = -Infinity, gr0 = Infinity, gr1 = -Infinity;
            var k;
            for (k = 0; k < to.length; k++) {
                var tp = to[k];
                goalSeen[tp.cell] = gen;
                goalDir[tp.cell] = (tp.dir + 2) % 4;
                /* A foreign line already leaving by this spot. */
                goalCost[tp.cell] = tp.cost + (foreign(0, tp.cell) + foreign(1, tp.cell) > 0 ? ORTHO_OVERLAP : 0);
                var tc = tp.cell % cols, tr = (tp.cell / cols) | 0;
                if (tc < gc0) gc0 = tc;
                if (tc > gc1) gc1 = tc;
                if (tr < gr0) gr0 = tr;
                if (tr > gr1) gr1 = tr;
            }
            var estimate = function (cell) {
                var c = cell % cols, r = (cell / cols) | 0;
                var dx = c < gc0 ? gc0 - c : (c > gc1 ? c - gc1 : 0);
                var dy = r < gr0 ? gr0 - r : (r > gr1 ? r - gr1 : 0);
                /* A step at full price and a turn wherever the goal is
                 * off both axes. The trunk discount makes this an over-
                 * estimate along a shared trunk, which costs a slightly
                 * longer route there at worst, and is what keeps a long
                 * search from flooding the grid. */
                return dx + dy + (dx && dy ? ORTHO_BEND : 0);
            };
            var startOf = Object.create(null);
            for (k = 0; k < from.length; k++) {
                var fp = from[k];
                var s = fp.cell * 4 + fp.dir;
                var cost0 = fp.cost + (foreign(0, fp.cell) + foreign(1, fp.cell) > 0 ? ORTHO_OVERLAP : 0) +
                    (hug[fp.cell] ? ORTHO_HUG : 0);
                if (seen[s] === gen && g[s] <= cost0) continue;
                seen[s] = gen;
                g[s] = cost0;
                prev[s] = -1;
                startOf[s] = fp;
                push(s, cost0 + estimate(fp.cell));
            }
            var END = S4;
            var expanded = 0;
            var endPort = null, endBest = Infinity;
            while (heapN) {
                var st = pop();
                if (st === END) break;
                if (closed[st] === gen) continue;
                closed[st] = gen;
                if (++expanded > ORTHO_MAX_EXPAND) return null;
                var cell = st >> 2, dir = st & 3;
                if (goalSeen[cell] === gen) {
                    var total = g[st] + goalCost[cell] + (goalDir[cell] === dir ? 0 : ORTHO_BEND);
                    if (total < endBest) {
                        endBest = total;
                        seen[END] = gen;
                        g[END] = total;
                        prev[END] = st;
                        push(END, total);
                    }
                }
                var c = cell % cols, r = (cell / cols) | 0;
                for (var nd = 0; nd < 4; nd++) {
                    if (nd === ((dir + 2) & 3)) continue;
                    var nc = c + DIR_DX[nd], nr = r + DIR_DY[nd];
                    if (nc < 0 || nr < 0 || nc >= cols || nr >= rows) continue;
                    var next = nr * cols + nc;
                    if (blocked[next]) continue;
                    var axis = nd & 1 ? 0 : 1;
                    var cost = g[st] + stepCost(next, axis) + (nd === dir ? 0 : ORTHO_BEND);
                    var ns2 = next * 4 + nd;
                    if (seen[ns2] === gen && g[ns2] <= cost) continue;
                    seen[ns2] = gen;
                    g[ns2] = cost;
                    prev[ns2] = st;
                    push(ns2, cost + estimate(next));
                }
            }
            if (seen[END] !== gen || prev[END] < 0) return null;

            /* Walk back: the cells, each tagged with its axis for the
             * occupancy, and the corners for the drawing. */
            var chainStates = [];
            for (var at = prev[END]; at >= 0; at = prev[at]) chainStates.push(at);
            chainStates.reverse();
            var first = startOf[chainStates[0]];
            var lastCell = chainStates[chainStates.length - 1] >> 2;
            for (k = 0; k < to.length; k++) {
                if (to[k].cell === lastCell) { endPort = to[k]; break; }
            }
            var cells = [];
            var pts = [[first.x, first.y]];
            var px = function (cellId) { return [x0 + (cellId % cols) * S, y0 + ((cellId / cols) | 0) * S]; };
            for (k = 0; k < chainStates.length; k++) {
                var sk = chainStates[k];
                var ck = sk >> 2, dk = sk & 3;
                var ax = dk & 1 ? 0 : 1;
                cells.push(ck * 2 + ax);
                if (k > 0 && (chainStates[k - 1] & 3) !== dk) {
                    /* Turned: the corner is the cell turned in, which
                     * holds a lane each way. */
                    var turnCell = chainStates[k - 1] >> 2;
                    cells.push(turnCell * 2 + ax);
                    pts.push(px(turnCell));
                }
            }
            /* The first cell holds the lane of the way it was left by. */
            var lastState = chainStates[chainStates.length - 1];
            var lastDir = lastState & 3;
            if (endPort && lastDir !== ((endPort.dir + 2) & 3)) {
                /* Turned into the name on the last cell. */
                pts.push(px(lastCell));
                cells.push(lastCell * 2 + (endPort.dir & 1 ? 0 : 1));
            } else {
                pts.push(px(lastCell));
            }
            pts.push([endPort.x, endPort.y]);
            return { pts: tidy(pts), cells: cells, cost: endBest };
        };

        /* Shortest first, by the distance between the two names. */
        var order = edges.filter(function (e) { return boxes[e.a] && boxes[e.b] && e.a !== e.b; });
        var centre = function (n) { var bb = boxes[n]; return [(bb[0] + bb[2]) / 2, (bb[1] + bb[3]) / 2]; };
        order.forEach(function (e) {
            var p = centre(e.a), q = centre(e.b);
            e.span = Math.abs(p[0] - q[0]) + Math.abs(p[1] - q[1]);
            (byNode[e.a] = byNode[e.a] || []).push(e);
            (byNode[e.b] = byNode[e.b] || []).push(e);
        });
        order.sort(function (p, q) { return p.span - q.span; });

        var found = Object.create(null);
        var route = function (e) {
            markMine(e, 1);
            var got = search(e);
            markMine(e, -1);
            if (got) {
                commit(e, got.cells, 1);
                found[e.id] = got;
            }
        };
        order.forEach(route);

        /* Once more for every line still lying along another. */
        var overlapping = function (e) {
            var cells = laid[e.id];
            if (!cells) return false;
            markMine(e, 1);
            var hit = false;
            for (var q = 0; q < cells.length && !hit; q++) {
                var axis = cells[q] & 1, cell = cells[q] >> 1;
                /* Its own cells count once in occ. */
                if (occ[axis][cell] - mine[axis][cell] > 1) hit = true;
            }
            markMine(e, -1);
            return hit;
        };
        for (j = 0; j < order.length; j++) {
            var e = order[j];
            if (!overlapping(e)) continue;
            var before = found[e.id];
            commit(e, before.cells, -1);
            delete found[e.id];
            route(e);
            if (!found[e.id]) {
                commit(e, before.cells, 1);
                found[e.id] = before;
            }
        }

        Object.keys(found).forEach(function (id) { out[id] = found[id].pts; });
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
    /* An arrowhead, and the two things that were wrong with the old one:
     * it was as narrow as the line it sat on, so at a glance it read as a
     * thickening rather than as a direction, and it was drawn straight
     * over whatever it landed among, so on a crowded rim it disappeared
     * into the lines behind it. It is now a broad head, and it is stroked
     * in the stage colour before it is filled, which leaves a thin clear
     * margin round it - the same trick the hovered line uses. */
    var ARROW_WING = 0.66;
    var ARROW_HALO = 3;
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
        var wing = size * ARROW_WING;
        ctx.beginPath();
        ctx.moveTo(tipX, tipY);
        ctx.lineTo(baseX - uy * wing, baseY + ux * wing);
        ctx.lineTo(baseX + uy * wing, baseY - ux * wing);
        ctx.closePath();
        var join = ctx.lineJoin;
        var dash = null;
        if (ctx.getLineDash) dash = ctx.getLineDash();
        if (ctx.setLineDash) ctx.setLineDash([]);
        ctx.lineJoin = 'round';
        ctx.strokeStyle = SURFACE;
        ctx.lineWidth = ARROW_HALO;
        ctx.stroke();
        ctx.lineJoin = join;
        if (dash && dash.length && ctx.setLineDash) ctx.setLineDash(dash);
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

    /** A colour as [r, g, b], or null for one this does not read. */
    function toRgb(colour) {
        var text = String(colour).trim();
        var hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(text);
        if (hex) {
            var h = hex[1];
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
        }
        var fn = /^rgba?\(([^)]+)\)$/i.exec(text);
        if (!fn) return null;
        var parts = fn[1].split(',');
        if (parts.length < 3) return null;
        return [Math.round(parseFloat(parts[0])), Math.round(parseFloat(parts[1])), Math.round(parseFloat(parts[2]))];
    }

    /* How dark an outline has to be to read against the pale fills a node
     * is given. Half the board's company colours are pastels chosen to be
     * seen as a line on white, and a pastel border round a pale yellow box
     * is no border at all, so anything lighter than this is mixed toward
     * black until it is - which keeps the hue, and so keeps the border
     * recognisably the same company as the lines leaving it. */
    var BORDER_LUMA = 0.52;
    var inkCache = Object.create(null);
    function borderInk(colour) {
        if (!colour) return OUTLINE;
        if (inkCache[colour]) return inkCache[colour];
        var rgb = toRgb(colour);
        if (!rgb) return (inkCache[colour] = colour);
        var luma = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
        var out = colour;
        if (luma > BORDER_LUMA) {
            var f = BORDER_LUMA / luma;
            out = 'rgb(' + Math.round(rgb[0] * f) + ',' + Math.round(rgb[1] * f) + ',' + Math.round(rgb[2] * f) + ')';
        }
        return (inkCache[colour] = out);
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

    /* --- where a line leaves a name ---
     *
     * A line runs from one name's centre to the other's, so where it
     * crosses the rim used to be decided entirely by where the other end
     * sat. On a hub that means a dozen lines out of the same edge: a
     * bubble is six times wider than it is tall, so any other name more
     * than a little below it is below it by the box's reckoning, and the
     * lines arrive in whatever order their targets happen to sit in -
     * three of them within a few pixels of each other while the two sides
     * of the bubble, which is most of its rim, go unused.
     *
     * So the rim is shared out. Each line still asks for the point facing
     * its own other end, and where the points are far enough apart it
     * gets exactly that. Where they are not they are pushed apart until
     * each has PORT_GAP to the next - and, because they are pushed around
     * the perimeter rather than along the one side, a crowded bottom
     * spills round the corners and the lines come out of the sides, which
     * is where the room was. They are pushed in the cyclic order they
     * started in, which is what stops two of them swapping places and
     * crossing each other on the rim.
     */
    /* The room a line asks for where it meets a name. */
    var PORT_GAP = 15;
    /* How far inside the rim a line actually starts, so the bubble drawn
     * over it still covers its first pixel and rimPoint has an inside
     * point to walk out from. */
    var PORT_SINK = 3;
    /* Below this a name's lines are not in each other's way, and each one
     * points honestly at its own other end. */
    var PORT_MIN_LINES = 3;
    /* The most of a rim the ports may take between them.
     *
     * Where a name has more lines than its rim has room for at PORT_GAP,
     * they take less each rather than more of the rim. Letting them fill
     * it was tried and is the one way this can do real harm: with every
     * port needed to hold the next one off, the only arrangement left is
     * an even ring, and an even ring is decided by nothing at all - a
     * WWASPS with thirty lines, all of them to names below it, was giving
     * a third of them a port on its top edge, where the line left upward,
     * doubled back under the name and came out below. A port that cannot
     * say where its other end is should at least not say the opposite. So
     * the ports keep slack between them, and a crowded name simply has
     * its lines closer together, which is the truth about it. */
    var PORT_SHARE = 0.72;

    /* Where a ray from the box's centre toward (tx, ty) crosses the rim,
     * as a distance clockwise round the perimeter from the top left. */
    function rimPos(box, tx, ty) {
        var w = box[2] - box[0], h = box[3] - box[1];
        var cx = box[0] + w / 2, cy = box[1] + h / 2;
        var dx = tx - cx, dy = ty - cy;
        if (!dx && !dy) return 0;
        var tX = dx ? (w / 2) / Math.abs(dx) : Infinity;
        var tY = dy ? (h / 2) / Math.abs(dy) : Infinity;
        var at;
        if (tX < tY) {
            at = Math.min(Math.max(cy + dy * tX - box[1], 0), h);
            return dx > 0 ? w + at : 2 * w + 2 * h - at;
        }
        at = Math.min(Math.max(cx + dx * tY - box[0], 0), w);
        return dy > 0 ? w + h + (w - at) : at;
    }

    /**
     * The point that distance round the rim, pulled just inside the shape
     * the node is actually drawn as.
     *
     * The spacing is worked out on the bounding box, because a box has a
     * perimeter a port can be slid along; the point it gives is then put
     * back on the shape. A person is an ellipse, and the corners of its
     * box are a long way outside it - a port left there would hang in
     * clear space beside the name with its line starting at nothing - so
     * the point is drawn back along its own radius until it is on the
     * ellipse. Then everything sinks PORT_SINK further in, which is what
     * puts a line's first pixel under the bubble painted over it and
     * leaves rimPoint an inside point to walk out from.
     */
    function rimAt(box, at, kind) {
        var w = box[2] - box[0], h = box[3] - box[1];
        var per = 2 * (w + h);
        var p = ((at % per) + per) % per;
        var x, y;
        if (p < w) { x = box[0] + p; y = box[1]; }
        else if ((p -= w) < h) { x = box[2]; y = box[1] + p; }
        else if ((p -= h) < w) { x = box[2] - p; y = box[3]; }
        else { x = box[0]; y = box[3] - (p - w); }

        var cx = box[0] + w / 2, cy = box[1] + h / 2;
        var dx = x - cx, dy = y - cy;
        if (kind === 'person' && w && h) {
            var k = Math.hypot(dx / (w / 2), dy / (h / 2));
            if (k > 1) { dx /= k; dy /= k; }
        }
        var len = Math.hypot(dx, dy);
        if (len > PORT_SINK) {
            var pull = (len - PORT_SINK) / len;
            dx *= pull;
            dy *= pull;
        }
        return [cx + dx, cy + dy];
    }

    /**
     * Push a name's ports apart until each has `gap` to the next, keeping
     * the order they arrived in.
     *
     * The array is the cyclic order, and a position is free to run past
     * either end of the perimeter while this works: rimAt takes it modulo
     * the perimeter at the end, so a port pushed off the bottom edge
     * simply appears on the side round the corner. Where a name has more
     * lines than its rim has room for, they share it equally instead.
     */
    function spreadPorts(pos, per, gap) {
        var n = pos.length;
        var i;
        if (n < 2) return;
        if (n * gap > per * PORT_SHARE) gap = per * PORT_SHARE / n;

        /* Cut the ring at its widest natural gap. That is the join a crowd
         * is least likely to straddle, and with it cut there the rim can
         * be treated as a line. Pushing pairs apart round the ring was
         * tried first and dropped: on a name whose lines all want the same
         * spot it is a diffusion, and it had not finished spreading sixty
         * of them after forty passes. */
        var cut = 0, widest = -1;
        for (i = 0; i < n; i++) {
            var d = (i === n - 1 ? pos[0] + per : pos[i + 1]) - pos[i];
            if (d > widest) { widest = d; cut = (i + 1) % n; }
        }
        var want = new Float64Array(n);
        for (i = 0; i < n; i++) {
            var j = (cut + i) % n;
            /* What this port asked for, less the room the ports before it
             * take: in these terms the arrangement is legal exactly when
             * the numbers do not decrease. */
            want[i] = pos[j] + (j < cut ? per : 0) - i * gap;
        }

        /* Pool adjacent violators: the nearest arrangement, in total
         * movement, that has every port clear of the next and none of them
         * out of the order they arrived in - which is what stops two lines
         * swapping places and crossing each other on the rim. A block is a
         * run that has closed up and now moves as one, at the average of
         * what its members asked for. */
        var value = new Float64Array(n);
        var count = new Int32Array(n);
        var blocks = 0;
        for (i = 0; i < n; i++) {
            value[blocks] = want[i];
            count[blocks] = 1;
            blocks++;
            while (blocks > 1 && value[blocks - 2] > value[blocks - 1]) {
                var sum = value[blocks - 2] * count[blocks - 2] + value[blocks - 1] * count[blocks - 1];
                count[blocks - 2] += count[blocks - 1];
                value[blocks - 2] = sum / count[blocks - 2];
                blocks--;
            }
        }
        var out = new Float64Array(n);
        var at = 0;
        for (var b = 0; b < blocks; b++) {
            for (var k = 0; k < count[b]; k++) { out[at] = value[b] + at * gap; at++; }
        }

        /* The one case cutting the ring cannot answer: the ports have
         * closed up all the way round, so the far end is now crowding the
         * near one. Then there is nothing to choose between them and they
         * share the rim equally, set where it costs the least to put them.
         */
        if (out[0] + per - out[n - 1] < gap - 0.001) {
            var mean = 0;
            for (i = 0; i < n; i++) mean += want[i];
            mean /= n;
            for (i = 0; i < n; i++) out[i] = mean + i * gap;
        }
        for (i = 0; i < n; i++) {
            var back = (cut + i) % n;
            pos[back] = out[i] - (back < cut ? per : 0);
        }
    }

    /* Fill and outline for one node, whatever its shape. The path must
     * already be traced. Filled by status and outlined in its company's
     * colour - the same colour the lines round it are drawn in, so a name
     * and the group it belongs to can be read off each other without
     * following a line to its end - or in the board's dark ink where the
     * record names no owner. A rebrand is dashed, because the place
     * carried on under another name and the dash says "continues
     * elsewhere". */
    function fillOutline(ctx, spec, alpha, heavy) {
        ctx.globalAlpha = alpha;
        ctx.fillStyle = STATUS_FILLS[spec.status] || STATUS_FILLS.unknown;
        ctx.fill();
        ctx.strokeStyle = spec.border || OUTLINE;
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
        ctx.lineTo(style.arrow ? w - 10 : w - 1, h / 2);
        ctx.stroke();
        ctx.setLineDash([]);
        if (style.arrow) {
            ctx.fillStyle = style.colour;
            drawArrow(ctx, 1, h / 2, w - 1, h / 2, 0, 10);
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
        var scratch = { kind: '', status: '', natsap: false, deaths: 0, border: '' };

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

        /* The group a node is drawn as belonging to, for its border. Wider
         * than companyOf, which answers only for a chain the board gave a
         * colour of its own: a name with no border colour is the thing the
         * border is there to fix, so a chain the board left black falls
         * back to the palette, and a name with no owner of its own is
         * taken to belong where the board frame it sits in belongs. */
        function clusterOf(node) {
            if (!node) return '';
            if (node.chain) return node.chain;
            var region = node.regions && node.regions[0];
            if (!region) return '';
            if (board.chainColours[region]) return region;
            return board.regionChains[region] || '';
        }

        /* A node's border: its group's colour, darkened where the board's
         * own is too pale to read as an outline, and the plain dark ink
         * where the record puts the name in no group at all. */
        renderer.clusterInk = function (node) {
            var chain = clusterOf(node);
            if (!chain) return OUTLINE;
            var colour = renderer.chainColour(chain);
            return !colour || colour === CHAIN_NONE ? OUTLINE : borderInk(colour);
        };

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
            /* The name is drawn at the bubble's scale, so the ellipse has
             * to hold it at that scale: measured at the working size, a
             * clicked person (grown 1.6 times) had a name wider than the
             * ellipse drawn round it. */
            if (node.kind === 'person') hw = Math.max(hh * 1.4, (nameW * scale / 2) / 0.82 + BUBBLE_PAD_X * 0.5 * scale);
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

            /* The rim shared out, a name at a time: every line that will be
             * drawn asks its two ends for a place to land, and a name with
             * enough lines to crowd its rim spreads them (see spreadPorts).
             *
             * Built from every edge whose two ends are in the scene rather
             * than from the ones the viewport happens to be showing, so
             * panning cannot change where a line leaves its name - the
             * routes below are remembered across a pan and would otherwise
             * be served against ports that had moved.
             */
            var ports = Object.create(null);
            var incident = Object.create(null);
            for (i = 0; i < scene.edges.length; i++) {
                var pe = scene.edges[i];
                if (pe.source._frame !== frameStamp || pe.target._frame !== frameStamp) continue;
                (incident[pe.source._i] = incident[pe.source._i] || []).push({ edge: pe, at: 0, other: pe.target._i });
                (incident[pe.target._i] = incident[pe.target._i] || []).push({ edge: pe, at: 1, other: pe.source._i });
            }
            Object.keys(incident).forEach(function (key) {
                var at = +key;
                /* A dot's rim is a few pixels round; there is nothing to
                 * share out, and its lines already leave from every side. */
                if (!bubbles[at]) return;
                var list = incident[at];
                if (list.length < PORT_MIN_LINES) return;
                var box = extent[at];
                var per = 2 * ((box[2] - box[0]) + (box[3] - box[1]));
                list.forEach(function (it) { it.p = rimPos(box, sx[it.other], sy[it.other]); });
                list.sort(function (p1, p2) { return p1.p - p2.p; });
                var pos = list.map(function (it) { return it.p; });
                spreadPorts(pos, per, PORT_GAP);
                list.forEach(function (it, n) {
                    var slot = ports[it.edge.id] || (ports[it.edge.id] = [null, null]);
                    slot[it.at] = rimAt(box, pos[n], scene.nodes[at].kind);
                });
            });
            renderer.ports = ports;

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
            /* A zoom in progress moves every name every frame, and a route
             * worked out for one frame is wrong the next: lines go straight
             * until it settles, and are routed once, where they land. A
             * view with more names than DETOUR_MAX_NODES is a field of dots
             * and goes straight throughout.
             *
             * A click's yoyo and a hover's gather move some names and not
             * others. The view is routed where every name sits at rest, and
             * only the lines of the names that are away from their spot go
             * straight while they are (see routeFor); the rest keep their
             * routes, so pointing at a name does not throw the whole view
             * back to diagonals. */
            var straightOnly = scene.nodes.length > DETOUR_MAX_NODES || zooming;
            /* The boxes lines are routed round: each name where it sits at
             * rest and at the size it is drawn there, not grown under the
             * pointer, so hovering a name does not route the whole view
             * again; and a name off the stage as the bubble it will be when
             * panned to, so a pan does not either. */
            var routeBoxes = new Array(scene.nodes.length);
            for (i = 0; i < scene.nodes.length; i++) {
                node = scene.nodes[i];
                var restP = positionOf(node);
                var rx = restP.x * k + t.x, ry = restP.y * k + t.y;
                var onStage = rx >= -300 && rx <= w + 300 && ry >= -60 && ry <= h + 60;
                if (bubbles[i] || !onStage) {
                    routeBoxes[i] = bubbleBox(node, rx, ry, grow[node.id] || 1);
                } else {
                    var dotAt = extent[i];
                    routeBoxes[i] = [dotAt[0] + rx - sx[i], dotAt[1] + ry - sy[i],
                        dotAt[2] + rx - sx[i], dotAt[3] + ry - sy[i]];
                }
            }
            /* A view zoomed out until its names sit on each other has no
             * lanes between them to route along: its lines go straight. */
            if (!straightOnly) {
                var piled = 0;
                for (i = 0; i < routeBoxes.length && piled <= routeBoxes.length / 10; i++) {
                    for (var pj = i + 1; pj < routeBoxes.length; pj++) {
                        var r1 = routeBoxes[i], r2 = routeBoxes[pj];
                        if (r1[0] < r2[2] && r1[2] > r2[0] && r1[1] < r2[3] && r1[3] > r2[1]) piled++;
                    }
                }
                if (piled > routeBoxes.length / 10) straightOnly = true;
            }
            /* The flag is part of the key: routes drawn straight for a
             * frame in motion must not be served to the frame at rest. */
            var sigParts = [k, sceneStamp, straightOnly ? 1 : 0];
            for (i = 0; i < scene.nodes.length; i++) {
                var ex2 = routeBoxes[i];
                sigParts.push(Math.round((ex2[0] - t.x) * 2), Math.round((ex2[1] - t.y) * 2),
                    Math.round((ex2[2] - t.x) * 2), Math.round((ex2[3] - t.y) * 2));
            }
            var sig = sigParts.join(',');
            if (routeMemo.sig !== sig) {
                routeMemo = { sig: sig, pts: Object.create(null), ortho: null };
                if (!straightOnly) {
                    var wanted = [];
                    var kinds = scene.nodes.map(function (n) { return n.kind; });
                    for (i = 0; i < scene.edges.length; i++) {
                        var oe = scene.edges[i];
                        if (oe.source._frame !== frameStamp || oe.target._frame !== frameStamp) continue;
                        wanted.push({ id: oe.id, a: oe.source._i, b: oe.target._i });
                    }
                    var routeStart = Date.now();
                    var laidOut = routeOrthogonal(wanted, routeBoxes, kinds);
                    if (root.KOP_NET_DEBUG && root.console) {
                        root.console.log('KOPDEBUG routed', Object.keys(laidOut).length, 'of', wanted.length,
                            'lines in', Date.now() - routeStart, 'ms');
                    }
                    routeMemo.ortho = Object.create(null);
                    Object.keys(laidOut).forEach(function (id) {
                        routeMemo.ortho[id] = laidOut[id].map(function (p) { return [p[0] - t.x, p[1] - t.y]; });
                    });
                }
            }
            var memo = routeMemo.pts;
            var ortho = routeMemo.ortho;
            var routeFor = function (edge, a, c) {
                var moved = offsets && (offsets[edge.sourceId] || offsets[edge.targetId]);
                if (moved) return [[sx[a], sy[a]], [sx[c], sy[c]]];
                var cached = memo[edge.id] || (ortho && ortho[edge.id]);
                if (!cached) {
                    var port = ports[edge.id];
                    var ax = port && port[0] ? port[0][0] : sx[a];
                    var ay = port && port[0] ? port[0][1] : sy[a];
                    var cx = port && port[1] ? port[1][0] : sx[c];
                    var cy = port && port[1] ? port[1][1] : sy[c];
                    /* Two lines between the same pair are held apart by
                     * their ports already; the offset would only push them
                     * off the rim they were just given. */
                    var par = port ? null : parallelOf[edge.id];
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
                scratch.border = renderer.clusterInk(node);

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
                        Math.max(10, Math.min(17, 11 * Math.sqrt(k))));
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

        /**
         * Where a node's bubble is on the stage right now, as [left, top,
         * right, bottom] in pixels: at its position this frame, gather
         * offset included, and at the size it is drawn (grown if clicked,
         * bigger again under the pointer). The hover card sits beside it.
         */
        renderer.screenBox = function (node) {
            var t = renderer.transform;
            var p = positionOf(node);
            var emphasis = renderer.emphasis || {};
            var off = emphasis.offsets ? emphasis.offsets[node.id] : null;
            var hoverId = emphasis.hoverId || null;
            var scale = (grow[node.id] || 1) * (node.id === hoverId ? LABEL_SIZE_HOVER / LABEL_SIZE : 1);
            return bubbleBox(node,
                (off ? p.x + off[0] : p.x) * t.k + t.x,
                (off ? p.y + off[1] : p.y) * t.k + t.y, scale);
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
        /* The two rules that keep initials and short forms of a node's own
         * name off the board; exported so the tests can hold them. */
        isAbbreviation: isAbbreviation,
        isSameName: isSameName,
        boardOtherNames: boardOtherNames,
        edgeFadeFor: edgeFadeFor,
        swatch: swatch,
        segmentHitsBox: segmentHitsBox,
        routeEdge: routeEdge,
        routeOrthogonal: routeOrthogonal,
        LANE: LANE,
        borderInk: borderInk,
        spreadPorts: spreadPorts,
        rimPos: rimPos,
        rimAt: rimAt,
        PORT_GAP: PORT_GAP,
        BOW_MIN_LENGTH: BOW_MIN_LENGTH,
        BORDER_BUBBLE: BORDER_BUBBLE,
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
