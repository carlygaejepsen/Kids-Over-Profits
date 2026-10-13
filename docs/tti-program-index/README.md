# TTI Program Index

The TTI Program Index (`/tti-program-index/`) is the public searchable directory of facilities in the Troubled Teen Industry dataset. It has two tabs: by parent company (operators and chains, each with its facilities listed under it) and by location (`?view=location`, facilities grouped by US state and by country).

## Scope

- The parent company tab lists operators/chains only. The frontend keeps a record only when it has an operator name and at least two named facilities, so single programs, location clusters and operator-less brand records are dropped.
- The location tab lists every state and country with its facilities. It replaced the separate `/location-index/` page, which now redirects to `/tti-program-index/?view=location`; `/international/` redirects to `?view=location&type=country` (`inc/redirects.php`). Links to the location tab are built with `kop_location_index_url()` (`inc/ajax-search-lite.php`).
- Other pages never link a single facility to the program index. Facility links go to the generated `/facility/<slug>/` page (`inc/facility-pages.php`) or to a state hub's `?search=`. Operator profiles live at `/operator/<slug>/` (`inc/operator-pages.php`).
- Facility cards in the index link out to their `/facility/<slug>/` page ("Full profile") through the `profile_url` field the feed supplies.

## Architecture

### Page Template
- `templates/page-tti-program-index.php` provides the tab bar, both panels (each with its own search box, filters, sort dropdown and loading skeleton; the location tab's controls carry a `loc-` prefix), and the inline configs `window.facilitiesConfig`, `window.locationConfig` and `window.locationIndexConfig`.
- The tab switcher keeps `?view=location` in the address bar, so the tab can be linked and survives a reload, and fires `kop:company-tab-shown` or `kop:location-tab-shown`. Each tab renders the first time it is shown, not on page load.
- `inc/enqueue.php::enqueue_tti_processor_scripts()` loads the assets for both tabs and localizes `facilitiesConfig` and `ttiIndexConfig` (`isAdmin`, `restUrl`).

### Frontend
- parent company tab: `js/tti-program-index.js`
- location tab: `js/location-index.js` (loaded after `tti-program-index-script`; reuses its feed through `window.kopDirectoryData` and `window.kopDirectoryDetail`)
- dependencies: `js/facility-merge.js`, `js/shared/facility-resources.js` (materials-on-file catalog), `js/shared/program-links.js` (handle `kop-program-links`, registered in `inc/facility-pages.php`), `js/submit-info.js` (per-entry "Submit info" form)
- styling: `css/tti-program-index.css`, `css/document-library.css` (FileBird folder render), `css/kop-components.css`, `css/skeleton.css`

### Data Sources
The page loads in steps, each only what the next screen needs:

1. `kopDirectoryData()` fetches `/wp-json/kop/v1/facilities?view=index` once for both tabs: every company and place, with each facility cut by `kop_directory_feed_slim_facility()` to what the lists show and search (names, other and past names, operator and owners, place, years, status, inspection report counts and licensing action, the violations flag, `nameUnderOperator`). This is about a tenth the size of the whole feed.
2. `kopDirectoryDetail(keys)` fetches `?view=detail&key[]=<project key>` when a visitor opens a company or place, together with the FileBird folder list (`kop/v1/folders`) the document buttons match against. Each key is fetched once; the server reads at most 20 keys a request.

If the REST index fails, `api/get-master-data.php` (the whole feed at once) stands in, and `kopDirectoryDetail` reads from it. An older server that ignores `?view=index` and sends the whole feed is handled the same way. There is no static copy to fall back to; if both fail, the page shows an error.

The split is built by `inc/directory-feed.php`. `kop_directory_feed_ensure()` builds the whole feed once and writes it under `uploads/kop-cache/directory/<fingerprint>/`: `index.json` (the slim lists, written last, so its presence means the set is complete) and one `p-<md5 of key>.json` per company or place. `kop_directory_feed_fingerprint()` combines the state feed's fingerprint (`kop_state_feed_fingerprint()`: facilities, links, news, lawsuits, memorials, inspections, theme files), the operator, referrer and transporter tables, whether the v2 model is on, and the saved rename years (`kop_network_rename_review`). A build older than `KOP_DIRECTORY_FEED_MAX_AGE` (6 hours) is made again anyway. WP-Cron (`kop_directory_feed_hourly`) rebuilds it hourly, so a visitor rarely waits for a build; older builds are removed an hour after they are replaced. A `?model=v2` preview is built fresh and never cached. Plain `kop/v1/facilities` (no `view`) is unchanged and still returns the whole feed.

A field the closed lists read must be added to `kop_directory_feed_slim_facility()`, or it is missing until the company or place is opened.

`kop/v1/facilities` reads the v2 facility model (`kop_v2_get_facilities_projects()` in `inc/facility-v2-readers.php`, built from `facilities_v2`, `kop_operators` and `kop_operator_facilities`) when the `program_index` area is switched on via the `kop_data_model` / `kop_data_model_areas` options, or when the page is opened with `?model=v2`. Otherwise it reads the legacy tables through `kop_get_facilities_projects_from_database()` in `inc/database.php`. Both paths attach linked news, lawsuits, memorials and inspection stats. See `docs/FACILITY-SCHEMA.md` for the v2 model.

Opened sections also call, on demand, `kop/v1/folder-content`, `kop/v1/render-folder-shortcode` and `kop/v1/link-preview`.

## Key Features

### Search and Filters
- keyword search across operators and facilities; `?search=` pre-fills it on the parent company tab (used by the site search results page)
- alternate names are searched too: a facility's past names, other names and current name, and the name it had under a company. On the parent company tab each former or alternate name also gets its own row, sorted under that name, pointing to the company and facility it belongs to
- parent company tab: status filter (open, closed, transferred); sort A-Z, violations/inspections only (a filter mode), most inspection reports; alphabet filter
- location tab: type filter (US states, international); sort A-Z or most facilities; alphabet filter; `?view=location&type=state` or `&type=country` opens it pre-filtered

### Directory Presentation
- operator sections (`<details>`) with their facility cards and expandable detail fields; a section's body is drawn when it is opened
- facility cards show "Formerly" and "Also known as" lines from the record's past and other names
- a renamed program is headed, under each company, by the name it had while that company ran it: `nameUnderOperator` (and `nameUnderOperatorYears` when the years are known), stamped on each company link's copy of the facility by `kop_v2_get_facilities_projects()` from `kop_facility_eras_name_under()` (`inc/facility-eras.php`). The card then says "Now known as: (the record's name)" and "Under this name: (years)". Only the v2 reader stamps it
- operator- and facility-level strips and sections for linked news, lawsuits and deaths on record
- materials on file, FileBird document folders, and research documents tagged with the facility or company at /researchreports/ (`research[]`, attached by the v2 reader with `kop_attach_research_to_projects()` in `inc/facility-v2-readers.php`)
- news items link the article itself, with "(archived copy)" beside the title when an archived copy is known (`window.kopNewsLinks`, `js/news-archive-links.js`)
- names that are title-cased for display keep the capitals on the sitewide Proper Names list (`kopProperNames()`, `js/proper-names.js`)

### Links
- Program and organization websites render through `KOP.programLinks` (`js/shared/program-links.js`): Wayback Machine first, the live site only through `/go/`.

## Tests

```bash
# The page drawn from the split feed must match the page drawn from the whole feed (every search, filter,
# sort, company body, place list), with load timings. Live page in Chrome with the working tree's JS and CSS
python scripts/test-directory-feed.py [--refresh] [--cpu 4]
# The name each renamed program gets under each company, on tmp/prod.sqlite (read-only); --list prints every renamed link
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-name-under-operator.php [--list]
```

## Legacy Path

`inc/enqueue.php::load_facilities_data()` is an older slug-driven loader (`js/inspections/facilities-display.js`) that returns early on pages using the program index template. When debugging the index, follow `enqueue_tti_processor_scripts()` and the template.
