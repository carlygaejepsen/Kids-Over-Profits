# Network Map

An interactive map of the connections between troubled teen industry
facilities, the people who ran and staffed them, the companies that owned
them, and the trade groups that accredited them. Built from the project's
Miro board export.

This document is the working plan. Phase 1 is done. Phase 2 is planned below
in detail. The fix list raised on 2026-09-17 is reconciled against what has
been built in its own section before Phase 3. Phases 3 and 4 are outlined
at the end.

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Data pipeline: CSVs to graph.json, overrides, QA report, tests | Done, branch `feat/network-graph-pipeline` |
| 2 | Core map: page, renderer, opening view, board layout, Focus/Expand, filters, search, drawer, URL state, mobile | Steps 1 to 6 done (2026-09-18) |
| 2b | Fix list of 2026-09-17: data fields (rebrand, years, deaths), reset view, click zoom, chrome compaction, profile links, starter views, imports | Open, itemised below |
| 3 | Analysis tools: paths between two nodes, list view with CSV export, corrections | Outlined |
| 4 | Integration: facility page embed, admin CSV re-import, timeline | Outlined |

## Phase 1 recap

Source of truth is the board export at `js/data/network/tti_nodes.csv` and
`tti_edges.csv`. The build script turns them into `graph.json` and a QA
report:

```bash
node scripts/build-network-graph.js   # CSVs + overrides -> graph.json + tmp/network-qa.md
node scripts/test-network-graph.js    # invariants, exits 1 on failure
```

Corrections go in `js/data/network/network-overrides.json`, never in the
CSVs, so the next board export does not undo them. The QA report at
`tmp/network-qa.md` is regenerated on every build and gitignored.

Key facts the map depends on:

- The CSV `network` column is a Miro frame, kept as `regions`. Real
  ownership is the `chain` column plus corporate edges. Chain is sparse, so
  the cross-network view uses `crossesRegion`.
- Node kinds: person, facility, parent, association, government, church,
  other. Edge categories: corporate, family, survivor, referral, board,
  leadership, clinical, admissions, staff, other, unknown.
- Edges store the person first on person-to-organisation edges. For
  takeovers, `source` is the acquirer. For rebrands, `source` became
  `target`.
- Same-name entities stay separate (there are two Gateway Academies). Only
  listed merge groups collapse.
- Facility links are exact-key matches against `facilities_v2` only. Looser
  matches are suggestions in the QA report, never applied.
- `graph.json` carries no timestamp, so rebuilds are byte-identical when
  the inputs have not changed.

## Phase 2: core map

Scoped to what a visitor can use on day one. Analysis tools wait for Phase
3, the facility-page embed for Phase 4.

### Decisions that shape the build

**Renderer: Canvas 2D, drawn by hand.** At 907 nodes SVG would be fine on
desktop but stutters on phones during pan, and Canvas keeps the door open
for growth.

**Library: four d3 UMD files, not the full bundle.** Measured sizes:
d3-force 8 KB, d3-quadtree 5 KB, d3-dispatch 2 KB, d3-timer 2 KB, 17 KB in
total against 280 KB for all of d3. Concatenated into one file with a
licence header, like `marked.min.js` and `purify.min.js` already in `js/`.
Pan, zoom and pinch are written by hand, about a hundred lines, because
d3-zoom drags in five more packages.

**The full map's layout is precomputed, not simulated in the browser.** A
Node script runs d3-force once, seeded from the Miro coordinates, and
writes positions to a file. The page paints settled on first load, works
under reduced motion with nothing to disable, and filtering hides nodes
without moving the rest. d3-force v3 uses its own seeded random source, so
the output is reproducible. The script loads the UMD files in a small `vm`
context that exposes a fake global, which sidesteps their `require` calls
for packages that are not installed.

The focus view is the exception, and it is why the vendor bundle ships to
the browser as well as being used at build time. A focused neighbourhood is
at most about thirty nodes, so re-settling it to fill the viewport costs a
few milliseconds even on a phone. Nine hundred nodes never move; thirty
do.

**Plain scripts, not ES modules.** Every existing page script is an IIFE
loaded with `wp_enqueue_script` in dependency order, and there is no
`script_loader_tag` filter adding `type="module"`. The map follows suit,
sharing one namespace on `window`.

**The page markup is real HTML in the template**, not built by JavaScript.
The toolbar, filter rail, drawer and legend exist before any script runs,
which keeps them crawlable, styleable, and usable by screen readers. Only
the canvas and the lists inside the drawer are filled by script.

**The map opens on a handful of organisations and grows from there.** This
replaces both the "select a node and dim the rest" sketch this document
originally carried and the whole-map-plus-chain model that replaced it.

Nine hundred names on screen at once is a hairball whatever the layout does
with them. Every attempt to fix that by arranging them better failed the
same way: 902 of the 907 sit in one connected component that already fills
97% of the frame, and the best force retune measured only moved the spacing
from "clumpier than random" to "about as even as random". The hairball was
never a layout problem. It was the number.

So the map opens on six organisations and nothing else. Everything else is
absent - not faint, not small, not drawn - until it is asked for, by
clicking something already on screen or by searching for it by name. Every
name after the first six arrived because someone went looking for it.

Which six is curated in `network-overrides.json`, resolved to ids by the
build, and it has to be curated: influence and prevalence are an editorial
judgement that no count reproduces. Synanon has six recorded connections
and belongs at the top; plenty of nodes with thirty do not. The map falls
back to the best-connected organisations when the list is missing, so a
board export without one still opens on something sensible.

Hover previews, click commits. Hovering a node lights it and everything it
connects to, drops the rest to about fifteen percent, and pulls the
neighbours in toward it so the cluster visibly gathers. Clicking commits:
the rest of the map goes away entirely, the node and its neighbours
re-settle to fill the viewport, and the node is pushed onto a chain.

The hover gather is a fraction of the way along each neighbour's existing
line to the hovered node, about a third, with a floor so nothing collides.
Pulling along the existing direction rather than snapping onto a ring means
a neighbour stays roughly where the eye last saw it, so the map does not
scramble under the pointer. It is a per-frame display offset, never a
change to the stored positions: leaving the node eases everything back, and
the precomputed layout is untouched. Under `prefers-reduced-motion` the
gather is skipped entirely and hover is dimming alone, which loses nothing
factual.

What a click does is a switch in the toolbar. In **Focus**, the default,
the view is the last thing clicked: click a person in a company's view and
you get that person's connections, not that person's connections laid over
the company's, and the trail is a history. In **Expand** the trail is a
union and each click adds a neighbourhood to the board, which is the "then
Cross Creek, then whoever ran it" reading this plan originally described. A
breadcrumb names each step in either mode, clicking a crumb truncates back
to it, and Escape or the Whole map button returns to the opening view.

Isolating on hover alone was considered and rejected: crossing a dense
region would rebuild the view once per node passed, and touch has no hover
at all, so the tap path would have needed its own design regardless. Hover
is a preview precisely because it is reversible.

**Built.** Several things about it are worth recording, including two that
cost an afternoon each.

