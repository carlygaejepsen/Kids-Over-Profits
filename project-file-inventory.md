# Kids Over Profits Repository Inventory

This document lists every tracked file in the project, explains the role it plays, and notes when the file is needed during development, deployment, or runtime. URLs are provided for assets that power specific public pages or API endpoints.

## Legend
- **When Needed** highlights the workflow, page load, or background job that relies on the file.
- **Public URL / Endpoint** lists the front-end route or HTTP endpoint that touches the file. Internal-only files show `—` when no public URL applies.

## Root Directory
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `.cpanel.yml` | Deployment recipe that rsyncs the `Website/` folder into the Kadence child theme on cPanel. | Required on every deployment run executed by cPanel Git hooks. | — |
| `.gitattributes` | Normalizes Git line endings across collaborators (`* text=auto`). | Always active for Git checkouts and merges. | — |
| `.zencoder/rules/repo.md` | Machine-readable repository overview consumed by Zencoder automation. | Read whenever automated tooling inspects repository metadata. | — |
| `agents.md` | High-level contributor guide for the entire repo. | Consulted by maintainers before making changes. | — |
| `analysis-findings.md` | Prior static-analysis report documenting linting status and fixes. | Reviewed during QA or onboarding to understand outstanding issues. | — |
| `project-file-inventory.md` | (This document) master catalogue of repository files and usage contexts. | Reference whenever planning updates or onboarding new contributors. | — |
| `Scripts/` | Data collection and transformation utilities (see breakdown below). | Invoked during data acquisition or preprocessing. | — |
| `Website/` | Kadence child theme shipped to production WordPress site. | Loaded on every page request in production. | Public site under `https://kidsoverprofits.org/` |

## Website/ (WordPress Child Theme)

### Bootstrap & Shared Helpers
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/functions.php` | Loads helper functions, enqueues CSS/JS (inline fallback), registers localization helpers for report pages, and bootstraps anonymous portal + data workflows. | Runs on every WordPress request that loads the child theme. | All WordPress front-end URLs. |
| `Website/style.css` | Declares child theme metadata and imports modular CSS bundles. | Must remain present for WordPress to register the child theme; CSS imports applied to every themed page. | All WordPress front-end URLs. |
| `Website/inc/template-helpers.php` | Provides helper functions (`kidsoverprofits_get_facility_report_intro`, sorting options, etc.). | Included by `functions.php` whenever report templates render. | Report pages `/ca-reports/`, `/az-reports/`, `/ct-reports/`, `/tx-reports/`, `/mt-reports/`, `/wa-reports/`, `/ut-reports/`. |
| `Website/template-parts/content-facility-report.php` | Shared markup for state facility listings (search box, alphabet filter, last-updated footer). | Loaded by each state `page-*-reports.php` template via `get_template_part`. | Same report URLs as above. |

### PHP Page Templates
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/page-admin-data.php` | Renders admin-only master data entry dashboard with tabs, tables, and forms. | Used when staff visits the secure Admin Data page. | Typically `/admin-data/` (WordPress page using this template). |
| `Website/page-az-reports.php` | Entry point for Arizona inspection report listing; passes slug `az-reports` to shared template. | Rendered for the public Arizona reports page. | `/az-reports/` |
| `Website/page-ca-reports.php` | Entry point for California report listing. | Rendered for the public California reports page. | `/ca-reports/` |
| `Website/page-ct-reports.php` | Entry point for Connecticut report listing. | Rendered for the public Connecticut reports page. | `/ct-reports/` |
| `Website/page-data-organizer.php` | Template for advanced facility data organizer UI. | Staff workflow for managing multi-operator datasets. | `/data-organizer/` |
| `Website/page-data.php` | Public submission form for community data contributions. | When supporters submit facility corrections/data. | `/data/` |
| `Website/page-facility-analysis.php` | Hosts aggregated analytics and facility summaries. | When analysts visit the Facility Analysis landing page. | `/facility-analysis/` |
| `Website/page-mt-reports.php` | Montana state report page wrapper. | Loads Montana facility listings. | `/mt-reports/` |
| `Website/page-test-autocomplete.php` | Internal QA page that exercises the autocomplete API categories. | Used during backend/autocomplete regression testing. | `/test-autocomplete/` (protected staging utility). |
| `Website/page-tx-reports.php` | Texas state report page wrapper. | Serves Texas facility listings. | `/tx-reports/` |
| `Website/page-ut-reports.php` | Utah state report page wrapper. | Serves Utah facility listings, including checklist integration. | `/ut-reports/` |
| `Website/page-wa-reports.php` | Washington state report page wrapper. | Serves Washington facility listings. | `/wa-reports/` |

