# Phase 0.2 – Technical & Architectural Decisions

## Next.js Runtime Profile

- Adopt the **App Router** with React Server Components to let server-side loaders prefetch facility datasets and admin metadata while keeping large JSON payloads off the client until needed. Pair with **Progressive Static Regeneration** for public listing routes so cached bundles stay warm yet updates propagate quickly via revalidation hooks.
- Maintain a thin **Edge-safe client shim** for public fallbacks that mirrors the WordPress localization contract (`facilitiesConfig`, `KOP_FACILITY_FORM_CONFIG`) so the new front end can downgrade gracefully when live APIs fail, just as `load_facilities_data()` and `enqueue_facility_form_script()` do today.【F:functions.php†L492-L674】
- Centralize route handlers under `app/api` with typed request/response schemas that match the PHP endpoints (`get-master-data.php`, `save-master.php`, `save-suggestion.php`, `process-edit.php`, `get-autocomplete.php`) to preserve consumer expectations during migration.【F:api/get-master-data.php†L1-L33】【F:api/save-master.php†L1-L176】【F:api/save-suggestion.php†L1-L139】【F:api/process-edit.php†L1-L176】【F:api/get-autocomplete.php†L1-L220】

## Data Persistence & Modeling

- Stand up a **managed PostgreSQL** instance and mirror table semantics for `facilities_master` and `suggested_edits`, including JSON columns that hold the project payloads and pretty-printed suggestion bodies. Prisma (or an equivalent ORM) should expose typed clients that enforce the `unique_name` and status fields the PHP code expects.【F:api/save-master.php†L34-L176】【F:api/save-suggestion.php†L44-L139】
- Implement migration scripts that seed existing datasets by replaying exports from `kop_get_facilities_projects_from_database()` so parity tests cover both live data and static bundles.【F:functions.php†L301-L415】
- Keep autocomplete data denormalized in a materialized view or cached table updated by a background job, reflecting the current endpoint’s sweeping traversal of nested JSON to satisfy low-latency lookups.【F:api/get-autocomplete.php†L95-L220】

## Configuration & Secret Management

- Replace `wp_localize_script` with a **configuration service**: API routes that emit the same config shapes (`KOP_FACILITY_FORM_CONFIG`, anonymous portal metadata) to clients on demand, backed by environment variables stored in the deployment provider’s secret manager.【F:functions.php†L625-L848】
- Provide per-environment overrides (staging, production) via typed config modules so feature flags—like debug logging currently toggled through localized booleans and `window.KOP_FACILITY_FORM_DEBUG`—can be flipped without redeploying the client bundle.【F:js/facility-form.v3.js†L1-L189】
- Document a secure `.env` loading strategy for local development that never exposes credentials checked into `api/config.php`, aligning with current repository practices.【F:api/config.php†L1-L34】

## File Uploads & Antivirus

- Recreate the anonymous document portal as a **Next.js Route Handler** that streams multipart uploads into object storage (e.g., S3 or compatible) while preserving the nonce, MIME, and size checks enforced in the current shortcode implementation.【F:functions.php†L726-L848】
- Integrate the **Cloudmersive antivirus API** through a server-side fetch wrapper with exponential backoff and structured logging; honor the existing requirement that uploads are quarantined until a scan succeeds or a configurable override is set.【F:functions.php†L803-L848】
- Store uploads in per-submission prefixes with deny-by-default access policies to emulate the generated `.htaccess` and `index.php` safety net shipped today.【F:functions.php†L752-L792】

## Observability, Testing & Deployment

- Establish CI pipelines that run unit and contract tests for the mirrored API routes, validating request/response compatibility against fixtures captured from the PHP implementation. Include smoke tests that verify fallback ordering for datasets mirrors `kop_get_facility_projects_dataset_urls()` and related helpers.【F:functions.php†L136-L415】
- Emit structured logs (JSON) with correlation IDs on every API route to replace the ad-hoc `error_log` calls seen in the PHP scripts, enabling centralized monitoring and alerting during cutover.【F:api/save-master.php†L58-L176】【F:api/process-edit.php†L81-L176】
- Deploy to an environment that supports **zero-downtime rollouts** (e.g., Vercel with preview deployments or a container-based platform) and script a blue/green switchover so WordPress routes can proxy to Next.js gradually while regression metrics are observed.

