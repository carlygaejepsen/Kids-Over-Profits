# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Kids Over Profits is a WordPress child theme (Kadence parent) that powers a data management system for tracking "Troubled Teen Industry" (TTI) facilities. The theme lives at `app/public/wp-content/themes/child/` inside Flywheel Local development environment.

## Development Environment

> **DEFAULT TARGET: the live site.** Unless the user explicitly says otherwise,
> always assume work, testing, URLs, and diagnostics refer to the production
> site at `https://kidsoverprofits.org`. Only treat the task as local
> (Flywheel) when the user explicitly says "local", "Flywheel", or
> "kids-over-profits.local".

- **Production (default)**: `https://kidsoverprofits.org` — NixiHost shared hosting (Apache/LiteSpeed, PHP 8.2, MySQL)
- **Local (only when specified)**: Flywheel Local at `https://kids-over-profits.local`
- **CMS**: WordPress with Kadence parent theme
- **No build process**: Plain PHP, vanilla JavaScript (plain scripts loaded in order by `wp_enqueue_script` dependencies, not ES modules), CSS

## Key Commands

```bash
# After editing any program data files in js/data/
node scripts/aggregate-all-programs.js

# Other data pipeline scripts
node scripts/rebuild-search-index.js
node scripts/extract-reddit-wiki-links.js

# After editing the network map CSVs or network-overrides.json
node scripts/build-network-graph.js
node scripts/test-network-graph.js

# The map rebuilds itself: inc/network-rebuild.php hashes what the build reads (staff, owners, names, years, status,
# consultants) hourly and, when it moves, starts .github/workflows/build-network-map.yml (sync 5 tables over SSH,
# build, test, commit graph.json, deploy). Status and "Rebuild now" at KOP Tools > Map Rebuild.
# Needs repo secret KOP_SSH_KEY and KOP_GITHUB_DISPATCH_TOKEN in api/config.local.php or .env on the server
php scripts/test-network-rebuild.php            # fingerprint + dispatch, against tmp/prod.sqlite, never calls GitHub
# After editing anything in js/network-map/
node scripts/test-network-modules.js
# The map on facility pages (embed.js + kop_network_map_slice_from_graph, PHP must match focus.js)
node scripts/test-network-embed.js [--php=<Local php.exe>]
node scripts/test-network-timeline.js           # the timeline (store years, timeline.js), seconds
# Researched map years reviewed at KOP Tools > Map Years (inc/network-years.php); accepted ones go on the map at once
node scripts/build-years-candidates.js [tmp/years-research]   # results -> js/data/network/years-candidates.json
php scripts/test-network-years.php && python scripts/check-network-years-page.py
# Renamed programs reviewed at KOP Tools > Map Renames (inc/network-renames.php): the rename year splits the two names' years on the map at once
php scripts/test-network-renames.php

# After editing the reporting directory data in js/data/reporting/
node scripts/build-reporting-directory.js
node scripts/verify-reporting-links.js          # slow, hits every agency site
node scripts/pull-childusa-sol.js               # monthly: CHILD USA sexual-abuse deadlines, review the diff, then rebuild
php scripts/test-reporting-directory.php        # renders the page offline
# After editing the glossary source js/data/glossary/glossary.md
node scripts/build-glossary.js
php scripts/test-glossary.php                   # renders /glossary/ offline, checks every #link
php scripts/test-glossary-build.php             # PHP build == glossary.json, editor round trips
# After editing the FL/NC adapters' text readers or api/lib-inspection-text-signals.php (PHP must match JS exactly)
node scripts/test-inspection-text-signals.js --php=<Local php.exe>
php scripts/test-inspections-read-lite.php     # inspections-read.php ?lite=1 / ?text= against tmp/prod.sqlite
# Generated /facility/ and /operator/ (parent company) pages, against tmp/prod.sqlite
php scripts/test-facility-pages.php
php scripts/test-operator-pages.php               # renders every /operator/<slug>/ page to tmp/operator-pages/
# Facility records linked to state inspection rows whose names differ (KOP Tools > Inspection Links, inc/inspection-links.php)
php scripts/test-inspection-links.php --file=<scraper --out json> [--state=PA]   # prints the suggestions, checks a linked row reaches the page
# Hub pages that list a category's posts (Editorials, Investigatory Spotlight; inc/hub-posts.php)
php scripts/test-hub-posts.php
# Every hub page through templates/page-hub.php + inc/hub-shell.php (per-hub settings), against tmp/prod.sqlite
php scripts/test-hub-pages.php
# Pages whose words live in js/data/pages/<slug>.json, edited at KOP Tools > Page Text
# (inc/page-text.php + inc/page-text-editor.php; e.g. /indian-boarding-schools/, a draft until published)
php scripts/test-page-text.php
# Utility and legal page templates: shortcode/share behavior, case metadata and image alt text
php scripts/test-utility-pages.php
# Live content with local utility/legal template CSS, screenshots at 390/768/1440
python scripts/preview-utility-pages.py --shots tmp/utility-preview
# Live hub frame preview at 390, 768 and 1440 px; defaults to all 12 hubs
python scripts/preview-hub-pages.py --shots tmp/hub-preview
# The legacy news posts + 2024 index going into news_submissions (against tmp/prod.sqlite)
php scripts/test-news-post-import.php
# Facility closures reported in the news (inc/closure-reports.php): hourly WP-Cron scan of saved
# articles -> KOP Tools > Closure Reports; confirming one sets the facility's status, which the
# /facility/ page and the network map (kop_network_map_status_overrides) follow at once
php scripts/test-closure-reports.php              # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-closure-reports.php --type=closure   # on the server (ea-php82): dry run; "apply" stores reports
# Facilities the news mentions that have no record (inc/facility-discovery.php): the hourly scan creates
# them from the article, or links a known one; KOP Tools > Facilities from News to remove or create by hand
php scripts/test-facility-discovery.php           # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-new-facilities.php --ids=502         # on the server: dry run; "apply" creates records
# Indigenous residential schools (inc/indigenous-schools.php): their own records, never TTI facilities; listed on
# /indian-boarding-schools/ with their articles, managed at KOP Data Tools > Indigenous Schools (Move here, news scan names)
php scripts/test-indigenous-schools.php          # the first move and the page, on an in-memory copy of tmp/prod.sqlite
# Young adult programs (18+, inc/young-adult-programs.php): their own records, never TTI facilities; listed on
# /young-adult-programs/, managed at KOP Tools > Young Adult Programs, filled from Woodbury Facts' "Young adult programs (18+)" tab
php scripts/test-young-adult-programs.php       # records, facts + exact undo, every no-record Woodbury item, the page
php scripts/test-young-adult-move.php           # the first move of 18+ facility records, on an in-memory copy (sync the mirror first)
# Edit in place (inc/inline-edit.php, js/inline-edit.js): admins get a pencil on every marked element
# (kop_ie_attr('<source>:<what>')) that saves through the source's own save path; a new page or field gets a marker there
php scripts/test-inline-edit.php                  # every facility sent back unchanged is unchanged, edits land, against tmp/prod.sqlite
# Survivor Testimony "Move to testimony" in the admin data form (js/data-form/testimony.js): every copy of a
# moved note can be cleared ("Remove copy"), moved text leaves the fields the public pages list
node scripts/test-testimony-move.js
# Admin facility id boxes: always kop_facility_finder_field() (inc/facility-finder.php), never a bare id input
php scripts/test-facility-finder.php              # search by name/past name/id against tmp/prod.sqlite, no bare id boxes left
# Header search dropdown and the site-wide search widget (kop_v2_search() in inc/facility-v2-readers.php, read by
# inc/ajax-search-lite.php, inc/global-search.php, search.php): also matches identification.pastNames/otherNames,
# shown as "Formerly X" / "Also known as X", current-name hits ranked first
php scripts/test-search-aliases.php               # against tmp/prod.sqlite
# Facility names in free-text lists (inc/facility-suggest.php): kop/v1/facility-suggest feeds the data forms'
# 'facilityref' autocomplete (ttiReferrals, knownReferrals, facilitiesReferred; reuses kop_v2_search); kop/v1/facility-links
# resolves names to /facility/<slug>/ for "Refers young people to" on /mental-health-providers/ (one batch, ambiguous never links)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-links.php   # prints the Billings list's linked/ambiguous/unmatched names
node scripts/test-facility-ref-split.js           # the forms split a pasted comma list into entries and mark names that will not link
# "Send to KOP" Chrome extension (browser-extension/send-to-kop/, loaded unpacked, not deployed) posts to
# kop/v1/extension/* (inc/source-submissions.php): articles -> news_submissions, lawsuits, legislation,
# anything else -> KOP Tools > Websites Sent In; same duplicate rules as the public forms
php -d extension=pdo_sqlite scripts/test-source-submissions.php   # against an in-memory copy of tmp/prod.sqlite
# Journalist extraction from news bylines (api/lib-journalists.php), against tmp/prod.sqlite
php scripts/test-journalists.php [--list]
# Subfolders for the largest flat FileBird folders: plan from tmp/prod.sqlite into
# seeds/media-subfolders.json (report in tmp/), applied on deploy by kop_apply_media_subfolders()
python scripts/build-media-subfolders.py
php scripts/test-media-subfolders.php
# Open data bulk downloads (/open-data/, inc/open-data.php; built daily by WP-Cron into uploads/kop-open-data/).
# Every dataset names its columns; a new public table or column is added there, never via SELECT *
php scripts/test-open-data.php [--skip-fulltext]  # builds from tmp/prod.sqlite into tmp/kop-open-data/, privacy checks
php scripts/build-open-data.php                   # on the server (ea-php82), rebuild now
# SVG icons that replace emojis (inc/icons.php: kop_icon() / kopIcon(), and the
# render filter for emojis in post content, widgets and the ACF facility-key field)
php scripts/test-icons.php
# After any template or page CSS change: no text may sit on the gradient body background
python scripts/check-bare-text.py               # one page per child template, live site
# ...and no text unreadable against its own panel, incl. forced :hover/:focus (see "Kadence colour traps")
python -u scripts/check-contrast.py [--local]   # WCAG AA, text and icons; --local serves the working tree css/js on the live pages
python scripts/test-colour-contrast.py          # static: inks, fills, no accent text or white-on-accent in any stylesheet
# Woodbury Reports pages about each program (inc/woodbury-mentions.php): scan every issue in the
# media library (reads tmp/prod.sqlite), cut the pages into C:/tmp/kop-woodbury/pending/, copy that
# folder to ~/kop-import/woodbury/ on the server, review and file at KOP Tools > Woodbury Reports
python scripts/woodbury-scan.py [--no-cut]
php scripts/test-woodbury-mentions.php            # "File under" facilities and parent companies (c<id>), company search, rows
# Woodbury Reports facts (inc/woodbury-facts.php): staff and careers, incidents, openings/closings, names,
# owners, moves read from every issue into tmp/woodbury-extract/facts/ (readers follow INSTRUCTIONS.md there);
# the build checks each quote, matches records, drops what they hold -> C:/tmp/kop-woodbury/pending/facts.json,
# copied to ~/kop-import/woodbury/; owner adds them at KOP Tools > Woodbury Facts (live at once, Undo)
python scripts/woodbury-facts.py
php scripts/test-woodbury-facts.php               # applies every proposal to the real docs, validator, exact undo
# HEAL's archived site (heal-online.org, gone; Wayback copies) into tmp/heal/: staff lists and program pages are read
# like Woodbury issues (tmp/heal/INSTRUCTIONS.md -> facts/), PDFs one by one (DOCS-INSTRUCTIONS.md -> docs/).
# Fetch is resumable and slow (the Archive refuses connections past ~15 requests a minute)
python scripts/heal-archive.py fetch && python scripts/heal-archive.py text && python scripts/heal-batches.py
python scripts/woodbury-facts.py --also tmp/heal   # HEAL facts join Woodbury Facts, cited to the archived copy, never auto-added
python scripts/heal-docs.py                         # tmp/heal/heal-links.json -> ~/kop-import/gdocs/, reviewed at Drive Docs
# The r/troubledteens wiki (markdown_output/ + the wiki editor's copies in tmp/prod.sqlite) read the same way:
# text -> tmp/wiki/text/ + INSTRUCTIONS.md, batches of pages not read yet; readers write tmp/wiki/facts/
python scripts/wiki-source.py text && python scripts/wiki-source.py batches
python scripts/woodbury-facts.py --also tmp/heal --also tmp/wiki   # wiki facts cite the wiki page, never auto-added
# Links from the owner's Google Docs and Sheets (docs/PLAN.md 3.9): tmp/gdocs/export.gs (from scripts/gdocs-select.py)
# exports them to G:/My Drive/KOP Doc Export; the links pass ties each link to a facility and drops what is on file ->
# tmp/gdocs/links.json, copied to ~/kop-import/gdocs/; reviewed at KOP Tools > Drive Docs (inc/drive-docs.php): news,
# court records and bills to their queues (no emails), program sites to profileLinks, the rest to the record's
# resourceLinks ({url, label, kind, source}, listed on the /facility/ page under "Materials and links")
python scripts/gdocs-extract.py
php scripts/test-drive-docs.php                   # every link on its real record, validator, exact undo, the page list
# Unsilenced's archive (public Drive folders) on facility/operator pages: only documents KOP has no copy of.
# Server lists every file with its md5 (read-only; ~/kop-import/unsilenced/files.jsonl -> tmp/unsilenced/),
# then the build compares with the media library md5s and inspection scrapers -> js/data/unsilenced/
php api/list-unsilenced-files.php [probe] [--minutes=25]   # on the server (ea-php82), resumable
php api/list-unsilenced-files.php restart --check          # monthly cron: lists into ~/kop-import/unsilenced/check/, mails when Unsilenced added enough
python scripts/build-unsilenced-links.py                   # report in tmp/unsilenced/build-report.md
php scripts/test-unsilenced-archive.php                    # inc/unsilenced-archive.php, fixture + the build
# Fornits survivor forum (inc/fornits.php): the crawl copies the treatment-abuse boards into tmp/fornits/ (never committed);
# the hourly Windows task "KOP Fornits" ties new topics to facilities and uploads them to ~/kop-import/fornits/; on the
# server an hourly read, Gemini free tier first then Groq (daily caps KOP_FORNITS_GEMINI_CALLS / KOP_FORNITS_DAILY_CALLS),
# proposes staff, incidents, survivor accounts (unpublished) and leads; reviewed at KOP Tools > Fornits, with exact Undo.
# "Check AI keys" there sends one request per provider through the site's own code (keys never shown)
python scripts/test-gemini.py                     # is a Gemini key usable? lists its models, names the fix
python scripts/fornits-crawl.py                   # resumable, one request per 3 s
python scripts/fornits-process.py [--no-upload] [--keep-crawling]   # --no-upload: dry run, marks nothing read
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-fornits.php   # every link + each kind on real docs, exact undo
# Scraper report PDFs on Drive -> wp-content/uploads/inspection-reports/<st>/ + index.json,
# linked as "Archived copy" on the state report pages (server CLI + nightly cron; no apply = dry run)
php api/sync-inspection-archive.php apply --limit=2000 --minutes=25
# See the working tree's map in a browser before pushing it
python scripts/preview-network-map.py --shots tmp/map-preview
```

