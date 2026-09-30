# Oklahoma inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all three state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Oklahoma's residential program monitoring summaries at
`https://kidsoverprofits.org/ok-reports/`, fed by a new `ok_scraper.py`.
Oklahoma has 85 tracked facilities (80 open). The state posts, for each
licensed residential program and shelter, every monitoring visit with the
regulation cited, what was observed and the plan to correct, plus
**substantiated complaints** with the allegation, the plan and the finding.

**Why it is urgent.** Each facility page shows only a rolling 36 months
("Monitoring Summary since 9/30/2023" on 2026-09-30). A visit that ages out is
gone. The scraper must go on a monthly schedule as soon as it works, and it
must keep a copy of every page it reads.

## The source (verified 2026-09-30)

OKDHS Child Care Services, Residential and Child-Placing Agency Licensing.
Classic ASP.NET WebForms on plain HTTP (the facility host does not answer on
HTTPS). Plain `requests` works; no CAPTCHA, WAF or login.

### 1. List the programs

```
GET  http://www.publicview.okdhs.org/ResidentialLocator/Default.aspx
POST http://www.publicview.okdhs.org/ResidentialLocator/Default.aspx
     every hidden input (__VIEWSTATE, __EVENTVALIDATION, ...), plus
     ctl00$ContentPlaceHolder1$rblProgramType = K85   (or K84)
     ctl00$ContentPlaceHolder1$btnSearch      = Search for Child Care
```

The POST redirects to
`http://www.publicview.okdhs.org/ResidentialLocator/ChildCareFacilities.aspx`,
which holds the results grid `ctl00$ContentPlaceHolder1$GridView1`, 30 rows a
page. **Page from that URL, not from Default.aspx**: post the results page's
own hidden inputs back to `ChildCareFacilities.aspx` with
`__EVENTTARGET=ctl00$ContentPlaceHolder1$GridView1` and
`__EVENTARGUMENT=Page$Next`, and stop when the grid no longer offers
`Page$Next`. (Posting the page request to Default.aspx returns a page with no
grid; that mistake cost time during the research.)

Program types:

| Value | Type | Count |
|---|---|---|
| `K85` | Residential Program | 72 |
| `K84` | Shelter Program | 21 |
| `K86` | Child Placement Agency | out of scope |

Grid columns: Number (the case number, linked), SubType (Residential,
Residential Treatment, Family Style, Regimented Residential, Secure Care; the
cell ends with a comma), Program Name, Address, City, Zip, Phone, Capacity.
Skip the pager row, which is a nested table inside the grid.

### 2. A program's page (plain GET, no session)

```
GET http://residentialchildplacingview.okdhs.org/ResidentialView/ResidentialView.aspx?CaseNumber=K850052676
```

The case number prefix is the program type, and ids from the grid go straight
into this URL. A saved example is `tmp/scraper-research/ok/ok_fac.html`.

The page has three parts.

