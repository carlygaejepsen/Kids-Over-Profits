# Virginia inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Virginia's licensing findings at
`https://kidsoverprofits.org/va-reports/`, fed by a new `va_scraper.py` with
two sources. Virginia is now the largest uncovered state in the facility
database: 139 tracked facilities, 121 open.

Two agencies license youth residential programs and each has its own site:

| Source | Covers | Size | Difficulty |
|---|---|---|---|
| **DBHDS** (Behavioral Health and Developmental Services) | Psychiatric residential treatment facilities, therapeutic group homes, crisis and substance use residential services for children | 22 + 117 location rows in the two main types | Moderate: a JavaScript challenge, then stateful form posts |
| **VDSS** (Social Services) | Children's residential facilities that are not behavioural health | 19 facilities, 180 inspections | Easy: plain GET pages |

Build VDSS first (a day's work, proves the page), then DBHDS, which holds
most of the facilities we track. One scraper, `--source vdss|dbhds|all`, as
`fl_scraper.py` does for Florida's sources.

## Source 1: VDSS (verified 2026-10-01)

https://www.dss.virginia.gov/licensed-care/search-licensing-programs/childrens-residential-facility-search/

Plain `requests` works; no login, CAPTCHA or bot wall. Three page shapes, all
GET on that one URL:

| Query string | Returns |
|---|---|
| `?facilityName=&location=&zipCode=&page=1&perPage=100&sort=asc&endpoint=crf` | The list. All 19 fit on one page. |
| `?licenseId=26703&endpoint=crf` | One facility: details and its inspection table |
| `?action=inspection&licenseId=26703&inspectionNumber=3114&endpoint=crf` | One inspection |

The list page embeds the raw search response as JSON (HTML-escaped) inside a
`<details>` block titled "Show Raw Search API Response": `licensedFacilities[]`
with `licenseId`, `licensed`, `facilityName`, `address{...}`, `phoneNumber`,
`facilityType`. That block looks like a debugging aid and may vanish; take
licence ids from the `licenseId=` links and use the JSON only if present.

Facility page fields: name, address, phone, Facility Type, License Type
(for example `2YR`), Expiration Date, Administrator, Capacity, Ages,
Inspector, and a table of Inspection Date (sometimes two dates,
`01/30/2023, 02/07/2023`), Complaint Related (YES/NO), Violations (Yes/No).

Inspection page: Inspection Dates, Complaint Related, Inspector, Areas
Reviewed (regulation sections), Comments (the inspector's narrative, ending
in several paragraphs of standing boilerplate about plans of correction and
appeal rights), then a Violations section with repeated blocks of `Standard:`,
`Description:`, `Violation:` with numbered `Findings:`, and `Plan of
Correction:` (so far always "Not available online. Contact Inspector for
more information."). Youth and staff appear as Y1, S1.

Across the 19 facilities on 2026-10-01: 180 inspections, 2021 to 2026, 21
complaint related, 64 with violations. Only currently licensed facilities are
listed; keep every licence id in the state file and keep asking for it.

Samples: `tmp/scraper-research/va/va_list.html`, `va_fac.html`,
`va_insp.html`, `va_probe.py`.

## Source 2: DBHDS (verified 2026-10-01)

https://vadbhdsv7prod.glsuite.us/GLSuiteWeb/Clients/vadbhds/Public/ProviderSearch/ProviderSearchSearch.aspx

### Getting in

The host sits behind an Azure application gateway that answers a plain
request with a 403 JavaScript challenge. A headless Playwright Chromium passes
it by itself in 4 to 12 seconds and receives a cookie named
`appgw_azwaf_jsclearance` (it lasted about five hours in testing).

**After that, plain `requests` does everything.** Verified end to end: copy
that one cookie and the browser's exact User-Agent into a `requests.Session`,
and the form, search, detail pages and PDFs all return 200. So the browser is
needed only to mint the cookie, at start-up and again whenever a response
comes back 403. Do not drive the whole scrape through the browser.

### The flow (ASP.NET WebForms, session-bound, no per-facility URLs)

Each step posts the previous page's full form (every hidden input, every
select's current value) to that page's own form action, plus the one button
clicked. A helper that does exactly this is in
`tmp/scraper-research/va/dbhds_replay.py`.

1. `GET ProviderSearchSearch.aspx`. Selects: Service Type (72 options, value
   equals the label), Status (blank, Active, Closed), License Type,
   Demographics, Gender, Diagnosis.
2. Post with the Service Type chosen and
   `ctl00$ContentPlaceHolder1$btnSubmit=Submit`. Lands on
   `ProviderSearchResults.aspx` with grid `dtgProviderSearchResults`, not
   paginated. Columns: Provider Name (a submit button), Provider Number,
   Status, Service (for example `14-001`), Location Name, Location City,
   Location Zip, Region. One row per location.
3. Post the row's Provider Name button. Lands on `ProviderSearchDetails.aspx`:
   provider name, address, contact, status, and a table of the provider's
   service licences, each with a button whose value is the service code.
4. Post the service button. Lands on `ProviderSearchServiceDetails.aspx`:
   licence number (`630-14-001`), licensed-as text, licence status and type,
   dates, stipulations, and three tables:
   - `dtgLocations`: Location Name, City, Zip Code.
   - `dtgInspections`: Inspection Date, Purpose (Unannounced, Scheduled, Human
     Rights, Death or Serious Incident, In-Office Review; can be blank), and a
     "View CAP" button on rows that have a finalized plan.
   - `dtgInvestigations`: Date Received, Investigation Number
     (`630-14-001-333`), Close Date, and an "Investigation Details" button.
5. Post a "View CAP" button. The response is the same page with a script that
   opens
   `/GLSuiteWeb/Clients/vadbhds/SubmitOpen.aspx?fileName=https%3a//VADBHDSv7PROD.glsuite.us/UI/Common/Report/<guid>.pdf`.
   URL-decode the `fileName` value and GET it directly: it returns the PDF.
   The guid is generated per request and the file is temporary, so download
   at once and never store that URL as the report link.
6. Post "Investigation Details". Lands on
   `ProviderSearchInvestigationDetails.aspx`: investigation id, provider,
   licence, program, inspection start and end dates, and its own "View CAP"
   button (`dtgCAP`). **Not finished in research:** one attempt to fetch an
   investigation's PDF got a 500. Work this out first in the build (compare
   with what a real browser does on that click); if investigations' plans
   cannot be fetched, still post the investigation as a report with its dates.

The site notes that only finalized, accepted corrective action plans are
shown, and that providers closed before 2021-10-28 are left out. History
runs from late 2021.

### Scope

Service types to search, as a constant list with an override flag:

- MH Psychiatric Residential Treatment Facility (PRTF) Service for Children and Adolescents (22 rows)
- MH Residential Therapeutic Group Home Service for Children and Adolescents (117 rows)
- MH Residential Crisis Stabilization Service for Children and Adolescents
- SA Residential Clinically Managed Medium-Intensity Service - ASAM Level 3.5 for Children and Adolescents
- SA Residential Clinically Managed Low-Intensity Service - ASAM Level 3.1 for Children and Adolescents
- SA Medically Monitored Intensive Inpatient Service - ASAM Level 3.7 for Children and Adolescents
- MH Inpatient Psychiatric Service for Children and Adolescents

Search each with Status blank, so closed services still on the site are
taken. Out of scope: every developmental disability (DD) service, day
treatment, outpatient, in-home, respite and case management.

### What a corrective action plan looks like

A generated text PDF (3 to 8 pages), sample at
`tmp/scraper-research/va/dbhds_cap2.pdf`. Every page repeats a header:
licence number, date of inspection, organization name, program type and
facility name. Then a five-column table: Standard(s) Cited, Comp (N = not
met), Description of Noncompliance, Actions to be Taken, Planned Comp. Date.
The provider's answer is marked `PR)` and the Office of Licensing's response
`OLR)` with Accepted, Partially Accepted or Not Accepted and a date.

As with Idaho, **extract with `page.extract_tables()`**: plain text extraction
interleaves the columns. On the sample, `extract_tables()` returned the five
columns cleanly, one row per cited standard (the column header row is not
part of the table). How a citation that runs over a page break comes back was not
checked; test it on a long plan and join the pieces.

Noncompliance text can cite incident numbers ("CHRIS Number: 20250033") and
describe individual incidents. People should appear as "Individual #1" or
initials. See the privacy rule below.

## Decisions already made

- `state` = `VA`, page slug `va-reports`, one adapter for both sources, state
  files `.va_vdss_state.json` and `.va_dbhds_state.json`.
- **VDSS facility**: `facility_name` = facility name; `program_name` =
  `VDSS-<licenseId>`; `program_category` = "Children's Residential Facility
  (VDSS)"; `executive_director` = Administrator; `bed_capacity`;
  `license_exp_date`; `action` = licence type.
- **VDSS report** = one inspection: `report_id` = `vdss-<inspectionNumber>`;
  `report_date` = the last inspection date, ISO; `report_url` = the
  inspection URL. `categories`: `source: "vdss"`, `complaint_related`,
  `inspector`, `areas_reviewed[]`, `comments` (with the standing boilerplate
  cut at "The evidence gathered during the inspection" or "Compliance with
  all applicable regulations"), `violations[] {standard, description,
  findings}`, `violation_count`. `raw_content` = comments plus violations.
- **DBHDS facility** = one licensed service at one provider:
  `facility_name` = the location name when the service has a single location,
  else the provider name; `program_name` = the service licence number
  (`630-14-001`); `program_category` = the short service label ("Psychiatric
  residential treatment facility (DBHDS)", "Therapeutic group home (DBHDS)",
  and so on); `action` = licence status and type; `license_exp_date`;
  `full_address` = provider address; `executive_director` = contact. Put the
  provider name and the list of locations in every report's `categories`
  (`provider`, `locations[]`), and include both in the adapter's `searchText`.
- **DBHDS report** = one inspection row or one investigation:
  `report_id` = `insp-<YYYYMMDD>-<slug of purpose>` (add `-2` on a clash) or
  the investigation number; `report_date` = inspection date, or the
  investigation's close date (received date if still open); `report_url` =
  the search page (there is no deep link). `categories`: `source: "dbhds"`,
  `kind: "inspection" | "investigation"`, `purpose`, `has_cap`,
  `citations[] {standard, standard_text, noncompliance, provider_action,
  licensing_response, planned_date}`, `citation_count`, `archive_name`.
  An inspection with no "View CAP" button is posted with `has_cap: false`
  and no citations: it shows the visit happened, and it must not be shown as
  "no violations", only as "no finalized plan posted".
- PDFs go through `ReportStore("VA_PDF_CACHE", "va_pdfs", <local fallback>)`
  named `<licence>_<report_id>.pdf`; `'va_pdfs' => 'va'` in
  `api/sync-inspection-archive.php`; `archiveState: 'VA'`. The archived copy
  is the only link a reader can open for a DBHDS plan.
- **Flagged** = a VDSS inspection with violations, or a DBHDS report with
  citations. VDSS inspection without violations is `clean`. A DBHDS row
  without a plan is `neutral`.
- A plan appears on the site only once finalized, so a row seen without a
  plan may gain one later. Keep `has_cap` per report in the state file and
  re-check rows that had none for 18 months after their date.
- Lite loading (`?state=VA&lite=1`, text on open), since the DBHDS text will
  be several MB.
- **Privacy.** Before anything is posted, run every extracted text through a
  check for patterns that should not be public: a date of birth, a full name
  followed by "DOB", a medical record number, a street address of an
  individual. Any hit keeps that report out of the payload and puts it in
  the run report for the owner. Michigan's source once held a seclusion sheet
  naming a detained youth; assume it can happen here.

## Build steps

1. **VDSS**: list, facility pages, inspection pages (about 200 GETs), parser,
   `--out`, name match, adapter, registration, owner sign-off, post.
2. **DBHDS cookie**: a small function that launches Playwright, waits for
   `#ContentPlaceHolder1_ddlServiceType`, and returns the clearance cookie and
   User-Agent; called at start and on any 403. Use a persistent profile
   outside the OneDrive folder, as the Minnesota launcher entry does.
3. **DBHDS walk**: for each service type, search; for each distinct provider
   in the grid, open details; for each in-scope service licence, open service
   details. Because the site is session-bound, navigate strictly in order and
   re-run the search when a step returns the search form instead of the
   expected page. Save each service details page (gzipped, beside the PDFs)
   so the lists can be re-read without the site.
4. **DBHDS plans**: for each inspection row with a plan not yet seen, post
   the button, decode the PDF URL, download immediately, extract. Then the
   investigations (see the open problem in step 6 of the flow).
5. **Parser** for the plan PDFs, written against at least 60 of them. Print:
   reports per kind, with and without plans, citations per plan, plans where
   the table extraction failed, and the privacy check's hits.
6. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=VA --file=<out.json>`.
   Our Virginia records are named like "Grafton - Carter Place Group Home",
   "Poplar Springs Hospital RTC", "Intercept Health- Shenandoah House", so
   try both the location name and "provider - location" and report which
   reaches more records before fixing `facility_name`.
7. **Adapter** `js/inspections/states/va.js`. Closest models:
   `js/inspections/states/fl.js` (two sources in one page, a source filter)
   and `js/inspections/states/pa.js` (citations with plans).
   Filters: source/agency; service type; report kind. A note that DBHDS
   publishes only finalized plans and nothing before late 2021, and that
   VDSS does not post plans of correction.
8. **Registration** per README step 4, page via `kop_tool_page_specs()`.
9. **Owner sign-off, then post** per the README.
10. **Severe findings** as a follow-up: VDSS violation findings and DBHDS
    "Description of Noncompliance" (a cited standard marked N is
    substantiated). Death or Serious Incident inspections deserve a look
    first.

## Acceptance checks

- `--source vdss --no-post --out`: 19 facilities, about 180 inspections, 64
  flagged, boilerplate removed from comments.
- `--source dbhds --no-post --out --limit 10`: ten services with inspections,
  plans downloaded and parsed into citations.
- A forced 403 (drop the cookie mid-run) recovers by minting a new one.
- A second run downloads nothing new.
- The privacy check's hits, if any, are listed and not in the payload.
- The page, fed the `--out` file, separates the two sources and never shows
  "no violations" for a DBHDS row that merely has no plan posted. Screenshots
  at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post for each source.
- Review any report held back by the privacy check.
- The scraper needs Playwright's Chromium on the machine that runs it (it is
  already installed here).

## Not in this plan

- Department of Juvenile Justice group homes and detention centres (many of
  our Virginia records): not checked for public reports.
- DBHDS developmental disability services.
