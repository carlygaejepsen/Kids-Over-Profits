# Fix plan, September 2026

The fix list raised on 2026-09-17 and what became of each item, then four
issues the owner raised on 2026-09-18 (items 11 to 14). Everything from the
first list is live on kidsoverprofits.org except the network map's last
steps; the second list is open.

Last updated 2026-09-18.

## Status

| # | Item | State |
|---|---|---|
| 1 | Network map | Most of Phase 2b done; see [section 1](#1-network-map) |
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
| 11 | Facility websites: Wayback and/or donotlink everywhere | Open: three page types still link live |
| 12 | State pages: alternate names missing | Done in code; filling the 4,334 records with no alternate name is research |
| 13 | Featured inspections not displaying | Done: both featured reports show on the home page and the hub |
| 14 | Parser that flags the worst inspection findings | Open: design below |

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

Still open:

- **2b.1 David Gilcrease.** The data holds all five of his connections. The
  check on the deployed build with the cross-group toggle off, and a module
  test asserting all five, are still to do.
- **2b.9 Toolbar and trail compaction.** The trail as one row of chips, the
  legend as a collapsible overlay, the toolbar in one row, the stage at full
  height.
- **Paths between two nodes.** Phase 3 in `docs/NETWORK-MAP.md`.

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
