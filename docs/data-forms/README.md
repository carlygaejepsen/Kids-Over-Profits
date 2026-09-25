# Data Forms

The Data Forms module covers both the admin master-data form and the public suggestion form. Together they support structured data entry for parent companies (operator projects with their facilities), operators, locations (state and country profiles), referrers, transporters, and mental health providers.

## Overview

The forms use a project-based workflow so contributors can build, save, reload, export, and submit complex records over time instead of entering everything in a single session.

## Page Entry Points

Page templates live in `templates/`. Each is a thin wrapper that includes the shared markup.

### Admin Form
- `templates/page-admin-data.php` (template "Admin Data Form")
- markup in `templates/data-form-admin.php`, gated by `kop_require_page_capability('manage_options')`
- tabs: parent companies, operators (edit only), locations, referrers, transporters, mental health providers
- saves directly to the master data workflow

### Public Suggestion Form
- `templates/page-data.php` (template "Public Data Form")
- markup in `templates/data-form-public.php`
- tabs: parent companies, locations, referrers, transporters, mental health providers
- submits suggestions for review rather than writing directly to the master dataset

Related admin screen: `templates/page-admin-data-manager.php` (rename, move, reassign, delete master records; backed by `api/data-manager.php`).

## Runtime Architecture

### Asset Loading
- `inc/enqueue.php` `enqueue_data_form_script()` decides when the stack loads: it matches either page template (or the wrapped markup template) and the slugs `data`, `tti-data-submission`, `admin-data`, `tti-admin-data`.
- `kop_enqueue_autocomplete_dependencies()` loads `js/data-form/utilities.js`, `js/data-form/db-form-loader.js` and `js/autocomplete.js` first.
- The config is localized twice, as `KOP_DATA_FORM_CONFIG` (on `db-form-loader`) and `dataFormConfig` (on `config.js`). It carries `ajaxUrl`, `restUrl`, `nonce`, `isAdmin`, `mode` (`admin` or `suggestions`) and `endpoints`: `SAVE_PROJECT` (`api/save-master.php`), `SAVE_SUGGESTION` (`api/save-suggestion.php`), `LOAD_PROJECTS` (`api/get-master-data.php`), `AUTOCOMPLETE` (`api/get-autocomplete.php`).
- Styles: `css/data-form.css`, `css/toolbar.css`, `css/tutorial-overlay.css`, `css/custom-modals.css`.

### Frontend Modules
Plain scripts (not ES modules), ordered by WordPress dependencies.
- `js/data-form-modules/`: `config.js` -> `data-normalizer.js` -> `api.js` -> `project.js`, then the UI modules `ui-render.js`, `ui-state.js`, `ui-events.js`, `ui-actions.js`, and the page modules `data-page.js` (both forms) and `admin-data-page.js` (admin only). `ui.js` is not enqueued.
- `js/data-form/`: the `data-form.v4.js` orchestrator (loads after the modules above), the sub-forms `location-form.js`, `referrer-form.js`, `transporter-form.js`, `provider-form.js`, plus `notes.js`, `data-search.js`, `data-toolbar.js`, `custom-modals.js`, `report-config.js` and `data-report-generator.js`.
- Supporting scripts: `js/autocomplete.js`, `js/field-tooltips.js`, `js/tutorial-overlay.js`, and on the admin form `js/admin-news-links.js` (linked news articles panel).
- `data-normalizer.js` holds `facilityToV2` / `facilityFromV2`, a port of the PHP normalizer; keep them in step with `scripts/check-facility-normalizer-parity.js`.

### Backend Endpoints
- `api/get-master-data.php` loads the master dataset (built from the v2 tables once the write switch is on)
- `api/save-master.php` saves admin edits (admin only)
- `api/save-suggestion.php` stores public suggestions in `suggested_edits`
- `api/get-autocomplete.php` powers shared autocomplete fields
- `api/process-edit.php` and `api/manage-submissions.php` approve or reject suggestions, both through `api/lib-suggested-edits.php`

## Facility Write Path (v2 model)

The v2 write switch has been on since 2026-09-18. The legacy facility tables are frozen as a backup; see `docs/DATA-MODEL-MIGRATION.md` and `docs/FACILITY-SCHEMA.md`.

- Everything that writes a facility goes through `inc/facility-store.php`: `kop_facility_normalize()`, `kop_facility_validate()`, `kop_facility_save()` (which validates and refuses error-severity violations).
- `inc/facility-v2-writer.php` translates between the form's project shape and the v2 tables (`facilities_v2`, operators, joins, location memberships). `kop_v2_save_form_project()` splits a project into one `kop_facility_save()` per facility; `kop_v2_form_projects()` builds the projects the form loads.
- `api/save-master.php`: saves of parent-company and location projects, renames and deletes go through the v2 writer when `kop_v2_writes_active()` is true. Referrer, transporter and provider projects still save to `referrers_master` / `transporters_master` / `providers_master`. Location rebuild/cleanup actions return 409.
- Approving a suggestion applies it with `kop_v2_save_form_project()` in partial mode, so a suggestion only changes the fields it lists.
- `facilities_master` takes no writes. `locations_master` keeps only state-level data with no v2 home (referrer consultants, field notes).
- Tools that still write the legacy tables must call `kop_v2_exit_if_legacy_frozen()`.

