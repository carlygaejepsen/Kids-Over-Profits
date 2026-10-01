# New Hampshire inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish New Hampshire's licensing visits to residential child care programs
at `https://kidsoverprofits.org/nh-reports/`, fed by a new `nh_scraper.py`.
New Hampshire has 52 tracked facilities (46 open). The state posts, for each
of 24 licensed residential programs, every licensing visit with the rules
reviewed, the inspector's observations on each non-compliance and the
program's corrective action plan. **Most visits are complaint visits** (106
of 146).

**Why it is urgent.** The site shows only the previous three years. Visits
age out, and a program that loses its licence disappears with its history.
Schedule this monthly from the day it works, and keep every page fetched.

## The source (reported by research 2026-10-01; list call and samples re-checked)

NH DHHS Child Care Licensing Unit search:
https://new-hampshire.my.site.com/nhccis/NH_ChildCareSearch

Salesforce Visualforce. Plain `requests` works; no login or CAPTCHA. (The
host without the hyphen, quoted in the state's own rule, returns 502.)
Working scripts are in `tmp/scraper-research/nh/`:
`sample_vf_remoting_client.py` (the list) and `sample_visit_detail_ajax.py`
(one visit). Start from them. The list script's last lines print the wrong
key: the rows are at `result.v[]`, each row's fields under `.v`.

### 1. List (Visualforce remoting)

GET the search page and parse the JSON inside
`RemotingProviderImpl({...})` for `vf.vid` and, for the method
`retrieveAccountRecords` of `NH_ChildCareSearchClass`, its `csrf`, `ns`,
`ver` and `authorization`. Then:

```
POST https://new-hampshire.my.site.com/nhccis/apexremote
Referer: <the search page>     X-User-Agent: Visualforce-Remoting

{"action":"NH_ChildCareSearchClass","method":"retrieveAccountRecords",
 "data":[29 arguments],"type":"rpc","tid":2,
 "ctx":{"csrf":...,"vid":...,"ns":...,"ver":...,"authorization":...}}
```

The 29 arguments are empty strings or false except the fourth, which is
`"Residential Child Care Program"`. The exact list is in the sample script.

Row fields: `Id` (the account id), `Name`, `ShippingStreet`, `ShippingCity`,
`ShippingPostalCode`, `Phone`, `Capacity__c`, `License_Status__c`,
`Licensed__c`. 24 programs on 2026-10-01, all Active: Mountain Valley
Treatment Center, The Ridge RTC, seven Mount Prospect Academy (MPA) sites,
Spaulding Academy, The Wediko School, Pine Haven Boys Center, Seven Hills,
Easterseals Gammon Academy sites, Nashua Children's Home, and others.

### 2. A program's page

```
GET https://new-hampshire.my.site.com/nhccis/NH_childcaresearchaccountdetail?id=<account id>
```

Its "Licensing History" tab is a table: Review Date, Type of Review (for
example "Licensed Complaint Visit"), Level of Compliance (two numbers, for
example 97 / 98), View Detail, Visit Documents. Visit ids appear in the
page as `getNonComplianceItem('a4l...')`.

### 3. A visit's detail (ViewState postback)

POST back to the same URL with `AJAXREQUEST=_viewRoot`, the form id, the id
of the script component that defines `getNonComplianceItem`,
`selectedVisitId=<visit id>`, and the page's
`com.salesforce.visualforce.ViewState*` hidden fields. The response is about
260 KB of HTML holding: the licensor, the date of visit, the date the
corrective action was accepted, and, per domain (for example Medication
Services), every rule reviewed with Compliant or Non-Compliant. Each
non-compliance has an "Observations" narrative and the program's
"Corrective Action Plan" text. A saved example:
`visit_complaint_a4lcs000000znWrAAI_ajax.html`.

Each rule appears twice in the HTML (once in a hidden accessibility copy);
de-duplicate by rule number.

### 4. Older visit documents

A few visits carry a "Visit Documents" link instead (1 of 146 in research):
a Salesforce content-delivery link, downloaded with the same four-request
sequence as Ohio (`tmp/scraper-research/oh/sample_salesforce_content_delivery_download.py`,
host `new-hampshire.my.salesforce.com`). The one found was a two-page scan
with no text layer; archive it and link it, with OCR text only if it reads
cleanly.

Volume: 146 visits (2023: 9, 2024: 44, 2025: 60, 2026: 33).

## Decisions already made

- `state` = `NH`, page slug `nh-reports`, state file `.nh_state.json`
  holding, per account id, `{visit id: content hash}` and the last name
  seen.
- `facility_name` = `Name` with doubled spaces collapsed ("MPA at Campton:
  Depot Street and Owl Street"); `program_name` = the account `Id`;
  `program_category` = "Residential child care program"; `bed_capacity` =
  `Capacity__c`; `action` = `License_Status__c`; address and phone from the
  row. Do not store `Email__c`.
- `report_id` = the visit id. `report_date` = the review date, ISO.
  `report_url` = the program's page (a visit has no URL of its own).
- **Refresh, do not just skip**: a visit's corrective action plan and
  acceptance date arrive after the visit. Re-post a visit whose content hash
  changed, as `ok_scraper.py` does.
- **Keep the pages.** Save each program page and each visit-detail response,
  gzipped, in a Drive folder `nh_html/<account id>/`, as Oklahoma's scraper
  does with `ok_html/`, and give the scraper a `--from-saved` mode.
- `categories`: `visit_type`, `is_complaint`, `compliance {met, reviewed}`,
  `licensor`, `cap_accepted_date`, `items[] {rule, rule_text, domain,
  observations, corrective_action_plan}` (non-compliant rules only),
  `item_count`, `rules_reviewed`.
- **Flagged** = `item_count > 0`. A visit with every rule compliant is
  `clean`.
- `summary` = "Complaint visit: 2 of 98 rules not met (He-C 4001.15(ag),
  ...)".
- `raw_content` = the non-compliant items written out (rule, observations,
  plan). Small enough to load without lite mode.
- Observations name staff as "Staff A" and describe residents without
  names. The README's privacy check still runs on every visit.

## Build steps

1. **Probe**: run the two sample scripts; expect 24 programs.
2. **List and program pages** (25 requests), saved.
3. **Visit details** (about 146 postbacks), saved. ViewState is per page
   load: fetch the program page, then post its visits one after another, and
   re-fetch the page if a postback returns the page without the detail.
4. **Parser.** Print: visits per type, items per visit, visits where the
   compliance numbers and the parsed items disagree (reviewed minus met
   should equal the item count; list every mismatch), and every distinct
   visit type.
5. **Payload, state, `--out`, `--from-saved`**, then
   `php scripts/match-inspection-names.php --state=NH --file=<out.json>`.
6. **Adapter** `js/inspections/states/nh.js`. Closest model:
   `js/inspections/states/ok.js` (items with observation and plan,
   complaints apart). Filter: visit type. A note on the page: the state
   shows three years at a time.
7. **Registration** per README step 4 (no PDF archive folder unless visit
   documents are kept, in which case `nh_pdfs`), page via
   `kop_tool_page_specs()`.
8. **Owner sign-off, then post** per the README.
9. **Schedule**: monthly at the least; add to `scraper_launcher.py`.
10. **Severe findings** as a follow-up: the observations of non-compliant
    items on complaint visits.

## Acceptance checks

- A full `--no-post --out` run: 24 programs, about 146 visits, no parser
  mismatch left unexplained.
- `--from-saved` reproduces the same payload.
- A second run posts nothing; changing one stored hash re-posts exactly that
  visit.
- The page, fed the `--out` file, shows complaint visits apart with
  observations and plans. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post.
- Run the scraper monthly.

## Not in this plan

- The Office of the Child Advocate's reports
  (https://www.childadvocate.nh.gov/news-reports/reports): the list is drawn
  by JavaScript and was not read. Worth a manual look for facility-specific
  reports for the media library.
- Programs that closed before the three-year window, and the health
  facilities licence search, which is verification only.
