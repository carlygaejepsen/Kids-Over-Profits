# Work plan

The one place for open work on the theme and on https://kidsoverprofits.org:
how to work in this repository, what is waiting on the owner, and what a
session can pick up next, in order. It replaces four handoffs (2026-09-18,
the two of 2026-09-24, and the page-template handoff of 2026-09-25) and the
open-work parts of the September fix plan. Those handoffs are in git history
if you need their wording.

Last checked against `main` at
[58b45532](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/58b455321b8a36123835f85d94cffac0ed6b4c4f)
on 2026-09-28. Check `git log origin/main` for what has landed since, and
update this file in the same commit as the work it tracks.

The record of finished work, with the reasoning behind each decision, stays
where it was written:

- [FIX-PLAN-2026-09.md](FIX-PLAN-2026-09.md): items 1 to 20 of the
  September fix list (code comments cite its item numbers).
- [NETWORK-MAP.md](NETWORK-MAP.md): the map's phases, with a "Built" note
  per step.
- [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md) and
  [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md): the template families and
  what became of every default-template page.
- The area READMEs listed in [README.md](README.md), for example
  `anonymous-portal/`, `state-inspection-reports/` and `news-processor/`.

---

## 1. Working in this repository

The owner's rules:

- **The default target is the live site.** Test against it after every
  deploy. Treat work as local (Flywheel) only when the owner says so.
- **Work on `main`.** No branches, no PRs. A push to `main` deploys through
  `.github/workflows/deploy.yml` and `.cpanel.yml`.
- **No emojis** anywhere: chat, UI text, code, comments, commits.
- **Reports to the owner carry full clickable URLs**: live pages, GitHub
  commits as `https://github.com/carlygaejepsen/Kids-Over-Profits/commit/<full sha>`,
  admin endpoints. Never bare paths or short SHAs.
- **No text directly on the gradient background.** Check with
  `python scripts/check-bare-text.py` after any template or page CSS change.
- **Colours** from `var(--kop-*)` in `css/colors.css`. Chartreuse, coral pink
  and bubblegum pink are for borders and highlights; accent colours as text
  use the `-ink` tokens.
- **Never link `/tti-program-index/` for a facility.** Facility links go to
  `/facility/<slug>/` or the state hub's `?search=`.
- **Program and organisation websites** link to the Wayback Machine first,
  and to the live site only through `/go/`.
- **Ask before any write to production.** Reads are fine.

The checkout at `C:\Users\daniu\source\repos\Kids-Over-Profits` is shared with
other sessions that commit and push to `main` at the same time:

- Stage files by path. Never `git add -A`, `git add .`, `git stash`,
  `git reset --hard` or `git clean`.
- Read `git diff --cached` before committing, and `git show --stat HEAD`
  after. Other sessions have swept staged files into their commits.
- When a file you need carries someone else's uncommitted hunk, stage a blob
  of `HEAD` plus only your change (`git hash-object -w`, then
  `git update-index --cacheinfo`). Committing another session's
  `require_once` of an uncommitted file would fatal production.
- Push with `git pull --rebase --autostash origin main`, then
  `git push origin HEAD:main`.
- If Source Control fills with files already on `main`, compare each to
  `origin/main`, `git reset --mixed origin/main` (moves the branch, touches no
  file), refresh only files equal to an older committed version, and set the
  upstream to `origin/main`.
- **Deleting a file in git does not delete it on the server.** Add an
  `rm -f` task to `.cpanel.yml` for each removed file.
- Template assignments are `kop_template_assignments()` in `inc/admin.php`;
  bump the `$version` in `kop_maybe_apply_template_assignments()` when the
  list changes. Redirects are `kop_redirect_map()` in `inc/redirects.php`.

Mechanics:

- **Line endings are mixed**, CRLF and LF, sometimes in one file. Keep each
  file's endings; match whitespace-tolerantly; assert each target string
  matches once.
- **The Bash tool collapses backslashes.** Put regex-bearing edit scripts in
  a file and run the file, or use the Edit tool. A Python script containing
  `unlink` was refused as destructive where the same Edit went through.
