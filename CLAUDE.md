# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Kids Over Profits is a WordPress child theme (Kadence parent) that powers a data management system for tracking "Troubled Teen Industry" (TTI) facilities. The theme lives at `app/public/wp-content/themes/child/` inside Flywheel Local development environment.

## Development Environment

> **DEFAULT TARGET: the live site.** Unless the user explicitly says otherwise,
> always assume work, testing, URLs, and diagnostics refer to the production
> site at `https://kidsoverprofits.org`. Only treat the task as local
> (Flywheel) when the user explicitly says "local", "Flywheel", or
> "kids-over-profits.local".

- **Production (default)**: `https://kidsoverprofits.org` — NixiHost shared hosting (Apache/LiteSpeed, PHP 8.2, MySQL)
- **Local (only when specified)**: Flywheel Local at `https://kids-over-profits.local`
- **CMS**: WordPress with Kadence parent theme
- **No build process**: Plain PHP, vanilla JavaScript (ES6 modules), CSS

## Key Commands

```bash
# After editing any program data files in js/data/
node scripts/aggregate-all-programs.js

# Other data pipeline scripts
node scripts/rebuild-search-index.js
node scripts/extract-reddit-wiki-links.js

# After editing the network map CSVs or network-overrides.json
node scripts/build-network-graph.js
node scripts/test-network-graph.js

# After editing anything in js/network-map/
node scripts/test-network-modules.js

# After editing the reporting directory data in js/data/reporting/
node scripts/build-reporting-directory.js
node scripts/verify-reporting-links.js          # slow, hits every agency site
php scripts/test-reporting-directory.php        # renders the page offline
# After editing the glossary source js/data/glossary/glossary.md
node scripts/build-glossary.js
php scripts/test-glossary.php                   # renders /glossary/ offline, checks every #link
# After any template or page CSS change: no text may sit on the gradient body background
python scripts/check-bare-text.py               # one page per child template, live site
# See the working tree's map in a browser before pushing it
python scripts/preview-network-map.py --shots tmp/map-preview
```

### Reporting directory data

`js/data/reporting/` holds where to report an abusive therapist or program,
state by state: `national.json`, one `states/<abbr>.json` per state, and the
generated `directory.json` the /report-abuse/ page reads. Every channel needs
a source URL and a `verified_on` date or the build refuses it. `README.md` in
that folder is the field-by-field schema. Rendered server-side by
`inc/reporting-directory.php`; the state hubs embed their own state's block.

### Glossary data

`js/data/glossary/glossary.md` is the TTI glossary source (sections `##`,
program groups `###`/`####`, entries `**Term** *(aka ...)*: text. Used at: *A, B*`).
`build-glossary.js` writes the `glossary.json` the /glossary/ page reads and
fails on any `**cross-reference**` that does not name an entry. Rendered
server-side by `inc/glossary.php` (`templates/page-glossary.php`).

### Network map data

`js/data/network/` holds the Miro board export (`tti_nodes.csv`, `tti_edges.csv`)
and the generated `graph.json` that the network map reads. Corrections go in
`network-overrides.json`, never in the CSVs, so the next board export does not
undo them. `staff-movement.csv` holds the reviewed staff moves the build adds
as edges; `node scripts/extract-staff-movement.js` drafts it from the profile
text into `tmp/staff-movement.draft.csv` for comparison. Each build rewrites
`tmp/network-qa.md` (gitignored) listing every row the rules had to guess at.
Facility links are resolved against
`facilities_v2` in `tmp/prod.sqlite` when that mirror is present, and fall back
to the program aggregate otherwise.

## Architecture

### Dual-Workflow Data System
1. **Admin workflow**: Direct writes to `facilities_master` table via `api/save-master.php`
2. **Public workflow**: Writes to `suggested_edits` table for approval via `api/save-suggestion.php`

### Database Tables
- `facilities_master` - Official facility records
- `suggested_edits` - Public submissions pending approval
- `locations_master` / `referrers_master` - Related data
- `wiki_submissions` / `news_submissions` - Content submissions
- `lawsuit_facility_links` / `lawsuit_news_links` - Which facilities a lawsuit involves and which articles cover it (synced on save; `api/lawsuit-facility-links.php`, `api/lawsuit-news-links.php`)
- `{prefix}kop_media_folder_tags` - Extra folder memberships (one document, many folders)
- `{prefix}kop_folder_links` - Legacy/current-name folder equivalence (curated in `api/link-folders.php`)
- `{prefix}kop_glossary_feedback` - Reader notes from the glossary's "My facility used this too" / "Suggest a correction" buttons (`inc/glossary-feedback.php`; reviewed under KOP Data Tools > Glossary Feedback)
- `{prefix}kop_addresses` / `{prefix}kop_facility_addresses` - Physical address IDs and which facility stood where (`api/manage-addresses.php`; join table rebuilt from facility data on each seed)

### Page Template → Script Loading Pattern
The system uses WordPress conditional loading in `functions.php`:
- `page-admin-data.php` → Admin form assets, mode='master'
- `page-data.php` → Public form assets, mode='suggestion'
- `page-tti-program-index.php` → Facility directory
- `page-wiki-editor.php` → Wiki content editor
- `page-news-processor.php` → News processing
- State report pages detected by slug pattern `*-reports`

### JavaScript Module Structure
```
js/data-form-modules/    ← Core modules (config.js loads first, no deps)
js/data-form/            ← Form utilities (utilities.js, data-form.v4.js orchestrator)
js/inspections/          ← State report viewers
js/data/                 ← Static JSON fallbacks
```

Module dependency chain: `config.js` → `data-normalizer.js` → `api.js` → `project.js` → UI modules

### API Configuration
Credentials loaded from `.env`, WordPress constants, or `api/config.local.php` (gitignored). The JavaScript reads `KOP_DATA_FORM_CONFIG` localized by PHP containing `apiBase`, `endpoints`, and `mode`.

## Code Conventions

- **Versioned filenames**: Use explicit versions when iterating (e.g., `data-form.v4.js`)
- **Procedural PHP**: API endpoints use procedural style
- **No build tooling**: Avoid introducing bundlers/transpilers
- **CSS variables**: Use `var(--kop-*)` from `css/colors.css` for styling

## Color Palette (css/colors.css)
Primary: Midnight Blue (#000435), Navy (#000080), Teal (#33A7B5)
Accents: Orange (#EF9034), Chartreuse (#B2E102), Coral Pink (#FE8088)
Backgrounds: Sand (#F2EEDF), Soft Pastel Yellow (#FFF5CB), Mint Green (#B6E3D4)

Reserve bright accents (Chartreuse, Coral Pink, Bubblegum Pink) for borders/highlights, not backgrounds.

## Program Data Pipeline

Source files in `js/data/reddit-wiki/programs-XX.json` (by state) and `js/data/tti-program-links.json` (uncategorized). After any edits:
```bash
node scripts/aggregate-all-programs.js
```
This generates `programs-array.json`, `search-index.json`, and `metadata.json`.

## REST API

- `GET /wp-json/kop/v1/facilities` - Returns facility data (registered in `functions.php`)
- Falls back to static JSON in `js/data/` when API unavailable

## Deployment

- Git deployment via `.cpanel.yml` or manual upload to NixiHost/cPanel
- Export database snapshots if schema changes
- Never commit `.env` or `config.local.php`