**The layout is a board: a hierarchy vertically, distance from the click
along it.** A force layout arranges by relationship, which is the right
input and the wrong output: it packs the well-connected into a knot and
leaves the corners of the stage empty, so names collide in the middle of a
mostly blank canvas. Measured on a thirty-six node neighbourhood it used 32%
of the stage, with seventeen nodes in one quadrant and two in another, and
only twenty of the thirty-six names could be drawn without overlapping.

The clicked node sits in the centre row. Above it are the people who ran
things and, above them, the companies; below it the programmes and, beneath
them, everyone else who worked there. Ownership and command are what this
map is for, so they sit above the places they acted on. "The people who ran
things" is read off the connections rather than the job title - anyone with
a leadership, board or ownership edge was running something, 230 of the 335
people on the board.

Within each band, distance from the centre row is distance from what was
clicked: first-degree connections take the rows nearest the centre,
second-degree the rows beyond, so a trace runs away from the middle rather
than doubling back across it, and within a row a node is ordered by where
its parent sits. The company band is the exception: there, distance from the
centre is distance *up the ownership chain*, read off corporate edges
between two companies with the source owning the target. A company that owns
another on screen is always in a row above it, whatever ring either was
revealed in - ring order alone drew a subsidiary above the company that owns
it - and a change in ownership height starts a new row, so an owner is never
drawn level with what it owns. These are not competing arrangements;
hierarchy is the axis and distance from the click is how far along it a node
goes.

Rows are packed by the width each name actually needs, not cut into columns
of a fixed width: a fixed column has to be as wide as the longest name or it
drops it, and as narrow as the stage allows or it runs out of columns, and
with one thirty-four character programme among forty short ones there is no
width that is both. A band's rings share one run of rows rather than each
starting its own, because with five bands and three rings that fragmentation
alone doubled the row count and pushed the block off the bottom of the
stage. Rows pack at the full width of the stage first, since a block wider
than the stage is the one thing that costs names. A shallow view - the six
opening organisations, or one person - is then repacked to a block nearer
the shape of the stage so the fit can zoom in and fill it; the width is
chosen by packing at each candidate and measuring, because names are
indivisible and a width worked out from area alone landed just under two
names and stood the six organisations in a single column. Zooming in only
ever makes a cell wider than its name. Gutters tighten before the fit is
ever allowed to scale a block down. Opening Provo Canyon School is 53 nodes
in ten rows with 53 labels.

**What is on the board.** The map opens on the curated organisations. Once
something is clicked, the board holds that node and everything it touches,
and then three rules run:

- *A person never appears alone.* The fact worth having about someone on
  this map is which programmes they turn up at, so when a person surfaces
  everywhere they connect to surfaces with them. One step and stop: a person
  reached through another person's expansion does not expand in turn.
- *Whoever owned it is never left off, and brings only itself.* One step up
  the ownership chain, not the whole of it - Provo Canyon School walks up
  through ten organisations if you let it. Owners only, never their other
  holdings: two facilities owned by the same company are not each other's
  business, and only somebody who worked at both puts a second programme on
  screen beside the first. The rule applies to what was asked for, not to
  the opening organisations; letting the background expand turned a click on
  Casa Grande Academy into nineteen parent companies.
- *Everything on screen is reachable from what was clicked*, along the edges
  on screen. "Has a line to something" was not enough: two of the opening
  organisations share an edge with each other and sailed through on it,
  sitting in a person's view with no connection to the person. What was
  clicked always stays. The opening view is exempt, since six organisations
  with two connections between them would come down to two.

**The filter rail starts closed.** As a permanent 250px column it took width
the labels need, and on a map that opens on six organisations and grows by
clicking the filters are a second-order tool. The Filters button is the way
in at every width.

**A name is part of its node.** The renderer publishes the screen boxes of
the labels it actually drew, and the viewport treats a click or hover on one
as landing on the node it names. Only drawn labels count, so a dropped label
is not clickable, and the boxes never overlap, so the first hit is the only
hit.

**A trace must never look like a connection.** On a board where a line
means a recorded relationship, a line drawn across a node it does not
connect is not a cosmetic problem: the map showed CEDU joined to Teen
Challenge, which share no edge and not even a neighbour, and the opening view
ran Synanon's line to CEDU straight through WWASPS. A right angle drawn
naively is not enough - a vertical leg at the node's own x runs through
every cell in that column between the two rows. So traces are routed the way
a track runs on a board: out of the node into the gutter beside its row,
along the gutter, up or down one vertical channel, along the gutter beside
the target's row, and in. The long legs only ever run in gutters, and the
one vertical channel is checked against every node between the two rows -
shape, clearance and label - and moved sideways until it is clear. Two nodes
in the same row route through the gutter below them, behind both labels;
labels are drawn last with a halo so the text stays legible over the line.
Every node also has a clear ring punched through the traces around it before
any node is drawn, so a line passing a name visibly goes in one side and out
the other, and a line that really does end there stops a little short of the
node rather than touching it.

The renderer publishes every route it drew, and the test checks each leg
against the box of every node the route does not connect. That test is what
caught the router not running at all: the label-collision pass declared a
`var grid` inside `draw()`, which hoists over the whole function and shadowed
the layout grid the router reads, so every trace had silently fallen back to
a straight line - including the gutter snapping this paragraph used to claim
was fixing things. A rename fixed it; the lesson is that a claim about what
the page draws needs a measurement of what the page draws.

Connections are routed as right-angled traces rather than straight
diagonals, which is what makes the result readable at that density: diagonals
between grid cells cross at every angle and read as a scribble over the
nodes, where right angles run in the gutters between rows and columns and
can be followed by eye end to end. Turns are offset per edge so two
connections sharing a channel do not lie exactly on top of each other, and
an arrowhead points along the final segment rather than back down the
straight line between the two nodes.

Layouts are applied outright, never tweened. Animating the positions meant
the view had to be aimed at where they were going rather than where they
were, and every attempt to run those two things on one clock left the frame
belonging to whichever finished last: an opened neighbourhood would settle
correctly and then be looked at through the previous view's zoom, with most
of it off the edges. The frame is now taken from the nodes after they are in
place, so it is measured against what is actually drawn and cannot disagree
with it.

Nothing rescales a settled layout to the stage. An earlier attempt to do
that had a floor to stop shrinking pushing shapes into each other, and the
floor was being read as a target: one close pair anywhere in a thirty-node
neighbourhood dragged the whole layout outwards to half again the height of
the stage. Fitting is the viewport's job and it does it by choosing a zoom;
node radius is clamped on the way to the screen so a six-node view cannot
blow its shapes up into blobs.

A settled view reserves room for labels, not just shapes: the collision
radius during the settle is the wider of the node and half its name. Packing
on radius alone arranges the shapes neatly and leaves the names on top of
each other.

The gather has to move the *hit testing* as well as the drawing. Treating it
as a pure display offset, as this plan originally described it, produces a
bug the plan could not see: the pointer leaves the hovered node toward a
neighbour that has gathered inward, the hit test still holds that
neighbour's real position, so nothing is under the pointer, the hover
clears, the gather eases out and the neighbour slides back to where it
started. The node appears to run away from the pointer reaching for it. The
quadtree still indexes settled positions — rebuilding it sixty times a
second would be wasteful — and the few dozen gathered nodes are checked by
hand before it, which is cheap because they are exactly the lit set.

