# Iowa inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Iowa's survey reports for Psychiatric Medical Institutions for
Children (PMICs) at `https://kidsoverprofits.org/ia-reports/`, fed by a new
`ia_scraper.py`. Iowa has 32 tracked facilities (18 open). The state posts
every survey visit to a PMIC since 2018, including complaint and incident
investigations, as the federal statement of deficiencies with the provider's
plan of correction. Closed institutions are still listed with their reports
(Sequel's Clarinda Academy among them).

A small, clean build: 46 institutions, 244 visits, 240 PDFs.

## The source (verified 2026-10-01)

Iowa Department of Inspections, Appeals and Licensing (DIAL), health
facilities database: https://dia-hfd.iowa.gov/

ASP.NET Core behind Cloudflare. Plain `requests` with a session works; no
login, CAPTCHA or challenge. Every POST needs the anti-forgery token from the
page before it.

### 1. Search

```
GET  https://dia-hfd.iowa.gov/
     -> cookie + hidden __RequestVerificationToken in the form "entitysearch"

POST https://dia-hfd.iowa.gov/Home/EntityPublicAdvancedSearch
     TypeVals=11, StateVals=0, __RequestVerificationToken=<token>
     -> the result page, which holds the same form again with the search filled in
```

`TypeVals=11` is "Psychiatric Medical Institutions for Children". An empty
`StateVals` returns 404.

### 2. The list (JSON)

```
POST https://dia-hfd.iowa.gov/Home/EntitySearchAjax
```

**Send every field of the result page's `entitysearch` form**, plus the
DataTables fields `draw`, `start`, `length`, `search[value]`,
`search[regex]`. A hand-picked subset of fields returns 404; that cost time
in research. The working field set on 2026-10-01 was: `tableSearchField`,
`PublicEntitySearch.Name`, `.City`, `.AddressLine1`, `.Zip`, `.Email`,
`.DayPhone`, `.MonthsSinceLastSurvey`, `.AdminLicense`, `.PreviousName`,
`.SpecialProgramFlag=false`, `.LossOfNATraining=false`,
`.NoQuarterlyReport=false`, `.TypeVals=11`, `boguscounties`,
`bogusentitytypes`, `bogusdegtypes`, `bogusstates`, `bogustatus`,
`bogustags`, and the token. Read them from the form each run, do not
hard-code them.

Add `PublicEntitySearch.StatusVals=153` for active (21 rows) and `155` for
closed (25 rows). Run both; the default is active only.

Row fields include `id`, `name`, `typeName`, `addressLine1`, `city`,
`county`, `zip`, `capacityCount`, `status`, `dayPhone`,
`classificationNumber`, `fedNumber`, `currentLicenseDate`,
`initialLicenseDate`, `expirationDate`, `dateDeleted`.

### 3. A facility's visits (JSON)

```
POST https://dia-hfd.iowa.gov/home/VisitListAjax?id=<entity id>
     DataTables fields + the token
```

Row fields: `id` (visit id), `visitDate`, `visitDateString`, `visitType`,
`scannedReport` (file name), `scannedCitation`, `isRevisit`, `parentId`,
`violationsState`, `violationsFed`, `certificationActions`,
`licensureActions`, `hasCompliance`, `fineNumber`.

Visit types across all 244 visits: Recertification 180, Complaint 34,
Initial 20, Initial Revisit 4, Incident 3, and one each of "Recertification,
Complaint" and "License Revisit". By year: 2018 (7), then 27 to 31 a year,
2025 (46), 2026 (20 so far).

The tags cited at a visit are also available as JSON:
`GET https://dia-hfd.iowa.gov/home/GetRuleCodesForVisit?visitId=<visit id>`
(reported by the research agent, not re-tested).

### 4. A report (plain GET)

```
GET https://dia-hfd.iowa.gov/Home/ViewReport?fileName=<scannedReport>
```

Example:
https://dia-hfd.iowa.gov/Home/ViewReport?fileName=ScannedReport_1118_2026-08-05_082331.pdf

Facility page for readers:
`https://dia-hfd.iowa.gov/Home/PublicEntityDetails?recordid=<entity id>`

Samples and the working probe are in `tmp/scraper-research/ia/`
(`ia_probe.py`, `report-1118-2026-02-03.pdf`).

## What a report looks like

A text PDF on the federal CMS-2567 form (the one read was 12 pages). "Initial
Comments" says what the survey was and names any complaint investigation by
number. Then one block per tag: the tag (for example `N 142`), the regulation,
"This STANDARD is not met as evidenced by", and findings by resident number.
The provider's plan of correction and completion date sit in a right-hand
column.

The form is a two-column layout: findings on the left, plan of correction on
the right. Follow the README's rule on PDF tables; check on samples whether
`extract_tables()` or a crop at the column boundary gives clean findings text.
A report with no deficiencies is a single page saying so.

## Decisions already made

- `state` = `IA`, page slug `ia-reports`, state file `.ia_state.json`
  (seen-ID pattern keyed by entity id).
- Scope: entity type 11 only, active and closed. The other children's
  residential types (comprehensive residential, shelter, detention) are
  licensed by Iowa HHS and have no published reports.
- `facility_name` = `name` (for example "Boys & Girls Home-Brick Unit 2");
  `program_name` = `IA-<entity id>`; `program_category` = "Psychiatric
  Medical Institution for Children"; `bed_capacity` = `capacityCount`;
  `action` = `status` (with the closed date when `dateDeleted` is set);
  `license_exp_date` = `expirationDate`; address and phone from the row.
- `report_id` = the visit `id`. `report_date` = `visitDate` as ISO.
  `report_url` = the `ViewReport` URL, or the facility page when the visit
  has no PDF (4 of 244).
- PDFs go through `ReportStore("IA_PDF_CACHE", "ia_pdfs", <local fallback>)`
  under the state's file name; `'ia_pdfs' => 'ia'` in
  `api/sync-inspection-archive.php`; `archiveState: 'IA'`.
- `categories` per report:

  ```json
  {
    "visit_type": "Recertification, Complaint",
    "is_complaint": true,
    "is_revisit": false,
    "violations_fed": 7,
    "violations_state": 0,
    "complaint_numbers": ["131336-C"],
    "tags": [
      {"tag": "N 142", "regulation": "483.358(c) ORDERS FOR USE OF RESTRAINT OR SECLUSION",
       "finding": "<first 800 characters>", "plan": "<first 400 characters>", "completion_date": "2026-02-16"}
    ],
    "tag_count": 7,
    "enforcement": "<certificationActions / licensureActions / fineNumber when set>"
  }
  ```

- **Flagged** = `violations_fed + violations_state > 0` (the state's own
  counts, which do not depend on the PDF parse). `clean` when both are zero
  and a report exists.
- `summary` = "Complaint survey: 7 federal deficiencies (N 142, N 161, ...)"
  or "Recertification survey: no deficiencies".
- `raw_content` = the findings text. Lite loading (`?state=IA&lite=1`).
- Federal surveys name residents only by number. The README's privacy check
  still runs.

## Build steps

1. **Probe**: run `tmp/scraper-research/ia/ia_probe.py`; expect 21 active,
   25 closed, 244 visits.
2. **Listing and visits** (48 requests in all).
3. **Download and extract** through `extract_with_cache`.
4. **Parser**, against at least 40 PDFs across years and visit types. Print:
   reports per visit type, tags parsed versus the state's violation counts
   for the same visit (they should agree; list every disagreement), and
   reports where the two columns could not be separated.
5. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=IA --file=<out.json>`.
   Units of one campus are separate entities here ("Boys & Girls Home-Brick
   Unit 2"); report how they line up with our records.
6. **Adapter** `js/inspections/states/ia.js`. Closest model:
   `js/inspections/states/ar.js` (federal surveys) and
   `js/inspections/states/pa.js` (citation with plan of correction).
   Filters: visit type (complaint and incident on their own), status (open
   or closed institution).
7. **Registration** per README step 4, page via `kop_tool_page_specs()`.
8. **Owner sign-off, then post** per the README.
9. **Severe findings** as a follow-up: Arkansas already has a federal-survey
   extractor (`kop_ih_extract()` case `AR`); reuse its reading of 2567 tags.

## Acceptance checks

- A full `--no-post --out` run: 46 institutions, 244 reports, flagged count
  equal to the number of visits with a non-zero violation count.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, separates complaint surveys and shows closed
  institutions as closed. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post.

## Not in this plan

- Iowa HHS's list of licensed residential, shelter and detention facilities
  (a 23-page PDF, https://hhs.iowa.gov/media/9472/download?inline) has no
  reports; it could be used later to check our Iowa records.
