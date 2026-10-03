# West Virginia inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish West Virginia's health-facility survey reports for youth providers at
`https://kidsoverprofits.org/wv-reports/`, fed by a new `wv_scraper.py`.
West Virginia has 40 tracked facilities (38 open). The state posts surveys
observed as far back as 2000, including complaint surveys, as statements of
deficiencies with plans of correction, and keeps closed facilities listed.

**Know the limit before starting.** Two agencies license these programs and
only one publishes:

- The Office of Health Facility Licensure and Certification (OHFLAC) licenses
  the *behavioral health* side of a provider and certifies psychiatric
  residential treatment facilities. Its surveys are online. This plan covers
  them.
- The Bureau for Social Services licenses the *residential child care* side
  (the group home licence itself) and publishes nothing.

So a group home appears here only through its operator's behavioral health
licence, often as one agency-level record, and many records for youth
providers are old ones marked "Closed - Other" that still carry years of
surveys. Scope is the hard part of this build, not access.

## The source (verified 2026-10-01)

https://ohflac.wvdhhr.org/Apps/Lookup/FacilitySearch

Plain `requests` works; no login, CAPTCHA or bot wall.

### 1. List (JSON)

```
POST https://ohflac.wvdhhr.org/Apps/Lookup/FacilitySearch
Content-Type: application/json; charset=utf-8
```

The body is the DataTables request object (`draw`, `columns`, `order`,
`start`, `length`, `search`) plus the page's filters: `FedCode`,
`NameFilter`, `CountyFilter`, `AdvCountyFilter`, `LegalNameFilter`,
`StatusFilter`, `ContactFilter`, `WithinDistance`, `ZIPFilter`,
`PaymentFilter`, `BedsFilter`, `LicenseFilter`, `ApprovedAMAPs`. They are
built in `/Apps/Scripts/Lookup/facsearch-2.0.js`; read that file and send
the same shape. A minimal body without the DataTables part returns
`{"error":"Object reference not set to an instance of an object."}`.

- `FedCode` = type code + subtype: `"06*"` for psychiatric residential
  treatment facilities, `"89*"` for Behavioral Health Centers.
- `StatusFilter` = `"active,closed,pending"` to include closed records.
- `length: -1` returns everything in one response.

Row fields: `DT_RowId` (the facility id), `Name`, `LegalName`, `StateKey`,
`Status` (Active, Pending, Closed - Owner / Other / State / Moved), `Opened`,
`Closed`, `Street`, `City`, `ZIP`, `County`, `Phone`, `FacType`,
`LicenseType`, `Number`, `Expires`, `LicensedBedCount`.

Counts on 2026-10-01: 6 psychiatric residential treatment facilities (2
active, 1 pending, 3 closed); 1,190 Behavioral Health Centers (663 active,
508 closed, 19 pending). Saved in `tmp/scraper-research/wv/`.

### 2. A facility's surveys

```
GET https://ohflac.wvdhhr.org/Apps/Lookup/SurveyHistory/<id>
```

Lists surveys by year (observed back to 2000): the date, the survey type
("Re-Licensure Survey", "Complaint Survey", "Complaint, Follow-up/Revisit",
...), and a button per report carrying `data-survid`, `data-survtype`
(`State` or `Federal`), `data-eventid` and `data-saveas`.

**This page returns HTTP 500 for a facility with no surveys.** Treat 500 as
"none" for that facility, but stop the run if every facility returns 500
(that would mean the site is down).

`GET https://ohflac.wvdhhr.org/Apps/Lookup/FacilityDetails/<id>` is the
reader-facing page; it shows surveys since October 2023 inline as HTML with
the deficiency text.

### 3. A report (plain GET)

```
GET https://ohflac.wvdhhr.org/Apps/SurveyForms/Display2567?survID=<survid>&stype=<State|Federal>&saveAsName=<saveas>
```

Returns a PDF generated on request (about 2 MB each).

## Scope: which of 1,190 behavioral health records are youth providers

The list has no field for the population served. Bed counts do not help: the
records with beds are mostly adult disability and substance use homes, while
most youth providers show zero beds.

Build the scope as an explicit allowlist, kept in a small JSON file beside
the scraper (`wv_scope.json`: facility id, name, why it is in):

1. All six psychiatric residential treatment facilities.
2. Behavioral health records whose `Name` or `LegalName` matches a youth
   provider. Start from the pattern used in research
   (`tmp/scraper-research/wv/wv_probe3.py`): it matched 169 records, of which
   76 had surveys, 540 surveys in all. The output is saved as
   `wv_youth_with_surveys.json`.
3. Then prune by hand-checkable rules, because that pattern over-reaches.
   **Known false positives:** every "RESCARE WV - ... DISTRICT / AGENCY"
   record (adult disability services; about 150 of the 540 surveys), county
   school health services, and adult programs with "girl" or "ranch" in the
   name. **Known true ones:** Stepping Stones Cottages, Youth Academy, Elkins
   Mountain School, Davis-Stuart, Children's Home of Wheeling, Genesis Youth
   Center, New River Ranch, Pressley Ridge, KVC, Burlington United Methodist
   Family Services, Board of Child Care (Everstand), Home Base, Youth
   Services System, Florence Crittenton (Wellspring), Cammack, St. John's
   Home, Golden Girl, Pine Valley School, Potomac Center, Braley & Thompson,
   National Youth Advocate Program.