The gather is also clamped, not just scaled. A third of the way along a
short line can be further than the two radii allow, so the travel is capped
at the distance that leaves the node's own radius plus the neighbour's plus
a small clearance. Without it, the neighbours of a big hub end up inside it.

The re-settle runs once, synchronously, and is then animated to, rather than
left running as a live simulation. Knowing the destination before anything
moves is what lets the viewport tween to its new frame on the same clock;
a live simulation would have the view chasing a wobble. A focused
neighbourhood is a few dozen nodes, so 220 ticks cost about a millisecond.
The focused view keeps its own coordinates in a separate map, which is why
leaving one puts the board back exactly as it was, and why dragging a node
inside a focus does not disturb the settled layout underneath.

Escape returns to the whole map from any depth, as this plan specified.
Stepping back one crumb at a time is what the breadcrumb is for. A deep
trail is therefore one keystroke from gone, with no undo — see the open
decisions.

| Path | Purpose |
|---|---|
| `scripts/build-network-layout.js` | Reads graph.json, runs the force settle, writes layout.json |
| `scripts/test-network-layout.js` | Every node has finite coordinates, no overlaps below a floor, bounding box sane |
| `js/data/network/layout.json` | Node id to x, y and radius. Separate from graph.json so physics tweaks never churn the data file |
| `js/vendor/d3-force.bundle.min.js` | The four UMD files concatenated, versions and ISC licence in the header |
| `templates/page-network-map.php` | Template Name: Network Map. Intro, app shell, noscript fallback, config localisation |
| `seeds/network-map.json` | Creates the page through the existing seed loader, slug `network` |
| `inc/admin.php` | Slug assigned in `kop_template_assignments`, version bumped 18 to 19 |
| `inc/template-layout.php` | Template added to the no-sidebar list |
| `inc/enqueue.php` | One block enqueuing the stylesheet, the vendor bundle, and the map scripts |
| `css/network-map.css` | Layout, tokens from colors.css, breakpoints |
| `js/network-map/store.js` | Loads both JSON files, indexes by id, holds filter state, computes the visible subgraph |
| `js/network-map/canvas.js` | Drawing only: shapes, edges, labels, dimming, DPR scaling |
| `js/network-map/viewport.js` | Pan, wheel zoom, pinch, node drag, quadtree hit testing |
| `js/network-map/search.js` | Typeahead over names and aliases, ARIA listbox |
| `js/network-map/filters.js` | Binds the rail controls to store state |
| `js/network-map/focus.js` | The chain: hover preview, click to commit, breadcrumb truncation, and the live re-settle of a focused neighbourhood |
| `js/network-map/drawer.js` | Selected node panel and connection list |
| `js/network-map/url-state.js` | Encodes selection, filters and viewport into the hash, restores on load |
| `js/network-map/app.js` | Bootstrap and event wiring |
| `scripts/test-network-modules.js` | Node tests for store, search ranking and url-state, which are DOM-free |

### Build steps

#### 1. Layout precompute

Forces:

- Link distance by category: short for corporate and family, longer for
  staff and clinical.
- Many-body repulsion scaled by node radius.
- Collision radius from importance.
- A weak pull toward each node's Miro position, strength around 0.05, so
  the settled map still resembles the board the team knows.
- A weak pull toward the centroid of each chain so ownership groups
  cluster.

Three hundred ticks. Isolated nodes are placed in a row along the bottom
edge. Output is rounded to one decimal. The test rejects any pair of nodes
closer than the sum of their radii minus a small tolerance, which catches a
bad force config before it ships.

**Built.** Three hundred ticks is not arbitrary: d3's default `alphaDecay`
reaches `alphaMin` at almost exactly that count, so the simulation is
allowed to finish rather than being cut off. Of 907 nodes, 904 simulate and
3 are isolated. The settled extent is 2991 by 2589, no pair of nodes
overlaps at all, and the closest any two sit is 19 units apart, so
everything is clickable at a phone's touch target size.

Two things differ from the sketch above. Radius is computed here and stored
in `layout.json` alongside x and y, rather than recomputed in the renderer,
because the collision force and the canvas have to agree exactly and two
copies of the formula would drift. Importance runs from about 8,000 to
150,000, so the radius is the log of importance stretched over the observed
range and clamped to 4..16, which keeps the sizes separated whatever scale
the next board export arrives on.

The vendor bundle is a browser UMD file run in a bare `vm` context so it
takes its global branch instead of calling `require` for packages this repo
does not install. That context has to supply `setTimeout`, `setInterval`
and `performance`, because constructing a simulation starts a `d3-timer`
before there is any chance to stop it. The handles are unref'd so a stray
timer cannot hold the process open. `requestAnimationFrame` is deliberately
left out so `d3-timer` falls back to `setTimeout`.

Both files are byte-reproducible: rebuilding without touching the CSVs
rewrites the same bytes. `layout.json` records the graph's `sourceHash`, so
the test fails loudly on a layout settled from an older graph rather than
letting the map draw nodes in the wrong places.

#### 2. Page and plumbing

The template prints an H1 and a two-paragraph standfirst explaining what
the map shows and its limits: board data curated by the project,
relationships as recorded, not a claim of wrongdoing. Then the shell:
toolbar, rail, canvas stage, drawer, legend.

It localises `KOP_NETWORK_CONFIG` with:

- cache-busted URLs for both JSON files,
- the facility directory URL,
- a map of facility id to profile URL built from
  `kop_facility_pages_index()`, which is transient-cached so the loop over
  326 ids is cheap. Only ids present in the index get a URL, so the drawer
  never links to a page that does not exist.

The seed file creates the page, the assignment binds the template, and the
template joins the no-sidebar list so it runs full width.

#### 3. Store and visible subgraph

A node is visible when it passes the kind, status, NATSAP, chain and region
filters and it has at least one connection left in the visible edge set —
or the minimum-connections slider is at zero and it has no connections in
the data at all, or it is the selected node. An edge is visible when both
endpoints are visible and its category is checked.

The cross-region toggle hides in-region edges. This is the staff-migration
view, and it uses `crossesRegion` because the chain column is sparse.

**Rail bound in step 5.** Nothing in `filters.js` decides what a filter
means; the store owns that, and the rail is its switches. The vocabulary
runs the same way: the legend reads its wording back off the checkboxes the
template rendered, so PHP stays the only place that decides how `parent`
reads to a visitor, and adding a kind to the board export needs no change in
any JavaScript file.

Checkboxes apply at once. The connections slider does not: its output
follows the thumb on every input event, but the filter waits about 140 ms
for a pause. A focused trail re-settles whenever the view changes, and
restarting that force run sixty times a second makes the map boil under the
thumb. Debouncing also means one recomputation per drag rather than one per
value crossed.

The Filters button that turns the rail into a sheet was pulled forward from
step 7. Below the breakpoint the rail is `position: fixed` across the bottom
of the stage, so without something to open and close it the narrow layout
ships with the rail permanently covering the map it filters. Widening the
window past the breakpoint reopens it, so a rail closed on a phone is not
still closed on a desktop.

Filtering recomputes the visible set once and hands the renderer flat
arrays. No per-frame filtering.

