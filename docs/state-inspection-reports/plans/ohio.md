# Ohio inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish Ohio's compliance reports for residential agencies at
`https://kidsoverprofits.org/oh-reports/`, fed by a new `oh_scraper.py`.
Ohio has 63 tracked facilities (42 open). The Department of Children and
Youth certifies group homes and children's residential centres and, since
July 2025, posts each agency's compliance review reports.

Size on 2026-10-01: 159 agencies operating 303 residential facilities, about
500 report PDFs. The archive grows every month, and only reports dated
2025-07-01 or later are online (earlier ones need a records request).

**Two limits to state plainly on the page.** Reports belong to the *agency*,
not to one of its facilities, and an agency-level review mixes residential
findings with foster care ones. And there is no public source for the
behavioral health licence (Department of Behavioral Health): its "Provider
Survey Reports" page exists but is empty.

## The source (verified 2026-10-01)

https://odjfs2.my.site.com/FindFosterCareAdoptionAgencies/s/

Salesforce Experience Cloud (Aura). Plain `requests` works; no login,
CAPTCHA or bot wall. Working scripts are in `tmp/scraper-research/oh/`:
`sample_aura_client.py`, `oh_list.py` and
`sample_salesforce_content_delivery_download.py`. Start from them.

### Calling the site

1. GET the search page. From the HTML (URL-decoded) read `fwuid` and the
   loaded hash for `APPLICATION@markup://siteforce:communityApp`. Both
   change when Salesforce deploys, so read them every run, never hard-code.
2. POST form fields to
   `https://odjfs2.my.site.com/FindFosterCareAdoptionAgencies/s/sfsites/aura?r=1&aura.ApexAction.execute=1`:
   - `message` = `{"actions":[{"id":"1;a","descriptor":"aura://ApexActionController/ACTION$execute","callingDescriptor":"UNKNOWN","params":{"namespace":"","classname":"DCYAgencySearchHelper","method":"<method>","params":{...},"cacheable":false,"isContinuation":false}}]}`
   - `aura.context` = `{"mode":"PROD","fwuid":"<fwuid>","app":"siteforce:communityApp","loaded":{"APPLICATION@markup://siteforce:communityApp":"<hash>"},"dn":[],"globals":{},"uad":true}`
   - `aura.pageURI` = `/FindFosterCareAdoptionAgencies/s/`
   - `aura.token` = `null`
3. The result is at `actions[0].returnValue.returnValue`; check
   `actions[0].state` is `SUCCESS`.

| Method | Params | Returns |
|---|---|---|
| `getFosterCareAdoptionAgenciesForMapView` | `{"selectedFilters": "<JSON string>"}` | `agencyList`: 732 rows, agencies and their facilities |
| `getAgencySearchPicklists` | none | filter values, including `facilityTypeOptions` |
| `getAgencyDetails` | `{"agencyId": "500533"}` (the digits of `OFCLA-500533`) | the agency with `facilities[]` (name, address, county, `facilityType`, phone, `recordId`, suspension flag) and `branches[]` |
| `getAgencyComplianceReports` | same | a list of `{fileName, fileURL}` |

**`selectedFilters` must be a JSON string holding all seven keys**, or the
call succeeds with nothing in it:

```
{"selectedAgencyTypes":[],"selectedFacilityType":"","selectedCategory":"","selectedCounty":"","selectedAgencyName":"","selectedAgencyZip":"","selectedRadius":""}
```

List rows carry `accountNumber`, `agencyId` (the parent agency's id on a
facility row), `agencyName`, address fields, `agencyType`, `isFacility`,
`hasActiveSuspension`, `agencyRecordId`. Of the 732 rows, 303 have
`isFacility` true. Facility types (from `getAgencyDetails`): 247 group homes,
41 child residential centres, 4 child wellness campuses, 4 scholars
residential centres, 3 residential parenting facilities, 2 crisis care
facilities, 1 therapeutic wilderness camp, 1 residential infant care centre.

Only current agencies are listed, with no status field. `getAgencyDetails`
for an id that has left the list returns null. Keep every agency id in the
state file and never delete on our side.

### Downloading a report

A `fileURL` is a Salesforce content-delivery page
(`https://odjfs2.my.salesforce.com/sfc/p/<org>/a/<id>/<key>`), not a PDF.
The download takes four plain requests, all implemented and tested in
`sample_salesforce_content_delivery_download.py`:

1. GET the link.
2. POST `https://odjfs2.my.salesforce.com/sfc/p/` with
   `compositePageName=<org>/a/<id>/<key>`; read `recordId` and `orgId` from
   the response.
3. GET `/sfc/ld/<org>/a/<id>/<key>/forceContent/contentDistributionApp.app?aura.format=JSON&aura.formatAdapter=LIGHTNING_OUT`
   for that app's `fwuid` and loaded hash, then POST `/sfc/ld/.../aura` with
   the action `ContentDistributionViewerController/ACTION$getContentDistributionInfo`.
   `aura.pageURI` must be `/sfc/p/#<org>/a/<id>/<key>` (with the `#`). It
   returns `versionId` and `viewId`.
