# Maine inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Maine's behavioral health licensing surveys for youth providers at
`https://kidsoverprofits.org/me-reports/`, fed by a new `me_scraper.py`.
Maine has 24 tracked facilities (20 open). The state's licence lookup shows,
for each licensed behavioral health organization, every survey since about
2013 with its outcome, and since late 2024 the documents themselves:
statements of deficiencies (including complaint surveys), no-deficiency
statements and plans of correction.

**Know the limit before starting.** Two licences are involved and only one
is online:

- The *behavioral health organization* licence (Division of Licensing and
  Certification) is in the lookup. Sweetser, Spurwink, KidsPeace, Good
  Will-Hinckley, Day One, Connections for Kids, NFI North and Summit
  Achievement all hold one. This plan covers it.
- The *children's residential care facility* licence (Office of Child and
  Family Services) has nothing online.

The licence is per organization, not per site, and most organizations in
the lookup serve adults. So, as with West Virginia, scope is an allowlist of
operators, and a report names its site only inside the PDF.

## The source (verified 2026-10-01)

https://www.pfr.maine.gov/almsonline/almsquery/searchcompany.aspx?board=6706

ASP.NET WebForms. Plain `requests` with a session works; no login, CAPTCHA
or bot wall. Working probes: `tmp/scraper-research/me/me_probe.py` (search
and CSV) and `me_probe2.py` (every detail page, counts).

### 1. Search

1. GET the URL above. It redirects once, adding
   `AspxAutoDetectCookieSupport=1`, and sets a session cookie.
2. POST the form back to its own action with **every hidden input**. The
   view state is split over `__VIEWSTATE`, `__VIEWSTATE1` ... `__VIEWSTATE10`
   (`__VIEWSTATEFIELDCOUNT` says how many), plus `__EVENTVALIDATION` and
   `__PREVIOUSPAGE`. Add:
   - `ctl00$ctl00$mainContent$mainContent$scRegulator` = `6706`
   - `ctl00$ctl00$mainContent$mainContent$btnSearch` = `Search`
3. Do **not** send `...$scAuthSpecQual` unless you mean to filter: it is a
   list box with no blank option, and an empty value returns the error
   page. `RM` selects "Residential Programs - Mental Health" (28 results),
   `RS` "Residential Programs - Alcohol & Drug". For this build, omit it and
   take all 341 licences.
4. Results land on `SearchResults.aspx`, about 25 a page; further pages are
   `SearchResults.aspx?PageNumber=N` in the same session. Each row: Name,
   Number, Location, Profession, Status, and a link
   `ShowDetail.aspx?SearchResultToken=<hex>`.
5. `GET ExportToCSV.aspx` in the same session returns the whole result set
   as CSV (name, licence number, profession, status, address, county, phone,
   expiration date, `Authorities`). Use it for the facility fields; use the
   result pages only for the detail links.

On 2026-10-01: 341 licences (226 mental health, 112 substance use, 3
employee assistance); 334 active, 3 expired, 4 denied. Closed organizations
are mostly not listed. The form has a "show history" checkbox that was not
tried; test whether it brings back former licensees.

Some organizations appear twice under two names with one licence number
(Good Will Home Association and Good Will-Hinckley, both `MHA414770`). Key
everything on the licence number.

### 2. A licence's page

`ShowDetail.aspx?SearchResultToken=<hex>`: licence number, status, first
licensure, expiration, address, phone, a licence history table, a Services
table (each authority with issue date, status, capacity, age category,
gender), and an **Inspections** table: Date, Type, Status. Under an
inspection that has documents sits a nested "Inspection Communications"
table: Type, Sent/Received Date, Document(s), each document a link
`ShowInspectionEventCommDetail.aspx?SearchResultToken=<hex>`.

The tables are nested, so read the inspection table's direct rows only and
attach each nested document table to the inspection row above it.

The Services table's "Age Category" is not reliable for scope: Summit
Achievement and Sweetser both show "Adult".

Tokens are opaque and there is no permanent URL for a licence or a
document. A document link fetched from a brand-new session a few minutes
later still returned the PDF, so tokens are not strictly session-bound, but
nothing says how long they last. Never store a token as a report link.