**Built.** Degree is counted once, over the candidate edges, and nodes below
the slider are dropped in a single pass rather than cascading. A cascade is
the obvious reading of "degree within the visible edge set", and it is
wrong: dropping a node lowers its neighbours' degrees, so a second round
drops more, and at a minimum of three the map empties itself two or three
rounds after the slider moved. One pass is predictable and is what a
visitor means by the control.

The store also owns two things the plan did not name. Status arrives as free
text — `open`, `closed or rebranded`, or empty for 357 of the 907 nodes — and
`statusBucket()` is the single place that maps it onto the rail's three
checkboxes. And `keepVisible` holds one node on screen whatever the slider
says, so selecting a result from search cannot select something the map then
hides.

The visible set is cached against a revision counter rather than recomputed
on read, so the filters can be set in a batch and the work still happens
once, when someone asks for the result.

The minimum-connections slider starts at zero, and zero reads as "any". A
floor of one would have hidden every name with no recorded connection, and
the three the board carries are not obscure ones: Judge Rotenberg
Educational Center, the Independent Educational Consultants Association and
Accelerated Christian Education. Nobody has documented a connection for them
yet. That is a gap in the research, not a reason for the map to leave them
out, and the standfirst already says as much about absent connections.

What zero does not do is resurrect a node whose connections the filters have
just taken away. A node earns its place by having a visible connection, so
the working floor is never below one; the slider's zero position adds back
only the nodes with no connections in the data at all. The distinction is
what keeps the cross-group view usable — without it, "who moved between
board groups" would answer with 451 names and 456 unrelated dots. Unchecking
every connection type is the same rule seen from the other end: the map
comes down to the three names that have nothing, which is an honest answer
to the question asked.

#### 4. Renderer

Shape by kind:

| Kind | Shape |
|---|---|
| person | circle |
| facility | rounded square |
| parent | diamond |
| association | hexagon |
| government, church | triangle |

Colour mode one, the default, by kind: teal, navy, orange, chartreuse
outline on sand, grey. Colour mode two by chain: twelve hues from the
tokens plus grey for the rest, with a legend that lists only the chains
present in the current view.

Open nodes solid, closed hollow with a two-pixel stroke, NATSAP a thin
chartreuse ring. Radius on a log scale of importance, clamped.

Edges: corporate solid and thicker, family dashed, unknown dotted grey,
survivor coral, cross-region orange when that toggle is on.

Labels: nodes above a degree threshold always, the rest fade in past a zoom
level, hovered, chained and neighbouring nodes always. Hover drops
everything off the neighbourhood to about fifteen percent; a committed
focus removes it from the scene entirely rather than dimming it.

Chain hulls come last in this step and can slip to Phase 3 without loss.

The legend is built by `filters.js`, not here. It is the rail's other half:
it says what the marks mean and it lists only what the rail has left in
view. Its swatches are painted by the renderer's own node painter, exported
for the purpose, so a swatch cannot drift from the thing it describes - the
kind shapes, the hollow closed node, the faded unrecorded one and the
chartreuse NATSAP ring are all one code path now.

**Built.** The renderer takes an `emphasis` object carrying the lit node and
edge sets, the dim alpha and the gather offsets, and focus.js fills it in;
step 4 added the dimming without reopening the draw loop, as intended.

Everything on the map is named. That was reckless when the map drew all nine
hundred nodes and a degree threshold was the only thing keeping the names
readable; it is the only honest rule now that what is on screen is there
because somebody asked for it. Collision limits the count instead: where two
names cannot both fit, the better-connected one wins and the other is
dropped rather than smeared over it. Labels are drawn in two passes, every
halo first and then every glyph, because drawing them one at a time means
the next label's halo paints over the last one's text - in a gathered
neighbourhood, where names land close together, labels visibly disappeared.

Direction outranks category in the edge styling, because "became" and
"acquired" are the two statements on this map that are wrong if you read
them backwards. Both carry an arrowhead and their own colour. Everything
else is undirected and has none, which is the honest signal that the record
does not say who came first. The fifty edges joining two people to each
other - married, divorced, siblings - are drawn in red and dashed rather
than as anonymous grey, and the legend names every connection type in view
with a real line swatch.

Dimming does not break the edge batching. Each style bucket is stroked twice
— once lit, once dimmed — so hover costs one extra path per style rather
than thirteen hundred alpha changes, and with no emphasis set the second
pass is skipped and this is the step 3 single pass unchanged.

An edge is lit when it is one of the hovered node's own connections, not
merely when both its ends happen to be lit. A line between two neighbours is
not what was hovered, and lighting it would say the hovered node had a
connection it does not have. With a neighbourhood lit, only its names are
labelled: labelling the dimmed nodes too would bury the answer in the thing
it was picked out of.

World coordinates are projected by hand instead of transforming the context.
Two things would otherwise fight the zoom: stroke widths and font sizes both
have to stay constant on screen while the map goes from 0.1 to 6, and
dividing every one of them by `k` is more code than projecting a point.
Projecting per node also gives step 4's hover gather a natural place to add
a display offset without touching the stored positions.

Edges are bucketed by style once per scene, so a frame is five `beginPath`
calls rather than thirteen hundred style changes, and both ends of a line
off the same side of the viewport skip it entirely.

Two details the sketch left open. Status unrecorded is drawn solid at 55
percent, so "nobody has recorded one" reads as faded rather than as closed —
it is a third of the map and it should not look like a claim. And outline
strength follows the fill's luminance rather than being fixed: half the
chain palette is pale enough to vanish against the sand background without a
firm edge, while navy outlined as firmly just looks smudged.

Chain colours are indexed by position in `meta.chains`, the build's sorted
list, so a chain keeps its colour however the view is filtered. Ten chains
exist; the palette runs to twelve so a board export can add two before
anyone has to think about it.

#### 5. Viewport

Pointer events throughout. One pointer drags the stage, two pointers pinch,
wheel zooms about the cursor, a pointer down on a node drags that node and
pins it for the session.

A quadtree over current positions answers hover and click, rebuilt only
after a drag ends. Every input schedules a single `requestAnimationFrame`
draw, so a burst of wheel events costs one paint. Zoom range roughly 0.1 to
6. A Reset view button restores the framed extent.

**Built.** The quadtree search radius is the largest radius in the scene
plus the slop, not the slop alone. `quadtree.find` returns the nearest
*centre*, so a search bounded by the slop alone misses a sixteen-unit hub
the moment the map is zoomed in far enough for the slop to be worth about a
world unit — a click well inside a big node would find nothing. The shape
test that follows is a circle of the node's own radius, which is close
enough for a diamond or a hexagon and much cheaper than the real outline.

The slop itself is eight CSS pixels, converted into world units per zoom
level, so a small node stays tappable on a phone however far out the map
is. Movement under four pixels between pointer down and up is a click, not a
drag: fingers wobble, mice do not.

Wheel deltas are normalised out of `deltaMode`, because Firefox reports
lines where Chrome reports pixels and an unnormalised tick there is a
fortieth of the one here. A pinch pans by its midpoint's travel and then
zooms about where the midpoint now is, so a two-finger drag that also
spreads does both; lifting one finger starts a fresh pan rather than
inheriting the pinch's midpoint.

#### 6. Search, drawer, URL state

Search ranks prefix match, then word-start, then substring, across names
and aliases. It shows eight results in an ARIA listbox. Enter selects and
centres.

