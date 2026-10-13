# Database Columns Reference

Quick reference for the main database tables and their columns. Columns are taken from each table's `CREATE TABLE` in the file named; when this page and the code disagree, the code wins (`php api/get-database-schema.php` lists the live schema).

Two databases are involved. Tables written `{prefix}...` (`wpdl_` on the live site) and the facility model tables live in the WordPress database. The records tables (`news_submissions`, `lawsuits`, `legislation`, `news_featured`, `memorial_victims`, ...) live in the database `api/config.php` connects to: read them with `kop_seed_pdo()`, never `$wpdb` (which silently finds nothing on the live site).

## Facility Model (v2, canonical)

Since the 2026-09-18 write switch every reader and writer uses these tables; `facilities_master` is the frozen legacy copy. Schema: `docs/FACILITY-SCHEMA.md`; migration history: `docs/DATA-MODEL-MIGRATION.md`. Table names come from `kop_facility_table()` (`inc/facility-store.php`), created by `kop_migration_create_tables()` (`inc/facility-migration.php`).

### facilities_v2
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`, plus stored generated columns read from the document: `schema_version`, `name`, `name_key`, `state`, `city`, `country`, `status`, `facility_type`, `start_year`, `end_year`

**Purpose:** One row per facility, the whole record as one JSON document (identification and past names, location, operatingPeriod, facilityDetails, staff with `personId`, `federalFunding`, `resourceLinks`, `legacy.mergedFacilities`, ...)
**Notes:** New ids start at 100000. Duplicates are merged at KOP Tools > Merge Duplicates (`inc/facility-merge.php`); every table holding facility ids is listed in `kop_fmerge_ref_tables()`.

### {prefix}kop_operators / {prefix}kop_operator_facilities / {prefix}kop_operator_links
**Columns:** operators: `id`, `unique_name`, `name`, `json_data`, `document_folder_id`, `legacy_master_id`, `created_at`, `updated_at`; operator_facilities: `operator_id`, `facility_id`, `relationship` (`current`/`past`/`other`), `sort_order`; operator_links: `operator_id`, `link_kind` (`news`/`lawsuit`), `link_id`, `link_type`, `created_by`, `created_at`

**Purpose:** Parent companies (/operator/ pages, `inc/operator-history.php`; `json_data.history` holds the written history) and which facilities each runs or ran.

### {prefix}kop_facility_locations / {prefix}kop_facility_identity / {prefix}kop_migration_state
**Purpose:** Which state/country pages list a facility (`facility_id`, `location_key`, `role`, `source`, `needs_review`), stable identity keys for facility ids, and the migration's switches (`state_key = 'writes'` holds the write mode).

### {prefix}kop_program_homes / {prefix}kop_program_groups / {prefix}kop_program_conversions
**Columns:** homes: `home_id`, `program_id`, `home_name`, `created_at`; groups: `program_id`, `created_record`, `created_at`, `created_by`

**Purpose:** Licensed homes and cottages grouped under one program record (`inc/program-homes.php`), and parent companies converted into one program, saved whole for Undo (`inc/program-homes-convert.php`).

### {prefix}kop_addresses / {prefix}kop_facility_addresses
**Purpose:** Physical address ids and which facility stood where (`api/manage-addresses.php`).

---

## Legacy Data Tables

### facilities_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** The pre-2026-09-18 master facility database - stores all projects as JSON. Frozen: saves go to `facilities_v2` (a legacy write gets a 409 from `kop_v2_exit_if_legacy_frozen`).
**Primary Key:** `id`
**Unique Keys:** `unique_name`

**Row kinds:**
- **Project rows** - operator/company/standalone-facility projects. These power the TTI Program Index directory.
- **`__facility_ref` rows** - identity-only rows created by `api/promote-facilities-to-rows.php` so nested facilities inside other projects can have a stable `facility_id` for foreign-key references (e.g., `news_facility_links`). Identified by `json_data.__facility_ref === true`. These are skipped by `kop_get_facilities_projects_from_database()` and don't appear in the public directory.

---

### suggested_edits
**Columns:** `id`, `master_id`, `edited_json_data`, `reason`, `submitter_ip`, `status`, `created_at`, `reviewed_at`

**Purpose:** Public suggestions for facility data edits
**Primary Key:** `id`
**Status Values:** `pending`, `approved`, `rejected`
**Notes:** `master_id` is a sanitized project/program-name string (not a numeric FK). `reviewed_at` is populated by `api/process-edit.php` when a submission is approved or rejected.

---

### referrers_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Referrer database - stores education consultants and agencies
**Primary Key:** `id`
**Unique Keys:** `unique_name`

---

### {prefix}transporters_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Youth transport companies (the data forms' Transporters tab, /youth-transport-companies/)
**Notes:** `wpdl_transporters_master` on the live site; always resolve the name with `kop_transporters_table()` (`inc/utilities.php`).

---

### providers_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Mental health providers outside the TTI that use its practices or refer to it (psychiatric wards, PHP/IOP, day schools, respite, outpatient); the data forms' Mental Health Providers tab, /mental-health-providers/
**Notes:** Created on first save or approval. Never part of the facility tables.

---

### marketers_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Marketing companies that sell programs to parents (websites, "independent" directories, help lines, sold inquiries, reputation work, instructions for AI chatbots); /marketing-companies/
**Notes:** `json_data` holds `{name, category, data: {marketerCompany, category}}`; every save path keeps only that (`kop_marketer_keep_data()`, `api/lib-marketers.php`). Seeded once per version from `seeds/marketers.json` (adds missing records, never changes one).

---

### tradeorgs_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Trade organizations (NATSAP, the OBH Council, IECA, ISPA, NATC...); /trade-organizations/ lists each with the programs whose facility records name it under memberships or accreditations
**Notes:** `json_data` holds `{name, category, data: {tradeOrg, category}}` (`kop_tradeorg_keep_data()`, `api/lib-tradeorgs.php`). Seeded from `seeds/tradeorgs.json` like marketers.

---

### locations_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Location aggregate database - stores state/country facility collections
**Primary Key:** `id`
**Unique Keys:** `unique_name`

---

## Submission Tables

### wiki_submissions
**Columns:** `id`, `program_name`, `city_state`, `organization`, `program_type`, `years_active`, `json_data`, `generated_markdown`, `original_markdown`, `status`, `submitted_by`, `submission_notes`, `reviewer_notes`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`