### 3. A document

`GET ShowInspectionEventCommDetail.aspx?SearchResultToken=<hex>` returns the
PDF directly.

### What is there

For the 28 residential mental health licences alone: 906 inspections (2013:
21, rising to 165 in 2024, 182 in 2025, 114 so far in 2026); types Desk
Review 607, Full Agency Survey 287, Survey Waived 10, Revisit 2; outcomes No
Deficiencies 576, Accepted Plan of Correction 330. Documents: 221, all from
late 2024 on (2024: 3, 2025: 107, 2026: 111), of kinds "NO DEFICIENCIES
SOD" 89, "PLAN OF CORRECTION" 72, "SOD WITH DEFICIENCIES" 60. Youth
operators in that set: Sweetser 91 inspections and 29 documents, Spurwink
53 and 11, NFI North 18 and 6, Summit Achievement 8 and 1.

Complaint surveys are recognisable from the document's file name and first
lines ("Complaint Survey 2026-BHP-3436"); the inspection table itself only
says Desk Review or Full Agency Survey.

## What the documents look like

Text PDFs. Samples in `tmp/scraper-research/me/`.

- **Statement of deficiencies** (2 pages in the sample): date completed,
  survey kind and number, organization, administrator, licence number,
  "Residential Site as applicable" with the site's address, then a table of
  two or three columns: the summary statement of deficiencies (rule section
  of 10-144 CMR Ch. 123, "This has not been met as evidenced by", a
  Finding), the plan of correction, and a completion date. `extract_tables()`
  found the table on the sample; use it (README, "Reading PDF tables").
- **No-deficiency statement**: the same header with a statement that the
  organization is in substantial compliance.
- **Plan of correction** (4 pages): a cover letter to the administrator,
  then the organization's answers. It belongs to the statement of
  deficiencies of the same inspection.

## Scope: the operator allowlist

Keep it in `me_scope.json` beside the scraper (licence number, name, why).
Start from these, all confirmed present on 2026-10-01:

| Organization | Licence | Our records it covers |
|---|---|---|
| Sweetser | MHA229941 | Sweetser Residential Treatment Center, probably Dirigo Place |
| Spurwink Services Inc. | MHA229881 | Spurwink's residential sites |
| KidsPeace National Centers of New England | MHA322081 | KidsPeace Graham Lake |
| Good Will-Hinckley (Good Will Home Association) | MHA414770 | Good Will-Hinckley Roundel Residential |
| Connections for Kids | MHA559381 | Connections for Kids - Park Place Gorham |
| Day One | MHA227222 and SAA221141 | Day One |
| NFI North, Inc. | MHA229301 | NFI's Maine programs |
| Summit Achievement | MHA733793 | Summit Achievement |
| Community Health & Counseling Services | MHA220861 | children's residential programs |
| Aroostook Mental Health Center | MHA219601 | Calais Children's Residential, if theirs |
| Maine Children's Home for Little Wanderers | MHA394165 | |
| Woodfords Family Services | MHA494640 | |
| Youth Villages, Inc | MHA736074 | |

Then check each of our 24 Maine records for its operator (Becket, Harbor
Family Services, Stetson Ranch, Sidney Riverbend, Oliver Place, Bridge
Crossing, Beacon House, Summit View) against the full CSV and add matches.
Several of our records are schools or closed programs that never held this
licence (Elan School, Hyde School, Long Creek); they will not match and that
is expected.

Give the owner the allowlist as a short table (in, out, unsure) before the
first post; only the unsure rows need a decision.

**Within an allowed organization, post every survey.** A multi-service
operator's surveys cover its adult and outpatient programs too. Where a
document names a residential site, record it; where a document is plainly
about an adult program, mark it `adult_program: true` so the page can hide
it by default. Do not drop it: the reader may want the operator's whole
record, and deciding by hand what is "about children" is where mistakes
would creep in.

## Decisions already made

- `state` = `ME`, page slug `me-reports`, state file `.me_state.json`
  holding, per licence number, each inspection's key and the documents seen.
