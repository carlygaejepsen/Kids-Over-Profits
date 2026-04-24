# Kids Over Profits Contributor Guide

This repository is the Kids Over Profits WordPress child theme. Treat it as the theme source itself, not as a full local WordPress or Flywheel site checkout. Older instructions that assumed a specific local repo path are obsolete for this repo.

## Environment Snapshot

### Production Environment
- Hosting: NixiHost shared hosting via cPanel
- Web server: Apache with LiteSpeed
- PHP: 8.2 (`ea-php82___lsphp`)
- Database: MySQL
- Theme stack: Kadence parent theme plus this child theme
- Infrastructure in play: Wordfence WAF and LiteSpeed Cache
- Access constraint: no production credentials live in the repo

### Repo Assumptions
- This repo may be checked out standalone and mounted into a WordPress install separately.
- Do not assume sibling `wp-admin`, `wp-content/plugins`, or Flywheel Local directories exist next to this checkout.
- Prefer WordPress path helpers such as `get_stylesheet_directory()` and `get_stylesheet_directory_uri()` over hard-coded local paths.
- For environment specifics, see `environment-summary.md`.

## Recent Important Changes

### April 2026
- `functions.php` is now a thin bootstrap file. Most theme logic moved into `inc/utilities.php`, `inc/enqueue.php`, `inc/database.php`, `inc/rest-api.php`, `inc/admin.php`, and `inc/features.php`.
- State inspection reports now use a shared page structure with a title, intro, alphabet filter, search and sort controls, results container, and `last-updated` slot.
- `api/inspections-read.php` now returns a `scraped_timestamp` value, and the state report pages surface that timestamp to visitors.
- The report pages were updated to render full facility report histories instead of truncating to a subset.
- Arkansas and Minnesota were added to the state report stack.
- Washington and Montana report rendering were normalized around the shared report-page behavior so their API payload handling is more consistent.
- Minnesota now has an in-repo scraper at `scripts/mn_scraper.py` that generates `js/data/mn_reports.json`, and the Minnesota renderer and dataset were refreshed again on 2026-04-24.
- `templates/data-form-admin.php`, `templates/data-form-public.php`, and `css/data-form.css` were reorganized for clearer field grouping and better form usability.

## Architecture Overview

### Bootstrap and Core Modules
- `functions.php` only wires the theme together and delegates nearly all logic to `inc/*.php`.
- `inc/utilities.php` contains shared helpers and context detection.
- `inc/enqueue.php` owns script and style loading, page-specific asset routing, and localized config objects.
- `inc/database.php` contains database access helpers for facilities and related records.
- `inc/rest-api.php` registers the `kop/v1/*` REST routes and related callbacks.
- `inc/admin.php` contains admin menu and admin-page glue code.
- `inc/features.php` contains feature classes such as `AnonymousDocPortal`.

### Data Stores
- `facilities_master` stores the authoritative facility records.
- `suggested_edits` stores public submissions awaiting review.
- `locations_master` stores normalized location records.
- `referrers_master` stores referrers and consultant data.
- `wiki_submissions` stores wiki editor submissions.
- `news_submissions` stores news processor submissions.
- `inspection_facilities` stores inspection-report facility records by state.
- `inspection_reports` stores the individual inspection and citation records linked to `inspection_facilities`.

### API Layer
- `api/get-master-data.php` and `api/save-master.php` power the admin data form.
- `api/save-suggestion.php` and `api/process-edit.php` power the public suggestion workflow and approval pipeline.
- `api/manage-submissions.php`, `api/save-wiki-submission.php`, and `api/save-news-submission.php` power the submission review flows.
- `api/inspections-read.php` and `api/inspections-write.php` power the state inspection reports.
- `api/config.php` resolves credentials from `.env`, WordPress constants, environment variables, or `api/config.local.php`.

## Repository Layout

### Primary Directories
- `api/` contains procedural PHP endpoints for CRUD, submissions, inspections, and diagnostics.
- `inc/` contains the modularized theme bootstrap code.
- `templates/` contains shared PHP fragments used by page templates.
- `js/` contains page entry points, data-form modules, inspection renderers, and helper libraries.
- `css/` contains the shared palette and page-specific stylesheets.
- `scripts/` contains data pipeline tools, importers, and scrapers.
- `docs/` contains focused workflow docs for admin submissions, data forms, inspection reports, the anonymous portal, the TTI index, and visual examples.

### Key Root Files
- `style.css` contains the WordPress child theme header.
- `.cpanel.yml` handles cPanel deployment automation.
- `.htaccess` contains Wordfence and LiteSpeed related rules.
- `.env.example` is the credential template.
- `submissions_database.sql` contains the wiki and news submission schema.
- `README.md`, `QUICK-REFERENCE.md`, and `environment-summary.md` are the main supporting docs.

## Major Pages and Workflows

### Core Data Management
- `page-admin-data.php` plus `templates/data-form-admin.php` provide the admin-only master-data form.
- `page-data.php` plus `templates/data-form-public.php` provide the public suggestion form.
- `page-admin-submissions.php` plus `js/admin-submissions.js` provide the admin review UI for submissions and edits.

### Public Directory and Index Pages
- `page-tti-program-index.php` powers the public facility index and related search experience.
- `page-location-index.php` powers the location directory.
- `page-referrer-index.php` powers the consultant and referrer directory.

### State Inspection Reports
- `page-state-reports.php` is the root page template for state report pages.
- `templates/page-state-reports.php` currently mirrors the shared report-page markup; keep the root and template versions aligned until one is removed.
- `css/facility-reports.css` now provides the shared layout and styling for report pages.

