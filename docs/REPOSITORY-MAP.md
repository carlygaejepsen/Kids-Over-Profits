# Kids Over Profits Repository Map

Baseline: 2026-09-24. Templates, routes and the tools repository's scrapers
checked against the code on 2026-10-13.

This workspace contains two related repositories. The web repository is the
WordPress child theme and application source. The tools repository contains
the Python scrapers that collect inspection data and post it to the web
repository's API.

## Repository Relationship

```text
Kids-Over-Profits-Tools
  state scrapers and inspection utilities
             |
             | HTTPS JSON writes
             v
Kids-Over-Profits
  WordPress child theme, APIs, templates, datasets, and documentation
             |
             | deployed through cPanel / GitHub Actions
             v
  kidsoverprofits.org
```

## Web Repository

Location: `c:\Users\daniu\source\repos\Kids-Over-Profits`

Purpose: standalone Kadence child theme for kidsoverprofits.org. It is not a
complete WordPress installation; WordPress and the Kadence parent theme are
provided by the hosting environment.

### Main areas

| Area | Responsibility | Starting points |
| --- | --- | --- |
| `functions.php` | Thin theme bootstrap | `functions.php` |
| `inc/` | Runtime modules, routing, database access, REST APIs, admin glue, feature renderers | `inc/enqueue.php`, `inc/admin.php`, `inc/rest-api.php`, `inc/template-layout.php` |
| `templates/` | Selectable PHP page templates and generated profile views | `templates/page-*.php`, `templates/facility-page.php`, `templates/operator-page.php` |
| `api/` | Procedural CRUD, submission, inspection, diagnostic, and maintenance endpoints | `api/` |
| `css/` | Shared palette, layout, component, and page-specific styles | `css/colors.css`, `css/` |
| `js/` | Vanilla JavaScript page entry points and shared modules | `js/inspections/`, `js/data-form/`, `js/network-map/` |
| `js/data/` | Packaged and generated datasets used as API fallbacks or page sources | `js/data/` |
| `scripts/` | Data builders, migration helpers, offline render tests, and production mirror tools | `scripts/` |
| `seeds/` | Curated content and data applied during deployment | `seeds/` |
| `docs/` | Architecture, workflow, handoff, and test documentation | `docs/README.md` |
| `wp-plugins/kop-tools/` | Optional admin dashboard for theme API tools and front-end admin pages | `wp-plugins/kop-tools/kop-tools.php` |
| `tmp/` | Local production mirror, snapshots, previews, and test output; mostly ignored | `tmp/prod.sqlite` |

### Important runtime flows

- Facilities and operators: `facilities_v2` data is rendered by the facility
  and operator route handlers into [facility-page.php](../templates/facility-page.php)
  and [operator-page.php](../templates/operator-page.php).
- Public and admin data forms: the page templates load the modular form stack
  and write through the endpoints in `api/`.
- Inspection reports: tools post normalized state data to
  `api/inspections-write.php`; pages read it through
  `api/inspections-read.php` and render it with state adapters.
- Program datasets: source files under `js/data/reddit-wiki/` are aggregated
  by `scripts/aggregate-all-programs.js`.
- Network map: source CSVs and overrides under `js/data/network/` produce the
  graph consumed by `js/network-map/`. `inc/network-rebuild.php` notices when
  the data the build reads has changed and starts
  `.github/workflows/build-network-map.yml`, which rebuilds, tests, commits
  and deploys `graph.json`.
- Review: every approval queue (news, lawsuits, bills, imports, AI drafts,
  merges, document types) is one source in `inc/review-inbox/` shown on the
  Submissions Review page (`inc/review-inbox.php`). Approving calls the
  queue's own apply function; Recently done keeps an exact Undo.
- People: everyone named in the records has a `{prefix}kop_people` id
  (`inc/people.php`); facility pages, company pages and the map read it.
