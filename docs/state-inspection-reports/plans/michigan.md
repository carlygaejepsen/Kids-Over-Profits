# Michigan inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all three state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Michigan's child caring institution licensing reports at
`https://kidsoverprofits.org/mi-reports/`, fed by a new `mi_scraper.py`.
Michigan is the largest uncovered state in the facility database: 141 tracked
facilities, 135 open. The state posts special investigation reports (complaint
investigations with the allegation, the interviews and a finding per
allegation), which is the most useful report type any state gives us.

**Why it is urgent.** The state's own site says violation reports can be taken
down after two years at the licensee's request. What is scraped and archived is
kept; what is not may disappear.

## The source (verified 2026-09-30)

MDHHS Division of Child Welfare Licensing public search:
https://michildwelfarepubliclicensingsearch.michigan.gov/licagencysrch/

It is a Salesforce Experience Cloud site. One unauthenticated endpoint serves
everything. No cookies, token, CAPTCHA or bot wall.

```
POST https://michildwelfarepubliclicensingsearch.michigan.gov/licagencysrch/webruntime/api/apex/execute?language=en-US&asGuest=true&htmlEncode=false
Content-Type: application/json

{"namespace":"","classname":"@udd/01p8z0000009E4V","method":"<method>",
 "isContinuation":false,"params":{...},"cacheable":false}
```

| Method | Params | Returns |
|---|---|---|
| `getAgenciesDetail` | `{}` | `returnValue.objectData.responseResult`: all 270 licensed agencies in one response |
| `getContentDetails` | `{"recordId": "<agencyId>"}` | `returnValue.contentVersionRes`: that agency's documents |
| `getContentBaseData` | `{"contentDocumentId": "<id>", "actionName": "download"}` | `returnValue`: the PDF as one base64 string |

Agency fields: `agencyId`, `AgencyName`, `AgencyType`, `Address`, `City`,
`County`, `ZipCode`, `Phone`, `LicenseNumber`, `LicenseStatus`,
`LicenseEffectiveDate`, `LicenseExpirationDate`,
`LicenseeGroupOrganizationName`.

Document fields used: `Title`, `ContentDocumentId`, `CreatedDate` (ISO),
`FileExtension`.

### Three things that will trip you

1. **TLS.** The server does not send its intermediate certificate, so Python
   `requests` and `curl_cffi` both fail with `CERTIFICATE_VERIFY_FAILED`
   (Windows `curl.exe` works only because Schannel fetches it). Fix: keep the
   intermediate in the Tools repo and verify against `certifi` plus that file.
   The intermediate is "Sectigo Public Server Authentication CA OV R36", from
   http://crt.sectigo.com/SectigoPublicServerAuthenticationCAOVR36.crt (DER;
   convert to PEM). A converted copy is at
   `tmp/scraper-research/mi/sectigo_ov_r36.pem`. Build the bundle at start-up
   in a temp file; if verification still fails, re-read the AIA URL from the
   leaf certificate and say so in the error. Do not use `verify=False`.
2. **The class id can change.** `@udd/01p8z0000009E4V` is the id of the Apex
   class `COM_CWLicensingSearchController`. A wrong id returns HTTP 400 with
   `{"returnValue":"The Apex request is invalid."}`. On that response, GET the
   search page, find the script paths matching
   `webruntime/view/<hash>/prod/en-US/home_1_view` and `agency_Detail_Page_1_view`,
   fetch them, and take the first `@udd/01p[0-9A-Za-z]+` next to
   `COM_CWLicensingSearchController`. Retry once with it.
3. **Titles are free text.** Do not rely on them alone for the report type.
   A sample of 432 documents across 25 facilities:

   | Title pattern | Share | Meaning |
   |---|---|---|
   | `2024SIC0001170`, `2021C0114015SI`, `CI670414130_SIR_2023...` | about 65% | Special investigation report |
   | `..._RNWL_...`, "Renewal" | about 9% | Renewal inspection report |
   | `..._INSP_...`, "Interim" | about 8% | Interim inspection report |
   | `..._ORIG...`, "Original" | about 3% | Original licensing study |
   | `..._ADD_...`, "Amended", bare numbers, anything else | rest | Read the PDF |

   Classify from the PDF's own heading line ("SPECIAL INVESTIGATION REPORT",
   "LICENSING STUDY REPORT", and the cover letter's "Attached is the Renewal
   Inspection Report / Interim Inspection Report / Original Licensing Study
   Report"). Use the title only as a fallback.

