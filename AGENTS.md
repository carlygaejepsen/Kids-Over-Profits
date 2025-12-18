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

This repository (the child theme) contains a full-stack data management application built within a WordPress environment. The application manages facility data through a dual-workflow system: administrative direct access and public suggestion submission.

### Architecture Overview

The application follows a traditional WordPress child theme structure with custom API endpoints, template files, and page-specific JavaScript modules. Key architectural patterns:

1. **Backend API Layer** (`api/`): PHP scripts handle database operations independent of WordPress REST API
2. **Template System** (`templates/`): Custom page templates loaded via WordPress page assignments
3. **Frontend Modules** (`js/`): Modular JavaScript files loaded conditionally based on page context
4. **Page Templates** (`page-*.php`): WordPress page template files that determine which assets load
5. **Conditional Loading** (`functions.php`): Smart script enqueuing based on page templates and slugs

### Database Schema

The application uses six main database tables:
- **`facilities_master`**: Official facility records (admin-managed)
- **`suggested_edits`**: Public-submitted changes pending approval
- **`locations_master`**: Location/address data for facilities
- **`referrers_master`**: Referrer information for facilities
- **`wiki_submissions`**: Wiki editor form submissions (TTI program wiki entries)
- **`news_submissions`**: News processor form submissions (processed news articles)

### Dependency Chain

```
functions.php (orchestrator)
    ↓
Page Templates (page-*.php) detect current page
    ↓
Conditional script loading based on template/slug
    ↓
Template Files (templates/*.php) OR page content
    ↓
JavaScript Modules load (js/*) with localized config
    ↓
API Endpoints called (api/*.php)
    ↓
Database operations (MySQL tables)
```

Here's a breakdown of the key components:

### Child Theme Root Files

-   **`functions.php`** (~1,850 lines) - **Core orchestrator**
    - Enqueues parent theme styles and color variables globally
    - Implements conditional script loading based on page template/slug
    - Registers REST API routes for facilities data export (`/wp-json/kop/v1/facilities`)
    - Implements `AnonymousDocPortal` class for document submissions
    - Handles Kadence theme navigation conflicts on headerless pages
    - Key hooks: `wp_enqueue_scripts`, `rest_api_init`, `admin_menu`
    - Major functions:
      - `kadence_child_enqueue_styles()` - Base CSS loading
      - `kop_enqueue_kadence_nav_guard()` - Navigation guard (loads first)
      - `load_facilities_data()` - TTI Program Index data
      - `enqueue_data_form_script()` - Form assets (admin/public)
      - `kop_enqueue_report_scripts()` - State report scripts
      - `kop_register_facilities_rest_routes()` - REST API setup

-   **`style.css`** - WordPress theme header
    - Theme metadata (Theme Name, Version, Template, etc.)
    - Required by WordPress to identify child theme
    - Minimal global CSS overrides

-   **Page Templates** - WordPress custom page templates:
    - `page-admin-data.php` - Includes `templates/data-form-admin.php`
    - `page-data.php` - Includes `templates/data-form-public.php`
    - `page-data-test.php` - Development/testing template
    - `page-tti-program-index.php` - Facility directory page
    - `page-wiki-editor.php` - Wiki content editor
    - `page-news-processor.php` - News processing interface

-   **Configuration Files**:
    - `.env` - Environment variables (gitignored, database credentials, API keys)
    - `.env.example` - Template for `.env` file
    - `.cpanel.yml` - cPanel Git deployment automation config
    - `.htaccess` - Apache directives (Wordfence WAF, LiteSpeed Cache)
    - `tailwind.config.js` - Tailwind configuration (minimal usage)
    - `.gitignore` - Excludes `.env`, `config.local.php`, etc.
    - `submissions_database.sql` - SQL schema for wiki/news submission tables

### Core Directories