- Mobile app and browser extension: the app
  (`kids-over-profits-mobile`) reads `kop/v1/facility`, `/operator`, `/news`
  and `/resources` from `inc/mobile-api.php`; the app's Send tab, the
  "Send to KOP" extension (`browser-extension/send-to-kop/`) and `/send/`
  post to `inc/mobile-submit.php`, and signed-in reviewers use
  `kop/v1/extension/*` (`inc/source-submissions.php`).

## Page Templates and Routes

Every template is in `templates/`. `kop_template_assignments()` in
`inc/admin.php` assigns templates to existing pages by slug, and
`kop_tool_page_specs()` there creates the pages a template needs when they
are missing. The slugs below come from `tmp/prod.sqlite` (synced 2026-10-12)
and those two functions.

### Pages that use a template

| Template | Pages (slug) |
| --- | --- |
| `page-home.php` | the home page (`kids-over-profits`) |
| `page-hub.php` | the 14 hubs (`history`, `survivors`, `researchreports`, `families`, `where-are-the-kids`, `advocates`, `journalists`, `volunteer`, `law-policy`, `resources`, `support`, `editorials`, `investigatory-spotlight`, `reputation-management`), each built from `kop_hub_config()` in `inc/hub-shell.php`; `support` stays published but 301s to `/donate/` |
| `page-article.php` | long-form articles (39), each placed under a hub by `kop_article_parents()` |
| `page-state.php`, `page-country.php` | the 50 state hubs and 13 country hubs |
| `page-state-reports.php` | the `/xx-reports/` inspection trackers (29 pages) |
| `page-inspection-reports.php`, `page-severe-reports.php` | `inspection-reports`, `severe-reports` |
| `page-tti-program-index.php` | `tti-program-index` (by parent company, and by location at `?view=location`) |
| `page-location-index.php` | `location-index`, which 301s to `/tti-program-index/?view=location` (`inc/redirects.php`) |
| `page-referrer-index.php` | `referrers-educational-consultants` |
| `page-transporter-index.php` | `youth-transport-companies` |
| `page-provider-index.php` | `mental-health-providers` |
| `page-marketer-index.php` | `marketing-companies` (`inc/marketers.php`) |
| `page-tradeorg-index.php` | `trade-organizations` (`inc/tradeorgs.php`) |
| `page-young-adult-programs.php` | `young-adult-programs` (`inc/young-adult-programs.php`) |
| `page-indian-boarding-schools.php` | `indian-boarding-schools` (`inc/indigenous-schools.php`; text in `js/data/pages/indian-boarding-schools.json`) |
| `page-related-industry.php` | `related-industries`, `foster-care`, `disability-group-homes`, `nursing-homes`, `psychiatric-hospitals`; drafts until the owner publishes them, text in `js/data/pages/<slug>.json` |
| `page-faq.php` | `faq` (text in `js/data/pages/faq.json`) |
| `page-glossary.php` | `glossary` |
| `page-report-abuse.php` | `report-abuse` |
| `page-memorial.php` | `in-loving-memory` |
| `page-network-map.php` | `network-map` |
| `page-news-feed.php` | `tti-news-feed` |
| `page-wiki-feed.php` | `reddit-wiki`, `r-troubledteens-wiki-backup` |
| `page-lawsuits.php`, `page-legislation.php` | `lawsuits`, `legislative-efforts` |
| `page-submit-lawsuit.php`, `page-submit-legislation.php` | `submit-lawsuit`, `submit-legislation` |
| `page-data.php` | `tti-data-submission` (the public data form) |
| `page-document-archive.php` | `document-archive` |
| `page-document-folder.php` | the five `document-library-*` pages |
| `page-open-data.php` | `open-data` |
| `page-utility.php` | `contact`, `links`, `anon-submit`, `donate`, `no-access` |
| `page-legal-document.php` | `richardson-v-elevations-rtc-prelitigation-panel-opinion` |
| `page-privacy-policy.php`, `page-terms-of-service.php` | `privacy-policy`, `terms-of-service` |
| `page-volunteers.php` | the volunteer projects page |
| `single-facility-profile.php` | the 16 hand-written facility profile pages (`hyde`, `elan`, ...); a profile listed in `kop_facility_pages_merged_profiles()` 301s to its `/facility/` page |
| `single-person-profile.php` | personnel profile pages (49, drafts) |
| Admin screens | `page-admin-data.php` (`admin-data`), `page-admin-data-manager.php` (`data-manager`), `page-admin-submissions.php` (`admin-submissions`), `page-admin-lawsuits.php`, `page-admin-legislation.php`, `page-admin-volunteers.php`, `page-news-processor.php` (`news-processor`), `page-wiki-editor.php` (`wiki-editor`) |

