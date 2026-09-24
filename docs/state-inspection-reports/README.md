# State-Level Inspection Reports

The State-Level Inspection Reports module provides public-facing viewers for state regulatory inspections, citations, and deficiency narratives. It is separate from the general TTI Program Index and focuses on state-specific compliance histories.

## Supported States

Fourteen tracker pages, one per state, at `/xx-reports/`:

| State | Slug | Viewer | Data |
|---|---|---|---|
| Arizona (`AZ`) | `az-reports` | shared engine | API |
| Arkansas (`AR`) | `ar-reports` | shared engine | API |
| California (`CA`) | `ca-reports` | `js/inspections/ca-reports.js` (legacy) | `js/data/ccl_reports_batch_*.json` only |
| Connecticut (`CT`) | `ct-reports` | shared engine | API, `js/data/ct_reports.json` fallback |
| Florida (`FL`) | `fl-reports` | shared engine | API |
| Georgia (`GA`) | `ga-reports` | shared engine | API |
| Minnesota (`MN`) | `mn-reports` | shared engine | API |
| Montana (`MT`) | `mt-reports` | shared engine | `js/data/mt_reports.json` only (the API has no Montana rows) |
| Nevada (`NV`) | `nv-reports` | shared engine | API |
| North Carolina (`NC`) | `nc-reports` | shared engine | API |
| Oregon (`OR`) | `or-reports` | shared engine | API |
| Texas (`TX`) | `tx-reports` | `js/inspections/tx_reports.js` (legacy) | API |
| Utah (`UT`) | `ut-reports` | shared engine | `js/data/ut_checklists/ut_reports*.json` (else `js/data/ut_reports*.json`) merged with the API |
| Washington (`WA`) | `wa-reports` | shared engine | API |

The canonical state-to-slug list is `kop_state_inspection_page_map()` in `inc/rest-api.php`. The home page grid, the hub, the facility pages and the state hubs all derive their tracker links from it (`kop_report_state_links()` and `kop_report_state_sentence()` in `inc/utilities.php`).

## Architecture

### Pages
- `templates/page-state-reports.php` (Template Name: State Reports) is the template for every `/xx-reports/` page. It prints the title, a back link to the state hub (resolved by stripping `-reports` from the slug), the page's editor content, the `last-updated` block, the alphabet filter, search input, sort control, "new reports only" toggle and the results container.
- `templates/page-inspection-reports.php` is the hub at `/inspection-reports/`: the state grid with a report count per state, report and facility totals, hand-featured reports and the newest approved severe findings.
- `templates/page-severe-reports.php` is `/severe-reports/`: every approved severe finding, newest first, 50 per page, filterable by state and kind of harm, each with a `#finding-<id>` anchor.
- The hub and severe pages are created by `kop_tool_page_specs()` in `inc/admin.php`. The tracker pages are ordinary WordPress pages assigned the State Reports template.

### Asset Loading
- `kop_enqueue_report_scripts()` in `inc/enqueue.php` matches the current page against an explicit list of slugs with `is_page()` (not a `*-reports` pattern) and loads that state's script plus `css/facility-reports.css`. Its `json_glob` entry is resolved to URLs and localized as `<xx>ReportsData.jsonFileUrls`.
- Twelve states (AR, AZ, CT, FL, GA, MN, MT, NC, NV, OR, UT, WA) are marked `report_page`: the shared engine `js/inspections/report-page.js` loads first with `css/kop-components.css`, `css/skeleton.css` and `css/report-page.css`, then the state adapter `js/inspections/states/<xx>.js`, which calls `KOP.reportPage.mount(adapter)`. The adapter contract (required `load`, `facilityName`, `reportTime`, `isFlagged`, `summary`, `report`; optional filters, sorts and notes) is documented at the top of `report-page.js`.
- The old per-state viewers (`js/inspections/<xx>_reports.js`) stay in the repo as rollback targets; each migrated entry in `inc/enqueue.php` names its own.
- Every tracker page also loads `js/inspections/severe-flags.js` and `css/severe-reports.css` (see Severe Findings).
- `kop_state_reports_body_class()` adds `kop-state-reports-page` to the same slug list.

### Data Sources
- Read: `api/inspections-read.php?state=XX` (public). Reads `inspection_facilities` and `inspection_reports`; `inspection_facilities.state` is the two-letter code. Re-listings of one document (same date, summary, text and categories under different URLs or file ids) are dropped.
- Write: `api/inspections-write.php`, POSTed by the scrapers with `KOP_DATA_API_KEY`; it upserts both tables (and creates them if missing).
- Static JSON in `js/data/` is either the only source (CA, MT), a fallback (CT) or merged with the API (UT); see the table above.
- Featured reports: `featured` and `featured_note` columns on `inspection_reports`, added by `api/update-schema.php` and curated in `api/manage-featured-inspections.php` (admin). The home page and hub check the column exists before querying, cached as `kop_inspection_featured_column_v2`.

## API Shape

`api/inspections-read.php` returns:

```json
{
  "total_facilities": 0,
  "source_state": "MN",
  "scraped_timestamp": "2026-04-24T13:00:00",
  "scraping_notes": {
    "total_reports": 0
  },
  "facilities": [
    {
      "facility_info": {
        "facility_name": "Example Facility",
        "full_address": "...",
        "program_category": "..."
      },
      "reports": [
        {
          "report_id": "ABC-123",
          "report_date": "2026-04-01",
          "report_url": "https://...",
          "raw_content": "...",
          "content_length": 0,
          "is_structured": false,
          "summary": "...",
          "categories": {}
        }
      ]
    }
  ]
}
```

`facility_info` also carries `phone`, `program_name`, `executive_director`, `bed_capacity`, `license_exp_date`, `relicense_visit_date` and `action`. `categories` is the decoded `categories_json`, whose shape differs by state; the adapters normalize it. `scraped_timestamp` populates the visible last-updated stamp; without one the API falls back to the newest facility `updated_at`. A state with no facilities returns only `total_facilities`, `source_state` and an empty `facilities`.

## Key Features

### Shared Report Experience (`report-page.js`)
- Alphabet filter, full-text search, sorts (default A-Z, name, violations only, most violations, most recent inspection) and a "new reports only" toggle (last 30 days).
- Optional per-state filter dropdowns and a note for states whose data records no findings.
- Report bodies can render lazily on first open, which keeps states with large document text listable.

### State-Specific Normalization
- Each adapter in `js/inspections/states/` decides how its data loads, what counts as a violation (read from the document text where the scraper's own fields are unreliable) and what a facility summary and a report show. The header comment of each adapter records those decisions.

### Severe Findings
- `inc/inspection-highlights.php` extracts findings per sentence, scores them by category of harm (death, sexual abuse, physical abuse, restraint with injury, self-harm, medical neglect, hospitalisation, missing child, police) scaled by the state's own severity signal, and stores candidates in `inspection_highlights` (scan progress in `inspection_highlight_scans`). Only substantiated findings are queued. Adapters exist for TX, CA, UT, AZ and CT (`kop_ih_supported_states()`, scanner version 3).
- `api/scan-inspection-highlights.php` runs the scan (browser: dry run, then `?apply=1` per batch; CLI: `php api/scan-inspection-highlights.php apply`). `api/review-inspection-highlights.php` is the admin review screen.
- Nightly cron (cPanel > Cron Jobs; the CLI binary, since cron's `php` is php-cgi and exits "CLI only."):

  ```
  15 4 * * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/scan-inspection-highlights.php apply >> /home/kidsover/logs/inspection-highlights-scan.log 2>&1
  ```

  Each night scans only reports this version of the rules has not seen, so it is quick once the backlog is done; new candidates wait in the review screen.
- A finding is severe at score 70 or more (`kop_ih_severe_score()`); nothing is published until an admin approves it. Approved severe findings appear on the home page, the hub and `/severe-reports/`, newest first.
- On the tracker pages, `js/inspections/severe-flags.js` reads `api/inspection-highlights-read.php?state=XX` (public, approved severe findings only) and flags matching reports by the opening words of the quote, since the static JSON report ids do not match the database. It works on both the shared engine and the legacy TX and CA markup.
- Tests: `php scripts/test-inspection-highlights.php` (rule cases plus a dry run over `tmp/prod.sqlite`) and `node scripts/test-severe-flags.js`.

## Adding or Updating a State

1. Acquire the state data from scraping, downloads, FOIA output, or manual collection.
2. Normalize it into the inspections schema and POST it to `api/inspections-write.php`.
3. Write an adapter at `js/inspections/states/<xx>.js` against the `report-page.js` contract.
4. Add the slug to `kop_enqueue_report_scripts()` (with `report_page => true`) and `kop_state_reports_body_class()` in `inc/enqueue.php`, and the state to `kop_state_inspection_page_map()` in `inc/rest-api.php` (plus `kop_state_inspection_dataset_urls()` if it ships static JSON).
5. Create the WordPress page `/xx-reports/` with the State Reports template.
6. For severe findings, add an extractor to `kop_ih_extract()` and the state to `kop_ih_supported_states()`, then bump `kop_ih_scanner_version()`.

## Scrapers

- No scraper lives in this repo any more (`scripts/mn_scraper.py` was removed on 2026-06-01). The state scrapers (`<xx>_scraper.py`, `scraper_launcher.py`, the shared `inspection_api_client.py` that POSTs to `api/inspections-write.php`) and `STATE_SCRAPER_GUIDE.md` are in the companion Kids-Over-Profits-Tools repo.
- `scripts/sync-prod-sqlite.py` copies production into `tmp/prod.sqlite` for offline work on these tables.

## Open Work

- Migrate Texas and California to the shared engine.
- Washington: a `--full` run of `wa_scraper.py` (needs the API key).
- Florida and North Carolina payloads from `api/inspections-read.php` are around 100 MB each.
- Severe-finding extractors for WA, FL, NV and the raw-text states (NC, GA, AR, MN, OR); a "What inspectors found" block on the facility pages.
- California stores about 9,100 reports twice under two id schemes, which inflates the hub counts.
