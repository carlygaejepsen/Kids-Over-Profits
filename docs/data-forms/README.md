# Data Forms

The Data Forms module covers both the admin master-data form and the public suggestion form. Together they support structured data entry for facilities, operators, locations, referrers, and related program metadata.

## Overview

The forms use a project-based workflow so contributors can build, save, reload, export, and submit complex records over time instead of entering everything in a single session.

## Page Entry Points

### Admin Form
- `page-admin-data.php`
- shared markup in `templates/data-form-admin.php`
- saves directly to the master data workflow

### Public Suggestion Form
- `page-data.php`
- shared markup in `templates/data-form-public.php`
- submits suggestions for review rather than writing directly to the master dataset

## Runtime Architecture

### Asset Loading
- `inc/enqueue.php::enqueue_data_form_script()` decides when the data-form stack should load.
- The form assets are localized with `KOP_DATA_FORM_CONFIG` so the frontend can resolve endpoints without hard-coded machine paths.

### Frontend Modules
- `js/data-form/` contains the legacy shared modules and the `data-form.v4.js` orchestrator.
- `js/data-form-modules/` contains the newer config, API, normalization, UI, and page-specific modules.
- supporting scripts include `js/autocomplete.js`, `js/field-tooltips.js`, and `js/tutorial-overlay.js`.

### Backend Endpoints
- `api/get-master-data.php` loads the master dataset
- `api/save-master.php` saves admin edits
- `api/save-suggestion.php` stores public suggestions
- `api/get-autocomplete.php` powers shared autocomplete fields
- `api/process-edit.php` is part of the suggestion approval workflow

## Key Features

### Project Workflow
- local project save and reload support
- import and export of project JSON
- browser-based draft persistence as backup
- ability to move between complex records without losing work

### Structured Editing
- facility, operator, location, and referrer data entry
- field notes and helper tooling
- autocomplete-backed entity lookups
- clone-facility workflow for quickly duplicating similar records

### Guidance and Tooling
- tutorial overlay for new users
- field tooltips for dense form sections
- admin-specific and public-specific page behaviors layered on top of shared modules

## Admin vs Public Behavior

### Admin
- edits are saved through `api/save-master.php`
- intended for direct maintenance of authoritative records in `facilities_master`

### Public
- submissions go through `api/save-suggestion.php`
- intended for review and approval before records affect the live master dataset
- public pages expose "Submit for Review" and local draft save flows

## Configuration Notes

- Prefer `KOP_DATA_FORM_CONFIG` and related localized settings instead of hard-coded environment paths.
- The form stack can hydrate from live endpoints and fallback datasets depending on what the enqueue layer provides.
- When updating the data-form docs, check both shared templates and the page-specific modules because the admin and public flows intentionally diverge.
