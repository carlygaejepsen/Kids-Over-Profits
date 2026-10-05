# Hawaii inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

**Status (2026-10-05): built, not posted.** `hi_scraper.py` and
`hi_scope.json` in the Tools repo, `js/inspections/states/hi.js` and the
registrations in the web repo. The numbers below are from a full
`--no-post --out` run that day. The first post waits for the owner.

## Goal

Publish Hawaii's licensing inspections of youth residential programs at
`https://kidsoverprofits.org/hi-reports/`. Hawaii has 9 tracked facility
records (2 open: Pacific Quest and Pearl Haven; Hawaii Youth Correctional
Facility and Kahi Mohala are not licensed under these rules). The Department
of Health, Office of Health Care Assurance (OHCA), State Licensing Section,
licenses special treatment facilities (STF) and therapeutic living programs
(TLP) under Hawaii Administrative Rules chapter 11-98 and posts one statement
of deficiencies and plan of correction for each licensing inspection since
2023.

## The source (verified 2026-10-05)

One static WordPress page, plain `requests`, no login or bot wall:
https://health.hawaii.gov/ohca/inspection-reports/

- The page (1.3 MB) is a Ninja Tables table of every state-licensed care home
  and facility: 2,315 rows on 2026-10-05, mostly community care foster family
  homes (1,595) and adult residential care homes. Each row is
  `<tr data-row_id="N">`: name, facility type, then one column of report
  links for each year, "Inspection Reports 2023" to "2026" ("Not Required"
  before the facility was licensed). Link labels are dates such as
  `08.22.24 Initial`.
- Types read: `STF` (30 rows) and `TLP` (13 rows): **43 facilities, 110
  links**, the same as the research of 2026-10-01. Maui Recovery appears
  twice (rows 7688 and 7928).
- Files are WordPress uploads,
  `https://health.hawaii.gov/ohca/files/<yyyy>/<mm>/<name>.pdf`. The upload
  month is when the state posted it, often months after the inspection.
- The site's WordPress REST API is closed (Kadence Security, HTTP 401); only
  the page itself can be read.
