# Kids Over Profits Child Theme Workspace

## Purpose
This child theme is built as a lightweight WordPress shell plus a full data-management system that powers Kids Over Profits. Use this README to understand the central features, entry points, and how to work safely inside the workspace.

## Workspace Layout & Key Capabilities
- **Root scope:** The repository lives at `app/public/wp-content/themes/child/` inside Flywheel Local; every hook, template, asset, and API point is maintained here.
- **Primary goals:** Manage authoritative facilities (`facilities_master`), allow public suggestions (`suggested_edits`), keep program metadata up to date (auto-linker data pipeline), host wiki/news submissions, display inspection reports, and offer an anonymous document portal.
- **Styles & design:** Use `css/colors.css` for the palette and layer page styles (`data-form.css`, `wiki-editor.css`, `news-processor.css`) on top of the Kadence parent theme.
- **Orchestration:** `functions.php` controls script enqueueing and data localization, guards Kadence navigation on headerless pages, and exposes `/wp-json/kop/v1/facilities`.
- **Root assets & helpers:** The top level also tracks `style.css` (theme header), `.htaccess`, `.cpanel.yml`, `tailwind.config.js`, `.env.example`, `clear-cache.php`, and `submissions_database.sql`.

## Directory Rundown
- **`api/`** — Procedural PHP endpoints (master/suggestion CRUD, autocomplete, wiki/news submissions, diagnostics) that consume credentials from `.env`, WordPress constants, environment vars, or `api/config.local.php`.
- **`templates/` & `page-*`** — Shared partials (`data-form-admin.php`, `data-form-public.php`) plus templates for admin/public forms, wiki/news, submissions, and the TTI program index.
- **`js/`** — Modular frontend stack covering data forms (`js/data-form/`, `js/data-form-modules/`), inspections (`js/inspections/`), fallback datasets (`js/data/`), and single-entry utilities (`autocomplete.js`, `anonymous-portal.js`, `wiki-editor.js`, `news-processor.js`, `tti-program-index.js`).
- **`css/`** — Color palette in `colors.css` and page-specific sheets (`data-form.css`, `wiki-editor.css`, `news-processor.css`, `facility-reports.css`, `anonymous-portal.css`, `tti-program-index.css`).
- **`scripts/`** — Node tools such as `aggregate-all-programs.js`, `rebuild-search-index.js`, `extract-reddit-wiki-links.js`, `batch-extract-helper.js` that regenerate program metadata consumed by the auto-linker.
- **`tests/`** — Currently holds `tests/db-form/` (add tests here as the form system grows).

## Key Pages & Workflows
### Admin Data Form
- Template: `page-admin-data.php` includes `templates/data-form-admin.php` and the admin-only JavaScript stack (e.g., `admin-data-page.js`, `data-form.v4.js`, plus shared modules).
- Workflow: Admin edits save directly to `facilities_master` via `api/save-master.php` and `api/get-master-data.php`.
- Features: Full field notes, referrer/location helpers, autocomplete, report generation, clipboard/JSON exports, and admin tooling.

### Public Suggestion Form
- Template: `page-data.php` loads `templates/data-form-public.php` and the modular form stack but uses `data-page.js` plus `mode='suggestion'` configuration.
- Workflow: Public submissions go into `suggested_edits`; approvals run through `api/process-edit.php`.

### TTI Program Index (Facility Directory)
- Template: `page-tti-program-index.php` and `functions.php::load_facilities_data()` provide REST data plus static fallbacks.
- Frontend: `js/inspections/facilities-display.js` renders a searchable grid, preferring live `/wp-json/kop/v1/facilities` but falling back on `js/data/*.json`.

### Wiki Editor & News Processor
- Templates: `page-wiki-editor.php` and `page-news-processor.php` bootstrap `js/wiki-editor.js` / `js/news-processor.js` and corresponding styles.
- Workflows: Submissions post to `api/save-wiki-submission.php` or `api/save-news-submission.php`. Admins review via `manage-submissions.php`.

### Admin Submissions & Anonymous Portal
- Admin Submissions Page: `page-admin-submissions.php` is the control center for reviewing public edits, wiki entries, and news articles.
- Anonymous Portal: Instantiate `[anonymous_doc_portal]` to enqueue `anonymous-portal.js`, upload files into `/wp-content/uploads/anonymous-submissions/`, and optionally scan via Cloudmersive (set `CLOUDMERSIVE_API_KEY` before `functions.php` loads).