4. GET `/sfc/dist/version/download/?oid=<orgId>&ids=<versionId>&d=<url-encoded /a/id/key>&operationContext=DELIVERY&viewId=<viewId>&dpt=`,
   which returns the PDF.

The `fileURL` itself opens in a browser, so it is a usable public link.

## What a report looks like

Text PDFs with names already redacted by the state ("Record 1 [REDACTED]").
File names: `Compliance Report-<Full|Focused|Other> Review (AR-nnnnnnnn).pdf`,
each with an optional `...-Additional Findings.pdf`.

- **Full Review** (the one read was 46 pages): the records reviewed per tool
  (New Staff, Anniversary Staff, Child in Residential, On-Site Residential,
  Child Interview, foster caregiver tools, ...); a "Summary of
  Noncompliance" with the agency's details; then "Summary of Findings of
  Noncompliance - CAP Needed": per question, the rule cited (for example
  `5180:2-5-30(C)(3)`), counts of Y / N / T-A / N-A, a compliance
  percentage, and per-record Reason and Comments.
- **Other Review**: often a one-page stub (agency, universe period) with the
  substance in the Additional Findings file. Complaint investigations
  probably arrive this way; nothing labels them, so do not call them
  complaints.
- **Additional Findings**: "Compliance Summary for Additional Findings" with
  the date generated, the licensing specialist, and the same
  question/count/percentage table.

There is no corrective action plan text.

## Decisions already made

- `state` = `OH`, page slug `oh-reports`, state file `.oh_state.json`
  (seen-ID pattern keyed by agency id, plus every agency id seen).
- Scope: agencies with at least one facility row (159). Foster-only and
  adoption-only agencies are out.
- **One facility row per agency**, because reports are per agency:
  `facility_name` = agency name; `program_name` = the agency's `OFCLA-`
  number; `program_category` = "Residential agency (n facilities)";
  `full_address` = agency business address; `action` = "Active suspension"
  when `hasActiveSuspension` is true, else "Certified".
- The agency's facilities go in every report's `categories.facilities[]`
  (name, type, address, county) and in the adapter's `searchText`, so a
  search for a group home's own name finds its agency. Facility pages on our
  site match by name: after `--out`, run the match script and report how
  many of our 63 Ohio records are reached through the agency name. If most
  of our records are named for the facility, raise it with the owner before
  posting: the alternative is one row per facility, each repeating its
  agency's reports, which inflates counts and should not be chosen quietly.
- **One report per review number.** The main file and its Additional
  Findings file are one report: `report_id` = the review number
  (`AR-00001359`); `raw_content` = both texts; both PDFs archived.
- `report_date` = "DATE REPORT GENERATED" when present, else the end of the
  universe period, ISO. `report_url` = the main file's `fileURL`.
- PDFs go through `ReportStore("OH_PDF_CACHE", "oh_pdfs", <local fallback>)`
  named `<review number>.pdf` and `<review number>-additional.pdf`;
  `'oh_pdfs' => 'oh'` in `api/sync-inspection-archive.php`;
  `archiveState: 'OH'`.
- `categories`: `review_type` (Full, Focused, Other), `review_number`,
  `universe_period`, `specialist`, `findings[] {question, rule, n_count,
  reviewed, compliance_pct, comments}` (only questions with at least one N),
  `finding_count`, `residential_finding_count` (findings from the
  residential tools), `facilities[]`, `archive_names[]`.
- **Flagged** = `finding_count > 0`. Show the residential count separately
  in the badge when it can be told apart by tool name.
- Lite loading (`?state=OH&lite=1`).

## Build steps

1. **Probe**: `python tmp/scraper-research/oh/oh_list.py` (732 rows, 303
   facilities).
2. **List, details, report lists** for the in-scope agencies (about 320
   calls).
3. **Download** through `extract_with_cache` (four requests per file, about
   500 files; go slowly).
4. **Parser**, against at least 40 reports of all three types. Print:
   reports per type, findings per report, reports whose main file is a stub
   with no additional file, and which tool names occur.
5. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=OH --file=<out.json>`.
6. **Adapter** `js/inspections/states/oh.js`. Closest model:
   `js/inspections/states/mi.js`. Summary lists the agency's facilities.
   Filters: review type; facility type. The page note covers the two limits
   in the Goal section and that reports begin in July 2025.
7. **Registration** per README step 4, page via `kop_tool_page_specs()`.
8. **Owner sign-off, then post** per the README.
9. **Schedule**: monthly; new reviews appear continuously.
10. **Severe findings** as a follow-up, from the per-record comments of
    residential findings.

## Acceptance checks

- A full `--no-post --out` run: about 159 agencies and 250 to 300 reports
  (main and additional files joined), every PDF starting with `%PDF`.
- A changed `fwuid` (simulate by corrupting it) is recovered by re-reading
  the page.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, finds an agency by one of its facilities'
  names. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Decide the facility-row question if the match report raises it.
- Approve the first production post.
- Consider a public records request for reports before July 2025.
