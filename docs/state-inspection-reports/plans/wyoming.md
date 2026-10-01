# Wyoming inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Wyoming's findings against youth residential providers at
`https://kidsoverprofits.org/wy-reports/`, fed by a new `wy_scraper.py`.
Wyoming has 37 tracked facilities (30 open). The Department of Family
Services posts every notice of non-compliance and every facility visit for
all 24 certified residential treatment centres, group homes, crisis centres,
the juvenile detention centre and the BOCES residential schools. A notice of
non-compliance is the outcome of an allegation investigation: what was
alleged, the finding, and the rules violated.

A second, small source adds the health department's federal surveys of
psychiatric residential treatment facilities.

About 420 documents in all. The catch: the Family Services PDFs are scans.

## Source 1: Department of Family Services (verified 2026-10-01)

One page holds everything (plain GET, no login or bot wall):
https://dfs.wyo.gov/providers/substitute-care/notice-of-non-compliance-findings-and-facility-visits/

The page is a set of accordions, one per facility: each opens with an element
carrying `main-text="<facility name>"` (HTML-escaped; one name carries a note,
"Trinity Teen Solutions (Currently not accepting placements as of
09/28/2022)"), followed by links to Google Drive. On 2026-10-01: 24
facilities, 400 distinct Drive file links and 14 Drive folder links ("All
documents"). The research agent's parse of the page is saved as
`tmp/scraper-research/wy/findings-index-parsed.json`.

Link labels carry the date and kind, in free text: "February 26, 2026 Site
Visit", "Cathedral Home SCL-305, Notice of Non-Compliance (06-18-2025)
08-13-2025". By the labels: about 85 notices of non-compliance, about 304
site visits, and a few corrective action plan responses and
recertifications, dated August 2022 to 2026.

Downloads:

- File: `https://drive.google.com/uc?export=download&id=<fileId>` works with
  plain `requests`. Check the response starts with `%PDF`; Drive answers
  with an HTML page when a file is too large to scan for viruses or the
  download quota is hit.
- Folder listing without login:
  `https://drive.google.com/embeddedfolderview?id=<folderId>#list`. The one
  folder listed in research held a visit that the page did not link, so list
  every folder and take files the page lacks.

Only current providers are on the page. A provider that loses certification
is removed with its links (Normative Services is gone). Everything found
must be archived at once and never deleted on our side; keep each facility's
Drive ids in the state file.

### The documents

All eight PDFs opened in research were scanned images with no text layer.

- **Notice of Non-Compliance (form SCL-305)**, typed, about four pages:
  facility and mailing address, the date the allegation was received, the
  allegation in a sentence ("It was reported that youth had access to THC in
  the facility"), date of allegation, the finding ("Evidence supports
  findings of non-compliance"), and the rules violated with chapter, section
  and rule text, then an explanation. **OCR reads these well**: tested with
  `pytesseract` at 250 dpi on
  `tmp/scraper-research/wy/report-meadowlark-notice-of-non-compliance.pdf`.
- **Facility Visit (form SCL-300)**, one page, **handwritten**: facility
  type checkboxes, reason (Unannounced Visit, Complaint Investigation,
  Compliance Monitoring, Technical Assistance, Change Request), rule
  violations, observations, compliance due date. **OCR does not read
  these**: the same test on the site-visit sample returned noise. Do not
  post OCR text for them.

## Source 2: Department of Health (reported by research, not re-tested)

Healthcare Licensing and Surveys public search:
https://ohlssurvey.health.wyo.gov/PublicSearch, a Vue app over a JSON API
with no login.

- Facilities: `POST https://ohlssurvey.health.wyo.gov/api/FacilitySearch/Search`
  with body `{"pageNum":0,"pageSize":2000,"sortBy":[],"groupBy":[],"searchText":"","searchType":"basic","additionalParams":{},"explicitFilters":[],"includeFields":[]}`
  (`sortBy` must be a list). 385 facilities with `FacilityTypeId` and
  `OpenClosed`.
- Surveys: `POST https://ohlssurvey.health.wyo.gov/api/FacilitySurveySearch/Search`,
  same body plus `"facilityId":[<ids>]` and
  `"sortBy":[{"key":"SurveyDate","order":"desc"}]`.
- Document: `GET https://ohlssurvey.health.wyo.gov/api/Survey/Download/<SurveyId>`,
  a text PDF on the federal CMS-2567 form with the plan of correction.

Type 16 (psychiatric residential treatment facility) has six facilities, two
open (Wyoming Behavioral Health, St. Joseph's Children's Home) and four
closed (Normative Services, YES House, Cornerstone Southeastern Wyoming
Juvenile Center, Pathfinders), with seven surveys in all, 2019 to 2026, three
of them complaint surveys. Type 17 is psychiatric hospitals (Wyoming
Behavioral Institute is id 226); its surveys were not pulled. Re-test these
calls first; samples are in `tmp/scraper-research/wy/wdh-*`.

## Decisions already made

- `state` = `WY`, page slug `wy-reports`, one adapter, `--source dfs|wdh|all`,
  state files `.wy_dfs_state.json` and `.wy_wdh_state.json`.
- **Family Services facility**: `facility_name` = the accordion name with
  any trailing parenthesis note removed (the note goes to `action`) and
  double hyphens tidied ("Fremont County Group Home - Boys (Riverton)");
  `program_name` = `DFS-<slug of the name>`, fixed in the state file once
  assigned; `program_category` from the notice's facility type or the page's
  grouping (residential treatment, group home, crisis centre, detention,
  BOCES).
- **Family Services report** = one document. `report_id` = the Drive file
  id. `report_url` = `https://drive.google.com/file/d/<id>/view`.
  `report_date`: for a notice, the date of the notice from the OCR text,
  else the last date in the link label; for a visit, the date in the label.
  The label is free text; a label with no readable date is posted with the
  date left empty and listed in the run report.
- `categories` for a notice: `kind: "notice"`, `allegation`,
  `allegation_date`, `finding`, `rules[] {chapter, section, title}`,
  `label`, `ocr: true`, `archive_name`. `raw_content` = the OCR text.
- `categories` for a visit: `kind: "visit"`, `label`, `ocr: false`,
  `archive_name`. `raw_content` empty. `summary` = "Facility visit
  (handwritten form; open the document to read it)". If the checkbox for
  "Complaint Investigation" can be read reliably by image position after
  sampling 30 visits, add `reason`; otherwise leave it out. Do not guess.
- Other kinds (corrective action plan response, recertification): `kind:
  "other"` with the label as summary, text by OCR if typed.
- **Health department facility**: `program_name` = `WDH-<facility id>`,
  `program_category` = "Psychiatric residential treatment facility";
  report = one survey, `report_id` = `wdh-<SurveyId>`, parsed as a 2567
  (see `iowa.md` and the Arkansas adapter).
- **Flagged** = a notice whose finding supports non-compliance, or a health
  survey with tags. Visits are `neutral`: the page cannot tell whether a
  handwritten visit found violations, and must not show them as clean.
- PDFs go through `ReportStore("WY_PDF_CACHE", "wy_pdfs", <local fallback>)`
  named `<drive id>.pdf` or `wdh-<SurveyId>.pdf`; `'wy_pdfs' => 'wy'` in
  `api/sync-inspection-archive.php`; `archiveState: 'WY'`. The archived copy
  matters here more than anywhere: the state removes a provider's documents
  when it leaves the list.
- **Privacy.** Notices describe incidents involving individual youth. They
  appeared to use no names in the sample, but OCR text is checked by the
  README's rule before posting, and handwritten visits are never
  transcribed.
- Lite loading is not needed (the text is a few hundred KB).

## Build steps

1. **Page parse**: facilities, labels, file and folder ids. Compare with
   `findings-index-parsed.json`.
2. **Folder listings** for the 14 folders; merge files the page lacks.
3. **Download** through `extract_with_cache`, with the `%PDF` check and a
   pause of two seconds between Drive downloads.
4. **Classify** each document by its label first, then by OCR of the first
   page's form number (SCL-305 or SCL-300).
5. **OCR and parse the notices** (`pdf2image` + `pytesseract`, as
   `nc_scraper.py` does). Print: notices parsed, notices where the
   allegation or the finding could not be found, every distinct finding
   sentence, and labels with no date.
6. **Health department source**: list, surveys, PDFs, 2567 parse.
7. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=WY --file=<out.json>`.
8. **Adapter** `js/inspections/states/wy.js`. Closest models:
   `js/inspections/states/fl.js` (two sources) and
   `js/inspections/states/or.js` (documents with labelled fields).
   Filters: kind (notices, visits, health surveys). Default sort by most
   recent. A note on the page: visits are handwritten forms shown as
   documents only.
9. **Registration** per README step 4, page via `kop_tool_page_specs()`.
10. **Owner sign-off, then post** per the README.
11. **Schedule**: monthly at least, since documents vanish with their
    provider. Add to `scraper_launcher.py`; the owner runs it.
12. **Severe findings** as a follow-up: notices only (allegation plus a
    finding that supports non-compliance is substantiated).

## Acceptance checks

- A full `--no-post --out` run: 24 Family Services facilities and about 410
  documents, each classified; every notice has an allegation and a finding
  or is listed as unparsed.
- No OCR text is posted for a handwritten visit.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, flags notices only and shows visits as
  documents. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post.
- Run the scraper monthly.
