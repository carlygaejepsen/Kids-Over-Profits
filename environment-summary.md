# Environment and Access Summary

This document summarizes the runtime expectations for the Kids Over Profits child theme.

## 1. Repo Scope

- Repository type: standalone WordPress child theme source
- Theme stack: Kadence parent theme plus this child theme
- Runtime expectation: this repo may be mounted into any compatible WordPress install
- Do not assume a fixed local checkout path such as `app/public/wp-content/themes/child/`
- Prefer WordPress path helpers like `get_stylesheet_directory()` and `get_stylesheet_directory_uri()` over hard-coded filesystem paths

## 2. Production Environment

- Hosting provider: NixiHost shared hosting with cPanel
- Web server: Apache with LiteSpeed
- PHP version: 8.2 (`ea-php82___lsphp`)
- Database: MySQL
- Deployment: `.cpanel.yml` or manual file transfer
- Remote access: SSH is available outside the repo, but no credentials are stored here

## 3. Application Runtime

### Core Bootstrap
- `functions.php` is now a thin bootstrap
- most theme logic lives in:
  - `inc/utilities.php`
  - `inc/enqueue.php`
  - `inc/database.php`
  - `inc/rest-api.php`
  - `inc/admin.php`
  - `inc/features.php`

### Main Data Stores
- `facilities_master`
- `suggested_edits`
- `locations_master`
- `referrers_master`
- `wiki_submissions`
- `news_submissions`
- `inspection_facilities`
- `inspection_reports`

### Primary Endpoints
- `api/get-master-data.php`
- `api/save-master.php`
- `api/save-suggestion.php`
- `api/process-edit.php`
- `api/save-wiki-submission.php`
- `api/save-news-submission.php`
- `api/manage-submissions.php`
- `api/inspections-read.php`
- `api/inspections-write.php`

## 4. Key Pages

- `page-admin-data.php`
- `page-data.php`
- `page-admin-submissions.php`
- `page-tti-program-index.php`
- `page-state-reports.php`
- `page-location-index.php`
- `page-referrer-index.php`
- `page-wiki-editor.php`
- `page-wiki-feed.php`
- `page-news-processor.php`
- `page-news-feed.php`

## 5. Configuration Files

- `.env.example`
- `.cpanel.yml`
- `.htaccess`
- `api/config.php`
- `api/config.local.php`
- `submissions_database.sql`

## 6. Credential Resolution

`api/config.php` resolves database credentials in this order:
1. `.env`
2. WordPress constants (`KOP_*` or `DB_*`)
3. environment variables
4. `api/config.local.php`

Credential keys:
- host: `KOP_DB_HOST` then `DB_HOST`
- database: `KOP_DB_NAME` then `DB_NAME`
- username: `KOP_DB_USER`, `KOP_DB_USERNAME`, `DB_USER`, `DB_USERNAME`
- password: `KOP_DB_PASS`, `KOP_DB_PASSWORD`, `DB_PASS`, `DB_PASSWORD`

## 7. Local Development Expectations

- Use any WordPress environment that can mount this child theme alongside the Kadence parent theme.
- Avoid documentation that assumes a single machine path or a specific Flywheel Local checkout.
- Test the workflows that your changes affect: data forms, TTI index, state reports, wiki/news tooling, and anonymous portal.

## 8. Documentation References

- `AGENTS.md`
- `README.md`
- `QUICK-REFERENCE.md`
- `docs/README.md`
