# Kids Over Profits – Contributor Guide

Welcome! This repository powers the **Kids Over Profits** WordPress child theme. Before making changes, read through this guide to understand the environment, collaboration preferences, and visual direction.

## Environment Snapshot

### Production Environment
- **Hosting provider & control panel:** NixiHost shared hosting managed through cPanel (Softaculous WordPress Manager for application administration).
- **Web server:** Apache with LiteSpeed
- **PHP version:** 8.2 (`ea-php82___lsphp`)
- **Database:** MySQL provisioned through the hosting environment.
- **CMS stack:** WordPress with the Kadence parent theme and this custom child theme.
- **Active plugins & services:**
  - Security: Wordfence Web Application Firewall (WAF)
  - Caching: LiteSpeed Cache (LSCache) configured via `.htaccess`
- **Access constraints:** No production credentials live in the repo; automation must work only with the checked-in files.

### Local Development Environment
- **Platform:** Flywheel Local (WordPress local development environment)
- **Project root:** `c:\Users\daniu\Local Sites\kids-over-profits\`
- **Web server:** Apache
- **PHP version:** 8.2.29
- **Database:** MySQL (accessible via Flywheel Local's Adminer or command line)
- **WordPress root:** `app/public/` (relative to project root)
- **Child theme directory:** `app/public/wp-content/themes/child/`
- **Version control:** Git repository maintained within the child theme directory

For more operational background, consult `environment-summary.md` and other documentation files in the repository.

## Flywheel Local Development Setup

### Directory Structure
The Flywheel Local environment organizes the project as follows:

```
c:\Users\daniu\Local Sites\kids-over-profits\
├── app\
│   ├── public\                    # WordPress root directory
│   │   ├── wp-admin\              # WordPress admin
│   │   ├── wp-content\
│   │   │   ├── themes\
│   │   │   │   └── child\         # This repository (Git-tracked)
│   │   │   ├── plugins\           # WordPress plugins
│   │   │   └── uploads\           # Media uploads
│   │   ├── wp-config.php          # WordPress configuration
│   │   ├── *.sql                  # Database export files
│   │   └── [WordPress core files]
│   └── sql\                       # Database backups
├── conf\                          # Server configuration
│   ├── apache\                    # Apache config
│   ├── php-8.2.29\                # PHP config
│   └── mysql\                     # MySQL config
└── logs\                          # Server logs
```

### Working with Flywheel Local
- **Starting the site:** Open Flywheel Local and start the "kids-over-profits" site
- **Accessing the site:** https://kids-over-profits.local (or the domain configured in Flywheel)
- **Database access:**
  - Use Flywheel Local's built-in Adminer (Database tab → Open Adminer)
  - Or connect via command line tools to localhost
- **File synchronization:** The `app/public/wp-content/themes/child/` directory is the Git repository
- **Database exports:** Export `.sql` files to `app/sql/` or `app/public/` for versioning
- **Configuration files:** Server configs are in `conf/` - modify only if necessary
- **Logs:** Check `logs/` for debugging web server and PHP errors

### Development Workflow
1. Start the site in Flywheel Local
2. Make changes in `app/public/wp-content/themes/child/`
3. Test changes at the local URL
4. Commit changes from within the child theme directory
5. Export database if schema changes were made
6. Deploy to production via Git deployment or manual file transfer

## Repository Layout

This repository (the child theme) contains a full-stack data management application built within a WordPress environment. Here's a breakdown of the key components:

### Child Theme Root Files
-   **`functions.php`**: The core of the child theme. It acts as the backend orchestrator, loading all necessary scripts and styles, defining API endpoints, and adding custom features to the WordPress admin area. It contains the main PHP logic for data handling and page-specific script loading.
-   **`style.css`**: The main stylesheet for the child theme, which primarily contains theme header information and can be used for global style overrides.
-   **`page-*.php`**: Custom page templates for WordPress pages (e.g., `page-admin-data.php`, `page-data.php`, `page-tti-program-index.php`, `page-wiki-editor.php`)
-   **`.env`**: Environment-specific configuration (gitignored, see `.env.example` for template)
-   **`.cpanel.yml`**: cPanel deployment configuration
-   **`.htaccess`**: Apache/LiteSpeed server directives
-   **`tailwind.config.js`**: Tailwind CSS configuration (if using Tailwind)

### Core Directories

-   **`api/`**: Backend API scripts for the data management system. Supports two distinct workflows: administrator and public suggestions.
    -   **`api/config.php`**: Database configuration loader that reads credentials from WordPress constants, environment variables, or `config.local.php`
    -   **`api/config.local.php`**: Local database credentials (gitignored, use `config.php.example` as template)

    -   **Administrator Workflow:**
        -   **`get-master-data.php`**: Fetches all official records from the `facilities_master` table to populate the form for admins.
        -   **`save-master.php`**: Saves data directly to the `facilities_master` table, used when an admin creates or updates a record.

    -   **Public Suggestion Workflow:**
        -   **`save-suggestion.php`**: A public endpoint that saves proposed changes to a separate `suggested_edits` table for review. It does *not* touch the live data.
        -   **`process-edit.php`**: The backend for the admin approval page. It allows an admin to approve or reject a pending suggestion. If approved, the data is moved from `suggested_edits` to the `facilities_master` table.

    -   **Shared Endpoints:**
        -   **`get-autocomplete.php`**: Provides autocomplete suggestions for form fields by querying both master and suggested data, ensuring consistency.
        -   **`approve-edits.php`**: Provides the frontend UI for the admin approval page, which uses `process-edit.php` to perform its actions.

-   **`js/`**: Client-side JavaScript files powering interactive features
    -   **`js/data-form/`**: Facility data entry form scripts
        -   `facility-form.v3.js` - Core admin data entry form (dynamic fields, validation, API communication)
        -   `db-form-loader.js` - Data loading and project management
        -   `facility-report-generator.js` - Generate printable HTML reports from form data
        -   `notes.js` - Facility notes functionality
        -   `referrer-form.js` - Referrer data entry and management
        -   `kadence-nav-guard.js` - Prevent Kadence theme navigation conflicts
        -   `admin-data-page.js` - Admin-specific page functionality
        -   `data-page.js` - Public suggestion page functionality
    -   **`js/inspections/`**: State inspection report scripts
        -   `facilities-display.js` - Public searchable facility directory (TTI Program Index page)
        -   `ca-reports.js`, `tx_reports.js`, `az_reports.js`, etc. - State-specific report pages
    -   **`js/data/`**: JSON data files for reports (fallback datasets)
    -   `anonymous-portal.js` - Anonymous document submission interface
    -   `autocomplete.js` - Form field autocomplete functionality
    -   `news-processor.js` - News content processing
    -   `tti-program-index.js` - TTI program index page logic
    -   `wiki-editor.js` - Wiki editor functionality

-   **`css/`**: Stylesheets for the project
    -   `data-form.css` - Facility data entry form styling (layout, typography, buttons, responsive design)
    -   `anonymous-portal.css` - Anonymous portal styling
    -   `colors.css` - Campaign color palette definitions
    -   `facility-reports.css` - Report generation styling
    -   `news-processor.css` - News processor styling
    -   `tti-program-index.css` - TTI index page styling
    -   `wiki-editor.css` - Wiki editor styling

-   **`templates/`**: PHP template files loaded via shortcodes or page templates
    -   `data-form-admin.php` - Administrator data entry interface (direct master database access)
    -   `data-form-public.php` - Public suggestion interface (submissions for review)

-   **`docs/`**: Project documentation
    -   **`docs/phase-0/`**: Initial project phase documentation
        -   `phase-0-1-requirements.md` - Project requirements
        -   `phase-0-2-technical-decisions.md` - Technical architecture decisions
        -   `phase-0-3-environment-transition.md` - Environment migration notes
    -   `security-hardening.md` - Security implementation guidelines

-   **`tests/`**: Test files and scripts
    -   **`tests/db-form/`**: Database form test suite

-   **`Wiki Editor Template/`**: Template files for wiki editor functionality

### WordPress Integration
All active pages are PHP-based WordPress pages that load templates or shortcodes:
-   Admin data form uses the `data-form-admin.php` template
-   Public suggestion form uses the `data-form-public.php` template
-   TTI Program Index page uses the `[facilities_display]` shortcode which injects `<div id="facilities-container"></div>` where `js/inspections/facilities-display.js` renders the database
-   Other pages are created as standard WordPress pages with embedded shortcodes or custom page templates

### Documentation Files
-   `AGENTS.md` - This file! Central developer guide (architecture, conventions, design principles)
-   `environment-summary.md` - Environment and access details
-   `DATABASE_SETUP_GUIDE.md` - Database configuration and setup
-   `DATA_FORM_TEMPLATE_SETUP.md` - Data form template configuration
-   `COLOR_SYSTEM_SUMMARY.md` - Color palette and design system
-   `KADENCE_NAV_FIX_SUMMARY.md` - Kadence theme navigation fixes
-   `ISSUE_FIX_SUMMARY.md` - Issue tracking and resolution
-   `NOTES_MIGRATION_GUIDE.md` - Notes system migration guide
-   `DIAGNOSTIC_TESTS.md` - System diagnostic procedures

## Collaboration Preferences
- **Versioning:** When iterating on assets, prefer explicit versioned filenames instead of overwriting (e.g., `facility-form.v4.js`). Preserve prior versions unless instructed otherwise.
- **Code style:** Follow established patterns—procedural PHP for endpoints, modular ES6 for scripts, and WordPress-friendly conventions throughout. Do not introduce new build tooling unless necessary.
- **Documentation:** Update this guide or the relevant `*-summary.md` files when environment or process details change.
- **Testing:** Where possible, validate changes against a WordPress instance running the Kadence parent theme plus this child theme.

## Visual & UX Direction
Use the preferred campaign color palette for new UI work:
- Soft Pastel Yellow — `#FFF5CB`
- Mint Green — `#B6E3D4`
- Teal — `#33A7B5`
- Navy Blue — `#000080`
- Midnight Blue — `#000435`
- Orange — `#EF9034`
- White — `#FFFFFF`
- Chartreuse — `#B2E102`
- Pale Spring Yellow — `#ECF385`
- Coral Pink — `#FE8088`
- Sand / Warm Ivory — `#F2EEDF`
- Powder Blue — `#AEE0ED`
- Bubblegum Pink — `#FC8ED6`