- `facility_name` = the organization's name, title-cased from the state's
  capitals (the mailing name where two names share a licence);
  `program_name` = the licence number; `program_category` = "Behavioral
  health organization (mental health)" or "(substance use)"; `action` =
  status; `license_exp_date`; address and phone from the CSV. Do not store
  the CSV's email address.
- **One report per inspection row**, with its documents attached:
  `report_id` = `<YYYYMMDD>-<type slug>` plus `-2` on a clash within one
  licence (the site gives inspections no id). `report_date` = the inspection
  date, ISO. `report_url` = the search page URL above (no deep link exists).
- An inspection with no documents (everything before late 2024) is still
  posted: date, type and outcome are the record.
- A document can be added to an inspection later (the plan of correction
  arrives after the statement). Re-post an inspection when its set of
  documents changes.
- PDFs go through `ReportStore("ME_PDF_CACHE", "me_pdfs", <local fallback>)`
  named `<licence>_<report_id>_<n>.pdf`; `'me_pdfs' => 'me'` in
  `api/sync-inspection-archive.php`; `archiveState: 'ME'`. The archived
  copies are the only links a reader can open.
- `categories`: `inspection_type`, `outcome` ("NO DEFICIENCIES" or
  "ACCEPTED PLAN OF CORRECTION"), `is_complaint`, `survey_numbers[]`,
  `site` (the residential site address from the PDF), `adult_program`,
  `documents[] {kind, date, title, archive_name}`, `deficiencies[]
  {section, rule_text, finding, plan, completion_date}`,
  `deficiency_count`.
- **Flagged** = outcome "ACCEPTED PLAN OF CORRECTION", or a parsed
  deficiency. `clean` for "NO DEFICIENCIES".
- `raw_content` = the statement of deficiencies' findings and the plan text.
  Lite loading (`?state=ME&lite=1`).
- The README's privacy check runs on every document. Complaint findings
  describe individual clients; the sample used no names.

## Build steps

1. **Probe**: run the two probe scripts; expect 341 licences and, for `RM`,
   28 with 906 inspections and 221 documents.
2. **Search, CSV, result pages** for all 341, saved.
3. **Scope**: build `me_scope.json` and the owner's table.
4. **Detail pages** for the allowlist, saved (gzipped, beside the PDFs), so
   the inspection history can be re-read without the site.
5. **Documents** through `extract_with_cache`. Fetch a licence's documents
   straight after its detail page, while the tokens are fresh.
6. **Parser.** Print: inspections per type and outcome, documents per kind,
   statements with no parsed deficiency, complaint surveys found, sites
   named, and how many reports were marked adult.
7. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=ME --file=<out.json>`.
   Names here are operators, so few of our facility records will match by
   name; list which records each operator should reach, for the owner to
   link at KOP Tools > Inspection Links.
8. **Adapter** `js/inspections/states/me.js`. Closest models:
   `js/inspections/states/pa.js` (deficiency with plan of correction) and
   whichever of West Virginia or Ohio is built by then (operator-level
   records). Filters: outcome; complaint surveys; "include adult programs"
   off by default. A note on the page: these are surveys of the operator's
   behavioral health licence; the children's residential licence is held by
   another office that publishes nothing, and documents are only online
   from late 2024.
9. **Registration** per README step 4, page via `kop_tool_page_specs()`.
10. **Owner sign-off** (scope table and numbers), **then post**.
11. **Severe findings** as a follow-up: findings in statements of
    deficiencies that are not marked adult.

## Acceptance checks

- The scope table exists and the owner has settled the unsure rows.
- A full `--no-post --out` run: every allowlisted licence with its full
  inspection history and every document downloaded as a PDF.
- A second run downloads and posts nothing; a new document on an old
  inspection re-posts just that inspection.
- No token appears in any stored URL.
- The page, fed the `--out` file, shows complaint surveys apart and hides
  adult-program reports until asked. Screenshots at 390, 768 and 1440 px
  looked at.

## Left for the owner

- Decide the unsure rows of the scope table.
- Link operators to facility records at KOP Tools > Inspection Links.
- Approve the first production post.