### API Endpoints (`Website/api/`)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/api/config.php` | Centralizes PDO connection credentials and aliases for legacy scripts. | Included by every API endpoint before database queries. | Included internally by other endpoints. |
| `Website/api/get-autocomplete.php` | Returns deduplicated suggestion lists for operators, facilities, humans, types, etc. | Called by admin data entry UI and QA tester to populate autocomplete dropdowns. | `https://kidsoverprofits.org/wp-content/themes/child/api/get-autocomplete.php` |
| `Website/api/get-master-data.php` | Fetches approved facility records for admin tooling. | Used when admin dashboard loads or refreshes data tables. | `https://kidsoverprofits.org/wp-content/themes/child/api/get-master-data.php` |
| `Website/api/process-edit.php` | Applies field-level updates from admin edits. | Triggered by admin UI save actions. | `https://kidsoverprofits.org/wp-content/themes/child/api/process-edit.php` |
| `Website/api/save-master.php` | Commits curated project data to the master dataset. | Invoked after admin approval workflows. | `https://kidsoverprofits.org/wp-content/themes/child/api/save-master.php` |
| `Website/api/save-suggestion.php` | Captures public data submissions from `/data/`. | Fired when visitors submit correction/suggestion forms. | `https://kidsoverprofits.org/wp-content/themes/child/api/save-suggestion.php` |

### Documentation (`Website/docs/`)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/docs/DISPLAY_TESTING.md` | Manual QA checklist for verifying front-end layouts and data display. | Consult during release QA. | — |
| `Website/docs/WORDPRESS_PAGE_SETUP.md` | Step-by-step instructions to configure WordPress pages and assign templates. | Used when provisioning new pages or onboarding admins. | — |

### HTML Prototypes (`Website/html/`)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/html/admin-data.html` | Static mock-up of admin data dashboard. | Reference when designing/administering WordPress equivalent. | — |
| `Website/html/az-reports.html` | Standalone Arizona report prototype with embedded JSON fetch logic. | Used for rapid prototyping before WordPress integration. | — |
| `Website/html/ca-reports.html` | Static California report prototype (multi-file loader). | Testing CA data in isolation. | — |
| `Website/html/ct-reports.html` | Prototype for Connecticut reports. | Independent QA of CT datasets. | — |
| `Website/html/data-organizer.html` | Prototype for data organizer UI. | UX exploration outside WordPress. | — |
| `Website/html/data.html` | Standalone public submission form. | Used for HTML regression tests or embedding elsewhere. | — |
| `Website/html/facility-analysis.html` | Prototype analytics dashboard. | Exploratory data visualization work. | — |
| `Website/html/mt-reports.html` | Prototype Montana report page. | QA for Montana data. | — |
| `Website/html/test-autocomplete.html` | HTML harness for exercising autocomplete endpoint. | Local QA without WordPress. | — |
| `Website/html/tx-reports.html` | Prototype Texas report page. | QA for Texas data ingestion. | — |
| `Website/html/ut-reports.html` | Prototype Utah report page (checklist integration). | QA for Utah data sets. | — |
| `Website/html/wa-reports.html` | Prototype Washington report page. | QA for Washington data. | — |

