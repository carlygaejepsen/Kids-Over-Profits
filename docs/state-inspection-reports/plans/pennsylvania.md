# Pennsylvania inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all three state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Pennsylvania's child residential facility inspection summaries at
`https://kidsoverprofits.org/pa-reports/`, fed by a new `pa_scraper.py`.
Pennsylvania has 91 tracked facilities (78 open) and the deepest public history
of any state looked at: inspection summaries from 2010 onward, each with the
regulation cited, the description of the violation and the provider's plan of
correction.

## The source (verified 2026-09-30)

PA Department of Human Services, Human Services Provider Directory:
https://www.humanservices.dhs.pa.gov/HUMAN_SERVICE_PROVIDER_DIRECTORY/

ASP.NET MVC. Plain `requests` works; no CAPTCHA, WAF or login.

### 1. List the facilities (one token-protected POST per service code)

```
GET  https://www.humanservices.dhs.pa.gov/HUMAN_SERVICE_PROVIDER_DIRECTORY/
     -> session cookie + hidden input __RequestVerificationToken

POST https://www.humanservices.dhs.pa.gov/HUMAN_SERVICE_PROVIDER_DIRECTORY/Home/HumanServicesProviderDirectorySearchResult
     form fields: __RequestVerificationToken, ReturnSearchScreen="", ProgramOffice="",
                  ServiceCode=<code>, ServiceCodeSub="", Region="", FacilityName="",
                  City="", County="", ZipCode="", LicenseStatusType=""
```

Fetch a fresh token before each search. The response is one unpaginated HTML
table with seven columns:

| Column | Holds |
|---|---|
| Service Type | for example RESIDENTIAL SERVICES |
| Program Office | Office of Children & Youth-Private (or County) ... |
| Name and Address | lines separated by `<br>`: unit name, legal entity, street, city, state, zip, `Phone:`, `County:`, `Region:` |
| Capacity | number |
| Type Of Operation | PROFIT / NON-PROFIT |
| License Status & License # | status, `[448090]`, and links: Inspection Summary, Certificate, Waiver |
| Type of License & dates | for example `FULL 2/24/2026 - 2/24/2027` |

Service codes in scope, with row counts on 2026-09-30:

| Code | Service | Rows |
|---|---|---|
| 36 | Residential Services (55 Pa. Code 3800) | 501 |
| 41 | Transitional Living | 64 |
| 40 | Secure Detention | 17 |
| 39 | Secure Care | 9 |
| 42 | Outdoor Program | 3 |
| 43 | Mobile Program | 1 |

Out of scope: 52 Private Children and Youth Agencies (190, foster and adoption
agencies), 44 Supervised Independent Living, 45 Day Treatment, 64 host homes,
and the adult and disability codes. Keep the list of codes a constant with a
`--service-codes` override.

**Only currently licensed rows are returned.** A search with no status gives
LICENSED and PDR-APPEAL PEND rows; searching by CLOSED, REVOKED, EXPIRED or
NON-RENEWAL returned nothing for code 36. So a facility that closes vanishes
from the list. Two consequences:

- Keep every licence number ever seen in the state file and keep asking for
  its report list (step 2 works by id with no search), so reports for closed
  units are still picked up.
- Never delete rows on our side when a unit disappears; set `action` to the
  last status seen.

### 2. A unit's report list (plain GET, no cookie)

```
GET https://www.humanservices.dhs.pa.gov/HUMAN_SERVICE_PROVIDER_DIRECTORY/Home/AzureInspVioltnReprtSearchResults?id=448090&facilityName=x
```

`id` is the bracketed number from the results table. `facilityName` is only
echoed into the page heading; any value works. The page lists file dates and
"View Report" links, newest first.

### 3. A report (plain GET)

```
GET https://www.humanservices.dhs.pa.gov/HUMAN_SERVICE_PROVIDER_DIRECTORY/Home/GetAzureFile?directory=inspectionsummary&filename=20250328_44809.pdf
```

File name = `YYYYMMDD_<licence>.pdf`. Note the licence in the file name is the
five-digit form (`44809`), while the table id has one more digit (`448090`;
the last digit was 0 for 483 rows, 1 or 2 for the rest). Take file names from
the links on the list page, never build them. A missing file returns 404 with
an empty body.

