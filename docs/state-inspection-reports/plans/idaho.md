# Idaho inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Idaho's children's residential licensing surveys at
`https://kidsoverprofits.org/id-reports/`, fed by a new `id_scraper.py`.
Idaho has 58 tracked facilities (34 open) and is a core state for the
industry. The state posts, for each licensed children's residential care
facility and outdoor program, every survey's statement of deficiencies with
the facility's plan of correction, or a letter saying none were found.

This is a small build: 40 facilities, about 156 PDFs.

## The source (verified 2026-10-01)

Idaho Department of Health and Welfare, Division of Licensing and
Certification, Children's Residential Licensing. The documents sit in the
department's public Laserfiche WebLink repository:

- Residential facility surveys (39 facility folders):
  https://publicdocuments.dhw.idaho.gov/WebLink/Browse.aspx?id=19853&dbid=0&repo=PUBLIC-DOCUMENTS
- Wilderness (outdoor program) surveys (1 folder, Blue Fire Wilderness Therapy):
  https://publicdocuments.dhw.idaho.gov/WebLink/Browse.aspx?id=19852&dbid=0&repo=PUBLIC-DOCUMENTS
- Provider list (one PDF: address, licence type, beds, ages):
  https://publicdocuments.dhw.idaho.gov/WebLink/Browse.aspx?id=20035&dbid=0&repo=PUBLIC-DOCUMENTS

Plain `requests` works. No login, cookie, CAPTCHA or bot wall; the listing call
answered without visiting the browse page first.

### List a folder

```
POST https://publicdocuments.dhw.idaho.gov/WebLink/FolderListingService.aspx/GetFolderListing2
Content-Type: application/json

{"repoName":"PUBLIC-DOCUMENTS","folderId":19853,"getNewListing":true,
 "start":0,"end":500,"sortColumn":"","sortAscending":true}
```

Returns `data` with `name`, `path`, `totalEntries` and `results[]`. Each
result has `name`, `entryId`, `type` (0 = folder, -2 = document),
`extension`, and a `data` array whose last two items are the created and
modified timestamps. Page with `start`/`end` until `totalEntries` is covered.

### Download a document

```
GET https://publicdocuments.dhw.idaho.gov/WebLink/ElectronicFile.aspx?docid=<entryId>&dbid=0&repo=PUBLIC-DOCUMENTS
```

Returns `application/pdf`. A human-facing link for the same document is
`https://publicdocuments.dhw.idaho.gov/WebLink/DocView.aspx?id=<entryId>&dbid=0&repo=PUBLIC-DOCUMENTS`.

### What is there

Folder 19853 holds one folder per facility, named by the facility (for
example "Boise Girl's Academy", "Summit Youth Academy  (fka Patriot Center)",
"Northwest Children's Home - Canyon View Center"). On 2026-10-01 the 39
folders held 151 PDFs with only two name patterns:

| Document name | Count | Meaning |
|---|---|---|
| `Approved POC-<m/d/yyyy>` | 117 | Statement of deficiencies with the accepted plan of correction |
| `No Deficiencies Letter-<m/d/yyyy>` | 34 | Licence renewed, zero deficiencies |

By year: 2021 (1), 2022 (25), 2023 (30), 2024 (30), 2025 (42), 2026 (23).
The wilderness folder adds five no-deficiency letters (one named with
underscores, `No Deficiencies Letter-9_27_2022`).

The folder tree is the only facility list, so a facility whose folder is
removed disappears. Keep every folder id seen in the state file and never
delete on our side.

## What a report looks like

All sampled PDFs were real text (2 to 12 pages), no OCR needed. Samples:
`tmp/scraper-research/id/id_poc_sample.pdf` and the probe scripts beside it.

**Statement of deficiencies.** A header block, then one table:

```
Children's Residential Licensing - Statement of Deficiencies
Agency: The Safe House                      Region(s): 5
Agency Type: Children's Residential Care Facility   Survey Dates: 4/10/2024 to 4/11/2024
License: CRL-2135                           License(s) Granted: 1-Year

Rule Reference/Text | Findings | Agency's Plan of Correction | Date to be Corrected
```