`data-form-admin.php`, `data-form-public.php`, `data-form-marketer.php` and
`data-form-tradeorg.php` are parts the data form templates include.

### Generated routes

These have no WordPress page. Each module adds a rewrite rule and renders
its own template.

| Route | Module | Template |
| --- | --- | --- |
| `/facility/<slug>/` | `inc/facility-pages.php` | `facility-page.php` |
| `/operator/` and `/operator/<slug>/` | `inc/operator-pages.php`, `inc/operator-history.php` | `operator-index.php`, `operator-page.php` |
| `/documents/` and `/documents/<type>/` | `inc/doc-types.php` | `document-types.php` |
| `/essential-reporting/` | `inc/featured-reporting.php` | `featured-reporting.php` |
| `/send/` | `inc/send-page.php` | `send-page.php` |
| `/site-map/` (`/sitemap/` 301s here) | `inc/site-map.php` | `site-map.php` |
| `/how-to-use-this-site/` | `inc/how-to-use.php` | `how-to-use.php` |
| `/volunteer-review/` | `inc/review-volunteers.php` | `volunteer-review.php` |

## Tools Repository

Location: `c:\Users\daniu\OneDrive\Documents\GitHub\Tools\Kids-Over-Profits-Tools`

Purpose: Python collection and normalization tools for state inspection and
citation data. The tools repository does not render WordPress pages.

### Main areas

| Area | Responsibility | Starting points |
| --- | --- | --- |
| `*_scraper.py` | State-specific source collection and normalization | One per state, `<st>_scraper.py`: AR, AZ, CA, CO, CT, FL, GA, HI, IA, ID, MD, ME, MI, MN, NC, NH, NV, OH, OK, OR, PA, SD, TX, VA, WA, WV, WY. Montana's reports are downloaded by `mt_dl.py`; Utah's and Arizona's citations by `utah_citation_scraper.py` and `az_citation_scraper.py` |
| Other collectors | Referrer and NATSAP lists | `edcon_scraper.py`, `natsap_edcon_scraper.py`, `natsap_program_scraper.py`, `ther_con_scraper.py` |
| `report_store.py` | Saves each scraped report PDF to the FileBird Google Drive folder and extracts its text once | `report_store.py` |
| `inspection_api_client.py` | Shared payload construction, batching, retries, and API writes | `inspection_api_client.py` |
| `scraper_state.py` | Incremental seen-ID and cursor state helpers | `scraper_state.py` |
| `scraper_launcher.py` | Desktop launcher and scraper registry | `scraper_launcher.py` |
| `kop_paths.py` | Locates the separate web repository and Google Drive report folders | `kop_paths.py` |
| `test_scrapers.py` | Scraper unit and parser tests | `test_scrapers.py` |
| `STATE_SCRAPER_GUIDE.md` | Shared inspection schema and per-state implementation notes | `STATE_SCRAPER_GUIDE.md` |
| Local caches and state files | Incremental state, PDFs, OCR, and browser artifacts | Gitignored runtime files |

### Data flow

