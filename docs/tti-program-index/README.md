# TTI Program Index

The TTI Program Index is the public searchable directory for facilities and parent organizations in the Troubled Teen Industry dataset.

## Overview

The index is designed to prefer live REST data while still tolerating fallback datasets when the live endpoint is unavailable. It supports broad browsing, keyword search, filtering, and deep card expansion for facility details.

## Architecture

### Page Template
- `page-tti-program-index.php` provides the page markup and inline config.
- `inc/enqueue.php::enqueue_tti_processor_scripts()` also loads the page assets and localizes runtime settings.

### Frontend
- main renderer: `js/tti-program-index.js`
- styling: `css/tti-program-index.css`
- related document/folder rendering support: `css/document-library.css`

### Data Sources
The page now uses an ordered source list:
1. `/wp-json/kop/v1/facilities` as the primary source
2. `api/get-master-data.php` as a fallback source
3. `js/data/facilities_master.json` as a packaged static fallback

`window.facilitiesConfig` carries those URLs into the frontend.

## Key Features

### Search and Filters
- keyword search across facilities and parent companies
- status filter for open, closed, and transferred facilities
- sort modes including alphabetical, violations-only, most violations, and recent inspections
- alphabet filter for quick browsing

### Directory Presentation
- grouped facility cards with expandable detail sections
- operating status, location, years, and structured detail fields
- resource indicators and linked supporting content when available

### Resilient Data Loading
- prefers live REST data from `kop/v1/facilities`
- can fall back to API or packaged JSON sources if the preferred endpoint fails
- keeps the public directory usable even when one source is temporarily unavailable

## Important Runtime Note

Older slug-driven loading logic still exists in `inc/enqueue.php`, but pages using the dedicated `page-tti-program-index.php` template now inject their own explicit `facilitiesConfig` and asset setup. When documenting or debugging the index, follow the template-specific path first.
