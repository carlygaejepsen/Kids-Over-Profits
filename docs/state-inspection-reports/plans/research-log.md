# State by state: what each one publishes

Every state and DC has now been checked for online licensing, inspection or
complaint reports on youth residential programs (2026-09-30 and 2026-10-01).
This is the record, so the research is not repeated. Re-check a "nothing
published" state only when there is a reason (a new law, a new portal, an
agency reorganisation).

"Tracked" is the number of facility records we hold for the state
(`facilities_v2` in `tmp/prod.sqlite`, 2026-10-01).

## Live on the site (17)

Arizona, Arkansas, California, Connecticut, Florida, Georgia, Michigan,
Minnesota, Montana, Nevada, North Carolina, Oklahoma, Oregon, Pennsylvania,
Texas, Utah, Washington.

## Plan written, ready to build (11)

Ordered by what is lost by waiting, then by size.

| State | Tracked | Plan | Source in one line | Note |
|---|---|---|---|---|
| New Hampshire | 52 | [new-hampshire.md](new-hampshire.md) | 24 programs, 146 visits with observations and corrective plans; 106 are complaint visits | Shows only three years: loses data while it waits |
| Wyoming | 37 | [wyoming.md](wyoming.md) | 24 providers, about 410 notices of non-compliance and visits, plus 7 federal surveys | Scanned PDFs; a provider's documents vanish when it leaves the list |
| Virginia | 139 | [virginia.md](virginia.md) | Behavioral health: about 140 services with corrective action plans; social services: 19 facilities, 180 inspections | Largest uncovered state; needs a browser once per run |
| Ohio | 63 | [ohio.md](ohio.md) | 159 residential agencies, about 500 compliance review PDFs | Reports start July 2025 and are per agency |
| Idaho | 58 | [idaho.md](idaho.md) | 40 facilities, about 156 statements of deficiencies and letters | Small and quick |
| West Virginia | 40 | [west-virginia.md](west-virginia.md) | Health-licence surveys back to 2001 for youth providers, about 540 | Scope needs an allowlist; PDFs carry a hidden stale text layer |
| Iowa | 32 | [iowa.md](iowa.md) | 46 psychiatric institutions for children (25 closed), 240 federal survey PDFs | Small and clean |
| Maryland | 29 | [maryland.md](maryland.md) | 174 short inspection summaries for group homes | Summaries only, one line per citation |
| Maine | 24 | [maine.md](maine.md) | Behavioral health licence surveys for about 13 youth operators: inspection history from 2013, documents (complaint surveys, deficiencies, plans of correction) from late 2024 | Per operator, so scope is an allowlist; no permanent links |
| Hawaii | 9 | [hawaii.md](hawaii.md) | Health department statements of deficiencies for special treatment facilities and therapeutic living programs, 43 facilities, 110 statements since 2023; 9 youth programs in scope, 21 statements | Built 2026-10-05, not yet posted; scans read by OCR; youth allowlist `hi_scope.json` |
| South Dakota | 16 | [south-dakota.md](south-dakota.md) | 27 providers, about 150 licensing studies, inspections and corrective plans | Small and easy |

## Something is published, no plan yet (worth a second look later)

