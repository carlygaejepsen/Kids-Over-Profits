# State-Level Inspection Reports

The State-Level Inspection Reports module provides public-facing viewers for state regulatory inspections, citations, and deficiency narratives. It is separate from the general TTI Program Index and focuses on state-specific compliance histories.

## Supported States

Current report pages are wired for:
- Arizona (`AZ`)
- Arkansas (`AR`)
- California (`CA`)
- Connecticut (`CT`)
- Minnesota (`MN`)
- Montana (`MT`)
- Texas (`TX`)
- Utah (`UT`)
- Washington (`WA`)

## Architecture

### Page Structure
- `page-state-reports.php` is the main WordPress page template for report pages.
- `templates/page-state-reports.php` currently mirrors the shared report-page markup. Keep both files aligned until one is retired.
- The shared page shell provides the title, intro copy, alphabet filter, search input, sort control, results container, and `last-updated` block.

### Asset Loading
- `inc/enqueue.php::kop_enqueue_report_scripts()` maps report page slugs to state-specific renderers.
- Each report page loads `css/facility-reports.css` plus a renderer in `js/inspections/`.
- Current page slugs are:
  - `ca-reports`
  - `ut-reports`
  - `az-reports`
  - `tx-reports`
  - `mt-reports`
  - `ct-reports`
  - `wa-reports`
  - `ar-reports`
  - `mn-reports`

### Data Sources
- Primary source: `api/inspections-read.php?state=XX`
- Write endpoint for scraper output: `api/inspections-write.php`
- Fallback or historical snapshots remain in `js/data/` where available.

Not every state has the same fallback layout:
- California uses `js/data/ccl_reports_batch_*.json`
- Utah can fall back to `js/data/ut_checklists/ut_reports*.json` or `js/data/ut_reports*.json`
- Arizona uses `js/data/az_reports/*.json`
- Connecticut, Minnesota, Montana, and Washington have state JSON snapshots in `js/data/`
- Some newer states may rely on the API first and only add checked-in fallbacks later

## API Shape

`api/inspections-read.php` returns the state report payload in the structure the frontend expects:

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
        "facility_name": "Example Facility"
      },
      "reports": [
        {
          "report_id": "ABC-123",
          "report_date": "2026-04-01",
          "summary": "...",
          "categories": {}
        }
      ]
    }
  ]
}
```

The `scraped_timestamp` field is now used to populate the visible `last updated` timestamp on the report pages. If no scrape timestamp exists, the API falls back to the most recent facility `updated_at` value.

## Key Features

### Shared Report Experience
- Alphabet filter for fast facility browsing
- Full-text search across loaded facilities
- Sort options for name, violations-only, most violations, and recent inspection activity
- Shared layout and styling via `css/facility-reports.css`

### Full Inspection Histories
- State renderers now aim to show complete report histories instead of limiting the view to a small subset.
- Narratives, citations, and structured category data remain state-specific.

### State-Specific Normalization
- Each renderer in `js/inspections/` maps that state's payload into the shared card and timeline UI.
- The renderers handle state-specific fields such as violation risk levels, rule citations, licensing details, and summary text.

## Adding or Updating a State

1. Acquire the state data from scraping, downloads, FOIA output, or manual collection.
2. Normalize it into the inspections schema and post it through `api/inspections-write.php`.
3. Add or update the renderer in `js/inspections/<state>_reports.js`.
4. Register the state slug and fallback glob pattern in `inc/enqueue.php::kop_enqueue_report_scripts()`.
5. Create or update the WordPress page using the State Reports template.
6. Add a checked-in fallback dataset under `js/data/` if needed for offline resilience or historical snapshots.

## Scraper Notes

- This repo now includes `scripts/mn_scraper.py` as an in-repo example of the inspections pipeline.
- Other state scrapers may live in this repo or in the companion Tools repo, depending on how the ingestion workflow was developed.
- When documenting new states, do not assume every scraper lives in a separate local repository.
