# Kids Over Profits Repository Map

Baseline: 2026-09-24

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
  graph consumed by `js/network-map/`.

## Tools Repository

Location: `c:\Users\daniu\OneDrive\Documents\GitHub\Tools\Kids-Over-Profits-Tools`

Purpose: Python collection and normalization tools for state inspection and
citation data. The tools repository does not render WordPress pages.

### Main areas

| Area | Responsibility | Starting points |
| --- | --- | --- |
| `*_scraper.py` | State-specific source collection and normalization | `ar_scraper.py`, `az_scraper.py`, `ca_scraper.py`, `ct_scraper.py`, `fl_scraper.py`, `ga_scraper.py`, `mn_scraper.py`, `nc_scraper.py`, `nv_scraper.py`, `or_scraper.py`, `tx_scraper.py`, `wa_scraper.py` |
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

## Page Template Baseline

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
| `international` | `/location-index/?type=country` | Replaced by the location index |
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
node scripts/test-network-modules.js
```

From the tools repository:

```text
python test_scrapers.py
python scraper_launcher.py
```

The tools repository should use `KOP_REPO_DIR` when the web repository is not
at its discovered canonical location. Production credentials and API keys are
not documented in this map.