Volume: 12 sampled units had 153 reports, from 2010 to 2026, so expect about
7,600 PDFs of 100 to 150 KB on the first run (roughly 1 GB). At one request a
second that is a little over two hours.

## What a report looks like

"Licensing Inspection Summary - Public", a text PDF (`pdfplumber` reads it).
Samples are in `tmp/scraper-research/pa/`.

Page 1 is a letter: the date, the legal entity and address, `RE:` the unit and
its address, `LICENSE/COC#:`, and one sentence that tells you the kind of
document. Three kinds seen so far:

| Letter sentence | Kind |
|---|---|
| "...no regulatory citations have been identified as a result of this inspection." | clean inspection |
| citations identified, plan of correction required (wording to be collected) | citation |
| "...we have determined that your submitted plan of correction is fully implemented." | follow-up on an earlier citation; it repeats the citations with the accepted plan |

The following pages hold the citations. Each one reads:

```
3800.31 Notification of Rights and Grievance Procedures
1. Requirements
3800.31.d. <regulation text>
Description of Violation
<what the inspector found>
Plan of Correction Accept ( - 03/28/2025)
<the provider's plan>
Licensee's Proposed Overall Completion Date: 03/28/2025
On-site Verification Implemented ( - 03/28/2025)
<the verification note>
```

Every page ends with a footer line `MM/DD/YYYY www.dhs.pa.gov N of M`; strip it
before parsing, since citations run across pages.

**Redaction.** The state blanks dates and names inside the letter and the
narratives, which leaves gaps such as "inspections on  of the above facility".
That is the source, not an extraction fault. Do not try to fill them.

Only one citation document has been read closely. Formats from 2010 to 2016
may differ (the summary was introduced in stages). Sample before you parse.

## Decisions already made

- `state` = `PA`, page slug `pa-reports`, state file `.pa_state.json`
  (seen-ID pattern keyed by the bracketed licence id, plus a `licences` map of
  every id ever seen with its last name, entity and status).
- **One facility row per licensed unit**, not per legal entity. The 501
  residential rows belong to 178 legal entities (George Junior Republic has
  37 cottages, KidsPeace 18). The state inspects and cites each unit
  separately, so merging them would lose which building a finding belongs to.
- `facility_name`: the unit name and the legal entity are both needed, because
  many unit names are codes ("1", "1C", "39 PINEBROOK DRIVE", "BENTON
  COTTAGE"). Rule: if the unit name already contains the entity's
  distinguishing words, or equals the entity name, use it alone; otherwise
  `"<Legal Entity>: <Unit>"`. Title-case the state's capitals (keep INC, LLC,
  roman numerals and initials). An alphabetical list then groups an entity's
  units together.
- `program_name` = the bracketed licence id (`448090`).
- `program_category` = service type (title-cased). `bed_capacity` = Capacity.
  `action` = licence status. `license_exp_date` = the end date in the last
  column. `full_address` and `phone` from the name cell.
  `executive_director` stays empty (the state redacts it).
- `report_id` = the file name without `.pdf` (`20250328_44809`).
- `report_date` = the date in the file name, as ISO. This is the inspection or
  review date printed in the page footer, not the letter date.
- `report_url` = the full `GetAzureFile` URL (it works as a plain link).
- PDFs go through `ReportStore("PA_PDF_CACHE", "pa_pdfs", <local fallback>)`
  under their own file names, and `'pa_pdfs' => 'pa'` is added to `$FOLDERS`
  in `api/sync-inspection-archive.php`; the adapter sets `archiveState: 'PA'`
  and calls `ctx.archiveLink(report_url)` (the key is the file name).
- `categories` per report:

  ```json
  {
    "kind": "clean | citation | followup | other",
    "legal_entity": "THE SUMMIT SCHOOL, INC.",
    "unit": "1",
    "chapter": "55 PA.CODE CHAPTER 3800",
    "letter_date": "2025-04-04",
    "citations": [
      {"regulation": "3800.31.d", "title": "Notification of Rights and Grievance Procedures",
       "regulation_text": "...", "violation": "...",
       "plan": "...", "plan_status": "Accept", "plan_date": "2025-03-28",
       "verification": "...", "verification_status": "Implemented"}
    ],
    "citation_count": 1
  }
  ```