-   **`api/`**: Backend API scripts for the data management system. Supports multiple workflows: administrator, public suggestions, and content submissions.
    -   **`api/config.php`**: Database configuration loader that reads credentials from `.env`, WordPress constants, environment variables, or `config.local.php`
    -   **`api/config.local.php`**: Local database credentials (gitignored, use `config.php.example` as template)

    -   **Administrator Workflow:**
        -   **`get-master-data.php`**: Fetches all official records from the `facilities_master` table to populate the form for admins.
        -   **`save-master.php`**: Saves data directly to the `facilities_master` table, used when an admin creates or updates a record.

    -   **Public Suggestion Workflow:**
        -   **`save-suggestion.php`**: A public endpoint that saves proposed changes to a separate `suggested_edits` table for review. It does *not* touch the live data.
        -   **`process-edit.php`**: The backend for the admin approval page. It allows an admin to approve or reject a pending suggestion. If approved, the data is moved from `suggested_edits` to the `facilities_master` table.

    -   **Content Submission Workflow:**
        -   **`save-wiki-submission.php`**: Saves wiki editor form submissions (TTI program wiki entries) to `wiki_submissions` table.
        -   **`save-news-submission.php`**: Saves news processor form submissions (processed news articles) to `news_submissions` table.
        -   **`manage-submissions.php`**: Admin interface for reviewing and managing wiki/news submissions.
        -   **`init-submissions-db.php`**: Database initialization script that creates `wiki_submissions` and `news_submissions` tables. Run with `?init=1` parameter or via CLI.

    -   **Shared Endpoints:**
        -   **`get-autocomplete.php`**: Provides autocomplete suggestions for form fields by querying both master and suggested data, ensuring consistency.
        -   **`approve-edits.php`**: Provides the frontend UI for the admin approval page, which uses `process-edit.php` to perform its actions.
        -   **`news_processor.php`**: Backend for news article processing.
        -   **`wiki_editor.php`**: Backend for wiki content editing.

    -   **Utility/Debug Scripts:**
        -   **`init-database.php`**: Creates/updates main database tables.
        -   **`check-env.php`**, **`check-data.php`**, **`check-functions.php`**: Diagnostic utilities.
        -   **`test-connection.php`**, **`test-php.php`**: Connection and PHP testing.
        -   **`cleanup-lowercase.php`**: Data cleanup utilities.

