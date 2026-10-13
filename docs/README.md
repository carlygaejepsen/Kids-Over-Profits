# Kids Over Profits Documentation

This folder documents the Kids Over Profits child theme. Unless a document
says otherwise, it describes the live site at https://kidsoverprofits.org.
The repository root `CLAUDE.md` lists every build and test command; this page
says where to read about each part.

## Start here

- [Work plan](./PLAN.md): the one list of open work. How to work in this
  repository, what is waiting on the owner, and what to pick up next, area
  by area (network map, templates, inspections, name eras, imports, the
  mobile app, federal funding).
- [Adding content](./ADDING-CONTENT.md): checklists of every place to update
  when adding a data category, a facility field, a page, an admin screen or
  a review queue, so the new thing reaches search, the site map, open data,
  the app and the merge tools.
- [Fix plan, September 2026](./FIX-PLAN-2026-09.md): the record of the
  September fix list (items 1 to 20), what was built and why.
- [Repository map](./REPOSITORY-MAP.md): the two repositories (this theme
  and the Python scrapers), the main code areas, and every page template
  and generated route with its slug.
- [Page template design plan](./PAGE-TEMPLATE-DESIGN-PLAN.md) and
  [page classification](./PAGE-CLASSIFICATION.md): the template families
  and what became of every default-template page.
- [cPanel deployment guide](./CPANEL-DEPLOYMENT-GUIDE.md): how a push to
  `main` reaches production through `.cpanel.yml`. The deploy never deletes
  files, so a removed file needs its own `rm -f` task there.

## Facility data

- [Research guide](./RESEARCH-GUIDE.md): how to research a facility for its
  profile (names, address, timeline, owners, program type, staff, lawsuits,
  inspections, survivor accounts, libraries and archives).

- [Data model migration](./DATA-MODEL-MIGRATION.md): the move to one row per
  facility (`facilities_v2`). The write switch has been on since 2026-09-18.
  [alternate-names-gap-2026-09-18.csv](./alternate-names-gap-2026-09-18.csv)
  is the snapshot of past and other names the migration checked.
- [Facility schema (v2)](./FACILITY-SCHEMA.md): the canonical facility
  document. Every writer goes through `inc/facility-store.php`.
- [Data Forms](./data-forms/README.md): the admin form (`mode=admin`) and
  the public suggestion form (`mode=suggestions`) for facilities, operators,
  locations, referrers, transporters, mental health providers, marketing
  companies and trade organizations.
- [Admin Submissions](./admin-submissions/README.md): the review dashboard
  for wiki, news and data-form submissions.

## Public pages

- [TTI Program Index](./tti-program-index/README.md): the directory of
  operators and chains. Facility links go to `/facility/<slug>/` or a state
  hub's `?search=`, never to the program index.
- [State Inspection Reports](./state-inspection-reports/README.md): the
  `/xx-reports/` viewers. Every state except TX and CA (27 of them, from AR
  to WY, with an adapter each in `js/inspections/states/`) shares
  `js/inspections/report-page.js`; the slug list is in
  `kop_enqueue_report_scripts()` (`inc/enqueue.php`). TX and CA still use
  their older viewers. `/severe-reports/` lists the findings the highlights parser rated
  most serious (`inc/inspection-highlights.php`). The research behind each
  state's scraper, built or not, is in
  [state-inspection-reports/plans/](./state-inspection-reports/plans/).
- [Network Map](./NETWORK-MAP.md): the map at `/network-map/`, which went
  public on 2026-09-21. The document is its plan and a record of each phase.

Some public pages have no document of their own yet. Read the code listed
here, and the tests `CLAUDE.md` names for each one:

| Page | Code |
| --- | --- |
| `/facility/<slug>/` | `inc/facility-pages.php`, `templates/facility-page.php`; a renamed program is cut into one section per name by `inc/facility-eras.php` |
| `/operator/` and `/operator/<slug>/` | `inc/operator-pages.php`, `inc/operator-history.php`, `templates/operator-index.php`, `templates/operator-page.php` |
| Other directories (`/youth-transport-companies/`, `/mental-health-providers/`, `/marketing-companies/`, `/trade-organizations/`, `/referrers-educational-consultants/`) | `templates/page-transporter-index.php`, `page-provider-index.php`, `page-marketer-index.php` (`inc/marketers.php`), `page-tradeorg-index.php` (`inc/tradeorgs.php`), `page-referrer-index.php` |
| `/young-adult-programs/` | `inc/young-adult-programs.php`, `templates/page-young-adult-programs.php` |
| `/indian-boarding-schools/` | `inc/indigenous-schools.php`, `templates/page-indian-boarding-schools.php` (text in `js/data/pages/`, KOP Tools > Page Text) |
| Related industries outside the TTI (`/related-industries/`, `/foster-care/`, ...; drafts) | `templates/page-related-industry.php`, text in `js/data/pages/<slug>.json` |
| `/documents/` and `/documents/<type>/` | `inc/doc-types.php`, `inc/doc-types-admin.php`, `templates/document-types.php` |
| `/essential-reporting/` | `inc/featured-reporting.php`, `inc/featured-nominations.php`, `templates/featured-reporting.php` |
| `/send/` | `inc/send-page.php`, `templates/send-page.php`, `js/send-page.js` (the same route the browser extension and the app use, `inc/mobile-submit.php`) |
| `/site-map/` and `/how-to-use-this-site/` | `inc/site-map.php`, `inc/how-to-use.php`, `templates/site-map.php`, `templates/how-to-use.php` |
| `/open-data/` | `inc/open-data.php`, `templates/page-open-data.php` |
| `/faq/` | `templates/page-faq.php`, text in `js/data/pages/faq.json` (`inc/page-text.php`) |
| State and country hubs | `templates/page-state.php`, `templates/page-country.php`, `inc/rest-api.php`, `inc/country-rest-api.php` |
| `/report-abuse/` | `inc/reporting-directory.php`, data in `js/data/reporting/` (schema in its `README.md`) |
| `/glossary/` | `inc/glossary.php`, `inc/glossary-feedback.php`, data in SQL tables (`inc/glossary-store.php`), edited at KOP Tools > Glossary Editor |
| Lawsuits and legislation | `templates/page-lawsuits.php`, `templates/page-legislation.php` and their admin and submit templates |
| Research library and resources | `inc/research-library.php`, `inc/resources-list.php` |
| Hubs and long-form articles | `templates/page-hub.php`, `templates/page-article.php`, `inc/hub-shell.php`, `inc/article-parts.php`, `inc/article-pieces.php`; pull quotes `inc/pull-quotes.php` (an admin selects words on the page and presses "Pull quote") |

## Review and admin tools

These have no document of their own either; the header comment of each file
says how it works, and `CLAUDE.md` names its test.

| Tool | Code |
| --- | --- |
| Review inbox (every approval queue on the Submissions Review page, Recently done with Undo, Conflicts, volunteer reviewers) | `inc/review-inbox.php`, `inc/review-inbox/<source>.php`, `inc/review-inbox-log.php`, `inc/review-volunteers.php`, `js/review-inbox.js` |
| Edit in place (the admin pencil on pages, pull quotes, document placement) | `inc/inline-edit.php`, `js/inline-edit.js`, `inc/doc-placement.php` |
| Merge Duplicates, renamed programs, Program Homes | `inc/facility-merge.php`, `inc/facility-merge-match.php`, `inc/program-homes.php`, `inc/program-homes-convert.php` |
| People (person ids, Merge People) | `inc/people.php`, `inc/people-merge.php` |
| Imports (Woodbury Reports, Fornits, Drive Docs, State Lists, data audit, federal funding) | `inc/woodbury-mentions.php`, `inc/woodbury-facts.php`, `inc/fornits.php`, `inc/drive-docs.php`, `inc/state-lists.php`, `inc/data-audit.php`, `inc/federal-funding.php`, `inc/auto-connect.php` |
| Hourly and nightly scans (closures, new facilities, bill and lawsuit statuses, plain summaries, news pictures) | `inc/closure-reports.php`, `inc/facility-discovery.php`, `inc/status-checks.php`, `inc/highlight-summaries.php`, `inc/news-images.php`; WP-Cron runs from the server's crontab (`inc/server-cron.php`) |
| Mobile app and browser extension API | `inc/mobile-api.php`, `inc/mobile-submit.php`, `inc/source-submissions.php`, `inc/extension-news-processor.php`, `browser-extension/send-to-kop/` |