Favor accessible contrasts and align UI accents with the bold blues and teals. Reserve the brighter lime and pink tones (`Chartreuse`, `Coral Pink`, `Bubblegum Pink`) for borders, outlines, and other highlight treatments rather than full backgrounds. When working with the softer pastels, use them as glow or shadow accents layered over neutral bases to preserve legibility. When styling text, ensure headings remain readable against light backgrounds from the palette.

Thanks for contributing! Maintain consistency with the structure above to ensure smooth collaboration.

## Runtime data flow

- **Facilities datasets and REST export** — `kop_get_facility_projects_dataset_urls()` discovers JSON exports in `js/data/` and orders them by newest-first so any consumer can fall back to the freshest static bundle (`functions.php`). When `kop_register_facilities_rest_routes()` runs on `rest_api_init` it exposes `kop/v1/facilities`, returning the live project set assembled by `kop_get_facilities_projects_from_database()`; this keeps cached bundles and database exports aligned. Finally, `load_facilities_data()` (hooked to `wp_enqueue_scripts`) merges the REST endpoint with any discovered static bundles, localizing both into `js/facilities-display.js` so the TTI index page can prefer the live API while gracefully degrading to packaged datasets if the API is unreachable (`functions.php`, `js/facilities-display.js`).

## Facility form configuration contract