-   **`js/`**: Client-side JavaScript files (modular architecture)

    -   **`js/data-form/`**: Facility data entry form system (modular architecture)

        -   `utilities.js` (~430 lines) - **Shared utility library (no dependencies)**
            - Debug logging with conditional output
            - Data manipulation (deepClone, nested getters/setters, resolvePathTarget)
            - String utilities (escapeHtmlForAttr, case conversion, city/state parsing)
            - Export utilities (copyToClipboard, downloadJSON, buildProjectExport)
            - UI helpers (showUploadStatus)
            - **This file provides global functions used by all other form modules**

        -   `data-form.v4.js` (~1,900 lines) - **Main form orchestrator**
            - Form initialization and lifecycle coordination
            - Event listener attachment and field mapping
            - Data loading/saving orchestration via module delegation
            - Reads `KOP_FACILITY_FORM_CONFIG` localized by `functions.php`
            - Dependencies: All modules below (loaded via functions.php)
            - Modes: `master` (admin) or `suggestion` (public)
            - **Delegates most functionality to specialized modules**

        -   `location-form.js` (~550 lines) - **Location data management**
            - Location/address field management
            - City, state, postal code handling
            - US state and country constants (US_STATE_SET, COUNTRY_SET)
            - Dependencies: `utilities.js`, `db-form-loader.js`

        -   `referrer-form.js` (~1,200 lines) - **Referrer data management**
            - Referrer information management
            - Auto-population from referrers_master table
            - Consultant navigation and data structures
            - Dependencies: `utilities.js`, `db-form-loader.js`

        -   `notes.js` (~1,200 lines) - **Field notes system**
            - CRUD operations for field-level notes
            - Note registration and rendering
            - Exports `window.NotesModule` namespace
            - Dependencies: `db-form-loader.js`, `referrer-form.js`

        -   `db-form-loader.js` (~630 lines) - **Data loading infrastructure**
            - Project list fetching and caching
            - Custom data loading from localStorage
            - Exports `window.KOP_FormLoader` namespace
            - Dependencies: `utilities.js`

        -   `facility-report-generator.js` (~1,300 lines) - **Report generation**
            - Generates printable HTML reports from form data
            - Export and download functionality
            - Dependencies: `utilities.js`

    -   **`js/data-form-modules/`**: Core data form modules (new modular architecture)

        -   `config.js` (~179 lines) - **Configuration module**
            - API endpoint resolution and normalization
            - Form mode detection (master vs. suggestions)
            - Debug logging configuration
            - Exports `window.KOP_FormConfig` namespace
            - **No dependencies - loads first**

        -   `data-normalizer.js` (~677 lines) - **Data normalization**
            - Project data structure normalization
            - Field name standardization
            - Default value initialization
            - Exports `window.KOP_DataNormalizer.normalizeProjectData()`
            - Dependencies: `referrer-form.js` (for create functions)

        -   `api.js` (~412 lines) - **API communication layer**
            - Cloud data loading (`loadAllProjectsFromCloud`)
            - Project persistence (`saveProjectToCloud`, `persistProjectLocally`)
            - Fallback dataset loading
            - Exports `window.KOP_API` namespace
            - Dependencies: `config.js`, `data-normalizer.js`

        -   `project.js` (~361 lines) - **Project management**
            - Project CRUD operations (create, load, delete, rename, recategorize)
            - Category determination and switching
            - New project initialization
            - Exports `window.KOP_Project` namespace
            - Dependencies: `api.js`

        -   `ui.js` (~68 lines) - **UI base module**
            - Array value update helpers
            - Provides `updateArrayItemValue` and `updateArrayObjectItemValue`
            - Exports `window.KOP_UI` namespace
            - Dependencies: Global utilities (`resolvePathTarget`, `getNestedValue`, `setNestedValue`)

        -   `ui-events.js` (~161 lines) - **UI event handlers**
            - Section toggle controls
            - Mobile section controls
            - Overview tab switching
            - Exports `window.KOP_UI_Events` namespace
            - Dependencies: jQuery only

        -   `ui-render.js` (~804 lines) - **UI rendering engine**
            - Facility controls rendering
            - Table of contents generation
            - Saved projects list rendering
            - Array rendering and management
            - Exports `window.KOP_UI_Render` namespace
            - Dependencies: `ui-events.js`

        -   `ui-state.js` (~109 lines) - **UI state management**
            - Project status tracking
            - Label updates for project types
            - Exports `window.KOP_UI_State` namespace
            - Dependencies: `ui-render.js`

        -   `ui-actions.js` (~373 lines) - **UI actions and mutations**
            - Facility operations (add, remove, clone, sort)
            - Facility navigation (previous, next)
            - Array item management
            - Exports `window.KOP_UI_Actions` namespace
            - Dependencies: All UI modules + `project.js` + `api.js`

        -   `kadence-nav-guard.js` (~330 lines) - **Navigation conflict prevention**
            - Prevents Kadence theme errors on headerless pages
            - Intercepts DOM queries for missing navigation elements
            - **Loaded globally with highest priority (no dependencies)**
            - Smart detection: strict blocking on headerless pages, graceful fallbacks elsewhere

        -   `admin-data-page.js` (~2,800 lines) - **Admin page orchestrator**
            - Coordinates all admin form modules
            - Admin-specific UI and features
            - Loaded only when `page-admin-data.php` template is active
            - Dependencies: ALL form modules above

        -   `data-page.js` (~2,900 lines) - **Public page orchestrator**
            - Coordinates public suggestion form
            - Restricted features for public users
            - Loaded only when `page-data.php` template is active
            - Dependencies: Core form modules (subset of admin)

    -   **`js/inspections/`**: State inspection report display
        -   `facilities-display.js` (~800 lines) - **Facility directory**
            - Searchable, sortable facility table
            - Fetches from REST API or fallback JSON
            - Loaded on TTI Program Index page
            - Dependencies: Localized data from `functions.php::load_facilities_data()`

        -   State-specific report pages (~600-800 lines each):
            - `az_reports.js` - Arizona inspection reports
            - `ca-reports.js` - California inspection reports
            - `ct_reports.js` - Connecticut inspection reports
            - `mt_reports.js` - Montana inspection reports
            - `tx_reports.js` - Texas inspection reports
            - `ut_reports.js` - Utah inspection reports
            - `wa_reports.js` - Washington inspection reports

    -   **`js/data/`**: Static JSON fallback datasets
        - Facility data exports (organized by date)
        - Used when REST API unavailable
        - Auto-discovered by `functions.php::kop_get_facility_projects_dataset_urls()`

    -   **Root-level modules**:
        -   `anonymous-portal.js` - Anonymous document submission (AJAX file uploads)
        -   `autocomplete.js` (~1,200 lines) - Form field autocomplete (queries `api/get-autocomplete.php`)
        -   `news-processor.js` - News content processing and article submission
        -   `tti-program-index.js` - TTI index page coordination with facility filtering
        -   `wiki-editor.js` (~2,500 lines) - Wiki content management with rich text editing, form submissions to `wiki_submissions` table

-   **`css/`**: Stylesheets for the project
    -   `data-form.css` - Facility data entry form styling (layout, typography, buttons, responsive design)
    -   `anonymous-portal.css` - Anonymous portal styling
    -   `colors.css` - Campaign color palette definitions
    -   `facility-reports.css` - Report generation styling
    -   `news-processor.css` - News processor styling
    -   `tti-program-index.css` - TTI index page styling
    -   `wiki-editor.css` - Wiki editor styling

