# Kids Over Profits Contributor Guide

This repository is the Kids Over Profits WordPress child theme. Treat it as the theme source itself, not as a full local WordPress or Flywheel site checkout. `CLAUDE.md` is the detailed reference: every subsystem's commands, tests and rules. This guide is the overview.

## Environment Snapshot

### Production Environment
- Hosting: NixiHost shared hosting via cPanel (https://kidsoverprofits.org, the default target for all work)
- Web server: Apache with LiteSpeed
- PHP: 8.2 (`ea-php82`); server CLI jobs use `/opt/cpanel/ea-php82/root/usr/bin/php`
- Database: MySQL, WordPress table prefix `wpdl_`; a second "records" database for news, lawsuits, legislation and the memorial (see `DATABASE-COLUMNS.md`)
- Theme stack: Kadence parent theme plus this child theme
- Infrastructure in play: Wordfence WAF and LiteSpeed Cache
- No Node on the server: `scripts/*.js` builds run locally or on GitHub Actions
- Access constraint: no production credentials live in the repo

### Repo Assumptions
- This repo may be checked out standalone and mounted into a WordPress install separately.
- Do not assume sibling `wp-admin`, `wp-content/plugins`, or Flywheel Local directories exist next to this checkout.
- Prefer WordPress path helpers such as `get_stylesheet_directory()` and `get_stylesheet_directory_uri()` over hard-coded local paths.
- Offline tests run against `tmp/prod.sqlite`, a SQLite mirror of production (`scripts/sync-prod-sqlite.py`).
- For environment specifics, see `environment-summary.md`.

## Adding Something New

Read `docs/ADDING-CONTENT.md` before adding a data category, facility field, page, admin screen, review queue or seed: it lists every search, site map, open data, app, merge, people and docs list that must learn about the new thing.

## Recent Important Changes

### September-October 2026
- **Facility model v2**: since 2026-09-18 every facility is one JSON document in `facilities_v2` (parent companies in `{prefix}kop_operators`); `facilities_master` is frozen. Schema in `docs/FACILITY-SCHEMA.md`.
- **Generated pages**: `/facility/<slug>/` and `/operator/<slug>/` for every qualifying record, with names by era, staff careers, serious findings, lawsuits, news, federal funding and document libraries. `/tti-program-index/` is one directory with company and location tabs.
- **Review inbox**: every approval queue on the Submissions Review page (`inc/review-inbox.php` + `inc/review-inbox/<source>.php`) with Undo, conflicts, "already on file", volunteer reviewers and follow-up emails.
- **Imports**: Woodbury Reports, Fornits, HEAL, the r/troubledteens wiki, Google Docs (Drive Docs), SCIAD NET, Unsilenced and other survivor archives, state-published facility lists, the data audit and federal funding all feed the review inbox; exact names connect themselves (`inc/auto-connect.php`).
- **People**: every named person has an id (`{prefix}kop_people`), merged at KOP Tools > Merge People.
- **Inspections**: shared engine with ~27 state adapters, serious-finding scan, plain-language summaries and rankings.
- **New record categories** kept out of the facility tables: mental health providers, marketing companies, trade organizations, young adult programs (18+) and Indigenous residential schools.
- **Content**: hub pages, articles with pull quotes, page text editable at KOP Tools > Page Text, the glossary in SQL tables, document types (`/documents/<type>/`), essential reporting, the reader's site map, open data downloads, edit-in-place pencils for admins.
- **Outside the site**: the mobile app (`inc/mobile-api.php`, `inc/mobile-submit.php`, admin mode in `inc/mobile-admin.php`), the Send to KOP browser extension and `/send/` page, the auto-rebuilding network map.
- All AI calls alternate between Groq and Gemini via `kop_ai_generate_alternating()` (`api/ai-providers.php`).

### April 2026
- `functions.php` became a thin bootstrap file; theme logic moved into `inc/*.php`.
- State inspection reports moved to a shared page structure with a `last-updated` slot driven by `scraped_timestamp` from `api/inspections-read.php`.

## Architecture Overview

### Bootstrap and Core Modules
- `functions.php` only wires the theme together and delegates nearly all logic to `inc/*.php` (one file per subsystem).
- `inc/utilities.php` contains shared helpers and context detection.
- `inc/enqueue.php` owns script and style loading, page-specific asset routing, and localized config objects.
- `inc/database.php` contains database access helpers for facilities and related records.
- `inc/rest-api.php` registers the core `kop/v1/*` REST routes; subsystem files register their own.
- `inc/admin.php` contains admin glue code; the KOP Tools menu is the `wp-plugins/kop-tools/` plugin.
- `inc/features.php` contains feature classes such as `AnonymousDocPortal`.
- `inc/facility-store.php`, `inc/facility-v2-readers.php`, `inc/facility-v2-writer.php` are the facility model's store, readers and writer.

### Data Stores (overview; columns in `DATABASE-COLUMNS.md`)
- `facilities_v2` stores the facility records; `{prefix}kop_operators` / `kop_operator_facilities` the parent companies and their programs; `{prefix}kop_facility_locations` which state/country pages list each.
- `facilities_master` is the frozen legacy copy; `suggested_edits` stores public submissions awaiting review.
- `locations_master`, `referrers_master`, `{prefix}transporters_master` (`kop_transporters_table()`), `providers_master`, `marketers_master`, `tradeorgs_master` store the other record categories.
- `young_adult_programs` and `indigenous_schools` hold programs kept apart from the TTI facility tables.
- `wiki_submissions`, `news_submissions`, `lawsuits`, `legislation`, `memorial_victims`, `news_featured` (+ `news_featured_nominations`) are records tables in the records database.
- `inspection_facilities` / `inspection_reports` hold scraper output; `inspection_highlights`, `inspection_highlight_summaries` and `inspection_report_counts` hold serious findings, their approved summaries and the rankings' counts.
- `record_status_proposals`, `facility_closure_reports`, `news_facility_candidates` hold changes found by the nightly and hourly scans, waiting for review.
- `{prefix}kop_people` / `{prefix}kop_person_roles`: person ids and where each person is named.
- `{prefix}kop_program_homes` and `{prefix}kop_program_groups` link licensed home records to parent program records.
- `{prefix}kop_review_log`, `_holds`, `_tags`, `{prefix}kop_volunteers` / `_recs` back the review inbox; import queues have their own `{prefix}kop_*` tables.

### API Layer
- `api/get-master-data.php` and `api/save-master.php` power the admin data form (saves go to `facilities_v2`).
- `api/save-suggestion.php` and `api/process-edit.php` power the public suggestion workflow and approval pipeline.
- `api/manage-submissions.php`, `api/save-wiki-submission.php`, and `api/save-news-submission.php` power the submission review flows.
- `api/inspections-read.php` and `api/inspections-write.php` power the state inspection reports.
- `api/config.php` resolves credentials from `.env`, `wp-config.php` constants, `api/config.local.php`, then `KOP_DB_*` fallbacks.
- Several `api/*.php` files are server CLI jobs (scans, backfills, imports) run with ea-php82; most dry-run unless given `apply`.

## Repository Layout

### Primary Directories
- `api/` contains procedural PHP endpoints and server CLI jobs.
- `inc/` contains the theme's PHP, one file per subsystem; `inc/review-inbox/` holds one file per review queue.
- `templates/` contains every page template (there are no root `page-*.php` files) and the generated-page templates.
- `js/` contains page entry points, data-form modules, inspection renderers, the network map and helper libraries.
- `css/` contains the shared palette (`colors.css`) and page-specific stylesheets.
- `scripts/` contains data pipelines, importers, builders and the offline test scripts.
- `seeds/` contains data applied once per version on deploy.
- `docs/` contains focused workflow docs; `docs/PLAN.md` is the single list of open work.
- `wp-plugins/kop-tools/` is the KOP Tools admin plugin; `browser-extension/send-to-kop/` the Send to KOP extension.

### Key Root Files
- `style.css` contains the WordPress child theme header.
- `.cpanel.yml` handles cPanel deployment (run by `.github/workflows/deploy.yml` on push to `main`; it never deletes files unless told to).
- `.htaccess` contains Wordfence and LiteSpeed related rules.
- `.env.example` is the credential template.
- `submissions_database.sql` contains the wiki and news submission schema.
- `CLAUDE.md`, `README.md`, `DATABASE-COLUMNS.md`, `QUICK-REFERENCE.md`, and `environment-summary.md` are the main supporting docs.

## Major Pages and Workflows

### Core Data Management
- `templates/page-admin-data.php` plus `templates/data-form-admin.php` provide the admin-only data form.
- `templates/page-data.php` plus `templates/data-form-public.php` provide the public suggestion form (`/tti-data-submission/`, opening on a guided start, `js/data-form/data-wizard.js`).
- `templates/page-admin-submissions.php` is the review inbox (`js/review-inbox.js`, `js/admin-submissions.js`).
- `templates/page-admin-data-manager.php` is the Data Manager.

### Public Directory and Index Pages
- `templates/page-tti-program-index.php` is the facility directory: by parent company (`js/tti-program-index.js`) and by location (`?view=location`, `js/location-index.js`); `/location-index/` redirects there.
- `templates/facility-page.php` and `templates/operator-page.php` render `/facility/<slug>/` and `/operator/<slug>/`; `templates/operator-index.php` lists companies.
- `templates/page-referrer-index.php` powers the consultant and referrer directory.
- `templates/page-transporter-index.php` (/youth-transport-companies/), `templates/page-provider-index.php` (/mental-health-providers/), `templates/page-marketer-index.php` (/marketing-companies/), `templates/page-tradeorg-index.php` (/trade-organizations/) and `templates/page-young-adult-programs.php` list the other categories.
- `templates/page-state.php` and `templates/page-country.php` are the state and country hubs; `templates/page-network-map.php` the network map.

### State Inspection Reports
- `templates/page-state-reports.php` is the shared report-page template; `css/facility-reports.css` provides its layout.
- `templates/page-severe-reports.php` lists serious findings across states.

### Content Pages
- `templates/page-hub.php` + `inc/hub-shell.php` render hub pages; `templates/page-article.php` articles.
- `templates/page-wiki-editor.php` and `templates/page-wiki-feed.php` cover wiki submission and the published wiki feed.
- `templates/page-news-processor.php` and `templates/page-news-feed.php` cover news submission and the published news feed.
- `templates/page-glossary.php`, `templates/page-report-abuse.php`, `templates/page-memorial.php`, `templates/page-lawsuits.php`, `templates/page-legislation.php`, `templates/page-open-data.php`, `templates/document-types.php`, `templates/featured-reporting.php`, `templates/site-map.php`, `templates/how-to-use.php`.
- `[anonymous_doc_portal]` renders the anonymous document portal.
- `templates/single-person-profile.php` renders seeded personnel-profile drafts from `seeds/<slug>.json` and `seeds/<slug>.html`; these stay unpublished until their claims have traceable sources and editorial review.

## JavaScript Structure

### Data Form Stack
- `js/data-form-modules/` contains the core modules (`config.js` loads first, then `data-normalizer.js`, `api.js`, `project.js`, UI modules).
- `js/data-form/` contains form utilities, the per-category forms and the `data-form.v4.js` orchestrator.
- `js/autocomplete.js`, `js/field-tooltips.js`, and `js/tutorial-overlay.js` support the data form experience.

### Inspection and Directory Stack
- Tracker pages run on the shared engine `js/inspections/report-page.js` with one adapter per state in `js/inspections/states/`; `docs/state-inspection-reports/README.md` is the current list. The legacy per-state renderers in `js/inspections/*_reports.js` stay as rollback targets (California and Texas still use theirs).
- `js/inspections/facilities-display.js` is loaded by `load_facilities_data()` on directory contexts other than the directory page template.
- `js/tti-program-index.js`, `js/location-index.js`, `js/referrer-index-v2.js`, and `js/document-library.js` support the public directory pages.

### Content and Submission Tools
- `js/wiki-editor.js`, `js/wiki-generation.js`, `js/wiki-parser.js` and `js/auto-linker.js` drive the wiki tooling.
- `js/news-processor.js` drives the news workflow.
- `js/inline-edit.js` is the admins' edit-in-place pencil on every page.
- `js/anonymous-portal.js` drives anonymous document uploads.

## Inspection Report Runtime

- `inc/enqueue.php::kop_enqueue_report_scripts()` maps a fixed list of report page slugs (`ca-reports`, `ut-reports`, ... , `co-reports`), not a `*-reports` pattern; a new state is added there and in `inc/rest-api.php`.
- Each slug loads its adapter (or legacy renderer) plus `css/facility-reports.css`.
- The primary data source is `api/inspections-read.php?state=XX` (`?lite=1` lists, report text on open).
- Static JSON files in `js/data/` remain fallback or historical snapshots for some states (CA, UT, AZ, CT, WA, MT, MN).
- `api/inspections-write.php` is the write endpoint for scraper output (protected by `KOP_DATA_API_KEY`).
- No scrapers live in this repo: they are in the companion Kids-Over-Profits-Tools repo.
- Scraped report PDFs stay in link-shared Drive folders; `api/sync-inspection-archive.php` writes an index of their links.

## Program Data Pipeline

- Program name lists for the wiki editor's auto-linker live under `js/data/reddit-wiki/programs-XX.json`; uncategorized leftovers in `js/data/tti-program-links.json`.
- Generated files include `programs-array.json`, `search-index.json`, `metadata.json`, and `index.json`.
- After editing state files or leftovers, run:

```bash
node scripts/aggregate-all-programs.js
```

- Do not edit generated files directly. Details in `QUICK-REFERENCE.md`.

## Runtime Data Flow

### Facilities and REST Export
- `kop/v1/facilities?view=index` serves the directory's slim lists and `?view=detail&key[]=` the full records of what a visitor opens (`inc/directory-feed.php`, file-cached by fingerprint); `api/get-master-data.php` is the fallback.
- State and country hubs read `kop/v1/state/<slug>` (file-cached; inspections loaded lazily).
- The mobile app reads `kop/v1/facility/<slug>`, `/operator/<slug>`, `/operators`, `/news`, `/resources` (`inc/mobile-api.php`).

### Data Form Configuration Contract
- `inc/enqueue.php` localizes `KOP_DATA_FORM_CONFIG` (also as `dataFormConfig`) for pages that load the data form stack.
- It carries `ajaxUrl`, `restUrl`, `nonce`, `isAdmin`, `mode` (`admin` or `suggestions`), `api` (`root`, `nonce`) and `endpoints` (`SAVE_PROJECT`, `SAVE_SUGGESTION`, `LOAD_PROJECTS`, `AUTOCOMPLETE`). There is no `apiBase` key.
- Use localized config or helper globals for staging and alternate hosting. Do not bake local machine paths into the scripts.

### Anonymous Portal
- `[anonymous_doc_portal]` is registered by the `AnonymousDocPortal` class in `inc/features.php`.
- Uploads are sent through `admin-ajax.php` via the `submit_anonymous_doc` action.
- Each file and its notes are sealed with the portal's public key (libsodium sealed box) before being written to `/wp-content/uploads/anonymous-submissions/`; the server never holds the private key, and the portal refuses uploads without a usable key. The owner opens downloads locally with `scripts/anon-portal-decrypt.php`.
- `CLOUDMERSIVE_API_KEY` enables antivirus scanning before files are accepted.

## Scripts and Tooling

- `CLAUDE.md` lists every build, import and test command; most tests are `scripts/test-*.php` run offline against `tmp/prod.sqlite` with Local's `php.exe`.
- `scripts/aggregate-all-programs.js` rebuilds the program name lists used by the auto-linker.
- `scripts/build-network-graph.js` / `scripts/test-network-graph.js` build and check the network map.
- Keep generated JSON artifacts and their producing scripts in sync when changing these pipelines.

## Collaboration Preferences

- Work on `main`; no feature branches.
- Prefer the modular structure that already exists. New shared PHP logic should usually live in `inc/` or `api/`, not back in `functions.php`.
- Keep page-template specific JavaScript in dedicated entry files and let `inc/enqueue.php` decide when they load.
- A new table holding facility ids is registered in `kop_fmerge_ref_tables()`; a new public column goes in `inc/open-data.php` by name.
- Anything the owner reviews ships with a review screen (usually a review inbox source), with Undo.
- No emojis anywhere; icons come from `kop_icon()` / `kopIcon()`.
- When changing program data, regenerate the aggregate files before finishing.
- Update `CLAUDE.md`, `AGENTS.md` or the focused docs in `docs/` when architecture or workflows change materially.

## Visual and Accessibility Direction

- Use the established campaign palette from `css/colors.css`.
- Core brand colors:
  - Soft Pastel Yellow: `#FFF5CB`
  - Mint Green: `#B6E3D4`
  - Teal: `#33A7B5`
  - Navy Blue: `#000080`
  - Midnight Blue: `#000435`
  - Orange: `#EF9034`
  - White: `#FFFFFF`
  - Chartreuse: `#B2E102`
  - Pale Spring Yellow: `#ECF385`
  - Coral Pink: `#FE8088`
  - Sand / Warm Ivory: `#F2EEDF`
  - Powder Blue: `#AEE0ED`
  - Bubblegum Pink: `#FC8ED6`
- Every text passes WCAG AA and every icon 3:1, in every state; see "Kadence colour traps" in `CLAUDE.md` (accent text uses `--kop-*-ink`, white text sits on `--kop-*-fill`).
- Use the brightest accents for highlights, outlines, and focus states rather than large background fills.
- Use `var(--kop-*)` variables from `css/colors.css` instead of introducing new hard-coded palette values.

## Helpful References

- `CLAUDE.md`
- `README.md`
- `DATABASE-COLUMNS.md`
- `QUICK-REFERENCE.md`
- `environment-summary.md`
- `docs/PLAN.md`
- `docs/FACILITY-SCHEMA.md`
- `docs/state-inspection-reports/README.md`
- `docs/data-forms/README.md`
- `docs/admin-submissions/README.md`
- `docs/anonymous-portal/README.md`
- `docs/VISUAL-EXAMPLES.md`
