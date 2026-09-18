# Fix plan, September 2026

Working plan for the fix list raised on 2026-09-17. Each item names the
files involved and what was found on inspection, so the work can be picked
up in any order. Items are grouped by area; a suggested order is at the end.

Two things to know before starting:

- Branch `feat/network-graph-pipeline` is being worked on by another
  session: the map commits through 89a9940 landed while this plan was
  written, and on 2026-09-18 the working tree held staged facility-page
  changes and a merge conflict in `inc/template-layout.php` that belong to
  that session. Coordinate before touching `js/network-map/`, `inc/` or
  the facility templates.
- `node scripts/test-network-modules.js` passed on the tree as of the last
  map read.

---

## 1. Network map

Merged into `docs/NETWORK-MAP.md` on 2026-09-18, under "Phase 2b: the fix
list of 2026-09-17", and reconciled against the six commits that landed
after this list was written (opening view, band layout, routed traces,
Focus/Expand, every name drawn). Already covered there: labels on every
node, using the whole stage, the circuit-board look, the two explore
modes, the Brown Schools / CEDU overlap, and the filter rail. Still open
there: the Gilcrease check, search, reset view, click zoom, rebranded vs
closed, years, deaths, profile links, toolbar and trail compaction,
starter views, staff movement, profile connections, and paths. One
decision is flagged for the user: "no off-screen neighbours" against the
built rule that an owner brings only itself.

---

## 2. Research & Reports (`/researchreports/`)

Built by `inc/research-library.php` from FileBird folders 27 (Academic)
and 25 (Government). Cards carry title, byline, year, kind, cover and
optional summary link. There is no relevance field and no sort other than
year then title, and nothing links a document to a facility.

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

The page's default order is now "most relevant": tier, then newest, then
title, with unrated last, which means the grid reads exactly as it did
before anything is rated. The sort control offers Most relevant / Newest /
A to Z; `js/research-library.js` reorders the cards in place from the three
data attributes each one carries and unhides the control, so a visitor
without JavaScript sees the PHP order and no dead select.

Nothing is seeded with a tier: which documents matter most is the site
owner's call, and the UI is there to make it. Covered by
`scripts/test-research-library.php` (25 checks against WP stubs, including
that an unrated library keeps the old order) and
`scripts/test-research-sort.js` (the browser half, asserting the JavaScript
order matches the PHP one).

**2B. Tag facilities mentioned.**
New attachment meta `kop_research_facilities` (list of `facilities_v2`
ids). Auto-suggest by running `kop_build_facility_alias_index()` from
`api/facility-aliases.php` over title, description and extracted PDF
text (the news pipeline already extracts text); the editor dialog shows
the suggestions as removable chips plus a search box to add more. Cards
show the chips as links to `/facility/<slug>/`. The facility page's
documents section gains a "Research that mentions this program" list
read from the same meta. A one-off script proposes tags for the current
library and writes them to a review file before anything is saved.

Files: `inc/research-library.php` (items, dialog, REST save),
`js/research-library-editor.js`, `css/hub.css`,
`inc/facility-pages.php` (reader).

---

## 3. Submission form (`templates/data-form-public.php`)

**3A. Inner collapsed panels are vague.**
Sub-sections carry a heading only. Add a one-line help paragraph under
each `.sub-section-header` (about 25 panels, listed in the agent survey:
Headquarters, Key Staff, Facility Ownership, Other Names, Known
Referrers, Website Links, International Program, Address Details, Other
Parent Companies, Operating Dates, Key Staff Positions, Current and Past
Accreditations, Licensing, News & Media, Official Documentation, Legal &
Compliance, Business & Property, Other Resources, Standard and Custom
Treatment Types, Philosophies, Incident Types). Each line says what goes
in and one example. Mirror the same lines in `data-form-admin.php`.
Style in `css/data-form.css` near the `.sub-section-*` rules.

**3B. Tooltips for more fields.**
`js/field-tooltips.js` covers the operator, consultant, referrer and a
few facility fields. Extend `FIELD_TOOLTIPS` and `addTooltipToLabel` to
the location fields, operating dates, accreditation and licensing lists,
every resource checkbox, treatment types, philosophies and critical
incidents. Remove the duplicate `operator-notes` key. The text for each
needs writing; a first pass can reuse the help lines from 3A.

**3C. Tutorial button overlaps the search button.**
Done 2026-09-18: the stylesheet now sets `right`, never `left`.
`js/tutorial-overlay.js` creates the button with inline `right:16px`, but
`css/tutorial-overlay.css` sets `left:20px` (and `left:15px` on mobile),
and CSS drops `right` when both are set, so the button lands bottom-left
on top of the site-wide search pill (`css/global-search.css`, also
bottom-left). Fix: delete the `left` rules from the stylesheet so the
inline bottom-right position wins, and give the two a consistent z-index.

---

## 4. Monitor menu: link to inspection reports

Done 2026-09-18. `templates/page-inspection-reports.php` at
`/inspection-reports/`: the state grid, live report and facility counts, the
curated featured reports and a note for visitors whose state is missing.
The page's own editor content, if any, prints above the grid, so the
introduction can be rewritten in wp-admin without touching the template.