The drawer shows name, kind badge, status pill, NATSAP badge, chain and
board region, a profile link when the config has one, a directory search
link otherwise, then connections grouped by category with the role phrased
from the stored direction: "therapist at", "acquired", "became". Each
connection row is a button that extends the chain by that node, the same
as clicking it on the canvas, which is what makes the chain reachable
without a pointer.

The hash carries the whole chain in order, colour mode, every filter, and
the viewport transform, so a shared link reproduces the trail rather than
just its last node. It is written with `replaceState` on a short debounce. The Share
button copies the URL.

#### 7. Mobile and accessibility

Below 900 px the rail becomes a bottom sheet behind a Filters button, the
drawer takes the full screen with a close button, and the toolbar collapses
to search plus a menu. Sixteen pixel gutters and no horizontal scroll.

Keyboard: Tab reaches search, the rail, and the canvas. On the canvas,
arrows step through the current search results or the selected node's
neighbours, Enter opens the drawer, Escape clears. The drawer is a live
region so selection is announced. Zoom-to-node jumps under
`prefers-reduced-motion` and tweens otherwise. Nothing is encoded by colour
alone.

### Testing

Three layers:

1. The layout test and the module tests run in plain Node after every
   build.

   `scripts/test-network-modules.js` runs the browser modules against the
   real `graph.json` and `layout.json` without a browser. There is no jsdom
   in this repo and no reason to add one: the three modules touch a small,
   known slice of the DOM — a canvas element, its 2D context, pointer and
   wheel listeners, `requestAnimationFrame` — so the test stubs that slice
   and evaluates them in a `vm` context against it. The stub context records
   call counts instead of rasterising, which is enough to tell "drew 904
   nodes" from "drew nothing", and `requestAnimationFrame` runs
   synchronously so a scheduled draw has happened by the next assertion.

   It covers the filter arithmetic, that a frame paints, that a click lands
   on the node under the cursor at both ends of the zoom range, that zooming
   holds the point under the pointer, the pan, drag, pinch and wheel
   gestures, and the whole of the focus chain: what hover lights, that the
   gather pulls neighbours in without pulling them inside, that a gathered
   node is clickable where it is drawn and not where it was, that reduced
   motion keeps the lighting and drops the movement, that committing narrows
   the view and re-settles it without overlaps or moving a stored position,
   and that extending, truncating and clearing the trail all land where they
   should. Search ranking and URL state join it when those modules land.

   Frames and timers are queued against a virtual clock rather than run
   inline. A synchronous `requestAnimationFrame` breaks the code under test
   in two ways that say nothing about a browser: a draw scheduled inside its
   own callback leaves the "already scheduled" guard permanently set, and an
   animation reading `performance.now()` sees a clock that never moves. The
   timer queue is what lets the slider's debounce be tested at all.

   Step 5 added a DOM stub: enough element to bind a filter rail to, with
   the handful of selectors `filters.js` actually uses and nothing else. The
   rail is built from the same meta block PHP reads, so the names, values
   and label wording match what ships. It is not a DOM implementation, and a
   module reaching for something that is not there throws, which is the
   point — the stub should fail loudly rather than quietly pretend.

   The assertions were checked by mutation rather than trusted: breaking the
   endpoint check in the store, the label thresholds, the click slop and the
   quadtree's size-aware search radius each makes it fail, and the last of
   those is the reason the close-zoom probe exists at all.
2. A manual browser list: desktop Chrome, Firefox and Safari; iOS Safari
   for pinch; Android Chrome; one pass at 375 px width; a Lighthouse
   accessibility run.
3. Deploy verified by fetching the vendor bundle and layout.json with a
   browser user agent, since HTML pages are bot-walled.

### Commit sequence

Each step leaves the branch deployable.

1. Vendor bundle, layout script, layout.json, layout test. **Done.**
2. Template, seed, assignment, layout list, enqueue block, empty
   stylesheet. The page renders its intro and an empty stage. **Done.**
3. Store, canvas, viewport. The map draws and pans. **Done**, with
   `app.js` as the bootstrap that wires them and the module tests alongside.
4. Hover preview and gather, click to focus, the chain and its breadcrumb.
   **Done.** `focus.js` owns the chain and never reads the document, so it
   is testable; `app.js` renders the breadcrumb from the chain it reports.
5. Filters, legend, colour modes. The slider's output reads "any" at zero
   and the number above it; the template prints that initial state, so the
   wiring has to keep it. **Done.**
   - 5b. The opening view, the board layout (bands, packed rows, routed
     traces, every name drawn), the three rules for what is on the board,
     Focus/Expand, labels as hit targets, the rail closed by default.
     **Done**, commits 52b0ab6 through 89a9940.
6. Search, drawer, URL state. **Not started**: the search box in the
   template is inert and nothing on the page links to a facility profile
   yet, though the config already carries the URL map.
7. Mobile breakpoints, keyboard, reduced motion, module tests.
8. Chain hulls, if they fit. Probably superseded by the band layout, which
   already groups by kind.

Deploy notes: `inc/` deploys last, and the new `js/vendor` and
`js/network-map` directories need confirming in the first deploy since the
cPanel job has been flaky about new paths.

### Open decisions

- ~~**Slug.**~~ Settled: `/network-map/`, matching the page title and this
  document.
- **The two "Asst." frames.** They are Miro overflow, not real groups. The
  plan shows them in the region filter labelled as board frames, which is
  honest, but they could be hidden from the filter entirely.
- **Hulls in Phase 2 or Phase 3.** They are the one visual that costs real
  time, and the map reads fine without them.
- **Escape on a deep trail.** It returns to the opening view from any
  depth, as planned, so six steps of research are one keystroke from gone
  and there is no undo. Stepping back one crumb would be safer and is one
  line; it would also mean Escape no longer matches the Whole map button
  beside it. Worth deciding once someone has walked a long chain and found
  out which is more annoying.
- **"No off-screen neighbours" against "an owner brings only itself".**
  The fix list asks that a node on screen never has connections off
  screen: everything it touches is pulled into the frame. The board rules
  built in 5b deliberately stop short of that in one place: a company
  brought on as an owner does not bring its other holdings, because two
  facilities under the same owner are not each other's business. People
  already open out fully, which is what the David Gilcrease report was
  about (see 2b.1). The remaining gap is facilities and companies at the
  edge of the board, whose unopened connections are not drawn. Either the
  sisters rule goes, and a click on a UHS facility brings every UHS
  programme, or the edge nodes get an unopened-connections mark. The list
  says the former; decide with a UHS view on screen.

## Phase 2b: the fix list of 2026-09-17

Items from the site-wide fix list that concern the map, checked against
the branch on 2026-09-18. "Done" means a commit already covers it; the rest
are open with the files involved.

### Step 1 closed (2026-09-18)

