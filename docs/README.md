# Kids Over Profits Documentation

This folder documents the Kids Over Profits child theme. Unless a document
says otherwise, it describes the live site at https://kidsoverprofits.org.
The repository root `CLAUDE.md` lists every build and test command; this page
says where to read about each part.

## Start here

- [Handoff, 2026-09-24: portal, inspections, AI providers](./HANDOFF-2026-09-24-portal-inspections.md):
  the anonymous portal's encryption and keys, the FL/NC lite report lists,
  the AI model updates, what is waiting on the owner and the open work.
- [Handoff, 2026-09-24: network map](./HANDOFF-2026-09-24-network-map.md):
  the next two network map features (route highlight, list view).
- [Handoff, 2026-09-18](./HANDOFF-2026-09-18.md): orientation for a new
  contributor or agent, and pointers to the working plans.
- [Fix plan, September 2026](./FIX-PLAN-2026-09.md): the September fix list
  (items 1 to 20) with a status table and a "Waiting on the owner" section
  for production actions only the site owner can run.
- [cPanel deployment guide](./CPANEL-DEPLOYMENT-GUIDE.md): how a push to
  `main` reaches production through `.cpanel.yml`. The deploy never deletes
  files, so a removed file needs its own `rm -f` task there.

## Facility data

- [Data model migration](./DATA-MODEL-MIGRATION.md): the move to one row per
  facility (`facilities_v2`). The write switch has been on since 2026-09-18.
- [Facility schema (v2)](./FACILITY-SCHEMA.md): the canonical facility
  document. Every writer goes through `inc/facility-store.php`.
- [Data Forms](./data-forms/README.md): the admin form (`mode=admin`) and
  the public suggestion form (`mode=suggestions`) for facilities, operators,
  locations and referrers.
- [Admin Submissions](./admin-submissions/README.md): the review dashboard
  for wiki, news and data-form submissions.

## Public pages

- [TTI Program Index](./tti-program-index/README.md): the directory of
  operators and chains. Facility links go to `/facility/<slug>/` or a state
  hub's `?search=`, never to the program index.
- [State Inspection Reports](./state-inspection-reports/README.md): the
  `/xx-reports/` viewers. AR, AZ, CT, FL, GA, MN, MT, NC, NV, OR, UT and WA
  share `js/inspections/report-page.js`. TX and CA still use their older
  viewers. `/severe-reports/` lists the findings the highlights parser rated
  most serious (`inc/inspection-highlights.php`).
- [Network Map](./NETWORK-MAP.md): the map at `/network-map/`, which went
  public on 2026-09-21. The document is its plan and a record of each phase.

Some public pages have no document of their own yet. Read the code listed
here, and the tests `CLAUDE.md` names for each one:

| Page | Code |
| --- | --- |
| `/facility/<slug>/` | `inc/facility-pages.php`, `templates/facility-page.php` |
| `/operator/<slug>/` | `inc/operator-pages.php`, `templates/operator-page.php` |
| State and country hubs | `templates/page-state.php`, `templates/page-country.php`, `inc/rest-api.php`, `inc/country-rest-api.php` |
| `/report-abuse/` | `inc/reporting-directory.php`, data in `js/data/reporting/` (schema in its `README.md`) |
| `/glossary/` | `inc/glossary.php`, `inc/glossary-feedback.php`, source in `js/data/glossary/glossary.md` |
| Lawsuits and legislation | `templates/page-lawsuits.php`, `templates/page-legislation.php` and their admin and submit templates |
| Research library and resources | `inc/research-library.php`, `inc/resources-list.php` |
| Hubs and long-form articles | `templates/page-hub.php`, `templates/page-article.php`, `inc/article-parts.php`, `inc/article-pieces.php` |

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
  generated JSON: the program aggregate, the network graph, the reporting
  directory and the glossary.
- `scripts/` holds the data pipelines, scrapers and offline test harnesses.
  Most PHP tests render against `tmp/prod.sqlite`, a mirror of production
  made by `scripts/sync-prod-sqlite.py`.
- `seeds/` holds records that are applied on deploy: facility records, draft
  posts, lawsuits, text fixes and operator aliases.

## Conventions

- Work lands on `main`, and a push to `main` deploys.
- Page text always sits on a solid panel, never directly on the gradient
  background. Run `python scripts/check-bare-text.py` after any change to a
  template or page CSS.
- Program and organization websites link to the Wayback Machine first, and to
  the live site only through `/go/`.
- The repository is public. Never commit `.env`, `api/config.local.php` or
  source PDFs of articles.