- **PHP**: none on PATH. Use
  `$LOCALAPPDATA/Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win64/php.exe`
  with `-n -l` to lint, and `-n -d extension_dir=<that bin>/ext -d
  extension=pdo_sqlite -d extension=mbstring` (plus `sodium`, `fileinfo`,
  `zip` where a test needs them) for the harnesses.
- **Production mirror**: `tmp/prod.sqlite`, refreshed by
  `python scripts/sync-prod-sqlite.py`. It goes stale fast; refresh before
  inventory work, and confirm a finding on the rendered page before
  reporting it (item 20 of the fix plan was wrong for reading the mirror).
- **Production over SSH**: `ssh -i ~/.ssh/kop_nixihost -p 1157
  kidsover@dfw-s07.nixihost.com`, database `kidsover_production`, prefix
  `wpdl_`, no wp-cli. The auto-mode classifier has refused SSH reads in some
  sessions; do not work around it, use the mirror and ask the owner.
- **Deploy check**: `gh run list --workflow=deploy.yml --limit 5`. A failure
  reading "VersionControl/update failed after 3 attempts" is the host; the
  next push deploys your commit too (`git merge-base --is-ancestor`). Then
  fetch a static asset with a browser user agent; HTML pages are bot-walled
  for outside curl, but curl over SSH with a browser user agent works.
- **Browser checks**: Python Playwright with `channel="chrome"` against the
  live site. `scripts/preview-network-map.py`, `scripts/preview-hub-pages.py`
  and `scripts/preview-utility-pages.py` splice the working tree into live
  pages.

---

## 2. Waiting on the owner

Actions only the owner can take (admin browser sessions, hosting, editorial
calls). Tools that change data show a dry run first; add `?apply=1` to act.
The date is when each was last confirmed open.

### Admin runs