| State | Tracked | What exists | Why it waits |
|---|---|---|---|
| Colorado | 56 | Health department (CDPHE) inspections, citation text, plans of correction and self-reported occurrences for every health-licensed facility, last three years, in a Tableau Server workbook; probed 2026-10-05, see "Colorado probe" below | Not built: the data is reachable, but only by driving the dashboard's server-side state, and scripted probes hung the state's Tableau server. The child-welfare licence (most facilities) publishes nothing |
| Massachusetts | 57 | Education department program reviews of approved special education residential schools, https://www.doe.mass.edu/oases/ps-cpr/ : about 150 DOCX reports | Education compliance reviews, not licensing or abuse findings; the residential licence publishes nothing. New regulations take effect 2026-11-17 and may bring licensing history online: re-check after that date |
| Missouri | 116 | The license-exempt facility registry, a one-page PDF updated monthly, https://mydss.mo.gov/provider-services/children/residential-program/license-exempt | A list, not reports. Worth diffing monthly to catch new and vanished facilities |
| Alabama | 116 | Mental health department site visit scores (a percentage per program site), https://apps.mh.alabama.gov/COCA/SiteVisitReports/default.aspx | Numbers only, no findings |
| North Dakota | 10 | Three scanned federal survey PDFs for psychiatric residential facilities, https://www.ndhealth.gov/hf/deficiency/ds-search.aspx | Three documents; add by hand to the media library |
| South Carolina | 47 | Monthly health department enforcement action PDFs, https://dph.sc.gov/professionals/healthcare-quality/healthcare-quality-enforcement-actions ; a list of 8 residential treatment facilities | Youth facilities almost never appear in them |
| Wisconsin | 30 | Health department surveys for youth crisis stabilisation and a few similar programs, https://www.forwardhealth.wi.gov/WIPortal/subsystem/public/DQAProviderSearch.aspx | Low yield (group homes and residential care centres publish nothing); the tool is being replaced |
| Nebraska | 7 | Licence lookup with inspection tables, https://www.nebraska.gov/LISSearch/search.cgi | Behind reCAPTCHA, and the tables were empty for residential licences |
| Rhode Island | 13 | Two Child Advocate investigation reports on named facilities, https://childadvocate.ri.gov/reports | A one-off download for the media library |

### Colorado probe (2026-10-05)

Verdict: **not buildable as a plain scraper; do not retry the scripted route
below.** Probes and samples: `tmp/scraper-research/co/`.

- **Where it lives.** The CDPHE page links "Find and compare healthcare
  facilities tool" =
  https://cohealthviz.dphe.state.co.us/t/HealthFacilitiesPublic/views/HealthFacilitySearchSite/1_HealthFacilitySearchSite
  (Tableau Server, site `HealthFacilitiesPublic`, guest access, workbook
  owner CDPHE). Six published views: `1_HealthFacilitySearchSite`,
  `2_FacilitysListofInspectionsandOccurrences`, `3A_CitationsText`,
  `3B_CitationsRegulation`, `3C_FacilityPlanofCorrection`,
  `4_OccurenceDescription`. Every view renders server-side (`render-mode-server`,
  even with `browserRenderingThreshold=100000`), so the bootstrap carries no
  data dictionary; the `tableauscraper` approach finds nothing.
- **What works, cheaply.** `GET .../views/HealthFacilitySearchSite/1_HealthFacilitySearchSite.csv`
  returns the whole facility list (5,208 rows: ID, name, address, phone, type,
  operating status, payor). URL filters work on these exports
  (`?Facility%20ID=010507`). Youth-relevant types: Psychiatric Residential
  Treatment Facility (3: Southern Peaks Regional Treatment Center 379MYC,
  Third Way Center 37J5MF, Devereux Cleo Wallace 370006 closed), Psychiatric
  Hospital (11, several with adolescent units: Cedar Springs, Peak View,
  Highlands, Denver Springs, Centennial Peaks, West Pines, Sierra Vista),
  Licensed Only Psychiatric Hospital (4, Eating Recovery Center), Behavioral
  Health Entity (33, all now "Closed" here since the licence moved to the
  Behavioral Health Administration in 2024; Tennyson Center, M.I.K.I.D.).
  Our records that appear: Cedar Springs, Devereux Cleo Wallace, Eating
  Recovery Center, Tennyson Center, Jefferson Center. CHRP (82) are
  waiver host homes, not programs.