### JavaScript Assets (`Website/js/`)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/js/admin-data.js` | Adds admin-only behaviors (bulk operations, validation) layered onto facility form module. | Enqueued on `/admin-data/`. | `/admin-data/` |
| `Website/js/anonymous-portal.js` | Handles drag/drop uploads, encryption flow, and submission UX for anonymous portal. | Loaded wherever the anonymous portal shortcode appears. | Public anonymous portal page. |
| `Website/js/app-logic.js` | Core shared logic for facility form builder (state management, UI binding). | Dependency for form-related pages (`/admin-data/`, `/data-organizer/`, `/data/`). | Same pages. |
| `Website/js/az_reports.js` | Fetches and renders Arizona JSON data sets. | Enqueued on `/az-reports/`. | `/az-reports/` |
| `Website/js/ca-reports.js` | Loads 21 California batch JSON files and renders alphabetized facility cards. | Enqueued on `/ca-reports/`. | `/ca-reports/` |
| `Website/js/css-test.js` | Diagnostic helper to verify CSS class coverage. | Used in styling QA sessions. | — |
| `Website/js/ct_reports.js` | Fetches Connecticut JSON dataset and renders facilities. | `/ct-reports/` |
| `Website/js/data-form.js` | Enhances public data submission form with validation and dynamic sections. | `/data/` |
| `Website/js/data-organizer.js` | Provides UI behaviors for the data organizer template. | `/data-organizer/` |
| `Website/js/facilities-display.js` | Powers the facility index/program finder with filtering. | Pages embedding the facility index shortcode (e.g., `/facility-analysis/`). | Facility index URL(s). |
| `Website/js/facility-form-local.v3.js` | Local development variant of facility form module (mock persistence). | Used for offline prototyping or local HTML harnesses. | — |
| `Website/js/facility-form.v3.js` | Production facility form logic shared across admin/public forms. | `/admin-data/`, `/data/`, `/data-organizer/`. | Same URLs. |
| `Website/js/facility-report-generator.js` | Generates printable facility reports (PDF-ready). | Invoked when exporting facility summaries from admin UI. | Download flows initiated from admin pages. |
| `Website/js/mt_reports.js` | Loads Montana JSON dataset. | `/mt-reports/` |
| `Website/js/report-test.js` | Testing harness for report rendering components. | Developer-only QA. | — |
| `Website/js/test-autocomplete.js` | Front-end companion to `/test-autocomplete/` template. | `/test-autocomplete/` |
| `Website/js/tx_reports.js` | Fetches Texas CSV/JSON assets and renders facility details. | `/tx-reports/` |
| `Website/js/ut_reports.js` | Integrates Utah JSON data with inspection checklist PDFs. | `/ut-reports/` |
| `Website/js/utilities.js` | Shared helper functions (formatting, data transforms) consumed by report scripts. | Automatically loaded where scripts import it. | Dependent pages above. |
| `Website/js/visual-test.js` | Developer tool for visual regression checks. | Local QA scenarios. | — |

### CSS Bundles (`Website/css/`)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/css/admin.css` | Styles for admin dashboard UI components. | `/admin-data/`, admin prototypes. | Same URLs. |
| `Website/css/common.css` | Base typography, colors, and utility classes. | Globally loaded via `style.css`. | Site-wide. |
| `Website/css/forms.css` | Form layout and input styling. | Forms such as `/data/`, `/admin-data/`. | Same URLs. |
| `Website/css/layout.css` | Grid and layout helpers for facility listings. | Used by all report and data pages. | Site-wide. |
| `Website/css/modals.css` | Modal dialog styling for admin tools and portal. | Triggered when modals appear on admin/public forms. | Same URLs. |
| `Website/css/print-report.css` | Print stylesheet for exported facility reports. | Applied during browser print/export actions. | Print view of report pages. |
| `Website/css/tables.css` | Styling for tabular data (violations, staff lists). | Report detail cards and admin tables. | Same URLs. |