## Scope

Take every agency whose `AgencyType` does not start with "Child Placing
Agency". On 2026-09-30 that was 110:

| AgencyType | Count |
|---|---|
| Child Caring Institution: Private | 76 |
| Court Operated Facilities | 17 |
| Child Caring Institution: Government Non-MDHHS | 9 |
| Child Caring Institution: Therapeutic Group Home | 6 |
| Child Caring Institution: MDHHS | 2 |

Child placing agencies (160, foster care and adoption) are out of scope. Make
the prefix filter a constant so it is easy to revisit.

Only currently licensed agencies are listed. A facility that closes drops off
the list, so never delete anything and keep the last known `agencyId` per
licence in the state file: its documents may stay reachable by id for a while.

Expected volume: about 17 documents per facility, so roughly 1,900 PDFs on the
first run, mostly 2020 to 2026 with a few back to 2004. Each PDF is 100 to
250 KB of real text (no OCR needed; `pdfplumber` reads them).

## What the reports look like

All four types open with a cover letter, then a form with numbered sections.
A sample is at `tmp/scraper-research/mi/mi_sir_sample.pdf`.

**Special investigation report**

- Cover letter: date, licence number, `SI #`, and one summary sentence:
  "No substantial violations were found." or a sentence requiring a corrective
  action plan.
- `I. IDENTIFYING INFORMATION`: Special Investigation #, Intake Date,
  License #, Licensee Group Organization, Licensee Designee, Chief
  Administrator, Agency Name, Agency Address, License Status, License
  Expiration Date, Capacity.
- `II. METHODOLOGY`: a table of contact date, method and purpose.
- `III. INVESTIGATION`: one block per allegation. It is a two-column table that
  `pdfplumber` flattens into label and text: `Rule Code & Section`, the rule
  text, `Violation Type`, `Allegation`, `Investigation`, `Analysis`,
  `Conclusion`. The conclusion seen so far is "Violation Established" or
  "Violation Not Established"; collect every distinct value across the corpus
  before fixing the list (expect a repeat-violation form).
- `IV. RECOMMENDATION`.

**Renewal, interim and original reports**: cover letter (says whether a
corrective action plan is required and gives the inspection date), identifying
information (adds Capacity and Program Type), methodology, a program
description, a rule/statutory violations section that either lists cited rules
or says "The agency was found in compliance.", and a recommendation.

Youth are anonymised as "Youth A", staff as "Staff 1".

## Decisions already made

- `state` = `MI`, page slug `mi-reports`, state file `.mi_state.json`
  (seen-ID pattern, keyed by `LicenseNumber`).
- `facility_name` = `AgencyName` as given, except that names in full capitals
  are title-cased (keep LLC, INC, roman numerals and initials intact).
- `program_name` = `LicenseNumber` (for example `CI670414130`).
- `program_category` = `AgencyType`. `action` = `LicenseStatus`.
  `license_exp_date` = `LicenseExpirationDate`. `full_address`, `phone` from
  the agency record. `executive_director` and `bed_capacity` from the newest
  report's identifying information (Chief Administrator, Capacity).
- `report_id` = `ContentDocumentId`. An amended report is its own document and
  its own report.
- `report_date` = the cover letter date, as ISO. Fall back to `CreatedDate`.
- `report_url` = the agency's public page,
  `https://michildwelfarepubliclicensingsearch.michigan.gov/licagencysrch/agency-detail-page?agency=<agencyId>`.
  There is no direct URL for a document (it only comes back as base64 from the
  POST), so the archived copy on our site is the link readers will use.
- PDFs go through `ReportStore("MI_PDF_CACHE", "mi_pdfs", <local fallback>)`,
  named `<LicenseNumber>_<ContentDocumentId>.pdf`. Put that file name in
  `categories.archive_name`; the adapter passes it to `ctx.archiveLink()`.
  Add `'mi_pdfs' => 'mi'` to `$FOLDERS` in `api/sync-inspection-archive.php`.
