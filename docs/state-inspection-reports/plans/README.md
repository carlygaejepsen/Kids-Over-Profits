# New state scraper plans: shared ground rules

One plan per state lives in this folder. Each is written to be handed to an
agent that has not seen the research. Read this file first, then the state
plan.

**Built and live (2026-09-30):** Michigan, Oklahoma, Pennsylvania. Their plans
stay here as the record of how each was built, and their scrapers and adapters
(`mi`, `ok`, `pa`) are the closest working examples for a new state.

**Ready to build (researched 2026-10-01).** Ten plans, in the order to
build them: the first two lose data while they wait.

| Plan | State | Source | Size | Why it matters |
|---|---|---|---|---|
| [new-hampshire.md](new-hampshire.md) | NH | Child care licensing search (Salesforce Visualforce, HTML) | 24 programs, 146 visits | Complaint visits with observations and corrective plans; the state shows only three years |
| [wyoming.md](wyoming.md) | WY | Family Services findings page (Google Drive PDFs, scanned) plus health department surveys (JSON + PDF) | 24 providers, about 420 documents | Notices of non-compliance; documents vanish with their provider |
| [virginia.md](virginia.md) | VA | Two agencies: behavioral health provider search (form posts + PDF, needs a browser once per run) and social services facility search (plain HTML) | about 140 + 19 facilities | Largest uncovered state (139 tracked) |
| [ohio.md](ohio.md) | OH | Children and Youth agency search (Salesforce Aura + PDF) | 159 agencies, about 500 PDFs | 63 tracked; reports begin July 2025 and keep coming |
| [idaho.md](idaho.md) | ID | Health and Welfare public document folders (JSON + PDF) | 40 facilities, about 156 PDFs | Core state for the industry; small and quick |
| [iowa.md](iowa.md) | IA | Inspections department health facilities database (JSON + PDF) | 46 institutions, 240 PDFs | Federal surveys with complaints; closed institutions kept |
| [west-virginia.md](west-virginia.md) | WV | Health facility licensure lookup (JSON + generated PDF) | about 80 records, about 540 surveys | History back to 2001; scope and PDF text need care |
| [maine.md](maine.md) | ME | State licence lookup (form posts + PDF) | about 13 youth operators | Complaint surveys and deficiencies since late 2024; scope is an operator allowlist |
| [south-dakota.md](south-dakota.md) | SD | Licensing portal (HTML + PDF) | 27 providers, about 150 PDFs | Small and easy |
| [maryland.md](maryland.md) | MD | Human Services report folders (HTML listing + PDF) | 32 providers, 174 PDFs | Summaries only |

**Built 2026-10-05, not yet posted:** [hawaii.md](hawaii.md), Hawaii (HI), the
Health department's statements of deficiencies for youth special treatment
facilities and therapeutic living programs (8 of 43 facilities, 20 statements
since 2023; a youth allowlist; scans read by OCR).

[research-log.md](research-log.md) records what every state publishes,
including the ones with nothing online, so the research is not repeated.

**First round:**

| Plan | State | Source | Size | Why it matters |
|---|---|---|---|---|
| [michigan.md](michigan.md) | MI | MDHHS child welfare licensing search (JSON + PDF) | 110 facilities, about 1,900 PDFs | Most tracked facilities of any uncovered state (141); special investigation reports |
| [pennsylvania.md](pennsylvania.md) | PA | DHS Human Services Provider Directory (HTML + PDF) | about 595 licensed units, about 7,600 PDFs | Deepest history (2010 onward), plans of correction included |
| [oklahoma.md](oklahoma.md) | OK | OKDHS residential locator (HTML only) | 93 programs | Smallest build; the source shows a rolling 36 months, so every month not scraped is lost |

All facts in a plan were checked against the live site on the date the plan
gives, from a residential IP with plain `requests` unless it says otherwise. Probe scripts and sample responses are
in `tmp/scraper-research/<state>/` in this repo (gitignored, this machine only).
If that folder is missing, the plans carry enough to rebuild the probes.