- **Inspections and citation lists: reachable.** In a session on view 2 with
  `?Facility ID=<id>`, `commands/tabsrv/select-region-no-return-server`
  (worksheet `List of Inspections`, zone 9, `vizRegionRect`
  `{"r":"viz","x":0,"y":0,"w":5000,"h":100000}`) then
  `commands/tabdoc/launch-hybrid-view-data-dialog` +
  `commands/tabdoc/get-view-data-dialog-tab-pres-model`
  (`dataProviderType=selection`, `isSummaryTable=true`, the sheet's
  `sqlproxy` datasource, `topN`) returns rows as JSON. Cedar Springs (010507):
  28 inspections 2023-03 to 2026-07 (complaint, licensure complaint,
  recertification) and 45 citation rows (code, title, inspection ID, date;
  "0000" = initial comments). The view-2 `.csv` and `.pdf` exports give only
  the count chart / the visible first ten rows; Download Crosstab offers only
  the two count sheets; `.pdf` of 3A ignores URL filters (its sheets are
  filtered by an exclude-all action).
- **Citation text: reachable only in a real browser.** Clicking an inspection,
  then a citation, then the 3A tab, then the text, then Download > Data opens
  a View Data window with Citation Text, Title, Facility, Inspection ID and
  date, S/S and code (one citation at a time; long text is split over sheets
  `Citation`, `Citation_Overflow1..7`). 3B (regulation) and 3C (plan of
  correction) work the same way. View 2 also lists **occurrences**
  (self-reported incidents: Cedar Springs shows 223 since 2023, types such as
  Physical Abuse, Sexual Abuse) with descriptions on view 4.
- **What went wrong.** Replaying that flow with `requests`
  (`commands/tabdoc/goto-sheet` to "3A. Citation's Text", then view data on
  `Citation`) does not carry the action filter: the server ran the query over
  the whole citation-text table. Three attempts each hung or dropped the
  connection, and from 16:20 the server stopped answering (timeouts, then
  HTTP 503 for at least 20 minutes). Never call view data on a 3A/3B/3C/4
  sheet unless the action filter has been confirmed narrowed.
- **If someone builds it later:** drive the UI with Playwright (clicks by
  position, one citation at a time, a few seconds apart; the inspection and
  citation lists come cheaply from the view-data commands above), ask CDPHE
  for the data through the "Find and compare facilities information request"
  form linked on the page, or file a records request. Scope would be about
  15 facilities (3 PRTFs, psychiatric hospitals with youth units, Eating
  Recovery Center); the BHA's own lookups were not probed.

## Nothing published online (records request is the only route)

Alaska, Delaware, District of Columbia, Illinois, Indiana, Kansas, Kentucky,
Louisiana, Mississippi, New Jersey, New Mexico, New York, Tennessee,
Vermont. Also the child-welfare residential licence in Colorado,
Massachusetts, Maine, West Virginia, Wisconsin and South Carolina, and the
behavioral health licence in Ohio.

Useful leftovers from those states:

- **Lists that could check our records** (no reports): Kentucky child-caring
  facilities (XLSX), Indiana licensed institutions and group homes (PDF),
  Louisiana residential homes (HTML table), Alaska residential child care
  facilities (XLSX), Iowa licensed facilities (PDF), New York mental health
  programs (open data), Tennessee mental health licences (search), Kansas
  and Mississippi psychiatric residential facility lists (PDF).
- **New Mexico**: the licensing unit moved to the Health Care Authority on
  2026-07-01. Its Division of Health Improvement already posts survey
  reports for other program types at https://www.hca.nm.gov/dhi-survey-reports/ ,
  so children's facilities may follow. Re-check in early 2027.
- **Vermont**: licensing reports exist as a document type (one reached the
  legislature as testimony) but are not posted. A records request for all
  residential treatment program licensing reports would be worthwhile.

## Where the details are

The agents' full findings for each state (endpoints, counts, what was and
was not verified) were delivered in session on 2026-09-30 and 2026-10-01 and
are summarised above. Saved responses and working probe scripts are in
`tmp/scraper-research/<state>/` on the owner's machine (gitignored).