```text
State agency or licensing source
        |
        v
Python scraper -> normalized facilities/reports
        |
        v
inspection_api_client.py -> web repo api/inspections-write.php
        |
        v
MySQL inspection_facilities + inspection_reports
        |
        v
WordPress REST read endpoint -> PHP template + JavaScript renderer
```

## Page Template Baseline (2026-09-24)

This section and the next record the inventory as it stood on 2026-09-24.
Every page in them has since been decided and handled; the outcome for each
is in [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md).

The local production mirror is `tmp/prod.sqlite`. It contains 182 page
records in draft, private, or published status.

| Page status | Assigned child template | Count |
| --- | --- | ---: |
| Published | Child templates | 152 |
| Published | Default page template | 23 |
| Private | Child templates | 3 |
| Draft | Default page template | 4 |

The 23 published pages currently using the default page template are:

| Title | Slug | Initial classification |
| --- | --- | --- |
| A Survivor's Guide to Legal Action Against Troubled Teen Industry Programs | `a-survivors-guide-to-legal-action-against-troubled-teen-industry-programs` | Candidate article template |
| Anonymous Document Submission | `anon-submit` | Candidate dedicated portal template |
| Document Archive | `document-archive` | Candidate document-library template |
| Doe v. Hyde Complaint | `doe-v-hyde-complaint` | Candidate legal-document template |
| Doe v. Hyde Complaint Amended | `doe-v-hyde-complaint-amended` | Candidate legal-document template |
| Donate | `donate` | Likely intentionally plugin/content-driven; verify styling |
| Troubled Teen Industry Educational Consultants and Referrers | `edcons` | Candidate directory/template |
| Editorials | `editorials` | Candidate hub or article template |
| International | `international` | Candidate hub template |
| Investigatory Spotlight | `investigatory-spotlight` | Candidate hub or article template |
| Links | `links` | Candidate resource/list template |
| News | `news` | Candidate news archive/feed template |
| No Access | `no-access` | Candidate access/error template |
| Overt and Covert Conversion Therapy Practices in Therapeutic Boarding Schools | `overt-and-covert-conversion-therapy-practices-in-therapeutic-boarding-schools` | Candidate article template |
| Overview | `overview` | Candidate hub template |
| Richardson Complaint | `richardson-complaint` | Candidate legal-document template |
| Richardson V. Elevations RTC Prelitigation Panel Opinion | `richardson-v-elevations-rtc-prelitigation-panel-opinion` | Candidate legal-document template |
| Shiver v. Southstone complaint | `shiver-v-southstone-complaint` | Candidate legal-document template |
| Shiver V. Southstone Motion for Default Judgement | `shiver-v-southstone-motion-for-default-judgement` | Candidate legal-document template |
| Shiver v. Southstone Summons | `shiver-v-southstone-summons` | Candidate legal-document template |
| Trinity Teen Solutions/ Trinity Cross Ranch | `trinity-teen-solutions-trinity-cross-ranch` | Candidate facility/profile template |
| Trinity Teen/Trinity cross complaint | `trinity-teen-trinity-cross-complaint` | Candidate legal-document template |

The mirror also contains 102 published posts without a specialized child
template, including news, legal records, and research content. Twelve
published posts use `single-facility-profile.php`. Posts are a separate work
stream from WordPress pages and should be audited after the default pages.

## Redundancy and Retirement Flags

These are review flags, not deletion instructions. The strongest evidence is
an existing entry in [inc/redirects.php](../inc/redirects.php), because those
URLs already redirect visitors elsewhere. The records should be moved to the
WordPress trash only after checking inbound links, media references, and
search-console data. Trash is preferable to permanent deletion because it
preserves recovery and ID history.

### High-confidence retirement candidates

These published page records already have explicit 301 destinations:

| Page slug | Existing destination | Reason |
| --- | --- | --- |
| `a-survivors-guide-to-legal-action-against-troubled-teen-industry-programs` | Uploaded PDF | Former single-file document page |
| `doe-v-hyde-complaint` | Uploaded PDF | Former single-file court document page |
| `doe-v-hyde-complaint-amended` | Uploaded PDF | Former single-file court document page |
| `edcons` | `/referrers-educational-consultants/` | Replaced by the referrer index |
| `international` | `/tti-program-index/?view=location&type=country` | Replaced by the location index (now a tab of the program index) |
| `overt-and-covert-conversion-therapy-practices-in-therapeutic-boarding-schools` | Uploaded PDF | Former single-file document page |
| `richardson-complaint` | Uploaded PDF | Former single-file court document page |
| `shiver-v-southstone-complaint` | Uploaded PDF | Former single-file court document page |
| `shiver-v-southstone-motion-for-default-judgement` | Uploaded PDF | Former single-file court document page |
| `shiver-v-southstone-summons` | Uploaded PDF | Former single-file court document page |
| `trinity-teen-solutions-trinity-cross-ranch` | Uploaded PDF | Former single-file court document page |
| `trinity-teen-trinity-cross-complaint` | `/lawsuits/` | Former single-file court document page |

### Likely duplicate or legacy pages

| Page slug | Signal | Recommended next step |
| --- | --- | --- |
| `news` | Default-template page named News exists beside the templated `tti-news-feed` page | Compare content and inbound links; likely redirect or convert to the news-feed template |
| `document-archive` | Older generic document page exists alongside the newer document-library and document-folder system | Inspect content and menu usage before choosing a canonical archive page |
| `editorials` | Generic editorial landing page has no child template | Compare its links and content with hub/article navigation; likely needs a hub template or retirement |
| `investigatory-spotlight` | Generic landing page exists while related spotlight content is represented by posts/articles | Check whether it is a live landing page or an obsolete shell |
| `overview` | Generic page with older overview content | Compare against the home page and hub pages before assigning a template |
| `links` | Large generic link collection | Decide whether it belongs in the resources hub or should become a dedicated styled resource template |
| `international` | Also listed above as a high-confidence retirement candidate | Remove from the active-page inventory after confirming the redirect is deployed |

### Pages to preserve pending review

These may be intentionally generic or plugin/content-driven and should not be
deleted based on the template field alone:

- `anon-submit`: likely contains the anonymous document portal shortcode.
- `contact`: contact content may be intentionally simple.
- `donate`: likely depends on Givebutter or embedded donation markup.
- `no-access`: may be an access-denied destination used by redirects or forms.
- Legal-document pages not covered by the redirect map should be checked for
  live links and attachments before any change.

### Retirement workflow

1. Confirm each redirect responds with the intended `301` on production.
2. Search menus, content, feeds, and database URLs for inbound references.
3. Confirm any PDFs or document attachments remain reachable independently.
4. Move only confirmed obsolete page records to Trash, never hard-delete first.
5. Keep the redirect map permanently and add a note explaining the canonical
  destination.
6. Re-run the page inventory so the default-template count reflects the
  cleanup rather than silently losing records.

### Step-one conclusion

The first conversion queue is the 11 remaining default-template pages that
are not already explicitly retired by the redirect map. The 12 redirected
records should be reviewed for trashing first. The next queue is the 102
published posts without a specialized template. Before
implementing either queue, classify each item by intended experience:
article, hub, directory, legal document, donation/plugin page, access/error
page, or intentional generic content.

## Relevant Commands

From the web repository:

```text
python scripts/check-bare-text.py
php scripts/test-facility-pages.php
php scripts/test-operator-pages.php
php scripts/test-site-map.php
node scripts/test-network-modules.js
```

`CLAUDE.md` at the repository root lists every other build and test
command.

From the tools repository:

```text
python test_scrapers.py
python scraper_launcher.py
```

The tools repository should use `KOP_REPO_DIR` when the web repository is not
at its discovered canonical location. Production credentials and API keys are
not documented in this map.