The builds are independent. If several agents work at once, the registration
edits in step 4 below touch the same few lines in shared files: pull before
editing them and keep those commits small.

## The two repos

- **Web repo** (this one): `C:/Users/daniu/source/repos/Kids-Over-Profits`,
  [github.com/carlygaejepsen/Kids-Over-Profits](https://github.com/carlygaejepsen/Kids-Over-Profits).
  Holds the page adapter, registration and severe-finding extractor.
- **Tools repo**: `C:/Users/daniu/OneDrive/Documents/GitHub/Tools/Kids-Over-Profits-Tools`,
  [github.com/carlygaejepsen/Kids-Over-Profits-Tools](https://github.com/carlygaejepsen/Kids-Over-Profits-Tools).
  Holds every scraper. Read `STATE_SCRAPER_GUIDE.md` there before writing code;
  its scraper template, payload schema and incremental-state rules apply.
  Its frontend section is out of date: pages now use the shared engine
  described below, not a per-state `xx_reports.js`.

Rules for both: commit straight to `main`, no feature branches. Stage files by
path, never `git add -A` (the Tools repo sits in OneDrive with large caches and
other sessions share the web checkout). Check `git log -1 --stat` after each
commit. Both repos are public: never commit report PDFs, state files, caches or
the API key.

## What "done" means for a state

1. **Scraper** `<xx>_scraper.py` in the Tools repo, using
   `inspection_api_client.post_facilities_to_api`, `scraper_state.py` and (for
   PDF states) `report_store.ReportStore`. Flags: `--full`, `--no-post`,
   `--limit N`, plus `--out <file.json>` which writes what the read API would
   return (`{"source_state", "scraped_timestamp", "facilities": [...]}`) so the
   page can be tested before anything is posted.
2. **Launcher and guide**: an entry in `SCRAPERS` in `scraper_launcher.py` and
   a section under "Existing Implementations" in `STATE_SCRAPER_GUIDE.md`.
3. **Adapter** `js/inspections/states/<xx>.js` in the web repo, written against
   the contract at the top of `js/inspections/report-page.js`. The header
   comment of the adapter records what counts as a violation and why, as the
   existing adapters do.
4. **Registration** in the web repo:
   - `inc/enqueue.php`: an entry in `kop_enqueue_report_scripts()` with
     `'report_page' => true` and `'json_glob' => ''`, and the slug in
     `kop_state_reports_body_class()`.
   - `inc/rest-api.php`: the state in `kop_state_inspection_page_map()`.
   - `inc/utilities.php`: the fallback list in `kop_report_state_links()`.
   - `docs/state-inspection-reports/README.md`: the table row and the count in
     the sentence above it. `AGENTS.md` lists the adapters and slugs too.
   - PDF states only: the Drive folder in `$FOLDERS` in
     `api/sync-inspection-archive.php`, and `archiveState` in the adapter.
5. **The page** `/xx-reports/`: created on deploy by an entry in
   `kop_tool_page_specs()` in `inc/admin.php` with the State Reports template
   and `'shared' => true`, as the Oklahoma and Pennsylvania entries do. Its
   `content` is one short paragraph saying which agency the reports come
   from, with a link to the source, and what the source leaves out.
6. **Severe findings** (second phase, after the data is live): an extractor in
   `kop_ih_extract()` in `inc/inspection-highlights.php`, the state in
   `kop_ih_supported_states()`, a bump of `kop_ih_scanner_version()`, and cases
   in `scripts/test-inspection-highlights.php`. The rules are in
   `docs/PLAN.md` section 3.3: substantiated findings only, no child-on-child
   fights, single medication errors never.
7. **`docs/PLAN.md`** section 3.3 updated in the same commit as the work.

## Payload rules that bite

- `inspection_facilities` is unique on `(state, facility_name, program_name)`.
  `program_name` must be the state's own stable id (licence or case number). A
  renamed facility otherwise becomes a second row.
- `inspection_reports` is unique on `(facility_id, report_id)`. Re-posting the
  same `report_id` updates the row, which is how edited source content is
  refreshed.
- `facility_name` is what the generated `/facility/<slug>/` pages match on
  (`kop_facility_pages_inspections()` in `inc/facility-pages.php`, by name key
  within the state). Use the name the public knows, not an internal code. After
  the first `--out` run, run
  `php scripts/match-inspection-names.php --state=XX --file=<out.json>`: it
  prints which records the names reach and the near misses. Do not rename
  records to force a match; the owner links leftovers at KOP Tools >
  Inspection Links (`inc/inspection-links.php`).
- `report_date` must parse with JavaScript `new Date()`; send ISO
  `YYYY-MM-DD`.
- Put everything the page needs at list time into `categories`. Then the page
  can load `inspections-read.php?state=XX&lite=1`, which drops `raw_content`,
  and fetch one report's text on open with `KOP.reportPage.withText()`
  (see `js/inspections/states/nc.js`). Lite works for any state. The
  `text_signals` PHP port in `api/lib-inspection-text-signals.php` is only
  needed when the page reads the text itself, which these plans avoid.
- `raw_content` still carries the full text: site search and the severe
  finding scan read it.

## Only licensing reports are posted

State document stores hold things that are not licensing reports. Michigan's
held a seclusion sheet naming a detained youth, and it was posted before
anyone noticed. Every scraper therefore decides what a document is before it
goes in the payload: a document that is not one of the report kinds the plan
names is logged, left out, and listed in the run report for the owner
(`mi_scraper.py` counts these as `not_a_report`). Extracted text is also
checked for things that must not be public (a date of birth, a named child, a
record number); a hit holds the report back the same way.

## Reading PDF tables

Statements of deficiencies are usually tables with a rule, a finding and a
plan side by side. `pdfplumber`'s `extract_text()` interleaves the columns
line by line, which cannot be undone afterwards (this is why Washington is
out of the severe-finding scan). Use `page.extract_tables()`, or crop by
column positions, and check on real samples that each cell comes out whole.

## Posting to production

`api/inspections-write.php` needs `KOP_DATA_API_KEY`. The scrapers read it from
the environment; `scraper_launcher.py` fills it from the web repo's `.env`. Build and test with `--no-post --out`. The first
production post for a new state is outward-facing: stop and ask the owner
before it, with the `--out` numbers in hand (facilities, reports, flagged
reports, date range). After the owner agrees, post with `--limit 3` first, load
`https://kidsoverprofits.org/wp-content/themes/child/api/inspections-read.php?state=XX`,
then post the rest.

## Testing the page before it exists on the site

Serve the `--out` file to the adapter in place of the API: either the jsdom
harness used for the state hubs, or Playwright with a route intercept on
`inspections-read.php` against an existing tracker page such as
`https://kidsoverprofits.org/ga-reports/` with the local adapter and
`report-page.js` swapped in. Look at screenshots at 390, 768 and 1440 px.
Then, once live: `python scripts/check-bare-text.py`,
`python -u scripts/check-contrast.py /xx-reports/`,
`node scripts/test-severe-flags.js`, PHP lint with Local's bundled `php.exe`
(`-n -l`; there is no `php` on PATH), and `gh run list --workflow=deploy.yml`
to confirm the deploy.

## Conduct toward the state sites

One request at a time, 0.5 to 1 second apart, a generic browser User-Agent, no
personal email address in any header or URL. Cache what was fetched so a
parser fix never means a second download. Never pass `verify=False`.

## Site rules the adapter must follow

No emojis anywhere (icons come from `kopIcon()`), colours only through
`var(--kop-*)`, no text directly on the gradient background, report bodies
built only with the `ctx.ui` helpers (they escape), non-breaking spaces written
as the JavaScript escape for U+00A0, not the literal character.
