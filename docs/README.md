# Kids Over Profits Documentation

This folder contains focused documentation for the major workflows inside the Kids Over Profits child theme.

## Workflow Docs

### [News Processor](./news-processor/README.md)
AI-assisted article intake, extraction, review, and submission for TTI-related news coverage.

### [Wiki Editor](./wiki-editor/README.md)
Structured wiki entry editing, markdown generation, import, browsing, and submission workflows.

### [TTI Program Index](./tti-program-index/README.md)
Public facility directory and search experience backed by the facilities REST endpoint with fallback datasets.

### [State Inspection Reports](./state-inspection-reports/README.md)
State-specific regulatory inspection viewers for Arizona, Arkansas, California, Connecticut, Minnesota, Montana, Texas, Utah, and Washington.

### [Admin Submissions](./admin-submissions/README.md)
Dashboard for reviewing wiki, news, and data-form submissions.

### [Data Forms](./data-forms/README.md)
Administrative and public data-entry forms for facilities, operators, locations, and referrers.

### [Anonymous Portal](./anonymous-portal/README.md)
Secure anonymous document upload flow for whistleblowers, survivors, and staff.

## Repository Runtime Overview

- `functions.php` is now a thin bootstrap that loads the real runtime from `inc/*.php`.
- `inc/` contains the theme modules for enqueueing, REST routes, database helpers, admin glue, utilities, and feature classes.
- `api/` contains the procedural endpoints for CRUD, submissions, inspections, diagnostics, and AI processing.
- `page-*.php` files provide WordPress page templates; `templates/` holds shared markup fragments.
- `js/` and `css/` contain the page entry points, module scripts, and page-specific styles.
- `scripts/` contains data pipelines, importers, and inspection scrapers such as `scripts/mn_scraper.py`.

## Current Notes

- State inspection reports now use a shared report-page structure and surface API-backed `last updated` timestamps.
- The TTI Program Index prefers `/wp-json/kop/v1/facilities` and falls back to packaged datasets only when necessary.
- The data-form stack relies on localized `KOP_DATA_FORM_CONFIG` values instead of environment-specific local filesystem paths.
- This repo should be treated as the child theme source itself, not as a full local WordPress checkout.
