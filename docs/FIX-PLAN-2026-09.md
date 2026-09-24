# Fix plan, September 2026

The fix list raised on 2026-09-17 and what became of each item, then four
issues the owner raised on 2026-09-18 (items 11 to 14), then a
reading-experience brief of 2026-09-22 (items 15 to 19). Everything from
the first list is live on kidsoverprofits.org except the network map's
last steps; the second list is open, and the third has not been started.

Last updated 2026-09-23.

## Status

| # | Item | State |
|---|---|---|
| 1 | Network map | Phase 2b and Paths done; ten reader suggestions of 2026-09-22 open; see [section 1](#1-network-map) |
| 2A | Research: relevance tiers and sort | Done; tiers waiting on the owner |
| 2B | Research: tag facilities mentioned | Done; tag proposals waiting on the owner |
| 3A | Form: help line on every collapsed panel | Done |
| 3B | Form: tooltips for more fields | Done |
| 3C | Tutorial button over the search button | Done |
| 4 | Monitor menu: inspection reports hub | Done; menu entry waiting on the owner |
| 5 | Resources page | Done; new entries waiting on the owner |
| 6 | Archived links for facility websites | Done for facility pages and hub cards; the rest is item 11 |
| 7A | State pages: alternate names | Rendering done; missing names are item 12 |
| 7B | State pages: PDF previews | Done; regeneration waiting on the owner |
| 8 | Facility links must not land on the program index | Done |
| 9 | Newsletter sign-up top padding | Done |
| 10 | Email notifications for new submissions | Done |
| 11 | Facility websites: Wayback and/or donotlink everywhere | Done: Wayback first, live site only through /go/ |
| 12 | State pages: alternate names missing | Done in code; filling the 4,334 records with no alternate name is research |
| 13 | Featured inspections not displaying | Done: both featured reports show on the home page and the hub |
| 14 | Parser that flags the worst inspection findings | Steps 1 to 5 built for Texas and California; the scan and the review are the owner's; other states and the nightly run still open |
| 15 | Long-form articles: orientation and structure | Code done; 15B to 15E are editorial now - the pieces exist (19) and the text goes in the page, not in a store, see [section 15](#15-long-form-articles-orientation-and-structure) |
| 16 | Spacing, typography and colour contrast | Done: 16B ink tokens with a test; 16A checked at 390px and already sound. The admin stylesheets are still accent-as-text |
| 17 | Loading skeletons for the JSON-driven pages | Done 2026-09-23; nine sections across five templates |
| 18 | Advocacy History, Corporatization, Lawsuits, Survivors | Open; editorial, once 15 and 19 exist. The brief's fourth page is its first |
| 19 | Reusable article pieces | Done 2026-09-23: five pieces and five shortcodes in `inc/article-pieces.php` |
| 20 | The staging copy, and what still points at it | Content clean as of 2026-09-23 bar one pingback comment; the hosting lock-down is the owner's |

## Waiting on the owner

Each of these has to be run in a browser while logged in as an admin, so no
session can do it. The tools that change data show a dry run first; add
`?apply=1` to act.

1. **PDF covers (7B).**
   [regenerate-pdf-previews.php](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php),
   then `?apply=1` repeatedly until it reports 0 remaining. Renders the
   first page of the 153 PDFs that have none.
2. **Inspection reports in the Monitor menu (4).**
   [rebuild-header-menu.php](https://kidsoverprofits.org/wp-content/themes/child/api/rebuild-header-menu.php),
   then `?apply=1`.
3. **Featured inspection reports (4).** Run
   [update-schema.php](https://kidsoverprofits.org/wp-content/themes/child/api/update-schema.php)
   first (it has no dry run; on production it only adds the `featured` and
   `featured_note` columns), then feature reports in
   [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php).
   Done by the owner on 2026-09-18 (two reports featured); see item 13 for
   why they did not appear at first. Each still needs a `featured_note`.
4. **Research facility tags (2B).**
   [propose-research-facility-tags.php](https://kidsoverprofits.org/wp-content/themes/child/api/propose-research-facility-tags.php)
   writes `kop-research-tag-proposals.json` to the uploads directory. Prune
   it, then run `?apply=1`.
5. **Research relevance tiers (2A).** Rate documents in the
   [research library](https://kidsoverprofits.org/researchreports/) editor.
   Until something is rated the page keeps its old order.
6. **Proposed resources (5).** While logged in,
   [/resources/](https://kidsoverprofits.org/resources/) shows a dashed block
   of proposed crisis lines, reporting routes and family resources that
   visitors cannot see. Check every number, then move the ones to publish
   into `kop_resources_groups()` in `inc/resources-list.php`.
7. **Starter views for the map (1).** Done 2026-09-18: four views are in
   `js/data/network/network-overrides.json` (Historical, Today's top
   players, Wilderness, Fundamentalist). Edit that file to change or add
   one.
8. **Inspection highlights (14).** Open
   [scan-inspection-highlights.php](https://kidsoverprofits.org/wp-content/themes/child/api/scan-inspection-highlights.php)
   for the dry run, then `?apply=1` repeatedly until it reports 0 remaining
   (about 14 loads). It only adds two new tables. Then review the queue in
   [review-inspection-highlights.php](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php).
   The first batch was saved on 2026-09-21 (3,000 reports, 250 candidates);
   about 13 more loads finish it. An approved finding scoring 70 or more
   appears on the home page and the inspection reports hub, most recent
   first, so the review screen opens on the most recent severe candidates.
   All of them are listed on [/severe-reports/](https://kidsoverprofits.org/severe-reports/)
   and flagged in the state trackers. Re-run
   [rebuild-header-menu.php](https://kidsoverprofits.org/wp-content/themes/child/api/rebuild-header-menu.php)
   with `?apply=1` to put Severe Reports in the Monitor menu.

9. **Staging links (20).** Done by the owner on 2026-09-23: the fixer was
   run and the memorial records, the OG Image snippet and the Hyde images are
   all on the live site now. One thing it does not reach: comment 453 on
   [/hyde/](https://kidsoverprofits.org/hyde/) is a pingback from the staging
   copy of the Fuller page, so it still prints a /staging/ link under the
   post. Delete it in Comments; it is a clone pinging the original.
## Working in this repository

Several sessions share the checkout at `C:\Users\daniu\source\repos\Kids-Over-Profits`
and push to main from scratchpad worktrees, because main is checked out in
another worktree. The shared checkout's branch therefore falls behind main,
and VS Code's Source Control fills with files that are already on main. On
2026-09-18 it showed 43; all of them were on main. The safe cleanup: compare
each file to `origin/main`, `git reset --mixed origin/main` (moves the branch,
touches no file), refresh from main only the files that equal an older
committed version, and set the branch's upstream to `origin/main`. Never
`git reset --hard` or `git stash` here: another session may have live edits
in the tree.

---

## 1. Network map

Tracked in detail in `docs/NETWORK-MAP.md` under "Phase 2b: the fix list of
2026-09-17".

Done on 2026-09-18: every name legible and nodes with unopened connections
marked with a count (the owner's decision: an owner still brings only
itself, but nothing is hidden silently); search, the drawer and links that
reopen a view; reset view and click zoom; rebranded drawn apart from closed,
years of operation, deaths; connections from facility profiles; staff
movement from the profiles, reviewed, as edges; the owner's staff list;
people the board leaves out; ownership, staff, family and membership
connections shown by default; starter views, with four lists (Historical,
Today's top players, Wilderness, Fundamentalist); a draft Network Map page
behind a password.

Done since: 2b.9 toolbar and trail compaction (2026-09-21), Paths between
two names (2026-09-21), the Sequel/TSI/YSI/Vivant chain from the owner's
sheet (2026-09-21), the board's own look with straight lines and clusters
round the click (2026-09-22), the zoom controls and the fuller legend
(2026-09-22). The page is public at
https://kidsoverprofits.org/network-map/.

Still open, in build order (each is itemised as 2d.n under "Phase 2d" in
`docs/NETWORK-MAP.md`, with what the map already does against each):

1. **Zoom controls (2d.10) and a fuller legend (2d.9).** Done 2026-09-22:
   plus, minus and Fit to screen on the stage under Reset view, with a hint
   the first time the reader's own zoom leaves a name off the stage; the
   Key gained the arrowhead row, the people-on-a-line circle, a two-line
   "How to read this", and opens itself on a first visit. Answers
   suggestions 3, 9 and 10, less the kind rows, which wait on 2d.2.
2. **Hover cards (2d.5).** Kind, status and years, state, deaths and
   connection count on hover, from the drawer's own profile; not on touch.
   Next.
3. **Open on one major cluster (2d.1).** Done 2026-09-22: the map opens on
   Universal Health Services already opened, 30 names, exactly what a click
   on it shows, with no trail. The six-name "largest networks" list is
   gone; the five curated lists remain under Start from.
4. **Simplify (2d.7) and Show all connections (2d.8).** A toolbar toggle
   that folds leaves into pills and drops staff, family and referral lines;
   a drawer and toolbar action that opens every pill in view, guarded by
   the legibility floor. Settles the last open decision of Phase 2.
5. **Highlight a route on the board in view (2d.6).** A found path lit on
   the current board, and "Route to this from ..." in the drawer.
6. **Kind marks (2d.2).** A non-colour mark for company, trade group and
   program. Waiting on the owner: it departs from the board's key.
7. **Chain hulls and group-by-network (2d.4).** The one that costs real
   time; can slip to Phase 3.

And from the first list:

- **2b.1 David Gilcrease.** The data holds all five of his connections. The
  check on the deployed build with the cross-group toggle off, and a module
  test asserting all five, are still to do.

---

## 2. Research & Reports (`/researchreports/`)

Built by `inc/research-library.php` from FileBird folders 27 (Academic)
and 25 (Government).

**2A. Relevance.** Done. A document carries a tier (1 "Start here",
2 "Important", 3 "Background", from `kop_research_relevance_tiers()`) and a
one-line "why it matters", both editorial and both optional: attachment
meta `kop_research_relevance` and `kop_research_relevance_note`, or the
`relevance` / `relevance_note` keys of the seed override map, or the
external overrides option for the four entries with no file. The card face
shows the tier as a pill above the kind line and the line under the byline;
the editor dialog gained a tier select and a one-line field, and
`kop/v1/research-entry` takes both (unrated or an empty line deletes the
meta, so a seed value comes back).

The page's default order is "most relevant": tier, then newest, then
title, with unrated last, so the grid reads exactly as it did before
anything is rated. The sort control offers Most relevant / Newest / A to Z;
`js/research-library.js` reorders the cards in place and unhides the
control, so a visitor without JavaScript sees the PHP order and no dead
select. Nothing is seeded with a tier: which documents matter most is the
owner's call.

Tests: `scripts/test-research-library.php` (including that an unrated
library keeps the old order) and `scripts/test-research-sort.js` (the
JavaScript order matches the PHP one).

**2B. Tag facilities mentioned.** Done. A document carries the
`facilities_v2` ids it is about as `kop_research_facilities`, one meta row
per facility, so a facility page finds its research with a meta query
(`kop_facility_pages_research()`). Cards show the programs as chips linking
to `/facility/<slug>/`, falling back to the location index; an id with no
row in `facilities_v2` is dropped rather than linked nowhere. The facility
page's Documents section gained "Research that mentions this program".

The editor dialog gained a picker: the stored tags as removable chips, plus
a search box on `GET kop/v1/research-facilities` (editors only).

`api/propose-research-facility-tags.php` proposes tags for the existing
library from each document's title, description, byline and extracted PDF
text. A proposal run saves nothing; `?apply=1` saves exactly what the
pruned proposals file holds, merged with hand-set tags. Matching is timid
on purpose: two words and ten characters minimum, generic program phrases
excluded, a name two facilities share dropped, and the longer name wins
where one sits inside another. Against the production mirror, 4,648 names
are indexed and 40 KB of industry prose proposes nothing.

Tests: `scripts/test-research-library.php` and
`scripts/test-research-tag-proposals.php`.

---

## 3. Submission form

**3A. Help line on every collapsed panel.** Done. The 25 lines live in
`inc/form-help.php`, not in the markup: the public form
(`templates/data-form-public.php`) collapses these panels and the admin form
(`templates/data-form-admin.php`) lays them out flat, so one list keeps the
two from drifting. Each line sits between the panel header and its content,
so it reads while the panel is still closed. Nine of the panels exist in
the admin form and reuse the same wording. Styles in `css/data-form.css`.

**3B. Tooltips for more fields.** Done. `js/field-tooltips.js` covers the
materials-on-file checkboxes, treatment types, philosophies, critical
incidents, and the address, operating date and licensing fields: 95 keys,
up from 54. The priority was jargon a submitter cannot be expected to know,
so LGAT, Positive Peer Culture, Therapeutic Community, hotseat groups,
rebirthing, attachment therapy and Law of Attraction now say what they are.

`scripts/test-form-help.js` reads the templates and asserts against them.
Writing it found three faults: the duplicate `operator-notes` the list
already knew about; five more keys defined twice, so a duplicate won or
lost by position; and five (`capacity`, `current-census`, `min-age`,
`max-age`, `gender`) keyed to field ids that exist in neither form and had
never rendered. Those fields are now reached by label text.

**3C. Tutorial button over the search button.** Done. The stylesheet set
`left` against the button's inline `right`, and CSS drops `right` when both
are set, so the button landed bottom-left on top of the site-wide search
pill. `css/tutorial-overlay.css` now sets `right` only.

---

## 4. Inspection reports hub

Done. [/inspection-reports/](https://kidsoverprofits.org/inspection-reports/)
(`templates/page-inspection-reports.php`): the state grid with a report
count on each button, live report and facility totals, the curated featured
reports, and a note for visitors whose state is missing. The page's own
editor content, if any, prints above the grid.

The state list was hard-coded in `templates/page-home.php` and kept a second
time as prose naming all fourteen states. Both now come from
`kop_report_state_links()` and `kop_report_state_sentence()` in
`inc/utilities.php`, derived from `kop_state_inspection_page_map()` in
`inc/rest-api.php`, so a new state is added in one place. The home page grid
is alphabetical as a result and links to the hub.

The page is created by `kop_tool_page_specs()` on `init` as well as
`admin_init`, so it exists without an admin opening wp-admin. It is in the
Monitor list in `api/rebuild-header-menu.php`, which still has to be re-run
(owner item 2).

Found on the way:

- `inspection_facilities.state` holds the two-letter code, not the state
  name, so the per-state counts key on the tracker slug's prefix.
- Production's `inspection_reports` had no `featured` or `featured_note`
  column, so the home page's featured block had been running a failing
  query on every load, hidden by `suppress_errors`. Those columns come from
  `api/update-schema.php`, not from the featured tool, which refuses to load
  until the schema update has run. Both pages now check for the column
  before querying, and remember a missing column for ten minutes only, so
  the block appears soon after the update (owner item 3).

---

## 5. Resources page

Done. [/resources/](https://kidsoverprofits.org/resources/) is built from
`inc/resources-list.php`: `kop_resources_groups()` is the published list,
ported from the page as it stood in September 2026 and regrouped by need
(taking legal action, survivor support, advocacy organisations, research
and reading, art and film, survivors of individual programs, survivors
telling their own stories, petitions). The Survivor's Guide link pointed at
a `/staging/` URL and now resolves from its slug; SCIAD, down since
November 2024, links to its snapshot and is labelled as one.
`kop_resources_strip_legacy_lists()` hides the old hand-built groups in the
page content so nothing prints twice; nothing is deleted in the editor.

`kop_resources_proposed()` holds the three needs the page never covered (a
crisis line, how to report abuse, where a family mid-placement can turn)
and renders only for a user who can edit the page, in a block that says it
is not published (owner item 6).

Changed after owner review, 2026-09-18:

- **Survivors Unrestrained meeting schedule.** The rebuild described the
  group only as peer support. The entry now carries the Zoom support groups
  (Tuesdays and Thursdays, 7 PM Eastern, survivors only) and both links,
  as the For Survivors page always has. The links there go through
  Facebook's click tracker; the list uses the plain Zoom addresses. Entries
  take an optional `links` field for this.
- **Layout.** The cards sat in a grid whose rows took the tallest card's
  height, leaving large gaps beside every short card. They now flow in CSS
  columns.
- **Grouping.** The original page kept group accounts run by each
  program's survivors (Instagram) apart from individual survivors telling
  their own stories (TikTok); the rebuild had merged them. The individuals
  are a card of their own again, and two programs listed once per platform
  are one entry each.

Tests: `scripts/test-resources-list.php`.

---

## 6. Archived links for facility websites

Done. `kop_facility_pages_archive_link()` in `inc/facility-pages.php`
rewrites a program's own website to `https://web.archive.org/web/<url>`,
which Wayback resolves to its newest snapshot, and keeps the original as a
small "live site" link with `rel="nofollow noreferrer noopener"`.
`kop_state_archive_profile_links()` in `inc/rest-api.php` applies the same
rule to the state and country hub cards, which show the snapshot only.
`kop_facility_pages_archive_exempt_host()` lists the hosts that keep a live
link: the archives, this site, Reddit, Wikipedia, survivor and advocacy
sites, social networks and public records.

`templates/single-facility-profile.php` is unchanged on purpose: its
external link is the survivor community page, which has to stay live.

**Needs a decision:** the facility panel on the `/xx-reports/` pages
(`js/inspections/facilities-display.js`) still links live, because it reads
`profileLinks` straight from the shared `kop/v1/facilities` feed that the
location index, the program index and the discovery scripts also read.
Fixing it means duplicating the host rule in JavaScript or changing that
feed for every consumer.

---

## 7. State pages

**7A. Alternate names.** Done. The card face carries "Also known as" and
"Formerly" under the facility name in `js/state-page.js` and
`js/country-page.js`, taking every key spelling and entry shape the data
uses and dropping repeats of a name already on the card. 316 facilities
carry one of those lists.

**7B. PDF previews.** Done, with a different cause than the list assumed.
2,154 of 2,313 PDFs already had working previews. The 153 that fail came
from the May 2026 media restore with metadata holding only a filesize, so
WordPress never rendered their first page; only 7 had a sidecar image, so
reusing the research library's sidecar lookup would not have helped.
`api/regenerate-pdf-previews.php` renders them in batches with the same call
an upload makes (owner item 1), and reports PDFs whose file is missing from
disk. The state and country document grids also accept `thumbnail_url`.

---

## 8. Facility links must not land on the program index

Done. The program index lists operators and chains only, so a facility
sent there found nothing. `kop_facility_pages_location_search_url()` is the
facility fallback, and `js/location-index.js` reads `?search=` and opens
the matching places. Fixed in the thin-record redirect, the facility page's
"Database record" link, the network map, the lawsuits page, the story-arc
"learn more" button, the global search and the Ajax Search Lite results.
Operators still go to the program index.

---

## 9. Newsletter sign-up top padding

Done. `css/sidebar.css`, one rule on `.primary-sidebar .ml-embedded` (the
live markup is a bare `.ml-embedded` div that MailerLite's script fills),
loaded whenever the primary sidebar is active.

---

## 10. Email notifications for new submissions

Done. `inc/submission-notify.php` holds
`kop_notify_admins($type, $title, $admin_url, $fields)`, built on the
bug-report mailer. Recipients come from the `kop_submission_notify_emails`
option (default `admin_email`). Every submitted value is stripped of tags,
collapsed to one line and truncated, so a form cannot relay markup through
the mail. The review link is resolved per type.

Calls at `api/save-suggestion.php`, `api/save-wiki-submission.php` (new rows
only, not admin-authored), `api/save-news-submission.php`,
`api/save-lawsuit-suggestion.php`, `api/save-legislation-suggestion.php`
and the anonymous document portal in `inc/features.php`, whose mail carries
only the submission id, file type and size.

Two findings changed the plan. Volunteer sign-ups have no insert to hook:
that form is external, and the one insert into `volunteer_projects` is an
admin creating a project. And news cannot be told apart by call site,
because the nightly discovery run posts to the same public endpoint a
person uses; the endpoint uses `submitted_by` and files bot finds under a
`news_auto` type that `KOP_SUBMISSION_DIGEST_TYPES` sends as one 7 AM daily
digest.

Tests: `scripts/test-submission-notify.php`, offline against WP stubs, no
mail sent.

---

# Raised 2026-09-18

## 11. Facility websites: Wayback and/or donotlink everywhere

**Outcome (2026-09-21).** The owner chose Wayback plus a secondary live link,
for programs, operators, referrers and transporters alike. donotlink turned
out to be defunct (donotlink.io does not resolve; donotlink.it and .com are
parked domains for sale, so anyone could buy one and redirect every link we
had pointed there), so the live link goes through our own endpoint instead:

- `/go/?u=<url>` (`kop_program_go_route()` in `inc/facility-pages.php`)
  answers `X-Robots-Tag: noindex, nofollow` and `Referrer-Policy:
  no-referrer`, forwards with a 302, and is disallowed in robots.txt. It
  forwards only to hosts that appear somewhere in our facility, operator,
  referrer or transporter records (cached six hours, rebuilt once on a miss),
  so it cannot be used as an open redirect.
- `js/shared/program-links.js` (`KOP.programLinks.html`) is the one renderer:
  archived copy first, then a small "live site" link through `/go/`; exempt
  hosts keep a plain link. The exempt list comes from
  `kop_facility_pages_archive_exempt_domains()`, so PHP and JS share it.
- Wired into the program index (operator websites and URL lists), the
  location index, the referrer index, the transporter index (not yet
  published) and the legacy tracker panel (`facilities-display.js`, which no
  live page loads). The facility pages' secondary link now uses `go_url`.
  The live `/xx-reports/` trackers run on `report-page.js`, which prints no
  program websites, so the plan's third page type needed no change.
- The feed data is unchanged: the article-discovery cron reads operator
  websites and profile links from `kop/v1/facilities` to learn each
  program's own domain, so rewriting them there would have broken it.
- Tests: `scripts/test-program-links.js` (helper cases, plus every link in a
  saved feed with `--feed`) and `scripts/test-program-links.php` (/go/ against
  a prod SQLite mirror: forwards a real program site, refuses other hosts,
  `javascript:`, protocol-relative and look-alike URLs).

The original plan follows.


Item 6 covers the generated facility pages and the state and country hub
cards, but a program's own website still reaches a visitor as a live link
in three places. Checked on the live site, 2026-09-18:

- **Generated facility pages** (`templates/facility-page.php`). The primary
  link is the Wayback copy, but item 6 added a secondary "live site" link
  beside it (for example Discovery Ranch South links
  `discoveryranchforgirls.com` directly). It carries
  `rel="nofollow noreferrer noopener"`, so it passes no search ranking, but
  it is still a click through to the program.
- **TTI program index** (`js/tti-program-index.js`, around lines 976 and
  1280): operator websites and facility `profileLinks`, both live.
- **State inspection trackers**, the facility panel on each `/xx-reports/`
  page (`js/inspections/facilities-display.js`, lines 346 and 425):
  operator websites and the field labelled "Archived Website", both live
  whatever the URL is.

The 14 hand-written profiles (`single-facility-profile.php`) link no program
site; their outbound links are news, survivor and archive pages. The
referrer and transporter indexes link consultants' and transport companies'
own sites live too; whether those fall under this rule is a decision.

Plan:

1. Decide the rule for the live link: drop it everywhere (Wayback only), or
   keep a secondary link routed through donotlink.io, which strips the
   referrer and passes no ranking. Wayback stays the primary link either
   way, because it preserves what the program said at the time.
2. Apply the rule once, server side. `kop/v1/facilities` and the operator
   feed already pass through PHP, so rewrite `profileLinks` and operator
   `websites` there with `kop_facility_pages_archive_link()` and its exempt
   list, rather than copying the host rule into three scripts. The location
   index, the program index and the discovery scripts all read this feed,
   so check first that the discovery scripts do not depend on the live URL
   (they may match on the domain).
3. Remove or reroute the secondary "live site" link on facility pages to
   match the rule.
4. Test with a harness over the feed asserting that no non-exempt host
   leaves it unarchived, and keep the crawl used for the check above as a
   script that lists outbound hosts on a sample of each page type.

## 12. State pages: alternate names missing

Item 7A's rendering works: on `/utah/`, all 29 facilities whose data carries
an alternate name show it on the card. The problem is what the page is
given. Checked 2026-09-18:

- The state feed (`kop/v1/state/<state>`) passes fewer names than the
  facility database holds: 29 Utah facilities have names in the feed against
  34 in `facilities_v2`, and 14 in Texas against 26. The hub reads the state
  aggregate first (one of the three copies of each facility's data), so a
  name added to `facilities_v2` but not to that copy never reaches the card.
- Coverage is thin at the source: 435 of 4,679 `facilities_v2` records carry
  any alternate name (`identification.pastNames` 175,
  `identification.otherNames` 159, `identification.currentName` 111).
- A rebranded facility's `currentName` ("now known as") is not on the card
  face; it is only in the collapsed details panel.
- `provenance.sourceOperator.otherNames` (34 records) holds the operator's
  names, not the facility's, and must stay off the facility card.
- Inspection-only rows (from `inspection_facilities`) arrive with empty name
  lists. Some match a `facilities_v2` record that has names, so the feed
  should merge those in when the match is certain.

Outcome (2026-09-18), measured with `scripts/test-state-feed-names.php`
against a prod snapshot taken that day (4,670 `facilities_v2` records):

- The feed was not losing names on production. Since the location pages
  read v2, every one of the 326 records with an alternate name reaches its
  tile on all 51 state pages and every country page. The "34 against 29" count
  above counted `currentName` values that only repeat the facility's name:
  89 of the 111 do, and only 22 facilities have a real "now known as" name.
  The same double counting explains 435: the true figure is 325 before the
  2026-09-18 merges, 326 now.
- The visible gap was `currentName`. The card face now shows "Now known as"
  above "Also known as" and "Formerly" (`js/state-page.js`,
  `js/country-page.js`), and the Details panel no longer repeats it. 22 cards
  gain the line, the Three Springs and Brown Schools rebrands among them.
- The feeds (`kop_state_attach_v2_names()` in `inc/rest-api.php`, shared by
  the state and country routes) now merge `otherNames`, `pastNames` and
  `currentName` from `facilities_v2` into every tile that resolves to a v2
  record: by id, or by name when one record on the page has that name in the
  same city. Names listed only in `provenance.sourceOperator.otherNames` are
  stripped from the tile. On v2 this changes nothing today; on the legacy
  fallback it recovers 5 of 24 records, and the other 19 are records created
  after the legacy tables were frozen, which that path cannot show.
- No inspection-only tile matched a v2 record by name with certainty, so
  none gained names that way.
- Coverage gap: 4,334 of 4,663 records carry no alternate name at all
  (after the data-shape fixes and merges below; 331 now carry one).
  [alternate-names-gap-2026-09-18.csv](alternate-names-gap-2026-09-18.csv)
  lists them with city, state, status and facility page (1,551 have a page).
  Largest: California 599, Texas 402, North Carolina 309, Utah 243,
  Arizona 175. Filling it is research, not code.

Data-shape fixes found by the same scan (2026-09-18), applied through
`seeds/facility-records.json` (init step version 24; every entry was checked
offline to change only the fields it names):

- 235 records had a malformed `city` from the old import, which showed on
  the cards ("101 First Quality Dr, , Andersonville, TN"): a leading comma,
  a highway or county-road number, a suite, or a foreign postcode in the city.
  Each was re-split from its raw address; the number goes back to the street
  and postcodes to `zip`.
- Names: "Three Springs, Inc." and "Straight – Midwest, Inc." had been split
  on the comma into a separate "Inc." former name (Sequel TSI Kissimmee,
  Sequel TSI Sierra Vista, Pathway Family Center); "Copper Canyon Academy(";
  two names starting with a zero-width space; Chad Youth Enhancement Center
  listing its own name.
- Open with an end year, settled from public sources: SUWS of the Carolinas
  (closed May 2023) and Magnolia Mill School (closed May 2025) are Closed;
  Sedona Sky Academy is Suspended (temporary closure in 2025); Union Juvenile
  Residential Facility keeps Open and loses the end year (it runs as Redwood
  Youth Academy). New Beginnings Girls Academy is unchanged: no source for
  its closure.
- Filed under UNKNOWN, so on no state page: Camp E-Toh-Anee (NH), Lighthouse
  for Boys (TX), Canyon View Park (MT), Talisman Academy (NC), Pine View
  Christian Academy (MS), New Beginnings Maternity Home (UT) and Teen
  Challenge Girls (NV) now have a state. Six were copies of a located record
  and are merged with `api/merge-facility-duplicates.php` (pairs
  10196:9618, 10865:14155, 13931:14156, 100023:100024, 100046:100047,
  100072:100073). Still unplaced: New Directions Home for Boys (Three
  Springs), and "Aspen Education Group #2" (100001), an operator filed as a
  facility.

A second pass over the other fields (same day, init step version 26):

- "Additional source fields" on the cards printed two bookkeeping keys:
  `documentFolderId` ("Document Folder Id: 272", 28 cards) and
  `mergedFacilities` (15 cards, written by the duplicate merges). Both are
  now in `SOURCE_KEYS_ALREADY_SHOWN` in `js/state-page.js` and
  `js/country-page.js`.
- Years: the card preferred the free-text `yearsOfOperation` over
  `startYear`/`endYear`, the facility pages the reverse, so 17 facilities
  showed different years in the two places. The feed now uses the pair when
  both years are set (as the facility pages do) and the text otherwise. The
  records whose text and years disagree still need checking against sources:
  New Dominion School of Virginia, Withlacoochee JRF, CEDU Middle School,
  Elevations RTC, Marion Youth Academy, Bartow Youth Training Center,
  Lexington Academy, Auldern Academy, Three Springs of Englishton Park,
  Three Springs Paint Rock Valley, Three Springs School of Madison, Oakley
  School, New Leaf Academy of Oregon.
- Gender spelled eleven ways; 81 records now read Male, Female or Co-ed
  ("All" counted as Co-ed). Three descriptive values were left as written.
- Zero-width spaces in 14 addresses (12 Kentucky state facilities) removed.
- 41 records carried blank staff entries (`{"name": "", "role": ""}`); the
  cards already skipped them, and they are now removed.

Run the check again after data work:

```
php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=6144M \
    scripts/test-state-feed-names.php --db=tmp/prod.sqlite [--legacy] [--gap=<file.csv>]
```

Original plan:


1. In the state and country feeds (`inc/rest-api.php`), take `otherNames`,
   `pastNames` and `currentName` from `facilities_v2` for every row that
   resolves to a v2 id, merged with whatever the aggregate carries.
2. Show "Now known as" on the card face beside "Also known as" and
   "Formerly" (`js/state-page.js`, `js/country-page.js`).
3. Report the coverage gap separately, listing the facilities that have no
   alternate names, so the owner can see the scale of the data work.
   Filling it is research rather than code: rebrands and former names come
   from news, filings and survivor accounts.
4. Test with the offline REST harness against prod dumps: for each state,
   the facilities with names in v2 are the facilities with names in the
   feed.

## 13. Featured inspections not displaying

Two reports are marked featured in production, both for UHS of Provo
Canyon (the Provo and Springville campuses, reports of 19 and 2 June 2026),
and neither showed.

Cause: before `api/update-schema.php` ran, the home page and the inspection
hub cached "the featured column does not exist" for a full day, at 05:23 on
2026-09-18. The later change that caches a missing column for ten minutes
only applied to entries saved after it, so the day-long "no" would have
stood until 05:23 on 2026-09-19 whatever the database said.

Fix, shipped 2026-09-18: both templates use a renamed cache key
(`kop_inspection_featured_column_v2`), so the stale entry is ignored and the
column is checked again on the next page load. Confirmed on 2026-09-18: the
[home page](https://kidsoverprofits.org/) and
[/inspection-reports/](https://kidsoverprofits.org/inspection-reports/) both
show the two Provo Canyon cards.

Also worth doing: neither featured report has a `featured_note`, so the cards
show the facility, state and date but no reason. Add a one-line note to each
in the featured tool.

## 14. Parser that flags the worst inspection findings

A pass over the inspection reports that finds the most serious findings,
pulls each one out with its quote and source, and queues it for review
before anything is highlighted on the site.

**Built 2026-09-21: extract, score, store and review, for Texas and
California** (41,971 of the 57,089 reports). Nothing is on the site yet and
nothing can be until a person approves it.

- `inc/inspection-highlights.php` holds all three steps and needs no
  WordPress. Texas: each row is one citation, scored from its deficiency
  narrative and scaled by HHSC's risk level (High 1.0 down to Low 0.4).
  California: a complaint investigation is one finding. The scraped
  `complaint_status` is wrong for about one report in ten (985 reports filed
  as unsubstantiated substantiate one allegation and not another, and 169
  more only say "substantiated"), so the outcome is read from the analyst's
  text: Substantiated 1.0, unsubstantiated never queued. A report that
  substantiates one allegation and not another is queued only for a
  sentence whose next verdict (within three sentences) is substantiated;
  an inconclusive one is not queued (owner rule 2026-09-21: all reports
  must be substantiated; scanner version 2). A facility evaluation counts
  only when its narrative cites a deficiency (0.7). Form boilerplate is
  cut off first.
- Two more gates from the same owner rule, scanner version 2: a sentence
  where the other party is a child ("by another child", "between the
  residents", "C1 ... with C2") is not physical or sexual abuse; and a
  finding whose only categories are missing and police is dropped unless
  the text records a serious injury (a death or a hospital visit is its
  own category and keeps it). Against the 2026-09-17 mirror this took the
  queue from 1,689 candidates to 988.
- **2026-09-22, scanner version 3: Utah, Arizona and Connecticut.** Utah
  keeps its findings in the report text, one line per rule cited
  ("R501-19-4(4): Supervision ... - The provider was out of compliance
  ..."); Arizona's deficiencies carry the surveyor's evidence statement
  and numbered findings (residents R1, employees E1); Connecticut's field
  visit form has an "Areas of regulatory non-compliance" section and its
  licensing letters list "the areas of non-compliance", split per
  regulation cited. None of the three attaches a severity, so a cited
  deficiency is trusted at 1.0 when the state was investigating a
  complaint or incident and 0.85 on a routine inspection
  (`kop_ih_citation_factor`). Arizona surveyors quote policies, statutes
  and intake histories at length, so a sentence-level noise rule
  (`kop_ih_noise_pattern`) now sets aside quoted policies and rules,
  instructions, training-topic lists, intake histories and events at an
  earlier placement, for every state. Also excluded: assaults by a child
  on staff, ligature hazards in the building, "deceased bird", "homicide
  risk". Mirror: Utah 49 candidates (36 severe), Arizona 188 (94),
  Connecticut 9 (0). Not built: Florida (its deficiency lists hold only
  the rule text; the narrative is in 68 KB PDF text), Washington (PDF
  columns interleaved by the scraper), Nevada (grade only), and the
  raw-text states NC, GA, AR, MN, OR.
- Scoring is per sentence, never per report. Nine categories with starter
  weights: death 100, sexual abuse 90, physical abuse or assault 80,
  restraint or seclusion with an injury named in the same sentence 75,
  suicide attempt or self-harm 65 (added to the plan's list), medical
  neglect 60, hospitalisation 55, child missing or ran away 45, police 40.
  A mention is dropped when a negation ("no", "denied", "unfounded") or a
  hypothetical ("could result in", "must report", "threatened", "hoped")
  stands within 60 characters before it. The score is the worst category's
  weight plus 5 for each further category (15 at most), times the state's
  factor; below 30 is not queued. The excerpt is the matching sentences
  verbatim, with `[...]` where text between them is left out.
- `inspection_highlights` keeps each candidate with its status, reviewer and
  note; `inspection_highlight_scans` records which reports this version of
  the rules has seen, so the nightly run reads only new reports, and bumping
  `kop_ih_scanner_version()` rescans everything. A re-run refreshes pending
  rows, withdraws pending rows the rules no longer produce, and never touches
  an approved or rejected one. `corrected_on_site` is stored, not scored,
  until that decision is made.
- The mirror holds 9,107 California reports twice for the same facility under
  two id schemes (`455002153-3` and `455002153-3-d92232c9bd`). The parser
  queues the finding once; the duplicate rows themselves are a scraper fault
  still to fix, and they inflate the report counts on the hub.
- Result against the mirror of 2026-09-17: 1,689 candidates (Texas 851,
  California 838) in 13 seconds; 113 score 90 or more, 348 score 70 to 89.
  By worst category: missing 498, physical abuse 311, sexual abuse 239,
  self-harm 202, medical neglect 183, hospitalisation 119, restraint injury
  53, police 49, death 35. The top of the queue is what it should be (a
  choking death while unsupervised, staff absconding with a minor, prone
  restraints with injuries); the known weakness is attribution, since a rule
  cannot tell a child assaulting staff from the reverse, which is what the
  review step is for.
- `api/scan-inspection-highlights.php` runs the scan (browser: dry run, then
  `?apply=1`, one batch per load; CLI: `apply` works through the backlog).
  `api/review-inspection-highlights.php` is the review screen: worst first,
  filters by state, category, score and facility, the full report under each
  excerpt, approve or reject with a note, and back to pending.
- Tests: `scripts/test-inspection-highlights.php` (45 rule cases including
  every false positive found so far, then a read-only dry run over the mirror;
  `--report=<file.md>` writes the top candidates per category and a random
  slice for reading). The store was also run twice against a throwaway
  MySQL 8: a second pass adds nothing and reviewed rows survive.

**The most recent severe reports are the ones highlighted** (owner,
2026-09-21). A finding scoring 70 or more (`kop_ih_severe_score()`) is
severe. Once approved it appears in the "demand attention" grid on the home
page (up to 6 cards with the hand-featured reports, which keep the front) and
on the inspection reports hub (up to 9), ordered by report date, newest first,
the worse finding leading on one day and undated findings last
(`kop_ih_recent_severe_sql()`, `kop_ih_site_highlights()`,
`kop_ih_render_cards()`). The card quotes the state's words, cut at 320
characters with the cut marked, names the category and the state's own label,
and links the state source and the tracker. The review note is never printed.
The review screen sorts the same way by default (severe first, newest first),
with "Worst first" as the other order, and marks each severe candidate.

Report dates are text in the reports table ("10/02/2023", "April 25, 2025",
"9/13/2023 - 9/14/2023", "3/23/25"), which cannot be sorted: the hand-featured
query's `ORDER BY report_date` is a string sort and puts December 2019 above
February 2026. Each highlight therefore carries `finding_date`, a real date
parsed by `kop_ih_parse_date()`. Production's table was created before the
column existed; `kop_ih_ensure_tables()` adds it and fills it, touching no
other field, the next time the scan or the review screen runs. Checked
against a throwaway MySQL 8 holding a table in the first shape.

**Every approved severe report has a page, and the trackers flag them**
(owner, 2026-09-21). The grids above show the newest few; two more places
carry all of them.

- [/severe-reports/](https://kidsoverprofits.org/severe-reports/)
  (`templates/page-severe-reports.php`, created by `kop_tool_page_specs()`,
  step version 6) lists every approved severe finding, most recent first, 50
  to a page, with filters for state and kind of harm. Each entry has the full
  quote, every category it matched, the state's label, the rule cited,
  whether the state recorded it as corrected at the inspection, the state
  source and the tracker, and an anchor (`#finding-<id>`) the other pages link
  to. The page says how a report gets there, that unsubstantiated complaints
  are not listed, and that absence from it says nothing about a facility. The
  home page and the hub link it under their grids ("See all N severe
  reports"), and it is in the Monitor list of `api/rebuild-header-menu.php`,
  which has to be re-run for the menu entry.
- In the regular feed, `js/inspections/severe-flags.js` marks the same
  reports on every `/xx-reports/` page: a "Severe finding" flag on the
  report's row, a count beside the facility's name, a note inside the opened
  report linking to its entry on the page above, and a banner over the list
  with a "Severe reports only" switch. The trackers read static JSON whose
  report ids are not the database's (California's are stored under two
  schemes), so a report is recognised by the state's own words:
  `api/inspection-highlights-read.php?state=XX` (public, approved severe
  findings only, no scores or notes) sends the opening of each quote,
  lower-cased with the spaces removed, and a report is that finding when its
  text contains it. A short opening must also sit under the same facility
  name; one of 60 characters or more is unique enough to match whatever the
  viewer calls the facility. The script reads the rendered page, so the state
  viewers were not touched, and it covers both markups (the Texas and
  California viewers and the shared `report-page.js` engine, whose lazily
  rendered reports are checked when opened).
- Checked in a real browser against the live Texas and California trackers
  with the unshipped script injected and the api answered from two reports on
  the page: the right two reports flagged, a short quote under another
  facility's name not flagged, the flags back after the viewer re-renders on
  search, the observer idle afterwards. The first version checked every
  report in 12 ms slices and took a minute on Texas's 11,559 reports, all of it
  the browser re-styling the page between slices; it now checks each
  facility's text once and opens its reports only on a hit: 1.3 seconds.
  Known limit: California's viewer renders a few facilities at a time, so
  "Severe reports only" filters what is on screen there, not the state.
- Tests: `scripts/test-severe-flags.js` (the matching rule) and the page's
  query, filters and paging in `scripts/test-inspection-highlights.php`.

Still to do: adapters for the other eleven states (Arizona, Connecticut,
Washington and Florida carry structured deficiencies; North Carolina,
Georgia, Arkansas, Minnesota and Oregon need the report text; Utah and
Nevada carry only counts and grades); a "What inspectors found" block on
the facility pages (the rest of step 5); the cron line (step 6).

What the data holds (production mirror, 57,089 reports in 13 states): no
report is stored in a common structure (`is_structured` is 0 everywhere).
Every report has `categories_json`, but each state's shape is different, and
the full text is in `raw_content`. Some states already carry a severity
signal:

- **Texas** (11,559 reports): a `Standard Risk Level` on every citation
  (High 2,143; Medium High 4,209; Medium 3,786; Medium Low 996; Low 425),
  with the standard violated and a deficiency narrative.
- **California** (30,412): complaint investigations with `allegations`
  (7,335 reports) and `investigation_findings`, where a substantiated
  finding can be told apart from an unsubstantiated one.
- **Utah** (2,942): a `Findings Count` (365 reports with findings).
  **Washington** (88): a `violation_count` (38 with violations).
- The other states need the text. A rough keyword pass over `raw_content`
  finds a death mentioned in 1,393 reports, sexual abuse or assault in
  1,438, hospitalisation in 1,155 and restraint with injury in 268. These
  are counts of mentions, not of incidents: a report can say "no deaths".

Plan:

1. **Extract** each state's findings into one shape: report id, facility,
   date, the finding's own text quoted verbatim, the standard cited, and
   any severity or substantiation the state recorded. One adapter per state
   shape, starting with Texas and California, which have the structure.
2. **Score** each finding. Use the state's own signal first (Texas High,
   California Substantiated), then look in the text for categories of harm:
   death, sexual abuse, restraint or seclusion causing injury, physical
   abuse by staff, medical neglect, hospitalisation, a child missing or run
   away, and police involvement. Exclude negations ("no injuries were
   found") and boilerplate sections (census, staffing counts), so the scorer
   matches on a finding's text, never on the whole report.
3. **Store** results in a new table (`inspection_highlights`: report id,
   facility id, category, score, excerpt, the state's severity label, a
   status of pending, approved or rejected, and who reviewed it), so a
   re-run adds new candidates without undoing a person's decision.
4. **Review before anything is published.** An admin screen lists pending
   highlights, worst first, each with its excerpt and a link to the source
   report; approving one publishes it. Nothing the parser guessed reaches
   the site unreviewed: a highlight names a facility and describes harm, so
   a person has to confirm the reading, and every published excerpt is the
   state's own words with a link to the report.
5. **Surface** approved highlights on the facility page (a "What inspectors
   found" block), the state tracker pages, the inspection hub, and the home
   page's featured block. That block can read approved highlights instead of
   the hand-set `featured` flag, or both.
6. **Run it** in the nightly pipeline after the scrapers, so new reports are
   scored as they arrive. Develop against the offline mirror from
   `scripts/sync-prod-sqlite.py`.

Open decisions: the scoring weights and the harm categories (a starter set
is above), whether a finding the facility corrected on the spot ranks lower,
and whether the page shows the Texas risk level or the site's own score.

---

# Raised 2026-09-22: reading the long pages

A list of site improvements arrived on 2026-09-22, written by another
assistant as a hand-off brief for a developer. It is reproduced below as
items 15 to 19, regrouped and checked against this theme. Nothing in it is
wrong about the reading experience; three of its assumptions about the
build needed correcting, and the items say what replaces them:

- **ACF is installed; the theme does not use it.** Corrected 2026-09-22:
  `advanced-custom-fields/acf.php` is active on production, though nothing
  in the theme calls `get_field()`. The brief assumed the editorial values
  below would be stored away from the article, in fields of some kind.

  **Settled by the owner on 2026-09-23: they are not stored anywhere new.**
  Not ACF, not post meta, not a table of their own. The summaries and the
  "Why this matters" text are written in the page, in the body, with the
  pieces from item 19 - which is also the only place the site's own search
  can see them. `inc/global-search.php` searches the SQL tables and the
  title and body of a WordPress page; it does not read post meta, and it
  would not read an ACF field either, so text kept in one would have been
  invisible to the search that every other kind of content on this site
  answers to. Nothing below needs a field group, an accessor or a table.
- **A custom post type is not needed and would cost URLs.** The 29
  timelines and case analyses are ordinary Pages already assigned
  `templates/page-article.php` by slug in `kop_template_assignments()`
  (`inc/admin.php`), and the 11 hubs are assigned `page-hub.php` the same
  way. That map is the ordering and grouping hook the brief wants a CPT
  for. Converting would mean migrating every page, every URL and every
  inbound link for no behaviour the map cannot give.
- **Yoast is installed and already prints a BreadcrumbList, a flat one.**
  Corrected 2026-09-22: every page carries "Home > this page" in Yoast's
  schema graph and nothing in between, because no page has a parent. The
  hierarchy still has to come from somewhere, but it should be handed to
  Yoast rather than printed again: two BreadcrumbLists on one page disagree
  with each other. Yoast draws no visible trail on this theme, so that part
  is the template's.

Some of the brief is already live and should not be rebuilt.
`templates/page-article.php` prints a reading time, an updated date, a
standfirst from the excerpt, a contents list (a native `<details>` block,
open by default) built from the article's own headings or its bold-only
marker paragraphs, and a corrections link. `css/article.css` sets a 72ch
measure at 1.7 line-height and `scroll-margin-top: 6rem` on headings, so
in-page anchors already clear the fixed header.

The pages the brief names are
[/advocacy-history/](https://kidsoverprofits.org/advocacy-history/),
[/corporatization/](https://kidsoverprofits.org/corporatization/),
[/lawsuits/](https://kidsoverprofits.org/lawsuits/). The fourth, "Survivors
& Families Fight Back", is the same page as the first (see 18D).

---

## 15. Long-form articles: orientation and structure

All of this lands in `templates/page-article.php`, `templates/page-hub.php`
and `css/article.css`. No bundler, no new plugin.

**15A. Breadcrumbs on every article and hub page.** Done 2026-09-22.
`inc/article-parts.php` holds `kop_article_parents()`, an ordered map of
page slug to the slug it is read under, and both templates print the trail
above the title (`css/trail.css`, loaded by each). The order of the map is
also the reading order, so 15G falls out of the same three lines.

The History branch is not guesswork: the hub links its four, and
`early-child-control` and `birth-of-the-tti` turned out to be index pages -
a page of links and nothing else - so each is a real step between the hub
and the timeline a reader lands on. The articles under the other four hubs
are placed by subject and are the owner's to move; nothing else changes
when one does. `scripts/test-article-parts.php` checks the map against
`kop_template_assignments()`, so an article that gains the reading template
and is never placed fails the test instead of reaching a reader with no
trail.

The machine-readable copy goes to Yoast through `wpseo_breadcrumb_links`,
not into a second `<script>` of our own.

**15B. Collapsible sections.** Wrap each h2 section of a long article in a
native `<details>`, no JavaScript. Two rules, or it costs more than it
buys: keep them **open by default** (a closed `<details>` is invisible to
the browser's find-in-page and to an anchor jump from the contents list),
and only apply it above a length threshold, so the short articles are
untouched. If a collapsed default is ever wanted, a `hashchange` handler
has to open the target section first. Candidates named in the brief:
Corporatization, Advocacy History, Straight Inc, WWASP.

**15C. Micro-summaries under each section header.** One sentence per
section, editorial, never generated - and written in the page, under the
heading it belongs to, per the decision above. No meta, no table: an editor
writes the sentence where a reader will see it, and the site's search finds
it because it is in the page.

**The code is done 2026-09-23; what is left is writing them.** A section
wrapped in the collapsible piece carries its sentence as the `summary`
attribute and always did. Everywhere else the sentence is a paragraph the
editor marks, either by giving the block the class `kop-article-summary` in
the editor sidebar or by typing `[kop_summary]`, and `css/article-pieces.css`
draws it as a quiet rule and lighter ink under the heading - not another
box, because three boxes in a column are louder than the article.

It has to be marked, and that is the one design decision here. A rule on
"the paragraph after a heading" needs no editor at all, and would restyle
the first line of the prose in every section that has no summary yet, which
today is all of them across 29 articles.

(The earlier plan here was post meta keyed by section id, and it was also
going to feed 15F its years. 15F shipped on 2026-09-23 without it - the
years were in the writing all along - so nothing else depends on that meta
existing.)

**15D. Colour-coded era tags.** CSS utility classes over `css/colors.css`,
one per era: nonprofit, for-profit, private equity, survivor advocacy. Two
constraints. The palette reserves the bright accents for borders and
highlights, not backgrounds, so an era reads as a rule or a chip outline,
not a block of colour. And colour must not be the only carrier of the
meaning (WCAG 1.4.1): the tag always prints its era name.

**15E. A "Why this matters" block.** The block itself shipped with item 19;
what is left is writing one per page and placing it. An editor puts
`[kop_why]` where it belongs in the article, usually straight under the
standfirst. Written for journalists, policymakers and parents.

The earlier plan had this in post meta so the template could print it in the
same place on every page without an editor thinking about it. Keeping it in
the body trades that guarantee for a block the search can actually find: the
position is now an editorial habit rather than something the template
enforces. Worth watching as the first few are written - if they drift about
the page, the template can hoist a `[kop_why]` block to a fixed position
without the text moving out of the body.

**15F. A horizontal timeline graphic.** Done 2026-09-23, and **the reason
this was written up as blocked was wrong**. The note said it needed a year
per section from 15C's meta, which nobody has filled in. The years were in
the writing all along, one level below the sections: every entry on these
pages is a list item whose bold run starts with a year, which is why the
contents list never saw them. Nine articles carry them - Juvenile Justice 76
entries across 1660 to 2023, Corporatization 75 across 1912 to 2024,
Fundamentalist Christian Homes 53 from 1517.

A band above the article draws one dot per entry at its year, over a ruler
whose step comes from the span (three centuries get half centuries, forty
years get decades, nothing gets more than thirteen marks). Each labelled
mark jumps to the first entry of its period. `kop_article_timeline_entries()`
reads the entries out of the rendered content and gives each one an id;
nothing is asked of an editor and nothing is written back to the post.

Positions are percentages rather than the SVG the brief asked for: a fixed
coordinate system would be standing in for what CSS already does at any
width. The dots are hidden from assistive technology and out of the tab
order - seventy-six tab stops in front of an article is an obstacle, not a
feature - so the labelled marks and the contents list are the route for
anybody not using a pointer.

`scripts/test-article-timeline.php` covers the year shapes these pages use
("1912 -", "1730s-1790s", "1179 CE", "1968:", "c. 1400") and holds the
parser to leaving the prose exactly as it found it. Three non-timeline
articles were checked too, and correctly get no band.

Worth knowing for anything else that relies on anchors: the Easy Table of
Contents plugin intercepts in-page anchor clicks and scrolls with its own
offset, so `scroll-margin-top` governs a link followed from outside the page
but not a click inside it. Nothing on these templates is fixed over the
content, so both land somewhere sensible.

**15G. "Continue to next section".** Done 2026-09-22. Previous and next
cards at the foot of each article, from a depth-first walk of its hub, so
the last timeline under one index page leads on to the next index page
rather than stopping. An article at either end of its hub prints only the
card it has, and one the map does not place prints neither.

**15H. Getting back to the contents from anywhere.** Done 2026-09-23, but
not as a sticky box. A small Contents button appears in the corner of the
window once the contents box has scrolled off, and opens a copy of the same
list; `js/article-toc.js` builds it from the list the template printed, so
the two cannot disagree. Below 1024px it stays away.

A sticky contents box was built first and it worked - it pinned under the
header, collapsed to its title bar, opened on hover. It was thrown away
because a sticky box is still part of the page: every time it changed size
it changed the height of the article and moved the text under the reader,
206 pixels on Spiritual Abuse, and a heading jumped to from the list ended
up off the top of the screen. A fixed panel cannot do that. Measured in
Chrome against the live articles: opening it moves the text by 0.0 pixels,
and a jumped-to heading lands in the reading area, clear of the button.

The constraint that ruled out a second column still stands:
`inc/template-layout.php` puts every child template inside Kadence's wrapper
with the site sidebar beside it, and that sidebar already holds search,
Givebutter and MailerLite.

**15I. Visible anchor links on headings.** Done 2026-09-23. Every heading
and every bold marker paragraph carries a link mark, hidden until the
heading is hovered and always reachable by keyboard, with the section's own
name in its label rather than "Link to this section" thirty times over. It
is dropped on touch screens, which have no hover to reveal it, and on an
article with too little structure for a contents box.
`scripts/test-article-sections.php` covers the heading path and the marker
path, and holds the function to leaving the prose exactly as it found it -
which matters here, because most of these articles have no headings at all:
advocacy-history, fundamentalist, war-on-drugs and the timelines are bold
marker paragraphs from end to end.

---

## 16. Spacing, typography and colour contrast

**16A. Mobile spacing and typography.** Checked 2026-09-23 and left alone.
Rendered at 390px in Chrome, the long-form pages already have what the brief
asks for: 1.7 line-height on running text, a 72ch measure, 2.25rem above
each marker paragraph with a rule over it, and `scroll-margin-top` clearing
the fixed header. Nothing measurable was wrong, so nothing was changed -
the accessibility problem on these pages was the colour contrast in 16B,
not the spacing.

**16B. Contrast, to WCAG AA.** Done 2026-09-23. `css/colors.css` gained an
ink version of each accent - same hue, same saturation, darkened until it
passes 4.5 on white, on sand and on the pastel yellow, so one value is safe
on any light ground the site uses:

| Token | Value | Worst light ground |
|---|---|---|
| `--kop-teal-ink` | `#24757F` | 4.60 on sand |
| `--kop-orange-ink` | `#A3570D` | 4.60 on sand |
| `--kop-coral-pink-ink` | `#D9020F` | 4.56 on sand |
| `--kop-chartreuse-ink` | `#5C7401` | 4.57 on sand |

Twenty-six declarations in the reading stylesheets had a display accent as
text and now use the ink; borders and fills keep the accent, and a rule with
a dark background of its own is left alone, since teal on midnight is
already 6.86. `scripts/test-colour-contrast.py` checks the arithmetic and
then reads the stylesheets back, so a new accent-as-text declaration fails a
test rather than shipping.

Still open: the admin stylesheets, which have about fifty more and are one
person's workbench rather than a page a visitor reads.

---

## 17. Loading skeletons for the JSON-driven pages

Done 2026-09-23. `kop_loading_skeleton()` in `inc/utilities.php` prints card
outlines the size of the real cards, and `css/skeleton.css` styles them.
Nine sections across five templates use it: the four sections of the state
and country hubs, the program index, the location index and the state
report pages. The words stay in a live region for anybody who cannot see a
shape, and the sweep stops under `prefers-reduced-motion`.

Every one of those containers has its innerHTML replaced wholesale when the
data lands, so no JavaScript changed and nothing has to clean up after the
skeleton.

Not the lawsuits page: `templates/page-lawsuits.php` renders its rows in PHP
and filters them client-side, so there is nothing to wait for.

---

## 18. The four pages named in the brief

Editorial work mostly, once 15 and 19 exist to hang it on.

**18A. Advocacy History.** An intro paragraph saying what the timeline is
for; a micro-summary per decade (15C); collapsible sections for Straight
Inc, WWASP and the GAO hearings (15B); era colours (15D); a "Key
milestones" list; and a "Submit your story" call to action at the foot,
pointing at the existing submission form.

**18B. Corporatization.** A collapsible section per corporation (15B); era
hierarchy (15D); a corporate-layering diagram as inline SVG; a "Key
concepts" glossary block; and, in place of the brief's "Corporate ownership
map" sidebar, a link into the live map at
[/network-map/](https://kidsoverprofits.org/network-map/) opened on the
chain the page is about - the map already takes a view in its URL, so this
is a link, not a new component.

**18C. Lawsuits and legal cases.** An intro explaining how to use the
database; a colour and label per case status; micro-copy under each filter;
grouping by facility, corporation or state; a "Recent updates" panel; and a
short block on how to submit a lawsuit, linking to
[/submit-lawsuit/](https://kidsoverprofits.org/submit-lawsuit/).

**18D. Survivors and Families Fight Back.** The brief lists this and
"Advocacy History" as two pages; they are one. The only page with that
title is [/advocacy-history/](https://kidsoverprofits.org/advocacy-history/),
whose title is "Survivors & Families Fight Back!" - so everything the brief
asks for here belongs to 18A, and the separate hub at
[/survivors/](https://kidsoverprofits.org/survivors/) ("For Survivors") was
not in the brief at all.

That hub could still use the same treatment: a thesis statement at the top,
a description under each link, an icon and colour per category, and a line
telling the reader they can read in order or jump ahead. `page-hub.php`
prints the editor's content unchanged and appends a module by slug, so the
descriptions are editor work and only the icons and colours need CSS.

---

## 19. Reusable article pieces

Four partials in `inc/`, each a function the templates call and, where an
editor needs to place one mid-article, a shortcode. No block build step:
the theme has no bundler and should not gain one.

1. **Era header.** Title, era colour tag, one-line summary, optional icon,
   anchor id. Used by 15C, 15D and 15F.
2. **Collapsible section.** Title, summary, body, optional sources. The
   `<details>` rules in 15B apply wherever it is used.
3. **Sources.** One citation format, auto-numbered, with the same markup on
   every page, so a reader learns it once.
4. **Why this matters.** The block from 15E, callable on hub pages too.

Suggested order of work: 19 first (the pieces), then 15A, 15B, 15E and 15G
(the structure), then 16 (a sweep that touches every page and is easier
once the new markup exists), then 15C, 15D, 15F and 15H, then 17, then 18.

**Done 2026-09-23.** `inc/article-pieces.php` holds all four, each as a
function that returns markup and a shortcode that calls the same builder, so
what an editor places and what a template prints cannot drift apart:
`[kop_era]`, `[kop_section]`, `[kop_sources]` and `[kop_why]`. A fifth,
`[kop_summary]`, was added the same day for 15C - the one sentence under a
heading that is not wrapped in a collapsible section.
`css/article-pieces.css` is loaded by both `page-article.php` and
`page-hub.php`. Covered by `scripts/test-article-pieces.php`.

Three decisions the pieces make, rather than leave to whoever places one:

- **A collapsible section has no closed state.** 15B's rule was "open by
  default"; the piece does not offer the alternative at all, because nothing
  in the theme opens a `<details>` on a hash change, and a closed one loses
  find-in-page and swallows a jump from the contents list. Asking for closed
  gives open, and the test says so.
- **The heading goes inside the `<summary>`.** That is where
  `kop_article_sections()` finds it, so a collapsible section reaches the
  contents list and the anchor marks from 15I like any other section.
- **An era is a name first.** The tag prints its era's name always; the
  colour is a class over it, one per era, in CSS over the tokens in
  `colors.css`, as a rule and a chip outline rather than a fill. An era
  nobody has written CSS for is written out in the neutral ink instead of
  being dropped (WCAG 1.4.1, and the palette's rule about bright accents).

What each dependent item still needs, now that the pieces exist: 15B, a pass
over the long articles deciding which sections are worth wrapping and above
what length; 15E, the writing, and `[kop_why]` placed where it belongs -
there is nothing left to build; 15C, the writing; 15D, nothing but placing
the tags. 18 is editorial throughout.

None of them is waiting on a decision any more: the owner settled the
storage question on 2026-09-23, and the answer was that this text belongs in
the page, where the search reaches it.

---

## 20. The staging copy of the site, and what still points at it

Raised 2026-09-22, and **the first version of this item was wrong**. It said
two links on [/birth-of-the-tti/](https://kidsoverprofits.org/birth-of-the-tti/)
pointed into the staging install. They did when `tmp/prod.sqlite` was taken on
2026-09-17; they had been fixed since - by
[fix-staging-links.php](https://kidsoverprofits.org/wp-content/themes/child/api/fix-staging-links.php),
which already existed and which the owner had run - and the rendered page has
linked to `/juvenile-justice-timeline/` all along. The claim came from reading
the mirror instead of the page. `scripts/check-staging-links.py` now reads
rendered pages for exactly that reason, and the mirror is used only to choose
which pages to fetch.

That tool cleaned post content, postmeta, attachments and the block menu: 45
pages down to 2. What it could not see was everything the site prints from
somewhere other than a post, which is where the last six links were hiding.

**Done 2026-09-22.**

- `inc/staging-links.php` adds `Disallow: /staging/` to robots.txt, on the
  same filter `inc/facility-pages.php` uses for `/go/`. Live and confirmed in
  [robots.txt](https://kidsoverprofits.org/robots.txt).
- `api/fix-staging-links.php` gained a third section, "Other tables the site
  prints from", covering `memorial_victims` (`kop_url`, `source_url`) and the
  Code Snippets table (`code`). Same rules as the rest of the tool: a preview
  with a tick per row, the plan recomputed from the live value on apply, and a
  URL is only rewritten when the live target is confirmed. Tested offline by
  `scripts/test-staging-link-fixer.php`.

**Done by the owner on 2026-09-23.** The tool was run and the six links are
gone from the live site. `scripts/check-staging-links.py` over 27 rendered
pages finds one thing left, and it is not something the tool can reach:
comment 453 on [/hyde/](https://kidsoverprofits.org/hyde/) is a pingback
from the staging copy of the Fuller page, so the comment list under the post
still carries a /staging/ link. Delete the comment - a clone pinging the
original is not a record of anything.

Two decisions taken on the way, neither of them the tool's to make:

1. **The OG Image snippet duplicates Yoast.** It prints `og:image`,
   `og:title`, `og:description`, `og:type` and `og:url` on the front page and
   Yoast prints the same five, so the home page carries two of each and a
   scraper takes whichever it likes. Fixing the URL kept both sets. Yoast's
   image is `banner-scaled.png` and the snippet's is the logo; deactivating
   snippet 6 in Code Snippets is the tidier end of it, and leaves Yoast as
   the one place sharing is configured.
2. **`facility-form-test` is a published post.** A development test page,
   live on the site, linking to a script that no longer exists anywhere. It
   should be a draft.

**The copy itself is still open.** `https://kidsoverprofits.org/staging/`
answers 200. Its front page carries `noindex`, but the inner pages do not -
`/staging/juvenile-justice-timeline/` has no robots meta at all - so page by
page it is still crawlable, and robots.txt is a request rather than a control.
Closing it properly is hosting work and the owner's: a password on `/staging/`
in cPanel, "Discourage search engines" inside the staging install, or taking it
down. The links that depended on it are fixed, so nothing on the live site
breaks when it goes.

Re-check any time with `python scripts/check-staging-links.py` (add `--all` to
fetch every page the mirror suspects rather than the first 25); it exits 1
while anything reader-facing remains.