### Reporting directory data

`js/data/reporting/` holds where to report an abusive therapist or program,
state by state: `national.json`, one `states/<abbr>.json` per state, and the
generated `directory.json` the /report-abuse/ page reads. Every channel needs
a source URL and a `verified_on` date or the build refuses it. `README.md` in
that folder is the field-by-field schema. Rendered server-side by
`inc/reporting-directory.php`; the state hubs embed their own state's block.

### Glossary data

`js/data/glossary/glossary.md` is the TTI glossary source (sections `##`,
program groups `###`/`####`, entries `**Term** *(aka ...)*: text. Used at: *A, B*`).
`build-glossary.js` writes the `glossary.json` the /glossary/ page reads and
fails on any `**cross-reference**` that does not name an entry. Rendered
server-side by `inc/glossary.php` (`templates/page-glossary.php`).
Admins can also edit entries in wp-admin (KOP Tools > Glossary Editor,
`inc/glossary-editor.php`): saved changes sit in the `kop_glossary_edits`
option and are applied over the deployed glossary.md by a PHP port of the
build (`inc/glossary-build.php`), live at once. Commit them by downloading the
merged glossary.md from the editor, rebuilding and committing; they then clear
themselves. `php scripts/test-glossary-build.php` checks the PHP build still
matches `glossary.json` exactly, so change both builds together.

