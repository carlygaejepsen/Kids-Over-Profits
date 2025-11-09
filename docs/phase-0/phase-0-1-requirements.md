# Phase 0.1 – Requirements Deep Dive Notes

## WordPress Bootstrapping & Localization Map

| Concern | Implementation | Notes |
| --- | --- | --- |
| Facility dataset discovery | `kop_get_facility_projects_dataset_urls()` scans `js/data/` for `facility-projects-export*.json`, sorts by modification date, and returns sanitized URLs for downstream fallbacks.【F:functions.php†L136-L162】 | Establishes priority order for static bundles that both the public listing and admin tooling consume. |
| Database discovery | `kop_get_facilities_database_connection()` reuses the global `$wpdb` unless alternate credentials exist in constants or `api/config.php`, allowing filters to swap databases without patching the theme.【F:functions.php†L164-L240】 | Provides the connection handle consumed by export helpers; failure cases surface as `WP_Error` for REST parity testing. |
| Master table resolution | `kop_discover_facilities_master_table()` iterates known table names and prefixes before querying live data.【F:functions.php†L245-L293】 | Supports legacy table naming so migrations must preserve at least one candidate or override via filter. |
| REST assembly | `kop_get_facilities_projects_from_database()` normalizes per-project payloads and builds the object returned by the `kop/v1/facilities` REST route registered on `rest_api_init`.【F:functions.php†L301-L415】【F:functions.php†L420-L439】 | Drives the live API that the Next.js build must reproduce, including error propagation when table metadata is missing. |
| Public dataset localization | `load_facilities_data()` enqueues `js/facilities-display.js`, preferring the REST endpoint then falling back to discovered bundles localized in `facilitiesConfig`.【F:functions.php†L492-L536】 | Mirrors the current “live first, cached otherwise” behavior for the TTI Program Index and must be matched in SSR/ISR logic. |
| Report bundle discovery | `kop_enqueue_report_scripts()` iterates state-specific configs, globbing JSON bundles and localizing URLs into dedicated data objects when matching pages load.【F:functions.php†L538-L619】 | Signals which static manifest system the Next.js migration needs for report pages. |
| Admin form loader | `enqueue_facility_form_script()` guards pages containing `[facility_form]`, injects `css/data-form.css`, and localizes `KOP_FACILITY_FORM_CONFIG` with fallback dataset URLs and an `apiBase` derived from `home_url()`.【F:functions.php†L625-L674】 | Defines the minimum config contract the React rewrite must respect, including fallback URL ordering. |
| Approval console bridge | The custom admin menu renders an iframe pointed at `api/approve-edits.php`, keeping moderation anchored to the PHP workflow.【F:functions.php†L676-L703】 | Migration must offer an equivalent approval surface or retire this bridge once new tooling lands. |
| Anonymous portal bootstrap | `AnonymousDocPortal` wires shortcode rendering, AJAX handlers, script/style enqueueing, and Cloudmersive-aware localization (nonce, max size, allowed types).【F:functions.php†L726-L848】 | Highlights upload security expectations (directory hardening, antivirus, nonce) that the Node implementation must preserve. |

## API Surface Inventory

| Endpoint | Method(s) | Responsibilities |
| --- | --- | --- |
| `api/get-master-data.php` | `GET` | Returns all rows from `facilities_master`, auto-upgrading legacy payloads into the modern `{ name, data, timestamp, currentFacilityIndex, category }` shape before emitting `{ success, projects }`.【F:api/get-master-data.php†L1-L33】 |
| `api/save-master.php` | `POST` JSON | Handles project saves (`action=save`), renames, and deletes with PDO transactions, assembling canonical project structures and upserting by `unique_name` while logging structured errors.【F:api/save-master.php†L1-L176】 |
| `api/save-suggestion.php` | `POST` JSON (CORS) | Validates anonymous submissions, derives a `master_id`, records metadata (reason, submitter IP), and inserts pending records into `suggested_edits` with pretty-printed JSON for review.【F:api/save-suggestion.php†L1-L139】 |
| `api/process-edit.php` | `POST` JSON (auth required) | Loads WordPress, enforces admin capability, wraps approval/rejection in transactions, publishes approved JSON into `facilities_master`, and updates suggestion status timestamps when columns exist.【F:api/process-edit.php†L1-L176】 |
| `api/get-autocomplete.php` | `GET` (CORS) | Normalizes category aliases, caps result limits, converts mixed JSON payload shapes, and aggregates distinct strings across operators, facilities, people, and status data for client-side autocomplete menus.【F:api/get-autocomplete.php†L1-L220】 |
| `api/approve-edits.php` | `GET` | Serves the iframe UI consumed by the admin menu entry; functionality delegates to the endpoints above (HTML not shown here). |

## Front-end Bundles & Dataset Expectations

- **`js/facility-form.v3.js`** resolves API endpoints by combining localized bases, optional `window.KOP_API.getEndpoint()`, and default theme paths, while collapsing fallback dataset URLs into a prioritized list and honoring debug flags set via localization, globals, or storage.【F:js/facility-form.v3.js†L1-L189】 Any Next.js form client must replicate this resolver logic to stay environment-agnostic.
- **`js/facilities-display.js`** restructures either modern `{ projects: { ... } }` responses or legacy arrays into operator groups before rendering, making alphabetical ordering and defensive checks (empty datasets, unknown operator names) part of the compatibility contract.【F:js/facilities-display.js†L1-L200】
- **`js/anonymous-portal.js`** relies on the localized `anonymous_portal_ajax` object for size/type validation, nonce submission, and upload progress feedback, augmenting drag-and-drop UX and asynchronous status handling around the WordPress AJAX endpoint.【F:js/anonymous-portal.js†L1-L206】

## Data Fallback & Compatibility Considerations

- Static exports discovered via `kop_get_facility_projects_dataset_urls()` seed both the admin form and the public listing; disruptions to bundle naming or placement break offline resilience.【F:functions.php†L136-L162】【F:functions.php†L625-L670】
- The REST payload produced by `kop_get_facilities_projects_from_database()` and cached JSON bundles share the `{ source, projects }` shape, while `facilities-display.js` tolerates historical array formats—parity tests must cover all three inputs.【F:functions.php†L301-L415】【F:js/facilities-display.js†L52-L89】
- Autocomplete values sweep deeply nested arrays (names, labels, prior operators, etc.), so schema migrations must preserve equivalent paths or adapt the collector logic during the rewrite.【F:api/get-autocomplete.php†L95-L220】

## Open Questions for Follow-up

1. Should the Next.js stack continue exposing a public iframe-compatible moderation view, or can the approval UI move entirely into the new admin shell?【F:functions.php†L676-L703】
2. Do we intend to keep supporting anonymous uploads without authentication, or can we introduce optional rate limiting or identity checks alongside the Cloudmersive scan in the new backend?【F:functions.php†L726-L848】
3. What guarantees exist around historical dataset filenames beyond `facility-projects-export*.json` (e.g., version suffixes), and do stakeholders require an archival retention policy when regenerating static bundles?【F:functions.php†L136-L162】
