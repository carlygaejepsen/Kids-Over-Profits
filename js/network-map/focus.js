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
 * What is on screen is places and the lines between them. A person who does
 * nothing in a view but join two places is folded into the line that joins
 * them (foldConnectors), and the line says who when it is pointed at
 * (connection.js); only the person who was clicked is drawn as a name.
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
    /* Past this many connections nothing gathers and hover is lighting
     * alone. A view packs its names as close as they will go, so pulling a
     * few of them in finds room and pulling in fifty does not: with every
     * programme its staff lead to on the board, Provo Canyon School has
     * fifty-two lines, and gathered, twenty-two of those names landed on
     * top of each other and were dropped. */
    var GATHER_MAX = 36;

    var GATHER_IN_MS = 170;
    var GATHER_OUT_MS = 130;
    /* What was clicked is drawn this much bigger than the rest, and swells
     * into it with a bounce while its connections are reeled in around it:
     * a yoyo coming back to the hand. */
    var HEAD_GROW = 1.6;
    /* The opening view is a handful of organisations on a stage built for a
     * hundred names. Drawn at the working size they are specks in a field of
     * sand; drawn grown they are what they are, the ways in. Only as far as
     * the stage has the width for: on a phone they stay the working size. */
    var OPENING_GROW = 1.7;
    var OPENING_GROW_WIDTH = 1000;
    var YOYO_MS = 800;
    /* The view zooms in on a click and its own connections when that frame
     * is at least this much closer than the whole board, and never closer
     * than this: six names framed alone would be blown up into blobs. */
    var CLOSER = 1.1;
    var NEAR_MAX_ZOOM = 2.4;
    /* The stage's own controls (Key at the left, Reset view and Full screen
     * at the right) sit in a band along its top, so a name framed there is
     * under a button; and a name against an edge is cut. What was clicked
     * keeps its whole box clear of both. */
    var STAGE_TOP = 48;
    var STAGE_EDGE = 12;
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
    /* A label's width, in world units. Names are drawn inside their bubble
     * at 12px, a little under seven pixels a character. */
    var LABEL_CHAR_WIDTH = 6.7;
    /* A name sits inside a bubble, so it takes its padding and the gap
     * the renderer keeps between bubbles on top of its own width. */
    var BUBBLE_EXTRA = 30;
    /* Clear space between one row's names and the next, on a route. */
    var ROW_GUTTER = 16;
    /* Height of the label that hangs under a node. */
    var LABEL_ROOM = 18;

    /**
     * How many small lines a point's name carries under it: its years, and
     * the name it traded under before.
     *
     * The painter owns the answer, because it is the one that draws them.
     * This is only for the layouts that run before a renderer exists, or
     * without one in a test; where `renderer.labelBox` is available the
     * measured box already includes them.
     */
    function subLineCount(point) {
        var painter = root.KOPNetworkCanvas;
        if (painter && painter.subLines && point && point.node) {
            return painter.subLines(point.node).length;
        }
        return point && point.years ? 1 : 0;
    }

    /* Matches build-network-layout.js. A focused view is a different problem
     * from the whole board, so the numbers are not identical, but a
     * connection type that pulls tight there should pull tight here. */
    var LINK_DISTANCE = {
        corporate: 70, family: 70, board: 95, leadership: 95,
        clinical: 130, admissions: 125, referral: 125,
        staff: 130, membership: 110, survivor: 125, other: 125, unknown: 125
    };
    var DEFAULT_DISTANCE = 125;

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

        /* The cell geometry of the current layout, or null before one has
         * been laid out. */
        var grid = null;

        var focus = { };
        focus.grid = function () { return grid; };

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

        /* The scene on screen, as last handed to the renderer. Hover and the
         * frame read a name's connections off this rather than off the
         * store: a line that stands for a person (see foldConnectors) is a
         * connection on screen that the store has no edge for. */
        var current = null;

        /** The lines on screen that end at this node: [{ edge, other }]. */
        function linksOnScreen(id) {
            var out = [];
            ((current && current.edges) || []).forEach(function (edge) {
                if (edge.sourceId === id) out.push({ edge: edge, other: edge.target });
                else if (edge.targetId === id) out.push({ edge: edge, other: edge.source });
            });
            return out;
        }

        /** The hovered node plus everyone it has a line to on screen. */
        function neighbourhoodOf(id) {
            var near = Object.create(null);
            var nearEdges = Object.create(null);
            near[id] = true;
            linksOnScreen(id).forEach(function (link) {
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
        /**
         * What the view is centred on. In focus mode, the last thing clicked:
         * the trail is a history, and clicking a person in a company's view
         * gives you that person's connections rather than laying them over
         * the company's. In expand mode, everything clicked: the trail is a
         * union, and each click adds a neighbourhood to the board. The
         * crumbs are the way back in either.
         */
        var mode = 'focus';
        function currentRoots() {
            if (!chain.length) {
                var opening = openingRoot();
                return opening ? [opening] : [];
            }
            return mode === 'focus' ? [chain[chain.length - 1]] : chain.slice();
        }

        /*
         * The map can open on one organisation already opened (2d.1): the
         * first screen is what a click on it would leave, its cluster with
         * it in the middle, and no trail. The build names it on the default
         * view as `root`. Everything that asks "what was clicked" asks
         * headOf(), so the opening root is laid out, framed and grown as a
         * click is; the trail, the crumbs and the address bar stay empty,
         * so Start over has nothing to go back to and the hash stays clean.
         */
        function openingRoot() {
            var id = store.viewRoot ? store.viewRoot() : null;
            return id && store.visible().nodeIds[id] ? id : null;
        }

        /** The name the view is about: the newest click, or the opening root. */
        function headOf() {
            if (inPath()) return null;
            if (chain.length) return store.node(chain[chain.length - 1]) || null;
            var opening = openingRoot();
            return opening ? store.node(opening) || null : null;
        }
        focus.mode = function () { return mode; };

        /*
         * A third thing the trail can be: a route. store.paths() finds how
         * two names are connected, and showPath() puts one route on the board
         * as itself - its names in order, the lines between each and the
         * next, and nothing else. Every rule below that brings more onto the
         * board (a person's places, a programme's owner) stands aside: the
         * question was how A reaches B, and the answer is these names.
         *
         * Nobody is folded into a line here either. On a route the person
         * who joins two places is the answer, so they are a name.
         *
         * It is a mode of the trail rather than a separate state so that the
         * crumbs, the address bar and the drawer carry on working: the trail
         * is the route, a crumb cuts the route back to that name, and
         * #open=a,b,c&mode=path reopens it. Clicking a name on the route
         * leaves it for that name's own view, in Focus.
         */
        function inPath() {
            return mode === 'path' && chain.length > 1;
        }
        focus.isPath = inPath;

        /** Whether the trail, read as a route, still has a line for every step. */
        function pathHolds() {
            if (chain.length < 2) return false;
            var live = store.visible().nodeIds;
            var seen = Object.create(null);
            for (var i = 0; i < chain.length; i++) {
                if (!live[chain[i]] || seen[chain[i]]) return false;
                seen[chain[i]] = true;
                if (i && !store.edgesBetween(chain[i - 1], chain[i]).length) return false;
            }
            return true;
        }
        focus.setMode = function (next) {
            next = next === 'expand' ? 'expand' : 'focus';
            if (next === mode) return;
            mode = next;
            if (chain.length) enterFocus();
            onChange();
        };

        function visibleIds() {
            var live = store.visible().nodeIds;

            if (inPath()) {
                var route = Object.create(null);
                chain.forEach(function (id) { if (live[id]) route[id] = true; });
                return route;
            }

            /* What the visitor actually asked for: everything opened, and
             * everyone those touch. The organisations the map opened with
             * are added at the end, because the rules below are about the
             * question being asked and not about the background. */
            var asked = Object.create(null);
            currentRoots().forEach(function (id) {
                if (!live[id]) return;
                asked[id] = true;
                store.neighbours(id, true).forEach(function (link) {
                    asked[link.other.id] = true;
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
             * This is also the only thing that puts a second programme on
             * screen beside the first. Two facilities owned by the same
             * company are not each other's business; somebody who worked at
             * both is.
             *
             * Object.keys takes a snapshot, so this opens people out by one
             * step and stops: a person reached through another person's
             * expansion does not expand in turn. People are the cheap case to
             * do this for - median degree two, most seven - but a rule that
             * walked outwards without a stop would not stay cheap.
             *
             * Always, whatever the view comes to. There used to be a budget
             * here: past thirty names, a place only one person led to waited
             * behind the count on that person. But the person is no longer
             * a name on the board - they are the line between the two
             * places (foldConnectors) - so a place held back would be a
             * connection with nothing on screen to say it exists. */
            Object.keys(asked).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'person') return;
                store.neighbours(id, true).forEach(function (link) {
                    asked[link.other.id] = true;
                });
            });

            /* And nobody is on the map without their places. The step above
             * can bring a person in second-hand - Narvin Lichfield arrives in
             * Provo Canyon School's view as somebody's brother - and the
             * one-step stop then left them standing there with every
             * programme they ran behind a +N: a person with a relationship
             * the map was not showing, in 68 views. The stop is for people
             * bringing people, which is what runs away. A person's
             * programmes and companies always come, whoever brought the
             * person; what those places bring in turn is a click away. */
            withTheirPlaces(asked);

            /* Whoever owned it is never left off. Ownership is the question
             * this map exists to answer, so a programme on screen without the
             * company behind it is the one gap worth closing automatically.
             *
             * Programmes only, and owners only: the edge has to run from a
             * company into the programme. Run for every name on screen and in
             * either direction, it pulled in whatever a company owned and
             * whoever owned it - opening Synanon brought CEDU's owners and
             * Leadership Dynamics' holdings, four companies nobody asked
             * about.
             *
             * One step only: the company that owned this place, not the
             * company that owned that company and so on - Provo Canyon School
             * walks up through ten organisations if you let it, which answers
             * a question nobody asked and buries the one they did. And owners
             * only, never their other holdings: a company's remaining
             * programmes are its business with them, not this facility's. */
            Object.keys(asked).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'facility') return;
                store.neighbours(id, true).forEach(function (link) {
                    if (link.other.kind !== 'parent') return;
                    if (link.edge.category !== 'corporate') return;
                    if (link.edge.direction === 'renamed') return;
                    /* A line with no direction is the board's shorthand for
                     * "part of this group", drawn from whichever end the
                     * author started at (Daniels Academy to Aspiro Group). */
                    if (link.outgoing && link.edge.direction !== 'none') return;
                    asked[link.other.id] = true;
                });
            });

            /* The organisations the map opens on belong to the opening view
             * only. Kept once something is opened, any of them a few edges
             * away rode in on the chain - Universal Health Services in
             * Synanon's view, through CEDU's owner. */
            var ids = Object.create(null);
            if (!chain.length) {
                var opening = Object.create(null);
                store.seeds().forEach(function (node) {
                    if (live[node.id]) opening[node.id] = true;
                });
                /* The people a starter view names are there to join its
                 * organisations, and they bring their places like anyone. */
                withTheirPlaces(opening);
                Object.keys(opening).forEach(function (id) {
                    if (live[id]) ids[id] = true;
                });
            }
            Object.keys(asked).forEach(function (id) {
                if (live[id]) ids[id] = true;
            });
            return ids;
        }

        /** Add every programme and company the people in this set connect to. */
        function withTheirPlaces(set) {
            Object.keys(set).forEach(function (id) {
                var node = store.node(id);
                if (!node || node.kind !== 'person') return;
                store.neighbours(id, true).forEach(function (link) {
                    if (link.other.kind !== 'person') set[link.other.id] = true;
                });
            });
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
            if (inPath()) {
                /* Only the lines of the route itself. Two names on a longer
                 * route can also be joined directly, and that line drawn
                 * here would be a shortcut across the route being shown. */
                var stepOf = Object.create(null);
                chain.forEach(function (id, i) { stepOf[id] = i; });
                edges = edges.filter(function (edge) {
                    return Math.abs(stepOf[edge.sourceId] - stepOf[edge.targetId]) === 1;
                });
            }

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
                /* Reachable from what was clicked, along the edges on screen.
                 * "Has a line to something" was not enough: two of the
                 * opening organisations share an edge with each other and
                 * sailed through on it, sitting in a person's view with no
                 * connection to the person. What was clicked always stays,
                 * even where the filters have taken away everything around
                 * it. */
                var adjacent = Object.create(null);
                edges.forEach(function (edge) {
                    (adjacent[edge.sourceId] = adjacent[edge.sourceId] || []).push(edge.targetId);
                    (adjacent[edge.targetId] = adjacent[edge.targetId] || []).push(edge.sourceId);
                });
                var reach = Object.create(null);
                var queue = [];
                currentRoots().forEach(function (id) {
                    if (ids[id]) { reach[id] = true; queue.push(id); }
                });
                while (queue.length) {
                    var at = queue.shift();
                    (adjacent[at] || []).forEach(function (other) {
                        if (!reach[other]) { reach[other] = true; queue.push(other); }
                    });
                }
                nodes = nodes.filter(function (node) { return reach[node.id]; });
                edges = edges.filter(function (edge) { return reach[edge.sourceId] && reach[edge.targetId]; });
                ids = reach;
            }

            var fold = inPath()
                ? { nodes: nodes, edges: edges, folded: Object.create(null) }
                : foldConnectors(nodes, edges);
            nodes = fold.nodes;
            edges = fold.edges;
            var shown = Object.create(null);
            nodes.forEach(function (node) { shown[node.id] = true; });

            /* What is on screen is not everything a node touches, and an
             * owner deliberately brings only itself, so a node can sit there
             * with connections the reader cannot see. Rather than dragging
             * them in - which is what turns opening one company into a
             * screenful of its other holdings - each node reports how many
             * connections it has off screen, and the renderer marks it with
             * the count. The reader can see there is more behind a name, and
             * clicking it is what brings it. A person folded into a line is
             * on screen as that line, so they are not counted as missing.
             */
            var hidden = Object.create(null);
            nodes.forEach(function (node) {
                var off = 0;
                store.neighbours(node.id, true).forEach(function (link) {
                    if (!shown[link.other.id] && !fold.folded[link.other.id]) off++;
                });
                if (off) hidden[node.id] = off;
            });

            return {
                nodes: nodes, edges: edges, nodeIds: shown,
                degrees: visible.degrees, hidden: hidden, folded: fold.folded
            };
        };

        /**
         * Fold the people who join two places into the line between them.
         *
         * Two programmes that share a therapist are connected, and the
         * connection is what belongs on the board: one line from one place
         * to the other, with the person a hover away on it. Drawn as a name
         * of their own the person was a third thing to read, with two lines
         * where the record is one fact, and a view of a programme with a
         * staff list was mostly staff.
         *
         * A person folds when every line they have on screen runs to an
         * organisation and there are two or more of those. What was clicked
         * never folds - open a person and they are the subject, drawn with
         * their places round them - and neither does anyone with a line to
         * another person, since a marriage has no place to fold into.
         * Someone with a single place on screen stays a name beside it.
         *
         * Somebody at three places is not three pairs' worth of lines. Their
         * places are joined through whichever of them was clicked, or failing
         * that the busiest, which says the same thing in two lines instead
         * of three and keeps a staff list from drawing a web. Several people
         * joining the same two places share the one line, and where the
         * record already has a line between the two (one owns the other, or
         * the staff list joined them) the people ride on that instead of
         * doubling it.
         *
         * The store is never touched: a folded line is a new object, and a
         * recorded edge that takes passengers is copied first.
         */
        function foldConnectors(nodes, edges) {
            var rootIds = Object.create(null);
            currentRoots().forEach(function (id) { rootIds[id] = true; });

            var linksOf = Object.create(null);
            edges.forEach(function (edge) {
                (linksOf[edge.sourceId] = linksOf[edge.sourceId] || []).push({ edge: edge, other: edge.target });
                (linksOf[edge.targetId] = linksOf[edge.targetId] || []).push({ edge: edge, other: edge.source });
            });

            var folded = Object.create(null);
            var joins = Object.create(null);
            var joinOrder = [];
            nodes.forEach(function (node) {
                if (node.kind !== 'person' || rootIds[node.id]) return;
                var links = linksOf[node.id] || [];
                var at = Object.create(null);
                var places = [];
                for (var i = 0; i < links.length; i++) {
                    var other = links[i].other;
                    if (other.kind === 'person') return;
                    if (!at[other.id]) { at[other.id] = []; places.push(other); }
                    at[other.id].push(links[i].edge);
                }
                if (places.length < 2) return;
                folded[node.id] = true;

                var anchors = places.filter(function (place) { return rootIds[place.id]; });
                if (!anchors.length) {
                    anchors = [places.slice().sort(function (a, b) {
                        return (linksOf[b.id] || []).length - (linksOf[a.id] || []).length ||
                            (a.id < b.id ? -1 : 1);
                    })[0]];
                }
                anchors.forEach(function (anchor) {
                    places.forEach(function (place) {
                        if (place === anchor) return;
                        var key = anchor.id < place.id ? anchor.id + '|' + place.id : place.id + '|' + anchor.id;
                        if (!joins[key]) {
                            joins[key] = { source: anchor, target: place, via: [] };
                            joinOrder.push(key);
                        }
                        var join = joins[key];
                        if (join.via.some(function (v) { return v.person === node; })) return;
                        join.via.push({ person: node, at: at });
                    });
                });
            });
            if (!joinOrder.length) return { nodes: nodes, edges: edges, folded: folded };

            var kept = [];
            var recorded = Object.create(null);
            edges.forEach(function (edge) {
                if (folded[edge.sourceId] || folded[edge.targetId]) return;
                var key = edge.sourceId < edge.targetId
                    ? edge.sourceId + '|' + edge.targetId : edge.targetId + '|' + edge.sourceId;
                if (recorded[key] === undefined) recorded[key] = kept.length;
                kept.push(edge);
            });
            joinOrder.forEach(function (key) {
                var join = joins[key];
                if (recorded[key] !== undefined) {
                    var original = kept[recorded[key]];
                    var copy = {};
                    Object.keys(original).forEach(function (field) { copy[field] = original[field]; });
                    copy.via = join.via;
                    kept[recorded[key]] = copy;
                    return;
                }
                kept.push({
                    id: 'via:' + key,
                    source: join.source, target: join.target,
                    sourceId: join.source.id, targetId: join.target.id,
                    category: 'people', roles: [], raw: '', direction: 'none',
                    crossesChain: false,
                    crossesRegion: join.via.some(function (v) {
                        return Object.keys(v.at).some(function (placeId) {
                            return v.at[placeId].some(function (e) { return e.crossesRegion; });
                        });
                    }),
                    provenance: 'fold',
                    via: join.via,
                    above: lineageOf(join)
                });
            });

            return {
                nodes: nodes.filter(function (node) { return !folded[node.id]; }),
                edges: kept,
                folded: folded
            };
        }

        /**
         * Which end of a folded line sits above the other, as [upper, lower]
         * ids, or null. A body someone belonged to goes above what they went
         * on to found - AA over Synanon, through Dederich - which the layout
         * used to read off the person standing between the two.
         */
        function lineageOf(join) {
            var ends = [join.source.id, join.target.id];
            for (var i = 0; i < join.via.length; i++) {
                var at = join.via[i].at;
                for (var e = 0; e < 2; e++) {
                    var body = ends[e];
                    var made = ends[1 - e];
                    var member = (at[body] || []).some(function (edge) { return edge.category === 'membership'; });
                    var founder = (at[made] || []).some(function (edge) {
                        return (edge.roles || []).some(function (role) { return /found/i.test(role); });
                    });
                    if (member && founder) return [body, made];
                }
            }
            return null;
        }

        /** Has the visitor opened anything, or is this still the opening view? */
        focus.isFocused = function () { return chain.length > 0; };

        /* --------------------------------------------------------- hover -- */

        focus.hover = function (node) {
            /* The yoyo owns the offsets while it runs; a pointer passing
             * over a name mid-flight would yank it somewhere else. */
            if (yoyo) return;
            var id = node ? node.id : null;
            if (id === hoverId) return;
            hoverId = id;

            if (!id) {
                releaseGather();
                applyEmphasis();
                return;
            }

            var hood = neighbourhoodOf(id);
            var crowd = Object.keys(hood.near).length - 1 > GATHER_MAX;
            gather = {
                id: id,
                near: hood.near,
                nearEdges: hood.nearEdges,
                targets: crowd ? null : gatherTargets(id, hood.near),
                progress: gather && gather.progress ? gather.progress : 0,
                direction: 1
            };
            /* Under reduced motion, or with too many to move, the gather is
             * skipped entirely and hover is dimming alone, which loses
             * nothing factual. */
            if (crowd || prefersReducedMotion()) {
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

        /* ----------------------------------------------------- line hover -- */

        /* Ids of the lines under the pointer, or null. */
        var hoverEdgeIds = null;
        /* And the key of the circle or pill on one of them, if the pointer
         * is on that rather than the line. */
        var hoverMarkerKey = null;

        /** Every line on screen between these two names, either way round. */
        focus.linesBetween = function (a, b) {
            return ((current && current.edges) || []).filter(function (edge) {
                return (edge.sourceId === a && edge.targetId === b) ||
                    (edge.sourceId === b && edge.targetId === a);
            });
        };

        /**
         * The pointer is on a line, or has left it. Two names can have more
         * than one line between them - one owns the other and they shared a
         * director - and those are drawn along the same route, so pointing
         * at one is pointing at all of them.
         *
         * Returns false when the line is not there to be pointed at: while
         * a click is still reeling its names in, every line is on its way
         * somewhere else, and a popup pinned to one would be left behind.
         */
        focus.hoverEdge = function (edge, marker) {
            if (edge && yoyo) return false;
            var markKey = (edge && marker && marker.key) || null;
            /* Called on every move along a line; only a change repaints. */
            if (edge && hoverEdgeIds && hoverEdgeIds[edge.id] && markKey === hoverMarkerKey) return true;
            var next = null;
            if (edge) {
                next = Object.create(null);
                next[edge.id] = true;
                focus.linesBetween(edge.sourceId, edge.targetId).forEach(function (e) { next[e.id] = true; });
            }
            if (!next && !hoverEdgeIds) return true;
            hoverEdgeIds = next;
            hoverMarkerKey = markKey;
            applyEmphasis();
            viewport.scheduleDraw();
            return true;
        };

        function applyEmphasis() {
            renderer.setEmphasis({
                hoverId: hoverId,
                hoverEdges: hoverEdgeIds,
                hoverMarker: hoverMarkerKey,
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

        /* ---------------------------------------------------------- yoyo -- */

        /* A click, animated. The clicked name swells to its size with a
         * bounce, and its connections are reeled in along their lines,
         * overshooting a little towards it before they settle: a yoyo coming
         * back to the hand. Names already on screen travel from the spot on
         * the screen where they were; names new to the screen come in from
         * further out along the line to the click.
         *
         * The layout and the frame are worked out first and applied
         * outright, as always, and the motion is display offsets alone, so
         * the frame cannot disagree with what is drawn - which is why
         * layouts themselves are never tweened. The camera does not travel:
         * the old layout and the new one share no coordinates, and a camera
         * gliding between the two frames showed a magnified patch of the
         * wrong part of the board on the way. */
        var yoyo = null;
        var yoyoFrame = 0;
        /* Set by a click, so a reload, a resize or a filter lays out without
         * the show. */
        var animateNext = false;
        /* Ids on screen in the view being left, for the yoyo to start from. */
        var drawn = null;

        /** Where every drawn node appears right now, and the camera. */
        function snapshot() {
            if (!drawn) return null;
            var at = Object.create(null);
            Object.keys(drawn).forEach(function (id) {
                var node = store.node(id);
                if (!node) return;
                var p = positionOf(node);
                var off = offsets ? offsets[id] : null;
                at[id] = { x: off ? p.x + off[0] : p.x, y: off ? p.y + off[1] : p.y };
            });
            var t = viewport.transform;
            return { at: at, k: t.k, x: t.x, y: t.y };
        }

        /* Out fast, past the mark and back: a damped spring, about a tenth
         * over at its furthest. */
        function spring(t) {
            return t >= 1 ? 1 : 1 - Math.exp(-5.5 * t) * Math.cos(2.5 * Math.PI * t);
        }

        /* What every name in the view is grown by: one, except on the
         * opening view. The layout reads it so the room it leaves for a name
         * is the room the name is drawn in. */
        var baseGrow = 1;

        function growFor(node) {
            if (!node) return null;
            var g = Object.create(null);
            g[node.id] = HEAD_GROW;
            return g;
        }

        function stopYoyo() {
            if (yoyoFrame) { root.cancelAnimationFrame(yoyoFrame); yoyoFrame = 0; }
            if (yoyo) {
                yoyo = null;
                offsets = null;
            }
        }

        function startYoyo(scene, before, head) {
            if (!before || !head) return;
            var end = viewport.transform;
            /* Where a name was on screen, in the new view's coordinates. */
            var was = function (p) {
                return {
                    x: (p.x * before.k + before.x - end.x) / end.k,
                    y: (p.y * before.k + before.y - end.y) / end.k
                };
            };
            var headNow = positionOf(head);
            var headWas = before.at[head.id] ? was(before.at[head.id]) : headNow;
            var from = Object.create(null);
            scene.nodes.forEach(function (node) {
                var p = positionOf(node);
                var start = before.at[node.id] ? was(before.at[node.id]) : {
                    x: headWas.x + (p.x - headNow.x) * 1.8,
                    y: headWas.y + (p.y - headNow.y) * 1.8
                };
                from[node.id] = [start.x - p.x, start.y - p.y];
            });
            yoyo = { head: head.id };
            var began = root.performance ? root.performance.now() : Date.now();

            var step = function (now) {
                if (!yoyo) return;
                var t = Math.max(0, Math.min(1, (now - began) / YOYO_MS));
                var s = spring(t);
                var next = Object.create(null);
                Object.keys(from).forEach(function (id) {
                    next[id] = [from[id][0] * (1 - s), from[id][1] * (1 - s)];
                });
                offsets = t < 1 ? next : null;
                var g = Object.create(null);
                g[head.id] = 1 + (HEAD_GROW - 1) * s;
                renderer.setGrow(g);
                applyEmphasis();
                viewport.scheduleDraw();
                if (t < 1) {
                    yoyoFrame = root.requestAnimationFrame(step);
                    return;
                }
                yoyoFrame = 0;
                yoyo = null;
                viewport.rebuildTree();
            };
            /* The first frame now, so the end state never flashes up before
             * the motion starts. */
            step(began);
        }

        /* --------------------------------------------------------- chain -- */

        focus.select = function (node) {
            if (!node) return;
            if (mode === 'path') {
                /* A click on a route leaves it for that name's own view. In
                 * Focus whatever the toggle said before: Expand would lay
                 * every name on the route out with all it touches at once. */
                mode = 'focus';
                if (chain[chain.length - 1] !== node.id) chain.push(node.id);
                animateNext = true;
                enterFocus();
                return;
            }
            if (chain.length && chain[chain.length - 1] === node.id) return;
            /* In expand mode a node already on the trail is already on the
             * board; clicking it again adds nothing. */
            if (mode === 'expand' && chain.indexOf(node.id) !== -1) return;
            chain.push(node.id);
            animateNext = true;
            enterFocus();
        };

        /**
         * Several names at once, side by side: what Enter in the search box
         * does with more than one match. Focus mode shows one root at a time,
         * so this switches to expand, where the trail is a union - and says
         * so, because the toggle the visitor set has just changed under them.
         */
        focus.openAll = function (nodes) {
            var ids = (nodes || []).filter(Boolean).map(function (n) { return n.id; });
            if (!ids.length) return;
            if (ids.length === 1) { focus.select(nodes[0]); return; }
            mode = 'expand';
            ids.forEach(function (id) {
                if (chain.indexOf(id) === -1) chain.push(id);
            });
            enterFocus();
            announce(ids.length + ' names opened together. The map is now in Expand mode.');
        };

        /**
         * Put one route on the board: ids in order, first to last. False,
         * and nothing changes, when a step has no line under the current
         * filters.
         */
        focus.showPath = function (ids) {
            var before = { chain: chain, mode: mode };
            chain = (ids || []).slice();
            mode = 'path';
            if (!pathHolds()) {
                chain = before.chain;
                mode = before.mode;
                return false;
            }
            enterFocus();
            return true;
        };

        /**
         * Put a whole trail back at once: a shared link, or the page reloaded.
         * Unknown ids are dropped (a board export can rename a node), and an
         * empty result is the opening view. Nothing is announced beyond what
         * entering the view announces.
         */
        focus.restore = function (ids, nextMode) {
            if (nextMode === 'expand' || nextMode === 'focus' || nextMode === 'path') mode = nextMode;
            var live = store.visible().nodeIds;
            var seen = Object.create(null);
            chain = (ids || []).filter(function (id) {
                if (seen[id] || !store.node(id) || !live[id]) return false;
                seen[id] = true;
                return true;
            });
            if (!chain.length) {
                if (mode === 'path') mode = 'focus';
                layout = null;
                showOpeningView();
                onChange();
                return;
            }
            enterFocus();
        };

        focus.truncateTo = function (index) {
            if (index < 0 || index >= chain.length) return;
            if (index === chain.length - 1) return;
            chain = chain.slice(0, index + 1);
            animateNext = true;
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
            if (mode === 'path') mode = 'focus';
            layout = null;
            hoverId = null;
            hoverEdgeIds = null;
            hoverMarkerKey = null;
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
            /* Back to the start: the next click settles from what is on screen. */
            seedKey = null;
            stopYoyo();
            var scene = focus.scene();
            baseGrow = Math.max(1, Math.min(OPENING_GROW, (renderer.width || 0) / OPENING_GROW_WIDTH));
            var grown = Object.create(null);
            scene.nodes.forEach(function (node) { grown[node.id] = baseGrow; });
            renderer.setGrow(grown);
            current = scene;
            renderer.setScene(scene);
            viewport.setScene(scene);
            drawn = scene.nodeIds;
            if (!scene.nodes.length) return;
            if (!renderer.width) return; /* no stage yet; the resize observer calls back */
            var head = headOf();
            if (head) {
                /* Opened on one organisation: laid out, grown and framed as
                 * a click on it would be, not as a block of grown names. */
                baseGrow = 1;
                scene.nodes.forEach(function (node) { grown[node.id] = 1; });
                renderer.setGrow(growFor(head));
                var cluster = settleLayout(scene, 70);
                applyLayout(scene, cluster.positions, 70 + cluster.overhang);
                return;
            }
            /* The frame is fitted to where names are centred, so the padding
             * has to hold half of the widest one as it is drawn here. */
            var padding = baseGrow === 1 ? 90 : scene.nodes.reduce(function (t, node) {
                var w = renderer.labelBox ? renderer.labelBox(node).width : 0;
                return Math.max(t, w * baseGrow / 2 + 24);
            }, 90);
            var opening = settleLayout(scene, padding);
            applyLayout(scene, opening.positions, padding + opening.overhang);
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
        /* The padding the current view was framed with; see applyLayout. */
        var lastPadding = 70;

        /**
         * Fit to screen: frame everything on the board where it stands.
         *
         * Reset view lays the trail out again, which is the right answer to
         * a board that has been dragged about, and the wrong one to a reader
         * who has only zoomed too far in and wants the whole of what they
         * were looking at back. This moves the camera and nothing else.
         */
        focus.fitAll = function () {
            if (!store.ready) return;
            var scene = focus.scene();
            if (!scene.nodes.length) return;
            viewport.fit(scene.nodes, lastPadding);
        };

        focus.reframe = function () {
            if (!store.ready) return;
            /* A click opens the drawer, which narrows the stage and lands
             * here mid-yoyo. Carry on from wherever the names are rather
             * than cutting to the end. */
            if (yoyo) animateNext = true;
            if (chain.length) enterFocus();
            else showOpeningView();
        };

        /* Where the settle for the current trail started from. Laying the
         * same trail out again (Reset view, a resize, a filter) starts from
         * the same place, so a dragged node goes back to its cell instead of
         * seeding a different arrangement from where it was dropped. */
        var seedKey = null;
        var seed = null;

        function enterFocus() {
            var animate = animateNext && !prefersReducedMotion();
            animateNext = false;
            var before = animate ? snapshot() : null;
            stopYoyo();
            stopSettle();
            dropGather();
            hoverId = null;
            hoverEdgeIds = null;
            hoverMarkerKey = null;

            /* A route cut back to one name, or one whose step a filter has
             * just taken away, is not a route: show its last name instead. */
            if (mode === 'path' && !pathHolds()) mode = 'focus';

            var scene = focus.scene();
            if (!scene.nodes.length) {
                /* The filters can hide everything the chain points at. Rather
                 * than show a blank stage, fall back to the whole map. */
                resetToWholeMap();
                return;
            }
            current = scene;

            var key = mode + ':' + chain.join(',');
            if (key !== seedKey) {
                /* The first click off a grown opening view settles from the
                 * map's own positions. That arrangement is sized to the
                 * window, so seeding from it made what a click on Sequel
                 * looked like depend on how wide the window was when the
                 * page opened, and at 1920px pushed fourteen of its names
                 * off the stage. */
                var fromOpening = seedKey === null && baseGrow > 1;
                seedKey = key;
                seed = Object.create(null);
                scene.nodes.forEach(function (node) {
                    var p = fromOpening ? node : positionOf(node);
                    seed[node.id] = { x: p.x, y: p.y };
                });
            }
            /* A route has two ends and no head: nothing on it is grown. */
            var head = inPath() ? null : store.node(chain[chain.length - 1]);
            /* Grown before the frame is chosen, so the zoom is measured
             * against the size it will be drawn at. */
            baseGrow = 1;
            renderer.setGrow(growFor(head));
            var settled = settleLayout(scene, 70, seed);
            applyLayout(scene, settled.positions, 70 + settled.overhang);

            renderer.setEmphasis({ hoverId: null });
            renderer.setScene(scene);
            viewport.setScene(scene);
            drawn = scene.nodeIds;
            if (before) startYoyo(scene, before, head);

            if (inPath()) {
                var names = chain.map(function (id) { return (store.node(id) || {}).name || id; });
                announce('Route from ' + names[0] + ' to ' + names[names.length - 1] + ', ' +
                    (chain.length - 1) + (chain.length === 2 ? ' step: ' : ' steps: ') + names.join(', then ') + '.');
            } else {
                announce(head
                    ? (head.name + ' and ' + (scene.nodes.length - 1) + ' connected names. ' +
                        'Step ' + chain.length + ' of your trail.')
                    : (scene.nodes.length + ' names.'));
            }
            onChange();
        }

        /* ------------------------------------------------------ settling -- */

        /**
         * Run the force settle once, synchronously, and return where every
         * node in the focused scene should end up. Seeded from where each
         * node currently appears (or from `from`, where given), so the
         * animation that follows is a rearrangement of what is on screen
         * rather than a cut.
         */
        function settleLayout(scene, gridPadding, from) {
            var d3 = root.d3;
            var byId = Object.create(null);
            var points = scene.nodes.map(function (node) {
                var p = (from && from[node.id]) || positionOf(node);
                var point = {
                    id: node.id, r: node.r, x: p.x, y: p.y,
                    label: Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH + BUBBLE_EXTRA) * baseGrow,
                    years: !!node.years,
                    node: node
                };
                byId[node.id] = point;
                return point;
            });

            /* The simulation consumes these and so does the grid, which
             * walks them outwards from whatever was opened. d3.forceLink
             * rewrites source and target into node references as a side
             * effect, so the grid reads the ids back off the endpoints
             * rather than trusting the fields it passed in. */
            var links = scene.edges.map(function (edge) {
                return {
                    source: edge.sourceId, target: edge.targetId,
                    category: edge.category, direction: edge.direction, roles: edge.roles,
                    /* A folded line's lineage, [upper, lower]; see lineageOf. */
                    above: edge.above || null
                };
            });

            if (inPath()) {
                var routeOverhang = pathLayout(points, gridPadding);
                var routePositions = Object.create(null);
                points.forEach(function (point) {
                    routePositions[point.id] = { x: point.x, y: point.y };
                });
                return { positions: routePositions, points: points, byId: byId, overhang: routeOverhang };
            }

            var overhang = clusterLayout(points, links, currentRoots());

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
         * A route, laid out as one: its names in order along a row, or down
         * a column when the row would not fit the stage.
         *
         * The board layout arranges by hierarchy - owners above what they
         * own, people hung off their places - which is right for a
         * neighbourhood and wrong here: a route through a company, a
         * programme and a person came out as a zigzag that had to be traced
         * by eye to find its order. In a line the order is the reading
         * order, every step is one straight trace, and each trace has room
         * for its caption, so the route reads as a sentence: who, what joins
         * them to the next, and so on to the other end.
         *
         * A column rather than a smaller row when the row is too wide: names
         * are drawn at one size whatever the zoom, so zooming a row out to
         * fit only pushes the names into each other.
         */
        var PATH_ROW_GAP = 150;
        var PATH_COLUMN_GAP = 34;

        function pathLayout(points, padding) {
            grid = null;
            if (!points.length || !renderer.width) return 0;
            var stepOf = Object.create(null);
            chain.forEach(function (id, i) { stepOf[id] = i; });
            var ordered = points.slice().sort(function (a, b) { return stepOf[a.id] - stepOf[b.id]; });

            var board = Math.max(200, renderer.width - padding * 2);
            var tallest = ordered.reduce(function (t, p) { return Math.max(t, p.r); }, 0);
            var yearsLine = (root.KOPNetworkCanvas && root.KOPNetworkCanvas.YEARS_LINE) || 11;
            /* A row is as tall as the deepest label in it: a name can carry
             * its years and the name it traded under before. */
            var rowH = tallest * 2 + LABEL_ROOM + ROW_GUTTER +
                ordered.reduce(function (deepest, p) {
                    return Math.max(deepest, subLineCount(p));
                }, 0) * yearsLine;

            var total = ordered.reduce(function (sum, p) { return sum + p.label; }, 0) +
                PATH_ROW_GAP * (ordered.length - 1);
            if (total <= board) {
                var x = -total / 2;
                ordered.forEach(function (p) {
                    p.x = x + p.label / 2;
                    p.y = 0;
                    x += p.label + PATH_ROW_GAP;
                });
                grid = { x0: -total / 2, y0: -rowH / 2, cellW: total, cellH: rowH, cols: 1, rows: 1 };
                /* The frame is fitted to where names are centred, and a name
                 * keeps its size as the view zooms in to fill the stage, so
                 * the two end names hang over the frame by half their drawn
                 * width. Without this a three-name row was framed with both
                 * ends cut off by the stage's edges. */
                var drawnHalf = function (p) {
                    return (p.node && renderer.labelBox ? renderer.labelBox(p.node).width : p.label) / 2;
                };
                return Math.max(0, Math.max(drawnHalf(ordered[0]), drawnHalf(ordered[ordered.length - 1])) + 12 - padding);
            }

            var pitch = rowH + PATH_COLUMN_GAP;
            var widest = ordered.reduce(function (w, p) { return Math.max(w, p.label); }, 0);
            var top = -pitch * (ordered.length - 1) / 2;
            ordered.forEach(function (p, i) {
                p.x = 0;
                p.y = top + i * pitch;
            });
            grid = {
                x0: -widest / 2, y0: top - pitch / 2, cellW: widest, cellH: pitch,
                cols: 1, rows: ordered.length
            };
            return 0;
        }

        /**
         * Lay the view out as a cluster: what was clicked in the middle and
         * everything it touches gathered round it, each name as far from
         * the next as its bubble needs and no further.
         *
         * Lines are drawn straight, so the layout that makes them shortest
         * is the one where each name sits as near as it can to what it
         * connects to and leaves its neighbours by whichever side faces
         * them. Rows cannot give that: with every name on a row and the
         * lines run through the gutters, every line left its name from
         * the bottom, and a line to the next name over went down, along
         * and back up, which is what the owner asked to have undone.
         *
         * The shape is seeded by hand and then settled. Round each root
         * (the click, or every name on an expanded trail) its connections
         * go on rings: who owned it above, what it owned or became below,
         * everyone else at the sides, a ring further out whenever the one
         * inside is full. Everything two steps out is seeded beyond the
         * name it hangs off. The force settle then takes out the overlaps
         * and pulls each name toward what it connects to, anchored to its
         * seed so the reading - owners above, holdings below - survives.
         *
         * Built in screen pixels, centred on the origin, so the fit that
         * follows lands at a zoom of about one. Bubbles are boxes, and it
         * is their boxes that keep names apart, not the link lengths.
         */
        /* Wide enough for a line to pass between two names: the router
         * keeps 4px clear of a name and turns 2px outside that, so two
         * names 18px apart leave it a 6px corridor. */
        var CLUSTER_GAP = 18;
        var OWNER_ABOVE = 36;
        var CLUSTER_TICKS = 220;
        /* How far beyond the name it hangs off a name two steps out is
         * seeded. */
        var RING_STEP = 58;
        /* The stage's width the fan may use, less this much either side. */
        var FAN_MARGIN = 70;
        /* Clear space between two roots' clusters on an expanded trail. */
        var CLUSTER_APART = 48;

        function clusterLayout(points, links, roots) {
            grid = null;
            if (!points.length || !renderer.width) return 0;
            var d3 = root.d3;
            var painter = root.KOPNetworkCanvas || {};
            var yearsLine = painter.YEARS_LINE || 11;
            var pitch = painter.LABEL_PITCH || 33;
            /* One root is a click: it holds the middle. Several (an
             * expanded trail) share it. The opening view has no trail, so
             * every name on it is a root of its own, and the ones that
             * connect gather round whichever comes first. */
            if (!roots.length) roots = points.map(function (p) { return p.id; });
            var headId = roots.length === 1 ? roots[0] : null;

            var byId = Object.create(null);
            points.forEach(function (p) {
                byId[p.id] = p;
                var box = p.node && renderer.labelBox
                    ? renderer.labelBox(p.node)
                    : { width: p.label, height: LABEL_ROOM + subLineCount(p) * yearsLine };
                var grow = p.id === headId ? HEAD_GROW : baseGrow;
                p.hw = Math.max(box.width * grow / 2, p.r) + CLUSTER_GAP / 2;
                p.hh = Math.max(box.height * grow / 2, p.r) + CLUSTER_GAP / 2;
            });

            seedCluster(points, links, roots, byId);
            points.forEach(function (p) {
                p.sx = p.x;
                p.sy = p.y;
                if (p.id === headId) { p.fx = 0; p.fy = 0; }
            });
            var report = function (stage) {
                if (!root.KOP_NET_DEBUG) return;
                var ov = 0, minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity, bad = 0;
                for (var oa = 0; oa < points.length; oa++) {
                    var pa = points[oa];
                    if (!isFinite(pa.x) || !isFinite(pa.y)) bad++;
                    minX = Math.min(minX, pa.x); maxX = Math.max(maxX, pa.x); minY = Math.min(minY, pa.y); maxY = Math.max(maxY, pa.y);
                    for (var ob = oa + 1; ob < points.length; ob++) {
                        var pb = points[ob];
                        if (Math.abs(pa.x - pb.x) < pa.hw + pb.hw - 0.5 && Math.abs(pa.y - pb.y) < pa.hh + pb.hh - 0.5) ov++;
                    }
                }
                console.log('KOPDEBUG', stage, points.length, 'points; overlaps', ov, 'bad', bad, 'bbox', Math.round(minX), Math.round(minY), Math.round(maxX), Math.round(maxY));
            };
            report('seeded');

            if (d3 && d3.forceSimulation && points.length > 1) {
                var sim = d3.forceSimulation(points)
                    .force('link', d3.forceLink(links)
                        .id(function (d) { return d.id; })
                        /* Never shorter than the two boxes side by side:
                         * a hub's two dozen programmes pulled to seventy
                         * pixels of it were a heap the collision could
                         * not undo. */
                        .distance(function (l) {
                            var base = LINK_DISTANCE[l.category] || DEFAULT_DISTANCE;
                            return Math.max(base, l.source.hw + l.target.hw);
                        })
                        .strength(0.15))
                    .force('charge', d3.forceManyBody().strength(-60).distanceMax(320))
                    .force('boxes', boxCollide(points))
                    .force('down', readDown(links, byId))
                    /* Anchored to the seed, not to the origin: the seed is
                     * the reading, the settle only tidies it. */
                    .force('x', d3.forceX(function (d) { return d.sx; }).strength(0.12))
                    .force('y', d3.forceY(function (d) { return d.sy; }).strength(0.12))
                    .stop();
                for (var i = 0; i < CLUSTER_TICKS; i++) sim.tick();
                sim.stop();
                report('settled');
                /* What the settle left overlapping is shelved: names at
                 * about one height go on one row, side by side in the
                 * order the settle left them, and the rows are stacked
                 * clear of each other outward from the click. Pushing
                 * pairs apart never converged on a heap; shelving is one
                 * pass and cannot leave two names sharing pixels. */
                shelve(points, headId);
            }
            report('parted');
            points.forEach(function (p) { delete p.fx; delete p.fy; });

            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            points.forEach(function (p) {
                minX = Math.min(minX, p.x - p.hw);
                maxX = Math.max(maxX, p.x + p.hw);
                minY = Math.min(minY, p.y - p.hh);
                maxY = Math.max(maxY, p.y + p.hh);
            });
            grid = {
                x0: minX, y0: minY, cellW: maxX - minX, cellH: pitch,
                cols: 1, rows: Math.max(1, Math.ceil((maxY - minY) / pitch))
            };
            /* Every name has exactly its own width, so nothing hangs over. */
            return 0;
        }

        /** The id at either end of a link, before or after d3 has been at it. */
        function idOf(end) {
            return end && end.id !== undefined ? end.id : end;
        }

        /**
         * Which end of an ownership or rename sits above the other, as
         * [upper, lower], or null for a line with no up and down to it. A
         * company is above the place it owns whichever way the record was
         * typed; between two of a kind, the source is the owner. People are
         * left out: where a person owned a place, the drawer says so.
         */
        function upperOf(link, byId) {
            var a = byId[idOf(link.source)], b = byId[idOf(link.target)];
            if (!a || !b) return null;
            /* A folded line carries its lineage: the body someone belonged
             * to above what they founded (AA above Synanon). */
            if (link.above && link.above.length === 2) {
                var up = byId[link.above[0]], lo = byId[link.above[1]];
                return up && lo ? [up, lo] : null;
            }
            var owns = link.category === 'corporate' || link.direction === 'renamed';
            if (!owns) return null;
            if (a.node.kind === 'person' || b.node.kind === 'person') return null;
            if (link.direction !== 'renamed') {
                var company = function (p) { return p.node.kind === 'parent' || p.node.kind === 'association'; };
                if (company(a) !== company(b)) return company(a) ? [a, b] : [b, a];
            }
            return [a, b];
        }

        /**
         * Seed positions: rings round each root, then the rest beyond the
         * name they hang off. Angles are in degrees with y downward, so 90
         * is straight down the stage.
         */
        function seedCluster(points, links, roots, byId) {
            var adj = Object.create(null);
            links.forEach(function (l) {
                var a = idOf(l.source), b = idOf(l.target);
                if (!byId[a] || !byId[b]) return;
                (adj[a] = adj[a] || []).push({ other: b, link: l });
                (adj[b] = adj[b] || []).push({ other: a, link: l });
            });
            var placed = Object.create(null);
            var clusters = [];

            roots.forEach(function (rid) {
                var head = byId[rid];
                if (!head || placed[rid]) return;
                placed[rid] = true;
                /* Its connections, by where they go: above, below, beside. */
                var groups = { upper: [], right: [], lower: [], left: [] };
                var sideNext = 0;
                (adj[rid] || []).forEach(function (a) {
                    if (placed[a.other]) return;
                    var p = byId[a.other];
                    var ud = upperOf(a.link, byId);
                    var where;
                    if (ud && ud[0] === head) where = 'lower';
                    else if (ud && ud[1] === head) where = 'upper';
                    else where = (sideNext++ % 2) ? 'left' : 'right';
                    groups[where].push(p);
                    placed[a.other] = true;
                });
                /* The fan: rows of what it owns below, rows of its owners
                 * above, the rest in columns either side, all centred on
                 * the root and no wider than the stage. Rows rather than
                 * rings because bubbles are wide and low: a ring spent the
                 * stage's height on names that could have sat beside each
                 * other, and a fan the stage could not frame left most of
                 * a click's connections a pan away. */
                var stageW = Math.max(500, (renderer.width || 1200) - FAN_MARGIN * 2);
                var sides = groups.left.concat(groups.right);
                var sideW = sides.reduce(function (t, p) { return Math.max(t, p.hw * 2); }, 0);
                var roomW = sides.length ? Math.max(320, stageW - 2 * (sideW + CLUSTER_GAP)) : stageW;
                var below = fanRows(groups.lower, head, 1, roomW);
                var above = fanRows(groups.upper, head, -1, roomW);
                var widest = Math.max(head.hw * 2, below.widest, above.widest);
                var tall = Math.max(above.extent + below.extent, head.hh * 2);
                /* A column may run a few rows tall even beside a root with
                 * nothing above or below it. */
                var colTall = Math.max(tall, 4 * (head.hh * 2 + CLUSTER_GAP));
                var extent = widest / 2;
                if (groups.right.length) extent = Math.max(extent, fanColumns(groups.right, head, 1, widest / 2 + CLUSTER_GAP, colTall));
                if (groups.left.length) extent = Math.max(extent, fanColumns(groups.left, head, -1, widest / 2 + CLUSTER_GAP, colTall));
                tall = Math.max(tall, head.ring ? head.ring.reduce(function (t, p) {
                    return Math.max(t, Math.abs(p.y) * 2 + p.hh * 2);
                }, 0) : 0);
                head.root = head;
                head.ring = head.ring || [];
                clusters.push({ head: head, extent: extent, tall: tall });
            });

            /* Roots side by side, each with its cluster's room, wrapping
             * onto another row when the stage is too narrow for the next:
             * the opening view is a block of names, and on a phone a
             * column. Names are drawn at one size whatever the zoom, so
             * zooming a row out to fit only pushes the names into each
             * other. */
            /* Wrapped well short of the stage's width, so the block is a
             * block and not a strip: a row of grown names across the
             * whole stage is what the opening view used to be. */
            var stageW = Math.max(240, ((renderer.width || 1200) - 40) * 0.7);
            var x = 0, y = 0, rowTall = 0, width = 0;
            clusters.forEach(function (c, i) {
                var span = c.extent * 2;
                var tallC = c.tall || c.extent * 2;
                if (i && x + CLUSTER_APART + span > stageW) {
                    width = Math.max(width, x);
                    y += rowTall + CLUSTER_APART;
                    x = 0;
                    rowTall = 0;
                }
                if (x) x += CLUSTER_APART;
                c.x = x + c.extent;
                c.y = y;
                x += span;
                rowTall = Math.max(rowTall, tallC);
            });
            width = Math.max(width, x);
            var height = y + rowTall;
            clusters.forEach(function (c) {
                var dx = c.x - width / 2;
                var dy = c.y + (c.tall || c.extent * 2) / 2 - height / 2;
                c.head.x = dx;
                c.head.y = dy;
                c.head.ring.forEach(function (p) { p.x += dx; p.y += dy; });
            });

            /* Two steps out: beyond the name each hangs off, along the line
             * from that name's root, a little apart from the last one
             * seeded there. Repeated until nothing new is placed, so a
             * chain three steps long still lands. */
            var more = true;
            while (more) {
                more = false;
                points.forEach(function (p) {
                    if (placed[p.id]) return;
                    var anchor = null, anchorLink = null;
                    (adj[p.id] || []).some(function (a) {
                        if (placed[a.other] && byId[a.other].root) { anchor = byId[a.other]; anchorLink = a.link; return true; }
                        return false;
                    });
                    if (!anchor) return;
                    var c = anchor.root;
                    var ax = anchor.x - c.x, ay = anchor.y - c.y;
                    var len = Math.hypot(ax, ay);
                    if (len < 1) { ax = 0; ay = 1; len = 1; }
                    ax /= len; ay /= len;
                    var out = RING_STEP * 1.25;
                    /* Hung off an ownership or a rename, it goes straight
                     * below or above the name it hangs off instead, so the
                     * pair reads the right way up from the start. */
                    var ud = upperOf(anchorLink, byId);
                    if (ud) {
                        ay = ud[0] === anchor ? 1 : -1;
                        ax = 0;
                        out = anchor.hh + p.hh + OWNER_ABOVE;
                    }
                    var n = anchor.hung = (anchor.hung || 0) + 1;
                    var side = (n % 2 ? 1 : -1) * Math.ceil(n / 2) * (p.hw + anchor.hw) * 0.5;
                    p.x = anchor.x + ax * out - ay * side;
                    p.y = anchor.y + ay * out + ax * side;
                    p.root = c;
                    placed[p.id] = true;
                    more = true;
                });
            }
            /* Anything left touches nothing placed: it stays where it was,
             * or takes a spot on the right if it was nowhere. */
            var loose = 0;
            points.forEach(function (p) {
                if (placed[p.id]) return;
                if (!isFinite(p.x) || !isFinite(p.y)) {
                    p.x = width / 2 + RING_STEP * 2 + (loose++) * RING_STEP;
                    p.y = 0;
                }
            });
        }

        /**
         * Rows of names below (sign 1) or above (sign -1) the root, each
         * row centred on it and no wider than `roomW`, the first row
         * against the root and each next one a row further out. Returns
         * how far out the rows reach and the widest row.
         */
        function fanRows(list, head, sign, roomW) {
            var y = head.hh + CLUSTER_GAP;
            var widest = 0;
            var i = 0;
            head.ring = head.ring || [];
            while (i < list.length) {
                var row = [];
                var w = 0;
                var rowH = 0;
                while (i < list.length) {
                    var pw = list[i].hw * 2;
                    /* A row always takes one name, however wide. */
                    if (row.length && w + pw > roomW) break;
                    row.push(list[i]);
                    w += pw;
                    rowH = Math.max(rowH, list[i].hh * 2);
                    i++;
                }
                var x = -w / 2;
                row.forEach(function (p) {
                    p.x = x + p.hw;
                    p.y = sign * (y + rowH / 2);
                    p.root = head;
                    head.ring.push(p);
                    x += p.hw * 2;
                });
                widest = Math.max(widest, w);
                y += rowH;
            }
            return { extent: list.length ? y : head.hh, widest: widest };
        }

        /**
         * Columns of names to the right (sign 1) or left (sign -1) of the
         * root's rows, each column centred on the root's row and no taller
         * than `tall`, the first against the rows at `x0` and each next one
         * a column further out. Returns how far out the columns reach.
         */
        function fanColumns(list, head, sign, x0, tall) {
            var x = x0;
            var i = 0;
            head.ring = head.ring || [];
            while (i < list.length) {
                var column = [];
                var h = 0;
                var colW = 0;
                while (i < list.length) {
                    var ph = list[i].hh * 2;
                    if (column.length && h + ph > tall) break;
                    column.push(list[i]);
                    h += ph;
                    colW = Math.max(colW, list[i].hw * 2);
                    i++;
                }
                var y = -h / 2;
                column.forEach(function (p) {
                    p.x = sign * (x + colW / 2);
                    p.y = y + p.hh;
                    p.root = head;
                    head.ring.push(p);
                    y += p.hh * 2;
                });
                x += colW + CLUSTER_GAP;
            }
            return x;
        }

        /**
         * Shelve the settled names: rows of names at about one height,
         * each row's names side by side in the order the settle left
         * them, centred where they were, and the rows stacked clear of
         * each other outward from the click, which stays where it is. The
         * settle's shape survives - what it put below stays below, what
         * it put left stays left - and nothing overlaps.
         */
        function shelve(points, headId) {
            var head = headId ? points.filter(function (p) { return p.id === headId; })[0] : null;
            var sorted = points.slice().sort(function (a, b) { return a.y - b.y; });
            var pitch = points.reduce(function (t, p) { return Math.max(t, p.hh * 2); }, 0) + CLUSTER_GAP;
            var rows = [];
            sorted.forEach(function (p) {
                var row = rows[rows.length - 1];
                if (row && p.y - row.top < pitch * 0.75) { row.members.push(p); return; }
                rows.push({ top: p.y, members: [p] });
            });
            rows.forEach(function (row) {
                var m = row.members;
                m.sort(function (a, b) { return a.x - b.x; });
                row.h = m.reduce(function (t, p) { return Math.max(t, p.hh * 2); }, 0);
                row.y = m.reduce(function (t, p) { return t + p.y; }, 0) / m.length;
                var w = m.reduce(function (t, p) { return t + p.hw * 2; }, 0) + CLUSTER_GAP * (m.length - 1);
                var x = m.reduce(function (t, p) { return t + p.x; }, 0) / m.length - w / 2;
                m.forEach(function (p) {
                    p.x = x + p.hw;
                    x += p.hw * 2 + CLUSTER_GAP;
                });
                /* The click keeps its spot: its row slides so it does. */
                if (head && m.indexOf(head) !== -1) {
                    var dx = head.x;
                    m.forEach(function (p) { p.x -= dx; });
                    row.y = 0;
                    row.head = true;
                }
            });
            var at = 0;
            rows.forEach(function (row, i) { if (row.head) at = i; });
            for (var below = at + 1; below < rows.length; below++) {
                var need = (rows[below - 1].h + rows[below].h) / 2 + CLUSTER_GAP;
                rows[below].y = Math.max(rows[below].y, rows[below - 1].y + need);
            }
            for (var above = at - 1; above >= 0; above--) {
                var room = (rows[above + 1].h + rows[above].h) / 2 + CLUSTER_GAP;
                rows[above].y = Math.min(rows[above].y, rows[above + 1].y - room);
            }
            rows.forEach(function (row) {
                row.members.forEach(function (p) { p.y = row.y; });
            });
        }

        /**
         * Bubbles are boxes, not discs: two names side by side need their
         * half-widths between them, two stacked only their half-heights. A
         * disc wide enough for a name spread the rows far apart. This
         * pushes an overlapping pair apart along whichever axis clears
         * them sooner, a pinned name standing still.
         */
        function boxCollide(points) {
            var strength = 0.6;
            return function () {
                for (var i = 0; i < points.length; i++) {
                    var a = points[i];
                    for (var j = i + 1; j < points.length; j++) {
                        var b = points[j];
                        var dx = (b.x + b.vx) - (a.x + a.vx);
                        var dy = (b.y + b.vy) - (a.y + a.vy);
                        var ox = a.hw + b.hw - Math.abs(dx);
                        var oy = a.hh + b.hh - Math.abs(dy);
                        if (ox <= 0 || oy <= 0) continue;
                        /* Two names on one spot part sideways, in a fixed
                         * order, so a settle is the same every time. */
                        if (dx === 0 && dy === 0) dx = 1e-3;
                        var pushX = 0, pushY = 0;
                        if (ox < oy) pushX = (dx < 0 ? -1 : 1) * ox * strength;
                        else pushY = (dy < 0 ? -1 : 1) * oy * strength;
                        var aFixed = a.fx != null, bFixed = b.fx != null;
                        var shareA = aFixed ? 0 : (bFixed ? 1 : 0.5);
                        var shareB = bFixed ? 0 : (aFixed ? 1 : 0.5);
                        a.vx -= pushX * shareA; a.vy -= pushY * shareA;
                        b.vx += pushX * shareB; b.vy += pushY * shareB;
                    }
                }
            };
        }

        /**
         * A company above what it owns, a place above what it became: each
         * ownership or rename nudges its lower end below its upper by the
         * two half-heights and a gap. A nudge among the other forces, so
         * it holds where the room allows and gives way rather than push a
         * name off the stage; the seed has already put the pair the right
         * way up, so it has little to do.
         */
        function readDown(links, byId) {
            var strength = 0.8;
            return function (alpha) {
                for (var i = 0; i < links.length; i++) {
                    var ud = upperOf(links[i], byId);
                    if (!ud) continue;
                    var upper = ud[0], lower = ud[1];
                    var want = upper.hh + lower.hh + OWNER_ABOVE;
                    var dy = lower.y - upper.y;
                    if (dy >= want) continue;
                    var k = (want - dy) * strength * alpha;
                    var upFixed = upper.fx != null, lowFixed = lower.fx != null;
                    if (!upFixed) upper.vy -= k * (lowFixed ? 1 : 0.5);
                    if (!lowFixed) lower.vy += k * (upFixed ? 1 : 0.5);
                }
            };
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
            /* Kept for Fit to screen, which frames the same names with the
             * same breathing room without laying them out again. */
            lastPadding = padding;
            var next = Object.create(null);
            scene.nodes.forEach(function (node) {
                var target = targets[node.id];
                if (target) next[node.id] = { x: target.x, y: target.y };
            });
            layout = next;
            renderer.setGrid(grid);

            /* Names are drawn at a fixed size whatever the zoom, so framing a
             * block that does not fit means zooming out until the rows are
             * closer together on screen than a name is tall - and then the
             * renderer has to drop names, which is the one thing it must not
             * do. On a stage too small for the whole block, hold the zoom at
             * the point where the rows still clear each other, put what was
             * clicked in the middle, and let the reader pan to the rest. A
             * stage that can hold the block is framed as before.
             */
            var floor = legibleZoom(scene, next);
            var points = scene.nodes.map(function (node) {
                var p = next[node.id] || positionOf(node);
                return { x: p.x, y: p.y, r: node.r };
            });
            var frame = viewport.frameOf(points, padding);
            /* What was just clicked (or the organisation the map opened on)
             * and its own connections: the part of the board the visitor is
             * looking at. */
            var head = headOf();
            var near = null;
            if (head) {
                /* Read off the lines on screen, so a place joined to the
                 * click through a folded person counts as its own. */
                var ownIds = Object.create(null);
                ownIds[head.id] = true;
                scene.edges.forEach(function (edge) {
                    if (edge.sourceId === head.id) ownIds[edge.targetId] = true;
                    else if (edge.targetId === head.id) ownIds[edge.sourceId] = true;
                });
                var own = scene.nodes.filter(function (node) { return ownIds[node.id]; }).map(function (node) {
                    var p = next[node.id] || positionOf(node);
                    return { x: p.x, y: p.y, r: node.r };
                });
                near = viewport.frameOf(own, padding, NEAR_MAX_ZOOM);
            }

            /* Names are drawn at a fixed size whatever the zoom, so framing a
             * block too big for the stage zooms out until names collide and
             * the renderer has to drop some - the one thing it must not do.
             * The renderer is asked, at each candidate zoom, how many names
             * it would drop (dropsAt), and the view takes the lowest zoom at
             * which the answer is none: the whole block when that fits,
             * otherwise the click and its own connections, otherwise the
             * click alone at the middle with the rest a pan away. The floor
             * from legibleZoom bounds the search from above: at the floor the
             * rows and neighbours clear each other by construction. */
            var at = function (node) { return next[node.id] || positionOf(node); };
            var mid = function (f) {
                return { x: (renderer.width / 2 - f.x) / f.k, y: (renderer.height / 2 - f.y) / f.k };
            };
            var centredAt = function (k, centre) {
                return { k: k, x: renderer.width / 2 - centre.x * k, y: renderer.height / 2 - centre.y * k };
            };
            var clean = function (t) {
                return !renderer.dropsAt || renderer.dropsAt(scene.nodes, at, t.k, t.x, t.y) === 0;
            };
            /* The lowest zoom in [lo, hi] at which nothing on the stage is
             * dropped, around a fixed centre. Nine halvings is well under a
             * percent of zoom, and each is one dry run of the label pass. */
            var lowestClean = function (lo, hi, centre) {
                if (clean(centredAt(lo, centre))) return lo;
                /* The top of the range is not always clean either, and a
                 * search that assumed it was handed back a zoom that dropped
                 * names on a phone. Step in until it is. */
                for (var up = 0; up < 6 && !clean(centredAt(hi, centre)); up++) hi *= 1.25;
                for (var step = 0; step < 9; step++) {
                    var m = (lo + hi) / 2;
                    if (clean(centredAt(m, centre))) hi = m; else lo = m;
                }
                return hi;
            };

            /* A click zooms in on what was clicked and its own connections,
             * whenever that is a real step closer than the whole board and
             * drops no name. The rest of the board is a pan away. */
            var chosen = null;
            /* A small view that fits the stage whole at full size is shown
             * whole: zooming in on the click's own connections would leave
             * the two steps out a pan away for no gain. */
            if (near && frame && frame.k < 1 && near.k > frame.k * CLOSER && clean(near)) {
                chosen = near;
            } else if (frame && clean(frame)) {
                chosen = null;   /* the whole block fits as it is */
            } else if (frame) {
                var top = Math.max(floor || frame.k, frame.k);
                if (mode === 'expand' && chain.length > 1 && near) {
                    chosen = centredAt(lowestClean(Math.max(near.k, frame.k), Math.max(top, near.k), mid(near)), mid(near));
                } else if (near && near.k >= frame.k) {
                    chosen = centredAt(lowestClean(frame.k, Math.max(top, near.k), mid(near)), mid(near));
                } else {
                    chosen = centredAt(lowestClean(frame.k, top, mid(frame)), mid(frame));
                }
                if (head && chosen) {
                    /* Whatever else had to go off the stage, the click stays
                     * on it: its whole box, drawn grown, clear of the
                     * controls along the top and of the edges. Its centre
                     * alone was not enough: a company with its programmes
                     * in rows below it sits at the top of its own cluster,
                     * and on a phone was framed with its name half under
                     * the Key and cut at the right. Names keep their size
                     * whatever the zoom, so the box is in screen pixels.
                     *
                     * Nudged, not re-centred. On a phone the drawer is a
                     * sheet over the lower half of the stage, which the
                     * frame does not know about, so a click put at the
                     * stage's middle is under the sheet; where the frame
                     * put it, near the top, it is in view. The view moves
                     * the least it can and is then checked again for
                     * dropped names around its new centre. */
                    var hp = at(head);
                    var hbox = renderer.labelBox ? renderer.labelBox(head) : { width: head.r * 2, height: head.r * 2 };
                    var hw = hbox.width * HEAD_GROW / 2;
                    var hh = hbox.height * HEAD_GROW / 2;
                    var hx = hp.x * chosen.k + chosen.x;
                    var hy = hp.y * chosen.k + chosen.y;
                    var dx = 0, dy = 0;
                    if (hx - hw < STAGE_EDGE) dx = STAGE_EDGE - (hx - hw);
                    else if (hx + hw > renderer.width - STAGE_EDGE) dx = (renderer.width - STAGE_EDGE) - (hx + hw);
                    if (hy - hh < STAGE_TOP) dy = STAGE_TOP - (hy - hh);
                    else if (hy + hh > renderer.height - STAGE_EDGE) dy = (renderer.height - STAGE_EDGE) - (hy + hh);
                    if (dx || dy) {
                        var moved = { k: chosen.k, x: chosen.x + dx, y: chosen.y + dy };
                        chosen = centredAt(lowestClean(moved.k, Math.max(top, moved.k), mid(moved)), mid(moved));
                    }
                }
            }

            if (chosen) {
                viewport.setTransform(chosen.k, chosen.x, chosen.y);
            } else if (mode === 'expand' && chain.length > 1 && frame && near && near.k >= frame.k) {
                /* Expand keeps every earlier click on the board, so framing
                 * all of it leaves the newest - the one the visitor is
                 * looking for - small in a corner. Frame what was just
                 * clicked and its own connections instead, and leave the rest
                 * of the board a pan away. */
                viewport.setTransform(near.k, near.x, near.y);
            } else {
                viewport.fit(scene.nodes, padding);
            }
            viewport.rebuildTree();
            viewport.scheduleDraw();
        }

        /**
         * The lowest zoom at which the rows of this layout still leave room
         * for the names between them, or 0 when there is only one row. The
         * pitch is measured off the placed positions rather than the packer's
         * intentions, so it is the spacing actually on screen.
         */
        function legibleZoom(scene, positions) {
            /* The renderer measures the names; asking it rather than
             * estimating keeps the two from drifting apart. */
            var boxOf = renderer.labelBox
                ? function (node) { return renderer.labelBox(node); }
                : function (node) {
                    return {
                        width: labelWidth(node) + 10,
                        height: LABEL_ROOM + subLineCount({ node: node, years: !!node.years }) * 11
                    };
                };
            var floor = 0;

            var rows = Object.create(null);
            scene.nodes.forEach(function (node) {
                var p = positions[node.id];
                if (!p) return;
                var y = Math.round(p.y);
                (rows[y] = rows[y] || []).push({ node: node, x: p.x, box: boxOf(node) });
            });
            var keys = Object.keys(rows).map(Number).sort(function (a, b) { return a - b; });

            /* Down the page: a row's labels hang below its nodes, so the gap
             * to the next row has to hold the tallest label in the row. */
            for (var i = 1; i < keys.length; i++) {
                var gap = keys[i] - keys[i - 1];
                if (gap <= 0) continue;
                var tallest = rows[keys[i - 1]].reduce(function (t, e) { return Math.max(t, e.box.height); }, 0);
                floor = Math.max(floor, tallest / gap);
            }

            /* Across a row: zooming out moves nodes together while names keep
             * their size, so two neighbours need half of each name between
             * them. On a narrow stage this is what binds. */
            keys.forEach(function (key) {
                var row = rows[key].slice().sort(function (a, b) { return a.x - b.x; });
                for (var j = 1; j < row.length; j++) {
                    var dx = row[j].x - row[j - 1].x;
                    if (dx <= 0) continue;
                    floor = Math.max(floor, (row[j - 1].box.width + row[j].box.width) / 2 / dx);
                }
            });

            /* Never above 1: a block that fits is framed, not blown up. */
            return Math.min(1, floor);
        }

        /** What one name takes across, in the units the packer measured it in. */
        function labelWidth(node) {
            return Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH + BUBBLE_EXTRA);
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
            stopYoyo();
            if (gatherFrame) root.cancelAnimationFrame(gatherFrame);
        };

        return focus;
    }

    root.KOPNetworkFocus = { create: create, GATHER: GATHER };
})(typeof self !== 'undefined' ? self : this);