### Network map data

`js/data/network/` holds the Miro board export (`tti_nodes.csv`, `tti_edges.csv`)
and the generated `graph.json` that the network map reads. Corrections go in
`network-overrides.json`, never in the CSVs, so the next board export does not
undo them. `staff-movement.csv` holds the reviewed staff moves the build adds
as edges; `node scripts/extract-staff-movement.js` drafts it from the profile
text into `tmp/staff-movement.draft.csv` for comparison. Each build rewrites
`tmp/network-qa.md` (gitignored) listing every row the rules had to guess at.
Facility links are resolved against
`facilities_v2` in `tmp/prod.sqlite` when that mirror is present, and fall back
to the program aggregate otherwise.

## Architecture

### Dual-Workflow Data System
1. **Admin workflow**: Direct writes to `facilities_master` table via `api/save-master.php`
2. **Public workflow**: Writes to `suggested_edits` table for approval via `api/save-suggestion.php`

### Database Tables
- `facilities_master` - Official facility records
- `suggested_edits` - Public submissions pending approval
- `locations_master` / `referrers_master` - Related data
- `providers_master` - Mental health providers outside the TTI (psychiatric wards, PHP/IOP, day schools, respite, outpatient) that use TTI practices or refer to TTI facilities; the data form's "providers" category (`js/data-form/provider-form.js`), kept out of the facility tables
- `wiki_submissions` / `news_submissions` - Content submissions
- `journalists` / `journalist_articles` - Internal-only list of journalists covering the TTI, extracted from news bylines (`api/lib-journalists.php`, managed in `api/manage-journalists.php`); never exposed publicly
- `facility_closure_reports` / `news_closure_scans` - Closures the hourly news scan found (one row per article and program, pending until an admin confirms) and which articles it has read (`inc/closure-reports.php`)
- `news_facility_candidates` / `news_facility_scans` - Every facility name from the news with no record and what the scan decided (created, matched, possible duplicate, provider, not a facility), one row per name, and which articles it has read (`inc/facility-discovery.php`)
- `indigenous_schools` / `indigenous_school_news` - Indian boarding, residential and mission schools, kept out of the facility tables (never on facility pages, hubs, map, search or open data), and which articles are about each (school_id 0 = the schools in general); the news scan files a school it finds as `review = 'pending'` (`inc/indigenous-schools.php`)
- `young_adult_programs` - Programs for people 18 and older, kept out of the facility tables like the Indigenous schools; `facts` is a JSON list of Woodbury items, each citing its issue page (`inc/young-adult-programs.php`; `ya` on `{prefix}kop_woodbury_facts` marks a no-record program's items for its tab)
- `lawsuit_facility_links` / `lawsuit_news_links` - Which facilities a lawsuit involves and which articles cover it (synced on save; `api/lawsuit-facility-links.php`, `api/lawsuit-news-links.php`)
- `{prefix}kop_woodbury_mentions` - Woodbury Reports pages about a program (article, news item or mention) found by `scripts/woodbury-scan.py`, pending until an admin files them in the program's "Woodbury Reports Mentions" folder
- `{prefix}kop_media_folder_tags` - Extra folder memberships (one document, many folders)
- `{prefix}kop_folder_links` - Legacy/current-name folder equivalence (curated in `api/link-folders.php`)
- `{prefix}kop_glossary_feedback` - Reader notes from the glossary's "My facility used this too" / "Suggest a correction" buttons (`inc/glossary-feedback.php`; reviewed under KOP Tools > Glossary Feedback)
- `{prefix}kop_addresses` / `{prefix}kop_facility_addresses` - Physical address IDs and which facility stood where (`api/manage-addresses.php`; join table rebuilt from facility data on each seed)

### Page Template → Script Loading Pattern
Templates live in `templates/`; `inc/enqueue.php` loads each page's assets conditionally:
- `templates/page-admin-data.php` → Admin form assets, mode='admin'
- `templates/page-data.php` → Public form assets, mode='suggestions'
- `templates/page-tti-program-index.php` → Facility directory, two tabs: by parent company (`js/tti-program-index.js`) and by location (`?view=location`, `js/location-index.js`; `/location-index/` redirects here)
- `templates/page-wiki-editor.php` → Wiki content editor
- `templates/page-news-processor.php` → News processing
- State report pages: `kop_enqueue_report_scripts()` matches a fixed list of slugs (`ca-reports`, `ut-reports`, ...), not a `*-reports` pattern; a new state must be added there and in `inc/rest-api.php`

### JavaScript Module Structure
```
js/data-form-modules/    ← Core modules (config.js loads first, no deps)
js/data-form/            ← Form utilities (utilities.js, data-form.v4.js orchestrator)
js/inspections/          ← State report viewers
js/data/                 ← Static JSON fallbacks
```

Module dependency chain: `config.js` → `data-normalizer.js` → `api.js` → `project.js` → UI modules

### API Configuration
Credentials loaded from `.env`, WordPress constants, or `api/config.local.php` (gitignored). The data forms read `KOP_DATA_FORM_CONFIG` (also localized as `dataFormConfig`), which carries `ajaxUrl`, `restUrl`, `nonce`, `isAdmin`, `endpoints` and `mode`. There is no `apiBase` key.

## Code Conventions

- **Versioned filenames**: Use explicit versions when iterating (e.g., `data-form.v4.js`)
- **Procedural PHP**: API endpoints use procedural style
- **No build tooling**: Avoid introducing bundlers/transpilers
- **CSS variables**: Use `var(--kop-*)` from `css/colors.css` for styling

## Color Palette (css/colors.css)
Primary: Midnight Blue (#000435), Navy (#000080), Teal (#33A7B5)
Accents: Orange (#EF9034), Chartreuse (#B2E102), Coral Pink (#FE8088)
Backgrounds: Sand (#F2EEDF), Soft Pastel Yellow (#FFF5CB), Mint Green (#B6E3D4)

Reserve bright accents (Chartreuse, Coral Pink, Bubblegum Pink) for borders/highlights, not backgrounds.

### Kadence colour traps (why text goes invisible)
Our stylesheets load **before** `kadence-global-css` on every page, so any
specificity tie goes to Kadence. Kadence also colours these elements directly,
so they do **not** inherit a panel's `color`:

- `h1`-`h6`: #1A202C / #2D3748. A navy panel with `color:#fff` still gets black headings.
- `a`: navy #000080 (hover #000435). A link in a navy panel is navy on navy.
- `button`, `.button`, `input[type=submit]`: white text on teal, and `button:hover`,
  `button:focus` (0,1,1) beat a one-class rule (0,1,0). A button styled
  `.x { background:#fff; color:navy }` or `.x:hover { background:sand }` goes
  white on white/sand on hover, or after a click (focus sticks).
- `input`/`select`/`textarea`: grey text on a white box. A transparent field on a dark panel is grey on navy;
  `select option` popups need their own background and colour.
- Undefined `var(--kop-x)` with no fallback resolves to *inherit*, so it silently
  takes the panel colour (the old `--kop-navy` white-on-white).
- Our own `.panel * { color:#fff !important }` blankets repaint components injected
  into the panel (doc tiles, pills, bug flags) white on their white backgrounds.

Rules: a dark panel sets colours for its headings, links and buttons explicitly.
Every `<button>` rule is `button.x` and names **both** colour and background in
the rest, `:hover`, `:focus` and `:active` states (links styled as buttons too:
Kadence's `a:focus` is midnight). A reusable component sets its own foreground
and background instead of inheriting. No `*` colour blankets.

Every text passes WCAG AA (4.5:1, large text 3:1) and every icon 3:1, in every state.
- Accent as **text or icon** on a light ground: `--kop-*-ink` (teal, orange, coral-pink,
  chartreuse, bubblegum-pink). On navy/midnight keep the bright accent.
- **White text on a fill**: `--kop-*-fill` (white on teal fill 5.35), never the bright
  accent (white on teal 2.86). Dark text on a bright accent is fine (midnight on teal 6.86).
- Secondary text (dates, sources, counts): `--kop-text-muted` (#4A5568), never Kadence
  greys like #718096 / #9ca3af / #999.
- Kadence's button and grey colours are restated from the palette in `css/colors.css`
  (`html:root`); plugin output (FileBird, MailerLite, AddToAny) in `css/plugin-contrast.css`.
- Icons (`kop_icon()` / `kopIcon()`) draw in `currentColor`: colour the text, not the SVG.

Check with `python -u scripts/check-contrast.py [--local] [paths]` (every text node and
small SVG against its real background, hover and focus forced, names the rule that set
each failing colour) and `python scripts/test-colour-contrast.py` (static: tokens, no
accent text, no white on an accent in any stylesheet).

## Program Data Pipeline

Source files in `js/data/reddit-wiki/programs-XX.json` (by state) and `js/data/tti-program-links.json` (uncategorized). After any edits:
```bash
node scripts/aggregate-all-programs.js
```
This generates `programs-array.json`, `search-index.json`, and `metadata.json`.

## REST API

- `GET /wp-json/kop/v1/facilities` - Returns facility data (registered in `functions.php`)
- Falls back to static JSON in `js/data/` when API unavailable

## Deployment

- Git deployment via `.cpanel.yml` or manual upload to NixiHost/cPanel
- Export database snapshots if schema changes
- Never commit `.env` or `config.local.php`