### Data Assets (`Website/js/data/`)
| Path / Pattern | Contents | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/js/data/CA_json/ccl_reports_batch_{1-21}.json` | 21 JSON batches comprising the California Community Care Licensing inspection dataset. | Loaded by `ca-reports.js` whenever `/ca-reports/` renders. | `/wp-content/themes/child/js/data/CA_json/ccl_reports_batch_X.json` (fetched via AJAX). |
| `Website/js/data/TX_reports/` | 680 Texas assets (CSV extracts and UUID-named JSON/ZIP blobs) mapped to operation IDs; includes one PDF (`tsi-turning-point.pdf`) and dataset export `facility-projects-export-2025-10-02 (1).json`. | Streamed by `tx_reports.js` on `/tx-reports/`; individual CSVs downloaded for drill-down views. | `/tx-reports/` asset requests under `/wp-content/themes/child/js/data/TX_reports/`. |
| `Website/js/data/checklists/*.pdf` | 1,342 Utah inspection checklist PDFs named by facility ID and report number. | Linked from Utah facility detail modals on `/ut-reports/`. | `/ut-reports/` downloads under `/wp-content/themes/child/js/data/checklists/`. |
| `Website/js/data/ct_reports.json` | Connecticut facility inspection dataset. | Loaded by `ct_reports.js` on `/ct-reports/`. | `/wp-content/themes/child/js/data/ct_reports.json` |
| `Website/js/data/facility-projects-export.json` | Serialized master project data used by admin organizer. | Fetched by admin/data organizer scripts. | `/admin-data/`, `/data-organizer/`. |
| `Website/js/data/ut_reports.json` | Utah inspection dataset (metadata, violation counts). | Consumed by `ut_reports.js`. | `/wp-content/themes/child/js/data/ut_reports.json` |
| `Website/js/data/wa_reports.json` | Washington inspection dataset. | Consumed by `wa_reports.js` on `/wa-reports/`. | `/wp-content/themes/child/js/data/wa_reports.json` |

### Additional Theme Assets
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Website/page-az-reports.php` … `Website/page-wa-reports.php` | (Covered above) | | |
| `Website/page-...` prototypes | (Covered above) | | |

## Scripts/ (Data Pipelines & Utilities)
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `Scripts/batch_dl.py` | Batch downloader for pulling multiple remote report files at once. | Run when seeding local data archives. | — |
| `Scripts/ca_scraper.py` | Scrapes California Community Care Licensing site, normalizes reports, aggregates by facility. | Executed during monthly CA data refresh. | — |
| `Scripts/ct_scraper.py` | Scraper for Connecticut DCF facility listings and reports. | Used during CT data updates. | — |
| `Scripts/tx_scraper.py` | Playwright automation that downloads Texas Health & Human Services inspection packets by operation ID. | Run to refresh Texas CSV/ZIP datasets feeding `/tx-reports/`. | — |
| `Scripts/ut_parser.py` | Parses Utah report data into JSON structures. | Used post-scrape before publishing Utah datasets. | — |
| `Scripts/ut_checklist_parser.py` | Extracts metadata from Utah checklist PDFs. | Run when new checklist PDFs arrive. | — |
| `Scripts/utah_citation_scraper.py` | Legacy Utah citation scraper resolving earlier merge conflicts. | Historical reference or fallback scraping run. | — |
| `Scripts/utah_citation_scraper.v2.py` | Second iteration of Utah citation scraper. | Alternate workflow when v3 unavailable. | — |
| `Scripts/utah_citation_scraper.v3.py` | Latest Utah citation scraper with OCR enhancements. | Preferred when gathering Utah citations. | — |
| `Scripts/utah_report.py` | Builds consolidated Utah report summaries from parsed data. | Final step in Utah pipeline before publishing JSON. | — |
| `Scripts/enhanced_extraction_with_ocr.py` | OCR pipeline for scanned inspection PDFs. | Triggered when PDFs lack embedded text. | — |
| `Scripts/easyocr_extraction.py` | Companion OCR helper leveraging EasyOCR. | Used for OCR-based extraction experiments. | — |
| `Scripts/drive_doc_parser.py` | Processes Google Drive document exports. | Run when ingesting Drive-sourced submissions. | — |
| `Scripts/edcon_scraper.py` | Scrapes educational consultant listings for cross-referencing operators. | Executed during consultant dataset updates. | — |
| `Scripts/edcon_hits_on_websites.csv` | Spreadsheet capturing consultant web hits discovered during scraping. | Reviewed alongside consultant research. | — |
| `Scripts/Educational Consultants - Crowdsourced_Names.csv` | Crowdsourced list of consultant names. | Input for enrichment scripts. | — |
| `Scripts/natsap_edcon_scraper.py` | Scraper for NATSAP educational consultant data. | Run to update consultant cross-reference list. | — |
| `Scripts/natsap_program_scraper.py` | Scrapes NATSAP program directory for facility metadata. | Used when expanding facility dataset. | — |
| `Scripts/ther_con_scraper.py` | Scraper for Therapy Consumer resources. | Run for alternate consultant/facility sources. | — |
| `Scripts/ther_con_scraper.v2.py` | Updated Therapy Consumer scraper. | Preferred for current data pulls. | — |
| `Scripts/consultant_leads.csv` | Spreadsheet of consultant lead contacts. | Referenced during outreach or data validation. | — |
| `Scripts/dcf_facilities_clean.json` | Cleaned dataset from Department of Children & Families. | Imported into admin organizer or analytics. | — |
| `Scripts/facility_input.html` | Standalone HTML form for testing facility data entry outside WordPress. | Local QA of form scripts. | — |

## Miscellaneous Directories
| Path | Purpose | When Needed | Public URL / Endpoint |
| --- | --- | --- | --- |
| `.git/` | Version control metadata (not deployed). | Required for Git operations. | — |

## Usage Summary by Workflow
- **Public visitors** hit the WordPress templates, JavaScript report loaders, and JSON/CSV/PDF assets listed above whenever they browse state report pages or submit data.
- **Admins** rely on `page-admin-data.php`, `page-data-organizer.php`, `Website/js/admin-data.js`, the facility form scripts, and all API endpoints under `Website/api/` to curate master datasets.
- **Data engineers** run Python scripts inside `Scripts/` to pull fresh inspection data, populate JSON/CSV assets in `Website/js/data/`, and reconcile consultant records stored in CSV/JSON files.
- **Deployment engineers** depend on `.cpanel.yml`, `.gitattributes`, and `.zencoder/rules/repo.md` for automated sync and tooling integration.

This inventory should serve as the authoritative reference whenever determining the impact scope of a change, planning QA coverage, or onboarding new maintainers.