-   **`templates/`**: PHP template files included by page templates

    -   `data-form-admin.php` (~2,300 lines) - **Administrator data entry interface**
        - Complete data form HTML structure
        - Field groups: Facility Info, Contact, Programs, Incidents, Inspections, etc.
        - Included by `page-admin-data.php`
        - Triggers conditional loading of admin form scripts
        - Database mode: Direct writes to `facilities_master` table

    -   `data-form-public.php` (~2,300 lines) - **Public suggestion interface**
        - Nearly identical structure to admin template
        - Included by `page-data.php`
        - Triggers loading of public suggestion scripts
        - Database mode: Writes to `suggested_edits` table for approval
        - Feature restrictions: No direct delete, requires approval workflow

-   **`tests/`**: Test files and scripts
    -   **`tests/db-form/`**: Database form test suite

-   **`Wiki Editor Template/`**: Template files for wiki editor functionality

### WordPress Integration & Page Loading System

The application uses conditional script loading based on page templates and slugs. Here's how each page works:

**1. Admin Data Entry Page**
```
WordPress Page (template: page-admin-data.php)
    ↓
functions.php::enqueue_data_form_script() detects template
    ↓
Loads modules in dependency order:
  1. utilities.js (global helpers)
  2. config.js → data-normalizer.js → api.js → project.js
  3. ui.js → ui-events.js → ui-render.js → ui-state.js → ui-actions.js
  4. db-form-loader.js, location-form.js, referrer-form.js, notes.js
  5. autocomplete.js, facility-report-generator.js, facility-toolbar.js
  6. data-form.v4.js (orchestrator)
  7. admin-data-page.js (page-specific logic)
    ↓
Localizes KOP_DATA_FORM_CONFIG with: mode='master', apiBase, endpoints
    ↓
User interacts → KOP_API module → Saves to facilities_master table
```

**2. Public Suggestion Page**
```
WordPress Page (template: page-data.php)
    ↓
Template includes templates/data-form-public.php
    ↓
functions.php::enqueue_data_form_script() detects template
    ↓
Loads same assets but: js/data-form/data-page.js instead of admin
    ↓
Localizes KOP_DATA_FORM_CONFIG with: mode='suggestion'
    ↓
User submits → API calls → Saves to suggested_edits table (requires approval)
```

**3. TTI Program Index Page**
```
WordPress Page (slug: tti-program-index, template: page-tti-program-index.php)
    ↓
functions.php::load_facilities_data() detects page slug
    ↓
Loads: js/inspections/facilities-display.js, css/tti-program-index.css
    ↓
Localizes facility data from REST API (/wp-json/kop/v1/facilities) or JSON fallback
    ↓
JavaScript renders searchable facility directory in #facilities-container
```

**4. Wiki Editor Page**
- Template: `page-wiki-editor.php`
- Loads: `js/wiki-editor.js`, `css/wiki-editor.css`
- Provides rich text editing interface

**5. State Report Pages**
- Detected by slug pattern: `{state}-reports` (e.g., 'ca-reports', 'tx-reports')
- `functions.php::kop_enqueue_report_scripts()` loads state-specific JS
- Each loads corresponding inspection report data

**6. Anonymous Document Portal**
- Shortcode: `[anonymous_doc_portal]`
- Can be embedded in any WordPress page
- `AnonymousDocPortal` class handles rendering and AJAX
- Loads: `js/anonymous-portal.js`, `css/anonymous-portal.css`
- Uploads stored in `/wp-content/uploads/anonymous-submissions/`

**Script Loading Detection Pattern**:
```php
// functions.php uses WordPress conditional tags:
is_page_template('page-admin-data.php')  → Admin form assets
is_page_template('page-data.php')        → Public form assets
is_page('tti-program-index')             → Facility display
is_page('wiki-editor')                    → Wiki editor
preg_match('/-reports$/', $slug)         → State reports
```

### Documentation Files
    -   `AGENTS.md` - This file! Central developer guide (architecture, conventions, design principles)
    -   `environment-summary.md` - Environment and access details
    -   Color system & accessibility guidance (consolidated here; COLOR_SYSTEM_SUMMARY.md kept only for legacy reference)

## Collaboration Preferences
- **Versioning:** When iterating on assets, prefer explicit versioned filenames instead of overwriting (e.g., `data-form.v4.js`). Preserve prior versions unless instructed otherwise.
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

