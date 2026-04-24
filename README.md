# Kids Over Profits Child Theme

This repository is the source for the Kids Over Profits WordPress child theme. Treat it as the theme codebase itself, not as a full local WordPress checkout.

## Repo Assumptions

- The repo can be mounted into any compatible WordPress install.
- Do not assume a fixed local path such as `app/public/wp-content/themes/child/`.
- Use WordPress path helpers and localized config objects instead of hard-coded machine paths.

## Current Architecture

### Bootstrap
- `functions.php` is now a thin loader.
- Most runtime logic lives in `inc/utilities.php`, `inc/enqueue.php`, `inc/database.php`, `inc/rest-api.php`, `inc/admin.php`, and `inc/features.php`.

### Core Areas
- `api/` contains procedural endpoints for facilities data, submissions, inspections, AI processing, and diagnostics.
- `page-*.php` and `templates/` provide the page templates and shared markup fragments.
- `js/` contains the data-form stack, state report renderers, wiki/news tooling, and public directory scripts.
- `css/` contains the shared color system and page-level styles.
- `scripts/` contains aggregation, import, and scraping utilities.
- `docs/` contains focused workflow documentation.

## Major Workflows

### Data Forms
- `page-admin-data.php` plus `templates/data-form-admin.php` handle direct admin editing.
- `page-data.php` plus `templates/data-form-public.php` handle public suggestions.
- The frontend relies on `KOP_DATA_FORM_CONFIG` and the `js/data-form/` plus `js/data-form-modules/` stacks.

### TTI Program Index
- `page-tti-program-index.php` and `js/tti-program-index.js` power the public facility directory.
- Primary source is `/wp-json/kop/v1/facilities` with API and static JSON fallbacks.

### State Inspection Reports
- `page-state-reports.php` and `templates/page-state-reports.php` provide the shared report-page layout.
- Current state stack includes Arizona, Arkansas, California, Connecticut, Minnesota, Montana, Texas, Utah, and Washington.
- `api/inspections-read.php` now exposes `scraped_timestamp`, which drives the visible `last updated` display.

### Wiki, News, and Admin Review
- `page-wiki-editor.php` and `page-news-processor.php` power the submission tools.
- `page-wiki-feed.php` and `page-news-feed.php` render published feed views.
- `page-admin-submissions.php` and `api/manage-submissions.php` drive review workflows.
- `[anonymous_doc_portal]` renders the anonymous document upload flow.

## Program Data Pipeline

- canonical program sources live under `js/data/reddit-wiki/`
- uncategorized leftovers live in `js/data/tti-program-links.json`
- regenerate aggregate program files with:

```bash
node scripts/aggregate-all-programs.js
```

Do not edit generated aggregate files directly.

## Helpful References

- `AGENTS.md`
- `environment-summary.md`
- `QUICK-REFERENCE.md`
- `docs/README.md`
- `docs/state-inspection-reports/README.md`
