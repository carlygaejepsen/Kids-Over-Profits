# State-Level Inspection Reports

The State-Level Inspection Reports module provides public-facing viewers for state regulatory inspections, citations, and deficiency narratives. It is separate from the general TTI Program Index and focuses on state-specific compliance histories.

## Supported States

Twenty-nine tracker pages, one per state, at `/xx-reports/`. Twenty-seven use the shared engine (`js/inspections/report-page.js` with an adapter in `js/inspections/states/<xx>.js`); Texas and California keep their legacy viewers. Twenty-eight pages are published; Colorado's is built but not yet posted (checked against the live site on 2026-10-13; Hawaii's was published on 2026-10-11):

| State | Slug | Viewer | Data |
|---|---|---|---|
| Arizona (`AZ`) | `az-reports` | shared engine | API |
| Arkansas (`AR`) | `ar-reports` | shared engine | API |
| California (`CA`) | `ca-reports` | `js/inspections/ca-reports.js` (legacy) | `js/data/ccl_reports_batch_*.json` only |
| Colorado (`CO`) | `co-reports` | shared engine | API (`co_scraper.py`; Health department (CDPHE) inspections of the last three years from its Tableau dashboard, each citation's text and the facility's plan of correction, read slowly in real Chrome; youth allowlist in `co_scope.json`; psychiatric residential treatment facilities and psychiatric hospitals with adolescent units only; built 2026-10-05, not yet run live; page not yet posted, no verdict reader) |
| Connecticut (`CT`) | `ct-reports` | shared engine | API, `js/data/ct_reports.json` fallback |
| Florida (`FL`) | `fl-reports` | shared engine | API |
| Georgia (`GA`) | `ga-reports` | shared engine | API |
| Hawaii (`HI`) | `hi-reports` | shared engine | API (published 2026-10-11; `hi_scraper.py`; Health department statements of deficiencies and plans of correction since 2023 for special treatment facilities and therapeutic living programs, a youth allowlist in `hi_scope.json`, scans read by OCR; PDFs archived to the Drive folder `hi_pdfs/`) |
| Idaho (`ID`) | `id-reports` | shared engine | API (`id_scraper.py`; statements of deficiencies with plans of correction and no-deficiency letters from the Health and Welfare public documents library; PDFs archived to the Drive folder `id_pdfs/`) |
| Iowa (`IA`) | `ia-reports` | shared engine | API (`ia_scraper.py`; survey visits to psychiatric medical institutions for children since 2018, CMS-2567 statements of deficiencies with plans of correction, from the DIAL health facilities database; PDFs archived to the Drive folder `ia_pdfs/`) |
| Maine (`ME`) | `me-reports` | shared engine | API (`me_scraper.py`; behavioral health organization surveys from the state licence lookup, an operator allowlist in `me_scope.json`; documents since late 2024 archived to the Drive folder `me_pdfs/`) |
| Maryland (`MD`) | `md-reports` | shared engine | API (`md_scraper.py`; residential child care inspection summaries since 2019, one row per provider, citations split by safety risk; PDFs archived to the Drive folder `md_pdfs/`) |
| Michigan (`MI`) | `mi-reports` | shared engine | API (`mi_scraper.py`; PDFs archived to the Drive folder `mi_pdfs/`) |
| Minnesota (`MN`) | `mn-reports` | shared engine | API |
| Montana (`MT`) | `mt-reports` | shared engine | `js/data/mt_reports.json` (the page reads only the file; `api/lib-mt-reports.php` copies it into the database for the serious findings scan) |
| Nevada (`NV`) | `nv-reports` | shared engine | API |
| New Hampshire (`NH`) | `nh-reports` | shared engine | API (`nh_scraper.py`; every licensing visit with observations and corrective action plans; the state's pages kept in the Drive folder `nh_html/`, statements of findings archived to `nh_pdfs/`; the state shows three years) |
| North Carolina (`NC`) | `nc-reports` | shared engine | API |
| Ohio (`OH`) | `oh-reports` | shared engine | API (`oh_scraper.py`; agency compliance review reports since July 2025, one row per agency, from the Department of Children and Youth agency search; PDFs archived to the Drive folder `oh_pdfs/`) |
| Oklahoma (`OK`) | `ok-reports` | shared engine | API (`ok_scraper.py`; the state's pages are kept in the Drive folder `ok_html/`, no PDFs) |
| Oregon (`OR`) | `or-reports` | shared engine | API (site visits, plus each abuse report ODHS substantiated from its quarterly legislative reports, posted as `kind = "complaint"`) |
| Pennsylvania (`PA`) | `pa-reports` | shared engine | API (`pa_scraper.py`; PDFs archived to the Drive folder `pa_pdfs/`, scans before mid-2019 read by OCR) |
| South Dakota (`SD`) | `sd-reports` | shared engine | API (`sd_scraper.py`; youth care provider licensing studies, corrective action plans and fire, health and safety inspections since 2024 from the licensing portal; PDFs archived to the Drive folder `sd_pdfs/`) |
| Texas (`TX`) | `tx-reports` | `js/inspections/tx_reports.js` (legacy) | API |
| Utah (`UT`) | `ut-reports` | shared engine | `js/data/ut_checklists/ut_reports*.json` (else `js/data/ut_reports*.json`) merged with the API |
| Virginia (`VA`) | `va-reports` | shared engine | API (`va_scraper.py`; two sources: DBHDS licensing inspections and investigations of youth residential services with finalized corrective action plans, and VDSS children's residential facility inspections; PDFs archived to the Drive folder `va_pdfs/`) |
| West Virginia (`WV`) | `wv-reports` | shared engine | API (`wv_scraper.py`; behavioral-health licence surveys, with reports archived to the Drive folder `wv_pdfs/`) |
| Wyoming (`WY`) | `wy-reports` | shared engine | API (`wy_scraper.py`; Family Services notices of non-compliance (OCR) and handwritten facility visits (documents only), plus health department PRTF surveys; PDFs archived to the Drive folder `wy_pdfs/`) |
| Washington (`WA`) | `wa-reports` | shared engine | API |

The canonical state-to-slug list is `kop_state_inspection_page_map()` in `inc/rest-api.php`. Its `$pending` list holds the trackers whose page the owner creates by hand (Michigan, Oklahoma, Pennsylvania, New Hampshire, Wyoming, Idaho, Maine, Ohio, West Virginia, Iowa, Maryland, South Dakota, Virginia, Hawaii, Colorado): each joins the map only once its page is published, so no list links a missing page. All of them are published except Colorado. The home page grid, the hub, the facility pages and the state hubs all derive their tracker links from it (`kop_report_state_links()` and `kop_report_state_sentence()` in `inc/utilities.php`).

## Architecture

### Pages
- `templates/page-state-reports.php` (Template Name: State Reports) is the template for every `/xx-reports/` page. It prints the title, a back link to the state hub (resolved by stripping `-reports` from the slug), the page's editor content, the `last-updated` block, the alphabet filter, search input, sort control, "new reports only" toggle and the results container.
- `templates/page-inspection-reports.php` is the hub at `/inspection-reports/`: the state grid with a report count per state, report and facility totals, hand-featured reports and the newest approved severe findings.
- `templates/page-severe-reports.php` is `/severe-reports/`: every approved severe finding, newest first, 50 per page, filterable by state and kind of harm, each with a `#finding-<id>` anchor.
- The hub and severe pages are created by `kop_tool_page_specs()` in `inc/admin.php`. The tracker pages are ordinary WordPress pages assigned the State Reports template.

### Asset Loading
- `kop_enqueue_report_scripts()` in `inc/enqueue.php` matches the current page against an explicit list of slugs with `is_page()` (not a `*-reports` pattern) and loads that state's script plus `css/facility-reports.css`. Its `json_glob` entry is resolved to URLs and localized as `<xx>ReportsData.jsonFileUrls`.
- Every state except TX and CA (27: AR, AZ, CO, CT, FL, GA, HI, IA, ID, MD, ME, MI, MN, MT, NC, NH, NV, OH, OK, OR, PA, SD, UT, VA, WA, WV, WY) is marked `report_page`: the shared engine `js/inspections/report-page.js` loads first with `css/kop-components.css`, `css/skeleton.css` and `css/report-page.css`, then the state adapter `js/inspections/states/<xx>.js`, which calls `KOP.reportPage.mount(adapter)`. The adapter contract (required `load`, `facilityName`, `reportTime`, `isFlagged`, `summary`, `report`; optional filters, sorts and notes) is documented at the top of `report-page.js`.
- The old per-state viewers (`js/inspections/<xx>_reports.js`) of the states migrated before the engine existed stay in the repo as rollback targets; each such entry in `inc/enqueue.php` names its own. States added since were built on the engine and have none.
- Every tracker page also loads `js/inspections/severe-flags.js` and `css/severe-reports.css` (see Severe Findings).
- `kop_state_reports_body_class()` adds `kop-state-reports-page` to the same slug list.

### Data Sources
- Read: `api/inspections-read.php?state=XX` (public). Reads `inspection_facilities` and `inspection_reports`; `inspection_facilities.state` is the two-letter code. Re-listings of one document (same date, summary, text and categories under different URLs or file ids) are dropped.
- Write: `api/inspections-write.php`, POSTed by the scrapers with `KOP_DATA_API_KEY`; it upserts both tables (and creates them if missing).
- Static JSON in `js/data/` is either the only source (CA, MT), a fallback (CT) or merged with the API (UT); see the table above.
- Archived copies: the scrapers keep each report's PDF (or page) in a per-state folder of the Drive FileBird folder (`<xx>_pdfs/`, Utah's `ut_checklists/`). `php api/sync-inspection-archive.php [apply] [--only=<st>]` (CLI, nightly cron) lists those folders and writes `wp-content/uploads/inspection-reports/<st>/index.json`, mapping each file's lookup key (`kop_inspection_archive_key()`) to its Drive URL; the PDFs stay in Drive and are never copied to the host. A folder is indexed only when it is shared "Anyone with the link"; a private one is reported and its old index removed. `report-page.js` (`ctx.archiveLink()`) and `kop_state_ut_inspection_details()` read the index and print "Archived copy" beside the state's own link, since states take reports down.
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

**`?lite=1`** (used by the FL and NC pages) leaves out every report's `raw_content` and adds `row_id`, `has_text` and `text_signals`: what the state's adapter would have read from the text at load (NC: citations, survey result, complaint outcome, opening sentence; FL DJJ: compliance words, PREA date and standard counts, SPEP period, facility name). They come from `api/lib-inspection-text-signals.php`, a PHP port of the adapters' readers; `node scripts/test-inspection-text-signals.js --php=<php.exe>` runs both over every FL and NC report in the mirror and must report no difference. The lite response is cached in `wp-content/uploads/kop-cache/` until the state's reports change (Florida went from 106 MB to 1.5 MB, 0.13 MB compressed; North Carolina from 95 MB to 3.4 MB). **`?text=<row_id>`** returns one report's `raw_content`; `KOP.reportPage.withText()` fetches it when a report is opened. `scripts/test-inspections-read-lite.php` checks both against the full response.

## Key Features

### Shared Report Experience (`report-page.js`)
- Alphabet filter, full-text search, sorts (default A-Z, name, violations only, most violations, most recent inspection) and a "new reports only" toggle (last 30 days).
- Optional per-state filter dropdowns and a note for states whose data records no findings.
- Report bodies can render lazily on first open, which keeps states with large document text listable. A body may also return a promise (see `withText()`), shown as a loading line until it settles.

### State-Specific Normalization
- Each adapter in `js/inspections/states/` decides how its data loads, what counts as a violation (read from the document text where the scraper's own fields are unreliable) and what a facility summary and a report show. The header comment of each adapter records those decisions.

### Severe Findings
- `inc/inspection-highlights.php` extracts findings per sentence, scores them by category of harm (death, sexual abuse, physical abuse, restraint with injury, self-harm, medical neglect, hospitalisation, missing child, police) scaled by the state's own severity signal, and stores candidates in `inspection_highlights` (scan progress in `inspection_highlight_scans`). Only substantiated findings are queued. 25 states have extractors (`kop_ih_supported_states()`): TX, CA, UT, AZ, CT, NC, GA, MN, AR, FL, OK, PA, MI, MT, NH, WY, ID, ME, OH, WV, IA, MD, SD, VA, WA. Not scanned: NV, OR, HI, CO. Washington reads only `categories.findings`, which `wa_scraper.py` fills since its column reader; older WA rows give nothing until a `--full` run. Montana's surveys are read from `js/data/mt_reports.json`, copied into the database by `api/lib-mt-reports.php`.
- Owner rules built into the scanner: a citation whose fault is only records, notices or training keeps only staff abuse, medical neglect and deaths (a death counts however the state cited it); a resident assaulting staff is not abuse; a plan of correction quoted in a finding is noise.
- Versions: `kop_ih_scanner_version($state)` is a shared base (10) times 100 plus the state's own bump from `kop_ih_state_rule_versions()` (CA 1), so a change to one state's adapter rescans only that state's reports.
- `api/scan-inspection-highlights.php` runs the scan (browser: dry run, then `?apply=1`; a saving browser run scans about 25 seconds a load and reloads itself until nothing remains; CLI: `php api/scan-inspection-highlights.php apply`). Findings are reviewed at KOP Tools > Review inbox > Serious violations (`inc/review-inbox/inspection-highlights.php`), which writes a decision exactly as the older screen `api/review-inspection-highlights.php` does (`?ids=...&tick=1` there rejects a picked list in one click). An approval folds the facility's same-day findings into one (`kop_ih_merge_same_day()`).
- Nightly cron (cPanel > Cron Jobs; the CLI binary, since cron's `php` is php-cgi and exits "CLI only."):

  ```
  15 4 * * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/scan-inspection-highlights.php apply >> /home/kidsover/logs/inspection-highlights-scan.log 2>&1
  ```

  Each night scans only reports this version of the rules has not seen, so it is quick once the backlog is done; new candidates wait in the review inbox.
- A finding is severe at score 70 or more (`kop_ih_severe_score()`); nothing is published until an admin approves it. Approved severe findings appear on the home page, the hub and `/severe-reports/` (`templates/page-severe-reports.php`: newest first, 50 a page, filterable by state and kind of harm, a `#finding-<id>` anchor each). Approved findings also show on the facility pages and program home cards, and count in the rankings.
- On the tracker pages, `js/inspections/severe-flags.js` reads `api/inspection-highlights-read.php?state=XX` (public, approved severe findings only) and flags matching reports by the opening words of the quote, since the static JSON report ids do not match the database. It works on both the shared engine and the legacy TX and CA markup.

### Person labels and codes in findings
Findings print the state's own words, but its labels for people are spelled out as an editor's insertion (`kop_ih_reader_labels()`, used for every printed excerpt): "S1" and "E1" become "[Staff 1]", "C1" "[Child 1]", "Y1" "[Youth 1]", "R1" "[Resident 1]"; North Carolina's "FC #3", "FS #1", "DC #9" and "QP #2" become "[Former client 3]", "[Former staff 1]", "[Deceased client 9]" and "[Qualified professional 2]", Arkansas's "MHT #1" "[Mental health technician 1]", and Minnesota's "the SP" and "the AV" "[the staff person]" and "[the alleged victim]". Where the state already bracketed a code its brackets are kept, a label the report defines ("Staff 1 (S1)") keeps just the words, and codes after a word naming a section, form or rule are left alone.

### Plain-language summaries
Some approved findings are a wall of codes, numbered people, stitched fragments and run-on sentences. `inc/highlight-summaries.php` picks those (`kop_hs_reasons()`: codes, numbered people, fragments joined with "[...]", run-on sentences, legal citations; about one in ten), and an hourly WP-Cron job (`kop_hs_hourly`) asks the AI for one to three plain sentences from the state's text only, through `kop_ai_generate_alternating()` (Groq and Gemini in turn). Drafts wait at KOP Tools > Review inbox > Plain summaries (edit, approve, reject, write again) and show above the state's wording on facility pages, home cards and `/severe-reports/` only once approved; the state's wording always stays. A summary belongs to the exact excerpt it was written from (`excerpt_hash`): a changed excerpt hides it and a new draft is made. Table `inspection_highlight_summaries`. On the server (ea-php82): `php api/scan-highlight-summaries.php [--try|apply] [--limit=10] [--ids=704]`, a dry run unless told otherwise.

### State hub inspection cards
A state hub's inspection cards (`kop_state_collect_inspection_summaries()` in `inc/rest-api.php`) say what the state's own `/xx-reports/` page says: the same badges, tone and count. Each state with an adapter except Colorado (26) has a PHP reader at `api/inspection-verdicts/<st>.php` defining `kop_iv_<st>($report)` and, where the verdict reads the report text, `kop_iv_<st>_needs_text()`. `api/lib-inspection-verdicts.php` loads them (`kop_inspection_verdict()`), and `kop_iv_version()` is part of the hub cache key. Text is fetched only for the rows whose reader needs it. Each port must return exactly what its adapter returns: change both together and run `scripts/test-inspection-verdicts.js`.

### Inspection Links
Facility pages take a state's inspection rows by name key (`kop_facility_pages_inspections()` in `inc/facility-pages.php`). Where the names differ (Pennsylvania licenses each building: "Adelphoi Village: Benet" for the record "Adelphoi Benet Home"), KOP Tools > Inspection Links (`inc/inspection-links.php`, also a Review inbox tab) suggests pairs in one state whose distinguishing words match, ticked in advance when in the same town; approved links are added to the name matches (option `kop_inspection_links`, with the rejections). A program with homes under it (Program Homes, `inc/program-homes.php`) is one card, linked as a unit ("Same program"; "Only <home>" for a row one home suggested), and rows a home already shows are not offered. "Link an entry by hand" links any row to any record. Checked links ship in `seeds/inspection-links.json`, applied once per `KOP_INSPECTION_LINKS_SEED_VERSION` (`kop_inspection_links_apply_seed()`). Names the network map's rename lines join to a record also match its inspection rows when no other record reaches them (`kop_facility_pages_doc_name_keys()`).

### Inspection Rankings
KOP Tools > Inspection Rankings (`inc/inspection-rollup.php`) ranks companies, facilities and states by approved serious findings by category (deaths, staff assaults, sexual abuse, ...) and by the states' own verdicts: reports, reports citing violations, violations cited, high-risk and repeat citations, complaints investigated and substantiated. `kop_irl_state_measures()` says which state publishes which measure and where it comes from; a measure a state does not publish is shown as a dash, never 0. An hourly batch counts each report once into `inspection_report_counts` (a report stored twice counts once, by content hash); bump `kop_irl_version()` (now 5) after changing a rule and every report is counted again. Records take rows by the facility pages' name rule plus Inspection Links, companies through `{prefix}kop_operator_facilities`. Owner rule (2026-10-05): public numbers are approved findings and state verdicts only; pending findings are counted apart on the admin screen.

### North Carolina cleanup
The old `nc_scraper.py` matcher filed adult-only facilities and posted one facility under many licences (81 adult-only facilities, 65 duplicate rows; the scraper is fixed in the Tools repo). `seeds/nc-inspection-cleanup.json` lists the rows to remove or merge (rules in `api/lib-nc-inspection-cleanup.php`). `php api/clean-nc-inspections.php [apply]` on the server (ea-php82) is a dry run unless `apply`; it saves every row it touches to `~/kop-backups/nc-inspection-cleanup-<time>.json` first, moves reports and reviewed findings of merged copies onto the row kept, and follows Inspection Links.

## Adding or Updating a State

1. Acquire the state data from scraping, downloads, FOIA output, or manual collection.
2. Normalize it into the inspections schema and POST it to `api/inspections-write.php`.
3. Write an adapter at `js/inspections/states/<xx>.js` against the `report-page.js` contract.
4. Add the slug to `kop_enqueue_report_scripts()` (with `report_page => true`) and `kop_state_reports_body_class()` in `inc/enqueue.php`, and the state to `kop_state_inspection_page_map()` in `inc/rest-api.php` (plus `kop_state_inspection_dataset_urls()` if it ships static JSON).
5. Create the WordPress page `/xx-reports/` with the State Reports template.
6. Port the adapter's verdict to `api/inspection-verdicts/<xx>.php` (`kop_iv_<xx>()`) for the state hub cards, and run `node scripts/test-inspection-verdicts.js --php=<php.exe> --states=<XX>`.
7. For severe findings, add an extractor to `kop_ih_extract()` and the state to `kop_ih_supported_states()`, then bump the state's entry in `kop_ih_state_rule_versions()` (or the shared base in `kop_ih_scanner_version()` when the shared rules change).
8. If the rankings should count its verdicts, add the state to `kop_irl_state_measures()` and bump `kop_irl_version()`.
9. For archived copies, add its Drive folder to `$FOLDERS` in `api/sync-inspection-archive.php` and share the folder "Anyone with the link".

## Scrapers

- No scraper lives in this repo any more (`scripts/mn_scraper.py` was removed on 2026-06-01). The state scrapers (`<xx>_scraper.py`, `scraper_launcher.py`, the shared `inspection_api_client.py` that POSTs to `api/inspections-write.php`) and `STATE_SCRAPER_GUIDE.md` are in the companion Kids-Over-Profits-Tools repo.
- `scripts/sync-prod-sqlite.py` copies production into `tmp/prod.sqlite` for offline work on these tables.

## Tests

```bash
# FL/NC text readers: PHP must match the adapters exactly
node scripts/test-inspection-text-signals.js --php=<Local php.exe>
php scripts/test-inspections-read-lite.php     # inspections-read.php ?lite=1 / ?text= against tmp/prod.sqlite
# State hub verdicts: the real adapters against the PHP readers on every report
node scripts/test-inspection-verdicts.js --php=<Local php.exe> [--states=PA,NC] [--ids=..]
php scripts/test-inspection-highlights.php     # rule cases plus a dry run over tmp/prod.sqlite
node scripts/test-severe-flags.js
php scripts/test-highlight-summaries.php       # which findings are picked, answer checks, printing
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=highlight-summaries
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-inspection-rollup.php [--list]
php scripts/test-inspection-links.php --file=<scraper --out json> [--state=PA]
php -d extension=pdo_sqlite scripts/test-nc-inspection-cleanup.php   # on an in-memory copy of tmp/prod.sqlite
```

## Open Work

- Migrate Texas and California to the shared engine.
- Colorado: first live run of `co_scraper.py`, publish `/co-reports/`, then a verdict reader and a severe-finding extractor.
- Washington: a `--full` run of `wa_scraper.py` (needs the API key), so older rows get findings.
- Severe-finding extractors for NV, OR and HI.
- California stores about 9,100 reports twice under two id schemes, which inflates the hub's report counts (the rankings count each once).
