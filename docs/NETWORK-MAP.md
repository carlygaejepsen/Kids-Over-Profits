# Network Map

An interactive map of the connections between troubled teen industry
facilities, the people who ran and staffed them, the companies that owned
them, and the trade groups that accredited them. Built from the project's
Miro board export.

This document is the working plan. Phase 1 is done. Phase 2 is planned below
in detail. Phases 3 and 4 are outlined at the end.

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Data pipeline: CSVs to graph.json, overrides, QA report, tests | Done, branch `feat/network-graph-pipeline` |
| 2 | Core map: page, renderer, hover and focus chain, filters, search, drawer, URL state, mobile | In progress, step 5 of 8 done |
| 3 | Analysis tools: Focus, Path, list view with CSV export, corrections | Outlined |
| 4 | Integration: facility page embed, admin CSV re-import | Outlined |

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

**The map has two states: the whole map, and a focused chain.** This is the
core of the page, and it replaces the "select a node and dim the rest"
sketch this document originally carried.

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

Clicking a neighbour from there extends the chain rather than replacing it,
so the view becomes "Lichfield, then Cross Creek, then whoever ran it" —
the trail the researcher actually walked. Everything on the chain stays on
screen with its own neighbours; the chain is the query. A breadcrumb in the
toolbar names each step, clicking a crumb truncates back to it, and Escape
or a Whole map button returns to the precomputed view.

Isolating on hover alone was considered and rejected: crossing a dense
region would rebuild the view once per node passed, and touch has no hover
at all, so the tap path would have needed its own design regardless. Hover
is a preview precisely because it is reversible.

**Built.** Three things about it are worth recording.

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
6. Search, drawer, URL state.
7. Mobile breakpoints, keyboard, reduced motion, module tests.
8. Chain hulls, if they fit.

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
- **Escape on a deep trail.** It returns to the whole map from any depth, as
  planned, so six steps of research are one keystroke from gone and there is
  no undo. Stepping back one crumb would be safer and is one line; it would
  also mean Escape no longer matches the Whole map button beside it. Worth
  deciding once someone has walked a long chain and found out which is more
  annoying.

## Phase 3: analysis tools (outline)

Focus and Path were the headline items here. Focus became the core Phase 2
interaction instead, and the chain covers most of what Path was for, so
what is left is:

- **Shortest path between two named nodes**: the chain walks the graph a
  hop at a time, which answers "how is A connected to B" only if you
  already suspect the route. Pick both ends, let the graph find it.
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
- A timeline mode once the dates column is populated. Today only two rows
  have dates.