**General information**, in spans with fixed ids: `lblFacilityName`,
`lblDirector`, `lblLocation`, `lblEmailAddress`, `lblFacilityPhoneNumber`,
`lblCountyOfFacility`, `lblFacilityType`, `lblProgramSubtype`,
`lblWorkerName` (the state's licensing specialist), `lblRegionPhoneNumber`,
`lblCapacity`, `lblServiceProvided`.

**Monitoring summary** (`lblMontVisit` gives the "since" date). One block per
visit: spans whose ids end in `_lblVisitDate`, `_lblVisitType` (Full or
Partial) and `_lblPurposeOfVisit` (Periodic, Other, ...), followed by a table
with id `gvNewMonitoringVisits`. The id repeats once per visit, so walk the
document in order rather than looking tables up by id. Table columns:
Requirement (for example `340:110-3-153.1(h)`), Regulation Description,
NonCompliance Observed, Plan To Correct, Correction Date, NRS (Yes or No:
"Numerous, Repeated and/or Serious"). A visit with nothing found shows the
text "No non-compliances observed" and no table.

**Complaint summary** (`lblNewComplaintsSinceDate`). The page says only
substantiated complaints are shown, and that anything rising to abuse or
neglect is referred to Child Welfare Services and not published here. One
block per complaint: a span ending in `_lblComplaintReceived` (date) and a
table with id `gvNewComplaints`, columns Requirement, Description, Allegation
Description, Plan To Correct, Allegation Findings ("Substantiated").

There are no PDFs anywhere in this source.

## Decisions already made

- `state` = `OK`, page slug `ok-reports`, state file `.ok_state.json`.
- Scope: `K85` and `K84`.
- `facility_name` = the program name from the facility page (`lblFacilityName`,
  which is in normal case; the grid has it in capitals).
- `program_name` = the case number (`K850052676`).
- `program_category` = type and subtype, for example "Residential: Residential
  Treatment" or "Shelter". `full_address` = location lines joined.
  `phone`, `bed_capacity`, `executive_director` (Director) from the page.
  `action` = "Licensed" while the program is listed; when it drops off the
  list, "No longer listed (last seen YYYY-MM-DD)".
- **One report per visit and one per complaint.**
  - Visit: `report_id` = `visit-<YYYYMMDD>-<type>` lower-cased, for example
    `visit-20260714-full`. If two visits share a date and type, add `-2`.
  - Complaint: `report_id` = `complaint-<YYYYMMDD>-<first 8 hex of sha1 of
    requirement + allegation text>`, since several complaints can share a
    received date.
  - `report_date` = the visit date or complaint received date, ISO.
  - `report_url` = the facility page URL.
- **Refresh, do not just skip.** The state edits entries after the visit (a
  correction date or a plan appears later). The state file keeps, per case
  number, `{report_id: content_hash}`; a report is posted when it is new or
  its hash changed. Re-posting the same `report_id` updates the row. Look at
  how `tx_scraper.py` handles edited deficiencies (Tools repo commit
  `0aa0895`) before designing this; match it if it fits.
- **Never delete.** A visit that has aged out of the state's window simply
  stops appearing; it stays in our database.
- **Keep the page.** Save each fetched facility page, gzipped, as
  `ok_html/<case number>/<YYYY-MM-DD>.html.gz` under the same Drive base the
  PDF states use (`kop_paths.py` finds it), only when it differs from the last
  saved copy. This is the only record once the window rolls, and it lets a
  parser fix be replayed. `ReportStore` is PDF-oriented; a small helper beside
  it is fine.
- `categories` per report:

  ```json
  {
    "kind": "visit | complaint",
    "visit_type": "Full",
    "purpose": "Periodic",
    "finding": "Substantiated",
    "items": [
      {"requirement": "340:110-3-153.1(h)",
       "description": "Background investigations - general.",
       "observed": "4 personnel were employed prior to ...",
       "plan": "In the future staff will not be employed ...",
       "correction_date": "2023-11-29",
       "nrs": true}
    ],
    "item_count": 1,
    "nrs_count": 1
  }
  ```

  For a complaint, `observed` holds the allegation description and `finding`
  the findings text.
- `summary` = "Full visit (Periodic): 2 non-compliances, 1 numerous, repeated
  or serious", "Full visit (Periodic): no non-compliances observed", or
  "Substantiated complaint: <requirement>".
- `raw_content` = the items flattened to readable text (requirement,
  description, observed, plan, one block each). It is small, so this state
  does **not** need lite loading; the page reads `?state=OK` directly.
- **Flagged** = a visit with at least one item, or any complaint. Tone
  `repeat` when any item has NRS Yes; `clean` for a visit with none.

## Build steps

1. **Probe.** `tmp/scraper-research/ok/ok_probe3.py` shows the working search
   and paging; confirm 72 and 21.
2. **Listing** for `K85` and `K84`, merged with every case number in the state
   file so programs that left the list are still visited until their page
   stops answering or comes back empty.
3. **Fetch and save** each facility page (one GET each, 93 in all, about two
   minutes at a polite pace).
4. **Parser.** Walk the page in document order, pairing each visit's header
   spans with the table that follows or the "No non-compliances observed"
   text, then the complaints. Run it over all 93 pages and print: visits,
   visits with items, items, NRS items, complaints, every distinct Visit Type,
   Purpose of Visit and Allegation Findings value, and any block that had a
   header but parsed to neither a table nor the no-findings text (must be
   zero).
5. **Payload and state** with content hashes as above. `--out` writes the
   read-API shape. Add `--from-saved <dir>` to rebuild the payload from the
   saved pages without touching the state site.
6. **Match report** against `facilities_v2` where `state = 'OK'` in
   `tmp/prod.sqlite` (85 rows; names there look like "Tulsa Boys Home",
   "Boys Ranch Town Edmond", "Integris Mental Health - Spencer"). List near
   misses; do not rename records to force matches.
7. **Adapter** `js/inspections/states/ok.js`. Closest models:
   `js/inspections/states/ga.js` and `js/inspections/states/ct.js`
   (structured items, no document text to fetch).
   - Summary: case number, type and subtype, capacity, director; stats for
     visits, non-compliances, substantiated complaints.
   - Report row: date; type "Full visit", "Partial visit" or "Substantiated
     complaint"; badges for the item count and "Numerous, repeated or
     serious"; the first observed text as preview; link to the state page.
   - Body: each item as requirement and description, what was observed, the
     plan to correct and the correction date.
   - Filters: subtype; kind (visits or complaints).
   - A note, in the adapter's summary or the page's editor content: the state
     shows 36 months at a time and publishes only substantiated complaints
     that did not rise to abuse or neglect, so an empty complaint list does
     not mean there were none.
8. **Registration** per README step 4 (no archive folder: there are no PDFs),
   then the owner's page creation.
9. **Owner sign-off, then post** per the README.
10. **Schedule.** Add the scraper to `scraper_launcher.py` and tell the owner
    it needs a monthly run at the least; note it in `docs/PLAN.md`. Do not set
    up a scheduled task on the owner's machine without asking.
11. **Severe findings** as a follow-up commit: sources are substantiated
    complaints (allegation description) and visit items with NRS Yes
    (non-compliance observed). Ordinary visit items still go through the usual
    harm-category scoring.

## Acceptance checks

- A full `--no-post --out` run covers 93 programs and the parser report in
  step 4 shows zero unparsed blocks.
- A second run posts nothing; editing one saved hash in the state file makes
  exactly that report post again.
- `--from-saved` reproduces the same payload as the live run.
- The page, fed the `--out` file, shows visits and complaints, flags
  correctly, and marks NRS items. Screenshots at 390, 768 and 1440 px looked
  at.
- After the owner's go-ahead: the live API returns Oklahoma, the page renders,
  `check-bare-text.py` passes.

## Left for the owner

- Create the `/ok-reports/` page (State Reports template).
- Approve the first production post.
- Run the scraper monthly (or agree to a scheduled task).

## Not in this plan

- Office of Juvenile Affairs group homes and secure care: no inspection
  lookup was found.
- The Office of Juvenile System Oversight posts a few inspection and complaint
  PDFs at
  https://oklahoma.gov/occy/publications/office-of-juvenile-system-oversight/facility-inspection-reports.html
  and
  https://oklahoma.gov/occy/publications/office-of-juvenile-system-oversight/facility-complaint-investigations.html.
  Worth a look later as documents for the media library, not as scraper input.
- Psychiatric residential treatment facility surveys held by the health
  department were not checked.
