# Environment and Access Summary

Runtime expectations for the Kids Over Profits child theme. Updated 2026-10-13.

## 1. Repo Scope

- Repository type: standalone WordPress child theme source (Kadence parent)
- The repo may be mounted into any compatible WordPress install; do not assume a fixed local checkout path
- Prefer WordPress path helpers like `get_stylesheet_directory()` and `get_stylesheet_directory_uri()` over hard-coded filesystem paths
- No build step: plain PHP, vanilla JavaScript, CSS

## 2. Environments

- **Production (default target)**: https://kidsoverprofits.org, NixiHost shared hosting with cPanel, Apache with LiteSpeed, PHP 8.2 (`ea-php82`), MySQL, table prefix `wpdl_`
- **Local (only when asked)**: Flywheel Local at https://kids-over-profits.local
- **Offline**: most tests run against `tmp/prod.sqlite`, a SQLite mirror of production made by `scripts/sync-prod-sqlite.py` (never committed)
- The server has no Node; `scripts/*.js` builds run locally or on GitHub Actions
- Server CLI jobs run with `/opt/cpanel/ea-php82/root/usr/bin/php` (the cron `php` is php-cgi). WP-Cron runs from the server's crontab every 5 minutes (`inc/server-cron.php`)

## 3. Deployment

- Push to `main`: `.github/workflows/deploy.yml` runs the `.cpanel.yml` tasks, which copy the theme into `public_html`
- A deleted file is not removed on the server unless `.cpanel.yml` has a task for it
- `.github/workflows/build-network-map.yml` rebuilds the network map when its inputs change (dispatched by `inc/network-rebuild.php`); `discover-articles.yml` is the news discovery workflow (the live run is the server cron)
- Seeds in `seeds/` apply once per version on deploy

## 4. Application Runtime

### Bootstrap
- `functions.php` is a thin loader; logic lives in `inc/*.php` (core: `utilities.php`, `enqueue.php`, `database.php`, `rest-api.php`, `admin.php`, `features.php`; one file per subsystem beside them)
- The KOP Tools admin menu is the `wp-plugins/kop-tools/` plugin

### Two databases
- WordPress database: WordPress tables, `{prefix}kop_*` tables and the facility model (`facilities_v2`, `{prefix}kop_operators`, ...)
- Records database (from `api/config.php`): `news_submissions`, `lawsuits`, `legislation`, `memorial_victims`, `news_featured` and the other records tables. Read them with `kop_seed_pdo()`, never `$wpdb`
- Table list: `DATABASE-COLUMNS.md`

### Main endpoints and routes
- REST namespace `kop/v1` (`inc/rest-api.php` and subsystem files): `facilities` (directory feed), `state/<slug>`, `facility/<slug>` and `operator/<slug>` (mobile app, `inc/mobile-api.php`), `mobile/submit`, `extension/*`, `app/session`
- `api/get-master-data.php`, `api/save-master.php` (admin data form), `api/save-suggestion.php`, `api/process-edit.php` (public suggestions)
- `api/save-wiki-submission.php`, `api/save-news-submission.php`, `api/manage-submissions.php` (submissions and review)
- `api/inspections-read.php`, `api/inspections-write.php` (state inspection reports)

## 5. Key Page Templates (all in `templates/`)

- Data: `page-admin-data.php`, `page-data.php`, `page-admin-data-manager.php`, `page-admin-submissions.php` (review inbox)
- Directories: `page-tti-program-index.php` (company and location tabs), `page-referrer-index.php`, `page-transporter-index.php`, `page-provider-index.php`, `page-marketer-index.php`, `page-tradeorg-index.php`, `page-young-adult-programs.php`
- Generated pages (routes, not WordPress pages): `facility-page.php`, `operator-page.php`, `operator-index.php`, `document-types.php`, `featured-reporting.php`, `site-map.php`, `how-to-use.php`, `send-page.php`
- Places and reports: `page-state.php`, `page-country.php`, `page-state-reports.php`, `page-severe-reports.php`, `page-network-map.php`
- Content: `page-hub.php`, `page-article.php`, `page-wiki-editor.php`, `page-wiki-feed.php`, `page-news-processor.php`, `page-news-feed.php`, `page-glossary.php`, `page-report-abuse.php`, `page-open-data.php`

## 6. Configuration Files

- `.env.example` (template for `.env`: database, `KOP_DATA_API_KEY`, `GROQ_API_KEY`, `GEMINI_API_KEY`, ...)
- `.cpanel.yml`, `.htaccess`
- `api/config.php`, `api/config.local.php` (gitignored)
- `submissions_database.sql`

## 7. Credential Resolution

`api/config.php`:
1. loads `.env` (nearest one up the tree) into the environment
2. includes `wp-config.php` and takes `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`/`DB_PASS` from its constants, else from the environment
3. lets `api/config.local.php` (`$localConfig`: `host`, `name`, `user`, `pass`) override them
4. fills anything still missing from `KOP_DB_HOST`, `KOP_DB_NAME`, `KOP_DB_USER`/`KOP_DB_USERNAME`, `KOP_DB_PASS`/`KOP_DB_PASSWORD` (constants or environment)

Other secrets (AI keys, `KOP_GITHUB_DISPATCH_TOKEN`, `KOP_COURTLISTENER_TOKEN`, `CLOUDMERSIVE_API_KEY`) are read the same way, from `api/config.local.php` or `.env`. Never commit either file.

## 8. Documentation References

- `CLAUDE.md` (commands and tests per subsystem)
- `AGENTS.md`, `README.md`, `DATABASE-COLUMNS.md`, `QUICK-REFERENCE.md`
- `docs/README.md`, `docs/PLAN.md`
