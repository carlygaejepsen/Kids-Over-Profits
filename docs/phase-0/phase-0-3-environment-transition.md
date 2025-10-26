# Phase 0.3 – Environment & Transition Planning

## Parallel WordPress & Next.js Posture

- Keep the production WordPress instance online as the source of truth while the Next.js stack matures. Preserve all existing dataset fallbacks and REST responses so legacy pages (TTI Program Index, facility form, reports) continue to read from `kop/v1/facilities` and bundled exports until the new APIs are validated.【F:functions.php†L301-L536】
- Continue publishing fresh `facility-projects-export*.json` bundles from WordPress during the transition. Those files must stay discoverable under `/js/data/` so both the WordPress front end and the staging Next.js app can reuse them as offline backups.【F:functions.php†L141-L162】【F:functions.php†L492-L536】
- Document the coexistence strategy: WordPress remains public-facing; Next.js staging runs privately behind authentication until parity QA is complete. Plan redirects and DNS updates only after confidence builds through smoke testing and stakeholder review.

## Staging & Deployment Topology

- Stand up a dedicated staging host (e.g., Vercel project or managed Node VM) that mirrors production environment variables and secrets. Sync the `KOP_FACILITY_FORM_CONFIG` contract and any API base URLs so client code can toggle between staging and production without hard-coded paths.【F:functions.php†L625-L674】
- Mirror database schemas in staging by importing snapshots from the live facilities database. This ensures new Prisma/ORM migrations reflect real data shapes before hitting production. Retain read-only access to production during the overlap for troubleshooting.【F:api/get-master-data.php†L1-L33】【F:api/save-master.php†L34-L176】
- Route staging uploads through the same Cloudmersive antivirus workflow to confirm the Next.js implementation respects the current portal constraints (nonce enforcement, MIME whitelist, secure storage) before launch.【F:functions.php†L726-L760】

## Data & Asset Synchronization

- Automate nightly exports from the production WordPress database to the staging environment so API parity tests always run against current records. Coordinate with hosting backups or wp-cli jobs to avoid manual drift.【F:functions.php†L301-L415】
- Version-control the JSON bundle manifest used by state reports so the Next.js build can fetch the same files WordPress exposes via `kop_enqueue_report_scripts()`. During overlap, publish bundles to both the WordPress theme directory and a CDN bucket the Next.js app can reach.【F:functions.php†L538-L619】
- Track anonymous document submissions in both environments, ensuring staging scans route to isolated storage buckets to prevent cross-contamination with production uploads.【F:functions.php†L726-L848】

## Transition Runbook & Rollback

- Define a deployment checklist that confirms API endpoint parity, dataset availability, and portal operations on staging before any public cutover. Capture validation steps per feature (facility CRUD, suggestions, autocomplete, reports, document uploads).【F:api/get-autocomplete.php†L95-L220】【F:api/save-suggestion.php†L44-L139】
- Plan a DNS switchover window where WordPress continues to serve traffic while a reverse proxy or CDN routes specific paths to the Next.js app. Maintain a toggle (feature flag or edge rule) that instantly reverts traffic to WordPress should regressions appear.【F:functions.php†L492-L674】
- Keep WordPress backups and database dumps on hand for rapid rollback. Schedule the cutover during low-traffic periods identified from existing analytics.

## Monitoring & Operations

- Instrument both environments with centralized logging and uptime alerts before transition week. Monitor REST endpoint latency, bundle freshness timestamps, and upload scan success rates to catch regressions early.【F:functions.php†L301-L536】【F:functions.php†L726-L848】
- Update the team runbook with SSH/hosting access details so responders can manage the live LiteSpeed server while also observing the staging Next.js deployment.【F:environment-summary.md†L13-L37】
- After cutover, leave WordPress running in read-only or proxy mode until the Next.js application demonstrates stability over an agreed burn-in period, then decommission redundant cron jobs and script enqueues.