**Purpose:** Wiki editor form submissions for TTI programs
**Primary Key:** `id`
**Status Values:** `draft`, `submitted`, `approved`, `published`, `rejected`

---

### news_submissions
**Columns:** `id`, `article_title`, `alternate_title`, `author`, `publication_name`, `publication_date`, `article_url`, `article_type`, `facilities_mentioned`, `staff_mentioned`, `survivors_mentioned`, `content_warnings`, `summary`, `json_data`, `generated_output`, `story_group_id`, `status`, `submitted_by`, `submission_notes`, `reviewer_notes`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`

**Purpose:** News processor form submissions
**Primary Key:** `id`
**Article Types:** `lawsuit`, `event`, `expose`, `arrest`, `closure`, `corporate`, `general`
**Status Values:** `draft`, `submitted`, `approved`, `published`, `rejected`
**Notes:** `story_group_id` clusters articles about the same story from different outlets (set automatically on save by `api/news-story-groups.php`; value is the lowest article id in the group, NULL = standalone). Rebuild all clusters with `api/rebuild-news-story-groups.php`.

---

### submission_attachments
**Columns:** `id`, `submission_type`, `submission_id`, `file_name`, `file_path`, `file_type`, `file_size`, `description`, `created_at`

**Purpose:** File attachments for submissions
**Primary Key:** `id`
**Submission Types:** `wiki`, `news`

---

### news_facility_links
**Columns:** `news_id`, `facility_id`, `link_type`, `created_at`, `created_by`

**Purpose:** Structured join between news_submissions and facility records (`facilities_v2` ids)
**Primary Key:** (`news_id`, `facility_id`) - composite
**Indexes:** `by_facility` (facility_id, news_id), `link_type`
**Link Types:** `mentioned`, `primary`, `related`
**Notes:** Coexists with `news_submissions.facilities_mentioned` (the free-text JSON array of names). The links table is the structured FK relationship; the JSON array remains for legacy display/search.

---

### lawsuit_facility_links
**Columns:** `lawsuit_id`, `facility_id`, `link_type`, `created_by`, `created_at`

**Purpose:** Structured join between lawsuits and facility records (`facilities_v2` ids), synced from `lawsuits.facilities_mentioned` on every save/approval (`api/lawsuit-facility-links.php`; backfill with `api/backfill-lawsuit-facility-links.php`)
**Primary Key:** (`lawsuit_id`, `facility_id`) - composite
**Link Types:** `mentioned` (owned by the sync), `primary`, `related` (admin-created, survive re-syncs)
**Notes:** The public lawsuit tracker uses these rows to turn facility tags into program-index links.

---

### lawsuit_news_links
**Columns:** `lawsuit_id`, `news_id`, `link_type`, `match_reason`, `created_by`, `created_at`

**Purpose:** News articles that cover a lawsuit, matched automatically on save/approval from either side (`api/lawsuit-news-links.php`; backfill with `api/backfill-lawsuit-news-links.php?dry_run=1`)
**Primary Key:** (`lawsuit_id`, `news_id`) - composite
**Link Types:** `auto` (owned by the sync), `manual` (survives re-syncs)
**Match Reasons:** `source_url`, `case_number`, `case_name`, `plaintiff`, `caption_party`, `filing_news`, `sole_case` (strongest first; see the header of `api/lawsuit-news-links.php`)
**Notes:** Only approved/published articles are ever linked or shown; a rejected article drops off the case card on its next review action.

---

### journalists (internal only)
**Columns:** `id`, `name`, `name_key`, `aliases`, `outlet`, `email`, `phone`, `social`, `website`, `location`, `beat`, `outreach`, `notes`, `status`, `source`, `created_at`, `updated_at`

**Purpose:** Journalists who cover the TTI, extracted from `news_submissions.author` bylines (`api/lib-journalists.php`) and curated in `api/manage-journalists.php`. Admin-only; never exposed through REST, search or a public page.
**Primary Key:** `id`; **Unique Key:** `name_key` (lowercase, accent-folded)
**Outreach:** `not_contacted`, `contacted`, `responded`, `ongoing`, `do_not_contact`
**Status:** `active`, `ignored` (not a journalist; the row stays so rescans skip the name)
**Notes:** `aliases` (one per line) match bylines like `name`. Outlets, article counts and dates are derived from `journalist_articles`, never stored.

---

### journalist_articles (internal only)
**Columns:** `journalist_id`, `news_id`

**Purpose:** Which journalist wrote which news entry. Rebuilt per article on save (`api/save-news-submission.php`) and for all entries by the manager's "Scan news entries" button.
**Primary Key:** (`journalist_id`, `news_id`) - composite
**Notes:** Rejected and deleted entries are never linked.

---

### saved_form_values
**Columns:** `id`, `form_type`, `category`, `value`, `use_count`, `created_at`, `updated_at`

**Purpose:** Saved autocomplete values for form fields
**Primary Key:** `id`
**Form Types:** `wiki`, `news`
**Unique Keys:** `form_type`, `category`, `value` (combined)

---

## Records Tables (records DB)

### lawsuits / legislation
**Columns (lawsuits):** `id`, `case_name`, `case_number`, `court`, `jurisdiction`, `filing_date`, `status`, `plaintiffs`, `defendants`, `facilities_mentioned`, `staff_mentioned`, `organizations_mentioned`, `claims`, `outcome`, `settlement_amount`, `summary`, `source_urls`, `document_urls`, `tags`, `filebird_folder_id`, `publication_status`, `submitted_by`, `reviewer_notes`, `published_at`, `created_at`, `updated_at`
**Columns (legislation):** `id`, `bill_number`, `bill_title`, `jurisdiction`, `chamber`, `session_year`, `bill_type`, `sponsors`, `status`, `introduced_date`, `last_action_date`, `last_action_text`, `subject_tags`, `summary`, `full_text_url`, `official_url`, `position`, `facilities_affected`, `tags`, `filebird_folder_id`, `publication_status`, `submitted_by`, `reviewer_notes`, `published_at`, `created_at`, `updated_at`

**Purpose:** The lawsuit tracker and legislation pages (`api/init-state-content-db.php`). `legislation_news_links` (`legislation_id`, `news_id`, `created_by`, `created_at`) ties bills to articles.

### record_status_proposals / record_status_sources
**Columns (proposals):** `id`, `kind`, `record_id`, `changes`, `change_hash`, `quote`, `quote_found`, `source_url`, `source_label`, `status`, `previous`, `reviewed_by`, `reviewed_at`, `created_at`
**Columns (sources):** `kind`, `record_id`, `source_url`, `source_ref`, `last_checked`, `last_outcome`, `last_detail`, `last_hash`

**Purpose:** The nightly bill and lawsuit status check (`inc/status-checks.php`): where each record is checked and the changes it found, waiting at Review inbox > Bill and lawsuit updates (Apply, exact Undo; a dismissed change never comes back).

### news_featured / news_featured_nominations
**Columns (featured):** `news_id`, `note`, `featured_by`, `featured_at`, `updated_at`
**Columns (nominations):** `news_id`, `note`, `reason`, `tier`, `status`, `nominated_by`, `created_at`, `decided_by`, `decided_at`, `feat_entry`

**Purpose:** Essential reporting: articles picked as especially good journalism, with an optional note (`inc/featured-reporting.php`, /essential-reporting/), and the nominations waiting at Submissions Review > Nominated (`inc/featured-nominations.php`, seeded from `js/data/featured/nominations.json`).

### facility_closure_reports / news_closure_scans
**Columns (reports):** `id`, `news_id`, `facility_id`, `program_name`, `location`, `stage`, `target_status`, `closure_date`, `quote`, `quote_found`, `status`, `previous_status`, `previous_end_year`, `applied_note`, `reviewed_by`, `reviewed_at`, `created_at`
**Columns (scans):** `news_id`, `content_hash`, `outcome`, `attempts`, `detail`, `scanned_at`

**Purpose:** Closures the hourly news scan found, pending until an admin confirms (`inc/closure-reports.php`).

### news_facility_candidates / news_facility_scans
**Columns (candidates):** `id`, `name_key`, `mention`, `news_id`, `decision`, `facility_id`, `detail`, `reviewed_by`, `created_at`, `updated_at`

**Purpose:** Facility names from the news with no record and what the scan decided (`inc/facility-discovery.php`); scans as for closures.

### memorial_victims
**Columns:** `id`, `name`, `age`, `program`, `date_of_death`, `date_precision`, `cause_of_death`, `cause_category`, `location`, `source_name`, `source_url`, `kop_url`, `notes`, `publication_status`, `created_at`, `updated_at`

**Purpose:** The memorial (`api/init-memorial-db.php`).

### indigenous_schools / indigenous_school_news
**Columns (schools):** `id`, `name`, `name_key`, `other_names`, `country`, `region`, `city`, `nations`, `run_by`, `opened`, `closed`, `status`, `notes`, `links`, `review`, `source`, `created_by`, `created_at`, `updated_at`

**Purpose:** Indian boarding, residential and mission schools, kept out of the facility tables; `indigenous_school_news` (`school_id`, `news_id`, ...) lists their articles (`inc/indigenous-schools.php`, /indian-boarding-schools/).

### young_adult_programs
**Columns:** `id`, `name`, `name_key`, `other_names`, `city`, `state`, `country`, `ages`, `program_type`, `run_by`, `opened`, `closed`, `status`, `notes`, `links`, `facts`, `review`, `source`, `created_by`, `created_at`, `updated_at`

**Purpose:** Programs for people 18 and older, kept apart from `facilities_v2`; `facts` is a JSON list of cited Woodbury items (`inc/young-adult-programs.php`, /young-adult-programs/).

### bug_reports
**Columns:** `id`, `feature`, `category`, `description`, `steps`, `contact`, `notify_updates`, `page_url`, `page_title`, `user_agent`, `viewport`, `console_errors`, `context_json`, `ip_hash`, `status`, `admin_note`, `created_at`, `updated_at`

**Purpose:** The site's bug reporter (`api/save-bug-report.php`).

---

## Inspection Tables

### inspection_facilities / inspection_reports
**Columns (facilities):** `id`, `state` (2-letter code), `facility_name`, `full_address`, `phone`, `program_category`, `program_name`, `executive_director`, `bed_capacity`, `license_exp_date`, `relicense_visit_date`, `action`, `scraped_timestamp`, `created_at`, `updated_at`
**Columns (reports):** `id`, `facility_id`, `report_id`, `report_date`, `report_url`, `raw_content`, `content_length`, `is_structured`, `summary`, `categories_json`, `created_at`, `updated_at`

**Purpose:** Scraper output for the /xx-reports/ pages (`api/inspections-write.php`, read by `api/inspections-read.php`). `facility_id` here is the inspection facility row, not a `facilities_v2` id. Featured columns are added by `api/update-schema.php`.

### inspection_highlights / inspection_highlight_scans
**Columns (highlights):** `id`, `report_id`, `facility_id`, `finding_key`, `text_hash`, `state`, `category`, `categories`, `score`, `finding_date`, `excerpt`, `standard`, `state_label`, `kind`, `corrected_on_site`, `status`, `reviewed_by`, `reviewed_at`, `review_note`, `scanner_version`, `created_at`, `updated_at`

**Purpose:** Serious findings picked from report text by the severe scan (`inc/inspection-highlights.php`); approved ones show on facility pages and /severe-reports/. Scans record which report was read by which scanner version.

### inspection_highlight_summaries
**Columns:** `highlight_id`, `excerpt_hash`, `summary`, `status`, `reasons`, `provider`, `reviewed_by`, `reviewed_at`, `created_at`

**Purpose:** AI-drafted plain-language summaries of hard-to-read findings, shown only once approved and only while `excerpt_hash` still matches (`inc/highlight-summaries.php`).

### inspection_report_counts
**Columns:** `report_id`, `facility_id`, `state`, `year`, `content_hash`, `counts` (JSON verdicts), `version`, `counted_at`

**Purpose:** Each report's state verdicts counted once, for the Inspection Rankings (`inc/inspection-rollup.php`).

---

## People

### {prefix}kop_people / {prefix}kop_person_roles
**Columns (people):** `id`, `name`, `name_key`, `aliases`, `merged_into`, `notes`, `pool` (`industry`/`harmed`/`press`), `created_at`, `updated_at`
**Columns (roles):** `record_kind`, `record_id`, `list`, `position`, `person_id`, `name`, `role`, `ref`

**Purpose:** One id per person named anywhere in the records (`inc/people.php`, merged at KOP Tools > Merge People); roles are rebuilt from the records by the hourly sync.

---

## Review and Import Queues (WordPress DB)

| Table | What it holds | Code |
|---|---|---|
| `{prefix}kop_review_log` | Every review inbox action, with its Undo | `inc/review-inbox-log.php` |
| `{prefix}kop_review_holds` | Snoozed items and items handed to one admin | `inc/review-inbox-log.php` |
| `{prefix}kop_review_tags` | Tags on review inbox items | `inc/review-inbox.php` |
| `{prefix}kop_volunteers` / `{prefix}kop_volunteer_recs` | Volunteer reviewers (hash of their link's token only) and their approve/reject/not sure recommendations | `inc/review-volunteers.php` |
| `{prefix}kop_auto_connect` | Exact-name connections made without a click, so an undone one is never redone | `inc/auto-connect.php` |
| `{prefix}kop_submission_followups` | "Email me when this has been reviewed" requests (address blanked once sent) | `inc/submission-followup.php` |
| `{prefix}kop_woodbury_mentions` / `{prefix}kop_woodbury_facts` | Woodbury Reports pages and facts waiting to be filed | `inc/woodbury-mentions.php`, `inc/woodbury-facts.php` |
| `{prefix}kop_fornits_topics` / `{prefix}kop_fornits_items` | Fornits forum threads and what the AI read from them | `inc/fornits.php` |
| `{prefix}kop_gdoc_links` | Drive Docs links (Google Docs export, HEAL, wiki, SCIAD NET, data audit sources) | `inc/drive-docs.php` |
| `{prefix}kop_state_list_rows` | Rows of state-published facility lists, matched or not | `inc/state-lists.php` |
| `{prefix}kop_doc_types` / `{prefix}kop_doc_type_suggestions` | Each media library document's type (student handbook, deposition, ...) and the AI's guesses awaiting review | `inc/doc-types.php` |
| `{prefix}kop_glossary_nodes` / `_entries` / `_aliases` / `_tags` / `_log` | The TTI glossary | `inc/glossary-store.php` |
| `{prefix}kop_glossary_feedback` | Readers' glossary notes | `inc/glossary-feedback.php` |
| `{prefix}kop_media_folder_tags` / `{prefix}kop_folder_links` | Extra folder memberships; legacy/current folder equivalence | `inc/database.php`, `api/link-folders.php` |

---

## Utility Scripts

### View Database Schema
Get a complete overview of all tables and columns:

```bash
# Browser (HTML view)
https://kidsoverprofits.org/wp-content/themes/child/api/get-database-schema.php?format=html

# Browser (JSON)
https://kidsoverprofits.org/wp-content/themes/child/api/get-database-schema.php

# Command line
php api/get-database-schema.php
```

---

## Example SELECT Queries

### Get all facilities
```sql
SELECT id, name, state, city, status, start_year, end_year
FROM facilities_v2
ORDER BY updated_at DESC;
```

### Get pending suggestions
```sql
SELECT id, master_id, status, submitter_ip, created_at
FROM suggested_edits
WHERE status = 'pending'
ORDER BY created_at ASC;
```

### Get all wiki submissions
```sql
SELECT id, program_name, city_state, organization, status, created_at
FROM wiki_submissions
ORDER BY created_at DESC;
```

### Get recent news articles
```sql
SELECT id, article_title, publication_name, publication_date, article_type, status
FROM news_submissions
WHERE status != 'rejected'
ORDER BY publication_date DESC
LIMIT 20;
```

### Get autocomplete values for a specific category
```sql
SELECT value, use_count
FROM saved_form_values
WHERE form_type = 'news' AND category = 'publications'
ORDER BY use_count DESC;
```