- The State Licensing Section page
  (https://health.hawaii.gov/ohca/state-licensing-section/) links the current
  rosters: `Special-Treatment-Facility-4-2026.pdf` (32 licences) and
  `Therapeutic-Living-Program-4-2026.pdf` (9). Each row: name, address, city,
  ZIP, island, licence expiry, licence number (`53-STF`), phone. The scraper
  finds the roster links on that page each run, since the file names carry
  the month.

## The documents

One form throughout, "Statement of Deficiencies and Plan of Correction",
landscape:

- **Cover page**: facility name, `CHAPTER 98`, address, inspection date and
  kind ("Annual" on every one read; "Initial" appears only in the page's
  labels). When nothing was cited the cover's table reads `NO DEFICIENCIES /
  NOT APPLICABLE (NA) / NA` and that is the whole document.
- **Deficiency pages**: a table of a checkbox column, "RULES (CRITERIA)" (the
  rule, e.g. `§11-98-12 Minimum standards for licensure; services. (14)`, its
  text, then `FINDINGS`), "PLAN OF CORRECTION" and "Completion Date". Each
  deficiency appears twice: a PART 1 page ("Did you correct the
  deficiency?") and a PART 2 page ("Future plan"). Some PART 1 pages say
  correcting after the fact is not practical and only a future plan is
  required.
- **Signature page**: the licensee's or administrator's name and date.

Of the 110: 36 are text PDFs with no deficiencies, 7 text PDFs with
deficiencies, 67 scans (the signed form, scanned back). The facility's
answers in a scan are typed or handwritten.

Findings name residents and staff by number only ("Resident #2", "Employee
#1,2", "Client #1 and #2"). No date of birth, name or record number was seen
in the in-scope statements.

### Reading the table

`extract_text()` interleaves the columns, and on the text PDFs
`extract_tables()` loses the bottom of the rules cell (the table's last rule
is missing, so the findings fall outside the detected table). Both are
avoided by cropping each column and reading it alone:

- **Text pages**: the header words ("RULES", "PLAN", "Completion") locate
  the columns; the vertical edges (`page.edges`) between them are the cut
  lines; each crop is read with `extract_text()`.
- **Scans**: the page is rendered at 250 dpi and read once by Tesseract to
  find the headers; the table rules are found as dark-pixel columns between
  the headers (a 14 px window, because a skewed scan's rule wanders over
  several pixel columns); each column is cropped and read again. Where OCR
  misses the "RULES" header, the rules column ends at the strong rule left
  of "PLAN".
- A plan column whose answer words (the printed prompts left out) average
  below 75 OCR confidence is handwriting: it is posted as "(handwritten; not
  transcribed, open the document to read it)". Typed answers read at 85 to
  95.
- PART 1 and PART 2 pages of one deficiency are joined by rule number and
  findings text (similarity at least 0.7); when OCR loses the word PART, a
  deficiency's second page is taken as PART 2.

Checked by eye on Bobby Benson 2024, Pacific Quest Olena 2025 and Reed's Bay
2023/2025, Hiki Mai Ka La 2023/2024, Na Ohana Pulama 2026, Pearl Haven
2023/2025, Benchmark 2024/2025, Nova Luna 2023 and E Ho'oulu Hou 2023.

## Scope: the youth allowlist

The STF and TLP licences cover adult substance use treatment, adult mental
health residences, crisis centres and youth programs alike, so scope is
`hi_scope.json` beside the scraper: one entry per page row (`row` =
`data-row_id`), with the page name, type, roster licence, `scope`
(in / out / unsure) and a one-line reason. Only `in` is scraped; `unsure` is
not; a page row the file does not list is reported at the end of every run
and not scraped.

On 2026-10-05: **8 in, 33 out, 2 unsure.**

| In | Licence | Why |
|---|---|---|
| Benchmark Behavioral Health System | 69-STF | Sub-acute residential treatment for adolescent boys 13 to 17 (CAMHD contracted provider list) |
| Bobby Benson Center | 53-STF | Residential substance use treatment for youth (CAMHD) |
| Hiki Mai Ka La | 59-STF | Child & Family Service residential crisis stabilization, ages 6 to 17 |
| Na Ohana Pulama | 118-STF | Catholic Charities; its 2026 plan says "all youth that enter the CBR2 program" |
| Pearl Haven | 107-STF | Rite of Passage / Ho'ola Na Pua program for girls (CAMHD CBR-2-CSEC); tracked |
| Pacific Quest - Reed's Bay | 116-STF | Pacific Quest, tracked |
| Pacific Quest - Olena | 117-STF | Pacific Quest, tracked (TLP on the page, STF on the roster) |
| Pacific Quest - Awapuhi | none | Pacific Quest, tracked; not on the April 2026 roster |

| Unsure | Why |
|---|---|
| E Ho'oulu Hou Elua Program | Catholic Charities, 2848 Park Street, 6 beds; nothing found says who it serves; not on the roster (2 statements, 2023 and 2024) |
| Nova Luna, Inc. | Eating disorder treatment for adolescents and adults; whether the residential site takes minors is not stated (1 statement, 2023) |

The 33 out are adult programs (C.A.R.E. Hawaii's adult residences and crisis
services, Big Island Substance Abuse Council, Habilitat, Hina Mauka,
Ho'omau Ke Ola, Aloha House, the Salvation Army, Sand Island, Women's Way,
the Hawaii State Hospital program and others), each with its reason in the
file. Pacific Quest also serves young adults; its sites are kept whole as a
tracked TTI program.

## Decisions made

- `state` = `HI`, page slug `hi-reports`, state file `.hi_state.json`
  (seen report ids and the URL each was posted from).
- **Facility**: `facility_name` = the scope file's `display_name` (the page
  name tidied: "Pacific Quest- Olena" -> "Pacific Quest - Olena", "inc."
  dropped); `program_name` = the roster licence number, or
  `OHCA-row-<row id>` for a facility not on the roster (Awapuhi);
  `program_category` from the licence type; address, phone and licence
  expiry from the roster, or the newest statement's address when the
  facility is off the roster; `action` says whether it is on the roster.
- **Report** = one statement. `report_id` = the inspection date from the
  page label (ISO), `-2` on a clash; a re-uploaded statement for the same
  inspection replaces the row. `report_url` = the state's PDF. `report_date`
  = the label date (it matched the document's own date on all 20).
- `categories`: `kind` (`deficiencies`, `no_deficiencies`, `unread`),
  `inspection_type`, `label`, `year_column`, `chapter`, `name_on_document`,
  `address_on_document`, `deficiency_count`, `deficiencies[] {rule, heading,
  rule_text, finding, correction, future_plan, completion_dates[],
  only_future_plan}`, `ocr`, `archive_name`, `page_row`, `page_type`.
- `raw_content` = each deficiency's rule, findings and the facility's typed
  answers. No-deficiency statements carry "No deficiencies."
- **Flagged** = `kind: deficiencies`. Clean = no deficiencies. `unread` (a
  statement whose table could not be read) is neutral and listed in the run
  report; there were none.
- **Only statements are posted**: a document whose first page is not the
  OHCA statement form is logged as `not_a_report` (none on 2026-10-05).
- **Privacy**: the posted text is checked for a date of birth (with a value
  after the words), a social security number, a record number (Medicaid,
  QUEST, MRN, case or record number with digits) and a named or initialed
  client, resident or youth. A hit holds the report, lists it, and moves its
  archived copy to `.report_extract_cache/hi_held/`. Nothing was held.
- PDFs through `ReportStore("HI_PDF_CACHE", "hi_pdfs", ...)`, named
  `<yyyy>-<mm>_<state file name>.pdf` (the state's upload path, so names are
  unique); `'hi_pdfs' => 'hi'` in `api/sync-inspection-archive.php`;
  `archiveState: 'HI'`.
- The page loads in full (20 reports); lite loading is not needed.
- Conduct: one request at a time, 0.8 s apart, a generic browser
  User-Agent, the index page and rosters saved to
  `.report_extract_cache/hi_pages/` on every fetch (`--cached` reruns
  without asking the state), extractions cached per PDF.

## The full run (2026-10-05)

`python hi_scraper.py --no-post --out <file>`: 24 requests (index page,
licensing page, 2 rosters, 20 PDFs).

- Page: 43 STF/TLP facilities, 110 links. Scope: 8 in, 33 out, 2 unsure
  (3 statements not scraped).
- Payload: 8 facilities, 20 statements, 2023-02-17 to 2026-03-17.
- By kind: 11 with deficiencies (12 deficiencies cited; all 11 flagged), 9
  with no deficiencies, 0 unread.
- 12 read by OCR. 4 with handwritten plans not transcribed (Benchmark
  2024-07-08 and 2025-07-02, Pearl Haven 2023-12-14 and 2025-12-05).
- Held by the privacy check: 0. `not_a_report`: 0. Failed downloads: 0.
  Label dates that differ from the document: 0.
- Every PDF archived to `I:\My Drive\FileBird Cloud - kidsoverprofits.org\hi_pdfs`
  (20 files).
- A second run with `--cached` made no requests and read every statement
  from the extraction cache.
- Names (`php scripts/match-inspection-names.php --state=HI
  --file=<out.json>`): 4 names reach 2 records (Pacific Quest - Reed's Bay,
  Awapuhi and Olena -> Pacific Quest #12565; Pearl Haven -> #9617).
  Benchmark Behavioral Health System, Bobby Benson Center, Hiki Mai Ka La and
  Na Ohana Pulama reach no record and have no near miss (we hold no record
  for them).

Known reading limits, left as they are: OCR spelling slips in scanned
answers ("dictitian", "Smg" for "5mg"), a stray line of signature initials at
the end of some typed answers, and one scan (Pacific Quest Olena 2025) whose
rule heading and PART 2 page did not read (the rule number is taken from the
whole-page read; the finding and the correction are there).

## Acceptance checks

- A full `--no-post --out` run: every in-scope statement downloaded,
  classified and parsed; done.
- A rerun downloads nothing (extractions cached; seen ids after a post).
- The page, fed the `--out` file through a Playwright route intercept on
  `/ga-reports/` (`python scripts/preview-state-reports.py --state HI
  --file <out.json> --shots tmp/hi-preview`): flags statements with
  deficiencies only, no console errors, no horizontal scroll at 390, 768
  and 1440 px; screenshots looked at.

## Left for the owner

- Settle the two unsure rows in `hi_scope.json` (E Ho'oulu Hou Elua
  Program, Nova Luna).
- Check that the Drive folder `hi_pdfs/` is shared "Anyone with the link"
  (the archive sync indexes only shared folders).
- Approve the first production post (run Hawaii from the launcher), then
  publish `/hi-reports/` (created on deploy as a draft).
- Link Benchmark, Bobby Benson, Hiki Mai Ka La or Na Ohana Pulama at KOP
  Tools > Inspection Links if records are added for them.
- Run it quarterly: the state posts a few statements a month across all
  care homes, and each STF or TLP is inspected once a year.
- Second phase: the severe-finding extractor (README step 6), findings only,
  after the data is live.
