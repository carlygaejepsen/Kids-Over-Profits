# Kids Over Profits Child Theme

This repository is the source for the Kids Over Profits WordPress child theme (Kadence parent) that runs https://kidsoverprofits.org, a research database on the "Troubled Teen Industry" (TTI). Treat it as the theme codebase itself, not as a full local WordPress checkout.

## Open Source, Open Data

Kids Over Profits is open source. Advocates, researchers, journalists and survivors are welcome to reuse it.

- **Code**: GNU General Public License v2 or later, see [LICENSE](LICENSE).
- **Data and writing**: Creative Commons Attribution-ShareAlike 4.0 (CC BY-SA 4.0), see [DATA-LICENSE.md](DATA-LICENSE.md). Credit "Kids Over Profits (https://kidsoverprofits.org)" and share what you build from it under the same license.
- **Bulk data**: daily CSV/JSON downloads of every public dataset at https://kidsoverprofits.org/open-data/ (`inc/open-data.php`).
- **Source**: https://github.com/carlygaejepsen/Kids-Over-Profits

## Repo Assumptions

- The repo can be mounted into any compatible WordPress install.
- Do not assume a fixed local path such as `app/public/wp-content/themes/child/`.
- Use WordPress path helpers and localized config objects instead of hard-coded machine paths.
- No build step: plain PHP, vanilla JavaScript loaded by `wp_enqueue_script` dependencies, plain CSS.

## Layout

- `functions.php` is a thin loader; the theme's logic lives in `inc/*.php` (one file per subsystem).
- `api/` holds procedural endpoints (data forms, submissions, inspections, AI processing, server CLI jobs).
- `templates/` holds every page template and the generated-page templates (`facility-page.php`, `operator-page.php`, `site-map.php`, ...). There are no root `page-*.php` files any more.
- `js/`, `css/`: page scripts and styles (`css/colors.css` is the palette).
- `scripts/`: data pipelines, importers, scrapers and the offline test scripts (`scripts/test-*.php`, most run against the `tmp/prod.sqlite` mirror).
- `seeds/`: data shipped with a deploy and applied once per version.
- `wp-plugins/kop-tools/`: the KOP Tools admin menu. `browser-extension/send-to-kop/`: the "Send to KOP" extension.
- `docs/`: workflow docs; `docs/PLAN.md` is the single list of open work. `CLAUDE.md` lists every subsystem's commands and tests.

## Major Subsystems

### Facility records
- Facilities live in `facilities_v2` (one JSON document per facility, schema in `docs/FACILITY-SCHEMA.md`) with parent companies in `{prefix}kop_operators`. The legacy `facilities_master` table is frozen.
- Admins edit through the admin data form (`api/save-master.php`); the public suggests through `/tti-data-submission/` (guided start, `api/save-suggestion.php` into `suggested_edits`).
- Other record categories: referrers/educational consultants, transporters, mental health providers (`providers_master`), marketing companies (`marketers_master`), trade organizations (`tradeorgs_master`), young adult programs (`young_adult_programs`) and Indigenous residential schools (`indigenous_schools`), each kept out of the facility tables. See `docs/data-forms/README.md`.
- Every named person has an id (`{prefix}kop_people`, `inc/people.php`). Duplicates are merged (Merge Duplicates, Merge People), homes grouped under programs (Program Homes), renamed programs kept by name era (`inc/facility-eras.php`).

### Public pages
- Generated `/facility/<slug>/` and `/operator/<slug>/` pages (`inc/facility-pages.php`, `inc/operator-history.php`), the facility directory at `/tti-program-index/` (company and location tabs, `inc/directory-feed.php`), state and country hubs, the network map (`/network-map/`, `js/network-map/`), the glossary, the reporting directory (`/report-abuse/`), the memorial, lawsuits and legislation trackers, document libraries and document types (`/documents/<type>/`), essential reporting (`/essential-reporting/`), hub and article pages with pull quotes, the reader's site map and `/how-to-use-this-site/`.
- Admins edit most of it in place with a pencil (`inc/inline-edit.php`).

### State inspection reports
- One `/xx-reports/` page per state: a shared engine (`js/inspections/report-page.js`) with one adapter per state in `js/inspections/states/`, data from `api/inspections-read.php` (`inspection_facilities`, `inspection_reports`). See `docs/state-inspection-reports/README.md`.
- Serious findings are picked out (`inspection_highlights`), given approved plain-language summaries (`inspection_highlight_summaries`) and counted into rankings (`inspection_report_counts`).

### Review and imports
- One review inbox on the Submissions Review page (`inc/review-inbox.php`, a source file per queue in `inc/review-inbox/`) with Undo, conflicts, volunteer reviewers and follow-up emails.
- Feeds into it: news (processor, discovery, closures, facilities from the news), the bill and lawsuit status check, Woodbury Reports, Fornits, Drive Docs, State Lists, the data audit, federal funding, wiki updates, document types and the Send to KOP extension / `/send/` page / mobile app.
- AI calls alternate between Groq and Gemini through `kop_ai_generate_alternating()` (`api/ai-providers.php`).

### Mobile app
- The app (repo `kids-over-profits-mobile`) reads `kop/v1` routes from `inc/mobile-api.php`, submits through `inc/mobile-submit.php`, and has an admin mode signed in to WordPress (`inc/mobile-admin.php`).

## Program Data Pipeline (wiki editor auto-linker)

- Program name lists for the wiki editor live under `js/data/reddit-wiki/programs-XX.json`; uncategorized leftovers in `js/data/tti-program-links.json`.
- Regenerate the aggregate files after editing them (see `QUICK-REFERENCE.md`):

```bash
node scripts/aggregate-all-programs.js
```

## Helpful References

- `CLAUDE.md` (commands and tests for every subsystem)
- `AGENTS.md` (contributor guide)
- `DATABASE-COLUMNS.md` (main tables and columns)
- `environment-summary.md`
- `QUICK-REFERENCE.md`
- `docs/README.md`, `docs/PLAN.md`
- `docs/state-inspection-reports/README.md`
