# Fix plan, September 2026

The fix list raised on 2026-09-17, and what became of each item. Every code
item is done and live on kidsoverprofits.org. What remains is the network
map's last items and a set of one-off actions only the site owner can run.

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
| 6 | Archived links for facility websites | Done; one surface left for a decision |
| 7A | State pages: alternate names | Done |
| 7B | State pages: PDF previews | Done; regeneration waiting on the owner |
| 8 | Facility links must not land on the program index | Done |
| 9 | Newsletter sign-up top padding | Done |
| 10 | Email notifications for new submissions | Done |

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
   The "Reports that demand attention" block then appears on the home page
   and the inspection hub within ten minutes.
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
7. **Starter views for the map (1).** The lists of names for each "Start
   from" view (historical, today's big players, religious, or others) go in
   `js/data/network/network-overrides.json` under `views`.

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
connections shown by default; starter views (the mechanism); a draft Network
Map page behind a password.

Still open:

- **2b.1 David Gilcrease.** The data holds all five of his connections. The
  check on the deployed build with the cross-group toggle off, and a module
  test asserting all five, are still to do.
- **2b.9 Toolbar and trail compaction.** The trail as one row of chips, the
  legend as a collapsible overlay, the toolbar in one row, the stage at full
  height.
- **2b.10 Starter view lists.** Waiting on the owner (item 7 above).
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
