# South Dakota inspection scraper: build plan

Read [README.md](README.md) in this folder first. It holds the rules shared by
all the state plans: repos, what "done" means, payload rules, posting to
production, testing.

## Goal

Publish South Dakota's licensing studies and corrective action plans for
youth care providers at `https://kidsoverprofits.org/sd-reports/`, fed by a
new `sd_scraper.py`. South Dakota has 16 tracked facilities (13 open). The
state's licensing portal posts each provider's licensing studies,
inspections and any compliance or corrective action plan.

A small, easy build: 27 providers in scope, about 150 documents, dated 2024
to 2026.

## The source (verified 2026-10-01)

SD Department of Social Services, Office of Licensing and Accreditation,
public portal: https://olapublic.sd.gov/youth-care-provider-search/

Plain `requests` works; no login, CAPTCHA or bot wall.

### 1. List

```
GET https://olapublic.sd.gov/youth-care-provider-search/?search=true&providerType=Youth+Care
```

Server-rendered HTML, every provider in one response, one
`tr.provider-search-row` each: a link to the profile, address, phone and
category. 41 providers on 2026-10-01:

| Category | Count | In scope |
|---|---|---|
| Residential Treatment | 9 | yes |
| Shelter Care | 9 | yes |
| Group Care | 4 | yes |
| Independent Living | 4 | yes |
| Intensive Residential Treatment | 1 | yes |
| Child Placement Agency | 14 | no |

Do not add the page's `status=Operational` filter: it hides eight providers
that are in fact operational. The `status=Closed` filter does not appear to
return closed facilities either. Treat the list as current providers only,
keep every profile id in the state file, and never delete on our side.

### 2. A provider's profile

```
GET https://olapublic.sd.gov/youth-care-program-profile/<id>?phone=<digits>
```

Take the link from the list as given (the `phone` parameter is part of it).
Fields: Program Name, Program Category, Phone, Website, Physical Address,
Status, Total Capacity, minimum and maximum age, and capacity by sex. Then
a Documents section in four groups, each entry with a title, a type-and-date
line ("Residential Treatment Licensing Study - 06/08/2026") and a Download
link:

| Group | Documents on 2026-10-01 | Use |
|---|---|---|
| Program Certificate | 38 | skip (the certificate itself) |
| Inspections | 47 | post (fire and health inspection forms, safety inspections) |
| Licensing Studies | 96 | post |
| Compliance Plans and Corrective Action Plans | 13, across 11 providers | post, flagged |

### 3. A document

```
GET https://olapublic.sd.gov/api/mcase/attachments/<id>
```

Returns the PDF. All sampled were text PDFs.

Samples and the probe: `tmp/scraper-research/sd/` (`sd_probe.py`,
`attachment_164618.pdf` a licensing study, `attachment_130351_canyon_hills_cap_2025.pdf`
a corrective action plan).

## What the documents look like

- **Licensing study** (10 pages in the sample): numbered rule sections
  (licensing requirements, insurance, staff qualifications, staff to child
  ratio, physical facility, nutrition, and so on), each with Yes / No / N/A
  against the administrative rule and a comments paragraph; then a
  recommendation and signatures.
- **Corrective action plan** (3 pages): date issued, status, then per item
  the administrative rule, "Summary of Non-Compliance Finding", the
  corrective action, supporting evidence and how compliance will be
  maintained.
- **Inspections**: fire and health inspection forms and public safety
  inspections; formats vary and were not read closely.

There is no complaint or investigation section; the portal has only a form
for submitting complaints. A corrective action plan may follow a complaint,
but nothing says so. Do not label any document a complaint finding.

## Decisions already made

- `state` = `SD`, page slug `sd-reports`, state file `.sd_state.json`
  (seen-ID pattern keyed by profile id).
- Scope: the five categories marked above.
- `facility_name` = Program Name; `program_name` = `SD-<profile id>`;
  `program_category` = Program Category; `bed_capacity` = Total Capacity;
  `action` = Status; address and phone from the profile.
- `report_id` = the attachment id. `report_date` = the date on the entry's
  type-and-date line, ISO. `report_url` = the attachment URL.
- PDFs go through `ReportStore("SD_PDF_CACHE", "sd_pdfs", <local fallback>)`
  named `<attachment id>.pdf`; `'sd_pdfs' => 'sd'` in
  `api/sync-inspection-archive.php`; `archiveState: 'SD'`.
- `categories`: `kind` (`licensing_study`, `corrective_action_plan`,
  `inspection`, `other`), `title`, `group`;
  for a study: `not_met[] {section, rule, comment}` (sections answered No),
  `not_met_count`, `recommendation`;
  for a plan: `status`, `date_issued`, `items[] {rule, finding,
  corrective_action}`, `item_count`.
- **Flagged** = a corrective action plan, or a study with `not_met_count`
  above zero. A study with every section met is `clean`. Inspections are
  `neutral` unless their form states a failed item that can be read
  reliably.
- `raw_content` = the document text. Lite loading is optional at this size;
  use it if the total text passes about 2 MB.

## Build steps

1. **Probe**: `python tmp/scraper-research/sd/sd_probe.py` (41 providers,
   194 attachments).
2. **List and profiles** (about 30 requests).
3. **Download and extract** through `extract_with_cache`.
4. **Parser**, starting with the studies and plans. Print: documents per
   kind, studies with sections answered No, plan items, and inspection
   forms by title so their formats can be judged.
5. **Payload, state, `--out`**, then
   `php scripts/match-inspection-names.php --state=SD --file=<out.json>`.
6. **Adapter** `js/inspections/states/sd.js`. Closest models:
   `js/inspections/states/or.js` (licensing visit reports with labelled
   sections) and `js/inspections/states/id.js` if Idaho is built first.
   Filter: document kind.
7. **Registration** per README step 4, page via `kop_tool_page_specs()`.
8. **Owner sign-off, then post** per the README.
9. **Severe findings** as a follow-up: corrective action plan findings only.

## Acceptance checks

- A full `--no-post --out` run: 27 providers, about 150 documents, each with
  a kind.
- A second run downloads and posts nothing.
- The page, fed the `--out` file, flags plans and studies with unmet
  sections. Screenshots at 390, 768 and 1440 px looked at.

## Left for the owner

- Approve the first production post.

## Not in this plan

- The same portal's behavioral health provider search
  (https://olapublic.sd.gov/behavioral-health-provider-search/) was not
  explored; it may hold psychiatric residential programs with the same
  document groups. Look before closing the build.