Each row cites a rule (`16.04.18.411.02.b SERVICE PLANS. ...`), the
surveyor's finding (which may open with "This is a repeat deficiency."), the
facility's answers to four fixed questions, and a date. The last page has
"Agency Representative & Title" and "Date Submitted". Older ones (2021 to
2022) say "Organization Type" and give the survey date loosely ("October 2021
Electronic").

**Use `page.extract_tables()`, not `extract_text()`.** Plain text extraction
interleaves the four columns line by line and cannot be untangled afterwards
(the same fault that keeps Washington out of the severe-finding scan).
`pdfplumber`'s table extraction returned the four columns cleanly on the
sample. How a row that runs over a page break comes back was not checked; test
it on a long statement and join the pieces.

**No-deficiency letter.** A two-page letter: date, administrator, facility,
address, "Zero deficiencies were noted this review period", and the licence
end date.

Whether complaint investigations are posted as their own documents is not
settled. Every sampled statement was a licensing survey. While parsing, look
for the words complaint, investigation and follow-up in the header and
findings and report what turns up.

## Decisions already made

- `state` = `ID`, page slug `id-reports`, state file `.id_state.json`
  (seen-ID pattern keyed by the facility folder's `entryId`, plus a `folders`
  map of every folder id seen with its last name).
- Scope: folders 19853 and 19852. The agency surveys folder (19851, adoption
  and foster agencies) is out of scope.
- `facility_name` = the folder name with doubled spaces collapsed. Keep
  "(fka ...)" out of the name: strip a trailing parenthesis that starts with
  "fka" or "formerly" and store it as `categories.former_name` on each report,
  so the name still matches our record.
- `program_name` = the licence number from the newest statement of
  deficiencies (`CRL-2135`). A facility with only no-deficiency letters has no
  licence number in its documents; use `ID-<folder entryId>` there. Once
  chosen, a facility's `program_name` is stored in the state file and never
  changes, or the facility would split into two rows.
- `program_category` = "Children's Residential Care Facility" or "Outdoor
  Program" (from the document header, else from which top folder it is in).
- `full_address`, `bed_capacity`: from the provider list PDF where the name
  matches; otherwise the address block of the newest letter. `action` =
  the newest "License(s) Granted" value or "Licence renewed".
- `report_id` = the document's `entryId`. `report_date` = the end of the
  survey dates in the header, as ISO; for a letter, the letter date; fall back
  to the date in the document name.
- `report_url` = the `DocView.aspx` link above.
- PDFs go through `ReportStore("ID_PDF_CACHE", "id_pdfs", <local fallback>)`,
  named `<entryId>.pdf`; store that as `categories.archive_name`; add
  `'id_pdfs' => 'id'` to `$FOLDERS` in `api/sync-inspection-archive.php` and
  `archiveState: 'ID'` in the adapter.
- `categories` per report:

  ```json
  {
    "kind": "deficiencies | no_deficiencies | other",
    "survey_dates": "4/10/2024 to 4/11/2024",
    "license": "CRL-2135",
    "license_granted": "1-Year",
    "region": "5",
    "deficiencies": [
      {"rule": "16.04.18.411.02.b", "rule_text": "SERVICE PLANS. 02. Updated Service Plan. ...",
       "finding": "Updated service plans did not address ...",
       "plan": "1. What actions will be taken ...", "date_to_correct": "2024-04-22",
       "repeat": true}
    ],
    "deficiency_count": 2,
    "repeat_count": 1,
    "former_name": "Patriot Center",
    "archive_name": "36342.pdf"
  }
  ```

- **Flagged** = `kind` is `deficiencies`. Tone `repeat` when any deficiency is
  marked a repeat; `clean` for a no-deficiency letter.
- `summary` = "2 deficiencies (1 repeat): 16.04.18.411.02.b, 16.04.18.416.03"
  or "No deficiencies".
- `raw_content` = for a statement, the rows written out one block per
  deficiency (rule, finding, plan, date), not the interleaved page text; for a
  letter, the letter text. The data is small, so the page reads `?state=ID`
  directly, with no lite loading.
- **Only licensing documents are posted.** Michigan's source once held a
  seclusion sheet naming a youth. A document that is neither of the two known
  kinds is logged, kept out of the payload and listed in the run report for
  the owner.

## Build steps

1. **Probe.** `tmp/scraper-research/id/id_probe.py` lists the folders and
   documents; confirm the counts.
2. **Listing.** Both top folders, then each facility folder. Record created
   and modified timestamps; a document whose modified time changed is fetched
   again.
3. **Download and extract** through `extract_with_cache`, storing the table
   rows (not just text) in the cached extraction.
4. **Parser.** Run over all 156 documents and print: documents per `kind`,
   deficiencies per document, repeats, documents where table extraction found
   no rows on a page that has text (must be explained, not ignored), every
   distinct "License(s) Granted" value, and any complaint or investigation
   wording found.
5. **Provider list.** Parse the one PDF in folder 20035 for address, type,
   beds and ages; match to folders by name; list folders with no match.
6. **Payload and state.** `--out` writes the read-API shape.
7. **Match report**: `php scripts/match-inspection-names.php --state=ID
   --file=<out.json>` against the 58 Idaho records (many of those are juvenile
   detention centres, which this source does not cover). Near misses go to
   the owner through KOP Tools > Inspection Links; do not rename records.
8. **Adapter** `js/inspections/states/id.js`. Closest models:
   `js/inspections/states/ok.js` (structured items, no text fetch) and
   `js/inspections/states/pa.js` (citation with plan of correction).
   - Summary: licence, type, beds; stats for surveys, deficiencies, repeats.
   - Report row: survey date, kind, badges ("3 deficiencies", "Repeat
     deficiency", "No deficiencies"), first finding as preview, links to the
     state document and the archived copy.
   - Body: each deficiency as rule, finding, plan of correction, date.
9. **Registration** per README step 4, with the page created by an entry in
   `kop_tool_page_specs()`.
10. **Owner sign-off, then post** per the README.
11. **Severe findings** as a follow-up: the sentence source is each
    deficiency's finding; a cited deficiency is substantiated.

## Acceptance checks

- A full `--no-post --out` run covers 40 facilities and about 156 documents,
  with the parser report from step 4 and no unexplained empty tables.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, flags only statements of deficiencies and
  shows each deficiency with its plan. Screenshots at 390, 768 and 1440 px
  looked at.
- After the owner's go-ahead: the live API returns Idaho, the page renders,
  `check-bare-text.py` and `check-contrast.py` pass.

## Left for the owner

- Approve the first production post.
- Review any document the scraper held back as not a licensing report.

## Not in this plan

- Psychiatric residential treatment facility surveys: the state's folder for
  them (id 4726 in the same repository) holds two facilities, one closed with
  two 2019 documents and one empty. Not worth a scraper; the two documents
  could go in the media library by hand.
- Juvenile detention centres (many of our Idaho records): licensed elsewhere,
  no public reports found.
