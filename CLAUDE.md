# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Kids Over Profits is a WordPress child theme (Kadence parent) that powers a data management system for tracking "Troubled Teen Industry" (TTI) facilities. The theme lives at `app/public/wp-content/themes/child/` inside Flywheel Local development environment.

## Development Environment

- **Local**: Flywheel Local at `https://kids-over-profits.local`
- **Production**: NixiHost shared hosting (Apache/LiteSpeed, PHP 8.2, MySQL)
- **CMS**: WordPress with Kadence parent theme
- **No build process**: Plain PHP, vanilla JavaScript (ES6 modules), CSS

## Key Commands

```bash
# After editing any program data files in js/data/
node scripts/aggregate-all-programs.js

# Other data pipeline scripts
node scripts/rebuild-search-index.js
node scripts/extract-reddit-wiki-links.js
```

## Architecture

### Dual-Workflow Data System
1. **Admin workflow**: Direct writes to `facilities_master` table via `api/save-master.php`
2. **Public workflow**: Writes to `suggested_edits` table for approval via `api/save-suggestion.php`

### Database Tables
- `facilities_master` - Official facility records
- `suggested_edits` - Public submissions pending approval
- `locations_master` / `referrers_master` - Related data
- `wiki_submissions` / `news_submissions` - Content submissions

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