### Facility targeting and common TTI practices

Facility form entries can record the program's stated target population and
reported TTI practices. `targetedDiagnoses`, `targetedBehaviors`, and
`ttiPractices` are stored as maps in each facility document. Practices can also
include an `other` string list. The schema-v3 normalizers in
`inc/facility-store.php` and `js/data-form-modules/data-normalizer.js` preserve
these groups when the form loads from or saves to `facilities_v2.json_data`.
The SQL table already stores the facility document as JSON, so these additions
do not require new relational columns.

## Mental Health Providers (hybrid category)

The **Mental Health Providers** tab (`data-category="providers"`) is for providers that are not part of the TTI but use TTI practices and refer children to TTI facilities: acute psychiatric wards, partial hospitalization (PHP) and intensive outpatient (IOP) programs, day schools, respite care and outpatient therapy.

- It is a hybrid of a facility and a referrer, so it reuses the facility editor. A provider project has a parent organization (the operator block, relabeled "Parent Organization / Health System") and one entry per site in `data.facilities`, with the usual identification, location, operations, staff, program details, accreditations, resources, treatment types, philosophy, incidents and notes sections.
- `js/data-form/provider-form.js` switches the facility wording to provider wording while the tab is active (`PROVIDER_LABELS`) and tags the project `category: "providers"`.
- The provider-only panel **Type of Care & TTI Ties** (`#provider-section`) writes to each site's `providerDetails`:
  - `careTypes` checkboxes: `hasPartialHospitalization`, `hasIntensiveOutpatient`, `hasRespiteCare`, `hasOutpatientTherapy`, `hasAcutePsychiatric`, `hasDaySchool`, plus `otherCareTypes`
  - `ttiPractices` checkboxes: `hasLevelSystem`, `hasRestraint`, `hasSeclusion`, `hasCommunicationRestrictions`, `hasConfrontationGroups`, `hasBehaviorContracts`, `hasStripSearches`, `hasForcedMedication`, plus `otherTtiPractices`
  - `ttiReferrals` (TTI facilities they refer to), `transportersUsed`, `ttiAffiliations`, `referralNotes`
- Staff rows (`staff.administrator`, `staff.notableStaff`) keep their role, name and past TTI employment, and in this tab also get a **Known TTI Connections** box (`ttiConnections`).
- Storage: `providers_master` (created on first save or approval), never the facility tables. Provider saves skip `__facility_ref` promotion and location linking, so provider sites do not appear on facility pages, state hubs or `/wp-json/kop/v1/facilities`. Every other category strips `providerDetails` before saving or submitting.
- Approval: `api/lib-suggested-edits.php` files a suggestion whose data carries `category: "providers"` into `providers_master`.

## Key Features

### Project Workflow
- local project save and reload support
- import and export of project JSON
- browser-based draft persistence as backup ("Save Draft Locally" on the public form)
- ability to move between complex records without losing work

### Structured Editing
- parent company, facility, operator, location, referrer, transporter, and mental health provider data entry
- field notes and helper tooling
- autocomplete-backed entity lookups
- clone-facility workflow for quickly duplicating similar records

### Guidance and Tooling
- numbered "how to submit" steps at the top of the public form
- one-line help under each panel heading from `inc/form-help.php` (`kop_form_help_lines()`, printed by `kop_form_panel_help()`), shared by both templates so their wording cannot drift
- tutorial overlay for new users
- field tooltips (`js/field-tooltips.js`) for dense form sections
- admin-specific and public-specific page behaviors layered on top of shared modules

## Admin vs Public Behavior

### Admin
- edits are saved through `api/save-master.php`, which requires `manage_options`
- facility data lands in the v2 tables (see above), not `facilities_master`

### Public
- submissions go through `api/save-suggestion.php` into `suggested_edits`
- reviewed and approved before they affect live records
- public pages expose "Submit for Review" and local draft save flows

## Tests

- `node scripts/test-form-help.js` checks the panel help keys and field tooltips against both templates
- `node scripts/check-facility-normalizer-parity.js` checks the JS and PHP facility normalizers agree
- `php scripts/test-facility-v2-writer.php` exercises the v2 save path end to end (destructive; throwaway MySQL copy only)

## Configuration Notes

- Prefer `KOP_DATA_FORM_CONFIG` and related localized settings instead of hard-coded environment paths.
- When changing panel headings or field ids, update `inc/form-help.php` and `js/field-tooltips.js` and rerun `scripts/test-form-help.js`.
- When updating the data-form docs, check both shared templates and the page-specific modules because the admin and public flows intentionally diverge.
