# Oregon: abuse findings, restraint reports and the missing program types

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Oregon is live at `https://kidsoverprofits.org/or-reports/`, but only with
site-visit reports for two program types. It has **no abuse or complaint
findings at all**, and the serious-findings scan leaves Oregon out because a
site-visit report's findings are rule text. This plan extends the existing
`or_scraper.py` (Tools repo) with three things the state already publishes on
the same site:

1. **Substantiated abuse cases** (the "Child Caring Agency Legislative
   Report", quarterly since 2021-Q4): Oregon's only public abuse findings.
2. **Restraint and involuntary seclusion reports** per program, quarterly.
3. **Site visits for the other program types** the scraper never reads.

Complaint investigations, civil penalties, conditions, plans of correction
and revocations are **not published** (see "Not in this plan").

## The source (research 2026-10-05; URLs, counts and parses re-checked)

Everything is in one SharePoint document library, `reports`, at
`https://www.oregon.gov/odhs/licensing/childrens-care-agencies`, reached
through the anonymous `_vti_bin/Lists.asmx` SOAP endpoint the scraper already
uses (browser user agent, Origin and Referer headers).

**Listing the whole library.** `GetListItems` on list `reports` with no
`viewName`, `<ViewAttributes Scope="RecursiveAll"/>`, `rowLimit` 500 and
paging: **1,333 rows** on 2026-10-05. Fields: `ows_Report_x002d_Type`,
`ows_Program_x0020_Type`, `ows_Agency0` (78 agencies), `ows_Title` (a date,
or `YYYY-Qn`), `ows_FileRef`, `ows_Quarterly_x0020_Report_x0020_Type`. The
file is `https://www.oregon.gov/` + the part of `FileRef` after `;#`.
`GetListCollection` also lists `agencies` (72 rows, type and website per
agency), useful for names.

Rows by report type: Restraint Involuntary Seclusion 940, Unannounced 209,
License Renewals 139, Quarterly Reports 28 (19 PDF, 9 xlsx), 6-month 13,
Initial License 2, Announced 1, one untyped xlsx. By program type: RC 567,
SH 318, FC 154, DT 150, ABS 71, AA 25, TBS 13, STS 6, none 29 (the statewide
files). **Outdoor youth programs: zero rows.** The scraper's two views today
return 171 rows (`rc` 167, `tbs` 4).

Program type codes (OAR 413-215): RC residential care, TBS therapeutic
boarding school, ABS academic boarding school, SH shelter care, STS secure
transportation services, OYP outdoor youth program, DT day treatment, FC
foster care agency, AA adoption agency. The builder confirms the labels from
`ows_Program_x0020_Type` before relying on them.

### 1. Legislative reports: substantiated abuse (the priority)

`reports/<YYYY>q<N>-leg.pdf`, 2021q4 to 2026q2: **19 PDFs, 61 substantiated
cases** (regex `\bCCA\d{6,7}[A-Z]?\b`, 1 to 7 a quarter). They have a text
layer; pdfplumber reads them. Each case is a block:

```
Report/allegation  Provider  Approximate incident date  Abuse type  Did physical injury, sexual abuse or death result?
CCA240110A  Trillium - Parry Center  10/5/2024  Wrongful Restraint  No
Nature of abuse and brief narrative | Corrective actions taken or ordered by the Department, and outcome
```

The two narrative columns come out interleaved line by line (two-column
layout). Split them with pdfplumber word x-positions (left column vs right
column), not by line. Examples: 2024q4 Looking Glass Pathway for Girls,
sexual abuse by staff; 2025q1 Trillium Parry Center, wrongful restraint;
2026q2 J Bar J Youth Services, neglect; Rimrock Trails ATC, neglect and
financial exploitation (staff stealing a youth's medication). Staff are
written "a specific staff", residents are not named.

After the cases, each PDF carries a **statewide** restraint summary for the
quarter (total restraints, programs reporting, children restrained,
reportable injuries, restraints by untrained staff, ...). For example
2025-Q1: 2,164 restraints, 34 programs, 195 children, 315 reportable
injuries. These are statewide numbers, not per program.

Provider is the agency or the program name as the state writes it
("Trillium - Parry Center", "J Bar J Youth Services"), sometimes not the site.

### 2. Restraint and involuntary seclusion (RIS)

- **2021-Q4 to 2023-Q4: 940 PDFs, one per program per quarter**, e.g.
  `reports/avfo-ap-rc-2021q4.pdf` (2 pages, text layer). Per quarter about 91
  to 116. Fields on the form: agency, program, period, restraint count,
  seclusion count, injuries, locked-room seclusions, rooms, children
  restrained, children restrained more than three times, steps taken to
  reduce. RC 402, SH 212, FC 133, DT 128, TBS 9 (J Bar J Academy), a few ABS
  and STS.
- **2024-Q1 onward: one statewide workbook per quarter**,
  `reports/<YYYY>q<N>-ris.xlsx` (2024q1 to 2026q2, 9 files plus an untyped
  2026q1 copy). Sheet `Worksheet`, header on row 2, **one row per program**
  (about 124 rows): `Agency`, `Program`, `TotalServed`, demographic counts
  (served vs restrained), then the counts: incidents involving restraint,
  incidents involving seclusion, restraint incidents with reportable injury,
  seclusion incidents with injury, locked-room seclusions, seclusion rooms,
  children restrained, children secluded, both, restrained more than three
  times, secluded more than three times, incidents by untrained staff, room
  description, steps to reduce. Read the headers by text, not by position.

### 3. Site visits for the other program types

Same report format the scraper already parses. About 190 Unannounced,
Renewal, 6-month and Initial rows outside RC and TBS (ABS, SH, DT, FC, AA,
STS). Read them from the whole-library listing, not by adding views.

## Decisions already made

- **Extend `or_scraper.py`**, no new scraper. `state` = `OR`, same page, same
  state file `.or_state.json`, same `ReportStore("OR_PDF_CACHE", "or_pdfs")`.
- **List once, from the whole library** (RecursiveAll), and route each row by
  `Report-Type`. The views stay only as a fallback. This also catches a type
  ODHS adds later.
- **Scope by program type**: site visits and RIS for RC, TBS, ABS, SH, STS,
  OYP. Foster care (FC), adoption (AA) and day treatment (DT) agencies are
  not residential programs: list them in the `--out` report but do not post
  them unless their agency matches a `facilities_v2` record (run
  `php scripts/match-inspection-names.php --state=OR --file=<out.json>`).
  The owner can widen this.
- **Abuse cases**: one report per case. `report_id` = the case id
  (`CCA240110A`), `report_date` = the approximate incident date (ISO),
  `report_url` = the legislative PDF, `facility_name` = the provider as
  written (the Inspection Links screen ties "Trillium - Parry Center" to its
  record). `categories`: `kind: "abuse_substantiated"`, `quarter`,
  `abuse_types[]`, `injury_or_death` (Yes/No as printed), `narrative`,
  `corrective_action`, `source_pdf`. `summary` = "Substantiated: Wrongful
  Restraint (10/5/2024)". `raw_content` = the narrative and the corrective
  action. Always flagged.
- **A case that appears in two quarters** (an update) keeps one row: same
  `report_id`, the later text wins, hash-refresh as `ok_scraper.py` does.
- **RIS**: one report per program per quarter. `report_id` = `ris-<quarter>-<file slug>`
  for the PDFs and `ris-<quarter>-<agency>-<program>` (slugged) for xlsx rows.
  `report_url` = the PDF, or the xlsx for the workbook rows. `categories`:
  `kind: "restraint_report"`, `quarter`, `restraints`, `seclusions`,
  `restraint_injuries`, `seclusion_injuries`, `locked_room_seclusions`,
  `children_served`, `children_restrained`, `children_restrained_3plus`,
  `untrained_staff_incidents`, `steps_to_reduce`. Not flagged by itself;
  the adapter shows the numbers. Do not store the demographic columns.
- **Statewide restraint totals** from the legislative PDFs: not reports.
  Write them to `--out` and to a small `or_ris_statewide.json` the page can
  show as a one-line note per quarter.
- **Serious findings**: the abuse cases are what the scan needs. Add an OR
  adapter in `inc/inspection-highlights.php` that reads only
  `kind = abuse_substantiated` (bump OR in `kop_ih_state_rule_versions()`);
  dry-run on real rows as the highlights memory requires. Site-visit rule
  text stays out.

## Build steps

1. **Whole-library listing** with paging; print counts by report type and
   program type and check them against the numbers above.
2. **Legislative parser.** Print per PDF: case ids found, and any block whose
   provider, date or abuse type did not parse. Expect 61 cases over 19 PDFs.
   Check the two-column split by eye on 2024q4, 2025q1 and 2026q1 (7 cases).
3. **RIS PDF parser** on the 940 forms; print forms where a count did not
   parse.
4. **RIS xlsx reader** (openpyxl) by header text; print the matched header
   for each field per file so a renamed column shows.
5. **Site visits for the other program types** through the existing parser.
6. **Payload, state, `--out`**, then the name match.
7. **Adapter** `js/inspections/states/or.js`: a filter for kind (site visit /
   abuse finding / restraint report); abuse findings open with narrative and
   corrective action; restraint reports as a small table of counts; the
   statewide note per quarter.
8. **Highlights adapter** as above, dry run on the `--out` rows.
9. **Owner sign-off, then post** per the README.

## Acceptance checks

- `--no-post --out` run: 1,333 library rows listed; 61 abuse cases with
  provider, date and type on every one; 940 RIS PDFs plus every xlsx row read
  with no unparsed count left unexplained.
- A second run posts nothing.
- The page fed the `--out` file shows the three kinds apart; screenshots at
  390, 768 and 1440 px looked at.
- The highlights dry run queues the substantiated cases and nothing from site
  visits.

## Left for the owner

- Approve the first production post (it needs `KOP_DATA_API_KEY`).
- Decide whether foster care, adoption and day treatment agencies belong on
  the page.
- Records requests (below).

## Not in this plan

- **Complaint investigations and licensing actions** (civil penalties,
  conditions, formal plans of correction, revocations): `reports.aspx` names
  them as "Other reports", but the library holds none. Ask the Children's
  Care Licensing Program for them by public records request (ODHS records
  request page: https://www.oregon.gov/odhs/pages/records-requests.aspx).
- **Outdoor youth programs and secure transport**: their pages exist but the
  library has no site visits for them (STS has 6 RIS rows only).
- **OTIS Digital Data Book** (https://www.oregon.gov/odhs/data/pages/otis-data.aspx):
  Power BI, yearly 2019 to 2026, "non-familial abuse of children". Its query
  endpoint refused scripted access. It looks like counts by type and outcome;
  check in a real browser whether it names facilities before planning it.
- **Chehalem Youth and Family Services**: a Notice of Intent to Revoke filed
  as legislative testimony,
  https://olis.oregonlegislature.gov/liz/2026R1/Downloads/PublicTestimonyDocument/254781 ,
  for the media library (not verified beyond the link).
- **Psychiatric residential treatment** (Oregon Health Authority licensing):
  not probed.