- **Labels never dropped.** A label that collides below its node is tried
  above it, then right, then left, before it is given up. The real defect
  was the phone: a big neighbourhood framed whole at 375 px zoomed out to
  0.28, where rows sat closer on screen than a name is tall. `applyLayout`
  no longer guesses: it asks the renderer (`renderer.dropsAt`, a dry run of
  the label pass with the same order, placements and collision grid) how
  many on-stage names a candidate zoom would drop, and takes the lowest
  zoom where the answer is none - the whole block when that works, else the
  click and its own connections, else the click in the middle, with the
  rest a pan away. `legibleZoom` (exact, from `renderer.labelBox`) bounds
  the search from above. A first version used an estimated floor with a
  25% margin; it dropped names once the years line made the estimate
  tight, which is why it measures now. Tested at 375 px and on the desktop
  stage: every node on the stage is named, none collide, and a node is only
  ever left off the stage when framing everything would really drop names.
- **Opening view re-lays out on a filter change.** `app.refresh` calls
  `focus.reframe()` on the opening view too, so a hidden organisation no
  longer leaves its cell empty.
- **Brown Schools / CEDU.** Test added: opening CEDU brings The Brown
  Schools a row above it, apart, both named. Closed.
- **2b.1 Gilcrease.** Opening Jeannie Courtney brings all five of his
  connections, and Reset filters clears the cross-group toggle. Test added.
  Closed.
- **Off-screen connections (the decision).** The built rule stays: an owner
  brings only itself. `focus.scene()` now reports `hidden`, the number of
  each node's live connections that are not on screen, and the renderer
  draws it as a "+N" pill at the node's upper right; the legend explains the
  pill whenever one is showing. Test added.

### Step 2 closed (2026-09-18): search, drawer, URL state

- **2b.2 Search** (`js/network-map/search.js`). Ranks every name on the map,
  on screen or not: prefix, then alias prefix, then a later word, then
  inside a word, ties to the better connected; eight results in the
  template's listbox, with arrow keys, Enter and Escape. Picking a name runs
  `focus.select`, so it arrives with its connections. Enter on the typed
  text opens every match together through `focus.openAll`, which switches to
  Expand and says so; the mode radios follow.
- **2b.8 Drawer** (`js/network-map/drawer.js`). Follows the head of the
  trail: kind, status, years and deaths when the build supplies them,
  aliases, the facility profile (or a location-index search for a facility
  with no page; nothing for a person), and every connection grouped by kind,
  each a button that follows it. Close keeps it shut until the next name.
  Ctrl- or Cmd-click on a node opens its profile in a new tab. On a phone the
  drawer is a sheet over the lower half, not the whole screen. The link glyph
  beside a label was left out: the drawer and Ctrl-click already reach the
  profile, and a glyph on every linked label crowded the rows.
- **URL state** (`js/network-map/url-state.js`). `#open=id,id&mode=expand`,
  written with replaceState on every change and read once the data loads
  and on hashchange, through `focus.restore`. A name the board no longer has
  is skipped. `inc/network-map.php` now also hands the page `memorialUrl`.

### Step 3 closed (2026-09-18): reset view and click zoom

- **2b.3** Reset view calls `focus.reframe()`, which lays the current board
  out again and frames it, so a dragged node goes back into its cell. The
  "Whole map" crumb is now "Start over". Test added.
- **2b.4** In Expand mode, after the layout, the newest click and its own
  connections are framed when that zooms in rather than out; the rest of
  the board is a pan away. Focus mode already centred the click. A large
  trail that hits the legibility floor is centred on the newest click
  anyway. Test added.

### Step 4 closed (2026-09-18): rebranded, years, deaths

All three are derived in `scripts/build-network-graph.js` from the prod
mirror and carried on the node; each has a map in `network-overrides.json`
(`statuses`, `years`, `deaths`) and a section in `tmp/network-qa.md`.

- **2b.5 Rebranded.** The board draws rebrand edges inconsistently
  (Lifeline for Youth, still open, points at the closed Life-Line Inc), so
  status decides first: the closed end of a rebrand is the dropped name.
  Where both ends are closed the edge is read as drawn and the pair is
  listed for review (40 today). The remaining closed nodes read "closed".
  80 rebranded, 228 closed. The rail has a fourth checkbox; rebranded is
  drawn hollow with a dashed outline, closed hollow with a solid one; the
  legend has both.
- **2b.6 Years.** From the linked facility's `start_year`/`end_year`, then
  its `operatingPeriod`, then the free-text `yearsOfOperation`; for
  companies, `wpdl_kop_operators.operatingPeriod`; then the board's own
  dates column. Never on a person (their board dates are terms of office).
  203 nodes carry years; 295 facilities without are listed. Drawn as a
  smaller second line under the name, counted in the label box, the row
  packer's row height and the legibility floor.
- **2b.7 Deaths.** Published `memorial_victims` rows matched by program
  name against node names, aliases and the linked facility's name; a
  program naming two nodes, or none, is listed rather than guessed. 27
  nodes carry 36 of the 230 deaths; most of the other 161 programs are not
  on the board at all. Drawn as a firm red ring outside the shape (outside
  the NATSAP ring when both apply), with a legend row whenever one is on
  screen; the drawer states the count and links the memorial.

`scripts/test-network-graph.js` now checks the four statuses, the years
format, that no person carries years, that death counts are positive
integers, and that the closed end of a rebrand paired with an open name is
never left plain "closed".

### Step 5, first half (2026-09-18): connections from the profiles (2b.12)

The build adds 40 edges from structured profile data, only where both ends
are already board nodes (the board stays the roster) and the board has no
line between them: facility `currentOwners`, `pastOperators`,
`otherOperators`, `investors` (corporate), `knownReferrers` (referral),
`staff.administrator` (leadership) and `notableStaff` (staff);
`wpdl_kop_operator_facilities` (corporate) and operator `parentCompanies`
and `founders`. Each carries `provenance: "profile"`, the drawer marks it
"from the profile", and every one is listed in the QA report. A name must
resolve to exactly one node. Facility `pastNames` and `otherNames` that
resolve to another node are not turned into edges - they would assert a
rebrand, and some are sister programmes (Asheville Academy for Girls and
Stone Mountain School) - so the 50 of them are listed for a person to
decide.

The richer data exposed two test assumptions, both corrected: a person
reached through another person's expansion does not open out in turn (the
scene rule), so only people one step from the click are held to bringing
all their places; and a node may be left off the stage only when framing
everything would drop names, which the test now checks against the
renderer.

### Step 5, second half (2026-09-18): staff movement (2b.11)

`scripts/extract-staff-movement.js` reads the ACF Staff Movement block
(`staff_transfers`, `_2`, `_3`) from the published profiles in the mirror
and from `seeds/*.json`, sorts each sentence into came-from or went-on-to
with the profile template's own tests, and drafts one row per person per
move. The reviewed result is `js/data/network/staff-movement.csv`
(person, from, to, role, year, source); the build reads the CSV, never the
sentences, and the script will not overwrite it without `--force` (it
writes `tmp/staff-movement.draft.csv` instead).

Today: 12 sentences on three profiles and the Provo Canyon School seed, 28
reviewed rows. The review fixed five parser mistakes (an organisation with
"and" in its name split in two, "led X before Y" read backwards, a campus
not named, a surname on its own, a start year taken for a move year) and
matched four places the board spells differently (WWASP, Cross Creek,
Greenbrier Academy for Girls, OceanQuest under VisionQuest).