## Program Data Pipeline
- Program data sources live in `js/data/`; state-specific files under `reddit-wiki/programs-XX.json` plus the “leftover” `tti-program-links.json`.
- After editing any source, run `node scripts/aggregate-all-programs.js` to regenerate `programs-array.json`, `search-index.json`, and metadata (see `QUICK-REFERENCE.md`).
- Additional scripts (`extract-reddit-wiki-links.js`, `rebuild-search-index.js`, etc.) help maintain links and indexes.

## Component Reference
- **`functions.php`:** The orchestrator for enqueueing scripts, guarding Kadence navigation, registering REST endpoints, and initializing `AnonymousDocPortal`.
- **Top-level assets:** `style.css`, `.htaccess`, `.cpanel.yml`, `tailwind.config.js`, `.env.example`, `clear-cache.php`, `submissions_database.sql`, and similar helpers control deployment, caching, tailwind tooling, and schema initialization.
- **`api/`:** Procedural endpoints handling CRUD (admin master, suggestions), autocomplete, submissions, diagnostics, and credential resolution.
- **`templates/`:** Shared HTML fragments that reduce duplication across page templates.
- **`page-*`:** Templates such as `page-admin-data.php`, `page-data.php`, `page-admin-submissions.php`, `page-wiki-editor.php`, `page-news-processor.php`, and `page-tti-program-index.php` load the precise scripts and styles needed by each workflow.
- **`js/`:** Modular frontend stack:
  - `js/data-form/` holds the data form utilities (`utilities.js`, `location-form.js`, `referrer-form.js`, `notes.js`, `db-form-loader.js`, `data-report-generator.js`, plus the orchestrator `data-form.v4.js`).
  - `js/data-form-modules/` contains the newer modules (`config.js`, `data-normalizer.js`, `api.js`, `project.js`, `ui.*`, `admin-data-page.js`, `data-page.js`).
  - `js/inspections/` powers the TTI program index and the state-specific inspection reports (`ca-reports.js`, `tx-reports.js`, etc.).
  - `js/data/` stores static JSON exports consumed when live APIs fail and by the auto-linker.
  - Entry scripts (`autocomplete.js`, `anonymous-portal.js`, `wiki-editor.js`, `news-processor.js`, `tti-program-index.js`, `facility-toolbar.js`) service their respective templates.
- **`css/`:** Palette and page stylesheets (`colors.css`, `data-form.css`, `wiki-editor.css`, `news-processor.css`, `facility-reports.css`, `anonymous-portal.css`, `tti-program-index.css`). Prefer `var(--kop-*)` props for new styling.
- **`scripts/`:** Data tooling (`aggregate-all-programs.js`, `rebuild-search-index.js`, `extract-reddit-wiki-links.js`, `batch-extract-helper.js`) that rewrites program metadata for the auto-linker.

## Development Workflow
1. Start the Flywheel Local site (`kids-over-profits`) and browse `https://kids-over-profits.local`.
2. Work inside `app/public/wp-content/themes/child/`; maintain the Kadence parent theme activation.
3. Modify PHP templates, JS modules, CSS, or API scripts as needed.
4. Run `node scripts/aggregate-all-programs.js` after editing program data files (`js/data/*`).
5. Test admin/public form flows, TTI index, wiki/news interfaces, and the anonymous portal.
6. Commit from within the child theme folder; export database snapshots if schema changes.
7. Deploy via `.cpanel.yml` (Git) or manual upload to NixiHost/cPanel for production.

## Configuration & Deployment Notes
- **Credentials:** Local `.env` or WordPress constants feed `api/config.php`. Use `.env.example` as a template; never commit secrets.
- **Caching/Security:** `.htaccess` manages Wordfence WAF and LiteSpeed Cache rules.
- **Tailwind:** `tailwind.config.js` exists for optional work, but most styles are handcrafted.
- **Color system:** `css/colors.css` and the palette in `AGENTS.md` explain accessible contrasts and highlight rules.

## Helpful References
- `AGENTS.md` for architecture, conventions, and palette guidance.
- `environment-summary.md` for development/production environments.
- `QUICK-REFERENCE.md` for program data workflows and aggregation reminders.
- `submissions_database.sql` for the wiki/news submissions schema.