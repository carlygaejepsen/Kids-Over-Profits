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
- `api/` – PHP endpoints for AJAX and admin tooling (configuration checks, data imports, saving edits, etc.).
- `data/` – Static export of the WordPress page whose slug is `data`, providing the facility submission interface.
- `js/` – Front-end scripts written in vanilla ES6 modules. Filenames are versioned (e.g., `facility-form.v3.js`).
- `css/`, `style.css` – Theme styling overrides that extend the Kadence parent.
- `functions.php` – Child theme bootstrap, hooks, and shortcode registrations.
- `tti-program-index/` – Content and assets for the TTI program database index.

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