- **Localized config object** — `wp_localize_script('facility-form-script', 'KOP_FACILITY_FORM_CONFIG', …)` guarantees every page that embeds `[facility_form]` exposes at least `fallbackProjectsUrl`, `fallbackProjectsUrls`, and `apiBase` (`functions.php`). The JavaScript loader (`js/facility-form.v3.js`) normalizes those inputs into ordered fallback lists, supplements them with optional `apiBaseFallbacks`, and resolves endpoint overrides supplied via `KOP_FACILITY_FORM_CONFIG.endpoints` before falling back to bundled resolver targets such as `/wp-content/themes/child/api/save-master.php`, `get-master-data.php`, and `get-autocomplete.php` (`api/save-master.php`, `api/get-master-data.php`, `api/get-autocomplete.php`). It also reads `mode` (or global `FORM_MODE`) to toggle between master and suggestion workflows, inspects `debug` / `debugLogging` flags (plus `window.KOP_FACILITY_FORM_DEBUG` or storage toggles) to enable verbose logging, and records the prioritized fallback dataset URL list so the form can hydrate itself when the API is offline.
- **Endpoint resolution helpers** — During boot the script looks for `window.KOP_API.getEndpoint()` and `window.KOP_THEME_BASES` to augment the localized config, ensuring custom hosting setups can centralize path discovery without editing the bundle. Contributors configuring staging environments should populate the localized object (or global helpers) instead of hard-coding paths so that API requests continue to point at the intended WordPress root even when the theme directory moves (`js/facility-form.v3.js`).

## Anonymous document portal guidance

- **Shortcode & assets** — Register `[anonymous_doc_portal]` anywhere to render the portal; the constructor of `AnonymousDocPortal` wires the shortcode, enqueues `js/anonymous-portal.js` and `css/anonymous-portal.css`, and localizes AJAX metadata (`functions.php`, `js/anonymous-portal.js`).
- **AJAX workflow** — Both authenticated and public submissions hit the `submit_anonymous_doc` action via `admin-ajax.php`, protected by the `anonymous_doc_nonce`. The handler issues unique submission folders and sanitizes filenames before persisting uploads (`functions.php`).
- **Upload hardening** — On instantiation the class creates `/wp-content/uploads/anonymous-submissions/` alongside a deny-all `.htaccess` and placeholder `index.php` to prevent direct access or directory listing (`functions.php`).
- **Cloudmersive scanning** — Define `CLOUDMERSIVE_API_KEY` in `wp-config.php` (or elsewhere before `functions.php` loads) so `scan_file_cloudmersive()` can submit each file to the Cloudmersive antivirus API; without the constant the system logs a warning and skips scanning, so production environments must supply a valid key (`functions.php`).