- `categories` per report, so the page never needs the text at list time:

  ```json
  {
    "doc_type": "special_investigation | renewal | interim | original | other",
    "title": "<Title from the site>",
    "si_number": "2024SIC0001170",
    "intake_date": "2024-02-20",
    "inspection_date": "2024-09-10",
    "licensee": "Youth Opportunity Investments, LLC",
    "cap_required": false,
    "outcome": "<the cover letter's summary sentence>",
    "allegations": [
      {"rule": "CCI Rule 400.4109", "rule_title": "Program statement",
       "allegation": "<first 600 characters>", "conclusion": "Violation Not Established"}
    ],
    "violations_established": 0,
    "cited_rules": ["400.4109"],
    "recommendation": "<section IV text>",
    "archive_name": "CI670414130_0698z0000086f4wAAA.pdf"
  }
  ```

- `summary` = a one-line gist: "Special investigation: 1 of 3 allegations
  established" or "Renewal inspection: corrective action plan required".
- `raw_content` = the full extracted text.
- A report is **flagged** when `violations_established > 0`, or, for the
  inspection types, when `cap_required` is true or rules are cited. A special
  investigation with nothing established is `clean`, and still shown: the
  allegation and the interviews are public record and readers want them.
- The page loads `?state=MI&lite=1` and opens text with `withText()`.

## Build steps

1. **Probe.** Re-run the three calls by hand (or
   `tmp/scraper-research/mi/mi_probe*.py`, which expect a `mi_bundle.pem` built
   as in "TLS" above) and confirm the counts still match this plan.
2. **Fetch layer.** Session with the CA bundle, the class-id recovery, retries
   with backoff on 5xx and timeouts, 0.5 s between calls.
3. **Download and extract** through `extract_with_cache`, so a rerun or a
   parser change never downloads a PDF twice. Guard the base64 decode: a
   response that does not start with `%PDF` is logged and skipped, not stored.
4. **Parser.** Write it against at least 60 cached PDFs spread over all four
   types and over years 2020 to 2026, then run it over everything and print:
   documents per `doc_type`, how many fell to `other`, every distinct
   `Conclusion` value, and how many special investigations produced zero
   allegation blocks. Anything above about 3% in `other` or with zero
   allegations means the parser is not finished. When a block cannot be
   parsed, keep the document with `doc_type` set and empty `allegations`; never
   drop it.
5. **Payload and state.** Seen-ID state advanced only after a successful post.
   `--out` writes the read-API shape.
6. **Match report.** Compare `facility_name` values with `facilities_v2` where
   `state = 'MI'` in `tmp/prod.sqlite`. An exact normalised match against the
   raw agency names gave 47 of 110 on 2026-09-30, so expect to list near
   misses (for example "EAGLE VILLAGE LEPPIEN", "Childrens Village-Residential").
   Report them; do not rename records to force matches.
7. **Adapter** `js/inspections/states/mi.js`. Closest models:
   `js/inspections/states/nc.js` for lite loading and `withText()`, and
   `js/inspections/states/or.js` for a PDF state with labelled sections.
   - Summary: licence number, agency type, licensee, capacity; stats for
     reports, investigations, violations established.
   - Report row: date, type, badges ("2 violations established", "Corrective
     action plan required", "No violations established"), the `outcome` line as
     preview, links to the state page and the archived copy.
   - Body on open: each allegation with its rule, the allegation text, and the
     conclusion; then the full text.
   - Filters: report type; agency type.
8. **Registration** per README step 4, then the owner's page creation.
9. **Owner sign-off, then post** per the README.
10. **Severe findings** as a follow-up commit: read only allegations whose
    conclusion is an established violation; the sentence source is the
    Allegation and Analysis text.

## Acceptance checks

- A full `--no-post --out` run covers all in-scope agencies and reports the
  counts in step 4.
- A second run with the state file finds nothing new and downloads nothing.
- Changing the class id to a wrong value in a test recovers by itself.
- The page, fed the `--out` file, lists the facilities, flags only reports
  with established violations or required corrective action, and opens a
  report's text. Screenshots at 390, 768 and 1440 px looked at.
- After the owner's go-ahead: the live API returns Michigan, the page renders,
  `check-bare-text.py` passes, the archive sync shows `mi` files.

## Left for the owner

- Create the `/mi-reports/` page (State Reports template).
- Approve the first production post.
- The Drive folder `mi_pdfs` reaches the site through the existing nightly
  `sync-inspection-archive.php` cron once the folder mapping is deployed.

## Not in this plan

- Corrective action plans: the state releases them only by records request.
- An optional extra source, the state's institution list with websites at
  https://mdhhs-pres-prod.michigan.gov/CCIMap/data/DataList.js (105 rows),
  could help name matching later.