The state list was hard-coded in `templates/page-home.php` and kept a second
time as prose naming all fourteen states. Both now come from
`kop_report_state_links()` and `kop_report_state_sentence()` in
`inc/utilities.php`, which derive from `kop_state_inspection_page_map()` in
`inc/rest-api.php`, the map the REST layer already uses. A new state is added
in one place. The home page grid is alphabetical as a result, and gained an
"All inspection reports" link to the hub.

Seeded through `kop_tool_page_specs()` with the version bumped to 4, and
added to the Monitor list in `api/rebuild-header-menu.php`. The menu script
still has to be re-run on prod as admin with `?apply=1`; until then the page
exists and is linked from the home page but is not in the header.

"Monitor" is a header menu section built by `api/rebuild-header-menu.php`
(Where Are The Kids, TTI Program Index, Referrers, Location Index,
Lawsuits, Legislative Efforts). There is no inspections hub page; each
state has its own `/xx-reports/` page and the homepage carries a grid of
them.

Plan: add a `page-inspection-reports.php` template at
`/inspection-reports/` that reuses the homepage's `$kop_report_states`
grid and the featured block, seeded through `inc/admin.php` like the
other hubs; then add it to the Monitor list in `rebuild-header-menu.php`
and re-run the script with `?apply=1` on prod.

---

## 5. Resources page

`/resources/` is a WordPress page whose content is edited in the editor;
`page-hub.php` prints it and has no module for that slug, so nothing in
the repo lists the resources.

Plan: give the slug a hub module like `law-policy` has, driven by a list
in `inc/resources-list.php` grouped by need (crisis lines, survivor
support, legal help, reporting abuse, families, advocacy organisations,
research) and rendered as one card style. Port the current page content
from the prod database into the list first, then add the new entries.
The new entries need the user's picks; the plan can propose a starter
set for review.

---

## 6. Facility profiles: archived links for facility websites

Done. `kop_facility_pages_archive_link()` in `inc/facility-pages.php`
rewrites a program's own website to `https://web.archive.org/web/<url>`,
which Wayback resolves to its newest snapshot, labels it
"<host> (archived copy)" (a curated `displayText` keeps its own wording),
and hands back the original as `live_url`, which
`templates/facility-page.php` offers as a small "live site" link with
`rel="nofollow noreferrer noopener"` under a one-line note about why the
first link is a snapshot. `kop_state_archive_profile_links()` in
`inc/rest-api.php` applies the same rule to the state and country hub
cards, which have no room for the second link and show the snapshot only.

`kop_facility_pages_archive_exempt_host()` holds the hosts that keep their
live link and is the place to add a case: the archives themselves, this
site, Reddit, Wikipedia, survivor and advocacy sites (heal-online,
linktr.ee), the social networks, and public records (`.gov`, `.mil`,
`.state|co|ci|k12.xx.us`). Everything else in `profileLinks` is taken to
belong to the program or its operator. Of the 147 links in the current
data, 84 are already Wayback URLs and about 20 are program sites.

`templates/single-facility-profile.php` was left alone on purpose: its
`external_profile` is the survivor community link ("Survivor community",
usually a linktr.ee), which has to stay live.