The build adds 17 staff edges with `provenance: "staff-movement"`. A person
who is a board node is connected to both places; otherwise - most are not,
and the board stays the roster - the two places are joined by a staff edge
that names who moved, which is what puts a second programme beside the
first. The drawer prints that sentence under the connection. Five rows name
a place not on the board (Silverado Academy is left there on purpose: it
may or may not be Silverado Boys Ranch) and are listed in the QA report.

Longer term, as the plan says, these belong in `staff.pastTTIJobs` on the
v2 record so the profile and the map read one source.

### Staff list backup (2026-09-18)

The owner's staff list ("Name (role place, role place, ...)", about 400
people) is kept verbatim in `js/data/network/staff-list.txt`.
`node scripts/parse-staff-list.js` turns it into `staff-list.csv` (person,
place, role, source), finding each place by board node name, alias, or a
spelling in its `SHORT_FORMS` table; `--report` prints entries that name no
board node. Family ties and places marked not TTI are left out.

The build reads the CSV after `staff-movement.csv` and only fills gaps: a
pair the map already has a line between is left alone (594 today). The list
says where someone worked, not in what order, so nothing claims a move. A
person who is a board node is connected to each place; otherwise the
person's first-listed place is joined to each of the others by a staff edge
whose text names them ("X worked at both A (role) and B (role) (staff
list)"). Survivor ties never join two places. Today: 341 edges with
`provenance: "staff-list"`, and 56 entries whose place is not on the board,
both listed in the QA report. The three "Embark at ..." campuses were
classed as people on the board and are corrected under `kinds`.

### People the board does not have (2026-09-18)