### Content Pages
- `page-wiki-editor.php` and `page-wiki-feed.php` cover wiki submission and published wiki feed flows.
- `page-news-processor.php` and `page-news-feed.php` cover news submission and published news feed flows.
- `[anonymous_doc_portal]` renders the anonymous document portal.

## JavaScript Structure

### Data Form Stack
- `js/data-form/` contains the older shared modules and the `data-form.v4.js` orchestrator.
- `js/data-form-modules/` contains the newer modular UI, API, and normalization layers used by the admin and public forms.
- `js/autocomplete.js`, `js/field-tooltips.js`, and `js/tutorial-overlay.js` support the data form experience.

### Inspection and Directory Stack
- `js/inspections/facilities-display.js` powers the facility directory display.
- Supported state report renderers currently include:
  - `az_reports.js`
  - `ar_reports.js`
  - `ca-reports.js`
  - `ct_reports.js`
  - `mn_reports.js`
  - `mt_reports.js`
  - `tx_reports.js`
  - `ut_reports.js`
  - `wa_reports.js`
- `js/tti-program-index.js`, `js/location-index.js`, `js/referrer-index-v2.js`, and `js/document-library.js` support the public directory pages.

### Content and Submission Tools
- `js/wiki-editor.js`, `js/wiki-generation.js`, and `js/wiki-parser.js` drive the wiki tooling.
- `js/news-processor.js` drives the news workflow.
- `js/anonymous-portal.js` drives anonymous document uploads.

## Inspection Report Runtime

- `inc/enqueue.php::kop_enqueue_report_scripts()` maps the current state report page slugs:
  - `ca-reports`
  - `ut-reports`
  - `az-reports`
  - `tx-reports`
  - `mt-reports`
  - `ct-reports`
  - `wa-reports`
  - `ar-reports`
  - `mn-reports`
- Each slug loads a state-specific renderer from `js/inspections/` plus `css/facility-reports.css`.
- The primary data source is `api/inspections-read.php?state=XX`.
- Static JSON files in `js/data/` remain fallback or historical snapshots:
  - California uses `js/data/ccl_reports_batch_*.json`
  - Utah uses `js/data/ut_checklists/ut_reports*.json` or `js/data/ut_reports*.json`
  - Arizona uses `js/data/az_reports/*.json`
  - Connecticut, Washington, Montana, and Minnesota use state JSON files in `js/data/`
- `api/inspections-write.php` is the write endpoint for scraper output.
- `scripts/mn_scraper.py` is the current in-repo example scraper for the inspections pipeline.

## Program Data Pipeline

- State program sources live under `js/data/reddit-wiki/`.
- Uncategorized leftovers live in `js/data/tti-program-links.json`.
- Generated component files include `programs-array.json`, `search-index.json`, `metadata.json`, and `index.json`.
- After editing state files or leftovers, run:

```bash
node scripts/aggregate-all-programs.js
```

- Do not edit generated component files directly.

## Runtime Data Flow

### Facilities and REST Export
- `inc/rest-api.php` exposes `kop/v1/facilities`.
- `inc/enqueue.php::load_facilities_data()` discovers static dataset URLs, prefers the live REST endpoint when available, and localizes the ordered source list into the facility display scripts.
- The TTI Program Index should prefer live REST data and fall back to packaged JSON only when necessary.

### Data Form Configuration Contract
- `inc/enqueue.php` localizes `KOP_DATA_FORM_CONFIG` for pages that load the data form stack.
- The JavaScript loader expects `apiBase`, `fallbackProjectsUrl`, `fallbackProjectsUrls`, and optional endpoint overrides instead of hard-coded environment-specific paths.
- Use localized config or helper globals for staging and alternate hosting. Do not bake local machine paths into the scripts.
- `mode` selects `master` vs `suggestion` workflows.
- `debug` and `debugLogging` flags, plus related globals, control verbose client-side logging.

### Anonymous Portal
- `[anonymous_doc_portal]` is registered by the `AnonymousDocPortal` class in `inc/features.php`.
- Uploads are sent through `admin-ajax.php` via the `submit_anonymous_doc` action.
- Uploads land in `/wp-content/uploads/anonymous-submissions/` with deny-by-default hardening files.
- `CLOUDMERSIVE_API_KEY` enables antivirus scanning before files are accepted.

## Scripts and Tooling

- `scripts/aggregate-all-programs.js` rebuilds the program datasets used by the auto-linker and directory pages.
- `scripts/import-location-pages.js`, `scripts/import-location-projects.php`, and `scripts/merge-location-duplicates.php` support the newer location-directory pipeline.
- `scripts/generate_combined_index.py` and `api/generate-combined-index.php` support combined index generation.
- Keep generated JSON artifacts and their producing scripts in sync when changing these pipelines.

## Collaboration Preferences

- Prefer the modular structure that already exists. New shared PHP logic should usually live in `inc/` or `api/`, not back in `functions.php`.
- Keep page-template specific JavaScript in dedicated entry files and let `inc/enqueue.php` decide when they load.
- When updating shared report-page markup, check both `page-state-reports.php` and `templates/page-state-reports.php`.
- When changing program data, regenerate the aggregate files before finishing.
- Update `AGENTS.md` or the focused docs in `docs/` when architecture or workflows change materially.

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
- Prefer accessible contrast pairings and use the brightest accents for highlights, outlines, and focus states rather than large background fills.
- Use `var(--kop-*)` variables from `css/colors.css` instead of introducing new hard-coded palette values when possible.

## Helpful References

- `README.md`
- `QUICK-REFERENCE.md`
- `environment-summary.md`
- `docs/state-inspection-reports/README.md`
- `docs/data-forms/README.md`
- `docs/admin-submissions/README.md`
- `docs/anonymous-portal/README.md`
- `docs/VISUAL-EXAMPLES.md`
