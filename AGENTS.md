# Kids Over Profits – Project Guide

Welcome! This repository contains the working files for the **Kids Over Profits** campaign website, which is implemented as a WordPress child theme running on a LiteSpeed-managed PHP 8.2 stack. Use this guide to understand the environment, key directories, and collaboration preferences before you make changes.

## Environment Snapshot
- **Hosting provider & control panel:** NixiHost shared hosting managed through cPanel with Softaculous WordPress Manager for application administration.
- **Hosting stack:** LiteSpeed web server with PHP 8.2 (handler `ea-php82___lsphp`).
- **Database:** MySQL managed through the NixiHost/cPanel environment.
- **CMS:** WordPress with the Kadence parent theme and a custom child theme located in this repository.
- **Local development:** Originally set up on Windows (`c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits`).
- **Access constraints:** No production credentials are stored here; automation can only work with files present in the repo.

## Repository Layout
- `api/` – PHP endpoints for AJAX and admin tooling (configuration checks, data imports, saving edits, etc.).
- `js/` – Front-end scripts used across the campaign tools. Files are plain JavaScript modules organized by feature (e.g., `facility-form.v3.js`, state-specific report builders).
- `data/` – Static export for the WordPress page whose slug is `data`, providing the facility submission interface.
- `functions.php`, `style.css` – Child theme overrides that extend the Kadence parent.
- `tti-program-index/` – Content and assets for the TTI program database index.
- Supporting documents: `environment-summary.md`, `troubleshooting-summary.md`, and `ca-reports.html` capture operational context and archived outputs.

## Dependencies & Tooling
- Server-side code targets WordPress conventions (hooks, shortcodes, REST-style endpoints).
- JavaScript is vanilla ES6 with a focus on compatibility with WordPress admin and front-end pages; no bundler is currently in use.
- External integrations rely on WordPress core and Kadence-provided functionality. Additional PHP libraries should remain lightweight and WordPress-friendly.

## Collaboration Preferences
- **File versioning:** When iterating on assets, prefer explicit versioned filenames (e.g., `facility-form.v1.js`, `facility-form.v2.js`). Preserve prior versions instead of overwriting unless instructed otherwise.
- **Code style:** Follow existing patterns in each directory (procedural PHP for endpoints, modular ES6 for scripts). Avoid introducing build tooling unless necessary.
- **Documentation:** Update this guide or the relevant `*-summary.md` files when environment or process details change.

Thanks for contributing! Keep consistency with the structure above to ensure smooth collaboration across the project.
