# Adding content: what is connected where

The site has many lists that name every kind of record, page or screen by
hand. A new one that misses a list does not break anything visibly: it is
simply absent from search, the site map, the open data, the app or the
merge tool. This page lists, for each kind of addition, every place that has
to learn about it.

Each section is a checklist. Function names are the places to edit; the test
named at the end of a section checks most of it. CLAUDE.md has the commands.

Contents:

1. [A new data category (a kind of record with its own table)](#1-a-new-data-category)
2. [A new field on facility records](#2-a-new-field-on-facility-records)
3. [A new public page or route](#3-a-new-public-page-or-route)
4. [A new admin screen or KOP Tools page](#4-a-new-admin-screen)
5. [A new review queue](#5-a-new-review-queue)
6. [A new table that holds facility ids](#6-a-new-table-that-holds-facility-ids)
7. [A new state inspection page](#7-a-new-state-inspection-page)
8. [A new public form](#8-a-new-public-form)
9. [New seeded records (marketers, trade organizations, lawsuits, bills, histories)](#9-new-seeded-records)
10. [Smaller rules that apply everywhere](#10-smaller-rules-that-apply-everywhere)
11. [Docs, tutorials and help to update](#11-docs-tutorials-and-help-to-update)
12. [Deploying it](#12-deploying-it)

---

## 1. A new data category

Examples: marketing companies (`marketers_master`, commits 680aef94 and
04b192a2) and trade organizations (`tradeorgs_master`, 6ffd20a3). Copy the
trade organization files and rename; this list is what those commits had to
touch.

**Storage and save paths**
- `api/lib-<kind>.php`: the field lists, `normalize`, `keep_data` (what a
  record keeps: only its own block, e.g. `{tradeOrg, category}`), `merge`,
  `names`.
- `inc/<kind>.php`: `kop_<kind>_all()`, `ensure_table`, seed loader
  (`apply_seed` + `maybe_apply_seed`), the public page renderer. Required from
  `functions.php`.
- `api/save-master.php` (admin saves) and `api/save-suggestion.php` (public
  suggestions): route the category to its table, keep only its block.
- `api/lib-suggested-edits.php`: approving a suggestion files it in the table
  and merges it into the record on file.
- `inc/rest-api.php` `kop_save_project_rest_callback()` and
  `kop_delete_project_rest_callback()`: the category (singular and plural) to
  its table, and the keep-only-its-block step.
- `api/get-master-data.php`: `$processTable('<kind>_master', '<kind>')` so
  saved projects load.
- `api/data-manager.php` `$CATEGORY_TABLE`, and the list of tables that are
  not facility tables.

**The data forms**
- `templates/data-form-<kind>.php`, included by both
  `templates/data-form-admin.php` and `templates/data-form-public.php` (the tab
  button and the saved-projects panel).
- `js/data-form/<kind>-form.js`, enqueued in `inc/enqueue.php`
  `enqueue_data_form_script()` and added to the orchestrator's dependency list
  there.
- `js/data-form-modules/project.js`: the default block in a new project, the
  ensure/toggle calls on load and on tab change, the first section to scroll
  to.
- `js/data-form-modules/api.js` and `js/data-form-modules/data-page.js`: the
  block key in the list of form blocks, and "send only this block for this
  category, drop it for every other".
- `js/data-form-modules/ui-render.js`: the saved-projects list element, the
  category's search query, and the autocomplete category of any list of
  facilities or companies (`clientFacilities`, `memberCompanies`...).
- `js/data-form/utilities.js` `resolvePathTarget()`: list paths
  `<block>.*` resolve to `formData.<block>`.
- `js/data-form/data-form.v4.js`, `js/data-form/db-form-loader.js`,
  `js/data-form/submission-editor.js`, `js/data-form/data-search.js`: the
  category name where the others are listed.
- `js/data-form/data-wizard.js`: an "Add a new ..." choice (`?add=<kind>`) and
  its `TOPICS`; `css/data-wizard.css` for its card.
- `js/admin-data-manager.js`, `templates/page-admin-data-manager.php`,
  `css/admin-data-manager.css`: the Data Manager filter.

**Public page and navigation**
- `templates/page-<kind>-index.php` and its stylesheet, enqueued in
  `inc/enqueue.php`.
- `inc/admin.php` `kop_tool_page_specs()` (creates the page; bump its
  `$version`), `kop_nav_item_specs()` (the menu, usually under Monitor) and
  `kop_template_assignments()` (bump the version in
  `kop_apply_template_assignments()`).
- `inc/site-map.php` `kop_site_map_template_sections()`: the template's
  section.
- `inc/how-to-use.php` `kop_how_to_use_groups()` if it is a main destination
  (bump the cache version there).

**Search and links**
- `inc/ajax-search-lite.php` `kop_asl_collect_database_matches()` (header
  dropdown) and `inc/global-search.php` `kop_global_search_collect()` (search
  popup): the table, label, template, and the anchor of the record's own
  entry on its page.
- `search.php`: the search page's group.
- `inc/facility-pages.php` `kop_program_go_hosts()`: the category's websites
  go through `/go/` like program sites.

**Records that point at it**
- `api/lib-wiki-link-label.php`: the `<kind>:<id>` token and its table, so
  editors show the record's name, never the token.
- `inc/people.php` `kop_people_kinds()`, `kop_people_record_label()` and
  `kop_people_named_sources()`: its people get person ids.
- `inc/woodbury-facts.php` `kop_wbf_create_kinds()` and
  `kop_wbf_filed_on()`, `inc/woodbury-create.php`,
  `inc/review-inbox/woodbury-mentions.php`: Woodbury items can create or be
  filed under it.
- `inc/wiki-updates.php` `kop_wiki_upd_candidates()`: wiki entries can link
  to it.
- The admin finder (`inc/facility-finder.php`) if admins need to pick one.

**Open data and the app**
- `inc/open-data.php` `kop_open_data_datasets()`: a dataset naming every
  column (never `SELECT *`); strip contact details and internal notes. Add the
  key to `$expected` in `scripts/test-open-data.php`.
- `inc/mobile-api.php` if the app should show it.

**Docs**: CLAUDE.md (Database Tables and a test line), `README.md`,
`AGENTS.md`, `DATABASE-COLUMNS.md`, `docs/data-forms/README.md`,
`docs/REPOSITORY-MAP.md`, and the data form tour in `js/tutorial-overlay.js`
if it lists the tabs.

**Test**: `scripts/test-<kind>.php` (copy `scripts/test-tradeorgs.php`: record
shape, seed, page, form markup keys == the JS keys).

---

## 2. A new field on facility records

- `inc/facility-store.php`: the document shape (`kop_facility_blank_document()`
  and, for a list with its own shape, a cleaner like
  `kop_facility_federal_funding_list()`). Every writer goes through this file;
  a field it does not know is dropped on save.
- `js/data-form-modules/data-normalizer.js`: the same shape in JS, or the form
  drops the field when it re-saves the record.
- The form markup and its section; the wizard's `TOPICS` in
  `js/data-form/data-wizard.js` if readers should reach it from the guided
  start.
- The facility page (`inc/facility-pages.php`, `templates/facility-page.php`)
  if it is shown, with an inline-edit marker (`kop_ie_attr()`) so admins can
  fix it in place.
- A dated list (things that happened in a year) goes in
  `kop_facility_eras_kinds()` (`inc/facility-eras.php`), so renamed programs
  file it under the name of its year.
- The directory: `kop_directory_feed_slim_facility()`
  (`inc/directory-feed.php`) only if the closed directory lists read it.
- The app: `kop_mobile_facility_payload()` (`inc/mobile-api.php`) copies named
  keys, so a new page section goes in its keep list.
- Open data: the facilities dataset exports the whole record; leave private
  parts out there (`kop_open_data_strip_keys()`).
- Merges: `inc/facility-merge.php` joins doc fields; check a list field merges
  rather than one side winning.
- `docs/FACILITY-SCHEMA.md`: the field, its type and who writes it.

Tests: `scripts/test-facility-pages.php`, `scripts/test-inline-edit.php`
(every facility sent back unchanged is unchanged), `scripts/test-mobile-api.php`.

---

## 3. A new public page or route

- A page template: `templates/page-<name>.php`; its assets in
  `inc/enqueue.php`; the page itself from `kop_tool_page_specs()` or
  `kop_template_assignments()` in `inc/admin.php` (bump the version so the
  deploy applies it); a menu entry in `kop_nav_item_specs()` if it belongs in
  the menu.
- A route (like /operator/, /site-map/, /essential-reporting/, /documents/):
  a rewrite rule in its own `inc/` file, a `*_url()` helper, and the rewrite
  flush that file already does on a version change.
- `inc/site-map.php`: every page template gets a section in
  `kop_site_map_template_sections()`; a route gets an item in its section;
  `kop_site_map_quick_links()` if it should appear in the search popup before
  typing and on the 404 page.
- `inc/how-to-use.php` `kop_how_to_use_groups()` if it is a main destination.
- A hub that should list it: `inc/hub-shell.php` settings. A page about abuse
  outside the TTI is linked only from a hub's boxed `outside` block
  (`kop_hub_outside()`).
- Words that the owner edits: `js/data/pages/<slug>.json` + Page Text
  (`inc/page-text.php`) instead of text in the template.
- Kept out of search engines: `kop_robots_disallow_paths()` (`inc/robots.php`).
- Checks: `python scripts/check-bare-text.py` (no text on the gradient),
  `python -u scripts/check-contrast.py --local`, `python -u
  scripts/check-mobile-overflow.py --local`, `php scripts/test-site-map.php`.

---

## 4. A new admin screen

- Register it with `add_submenu_page()` under KOP Tools.
- `wp-plugins/kop-tools/kop-tools.php`: an entry in the tools registry with a
  one-line description of what the screen does (bump the plugin version).
- Every tool is on the sidebar (every registry category is a sidebar
  category) and in the Review Inbox's **All tools** tab, both read from the
  registry, so the registry entry is all it takes. Never hide a screen with
  `remove_submenu_page()`: it makes the screen answer 403.
- A facility id box is always `kop_facility_finder_field()`
  (`inc/facility-finder.php`), never a bare id input.
- Editors print records' names, never stored tokens like `consultant:12`
  (`api/lib-wiki-link-label.php`).
- Anything the owner reviews gets a one-page screen with exact Undo, not a file
  to read.
- `docs/admin-submissions/README.md` if it is part of reviewing.

---

## 5. A new review queue

- A source file `inc/review-inbox/<name>.php` that calls
  `kop_rinbox_register()` with the queue's own apply and undo functions.
- Every approve/reject action carries `help`: one sentence saying what the
  click does.
- Undo: the item's `undo` action, so Recently done can reverse it.
- Items already on file: an `on_file` callback
  (`kop_rinbox_on_file_keys()`), so they leave the waiting list.
- Conflicts: `conflicts` and `resolve`, so they go to Review inbox >
  Conflicts. Having no information is never a conflict.
- Volunteers: a queue volunteers must never open goes in
  `kop_vol_never_sources()` (`inc/review-volunteers.php`).
- A queue that files items its own way goes in the skip list of
  `inc/review-inbox/_ya-file-action.php` ("File under a young adult
  program").
- A test in `scripts/review-inbox-tests/`, run with
  `scripts/test-review-inbox.php --source=<name>`.
- `docs/admin-submissions/README.md`: the queue and what its buttons do.

---

## 6. A new table that holds facility ids

- `kop_fmerge_ref_tables()` (`inc/facility-merge.php`), or
  `kop_fmerge_json_tables()` for ids inside JSON, so merging two records moves
  its rows. Files keyed by facility id read
  `kop_facility_merge_expand_ids()`.
- If an import writes rows for records that may have been merged since:
  `kop_fmerge_follow_waiting()`.
- If its rows show on facility pages, the page data and its fingerprint (cached
  pages and feeds rebuild only when their fingerprint moves:
  `kop_state_feed_fingerprint()`, the directory feed, the facility page index).
- `DATABASE-COLUMNS.md` and the Database Tables list in CLAUDE.md.
- Test: `scripts/test-facility-merge.php`.

---

## 7. A new state inspection page

- `kop_enqueue_report_scripts()` in `inc/enqueue.php` matches a fixed list of
  slugs (`ca-reports`, `ut-reports`, ...): add the new one there and in
  `inc/rest-api.php`.
- An adapter in `js/inspections/states/<st>.js` for the shared engine
  (`js/inspections/report-page.js`).
- A verdict reader in `api/inspection-verdicts/<st>.php` so the state hub's
  cards say what the report page says; run `scripts/test-inspection-verdicts.js`.
- What the state publishes in `kop_irl_state_measures()`
  (`inc/inspection-rollup.php`) for Inspection Rankings.
- `docs/state-inspection-reports/README.md` and the inspection tour
  (`js/inspection-reports-tutorial.js`) if it names the states.

---

## 8. A new public form

- "Email me when this has been reviewed": the block from
  `js/submission-followup.js` and a kind in `kop_followup_kinds()`
  (`inc/submission-followup.php`); the endpoint calls
  `kop_followup_register()`.
- Duplicates are checked with the same rules as the other forms.
- The guided tour in `js/tool-tutorials.js`, and the form's help text in
  `inc/form-help.php`.
- Its page in the site map and, if it is a main way to contribute, in How to
  use this site.

---

## 9. New seeded records

Seeds add records on deploy without anyone opening wp-admin. A seed only runs
when its version moves, and most never change a record that is already on file.

| What | File | Bump |
| --- | --- | --- |
| Marketing companies | `seeds/marketers.json` | its `version` |
| Trade organizations | `seeds/tradeorgs.json` | its `version` |
| Lawsuits | `seeds/lawsuits.json`, `seeds/lawsuit-updates.json` | the updates version (re-runs every listed update; give each case `facility_links`) |
| Bills, bill corrections | `seeds/legislation.json`, `seeds/legislation-updates.json` | `version` |
| Company histories | `seeds/operator-histories.json` | `KOP_OPERATOR_HISTORY_SEED_VERSION` (replaces only drafts nobody edited) |
| Essential reporting nominations | `js/data/featured/nominations.json` | `version` |
| Fixes to a published page's words | `seeds/text-fixes.json` | a new `fix` id (also fix the `seeds/history/` copy) |
| Facility records, merges, names | `seeds/facility-records.json` and the other `seeds/*.json` read by `kop_apply_template_assignments()` | the template assignments version |

---

## 10. Smaller rules that apply everywhere

- **Search boxes and autocompletes** match past, other and current names and
  say which (`kop_alias_label()`); a list mixing companies and facilities
  shows the Company / Facility pill (`kop_kind_pill()` == `kopKindPill()`).
- **Search results** link to the record's own page
  (`kop_search_v2_result_url()`, `kop_search_record_page_url()`).
- **Links** print words, never web addresses: `kop_url_label()` /
  `kopUrlLabel()`.
- **Program and industry websites** link to the archived copy, the live site
  only through `/go/`.
- **Recasing text**: every function that changes case ends with
  `kop_proper_names_apply()` / `kopProperNames()`.
- **Icons**: `kop_icon()` / `kopIcon()`, never emojis.
- **AI calls** go through `kop_ai_generate_alternating()`
  (`api/ai-providers.php`), never straight to one provider.
- **Admin pencils**: a new editable element gets `kop_ie_attr('<source>:<what>')`
  and saves through its source's own save path.
- **Document types**: a new type is one entry in `kop_dt_types()`; after
  changing the rules bump `KOP_DOC_TYPES_RULES_VERSION`.
- **People named in a new place** get ids through
  `kop_people_named_sources()`, in the right pool (industry, harmed, press).
- **Colours**: follow "Kadence colour traps" in CLAUDE.md.
- **Code in two languages must match**: several rules exist in both PHP and JS
  (or Python), and each has a parity test. Change both sides and run it:
  network map slice (`scripts/test-network-embed.js`), inspection text signals,
  inspection verdicts, URL labels, proper names, kind pill, news archive links,
  wiki contact and spacing, the site map filter.

---

## 11. Docs, tutorials and help to update

| Audience | Where | Update when |
| --- | --- | --- |
| Readers | How to use this site (`inc/how-to-use.php`, `templates/how-to-use.php`) | a new main destination or way to search |
| Readers | Site map (`inc/site-map.php`) | any new public page or route |
| Readers | Guided tours: `js/tutorial-overlay.js` (data form), `js/wiki-editor-tutorial.js`, `js/tool-tutorials.js` (News Processor, lawsuit and legislation forms, anonymous portal), `js/program-index-tutorial.js`, `js/network-map-tutorial.js`, `js/inspection-reports-tutorial.js`, `js/glossary-tutorial.js` | the toured page gains, loses or renames something a step points at (each step's `target` is a CSS selector) |
| Readers | Field help (`inc/form-help.php`, test `scripts/test-form-help.js`) | a form field's meaning changes |
| Readers | /open-data/ (`inc/open-data.php`) | a new public table or column |
| Admins | KOP Tools registry (`wp-plugins/kop-tools/kop-tools.php`) | a new, renamed or retired admin screen |
| Admins | Review inbox help strings and `docs/admin-submissions/README.md` | a new queue or a changed action |
| Developers | CLAUDE.md | a new subsystem, table, test or command |
| Developers | `README.md`, `AGENTS.md`, `DATABASE-COLUMNS.md`, `docs/README.md`, `docs/REPOSITORY-MAP.md` | a new subsystem, table, template or page |
| Developers | `docs/FACILITY-SCHEMA.md`, `docs/data-forms/README.md` and the other `docs/<area>/README.md` | that area changes |
| Owner | `docs/PLAN.md` | open work and the owner's queue (the one open list) |

---

## 12. Deploying it

- A push to main deploys (`.cpanel.yml`). Seeds, template assignments and
  rewrite rules apply on the first request after the deploy once their version
  has moved.
- The deploy never deletes files: a removed file needs an `rm -f` task in
  `.cpanel.yml`.
- The server has no Node: anything built by a `scripts/*.js` file is built
  locally or on GitHub Actions and committed.
- Never commit `.env`, `api/config.local.php`, or article and archive PDFs (the
  repo is public).
