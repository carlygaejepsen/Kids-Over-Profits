# TTI Program Index

The TTI Program Index (`/tti-program-index/`) is the public searchable directory of parent companies and chains in the Troubled Teen Industry dataset, with each operator's facilities listed under it.

## Scope

- The index lists operators/chains only. The frontend keeps a record only when it has an operator name and at least two named facilities, so single programs, location clusters and operator-less brand records are dropped.
- Other pages never link a single facility to the program index. Facility links go to the generated `/facility/<slug>/` page (`inc/facility-pages.php`) or to a state hub's `?search=`. Operator profiles live at `/operator/<slug>/` (`inc/operator-pages.php`).
- Facility cards in the index link out to their `/facility/<slug>/` page ("Full profile") through the `profile_url` field the feed supplies.

## Architecture

### Page Template
- `templates/page-tti-program-index.php` provides the markup (search box, status filter, sort dropdown, alphabet filter, loading skeleton) and an inline `window.facilitiesConfig`.
- `inc/enqueue.php::enqueue_tti_processor_scripts()` loads the assets and localizes `facilitiesConfig` and `ttiIndexConfig` (`isAdmin`, `restUrl`).

### Frontend
- renderer: `js/tti-program-index.js`
- dependencies: `js/facility-merge.js`, `js/shared/facility-resources.js` (materials-on-file catalog), `js/shared/program-links.js` (handle `kop-program-links`, registered in `inc/facility-pages.php`), `js/submit-info.js` (per-entry "Submit info" form)
- styling: `css/tti-program-index.css`, `css/document-library.css` (FileBird folder render), `css/kop-components.css`, `css/skeleton.css`

### Data Sources
The frontend tries each URL in order and uses the first that returns valid JSON:
1. `/wp-json/kop/v1/facilities` (`inc/rest-api.php`)
2. `api/get-master-data.php`
3. `js/data/facilities_master.json` (hardcoded last resort in the JS; the file is not in the repo, so this step always fails)

`kop/v1/facilities` reads the v2 facility model (`kop_v2_get_facilities_projects()` in `inc/facility-v2-readers.php`, built from `facilities_v2`, `kop_operators` and `kop_operator_facilities`) when the `program_index` area is switched on via the `kop_data_model` / `kop_data_model_areas` options, or when the page is opened with `?model=v2`. Otherwise it reads the legacy tables through `kop_get_facilities_projects_from_database()` in `inc/database.php`. Both paths attach linked news, lawsuits, memorials and inspection stats. See `docs/FACILITY-SCHEMA.md` for the v2 model.

After the dataset loads, the page also calls `kop/v1/folders` for FileBird document matching, and on demand `kop/v1/folder-content`, `kop/v1/render-folder-shortcode` and `kop/v1/link-preview`.

## Key Features

### Search and Filters
- keyword search across operators and facilities; `?search=` pre-fills it (used by the site search results page)
- status filter: open, closed, transferred
- sort: A-Z, violations/inspections only (a filter mode), most inspection reports
- alphabet filter

### Directory Presentation
- operator sections (`<details>`) with their facility cards and expandable detail fields
- operator- and facility-level strips and sections for linked news, lawsuits and deaths on record
- materials on file and FileBird document folders

### Links
- Program and organization websites render through `KOP.programLinks` (`js/shared/program-links.js`): Wayback Machine first, the live site only through `/go/`.

## Legacy Path

`inc/enqueue.php::load_facilities_data()` is an older slug-driven loader (`js/inspections/facilities-display.js`) that returns early on pages using the program index template. When debugging the index, follow `enqueue_tti_processor_scripts()` and the template.