## Color System & Accessibility (consolidated)
- **Palette source:** Colors live as CSS custom properties in `css/colors.css` with primary blues/teal/greens, bright accents (Orange, Chartreuse, Coral Pink, Bubblegum Pink), and light backgrounds (Sand, Soft Pastel Yellow, Pale Spring Yellow, White).
- **Enqueue order:** `functions.php` registers `kop-colors` globally; dependent styles include `data-form.css` (global + page templates), `wiki-editor.css`, `news-processor.css`, `facility-reports.css`, and `anonymous-portal.css` so color variables load before page styles.
- **Adoption status:** `wiki-editor.css` and `news-processor.css` are fully converted to the palette and WCAG AA compliant; `data-form.css`, `facility-reports.css`, and `anonymous-portal.css` still need conversion to variables and a contrast audit.
- **Accessibility guardrails:** Target WCAG AA (4.5:1 normal text, 3:1 large text/UI). Safe pairings: Midnight/Navy text on light backgrounds; White text on Navy/Midnight/Teal/Orange/Chartreuse. Avoid Teal text on dark blues and White on the light pastels.
- **Bright accents:** Keep Chartreuse, Coral Pink, and Bubblegum Pink for borders, outlines, focus states, and highlights rather than full backgrounds.
- **Next steps:** Replace remaining hardcoded colors with `var(--kop-*)`, run axe or WAVE on key pages, and document standard buttons/fields that use the palette.

Thanks for contributing! Maintain consistency with the structure above to ensure smooth collaboration.

## Runtime data flow

- **Facilities datasets and REST export** — `kop_get_facility_projects_dataset_urls()` discovers JSON exports in `js/data/` and orders them by newest-first so any consumer can fall back to the freshest static bundle (`functions.php`). When `kop_register_facilities_rest_routes()` runs on `rest_api_init` it exposes `kop/v1/facilities`, returning the live project set assembled by `kop_get_facilities_projects_from_database()`; this keeps cached bundles and database exports aligned. Finally, `load_facilities_data()` (hooked to `wp_enqueue_scripts`) merges the REST endpoint with any discovered static bundles, localizing both into `js/facilities-display.js` so the TTI index page can prefer the live API while gracefully degrading to packaged datasets if the API is unreachable (`functions.php`, `js/facilities-display.js`).

## Data form configuration contract

- **Localized config object** — `wp_localize_script('data-form-script', 'KOP_DATA_FORM_CONFIG', …)` guarantees every page that embeds `[data_form]` exposes at least `fallbackProjectsUrl`, `fallbackProjectsUrls`, and `apiBase` (`functions.php`). The JavaScript loader (`js/data-form.v4.js`) normalizes those inputs into ordered fallback lists, supplements them with optional `apiBaseFallbacks`, and resolves endpoint overrides supplied via `KOP_DATA_FORM_CONFIG.endpoints` before falling back to bundled resolver targets such as `/wp-content/themes/child/api/save-master.php`, `get-master-data.php`, and `get-autocomplete.php` (`api/save-master.php`, `api/get-master-data.php`, `api/get-autocomplete.php`). It also reads `mode` (or global `FORM_MODE`) to toggle between master and suggestion workflows, inspects `debug` / `debugLogging` flags (plus `window.KOP_DATA_FORM_DEBUG` or storage toggles) to enable verbose logging, and records the prioritized fallback dataset URL list so the form can hydrate itself when the API is offline.
- **Endpoint resolution helpers** — During boot the script looks for `window.KOP_API.getEndpoint()` and `window.KOP_THEME_BASES` to augment the localized config, ensuring custom hosting setups can centralize path discovery without editing the bundle. Contributors configuring staging environments should populate the localized object (or global helpers) instead of hard-coding paths so that API requests continue to point at the intended WordPress root even when the theme directory moves (`js/data-form.v4.js`).

## Anonymous document portal guidance

- **Shortcode & assets** — Register `[anonymous_doc_portal]` anywhere to render the portal; the constructor of `AnonymousDocPortal` wires the shortcode, enqueues `js/anonymous-portal.js` and `css/anonymous-portal.css`, and localizes AJAX metadata (`functions.php`, `js/anonymous-portal.js`).
- **AJAX workflow** — Both authenticated and public submissions hit the `submit_anonymous_doc` action via `admin-ajax.php`, protected by the `anonymous_doc_nonce`. The handler issues unique submission folders and sanitizes filenames before persisting uploads (`functions.php`).
- **Upload hardening** — On instantiation the class creates `/wp-content/uploads/anonymous-submissions/` alongside a deny-all `.htaccess` and placeholder `index.php` to prevent direct access or directory listing (`functions.php`).
- **Cloudmersive scanning** — Define `CLOUDMERSIVE_API_KEY` in `wp-config.php` (or elsewhere before `functions.php` loads) so `scan_file_cloudmersive()` can submit each file to the Cloudmersive antivirus API; without the constant the system logs a warning and skips scanning, so production environments must supply a valid key (`functions.php`).
