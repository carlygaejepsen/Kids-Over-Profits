# Colorado: CDPHE health facility inspections (slow browser click-driver)

Built 2026-10-05. Read `README.md` in this folder first. Verified against the
live dashboard on 2026-10-05; probes and samples in `tmp/scraper-research/co/`.

## Status (2026-10-05): built, never run live

Built: `co_scraper.py` and `co_scope.json` (Tools repo, in the launcher and
the guide), `js/inspections/states/co.js` (tested against a hand-made
fixture at 390/768/1440 px), the registrations and the draft `/co-reports/`
page spec (these landed inside another session's commit f7451626).

Not done: the first live run. The citation-text path was never exercised
by the finished scraper, and no `--out` file exists. The server was not fit
to drive that evening: a 30 s timeout on a plain page at 18:27 ET and HTTP
503 at 18:57, after it had answered in about a second at 17:57. Two
`--lists-only` attempts (17:22, 18:19) stopped themselves on a 30 s timeout,
as designed.

To run it on a healthy day (evening, Mountain time), from the Tools repo:

1. Health: one request by hand, e.g. open
   https://cohealthviz.dphe.state.co.us/t/HealthFacilitiesPublic/views/HealthFacilitySearchSite/1_HealthFacilitySearchSite
   in a browser. It should draw within a few seconds.
2. Lists only (cheap):
   `python co_scraper.py --no-post --lists-only --facility 010507`
   Look for "28 inspections, 45 citation rows" (Cedar Springs on 2026-10-05).
3. One citation, watched:
   `python co_scraper.py --no-post --facility 010507 --max-citations 1 --headed --shots tmp/co-shots --out tmp/co-out.json`
   Look at the screenshots: after "tab-3A" the header must name Facility ID
   010507, the inspection and the code; the log must say "read <inspection>
   <code>". A "does not show exactly this citation" failure means the click
   positions are off: nothing was downloaded, fix the positions before
   going on. Check the cached file in
   `.report_extract_cache/co/010507/citations/` holds one row with the text
   (and `plan_rows`, or a `plan_error` saying why 3C was not read: the 3C
   header and click position were never checked).
4. One facility: the same with `--max-citations 45`; then
   `php scripts/match-inspection-names.php --state=CO --file=<out.json>` and
   `python scripts/preview-state-reports.py --state CO --file <out.json> --shots tmp/co-preview`
   in the web repo.
5. The rest: `--max-citations 40` a run, one run an evening.

Any "STOPPED" line means stop for the day; it never retries.

## Read this before running anything

On 2026-10-05 scripted replays of the dashboard's session commands asked the
state's Tableau server for the whole statewide citation-text table, and the
server stopped answering for at least twenty minutes, then answered only
intermittently for hours. The scraper exists in its current slow form because
of that. Its rules (in `co_scraper.py` and the Tools repo guide) are not
optional. (A later probe at 16:52 made the same mistake once more: its drag
selection silently selected nothing and it still asked for 3A's data. That
is why the scraper now checks the page header before every download.)

- Citation text only through real Chrome, clicking the way a visitor does,
  and only after the page's own header names exactly the facility, inspection
  and citation selected. Never call View Data on a 3A/3B/3C/4 sheet without
  that check; never replay those commands with `requests`.
- At least 5 s between dashboard actions; stop the whole run (no retry) on a
  response over 30 s, a 5xx, or two failed actions in a row.
- `--max-citations` (default 40) per run; evenings, Mountain time.

## Source

The health department page
https://cdphe.colorado.gov/health-facilities/find-and-compare-facilities
links its "Find and compare healthcare facilities tool", a Tableau Server
workbook with guest access:
https://cohealthviz.dphe.state.co.us/t/HealthFacilitiesPublic/views/HealthFacilitySearchSite/1_HealthFacilitySearchSite

| View | What it shows |
|---|---|
| `1_HealthFacilitySearchSite` | every facility (5,208): ID, name, address, phone, type, status, payor |
| `2_FacilitysListofInspectionsandOccurrences` | one facility: inspections (date, ID, type), citations of the selected inspections (code, title), self-reported occurrences (date, ID, type) |
| `3A_CitationsText` | the selected citation: scope and severity, the surveyor's text |
| `3B_CitationsRegulation` | the rule's text |
| `3C_FacilityPlanofCorrection` | the facility's plan of correction |
| `4_OccurenceDescription` | an occurrence's description and the facility's comment |

The state keeps three years. All views render on the server
(`render-mode-server`), so a page load carries no data; `tableauscraper`
finds nothing. Long citation text is split over sheets `Citation`,
`Citation_Overflow1..7`.

What is cheap and safe (used without a browser):

- `GET .../1_HealthFacilitySearchSite.csv`: the whole facility list.
- A view-2 session with `Facility ID=<id>` in the URL: View Data
  (`launch-hybrid-view-data-dialog` + `get-view-data-dialog-tab-pres-model`,
  `dataProviderType=selection`) on `List of Inspections` gives all its
  inspections; a rectangle selection over that sheet
  (`select-region-no-return-server`, zone 9) then View Data on
  `List of Citations` gives every citation row. Cedar Springs: 28
  inspections, 45 citation rows, each call under 2 s.

What does not work: the `.csv`/`.pdf` exports of views 2-4 (first sheet or
first screen only; 3A ignores URL filters because its sheets are filtered by
an exclude-all action); Download Crosstab (only the two count charts);
client-side rendering (refused).

## Scope

`co_scope.json` (Tools repo), one entry per Facility ID of six types:

- **In (9):** the three psychiatric residential treatment facilities
  (Southern Peaks Regional Treatment Center, Third Way Center, Devereux Cleo
  Wallace, closed), five psychiatric hospitals with adolescent units (Cedar
  Springs, Peak View, Highlands, Denver Springs, Centennial Peaks; the unit
  claims come from the hospitals' own program lists) and Tennyson Center for
  Children (behavioral health entity, closed licence).
- **Unsure (9), not read until the owner decides:** West Pines, Sierra
  Vista, Johnstown Heights, West Springs, the three Eating Recovery Center
  licences, HCA HealthONE Aurora's mental health centre, M.I.K.I.D.
- **Out (54):** the state hospitals (Pueblo, Fort Logan), children's general
  hospitals, adult assisted-living residential treatment facilities, the
  other behavioral health entities (clinics; the licence moved to the
  Behavioral Health Administration in 2024).

The child-welfare licence that most Colorado youth programs hold publishes
nothing; the page says so.

## Payload

- Facility: `facility_name` as the state writes it, `program_name`
  `CO-<Facility ID>`, `program_category` the state's facility type,
  address, phone, status.
- Report: one per inspection, `report_id` the state's inspection ID,
  `report_date` ISO, `report_url` the view-2 page for the facility.
  `categories.citations[]`: code, title, scope and severity, text, plan.
  `categories.comments`: the 0000/9999 opening and closing comments.
  Flagged when at least one citation is not 0000/9999.
- Built only when every citation of the inspection has been read; a
  privacy hit (date of birth, record number, named patient) holds it.
- Psychiatric hospitals also treat adults; the dashboard does not say which
  unit a citation concerns. The text is kept whole and the page says so.

## Not done

- Self-reported occurrences (view 4): Cedar Springs alone lists 223 since
  2023 (physical abuse, sexual abuse ...). They are the facility's own
  reports, not findings; reading them needs its own click path, proven the
  same way first, and a privacy review of the descriptions.
- Regulation text (3B).
- The severe-findings extractor.
