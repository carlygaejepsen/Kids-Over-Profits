# Kids Over Profits – Contributor Guide

Welcome! This repository powers the **Kids Over Profits** WordPress child theme. Before making changes, read through this guide to understand the environment, collaboration preferences, and visual direction.

## Environment Snapshot
- **Hosting provider & control panel:** NixiHost shared hosting managed through cPanel (Softaculous WordPress Manager for application administration).
- **Web server & PHP:** LiteSpeed with PHP 8.2 (`ea-php82___lsphp`).
- **Database:** MySQL provisioned through the hosting environment.
- **CMS stack:** WordPress with the Kadence parent theme and this custom child theme.
- **Local development context:** Historically maintained from Windows at `c:\\Users\\daniu\\OneDrive\\Documents\\GitHub\\Kids-Over-Profits`.
- **Access constraints:** No production credentials live in the repo; automation must work only with the checked-in files.

For more operational background, consult `environment-summary.md`, `troubleshooting-summary.md`, and `ca-reports.html`.

## Repository Layout

This repository contains a full-stack data management application built within a WordPress environment. Here’s a breakdown of the key components:

-   **`functions.php`**: The core of the child theme. It acts as the backend orchestrator, loading all necessary scripts and styles, defining API endpoints, and adding custom features to the WordPress admin area. It contains the main PHP logic for data handling and page-specific script loading.

-   **`api/`**: This directory contains PHP scripts that serve as the backend API for the data form. It supports two distinct workflows for data management: one for administrators and one for public suggestions.
    -   **`api/config.php`**: A critical configuration file holding the database credentials. It establishes the database connection used by all other API scripts.

    -   **Administrator Workflow:**
        -   **`get-master-data.php`**: Fetches all official records from the `facilities_master` table to populate the form for admins.
        -   **`save-master.php`**: Saves data directly to the `facilities_master` table, used when an admin creates or updates a record.

    -   **Public Suggestion Workflow:**
        -   **`save-suggestion.php`**: A public endpoint that saves proposed changes to a separate `suggested_edits` table for review. It does *not* touch the live data.
        -   **`process-edit.php`**: The backend for the admin approval page. It allows an admin to approve or reject a pending suggestion. If approved, the data is moved from `suggested_edits` to the `facilities_master` table.

    -   **Shared Endpoints:**
        -   **`get-autocomplete.php`**: Provides autocomplete suggestions for form fields by querying both master and suggested data, ensuring consistency.
        -   **`approve-edits.php`**: Provides the frontend UI for the admin approval page, which uses `process-edit.php` to perform its actions.

-   **`js/`**: Contains the client-side JavaScript files that power the site's interactive features.
    -   **`js/facility-form.v3.js`**: The core script for the admin data entry form. It manages dynamic fields, validation, and communication with the backend API.
    -   **`js/facilities-display.js`**: Renders the public-facing, searchable directory of all facilities. It fetches data from the API and injects it into the `tti-program-index` page.
    -   **`js/anonymous-portal.js`**: Powers the frontend of the secure, anonymous document submission page, handling file uploads and user interaction.
    -   **`js/facility-report-generator.js`**: A utility for admins to generate a clean, printable HTML report from the data currently loaded in the facility form.
    -   Other scripts in this directory power the various state-specific report pages (e.g., `ca-reports.js`, `tx_reports.js`).

-   **`css/`**: Contains the stylesheets for the project.
    -   **`css/data-form.css`**: This file provides all the styling for the facility data entry form, ensuring a consistent and user-friendly interface. It covers everything from layout and typography to button styles and responsive design.

-   **`data/`**: This directory holds a static HTML export of the WordPress page with the slug `data`, which provides the facility submission interface. It also contains various JSON data files used by the reporting pages.

-   **`tti-program-index`**: An HTML file that serves as the content for the main TTI Program Index page in WordPress. It contains the placeholder `<div id="facilities-container"></div>` where the `facilities-display.js` script renders the database.

-   **`style.css`**: The main stylesheet for the child theme, which primarily contains theme header information and can be used for global style overrides.

-   **`AGENTS.md`**: This file! The central guide for developers, outlining the project's architecture, conventions, and design principles.

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
