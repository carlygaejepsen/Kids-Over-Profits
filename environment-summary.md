# Environment and Access Summary

This document outlines the key details of the development and server environment for the Kids Over Profits project.

## 1. Local Development Environment

*   **Platform:** Flywheel Local (WordPress local development environment)
*   **Operating System:** Windows
*   **Project Root:** `c:\Users\daniu\Local Sites\kids-over-profits\`
*   **WordPress Root:** `app/public/` (relative to project root)
*   **Child Theme Directory:** `app/public/wp-content/themes/child/`
*   **Web Server:** Apache (via Flywheel Local)
*   **PHP Version:** 8.2.29
*   **Database:** MySQL (accessible via Flywheel Local's Adminer)
*   **Local URL:** https://kids-over-profits.local
*   **Version Control:** Git repository maintained within the child theme directory
*   **Current Branch:** `staging.v2`
*   **Default Branch:** `main`

## 2. Production Server Environment

*   **Hosting Provider:** NixiHost (shared hosting, cPanel)
*   **Web Server:** Apache with LiteSpeed
*   **PHP Version:** 8.2 (handler: `ea-php82___lsphp`)
*   **Database:** MySQL (provisioned through hosting)
*   **Remote Access:** SSH access available for shell commands
*   **Deployment:** Git deployment via `.cpanel.yml` or manual file transfer

## 3. Application Details

*   **Framework:** WordPress
*   **Theme Architecture:** Kadence parent theme with custom child theme
    *   **Parent Theme:** Kadence
    *   **Child Theme Directory:** `child`
*   **Active Plugins:**
    *   **Security:** Wordfence WAF (loaded via `.htaccess`)
    *   **Caching:** LiteSpeed Cache (LSCache)
    *   **SEO:** Yoast SEO (wordpress-seo)
    *   **Custom Fields:** Advanced Custom Fields
    *   **Media:** FileBird Pro, Media Sync, PDF Embedder
    *   **Donations:** Givebutter
    *   **Analytics:** Google Site Kit
    *   **Backup:** UpdraftPlus
    *   **And more...** (33 total plugins)

## 4. Custom Application Architecture

The child theme contains a full-stack data management application:

### Database Tables
*   `facilities_master` — Official facility records (admin-managed)
*   `suggested_edits` — Public-submitted changes pending approval
*   `locations_master` — Location/address data for facilities
*   `referrers_master` — Referrer information for facilities

### API Endpoints (`api/`)
*   **Admin Workflow:**
    *   `get-master-data.php` — Fetches official records
    *   `save-master.php` — Saves directly to master table
*   **Public Suggestion Workflow:**
    *   `save-suggestion.php` — Saves to suggested_edits for review
    *   `process-edit.php` — Admin approval/rejection handler
*   **Shared:**
    *   `get-autocomplete.php` — Form field autocomplete
    *   `approve-edits.php` — Admin approval UI

### Page Templates
*   `page-admin-data.php` — Admin data entry interface
*   `page-data.php` — Public suggestion form
*   `page-tti-program-index.php` — Facility directory
*   `page-wiki-editor.php` — Wiki content editor
*   `page-news-processor.php` — News processing interface

### JavaScript Modules (`js/`)
*   `js/data-form/` — Facility data entry form system (modular architecture)
*   `js/inspections/` — State inspection report displays
*   `js/data/` — Static JSON fallback datasets

### Stylesheets (`css/`)
*   `data-form.css`, `colors.css`, `facility-reports.css`, `wiki-editor.css`, etc.

## 5. Configuration Files

*   **`.env`** — Environment variables (gitignored, contains credentials)
*   **`.env.example`** — Template for `.env` file
*   **`.cpanel.yml`** — cPanel Git deployment automation
*   **`.htaccess`** — Apache directives (Wordfence, LiteSpeed)
*   **`tailwind.config.js`** — Tailwind CSS configuration
*   **`api/config.php`** — Database configuration loader
*   **`api/config.local.php`** — Local database credentials (gitignored)

## 6. Database Credential Configuration

`api/config.php` resolves connection settings from multiple sources in this priority order:

### Source Priority
1. **`.env` file** — Loaded first, sets environment variables
2. **WordPress constants** — From `wp-config.php` (KOP_* or DB_* constants)
3. **Environment variables** — System-level environment
4. **Local override file** — `api/config.local.php` (fallback)

### Credential Resolution Order
*   **Host:** `KOP_DB_HOST` → `DB_HOST`
*   **Database:** `KOP_DB_NAME` → `DB_NAME`
*   **Username:** `KOP_DB_USER` → `KOP_DB_USERNAME` → `DB_USER` → `DB_USERNAME`
*   **Password:** `KOP_DB_PASS` → `KOP_DB_PASSWORD` → `DB_PASS` → `DB_PASSWORD`

### Setup Options
1. **For Flywheel Local:** Create `.env` file with database credentials from Flywheel's Database tab
2. **For Production:** Credentials are typically in `wp-config.php`
3. **For CLI scripts:** Copy `api/config.php.example` to `api/config.local.php` and configure

When credentials are missing, the API responds with HTTP 500 and a JSON payload describing the missing fields.

## 7. Development Workflow

1. Start the site in Flywheel Local
2. Make changes in `app/public/wp-content/themes/child/`
3. Test changes at `https://kids-over-profits.local`
4. Commit changes from within the child theme directory
5. Export database if schema changes were made
6. Deploy to production via Git or manual transfer

## 8. Documentation

*   **`AGENTS.md`** — Central developer guide (architecture, conventions, design)
*   **`environment-summary.md`** — This file
*   **`COLOR_SYSTEM_SUMMARY.md`** — Color palette and design system

