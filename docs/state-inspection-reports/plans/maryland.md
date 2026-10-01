# Maryland inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Maryland's residential child care inspection summaries at
`https://kidsoverprofits.org/md-reports/`, fed by a new `md_scraper.py`.
Maryland has 29 tracked facilities (16 open). The Department of Human Services
posts a short summary of each licensing inspection of a group home or other
residential child care program.

A small build: 32 provider folders, 174 PDFs. Be clear with readers about
what these are: one- or two-page summaries that list each regulation cited
with a one-line comment. They are not full inspection narratives.

## The source (verified 2026-10-01)

Maryland Department of Human Services, Office of Licensing and Monitoring:
https://dhs.maryland.gov/licensing-and-monitoring/

The reports sit in a public file browser. Plain `requests` works; no login,
CAPTCHA or bot wall.

```
GET https://dhs.maryland.gov/documents/?dir=Licensing-and-Monitoring/Reports
    -> 75 provider folders (links of the form ?dir=Licensing-and-Monitoring/Reports/<Provider>)

GET https://dhs.maryland.gov/documents/?dir=Licensing-and-Monitoring/Reports/<Provider>
    -> program-type subfolders: RCC, TFC, Adoption, ILP, CPA

GET https://dhs.maryland.gov/documents/?dir=Licensing-and-Monitoring/Reports/<Provider>/RCC
    -> the PDFs
```

Use the `?dir=` form. The bare path `/documents/Licensing-and-Monitoring/Reports/`
returns 403. A PDF itself is a plain GET, for example:
https://dhs.maryland.gov/documents/Licensing-and-Monitoring/Reports/Woodbourne%20Center%2C%20Inc/RCC/RCC-GH-Nexus-Woodbourne-May-2025.pdf

On 2026-10-01: 32 providers had an `RCC` (residential child care) folder, 21
of them with files, 174 PDFs in all, dated 2019 to 2025 (2019 is the heaviest
year). Take the file links from the listing page as given; in research, one
attempt to pick out `.pdf` links by a simple pattern found none on some
pages, so read how the listing marks files (the saved pages are in
`tmp/scraper-research/md/`, with `all-report-files.json` listing every file
the research agent found).

Folder names carry the legal entity with odd escapes ("Children_s Home, Inc,
The", "Southern Maryland Youth Home, Inc_2E"). File names are inconsistent;
never take the date or site from the file name.

Providers that have left the state's current directory still have their
folders, so old reports stay up. There is no status field.

Current provider directory, for addresses and licence types:
https://dhs.maryland.gov/licensing-and-monitoring/provider-directories/

## What a report looks like

"Residential Child Care Report Summary", a text PDF of 2 to 3 pages
(`tmp/scraper-research/md/report-woodbourne-2025-05.pdf`):

- Provider organization, program administrator.
- A site table: name and address, licence number, total capacity, DHS and DJS
  census, licence expiry date, date of inspection.
- Contracting agency, licensing agency, licence type (for example Group Home).
- Type of inspection: Quarterly, Re-licensure, Mid-license or Periodic.
- Current status of licence.
- Two citation blocks, each listing site, COMAR citation number, a one-line
  comment and a status (Resolved, or CAP for corrective action plan):
  1. violations that "MAY present safety risks for children";
  2. violations that "DO NOT present imminent safety risks".
  Either can read "No COMAR violations."
- Licensing staff names, roles and dates.

The layout is a form with labels in a left column, and `extract_text()`
mixes the label text into the citations (see the sample). Use
`extract_tables()` or crop the label column away; check on samples. One
report can cover several sites of one provider.

## Decisions already made

- `state` = `MD`, page slug `md-reports`, state file `.md_state.json`
  (seen-ID pattern keyed by provider folder name).
- Scope: `RCC` folders only. Treatment foster care, independent living,
  adoption and child placement are out of scope.
- **One facility row per site** where the report's site table names sites;
  a report covering three sites becomes three reports, one per site, each
  carrying only that site's citations (citations name their site). If the
  site cannot be told apart reliably after sampling, fall back to one
  facility per provider and say so in the run report.
- `facility_name` = the site name from the table (for example "Nexus
  Woodbourne"), falling back to the provider organization; `program_name` =
  the site's licence number (`#2560`) or, without one, `MD-<slug of provider
  and site>`; `program_category` = licence type; `bed_capacity` = total
  capacity; `executive_director` = program administrator;
  `license_exp_date`; `action` = current status of licence. The provider
  organization goes into `categories.provider` on every report and into the
  adapter's `searchText`.
- `report_id` = a short hash of the PDF's URL path plus the site (file names
  are not stable enough to read, but they are unique). `report_date` = the
  site's date of inspection, as ISO; these are written like `5.21.25`.
- `report_url` = the PDF URL.
- PDFs go through `ReportStore("MD_PDF_CACHE", "md_pdfs", <local fallback>)`;
  `'md_pdfs' => 'md'` in `api/sync-inspection-archive.php`;
  `archiveState: 'MD'`, with `ctx.archiveLink(report_url)`.
- `categories` per report: `provider`, `inspection_type`, `license_type`,
  `census {dhs, djs, other}`, `safety_citations[] {citation, comment,
  status}`, `other_citations[] {citation, comment, status}`,
  `safety_count`, `other_count`, `cap_count`.
- **Flagged** = any citation in either block. Badge the first block
  separately ("2 citations that may present safety risks"), since that is
  the state's own severity line.
- `raw_content` = the citations written out plainly. The data is small; no
  lite loading.
- The email addresses of the program administrator and licensing staff are
  in every report. Keep them out of `categories`, `summary` and
  `raw_content`; the archived PDF is the state's own document and stays as
  published.

## Build steps

1. **Listing**: the three levels above, about 110 requests.
2. **Download and extract** through `extract_with_cache`.
3. **Parser**, against at least 40 PDFs including 2019 ones (the form was
   revised in 10/2021; expect an older layout). Print: reports per
   inspection type, reports with several sites, citations per block, and
   reports where no site table was found.
4. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=MD --file=<out.json>`.
5. **Adapter** `js/inspections/states/md.js`. Closest model:
   `js/inspections/states/ok.js` (short structured items).
   A note on the page: these are the state's summaries, not full reports;
   the state's psychiatric residential treatment centres (health department)
   and juvenile services facilities publish nothing per facility.
6. **Registration** per README step 4, page via `kop_tool_page_specs()`.
7. **Owner sign-off, then post** per the README.
8. **Severe findings**: probably not worth an extractor. The comments are
   one line each. Decide after reading the first-block citations in the
   parsed data, and say what was decided in `docs/PLAN.md`.

## Acceptance checks

- A full `--no-post --out` run covers all 174 PDFs and reports the parser
  numbers; no email address appears anywhere in the payload.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, shows the two citation blocks apart.
  Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post.

## Not in this plan

- Maryland Department of Health residential treatment centres: only a list of
  six, https://health.maryland.gov/ohcq/docs/Provider-Listings/Excel/Residential-Treatment-Centers-EXCEL.xlsx.
- Department of Juvenile Services facilities: the independent juvenile
  justice monitor's quarterly reports are narrative PDFs, better suited to
  the media library than to this pipeline.