Still on a live link: the facility panel on the `/xx-reports/` pages
(`js/inspections/facilities-display.js`, field labelled "Archived
Website"), because it reads `profileLinks` straight from the shared
`kop/v1/facilities` feed, which the location index, the program index and
the article-discovery scripts also read. Rewriting it there means either
duplicating the host rule in JavaScript or changing that feed for every
consumer; it needs a decision first.

---

## 7. State pages

**7A. Alternate names not showing.** Done. The card face now carries
"Also known as" and "Formerly" under the facility name in
`js/state-page.js` and `js/country-page.js`, and the two rows are gone
from the "Show details" panel. The collector takes `other_names` /
`otherNames` / `aliases` and `past_names` / `pastNames` / `formerNames`,
a list or a bare string, and entries that are objects (`{value}`,
`{name}`, `{label}`), and drops repeats of a name already on the card.
316 facilities in the current data carry one of those lists. The
inspection-only rows still come through with empty arrays, which is
right: those records have no names beyond the licensed one.

**7B. PDF previews not rendering.**
Done 2026-09-18, with a different cause than first written below. Checked
against prod: 2,154 of 2,313 PDFs have preview sizes and their files are on
disk, and the folder-content endpoint returns a working `thumb_url` for
them. What fails is 153 PDFs from the May 2026 restore whose metadata holds
only a filesize (no preview was ever rendered; mostly Vivant, Youth
Services International, Three Springs, and 30 unfiled), plus a few older
ones. Only 7 have a sidecar attachment, so the sidecar lookup would not
have helped. Fix: `api/regenerate-pdf-previews.php` (admin, dry run by
default, `?apply=1` in batches) renders them with the same call an upload
makes; the server's Imagick reads PDFs. It also reports PDFs whose file is
missing from disk. `state-page.js` and `country-page.js` now accept
`thumbnail_url` too. Original note:
The documents panel uses `thumb_url` from `kop_get_attachment_preview_url()`
in `inc/utilities.php`, which relies on WordPress's generated first-page
JPG. The 2026 media restore registered those JPGs as separate
`<name>-pdf.jpg` attachments (which the hidden-preview filter then
hides), so the PDFs themselves have no sizes and the helper returns
nothing. The research library already works around this with sidecar
recovery. Fix: move that sidecar lookup into
`kop_get_attachment_preview_url()` as the step after the meta overrides,
so every caller (state, country, location index, program index) gets the
preview. Also accept `thumbnail_url` in `state-page.js` as the other
renderers do.

---

## 8. Facility redirects must not land on the program index

Done 2026-09-18. `kop_facility_pages_location_search_url()` is the facility
fallback; `js/location-index.js` reads `?search=` and opens the matching
places. The same wrong target was also fixed in the lawsuits page, the
global search and the Ajax Search Lite results (operators still go to the
program index, facilities to the location index).

`inc/facility-pages.php` (thin-record handling around line 1154) sends a
thin `/facility/<slug>/` to the state or country hub, and falls back to
`/tti-program-index/?search=<name>`. Most facilities are not in that
index; all are in the location index. Change the fallback (and the
directory link near line 1220, and `directoryUrl` in the network map
config) to `/location-index/?search=<name>`, and confirm
`js/location-index.js` honours a `search` query parameter.

---

## 9. Newsletter sign-up top padding

Done 2026-09-18: `css/sidebar.css`, one rule on `.primary-sidebar
.ml-embedded` (the live markup is a bare `.ml-embedded` div filled by
MailerLite's script), enqueued whenever the primary sidebar is active.

The newsletter is the MailerLite widget in Kadence's `sidebar-primary`,
placed in the Customizer, not in the repo. Fix in the child theme's
stylesheet with a rule on the widget wrapper (inspect the live markup for
the exact class; MailerLite embeds use `.ml-form-embedContainer`), adding
top padding. One rule, no template change.

---

## 10. Email notifications for new submissions

Done 2026-09-18. `inc/submission-notify.php` holds
`kop_notify_admins($type, $title, $admin_url, $fields)`, built on the
bug-report mailer, with recipients in the `kop_submission_notify_emails`
option (default `admin_email`) and a `kop_submission_notify_recipients`
filter. Every submitted value is stripped of tags, collapsed to one line
and truncated to 300 characters, so a form cannot relay markup or a wall
of text through the mail. The review link is resolved per type: the
wp-admin approval screen for suggested edits, the front-end template
pages for the rest, falling back to the tools menu.

Calls added at: `api/save-suggestion.php` (suggested_edits),
`api/save-wiki-submission.php` (new rows only, and not when an admin is
the author), `api/save-news-submission.php`,
`api/save-lawsuit-suggestion.php`, `api/save-legislation-suggestion.php`
and `inc/features.php` (the anonymous portal's TODO; the mail carries
only the submission id, file type and size, never the filename, the
notes or an address).

Two findings changed the plan. Volunteer sign-ups have no insert to hook:
`templates/page-volunteers.php` sends people to an external form or a
`mailto:`, and the one insert into `volunteer_projects` is an admin
creating a project. And news cannot be digested by call site, because the
nightly discovery run posts to the same public endpoint a person uses; the
endpoint tells them apart by `submitted_by` and files bot finds under a
separate `news_auto` type. `KOP_SUBMISSION_DIGEST_TYPES` (default
`news_auto`) decides what is queued for the 7am daily digest; wp-cron is
enabled on prod, so the event fires on ordinary traffic.

Tested with `php scripts/test-submission-notify.php`, an offline harness
over WP stubs that captures every `wp_mail()` call: 31 checks, no mail and
no database.

Only bug reports send mail (`api/save-bug-report.php`,
`inc/bug-report-notify.php`). Suggested edits, wiki, news, anonymous
documents (`inc/features.php` has a TODO for it), lawsuit and legislation
suggestions and volunteer sign-ups insert rows silently.

Plan: a small `inc/submission-notify.php` with
`kop_notify_admins($type, $title, $admin_url, $fields)` built on the
bug-report mailer, a recipient list option (default `admin_email`), and a
call at each insert site listed above. Include a plain-text summary and a
link to the matching admin screen. Add a daily digest option behind a
constant for the high-volume types (news). Test with the offline REST
harness pattern before deploying, since wp_mail on NixiHost has no
sandbox.

---

## Suggested overall order

1. Quick, self-contained fixes: 3C tutorial button, 8 redirect target,
   9 newsletter padding, 7B preview helper. All done 2026-09-18.
2. 10 notifications (small, high value). Done 2026-09-18.
3. Network map items in the order under "Phase 2b" in
   `docs/NETWORK-MAP.md`.
4. 7A state-page names, 6 archived links.
5. 2A and 2B research page.
6. 3A and 3B form copy (needs writing time).
7. 4 inspections hub (done 2026-09-18), 5 resources module (needs content decisions).