4. Cross-check with our own 40 West Virginia records in `tmp/prod.sqlite`
   (17 of them matched a state record by name in research) and with the
   state's placement directory of current youth facilities,
   https://www.wvdhhr.org/wvcpn/Default.asp (65 facilities), to catch
   operators the name pattern misses.

Present the allowlist to the owner as a short table (included, excluded,
unsure) before the first post. Do not make the owner read JSON; the unsure
rows are the only ones needing a decision.

Agency-level records for foster care and community services operators (KVC
county offices, National Youth Advocate Program) are youth-serving but not
residential. Include the operator's main record, leave out its county
offices, and mark `program_category` accordingly.

## What a report looks like, and the template trap

The reports are the federal CMS-2567 form or the state's own 2567: initial
comments (survey type, census, sample size), then per tag the regulation,
"not met as evidenced by" findings, and the provider's plan of correction
with a completion date in the right-hand column.

**Every PDF has a second, hidden text layer left over from the form
template.** Plain `extract_text()` returns the real report mixed character
by character with stale text: on state forms a 2004 deficiency about
"adolescent girls bedrooms" (tag C 173), on federal forms nursing-home tag
F 156. A scraper that trusts that text will report a deficiency that is not
in the document. Verified on both samples in `tmp/scraper-research/wv/`.

Two ways through were considered:

- **OCR of the rendered page** reads what is visible. On the sample (a 2012
  survey with no deficiencies) `pytesseract` at 200 dpi recovered the report
  without the hidden template tags. OCR remains slower than text extraction.
- **Filter the characters by font.** On the state sample the stale layer was
  in an embedded `Arial` subset and the real content in `Helvetica`.

Calibration: 55 varied reports (35 State, 20 Federal; 2000-2026) showed no
unexpected report fonts. Filtered text averaged 98.1% OCR word recall, with
a 90.3% minimum. A C 173 citation in a 2016 report was present in the visible
OCR and is genuine report content, not the hidden template. The scraper uses
the font filter, OCRs a deterministic 5% sample, holds sampled reports when
OCR is unavailable or below 90% recall, and holds a C 173/F 156 finding only
when OCR does not confirm the tag. An offline payload build from the 55
calibration extracts produced 54 reports and held one by the privacy check;
there were no unparsed reports, date mismatches or unexpected fonts. For
surveys since October 2023, the inline HTML on FacilityDetails is a third,
clean source and a good cross-check.

## Decisions already made

- `state` = `WV`, page slug `wv-reports`, state file `.wv_state.json`
  (seen-ID pattern keyed by facility id).
- `facility_name` = `Name`, title-cased from the state's capitals;
  `program_name` = `WV-<DT_RowId>`; `program_category` = "Psychiatric
  residential treatment facility" or "Behavioral health centre (youth
  provider)"; `action` = `Status`; `bed_capacity` = `LicensedBedCount` when
  above zero; address and phone from the row. `LegalName` goes in every
  report's `categories.legal_name` and the adapter's `searchText`.
- `report_id` = `<survid>-<stype>`. `report_date` = the survey date from the
  history page, ISO. `report_url` = the facility's FacilityDetails page (the
  PDF link is long and generated; the archived copy is the stable one).
- PDFs go through `ReportStore("WV_PDF_CACHE", "wv_pdfs", <local fallback>)`
  named `<saveas>.pdf`; `'wv_pdfs' => 'wv'` in
  `api/sync-inspection-archive.php`; `archiveState: 'WV'`. At about 2 MB
  each this is roughly 1 GB.
- `categories`: `survey_type`, `form` (State or Federal), `is_complaint`,
  `event_id`, `legal_name`, `tags[] {tag, regulation, finding, plan,
  completion_date}`, `tag_count`, `archive_name`.
- **Flagged** = at least one tag other than the initial comments. A survey
  whose text says no deficiencies were cited is `clean`.
- Lite loading (`?state=WV&lite=1`).

## Build steps

1. **List** both types; save the raw responses.
2. **Scope**: build `wv_scope.json` as above and the owner's table.
3. **Survey histories** for the scope (500 means none).
4. **Download** through `extract_with_cache`. The PDFs are generated per
   request; go slowly (one a second).
5. **Text**: the font filter is calibrated on 55 varied samples. Then the tag
   parser. Print reports per survey type, tags per report, any C 173/F 156
   tag not confirmed by OCR, and the OCR comparison.
6. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=WV --file=<out.json>`.
7. **Adapter** `js/inspections/states/wv.js`. Closest model:
   `js/inspections/states/ar.js`. A note on the page: these are surveys of
   the provider's health licence; the group home licence itself is held by
   another bureau that publishes no reports. Filters: survey type
   (complaints on their own), facility type, open or closed.
8. **Registration** per README step 4, page via `kop_tool_page_specs()`.
9. **Owner sign-off** (scope table and numbers), **then post**.
10. **Severe findings** as a follow-up, reusing the Arkansas reading of
    2567 tags.

## Acceptance checks

- The scope table exists and the owner has settled the unsure rows.
- No report in the payload carries the template's stale deficiency.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, shows complaint surveys apart and closed
  facilities as closed. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Decide the unsure rows of the scope table.
- Approve the first production post.
- Confirm Drive has room for about 1 GB in `wv_pdfs`.