- **Flagged** = `kind` is `citation`. A `followup` document repeats citations
  already counted, so it is `neutral` with a badge such as "Plan of correction
  verified" and does not add to the facility's violation count. If the corpus
  shows follow-ups whose original citation document is not in the list, say so
  in the run report and count those follow-ups as flagged instead; do not
  guess silently.
- `summary` = "3 citations: 3800.31.d, 3800.143.a, ..." or "No citations".
- `raw_content` = the extracted text with page footers removed.
- The page loads `?state=PA&lite=1` and opens text with `withText()`.

## Build steps

1. **Probe.** `tmp/scraper-research/pa/pa_probe.py` runs the search for each
   code and prints counts; confirm they are close to the table above.
2. **Listing.** One search per service code with a fresh token, parsed into
   unit records. Merge with the `licences` map from the state file so units no
   longer listed are still visited.
3. **Report lists.** One GET per unit; collect file names; skip seen ones.
4. **Download and extract** through `extract_with_cache`. Support
   `--since YYYY` so the first production run can be staged (newest years
   first) instead of two hours in one go, and make the run resumable: the
   extract cache already makes a restart cheap.
5. **Parser.** Before writing it, pull at least 80 cached PDFs spread across
   2010 to 2026 and across service codes, and write down every distinct letter
   sentence and every citation layout found. Then parse all and print:
   documents per `kind`, the share in `other`, citation count distribution,
   and documents with pages beyond the letter but zero parsed citations. Above
   about 3% in either bucket means the parser is not finished. An unparsed
   document is kept with `kind: "other"` and its full text.
6. **Payload and state.** `--out` writes the read-API shape. Posting about
   7,600 reports is a large write; `inspection_api_client` batches it, but
   post by service code or year so a failure does not lose a whole run, and
   advance the state after each successful batch.
7. **Match report.** Compare names with `facilities_v2` where `state = 'PA'`
   in `tmp/prod.sqlite` (91 rows; names there look like "Adelphoi Benet Home",
   "Abraxas I", "The Bradley Center PRTF"). Try the unit name, the entity name
   and the combined name, and report which rule matches most. If the combined
   `facility_name` rule above matches clearly worse than another, raise it
   before posting rather than changing the rule quietly.
8. **Adapter** `js/inspections/states/pa.js`. Closest models:
   `js/inspections/states/nc.js` (lite loading, citations read per report) and
   `js/inspections/states/ga.js` (statements of deficiency).
   - Summary: legal entity, service type, capacity, licence status; stats for
     reports and citations.
   - Report row: date, kind, badge with the citation count, the first
     violation description as preview, links to the state PDF and the archived
     copy.
   - Body on open: each citation as regulation, violation, plan of correction
     and verification; then the full text.
   - Filters: service type; region if it is kept in `categories`.
   - `searchText` should include the legal entity so a search for "KidsPeace"
     finds every unit.
   - A note on the page explaining that the state blanks names and dates.
9. **Registration** per README step 4, then the owner's page creation.
10. **Owner sign-off, then post** per the README.
11. **Severe findings** as a follow-up commit: the sentence source is each
    citation's "Description of Violation"; a citation is by definition
    substantiated. Follow-up documents are skipped so a finding is not queued
    twice.

## Open question to settle during the build

Whether children's psychiatric residential treatment facilities appear under
code 36. The only OMHSAS residential treatment code in the list is for adults
(14), so they are assumed to be licensed under chapter 3800 and listed with
code 36. Check by looking for three known ones from `facilities_v2` (for
example The Bradley Center, Sarah Reed Children's Center, a Devereux unit). If
they are absent, search the OMHSAS program office rows and report what is
found before widening the scope.

## Acceptance checks

- A `--no-post --out --limit 40` run produces units from at least three
  service codes with parsed citations.
- A full `--no-post` run reports the parser numbers in step 5.
- A second run downloads nothing and posts nothing.
- A unit removed from the listing in a test is still visited through the
  state file.
- The page, fed the `--out` file, groups an entity's units together, flags
  only citation documents, and opens a report. Screenshots at 390, 768 and
  1440 px looked at.
- After the owner's go-ahead: the live API returns Pennsylvania, the lite
  response is a few MB at most, the page renders, `check-bare-text.py` passes.

## Left for the owner

- Create the `/pa-reports/` page (State Reports template).
- Approve the first production post, and say whether to post everything or
  start with recent years.
- Confirm Drive has room for about 1 GB in `pa_pdfs`.