## Content tools

- [News Processor](./news-processor/README.md): AI-assisted intake of news
  articles, from extraction and review through to submission. Nightly article
  discovery runs as `scripts/discover-articles.php` on the NixiHost cron.
- [Wiki Editor](./wiki-editor/README.md): editing, importing and browsing
  structured wiki entries.
- [Anonymous Portal](./anonymous-portal/README.md): anonymous document upload
  for whistleblowers, survivors and staff (the `[anonymous_doc_portal]`
  shortcode, `inc/features.php`).

## Document library

These guides are for editors placing documents on WordPress pages:

- [Simple guide](./DOCUMENT-LIBRARY-SIMPLE-GUIDE.md)
- [Shortcodes cheat sheet](./SHORTCODES-CHEAT-SHEET.md)
- [Copy-paste templates](./COPY-PASTE-TEMPLATES.md)
- [Visual examples](./VISUAL-EXAMPLES.md)

Per-organization library pages use `templates/page-document-folder.php`.
Every document also has a type (handbook, inspection report, legal filing,
news clipping...), and `/documents/<type>/` lists every document of one type
side by side across programs (`inc/doc-types.php`); set types with the pencil
on a document tile or at KOP Tools > Document Types.

## Where the code lives

- `functions.php` only loads the runtime, which lives in `inc/*.php`.
- `templates/` holds every page template. The theme root has no `page-*.php`
  files now. `kop_template_assignments()` in `inc/admin.php` assigns
  templates to pages by slug, and `inc/redirects.php` holds the 301s for
  retired slugs.
- `api/` holds the procedural endpoints: CRUD, submissions, inspections,
  imports and one-off admin fixes (run from the browser as an admin, usually
  with `?apply=1`).
- `js/` and `css/` hold page scripts and styles. `js/data/` holds the
  generated JSON (the program aggregate, the network graph, the reporting
  directory) and reviewed inputs such as the data audit and federal funding
  proposals, page text (`js/data/pages/`) and the Unsilenced and survivor
  archive link lists. The glossary is in SQL tables; `js/data/glossary/glossary.md` is
  only the source of its one-time import.
- `scripts/` holds the data pipelines, scrapers and offline test harnesses.
  Most PHP tests render against `tmp/prod.sqlite`, a mirror of production
  made by `scripts/sync-prod-sqlite.py`.
- `seeds/` holds records that are applied on deploy: facility records, draft
  posts, lawsuits and legislation, text fixes, operator aliases and
  histories, marketers and trade organizations. Each seed applies once per
  version, so a change ships with a version bump.
- `browser-extension/send-to-kop/` is the "Send to KOP" extension (loaded
  unpacked or packaged by `package.ps1`, never deployed); the mobile app is
  its own repository, `kids-over-profits-mobile`.

## Conventions

- Work lands on `main`, and a push to `main` deploys.
- Page text always sits on a solid panel, never directly on the gradient
  background. Run `python scripts/check-bare-text.py` after any change to a
  template or page CSS.
- Program and organization websites link to the Wayback Machine first, and to
  the live site only through `/go/`.
- The repository is public. Never commit `.env`, `api/config.local.php` or
  source PDFs of articles.