The board was the roster, so anyone missing from it could not appear: Mel
and Brigitte Wasserman, named as CEDU's founders on the operator record,
were dropped. The build now adds a person node (`addPeople`) when the staff
list, `staff-movement.csv` or a profile ties them to two or more nodes, or
names them founder, owner, CEO or president of one. Someone named at one
place in another role stays off, which keeps single-facility rosters
(Straight - Cincinnati's board) from swamping the map. Added nodes carry
`addedFrom` and sit at the mean board position of their places; the QA
report lists each, with a "check" where the board has someone with the
same surname and initial.

Board people are also matched without a quoted nickname or middle names
(Glenda "Glen" Roach, Sarah Persha Koalkin), unless the shorter name is
somebody else's. Operator founders, CEO and key executives are read from
`keyStaff`, where the records keep them; the operator "CEDU" resolves
through an alias. Today: 191 people added, 1098 nodes, 1967 edges.

The added connections exposed three map rules, fixed alongside: an
undirected facility-to-company line now counts as ownership whichever end
the board drew it from; a tap on a drawn name selects that name unless the
pointer is on a shape itself; and the tests allow staff beside the click
in the centre row, as 0592dd5 intended.

### Lines carry their words and the board's colours (2026-09-18)

Each line is captioned with its relationship as the board wrote it, in
small italic on its longest straight run, after the names and in their
collision grid so a name always wins. Lines the board left unlabelled stay
bare, and the whole map is not captioned.

The board draws each company's connections in the company's colour; the
export carries no colours, so they were read off the Miro frames and kept
in `network-overrides.json` (`chainColours`, `regionChains`,
`membershipColour`). A line takes the colour of the company whose places
it joins: a node's recorded chain, or failing that its board frame
(Wilderness counts as Aspen). A line between two companies stays the plain
ink of its kind. Rebrand, acquisition, family and survivor lines keep their
own colours, every kind keeps its dash, membership lines are the board's
NATSAP blue, and a company's own nodes carry its colour on their border.
Frames the board left black (Synanon, Roloff, Rite of Passage, CERTS,
Eckerd, Evolve, HOPE, YOI, YSI) stay black.

### Step 7 mechanism (2026-09-18): starter views (2b.10)

`network-overrides.json` takes `views`: `{"key": {"label": "...",
"names": [...]}}`. The build resolves each to ids under `meta.views`, with
the headline first as `default`; a name that matches nothing is listed in
the QA report and a view that resolves to nothing is dropped. The store
opens on the chosen view (`store.setView`, `store.seeds`), the toolbar gets
a "Start from" select - printed only when there is more than the default -
and the hash carries `view=key`, so a link can open on it.

**Waiting on the owner:** the lists themselves (historical, today's big
players, religious, or whatever else). Nothing is invented here; until a
list is added the map behaves exactly as before.

### Already covered by 5b

- **Every visible node labelled.** The degree threshold is gone and every
  name is drawn; the band layout packs rows by label width so names fit.
  What remains: a label can still be dropped where two collide at a small
  stage size. The rule the list asks for is *never*, so the fallback should
  be a second placement (above, right, left) rather than a drop, and the
  fit should widen the block instead of letting a name go. Verify at 375 px.
- **Use all the visible space.** Rows pack at the stage width and shallow
  views are repacked to the stage's shape. Verify one case the list named:
  unchecking a kind or a status on the *opening* view. `app.refresh` only
  re-lays out when a chain exists, so hidden nodes may leave gaps there.
- **Circuit-board look.** The grid, gutters, routed traces, clear rings and
  halos are in. Left for a style pass: a faint dot grid on the surface,
  pad-style rings on nodes, junction dots where a trace meets a pad.
- **Focus and Expand modes.** Built as a toolbar switch. Focus shows the
  last thing clicked; Expand accumulates.
- **Brown Schools and CEDU on top of each other.** Caused by the force
  settle packing two wide-labelled hubs 52 units apart. The band layout
  gives each a cell of its own, and ownership ordering puts The Brown
  Schools in a row above CEDU (e1188, acquirer). Confirm on screen and
  close.
- **Toggles taking too much space.** The rail now starts closed at every
  width. The trail and toolbar are still open (2b.9).

### 2b.1 David Gilcrease shows two connections, not five

The data is right: `graph.json` carries e0204 (Jeannie Courtney, family),
e0264 (LifeSpring, staff), e0718 (Cross Creek, leadership), e0733 (WWASPS,
staff) and e0734 (Resource Realizations, unknown). The two that showed are
exactly the two `crosses_network=True` edges, which is also exactly what
the "Only connections that cross board groups" toggle leaves visible.

Since the report, 52b0ab6 opens every person out by one step, so Gilcrease
surfacing anywhere now brings all five. Two checks before closing:
reproduce on the deployed build with the cross-group toggle off, and add a
module test that opens Jeannie Courtney and asserts all five Gilcrease
edges are in the scene. If it still shows two, the toggle is the cause
and `resetFilters` needs to clear it.

The wider rule the list states, that nothing on screen has connections off
screen, is the open decision above. Files: `js/network-map/focus.js`
(`visibleIds`), `store.js`, `scripts/test-network-modules.js`.

### 2b.2 Search

Step 6, unbuilt. `search.js`: prefix, then word-start, then substring over
name and aliases; eight results in the ARIA listbox the template already
prints. On select: if the node is not on the board, push it onto the chain
(`focus.select`) so it arrives with its connections; then frame the match
at a zoom no lower than the current one. Enter with several matches opens
them all and fits them together. Ranking test in the module tests.

### 2b.3 Reset view

`app.js` still binds it to `viewport.fit()` with no arguments, which frames
whatever the viewport holds at its drawn positions and does nothing about
the layout. Replace with `focus.reframe()`: re-lay out the current scene
under the current mode and take the frame from the placed nodes, the same
path a click takes. Rename the "Whole map" crumb to "Start over": there is
no whole map any more.

### 2b.4 Clicking a node zooms to it

The band layout puts the clicked node in the centre row and the fit frames
the block, so in Focus mode this is already true. In Expand mode a long
trail leaves the last click small. After the layout, frame the head node
and its own connections unless that would zoom out, and let the rest of
the board be reachable by pan.

### 2b.5 Rebranded and closed drawn differently

Source status is one string, "closed or rebranded". Derive it in the build:
a node with an outgoing rebrand edge (`REBRAND_RE`, source became target)
is `rebranded`; the rest stay `closed`. Add a `statuses` map to
`network-overrides.json` for corrections. `statusBucket` in `store.js`
gains `rebranded`; the rail gets a fourth checkbox; the renderer draws
rebranded hollow with a dashed outline and closed hollow solid; legend row
added. `identification.pastNames` on the 326 linked facilities can confirm
rebrands the board does not record.

### 2b.6 Years of operation on every node where known

Only two board rows have dates. `facilities_v2` has `start_year` for 357
and `end_year` for 218 of 4,679 rows plus `operatingPeriod.yearsOfOperation`
as text; `wpdl_kop_operators` has `operatingPeriod` for 45 operators. In
`build-network-graph.js`, for matched nodes take years from the facility
(columns first, then the text) or the operator, and emit
`years: "1971-2004"`. The renderer draws a smaller second line under the
name, and the row packer counts it in the cell height. The QA report lists
nodes with no years.

### 2b.7 Facilities with reported deaths outlined in red

`memorial_victims` has 230 published rows across 188 program names, keyed
by free-text `program` with no facility id. Build step: match `program`
against node names and aliases (and facility names via the facility link),
count per node, emit `deaths: N`. Unmatched programs go to the QA report;
`network-overrides.json` gets a `deaths` map for the rest. Renderer: a
firm red outline (not coral pink, this is a warning mark), a legend row,
and the drawer shows the count with a link to `/in-loving-memory/`.

### 2b.8 Node links to facility profiles

`KOP_NETWORK_CONFIG.facilityUrls` already maps 326 facility ids to profile
URLs and nothing reads it. Build `drawer.js` (step 6): name, kind, status,
years, deaths, profile link or a location-index search otherwise,
connections grouped by category with each row a button that extends the
chain. Also a link glyph beside the label of any node with a profile,
opening it in a new tab; Ctrl-click on the node does the same. The
directory fallback should be `/location-index/`, not the program index,
since most facilities are only in the former.

### 2b.9 Toolbar and trail compaction

- The trail becomes a single row of chips along the stage's top edge,
  middle-truncated past six steps, Start over at the left.
- The legend moves to a collapsible corner overlay on the stage, closed by
  default with a one-line summary strip.
- Toolbar in one row: search, Path, Start from, Focus/Expand, Reset view,
  Copy link. Colour mode moves into the filter sheet.
- Stage height becomes `calc(100vh - header)`.

### 2b.10 Starter views

`network-overrides.json` gains `views`: named lists of node names
(`historical`, `todays-big-players`, `religious`; today's `headline`
becomes `default`). The build resolves them under `meta.views`. A "Start
from" select swaps `store.seeds()`; the hash carries the choice. The lists
themselves need curating by hand.

### 2b.11 Staff movement

There is no standalone document. What exists is the ACF "Staff Movement"
block (`staff_transfers`, `_2`, `_3`) on the legacy editorial profile
posts, parsed into came-from / went-on-to sentences by
`kop_fp_staff_connections()` in `templates/single-facility-profile.php`,
and seeded for two records (`seeds/provo-canyon-school.json`,
`seeds/discovery-ranch.json`). Prod holds the block on the other editorial
posts.

1. A script reads every `staff_transfers*` value from `tmp/prod.sqlite`
   (postmeta) and the seeds, runs the existing sentence classifier, and
   writes `js/data/network/staff-movement.csv` (person, from, to, role,
   year, source post) for review.
2. `build-network-graph.js` merges the CSV as extra edges with
   `provenance: "staff-movement"`, resolving names against nodes and
   aliases; unresolved names go to the QA report.
3. Longer term, the same sentences migrate into `staff.pastTTIJobs` on the
   v2 record so the profile and the map read one source.

### 2b.12 Connections from facility profiles

Two structured sources in `tmp/prod.sqlite`:

- `wpdl_kop_operator_facilities` (642 rows: operator, facility,
  current/past) and `wpdl_kop_operators` (45 operators with founders,
  parentCompanies, operatingPeriod);
- `facilities_v2.json_data`: `identification.currentOwners`,
  `pastOperators`, `otherOperators`, `investors`, `knownReferrers`,
  `pastNames`; `staff.administrator`, `notableStaff`, `pastTTIJobs`;
  `provenance.sourceOperator.parentCompanies`.

Build step: for every node with a facility link, and for every operator
whose name matches a node, add corporate, leadership and referral edges
where the other end resolves to an existing node or alias. Unresolved
names go to the QA report. New nodes are not created automatically (the
board stays the roster) unless an override lists them. Edges carry
`provenance: "profile"` so the drawer can say where a connection came from.

### Order

1. Close the verifications under "already covered" (labels never dropped,
   opening-view re-layout, Brown/CEDU) and 2b.1.
2. 2b.2 search, 2b.8 drawer, then URL state, which finishes step 6.
3. 2b.3 reset view and 2b.4 click zoom, both on the reframe path.
4. 2b.5, 2b.6, 2b.7: build-script fields plus their marks, one commit each.
5. 2b.12 profile connections, then 2b.11 staff movement.
6. 2b.9 compaction and the remaining circuit-board polish.
7. 2b.10 starter views, once the lists exist.
8. Paths (Phase 3).

## Phase 3: analysis tools (outline)

Focus and Path were the headline items here. Focus became the core Phase 2
interaction instead, and the chain covers some of what Path was for, so
what is left is:

- **Shortest path and all paths between two named nodes**: the chain
  walks the graph a hop at a time, which answers "how is A connected to B"
  only if you already suspect the route. `store.paths(fromId, toId,
  {max})` in the DOM-free store: BFS for the shortest, bounded DFS (six
  hops, fifty results) for all simple paths, over the filtered edge set.
  UI: a Path button opens two search boxes; the result sets the chain to
  the path nodes in Focus mode and a list under the breadcrumb offers every
  found path. Test against the real graph (Gilcrease to Synanon is a good
  fixture).
- **List view**: an accessible, sortable table of the same filtered nodes
  and edges, with CSV export. This is the screen-reader path.
- **Suggest a correction**: reuses `submit-info.js` to write to
  `suggested_edits`.
- Optional re-layout of the filtered subgraph on demand.

## Phase 4: integration (outline)

- A one-hop mini graph on `single-facility-profile.php` and the generated
  `/facility/` pages, rendered by the same modules from graph.json, with a
  "See full map" link.
- Admin CSV re-import through an `api/` endpoint so researchers update the
  board without a git commit.
- A timeline mode once years are populated. Today only two board rows have
  dates; 2b.6 fills in several hundred from the facility and operator
  records, which is enough to start.