1. **Menu entries** (2026-09-23).
   [rebuild-header-menu.php](https://kidsoverprofits.org/wp-content/themes/child/api/rebuild-header-menu.php),
   then `?apply=1`. Puts Inspection Reports and Severe Reports in the
   Monitor menu.
2. **PDF covers** (2026-09-23).
   [regenerate-pdf-previews.php](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php),
   then `?apply=1` until it reports 0 remaining (153 PDFs).
3. **Severe findings review** (2026-09-24). The nightly scan cron was added
   on 2026-09-24 (first run 04:15 on 2026-09-25). Send the first lines of
   `/home/kidsover/logs/inspection-highlights-scan.log` (expect "Saved:
   scanned N reports, 0 remaining"), then review candidates in
   [review-inspection-highlights.php](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php).
   Nothing reaches [/severe-reports/](https://kidsoverprofits.org/severe-reports/)
   unapproved. Scanner version 4 (2026-09-28) adds NC, GA, MN, AR and FL
   and tightens two rules; approved findings the new rules no longer back
   stay published until rejected by hand:
   [13 approved findings to recheck](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php?status=approved&ids=230,1285,1123,1306,561,1307,616,859,875,114,199,408,894)
   (minors' sexual activity without an adult, single medication errors).
4. **Featured report notes** (2026-09-18). The two featured UHS of Provo
   Canyon reports have no `featured_note`; add one each in
   [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php).
5. **Research facility tags** (2026-09-23).
   [propose-research-facility-tags.php](https://kidsoverprofits.org/wp-content/themes/child/api/propose-research-facility-tags.php)
   writes `kop-research-tag-proposals.json` to uploads; prune it, then
   `?apply=1`.
6. **Research relevance tiers** (2026-09-23). Rate documents in the
   [research library](https://kidsoverprofits.org/researchreports/) editor.
7. **Proposed resources** (2026-09-23). Logged in,
   [/resources/](https://kidsoverprofits.org/resources/) shows a dashed block
   of proposed crisis lines, reporting routes and family resources. Check
   every number; the ones to publish move into `kop_resources_groups()` in
   `inc/resources-list.php`.
8. **Staging pingback** (2026-09-23). Delete comment 453 on
   [/hyde/](https://kidsoverprofits.org/hyde/), a pingback from the staging
   copy.
9. **WA full scrape** (2026-09-24). `wa_scraper.py --full` in the Tools repo,
   with `KOP_DATA_API_KEY`.

### Hosting

10. **Close `/staging/`.** It answers 200 and its inner pages carry no
    `noindex`. Password it in cPanel, set "Discourage search engines" in the
    staging install, or take it down; nothing on the live site depends on it.
11. **Portal leftovers** (optional). NixiHost backups still hold the one
    pre-encryption submission (`SUB-2025-YLVUY0CL`) until they rotate, unless
    support purges them. The owner deleted the public copies and asked
    archive.org to remove its capture on 2026-09-24; not re-checked, at the
    owner's request.

### Decisions

12. **Hub editor content** (page templates 4.4). Changes ship through
    `kop_apply_text_fixes()` in `inc/admin.php`, never by hand on
    production. Recommendations:
    - Where Are the Kids and Advocates link `/international`, which 301s to
      `/location-index/?type=country`: point the links at the destination.
      (Advocates' `/tti-program-index/` link is correct: operators.)
    - Survivors repeats the support groups and accounts the Resources module
      carries: keep one list on Resources and link to it.
    - Survivors links "Tips for Enjoying Nature..." to the post
      `/11-12-2024/`; if it matches the page `survivor-resources-nature`,
      redirect the post to the page.
    - Research & Reports: "Summary coming soon" on six entries and "Click
      here for notes" links; the library module is the canonical list, so
      trim the editor content to the introduction.
    - Survivors, Research & Reports, Advocates and Resources use `h6` for
      section headings, History `h5` under `h3`: use `h2` and `h3`.
13. **Development leftover posts** (page templates 6.2, most exposed).
    Published and public: `facility-form-test`, `data-organizer`, `data`,
    `data-analysis`, `arizona-adhs-inspections` (2.7 MB). Recommendation:
    unpublish to draft, after checking no link or redirect depends on them.
    `tmp/post-inventory.csv` shows zero inbound links for the first four.
14. **News posts** (6.3). The 75 News posts are now duplicated in the news
    feed. Recommended: 301 each to its feed entry, as `/news/` was.
    Alternative: a `templates/single-news.php`.
15. **Lawsuit posts** (6.4). Per post: 301 to `/lawsuits/#lawsuit-<id>` when
    the row covers it, or keep the analysis and link it from the row.
16. **OG Image snippet.** Code Snippets snippet 6 duplicates Yoast's five
    `og:` tags on the front page. Recommended: deactivate it.
17. **Network map.** Kind marks answered 2026-09-28 (a small-caps kind
    word under the name; open work 3.1.3). Still open: the judgement
    calls in `tmp/network-qa.md` (rebuilt by every map build):
    about 50 profiles naming another node as a past or other name, closed-
    to-closed rebrands, unplaced memorial programs, Silverado Academy,
    "Brent Hall" folded into Brent Charles Hall, and the 56 staff-list
    places not on the board.
18. **Portal table.** Clear the leftover `wpdl_anonymous_submissions` row
    (empty message and contact, a `files_data` blob naming the file) or drop
    the table.

### Unconfirmed settings

19. Which mode production's `kop_data_model` / `kop_data_model_areas`
    options use for the `program_index` read area, and the value of
    `kop_submission_notify_emails` (no admin screen; default `admin_email`).

---

## 3. Open work, in order

### 3.1 Network map

Live at https://kidsoverprofits.org/network-map/. Build and ship each on its
own. The full specifications are in the [appendix](#appendix-network-map-specifications).

1. **List view with CSV download** (Phase 3). A Map / List switch showing
   the board as two sortable tables with CSV export. Not started (no
   `js/network-map/list.js`).
2. **David Gilcrease (2b.1).** A module test asserting all five of his
   connections, and a check of the deployed build with the cross-group toggle
   off.
3. **Kind marks (2d.2) and the legend's kind rows (2d.9)** (owner's choice
   2026-09-28): a small-caps kind word set under the name (COMPANY, TRADE
   GROUP), fading with the labels at small zoom, never a colour; the
   legend gets a row per kind painted by the same painter.
4. **Phase 4 integration** (owner's go-ahead 2026-09-28, after the list
   view): the one-hop mini graph on facility profile pages with a "See
   full map" link, admin CSV re-import through an `api/` endpoint, and a
   timeline mode now that 2b.6 filled in years. Outlined at the end of
   NETWORK-MAP.md; spec each piece there before building, as 2d.6 was.
5. Later, not to be started without a word from the owner: chain hulls and
   group-by-network (2d.4), "Suggest a correction" from the map (Phase 3).

Shipped from this list: the route highlight (2d.6) on 2026-09-28; the
record of what was decided is NETWORK-MAP.md's 2d.6 "Built" paragraphs.

After any map change: `node scripts/build-network-graph.js`,
`node scripts/test-network-graph.js`, and
`node scripts/test-network-modules.js > tmp/nm-test.log 2>&1` in the
background (9 to 12 minutes, silent until the end).

### 3.2 Page templates and posts

Phases 0 to 3 of [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md)
are done: hubs from `kop_hub_config()` (`inc/hub-shell.php`), the preview
tool, the Law & Policy updated date and `--live` link check in
`scripts/test-hub-pages.php`, the utility and legal-document templates
(`inc/utility-pages.php`), `/support/` 301 to `/donate/`, the Links card
image. `no-access` still prints a raw `[dlm_no_access]` (Download Monitor
inactive; the owner is not concerned). Never trash, rename or re-slug
`no-access`: Download Monitor points at it by ID.

Open:

1. **Visual QA of all hubs** (4.2). Run
   `python scripts/preview-hub-pages.py --shots tmp/hub-preview` and look at
   every shot at 390, 768 and 1440. Only Law & Policy, History, Survivors and
   Support were checked. Watch the longest action labels at 390, the
   contribute band at 768 (it switches at 760), and wide editor blocks
   (Research & Reports, Resources, Where Are the Kids' map) sitting on a
   module with no spacing. Fix in `css/hub.css` under `.kop-hub*` only.
2. **Post classification** (6.1). `scripts/inventory-posts.py` exists and
   `tmp/post-inventory.csv` was written on 2026-09-25 with a proposed class
   per post. Refresh the mirror, re-run it, and write
   `docs/POST-CLASSIFICATION.md` in the shape of PAGE-CLASSIFICATION.md
   (`news`, `article`, `legal-record`, `facility-profile`, `tool-or-test`,
   `obsolete`). Confirm `in_news_feed` is true for every News post before
   decision 14 is acted on.
3. **"Where are the kids?" posts** (6.5). For each of the 12, where a
   `/facility/<slug>/` page covers the same facility, recommend
   `single-facility-profile.php`, as the 12 facility-profile posts got.
4. **Act on decisions 13 to 15** once answered.
5. **Category archive layout and a 404 page** (6.6), with search and links to
   the main hubs, after 2 to 4. Both on a solid panel.

Done for any template task: PHP lints, the offline test passes, screenshots
at 390, 768 and 1440 looked at, `check-bare-text.py` passes, deploy green,
live HTML checked (and `critical error` absent), and the design plan's Status
updated.

### 3.3 Inspection reports

1. **The first scan log** (owner item 3), then any new candidates.
2. **Move TX and CA to the shared viewer.** The last two states on legacy
   scripts (`js/inspections/tx_reports.js`, `js/inspections/ca-reports.js`).
   Fix CA first: it loads static `ccl_reports_batch_*.json` and holds about
   9,100 reports twice under two id schemes (`455002153-3` and
   `455002153-3-d92232c9bd`), which inflates hub counts. Use the jsdom
   harness and a before/after snapshot of counts, badges, previews and
   summaries (the FL/NC lite-list method). `severe-flags.js` reads both
   markups; check the flags still land.
3. **Severe-finding extractor for WA.** Built
   (scanner version 4, 2026-09-28): TX, CA, UT, AZ, CT, NC (harm tags only:
   V 131/132, 289-296, 314/315, 366/367, 512-525), GA, MN, AR (federal
   surveys only), FL (DJJ reviews, Failed/Limited indicators). Left out: OR
   (findings are the rule's own wording), WA (scraper interleaves the PDF
   columns), NV (no text). Rules: substantiated only; no child-on-child
   fights; minors' sexual activity only with an adult taking part or an
   assault; single medication errors never, a pattern of them yes;
   elopement only with death or injury. Flags on the trackers match NC,
   GA, MN, AR and FL reports by their document link (08172021).
4. **"What inspectors found" on facility pages** (the rest of fix-plan step
   14.5): approved highlights on `/facility/<slug>/`.

The FL/NC lite list has one standing rule: the PHP readers in
`api/lib-inspection-text-signals.php` must match `nc.js` and `fl.js`
exactly. Change both, bump `kop_its_version()`, and run
`node scripts/test-inspection-text-signals.js --php=<php.exe>`.

### 3.4 Long-form articles

The code is done (fix plan 15, 17, 19). What is left is mostly writing, and
the text goes in the page body, never in post meta, ACF or a table (owner,
2026-09-23), so the site search can find it:

1. **15B**: decide which sections of the long articles to wrap in
   `[kop_section]` and above what length (candidates: Corporatization,
   Advocacy History, Straight Inc, WWASP).
2. **15C** summaries (`[kop_summary]`), **15E** "Why this matters"
   (`[kop_why]`), **15D** era tags (`[kop_era]`).
3. **18A** Advocacy History (the brief's "Survivors and Families Fight Back"
   is the same page), **18B** Corporatization (a corporate-layering SVG, a
   key-concepts block, a link into the map on its chain), **18C** Lawsuits
   (intro, status labels, filter micro-copy, grouping, recent updates, how to
   submit).

### 3.5 Data research

- **Alternate names**: 4,334 of 4,663 facilities carry none;
  [alternate-names-gap-2026-09-18.csv](alternate-names-gap-2026-09-18.csv)
  lists them. Re-check with `scripts/test-state-feed-names.php`.
- **Years that disagree** between text and start/end years: New Dominion
  School of Virginia, Withlacoochee JRF, CEDU Middle School, Elevations RTC,
  Marion Youth Academy, Bartow Youth Training Center, Lexington Academy,
  Auldern Academy, Three Springs of Englishton Park, Three Springs Paint Rock
  Valley, Three Springs School of Madison, Oakley School, New Leaf Academy of
  Oregon.
- **Unplaced records**: New Directions Home for Boys (Three Springs), and
  "Aspen Education Group #2" (100001), an operator filed as a facility.

### 3.6 Small and unverified

- The admin stylesheets still use about fifty accent colours as text (16B).
- No real upload has gone through the live anonymous portal since it began
  sealing files; the offline test covers the path.
- No live call has been made to the new Claude, Gemini or Hugging Face
  models in the AI helpers (`docs/news-processor/README.md`); the pages send
  `provider: 'groq'`, so they only run when a request names them.

---

## Appendix: network map specifications

Written 2026-09-24 for the route highlight and the list view. The route
highlight (A) shipped 2026-09-28 and its section is gone from here; what
was built and why is NETWORK-MAP.md 2d.6. The list view remains. Ship it
on its own: commit, push, deploy, check live.

### 0. Read this first

#### Where things are

| What | Where |
|---|---|
| The page template (toolbar, trail, stage, drawer, Key) | `templates/page-network-map.php` |
| Script load order (each module depends on those before it) | `inc/enqueue.php`, the `foreach (array('store', 'canvas', ...))` list near line 2012 |
| Data: nodes, edges, filters, `paths()` | `js/network-map/store.js` |
| What is on the board, layout, trail, modes, Simplify, Show all | `js/network-map/focus.js` (about 2,700 lines) |
| Drawing and the emphasis state | `js/network-map/canvas.js` (`renderer.setEmphasis`, about line 1900) |
| The "Connect two names" form and the route list in the drawer | `js/network-map/path.js` |
| The side panel | `js/network-map/drawer.js` |
| Wiring, the buttons, Escape | `js/network-map/app.js` |
| The address bar (`#open=...&mode=...&simple=1`) | `js/network-map/url-state.js` |
| Styles | `css/network-map.css` |
| The module tests (Node, a fake DOM, the real `graph.json`) | `scripts/test-network-modules.js` |
| A browser preview of the working tree | `scripts/preview-network-map.py` |
| The design record: read the 2d.6 section and Phase 3 before you start | `docs/NETWORK-MAP.md` |

#### How the modules are written

- Plain scripts, no bundler, no ES modules. Each file is an IIFE that hangs
  one object on `root` (`root.KOPNetworkPath = {...}`). Use `var` and
  `function`, the way the files around your change do.
- Anything worth testing is DOM-free and exported, so the tests can call it
  without a document. Examples: `KOPNetworkPath.describeRoute`,
  `KOPNetworkCard.summary`, `KOPNetworkUrlState.parse/format`.
- The comments explain why, in full sentences, at the density of the
  surrounding code. Match it.
- The test DOM in `scripts/test-network-modules.js` (the `el()` function,
  about line 262) is deliberately small. Its elements have `className` (a
  string), `setAttribute`, `getAttribute`, `appendChild`, `textContent`,
  `hidden`, `addEventListener`, `dispatch(type)`, `focus()` and
  `querySelector(All)` for `tag`, `.class` and `tag[attr="value"]` only.
  They have **no `classList`, `innerHTML`, `dataset`, `insertBefore`,
  `removeChild` or `closest`**. Any module the tests load must stick to what
  the stub has. `app.js` is not loaded by the tests, so it may use
  `classList`.
- Colours come from `css/colors.css` as `var(--kop-*)`. The map's own look
  (white stage, status fills, blue NATSAP names, company-coloured lines) is
  the owner's Miro board and must not change.
- No emojis anywhere: UI text, code, comments, commits.

#### Mechanics that will bite you

- **The module test suite takes 9 to 12 minutes and prints nothing until it
  ends.** Run it in the background and wait for it; it is not hung:
  `node scripts/test-network-modules.js > tmp/nm-test.log 2>&1`.
  Failures are the lines starting `  - `.
- **Line endings.** The JS, CSS and PHP files are CRLF. When you edit with a
  script, read and write with `newline=''` and replace `\n` with `\r\n` in
  your inserted text. The Bash tool collapses backslashes inside inline
  Python, so put edit scripts in a file under `tmp/` and run the file.
- **This checkout is shared with other agent sessions** that commit and push
  to main at the same time. Stage files by path, never `git add -A`, and
  before pushing run `git pull --rebase --autostash origin main`. After
  committing, check `git show --stat HEAD` holds only your files.
- **Work on main.** Commit and push straight to main. No branches, no PRs.
  Push deploys to production through GitHub Actions (`deploy.yml`).
- Every commit message ends with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- **Deploy check.** Find your run with
  `gh run list --workflow=deploy.yml -L 5`. A failure reading
  "VersionControl/update failed after 3 attempts" is the host being flaky;
  the next push's run deploys your commit too. Confirm with
  `git merge-base --is-ancestor <yours> <deployed sha>`.
- **Live check.** The HTML pages block plain curl. Use Python Playwright
  (installed) with `channel='chrome'` against the live page. `window.KOPNetworkDebug`
  exposes `{ store, renderer, viewport, focus }` for reading state.
- **The Key opens itself on a first visit** and, on a phone, covers the
  stage controls. In Playwright, close it first: click
  `#kop-network-filters-toggle` when its `aria-expanded` is `"true"`.

#### Things in focus.js you will use

- `chain` (the trail, ids oldest first) and `mode`: `'focus'` (the last name
  only), `'expand'` (the union of everything clicked) or `'path'` (a route
  drawn on its own).
- `visibleIds()` decides which names are on the board; `focus.scene()`
  returns `{ nodes, edges, nodeIds, hidden, folded, simplified }`. `hidden`
  is the "+N" count per name. `folded` holds the people drawn as a line
  between two places instead of as a name (`foldConnectors`).
- `enterFocus()` re-lays the board out and calls `onChange()`. The layout is
  seeded by a key built from `mode`, `simple` and `chain` (look for
  `var key = mode`). Change that key if your state changes the layout.
- `applyEmphasis()` hands `renderer.setEmphasis({ hoverId, near, nearEdges,
  dim, offsets, ... })`. `near` is a set of node ids that stay lit,
  `nearEdges` a set of edge ids; everything else is drawn at `dim` (0.15).
  Today only the hover gather sets `near`.
- `focus.linesBetween(a, b)` returns the lines on screen between two names,
  including the synthetic folded lines (`category: 'people'`, id
  `via:<key>`).
- `focus.showPath(ids)` draws a route on its own (mode `'path'`).
- `focus.isSimple()` / `focus.setSimple(on)` and `focus.showAll()` shipped
  on 2026-09-24 (commit 51fd2bf); read them as the most recent example of a
  feature added end to end (focus, url-state, template, app, CSS, tests,
  doc).

---

### List view with CSV download (Phase 3)

#### Why

The canvas is one element standing for every name on it. The arrow keys
(`keys.js`) and the drawer make it usable without a pointer, but there is
still no way to read the whole board as text, sort it, or take it away. The
list view is the screen-reader path, and the way a researcher gets the
board into a spreadsheet.

#### What to build

A **Map / List** switch that shows what the board shows, as two tables, in
place of the canvas:

1. **Names**, one row per name on the board, including the people folded
   into lines (listed as names here, with "(drawn on a line)" in the
   Shown-as column). Columns:
   - Name, a button that opens that name on the map (`focus.select`) and
     stays in the list
   - Kind: the drawer's words, `KOPNetworkDrawer.factsFor` or its
     KIND_WORDS
   - Status
   - Years
   - Group (`node.chain`)
   - NATSAP (Yes or empty)
   - Connections on the board
   - Not on the board (the "+N" count, `scene.hidden[id]`)
   - Deaths recorded (`node.deaths`)
   - Profile, a link from `KOPNetworkDrawer.profileFor(node, config)` when
     it has `own: true`. Never link `/tti-program-index/`.
2. **Connections**, one row per recorded line between two names on the
   board: From, To, Connection (the line's label from
   `KOPNetworkCanvas.styleFor(edge, false).label`, the same words the Key
   uses), Role (`KOPNetworkConnection.wordsOf(edge)`), Source ("from the
   profile", or the staff-list text, as `drawer.groupsFor` does). Use the
   real store edges between those names, not the synthetic folded lines:
   a folded person's two lines are two rows here.
3. Each table sorts by any column: the header is a button, a click
   toggles ascending and descending, and `aria-sort` goes on the `th`. The
   default is Name ascending, and From then To for connections. Sorting is
   stable and case-insensitive; numbers sort as numbers.
4. **Download CSV**, one button per table. The file is built in the
   browser from exactly the rows shown, in the order shown. RFC 4180
   quoting (quote every field, double any inner quote), CRLF line ends, a
   UTF-8 byte order mark so Excel reads the accents. Guard against formula
   injection: prefix a field that starts with `=`, `+`, `-`, `@`, a tab or
   a carriage return with an apostrophe. Filenames:
   `network-map-<slug of the name being read, or "opening-view">-names.csv`
   and `...-connections.csv`. Keep the CSV pure data, with no title or
   source line above the header row; the source and the date go in each
   table's caption on the page instead.
5. The list follows the board: open a name, switch Focus or Expand, turn on
   Simplify, press Show all, light a route, and the list shows the new
   board (it re-renders on `focus` `onChange`). The trail, the drawer and
   the toolbar keep working while the list is showing.
6. The switch is kept in the link as `list=1`.
7. The stage controls that only make sense on a canvas (zoom, Fit to
   screen, Reset view, Full screen, the Key) are hidden while the list
   shows. Show all and Simplify stay.

#### Where the code goes

**New module `js/network-map/list.js`**, exported as `root.KOPNetworkList`:

- `rows(store, scene, config)`: DOM-free. Returns
  `{ names: [...], connections: [...] }` as plain objects with the column
  values above. It also covers the folded people: `Object.keys(scene.folded)`
  are ids of people on the board as lines; include them as names.
- `sortRows(rows, key, direction)`: DOM-free and stable.
- `toCsv(columns, rows)`: DOM-free. Returns the CSV string, BOM included.
- `create({ store, focus, config, document, panel, toggle, announce })`:
  builds the two tables into `panel` and re-renders them from
  `focus.scene()`. It returns `{ render, isOpen, setOpen }`. The download
  uses a `Blob` and a temporary `<a download>`. Keep that inside a function
  that the tests do not call, since the stub has no `Blob` or `URL`.
- Add `'list'` to the module list in `inc/enqueue.php` after `'card'`.
  `scripts/preview-network-map.py` reads the same list, so the preview picks
  it up.

**Template (`templates/page-network-map.php`)**

- In the toolbar actions, after Copy link: a button
  `#kop-network-list-toggle` with `aria-pressed="false"` and the text
  "List". On a phone the toolbar must stay one row: check at 390 px, and
  shorten the Connect label further if needed.
- Inside `.kop-network__stage`, after the canvas: a hidden
  `<section class="kop-network__list" id="kop-network-list"
  aria-label="The map as a list">`. Leave it empty; `list.js` fills it.
- Explain the switch in a PHP comment above it, in the file's style.

**app.js**

- Create the list after the drawer, with `announce`.
- The toggle: flip `aria-pressed`, show or hide the panel, hide the canvas
  and the canvas-only stage controls, and move focus into the panel (its
  first heading) when opening and back to the toggle when closing.
  Announce "The map as a list: N names, M connections."
- Call `list.render()` from the `focus` `onChange` handler when the list is
  open (next to `syncBoardControls`).
- Escape inside the list closes the list, not the trail.

**url-state.js**

- `list=1`, parsed and formatted the same way as `simple`. `read()` opens
  the list after restoring the trail. The app needs a hook: pass
  `options.onList(open)` into `KOPNetworkUrlState.create`, and give
  `write()` an `options.listOpen()`.

**CSS**

- The panel fills the stage (`position: absolute; inset: 0;` inside the
  stage) with its own scroll, a white background, and the tables at 100%
  width. The Names table scrolls sideways on a phone inside the panel, not
  the page.
- Sticky header row. The sort buttons look like text, with a small
  up/down marker drawn in CSS (no icon font, no emoji).
- Text never sits on the gradient: the panel is solid.

#### Tests

Add a block to `scripts/test-network-modules.js`, and add
`js/network-map/list.js` to its `files` list after `card.js`.

1. `rows()` on Provo Canyon School's board gives one name row per name on
   the scene plus one per folded person, and every connection row joins two
   names in that set.
2. The counts in the rows match the scene: "Not on the board" equals
   `scene.hidden[id]` or 0.
3. A folded person appears with "(drawn on a line)" and has a row in
   Connections for each place they join.
4. Profile links only where `profileFor(...).own` is true. No href
   anywhere contains `/tti-program-index/`.
5. `sortRows` by name ascending and descending, by a number column
   (numeric, not text: 10 after 9), and stable for ties.
6. `toCsv`: the BOM is present; a name with a comma, a quote and a line
   break survives a round trip through a small CSV parser written in the
   test; `=HYPERLINK(...)` comes out prefixed with an apostrophe; lines end
   CRLF.
7. `create()` with the stub DOM: the tables render, the header buttons set
   `aria-sort`, and clicking a Name button calls `focus.select` for that
   node and leaves the list open.
8. With Simplify on, the list has the same names as the simplified board.
   After Show all, it has the new names.
9. `url-state`: `list=1` round-trips.

#### Check it in a browser

At 1440x900 and 390x780, through the preview:

- Open Provo Canyon School and switch to List. Both tables show. Sort each
  column both ways. Download both CSVs (Playwright `expect_download`), then
  open them in Python's `csv` module and check the row counts against the
  scene.
- Tab through the list: the toggle, the tables' sort buttons, the name
  buttons, the profile links, the download buttons. Focus is visible on
  each.
- Turn on Simplify and press Show all with the list open; it follows.
- Reload with `#open=provo-canyon-school&list=1`; the list is open.
- The page does not scroll sideways at 390 px.
- Run `python scripts/check-bare-text.py` after deploying (it checks the
  live site).

#### Done when

- Every test passes, including the new block.
- It works in the browser at both widths, and the CSVs open cleanly.
- `docs/NETWORK-MAP.md`: replace the "List view" line under Phase 3 "Still
  outlined" with a "### List view (date)" section in the style of "Paths
  between two names", saying what was built and why the choices were made.
- Deployed and checked on the live page.
