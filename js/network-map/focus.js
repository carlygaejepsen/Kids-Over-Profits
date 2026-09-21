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
    var LABEL_HALF_PER_CHAR = LABEL_CHAR_WIDTH / 2;
    /* Air either side of a name that stands between two places, in the
     * gap those two already leave. */
    var STAND_BETWEEN_AIR = 8;
    /* Clear space between one column's names and the next. */
    var COLUMN_GUTTER = 26;
    /* The bands, top to bottom. */
    var TIER_COMPANY = 0;
    var TIER_COMMAND = 1;
    var TIER_PROGRAMME = 2;
    var TIER_STAFF = 3;
    var TIER_OTHER = 4;

    /* Clear space between one row's names and the next. Tight, because
     * rows are what a tall map spends its height on and every row that does
     * not fit is a name that does not appear. */
    var ROW_GUTTER = 16;
    /* A view this small is laid out as one tree rather than split into
     * clusters. */
    var SMALL_VIEW = 20;
    /* What it costs, when fitting clusters together, to draw something
     * level with or above a thing the records put it under. For a person,
     * about one long line: people join clusters, so nearly every place a
     * cluster could go breaks one of these somewhere, and as a rule it lined
     * every cluster up in the same rows, one board-width strip. Ownership
     * and renames between organisations are what the map is for and far
     * rarer, so breaking one costs as much as a stage of line. */
    var HIERARCHY_COST = 400;
    var OWNERSHIP_COST = 3000;
    /* Height of the label that hangs under a node. */
    var LABEL_ROOM = 18;

    /**
     * How much room a node needs to itself: enough for its own shape, and
     * enough for its name, which is drawn centred underneath and is almost
     * always the wider of the two. Settling on radius alone packs the shapes
     * neatly and leaves the labels on top of each other.
     */
    function spaceFor(node) {
        var label = String(node.name || '').length * LABEL_HALF_PER_CHAR + BUBBLE_EXTRA / 2;
        return Math.max(node.r + 8, label);
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
            if (!chain.length) return [];
            return mode === 'focus' ? [chain[chain.length - 1]] : chain.slice();
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
        focus.hoverEdge = function (edge) {
            if (edge && yoyo) return false;
            /* Called on every move along a line; only a change repaints. */
            if (edge && hoverEdgeIds && hoverEdgeIds[edge.id]) return true;
            var next = null;
            if (edge) {
                next = Object.create(null);
                next[edge.id] = true;
                focus.linesBetween(edge.sourceId, edge.targetId).forEach(function (e) { next[e.id] = true; });
            }
            if (!next && !hoverEdgeIds) return true;
            hoverEdgeIds = next;
            applyEmphasis();
            viewport.scheduleDraw();
            return true;
        };

        function applyEmphasis() {
            renderer.setEmphasis({
                hoverId: hoverId,
                hoverEdges: hoverEdgeIds,
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
                    /* Carried onto the simulation node so the collision force
                     * and the grid agree about how much room this name
                     * takes. */
                    space: spaceFor(node),
                    label: Math.max(node.r * 2, String(node.name || '').length * LABEL_CHAR_WIDTH + BUBBLE_EXTRA) * baseGrow,
                    tier: tierOf(node),
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

            if (d3 && d3.forceSimulation && points.length > 1) {
                var sim = d3.forceSimulation(points)
                    .force('link', d3.forceLink(links)
                        .id(function (d) { return d.id; })
                        .distance(function (l) { return LINK_DISTANCE[l.category] || DEFAULT_DISTANCE; }))
                    .force('charge', d3.forceManyBody().strength(function (d) {
                        return -120 * (d.r / 8);
                    }))
                    .force('collide', d3.forceCollide().radius(function (d) {
                        return d.space;
                    }).iterations(3))
                    /* Pull to the origin, not to a centre force: forceCenter
                     * shifts the whole cloud every tick and leaves a chain of
                     * two clusters drifting. */
                    .force('x', d3.forceX(0).strength(0.06))
                    .force('y', d3.forceY(0).strength(0.06))
                    .stop();
                for (var i = 0; i < SETTLE_TICKS; i++) sim.tick();
                sim.stop();
            }

            var overhang = boardLayout(points, gridPadding, currentRoots(), links);

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

        /* ---------------------------------------------------- the board -- */

        /** The two ids at the ends of a link. d3.forceLink swaps them for
         * node objects on its way past, so read either. */
        function endsOf(link) {
            var a = link.source && link.source.id !== undefined ? link.source.id : link.source;
            var b = link.target && link.target.id !== undefined ? link.target.id : link.target;
            return [a, b];
        }

        /**
         * Which band of the map a node belongs in, top to bottom: companies,
         * the people who ran things, programmes, everyone else who worked
         * there, and the bodies around the edges of the industry.
         *
         * "Ran things" is read off the connections rather than the person:
         * somebody with a leadership, board or ownership edge was running
         * something, whatever their title. Of the 335 people on the board,
         * 230 have one.
         */
        function tierOf(node) {
            if (node.kind === 'parent' || node.kind === 'association') return TIER_COMPANY;
            if (node.kind === 'person') {
                var by = node.degreeByCategory || {};
                var runs = (by.leadership || 0) + (by.board || 0) + (by.corporate || 0);
                return runs > 0 ? TIER_COMMAND : TIER_STAFF;
            }
            if (node.kind === 'facility') return TIER_PROGRAMME;
            return TIER_OTHER;
        }

        /**
         * Which end of a connection is drawn above the other, as [upper,
         * lower], or null where the connection says nothing about it.
         *
         * Ownership and command are what this map is for, so they read
         * downwards the way the owner's board does: a company above what it
         * owns, a programme above what it was renamed to, the people who ran
         * a programme above it and the people who worked there below it. A
         * company is above its staff, but a founder or owner is above what
         * they founded, which is what makes AA, Dederich and Synanon one
         * line down the page. A member is below the body they belonged to.
         * Family, referral and the rest are sideways connections, and the
         * people they join sit level with each other.
         */
        function aboveOf(link, byId) {
            var ends = endsOf(link);
            var a = byId[ends[0]];
            var b = byId[ends[1]];
            if (!a || !b || a === b) return null;
            if (link.above && byId[link.above[0]] && byId[link.above[1]]) return link.above;
            if (link.category === 'corporate' && link.direction === 'renamed') return [a.id, b.id];

            var company = function (p) { return p.tier === TIER_COMPANY; };
            var person = function (p) { return p.tier === TIER_COMMAND || p.tier === TIER_STAFF; };
            if (person(a) === person(b)) {
                /* Source owns target, as the board records it. */
                if (link.category !== 'corporate' || person(a)) return null;
                if (company(a) === company(b)) return company(a) ? [a.id, b.id] : null;
                return company(a) ? [a.id, b.id] : [b.id, a.id];
            }
            var who = person(a) ? a : b;
            var body = person(a) ? b : a;
            if (link.category === 'membership') return [body.id, who.id];
            if (body.tier === TIER_OTHER) return null;
            var runs = link.category === 'leadership' || link.category === 'board' ||
                link.category === 'corporate';
            if (company(body)) {
                var founded = (link.roles || []).some(function (role) { return /found|owner/i.test(role); });
                return runs && founded ? [who.id, body.id] : [body.id, who.id];
            }
            return runs ? [who.id, body.id] : [body.id, who.id];
        }

        /**
         * A level for every node, top to bottom, from the connections that
         * say which way up they go.
         *
         * Organisations first, from what joins them to each other: ownership,
         * renames, and lineage through a person (a body someone belonged to
         * above what they went on to found, two levels apart so the person
         * fits between). Longest path from the top, so everything sits at
         * least a level under whatever it is under; then anything with
         * nothing above it drops to just over what it sits on, so a lone
         * owner is not stranded levels above its one programme. A cycle in
         * the records - the board has a couple - loses the connection that
         * would close it, ownership and renames winning.
         *
         * People second, each one hung a level above what they ran or a
         * level below where they worked. People do not push organisations
         * apart: a staffer at one programme who ran another put the second a
         * level under the first, and with a few of those in a row a cluster
         * of twenty-four names came out ten rows deep. Where a person's
         * places disagree, the median wins.
         *
         * Anything the directions say nothing about sits level with what it
         * connects to. Returns the levels, and which node is above which
         * wherever the levels honour it, for the packer to keep between
         * clusters.
         */
        function levelsOf(points, links, byId, adjacent) {
            var isPerson = function (p) { return p.tier === TIER_COMMAND || p.tier === TIER_STAFF; };
            var orgs = points.filter(function (p) { return !isPerson(p); });

            var pairs = [];
            var hung = [];
            var memberOf = Object.create(null);
            var founded = Object.create(null);
            links.forEach(function (link) {
                var pair = aboveOf(link, byId);
                if (!pair) return;
                var upper = byId[pair[0]];
                var lower = byId[pair[1]];
                if (!isPerson(upper) && !isPerson(lower)) {
                    pairs.push({ u: upper.id, v: lower.id, gap: 1, first: link.category === 'corporate' ? 0 : 1 });
                    return;
                }
                if (isPerson(upper) === isPerson(lower)) return;
                var who = isPerson(upper) ? upper.id : lower.id;
                var org = isPerson(upper) ? lower.id : upper.id;
                hung.push({ who: who, org: org, orgAbove: isPerson(lower) });
                if (link.category === 'membership') (memberOf[who] = memberOf[who] || []).push(org);
                if (isPerson(upper) && (link.roles || []).some(function (role) { return /found/i.test(role); })) {
                    (founded[who] = founded[who] || []).push(org);
                }
            });
            Object.keys(memberOf).forEach(function (who) {
                memberOf[who].forEach(function (body) {
                    (founded[who] || []).forEach(function (org) {
                        if (body !== org) pairs.push({ u: body, v: org, gap: 2, first: 1 });
                    });
                });
            });
            pairs.sort(function (x, y) { return x.first - y.first; });

            var down = Object.create(null);
            var up = Object.create(null);
            var gap = Object.create(null);
            orgs.forEach(function (p) { down[p.id] = []; up[p.id] = []; });
            var reaches = function (from, to) {
                var seen = Object.create(null);
                var stack = [from];
                while (stack.length) {
                    var at = stack.pop();
                    if (at === to) return true;
                    if (seen[at]) continue;
                    seen[at] = true;
                    Array.prototype.push.apply(stack, down[at]);
                }
                return false;
            };
            pairs.forEach(function (entry) {
                if (!down[entry.u] || !down[entry.v]) return;
                if (down[entry.u].indexOf(entry.v) !== -1 || reaches(entry.v, entry.u)) return;
                down[entry.u].push(entry.v);
                up[entry.v].push(entry.u);
                gap[entry.u + '>' + entry.v] = entry.gap;
            });

            /* Topological order, Kahn's way, in the order the points came. */
            var waiting = Object.create(null);
            var order = [];
            orgs.forEach(function (p) { waiting[p.id] = up[p.id].length; });
            var ready = orgs.filter(function (p) { return !waiting[p.id]; }).map(function (p) { return p.id; });
            while (ready.length) {
                var id = ready.shift();
                order.push(id);
                down[id].forEach(function (v) {
                    if (--waiting[v] === 0) ready.push(v);
                });
            }

            var level = Object.create(null);
            order.forEach(function (id) {
                level[id] = up[id].reduce(function (t, u) { return Math.max(t, level[u] + gap[u + '>' + id]); }, 0);
            });
            order.slice().reverse().forEach(function (id) {
                if (up[id].length || !down[id].length) return;
                level[id] = down[id].reduce(function (t, v) { return Math.min(t, level[v] - gap[id + '>' + v]); }, Infinity);
            });

            var placed = Object.create(null);
            order.forEach(function (id) { if (up[id].length || down[id].length) placed[id] = true; });
            var median = function (values) {
                values.sort(function (x, y) { return x - y; });
                return values[Math.floor((values.length - 1) / 2)];
            };

            /* An organisation with nothing to order it against another sits
             * level with the organisations it shares people with. */
            var freeOrgs = orgs.filter(function (p) { return !placed[p.id]; });
            for (var round = 0; round < 4; round++) {
                freeOrgs.forEach(function (p) {
                    var near = [];
                    adjacent[p.id].forEach(function (o) {
                        if (placed[o] && !isPerson(byId[o])) near.push(level[o]);
                        if (!isPerson(byId[o])) return;
                        adjacent[o].forEach(function (o2) {
                            if (o2 !== p.id && placed[o2] && !isPerson(byId[o2])) near.push(level[o2]);
                        });
                    });
                    if (!near.length) return;
                    level[p.id] = median(near);
                    placed[p.id] = true;
                });
            }
            freeOrgs.forEach(function (p) {
                if (!placed[p.id]) { level[p.id] = 0; placed[p.id] = true; }
            });

            /* People, hung off their organisations. */
            var wants = Object.create(null);
            var floor = Object.create(null);
            var ceiling = Object.create(null);
            hung.forEach(function (h) {
                var at = level[h.org] + (h.orgAbove ? 1 : -1);
                (wants[h.who] = wants[h.who] || []).push(at);
                if (h.orgAbove) floor[h.who] = Math.max(floor[h.who] === undefined ? -Infinity : floor[h.who], at);
                else ceiling[h.who] = Math.min(ceiling[h.who] === undefined ? Infinity : ceiling[h.who], at);
            });
            Object.keys(wants).forEach(function (who) {
                var want = median(wants[who]);
                var lo = floor[who] === undefined ? -Infinity : floor[who];
                var hi = ceiling[who] === undefined ? Infinity : ceiling[who];
                level[who] = lo <= hi ? Math.min(hi, Math.max(lo, want)) : want;
                placed[who] = true;
            });

            /* Everyone else - family, a partner - level with who they know. */
            var rest = points.filter(function (p) { return !placed[p.id]; });
            for (var pass = 0; pass < 4; pass++) {
                rest.forEach(function (p) {
                    var near = adjacent[p.id].filter(function (o) { return placed[o]; })
                        .map(function (o) { return level[o]; });
                    if (!near.length) return;
                    level[p.id] = median(near);
                    placed[p.id] = true;
                });
            }
            rest.forEach(function (p) { if (!placed[p.id]) level[p.id] = 0; });

            /* Which is above which, wherever the levels honour it, and what
             * breaking it between clusters would cost. */
            var above = Object.create(null);
            points.forEach(function (p) { above[p.id] = []; });
            links.forEach(function (link) {
                var pair = aboveOf(link, byId);
                if (!pair || level[pair[0]] >= level[pair[1]]) return;
                var people = isPerson(byId[pair[0]]) || isPerson(byId[pair[1]]);
                above[pair[0]].push({ id: pair[1], cost: people ? HIERARCHY_COST : OWNERSHIP_COST });
            });
            return { level: level, down: above };
        }

        /**
         * Split the view into clusters of names that belong together, the
         * way the owner's board is drawn: a family of programmes with the
         * people who ran them, not every company in one strip across the top
         * and every programme in another along the bottom.
         *
         * Greedy modularity (the first stage of Louvain, then once more on
         * the clusters it found), in a fixed order so the same view always
         * splits the same way. A cluster stops growing at a size cap, which
         * is what keeps a hub's view from being one cluster of everything.
         * Names with a single connection are left out of the vote and follow
         * the name they hang off: they say nothing about structure, and a
         * hub's thirty of them would otherwise fill its cluster to the cap.
         *
         * A small view is not split at all: twenty names are one tree, and
         * cutting it put Lester Roloff level with the company he founded.
         * Each connected piece of it is a cluster.
         */
        function clustersOf(points, adjacent, owns) {
            if (points.length <= SMALL_VIEW) {
                var piece = Object.create(null);
                points.forEach(function (p) {
                    if (piece[p.id] !== undefined) return;
                    if (!adjacent[p.id].length) { piece[p.id] = '(loose)'; return; }
                    var stack = [p.id];
                    while (stack.length) {
                        var at = stack.pop();
                        if (piece[at] !== undefined) continue;
                        piece[at] = p.id;
                        Array.prototype.push.apply(stack, adjacent[at]);
                    }
                });
                return piece;
            }
            var degree = function (id) { return adjacent[id].length; };
            var leaf = Object.create(null);
            points.forEach(function (p) { if (degree(p.id) === 1) leaf[p.id] = true; });
            var core = points.filter(function (p) { return degree(p.id) > 1; })
                .sort(function (a, b) { return degree(b.id) - degree(a.id) || (a.id < b.id ? -1 : 1); });
            var cap = Math.max(8, Math.min(26, Math.ceil(core.length / 2.5)));

            /* One pass of local moving over a weighted graph of units. */
            var move = function (units, weight, size) {
                var of = Object.create(null);
                var tot = Object.create(null);
                var count = Object.create(null);
                var total = 0;
                var strength = Object.create(null);
                units.forEach(function (u) {
                    var s = 0;
                    Object.keys(weight[u]).forEach(function (v) { s += weight[u][v]; });
                    strength[u] = s;
                    total += s;
                    of[u] = u;
                    tot[u] = s;
                    count[u] = size[u];
                });
                if (!total) return of;
                for (var pass = 0; pass < 12; pass++) {
                    var moved = false;
                    units.forEach(function (u) {
                        var k = strength[u];
                        if (!k) return;
                        var own = of[u];
                        var into = Object.create(null);
                        Object.keys(weight[u]).forEach(function (v) {
                            if (v === u) return;
                            into[of[v]] = (into[of[v]] || 0) + weight[u][v];
                        });
                        tot[own] -= k;
                        count[own] -= size[u];
                        var best = own;
                        var bestGain = (into[own] || 0) - tot[own] * k / total;
                        Object.keys(into).forEach(function (c) {
                            if (c === own || count[c] + size[u] > cap) return;
                            var gain = into[c] - tot[c] * k / total;
                            if (gain > bestGain + 1e-9) { best = c; bestGain = gain; }
                        });
                        of[u] = best;
                        tot[best] += k;
                        count[best] += size[u];
                        if (best !== own) moved = true;
                    });
                    if (!moved) break;
                }
                return of;
            };

            var coreIds = core.map(function (p) { return p.id; });
            var isCore = Object.create(null);
            coreIds.forEach(function (id) { isCore[id] = true; });
            var weight = Object.create(null);
            var size = Object.create(null);
            coreIds.forEach(function (id) {
                weight[id] = Object.create(null);
                size[id] = 1;
                adjacent[id].forEach(function (o) {
                    /* Ownership counts three times over: inside a cluster the
                     * tree draws an owner above what it owns without fail,
                     * and between clusters only as far as the packing allows. */
                    if (isCore[o]) weight[id][o] = (weight[id][o] || 0) + (owns[id + '>' + o] ? 3 : 1);
                });
            });
            var of = move(coreIds, weight, size);

            /* Once more with each cluster as a unit, so small clusters that
             * belong together merge. */
            var units = [];
            var seenUnit = Object.create(null);
            coreIds.forEach(function (id) {
                if (!seenUnit[of[id]]) { seenUnit[of[id]] = true; units.push(of[id]); }
            });
            var uWeight = Object.create(null);
            var uSize = Object.create(null);
            units.forEach(function (u) { uWeight[u] = Object.create(null); uSize[u] = 0; });
            coreIds.forEach(function (id) {
                uSize[of[id]]++;
                Object.keys(weight[id]).forEach(function (o) {
                    if (of[o] === of[id]) return;
                    uWeight[of[id]][of[o]] = (uWeight[of[id]][of[o]] || 0) + weight[id][o];
                });
            });
            var merged = move(units, uWeight, uSize);

            var cluster = Object.create(null);
            coreIds.forEach(function (id) { cluster[id] = merged[of[id]]; });
            points.forEach(function (p) {
                if (!leaf[p.id]) return;
                var other = adjacent[p.id][0];
                /* Two names joined only to each other are a cluster of two. */
                cluster[p.id] = leaf[other] ? (p.id < other ? p.id : other) : cluster[other];
            });
            /* Names with no line on screen share one cluster, packed as a
             * block of their own. */
            points.forEach(function (p) { if (cluster[p.id] === undefined) cluster[p.id] = '(loose)'; });
            return cluster;
        }

        /**
         * One cluster as a small tree: its levels top to bottom, each level
         * ordered to keep lines from crossing, a level too wide for the
         * cluster wrapped under itself, and each name slid along its row
         * towards the names it connects to.
         *
         * Rows are packed by the width each name actually needs, so every
         * name gets exactly its own room. Returns the rows with each point's
         * offset from the block's left edge in p.bx.
         */
        function layoutBlock(members, level, adjacent, needOf, maxWidth, aspect, rowH) {
            var inBlock = Object.create(null);
            members.forEach(function (p) { inBlock[p.id] = p; });
            var near = function (p) {
                return adjacent[p.id].filter(function (o) { return inBlock[o]; });
            };

            var byLevel = Object.create(null);
            members.forEach(function (p) {
                (byLevel[level[p.id]] = byLevel[level[p.id]] || []).push(p);
            });
            var keys = Object.keys(byLevel).map(Number).sort(function (a, b) { return a - b; });
            var layers = keys.map(function (key) {
                /* The settle's left to right is the first guess at an order. */
                return byLevel[key].sort(function (a, b) { return a.x - b.x || (a.id < b.id ? -1 : 1); });
            });

            /* Barycentre sweeps: each layer sorted by where its connections
             * sit in the layers already swept, down then up. */
            var pos = Object.create(null);
            var index = function (layer) {
                layer.forEach(function (p, i) { pos[p.id] = (i + 0.5) / layer.length; });
            };
            var layerOf = Object.create(null);
            layers.forEach(function (layer, li) {
                index(layer);
                layer.forEach(function (p) { layerOf[p.id] = li; });
            });
            var sweep = function (li, from) {
                var layer = layers[li];
                var want = Object.create(null);
                layer.forEach(function (p) {
                    var xs = near(p).filter(function (o) { return from(layerOf[o]); })
                        .map(function (o) { return pos[o]; });
                    want[p.id] = xs.length ? xs.reduce(function (t, x) { return t + x; }, 0) / xs.length : pos[p.id];
                });
                layer.sort(function (a, b) { return want[a.id] - want[b.id] || pos[a.id] - pos[b.id]; });
                index(layer);
            };
            for (var it = 0; it < 6; it++) {
                var li;
                for (li = 1; li < layers.length; li++) {
                    sweep(li, function (l) { return l < li; });
                }
                for (li = layers.length - 2; li >= 0; li--) {
                    sweep(li, function (l) { return l > li; });
                }
            }

            /* How wide the cluster may be before a level wraps: near the
             * shape the stage wants, never narrower than its widest name. */
            var total = members.reduce(function (t, p) { return t + needOf(p); }, 0);
            var widest = members.reduce(function (t, p) { return Math.max(t, needOf(p)); }, 0);
            var limit = Math.max(widest, Math.min(maxWidth, Math.sqrt(total * rowH * aspect)));

            var rows = [];
            layers.forEach(function (layer) {
                var width = layer.reduce(function (t, p) { return t + needOf(p); }, 0);
                var parts = Math.max(1, Math.ceil(width / limit));
                var share = width / parts;
                var row = [];
                var used = 0;
                layer.forEach(function (p) {
                    if (row.length && used + needOf(p) / 2 > share && parts > 1) {
                        rows.push(row);
                        row = [];
                        used = 0;
                        parts--;
                    }
                    row.push(p);
                    used += needOf(p);
                });
                if (row.length) rows.push(row);
            });

            /* Across: pack each row, then slide names towards the mean of
             * what they connect to in the other rows, a few passes each way.
             * The order within a row is kept, and a row that has to spread
             * is shifted so it stays centred on what it wants. Nothing
             * slides outside a frame a little wider than the widest row:
             * left free, the sliding stretched a sixty-name view to twice
             * the stage and the fit had to zoom out past the point where
             * names clear each other. */
            var x = Object.create(null);
            var frame = 0;
            rows.forEach(function (row) {
                var width = row.reduce(function (t, p) { return t + needOf(p); }, 0);
                frame = Math.max(frame, width);
                var at = -width / 2;
                row.forEach(function (p) { x[p.id] = at + needOf(p) / 2; at += needOf(p); });
            });
            var half = frame * 1.1 / 2;
            var rowOf = Object.create(null);
            rows.forEach(function (row, ri) { row.forEach(function (p) { rowOf[p.id] = ri; }); });
            var align = function (row) {
                var want = row.map(function (p) {
                    var xs = near(p).filter(function (o) { return rowOf[o] !== rowOf[p.id]; })
                        .map(function (o) { return x[o]; });
                    return xs.length ? xs.reduce(function (t, v) { return t + v; }, 0) / xs.length : x[p.id];
                });
                var placed = [];
                row.forEach(function (p, i) {
                    var min = i ? placed[i - 1] + (needOf(row[i - 1]) + needOf(p)) / 2 : -Infinity;
                    placed.push(Math.max(want[i], min));
                });
                var drift = 0;
                placed.forEach(function (v, i) { drift += v - want[i]; });
                drift /= row.length;
                var i;
                for (i = row.length - 1; i >= 0; i--) {
                    var ceiling = i < row.length - 1
                        ? placed[i + 1] - (needOf(row[i]) + needOf(row[i + 1])) / 2
                        : half - needOf(row[i]) / 2;
                    placed[i] = Math.min(placed[i] - drift, ceiling);
                }
                for (i = 0; i < row.length; i++) {
                    var floor = i ? placed[i - 1] + (needOf(row[i - 1]) + needOf(row[i])) / 2
                        : -half + needOf(row[i]) / 2;
                    placed[i] = Math.max(placed[i], floor);
                }
                row.forEach(function (p, j) { x[p.id] = placed[j]; });
            };
            for (var pass = 0; pass < 4; pass++) {
                var ri;
                for (ri = 1; ri < rows.length; ri++) align(rows[ri]);
                for (ri = rows.length - 2; ri >= 0; ri--) align(rows[ri]);
            }

            var left = Infinity;
            var right = -Infinity;
            members.forEach(function (p) {
                left = Math.min(left, x[p.id] - needOf(p) / 2);
                right = Math.max(right, x[p.id] + needOf(p) / 2);
            });
            /* Each row's own extent too, so the packer can tuck another
             * cluster in beside a short row rather than keeping clear of the
             * whole rectangle. */
            var outline = rows.map(function (row) {
                row.forEach(function (p) { p.bx = x[p.id] - left; });
                return [row[0].bx - needOf(row[0]) / 2, row[row.length - 1].bx + needOf(row[row.length - 1]) / 2];
            });
            rows.forEach(function (row, ri) {
                row.forEach(function (p) { p.brow = ri; });
            });
            return { members: members, rows: rows.length, w: right - left, outline: outline };
        }

        /**
         * Fit the clusters together into one board the shape of the stage.
         *
         * The biggest cluster, or the one holding what was clicked, goes
         * down first. Each one after it is the unplaced cluster with most
         * connections to what is already down, and it goes wherever keeps
         * its lines shortest and the board nearest the stage's shape. A
         * place that would draw an owner below what it owns, or a leader
         * below their programme, costs far more than a long line, so the
         * hierarchy holds between clusters as well as inside them.
         *
         * Clusters are fitted row by row, not as rectangles: a tree is
         * narrow at the top and wide at the bottom, and keeping clear of its
         * whole bounding box left the corners of the board empty. Another
         * cluster can sit beside a short row, as it would on a board drawn
         * by hand, with a double gutter between the two.
         *
         * Everything sits on one lattice of rows, clusters included, which
         * is what lets the router run its traces in the gutters between
         * them.
         */
        function packBlocks(blocks, links, down, rowH, aspect) {
            var GAP_X = COLUMN_GUTTER * 2;
            var blockOf = Object.create(null);
            var member = Object.create(null);
            blocks.forEach(function (block, bi) {
                block.members.forEach(function (p) { blockOf[p.id] = bi; member[p.id] = p; });
            });

            var between = blocks.map(function () { return []; });
            links.forEach(function (link) {
                var ends = endsOf(link);
                var a = blockOf[ends[0]];
                var b = blockOf[ends[1]];
                if (a === undefined || b === undefined || a === b) return;
                between[a].push([ends[0], ends[1]]);
                between[b].push([ends[1], ends[0]]);
            });
            var aboveAcross = blocks.map(function () { return []; });
            Object.keys(down).forEach(function (u) {
                down[u].forEach(function (below) {
                    var v = below.id;
                    if (blockOf[u] === undefined || blockOf[v] === undefined || blockOf[u] === blockOf[v]) return;
                    aboveAcross[blockOf[u]].push([u, v, below.cost]);
                    aboveAcross[blockOf[v]].push([u, v, below.cost]);
                });
            });

            var isPlaced = Object.create(null);
            var placedCount = 0;
            var gx = function (id) { return blocks[blockOf[id]].x + member[id].bx; };
            var gy = function (id) { return blocks[blockOf[id]].y + member[id].brow; };
            /* What is already taken, as spans of x in each row. */
            var taken = Object.create(null);
            var bounds = null;

            var extent = function (b, x, y) {
                var box = { x0: Infinity, x1: -Infinity, y0: y, y1: y + b.rows };
                b.outline.forEach(function (span) {
                    box.x0 = Math.min(box.x0, x + span[0]);
                    box.x1 = Math.max(box.x1, x + span[1]);
                });
                return box;
            };

            var cost = function (bi, x, y) {
                var b = blocks[bi];
                b.x = x;
                b.y = y;
                var c = 0;
                between[bi].forEach(function (pair) {
                    if (!isPlaced[blockOf[pair[1]]]) return;
                    c += Math.abs(gx(pair[0]) - gx(pair[1])) + Math.abs(gy(pair[0]) - gy(pair[1])) * rowH;
                });
                aboveAcross[bi].forEach(function (pair) {
                    var other = blockOf[pair[0]] === bi ? pair[1] : pair[0];
                    if (!isPlaced[blockOf[other]]) return;
                    if (gy(pair[0]) >= gy(pair[1])) c += pair[2];
                });
                var box = extent(b, x, y);
                var w = Math.max(bounds.x1, box.x1) - Math.min(bounds.x0, box.x0);
                var h = (Math.max(bounds.y1, box.y1) - Math.min(bounds.y0, box.y0)) * rowH;
                return c + 4 * Math.max(w, h * aspect);
            };

            /* The places along row y where the block fits, nearest to the
             * x it would like first: that x if it is free, otherwise the
             * nearest free place either side of it. */
            var fitsAt = function (b, y, want) {
                var bans = [];
                b.outline.forEach(function (span, r) {
                    (taken[y + r] || []).forEach(function (used) {
                        bans.push([used[0] - GAP_X - span[1], used[1] + GAP_X - span[0]]);
                    });
                });
                var free = function (x) {
                    return bans.every(function (ban) { return x <= ban[0] + 0.01 || x >= ban[1] - 0.01; });
                };
                if (free(want)) return [want];
                var left = null;
                var right = null;
                bans.forEach(function (ban) {
                    ban.forEach(function (edge) {
                        if (!free(edge)) return;
                        if (edge <= want && (left === null || edge > left)) left = edge;
                        if (edge >= want && (right === null || edge < right)) right = edge;
                    });
                });
                return [left, right].filter(function (x) { return x !== null; });
            };

            var place = function (bi, x, y) {
                var b = blocks[bi];
                b.x = x;
                b.y = y;
                isPlaced[bi] = true;
                placedCount++;
                b.outline.forEach(function (span, r) {
                    (taken[y + r] = taken[y + r] || []).push([x + span[0], x + span[1]]);
                });
                var box = extent(b, x, y);
                if (!bounds) {
                    bounds = box;
                } else {
                    bounds.x0 = Math.min(bounds.x0, box.x0);
                    bounds.x1 = Math.max(bounds.x1, box.x1);
                    bounds.y0 = Math.min(bounds.y0, box.y0);
                    bounds.y1 = Math.max(bounds.y1, box.y1);
                }
            };

            var weight = function (b) { return (b.holdsRoot ? 1e6 : 0) + b.members.length; };
            var first = 0;
            blocks.forEach(function (b, bi) {
                if (weight(b) > weight(blocks[first])) first = bi;
            });
            place(first, 0, 0);

            while (placedCount < blocks.length) {
                var next = -1;
                var nextScore = -1;
                blocks.forEach(function (b, bi) {
                    if (isPlaced[bi]) return;
                    var tied = between[bi].filter(function (pair) { return isPlaced[blockOf[pair[1]]]; }).length;
                    var score = tied * 1000 + b.members.length;
                    if (score > nextScore) { nextScore = score; next = bi; }
                });
                var b = blocks[next];
                var ties = between[next].filter(function (pair) { return isPlaced[blockOf[pair[1]]]; });
                /* Where its lines would be shortest: the mean of where its
                 * connections already sit, less where they start in the
                 * block. Unconnected, the middle of the board. */
                var want = ties.length
                    ? ties.reduce(function (t, pair) { return t + gx(pair[1]) - member[pair[0]].bx; }, 0) / ties.length
                    : (bounds.x0 + bounds.x1 - b.w) / 2;
                var best = null;
                for (var y = bounds.y0 - b.rows; y <= bounds.y1; y++) {
                    fitsAt(b, y, want).forEach(function (x) {
                        var c = cost(next, x, y);
                        if (!best || c < best.c) best = { c: c, x: x, y: y };
                    });
                }
                if (!best) best = { x: bounds.x1 + GAP_X, y: bounds.y0 };
                place(next, best.x, best.y);
            }
            return bounds;
        }

        /**
         * The opening view's clusters side by side across the whole board,
         * with the room left over shared out evenly between and around them.
         *
         * The packer fits clusters together as tightly as their lines allow,
         * which is right for a view of eighty names and wrong for a handful
         * of organisations with nothing between them: it leaves them bunched
         * at one end of a stage that is otherwise empty sand. Only where the
         * clusters have no lines to each other and all fit in one row;
         * anything else keeps the packer's arrangement. Returns the new
         * bounds, or null where it does not apply.
         */
        function abreast(blocks, links, board) {
            if (blocks.length < 2) return null;
            var blockOf = Object.create(null);
            blocks.forEach(function (block, bi) {
                block.members.forEach(function (p) { blockOf[p.id] = bi; });
            });
            var joined = links.some(function (link) {
                var ends = endsOf(link);
                return blockOf[ends[0]] !== undefined && blockOf[ends[1]] !== undefined &&
                    blockOf[ends[0]] !== blockOf[ends[1]];
            });
            if (joined) return null;

            var spans = blocks.map(function (block) {
                var lo = Infinity;
                var hi = -Infinity;
                block.outline.forEach(function (span) {
                    lo = Math.min(lo, span[0]);
                    hi = Math.max(hi, span[1]);
                });
                return [lo, hi];
            });
            var taken = spans.reduce(function (t, span) { return t + span[1] - span[0]; }, 0);
            if (taken + COLUMN_GUTTER * 2 * blocks.length > board) return null;

            /* A whole share of the spare width between neighbours, half a
             * share outside the first and the last. */
            var gap = (board - taken) / blocks.length;
            var tallest = blocks.reduce(function (t, block) { return Math.max(t, block.rows); }, 1);
            var at = -board / 2 + gap / 2;
            blocks.forEach(function (block, bi) {
                block.x = at - spans[bi][0];
                block.y = Math.floor((tallest - block.rows) / 2);
                at += spans[bi][1] - spans[bi][0] + gap;
            });
            return { x0: -board / 2 + gap / 2, x1: board / 2 - gap / 2, y0: 0, y1: tallest };
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
            var rowH = tallest * 2 + LABEL_ROOM + ROW_GUTTER +
                (ordered.some(function (p) { return p.years; }) ? yearsLine : 0);

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
         * Lay the view out as a board: clusters of names that belong
         * together, each drawn as a small tree with ownership and command
         * reading downwards, fitted together to fill the stage.
         *
         * The rows of a single strip across the whole stage - every company
         * along the top, every programme along the bottom - put a person a
         * stage away from the programmes they worked at and strung lines
         * from one edge of the map to the other. Clusters keep what belongs
         * together near each other, so most lines are a row or two long.
         *
         * Built in screen pixels, centred on the origin, so the fit that
         * follows lands at a zoom of about one.
         */
        function boardLayout(points, padding, roots, links) {
            var n = points.length;
            grid = null;
            if (!n || !renderer.width) return 0;

            var board = Math.max(200, renderer.width - padding * 2);
            var boardH = Math.max(200, renderer.height - padding * 2);
            var aspect = board / boardH;
            /* What was clicked is drawn grown, and its rows share one
             * lattice with everything else, so every row leaves room for it:
             * sized for the others alone, its label hung down into the
             * gutter below and the traces running along it cut through. */
            var isHead = Object.create(null);
            roots.forEach(function (id) { isHead[id] = true; });
            var tallest = points.reduce(function (t, p) {
                return Math.max(t, p.r * (isHead[p.id] ? HEAD_GROW : baseGrow));
            }, 0);
            var needOf = function (p) { return p.label + COLUMN_GUTTER; };

            var byId = Object.create(null);
            var adjacent = Object.create(null);
            points.forEach(function (p) { byId[p.id] = p; adjacent[p.id] = []; });
            links.forEach(function (link) {
                var ends = endsOf(link);
                if (!byId[ends[0]] || !byId[ends[1]] || ends[0] === ends[1]) return;
                if (adjacent[ends[0]].indexOf(ends[1]) === -1) adjacent[ends[0]].push(ends[1]);
                if (adjacent[ends[1]].indexOf(ends[0]) === -1) adjacent[ends[1]].push(ends[0]);
            });

            /* A name with years under it is a line taller; give every row
             * that room when any name on the board has them, so the rows
             * stay even. */
            var yearsLine = (root.KOPNetworkCanvas && root.KOPNetworkCanvas.YEARS_LINE) || 11;
            var labelRoom = LABEL_ROOM + (points.some(function (p) { return p.years; }) ? yearsLine : 0);
            var rowH = tallest * 2 + labelRoom + ROW_GUTTER;

            var levels = levelsOf(points, links, byId, adjacent);
            var owns = Object.create(null);
            links.forEach(function (link) {
                if (link.category !== 'corporate') return;
                var ends = endsOf(link);
                owns[ends[0] + '>' + ends[1]] = owns[ends[1] + '>' + ends[0]] = true;
            });
            var cluster = clustersOf(points, adjacent, owns);
            /* Places joined to the click only through somebody who worked at
             * both. They are connections, but not the click's own household:
             * see below. */
            var throughPeople = Object.create(null);
            links.forEach(function (link) {
                if (link.category !== 'people') return;
                var ends = endsOf(link);
                throughPeople[ends[0] + '>' + ends[1]] = throughPeople[ends[1] + '>' + ends[0]] = true;
            });

            var isRoot = Object.create(null);
            roots.forEach(function (id) { isRoot[id] = true; });
            /* What was clicked keeps everything it connects to in its own
             * cluster: its connections drawn close around it, which is what
             * a click is asking to see, and its owners and holdings in the
             * one place the tree puts an owner above what it owns without
             * fail. Opening CEDU put The Brown Schools, which bought it, in a
             * neighbouring cluster level with a school CEDU owned, and no
             * place for that cluster could honour both.
             *
             * Not the places joined to it only through a folded person.
             * Provo Canyon School has fifty of those, and pulled into its
             * cluster each one left its own owner behind in another: The
             * Brown Schools came out level with San Marcos Treatment Center,
             * which it owned. They cluster with whatever else they belong
             * to, as they did when the person stood between the two. */
            roots.forEach(function (id) {
                if (!byId[id] || cluster[id] === undefined) return;
                adjacent[id].forEach(function (other) {
                    if (throughPeople[id + '>' + other]) return;
                    cluster[other] = cluster[id];
                });
            });
            var groups = Object.create(null);
            var keys = [];
            points.forEach(function (p) {
                var key = cluster[p.id];
                if (!groups[key]) { groups[key] = []; keys.push(key); }
                groups[key].push(p);
            });
            var blocks = keys.map(function (key) {
                var members = groups[key];
                /* Levels are worked out again inside each cluster, from its
                 * own connections only. Taken from the whole view, a rename
                 * chain or an ownership chain running through other clusters
                 * left a level for every link of it, and a cluster of
                 * twenty-four names came out eleven rows tall and a third of
                 * the stage wide. The packer keeps the order between
                 * clusters. The loose names have no levels at all; one row
                 * that wraps to the stage's shape. */
                var level = Object.create(null);
                if (key === '(loose)') {
                    members.forEach(function (p) { level[p.id] = 0; });
                } else {
                    var mine = Object.create(null);
                    members.forEach(function (p) { mine[p.id] = p; });
                    var near = Object.create(null);
                    members.forEach(function (p) {
                        near[p.id] = adjacent[p.id].filter(function (o) { return mine[o]; });
                    });
                    var inside = links.filter(function (link) {
                        var ends = endsOf(link);
                        return mine[ends[0]] && mine[ends[1]];
                    });
                    level = levelsOf(members, inside, mine, near).level;
                }
                var block = layoutBlock(members, level, adjacent, needOf, board, aspect, rowH);
                block.holdsRoot = members.some(function (p) { return isRoot[p.id]; });
                return block;
            });

            var bounds = packBlocks(blocks, links, levels.down, rowH, aspect);

            /* The opening view goes side by side where that fits, with each
             * unconnected organisation a cluster of its own so that it gets
             * its share of the width; wrapped into one block of loose names
             * they sat in a knot. Where it does not fit, the members get
             * back the places the packer's block gave them. */
            if (!roots.length) {
                var apart = [];
                var packed = [];
                blocks.forEach(function (block, bi) {
                    if (keys[bi] !== '(loose)') { apart.push(block); return; }
                    block.members.forEach(function (p) {
                        packed.push([p, p.bx, p.brow]);
                        var alone = Object.create(null);
                        alone[p.id] = 0;
                        apart.push(layoutBlock([p], alone, adjacent, needOf, board, aspect, rowH));
                    });
                });
                /* Across the stage itself, not the board inside its padding:
                 * a cluster's extent already holds its names edge to edge. */
                var side = abreast(apart, links, renderer.width - 48);
                if (side) {
                    blocks = apart;
                    bounds = side;
                } else {
                    packed.forEach(function (was) { was[0].bx = was[1]; was[0].brow = was[2]; });
                }
            }
            var rows = bounds.y1 - bounds.y0;

            /* Tighten before ever letting the fit scale the board down: a
             * board scaled down takes its rows closer than a name is tall and
             * the renderer starts dropping names. Not below the point where
             * the gutter still holds a clear channel for the traces. */
            if (rows * rowH > boardH) {
                rowH = tallest * 2 + 15 + 10 + (labelRoom > LABEL_ROOM ? yearsLine : 0);
            }

            /* Use the width the stage has. A board narrower than the stage's
             * shape is framed by its height, and at that zoom the stage shows
             * more width than the clusters fill, so spread them into it -
             * never more than half again, or a cluster stops reading as one. */
            var width = bounds.x1 - bounds.x0;
            var spread = Math.min(1.5, Math.max(1, rows * rowH * aspect / Math.max(1, width)));
            var midX = (bounds.x0 + bounds.x1) / 2;
            var midRow = (bounds.y0 + bounds.y1 - 1) / 2;
            blocks.forEach(function (block) {
                block.members.forEach(function (p) {
                    p.x = (block.x + p.bx - midX) * spread;
                    p.y = (block.y + p.brow - midRow) * rowH;
                });
            });

            /* Measured as drawn, and grown where drawn grown: the packer's
             * own estimate of a name leaves out the pill a person is drawn in. */
            var widthOf = function (p) {
                var w = p.node && renderer.labelBox ? renderer.labelBox(p.node).width : p.label + STAND_BETWEEN_AIR * 2;
                return w * (isHead[p.id] ? HEAD_GROW : baseGrow);
            };
            var whole = viewport.frameOf(points, padding);
            standBetween(points, adjacent, byId, whole && whole.k > 0 ? Math.min(1, whole.k) : 1, widthOf);

            grid = {
                x0: -width * spread / 2, y0: (bounds.y0 - midRow) * rowH - rowH / 2,
                cellW: width * spread, cellH: rowH,
                cols: 1, rows: rows
            };

            /* Every name has exactly its own width, so nothing hangs over. */
            return 0;
        }

        /**
         * Move the people who join two places on one row onto that row,
         * between them.
         *
         * The staff member is the connection: the therapist who turns up at
         * four schools, the director whose next job is the company that
         * bought the last one. Hung off the row below, their two lines run
         * down to a name and back up again, which reads as two connections
         * to two different things rather than one thing joining both, and on
         * a wide row the trip is halfway across the board. Standing between
         * the two places, the line goes straight through them.
         *
         * Only into room that is already there, or that a row can make inside
         * the board's own edges. Widening the board to make space came out of
         * the zoom - the Second Nature view pushed four companies off the
         * stage to pay for it - so a person moves only where the gap holds
         * them, and stays where they were when it does not. Room is measured
         * at the zoom the whole view is framed at (frameK) with the width the
         * renderer draws (widthOf), since a name keeps its size as the view
         * zooms out and the gap around it does not.
         *
         * Ownership and renames are untouched: they run between
         * organisations, and no person stands in the middle of one.
         */
        function standBetween(points, adjacent, byId, frameK, widthOf) {
            var isPerson = function (p) { return p && (p.tier === TIER_COMMAND || p.tier === TIER_STAFF); };

            /* Who is on each row, left to right. Rows are the lattice the
             * whole board sits on, so a row is just a y. */
            var rows = Object.create(null);
            points.forEach(function (p) {
                (rows[p.y] = rows[p.y] || []).push(p);
            });
            Object.keys(rows).forEach(function (y) {
                rows[y].sort(function (a, b) { return a.x - b.x; });
            });

            /* Left and right edges of a row, names included. */
            var extentOf = function (list) {
                var lo = Infinity;
                var hi = -Infinity;
                list.forEach(function (q) {
                    lo = Math.min(lo, q.x - q.label / 2);
                    hi = Math.max(hi, q.x + q.label / 2);
                });
                return [lo, hi];
            };
            /* The edges of the board itself, which nothing may pass: the
             * view is framed on these, so a row that grew past them would
             * cost the whole board its zoom. */
            var boardBox = Object.keys(rows).reduce(function (box, y) {
                var edges = extentOf(rows[y]);
                return [Math.min(box[0], edges[0]), Math.max(box[1], edges[1])];
            }, [Infinity, -Infinity]);

            var movers = points.filter(function (p) {
                if (!isPerson(p)) return false;
                var near = adjacent[p.id] || [];
                if (near.length < 2) return false;
                /* Everything they touch is a place, and every one of those
                 * places is on one row that is not theirs. */
                var row = null;
                for (var i = 0; i < near.length; i++) {
                    var other = byId[near[i]];
                    if (!other || isPerson(other)) return false;
                    if (row === null) row = other.y;
                    else if (other.y !== row) return false;
                }
                return row !== null && row !== p.y;
            });
            /* Widest first: the name that needs the most room gets first
             * refusal on the gaps, rather than being the one left out
             * because a shorter name took the only space it fitted. */
            movers.sort(function (a, b) { return widthOf(b) - widthOf(a); });

            movers.forEach(function (p) {
                var places = (adjacent[p.id] || []).map(function (id) { return byId[id]; })
                    .sort(function (a, b) { return a.x - b.x; });
                var row = rows[places[0].y] || [];
                /* Where on that row the name clears both neighbours at the
                 * zoom the whole view will be framed at. Names are drawn at
                 * one size however far out the view is, so the gap a name
                 * needs grows as the zoom falls: a spot that holds it at full
                 * size can leave it on top of a place once the view is framed
                 * out, and a pair that no zoom short of the top of the range
                 * pulls apart is what zoomed Provo Canyon School's view in
                 * until twenty of its fifty-three names were a pan away. */
                var reach = function (a, b) { return (widthOf(a) + widthOf(b)) / (2 * frameK); };
                var best = null;
                /* Any gap on that row that lies between the two places,
                 * not only the space immediately between them: on a busy
                 * row there is usually somebody else along the way, and
                 * standing in the next gap over still reads as standing
                 * between the two. The spot nearest where they already are
                 * wins, so a name moves as little as the row allows. */
                var span = [places[0].x, places[places.length - 1].x];
                var tightest = null;
                for (var i = 1; i < row.length; i++) {
                    var left = row[i - 1];
                    var right = row[i];
                    var lo = Math.max(left.x + reach(left, p), span[0]);
                    var hi = Math.min(right.x - reach(p, right), span[1]);
                    if (lo < hi) {
                        var at = Math.min(hi, Math.max(lo, p.x));
                        if (best === null || Math.abs(at - p.x) < Math.abs(best - p.x)) best = at;
                    } else if (right.x > span[0] && left.x < span[1]) {
                        var short = reach(left, p) + reach(p, right) - (right.x - left.x);
                        if (!tightest || short < tightest.short) tightest = { short: short, index: i };
                    }
                }

                /* Nowhere on that row with room as it stands. A row may still
                 * be pushed apart to make room, as long as it stays inside
                 * the board's own edges: the view is framed on those, so a
                 * row that grows within them costs the reader nothing, and
                 * one that grows past them costs every name on the board
                 * some of its zoom. */
                if (best === null && tightest) {
                    var edges = extentOf(row);
                    var need = tightest.short;
                    if (edges[0] - need / 2 >= boardBox[0] && edges[1] + need / 2 <= boardBox[1]) {
                        row.forEach(function (other, j) {
                            other.x += j < tightest.index ? -need / 2 : need / 2;
                        });
                        var l = row[tightest.index - 1];
                        best = l.x + reach(l, p);
                    }
                }
                if (best === null) return;
                var wasRow = rows[p.y];
                if (wasRow) rows[p.y] = wasRow.filter(function (other) { return other !== p; });
                p.x = best;
                p.y = places[0].y;
                row.push(p);
                row.sort(function (a, b) { return a.x - b.x; });
            });
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
            /* What was just clicked and its own connections: the part of
             * the board the visitor is looking at. */
            var head = chain.length && !inPath() ? store.node(chain[chain.length - 1]) : null;
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
            if (near && frame && near.k > frame.k * CLOSER && clean(near)) {
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
                    /* Whatever else had to go off the stage, the click stays on it. */
                    var hp = at(head);
                    var hx = hp.x * chosen.k + chosen.x;
                    var hy = hp.y * chosen.k + chosen.y;
                    if (hx < 0 || hx > renderer.width || hy < 0 || hy > renderer.height) {
                        /* Moved, the view has to be checked again: the
                         * zoom that was clean around the old centre dropped
                         * two names around this one on a phone. */
                        chosen = centredAt(lowestClean(chosen.k, Math.max(top, chosen.k), hp), hp);
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
                    return { width: labelWidth(node) + 10, height: LABEL_ROOM + (node.years ? 11 : 0) };
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
