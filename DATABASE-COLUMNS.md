# Database Columns Reference

Quick reference for all database tables and their columns.

## Core Data Tables

### facilities_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Master facility database - stores all projects as JSON
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

**Purpose:** Structured join between news_submissions and facilities_master
**Primary Key:** (`news_id`, `facility_id`) - composite
**Indexes:** `by_facility` (facility_id, news_id), `link_type`
**Link Types:** `mentioned`, `primary`, `related`
**Notes:** Coexists with `news_submissions.facilities_mentioned` (the free-text JSON array of names). The links table is the structured FK relationship; the JSON array remains for legacy display/search.

---

### lawsuit_facility_links
**Columns:** `lawsuit_id`, `facility_id`, `link_type`, `created_by`, `created_at`

**Purpose:** Structured join between lawsuits and facilities_master, synced from `lawsuits.facilities_mentioned` on every save/approval (`api/lawsuit-facility-links.php`; backfill with `api/backfill-lawsuit-facility-links.php`)
**Primary Key:** (`lawsuit_id`, `facility_id`) - composite
**Link Types:** `mentioned` (owned by the sync), `primary`, `related` (admin-created, survive re-syncs)
**Notes:** The public lawsuit tracker uses these rows to turn facility tags into program-index links.

---

### lawsuit_news_links
**Columns:** `lawsuit_id`, `news_id`, `link_type`, `match_reason`, `created_by`, `created_at`

**Purpose:** News articles that cover a lawsuit, matched automatically on save/approval from either side (`api/lawsuit-news-links.php`; backfill with `api/backfill-lawsuit-news-links.php?dry_run=1`)
**Primary Key:** (`lawsuit_id`, `news_id`) - composite
**Link Types:** `auto` (owned by the sync), `manual` (survives re-syncs)
**Match Reasons:** `source_url`, `case_number`, `case_name`, `plaintiff`, `filing_news`
**Notes:** Only approved/published articles are ever linked or shown; a rejected article drops off the case card on its next review action.

---

### saved_form_values
**Columns:** `id`, `form_type`, `category`, `value`, `use_count`, `created_at`, `updated_at`

**Purpose:** Saved autocomplete values for form fields
**Primary Key:** `id`
**Form Types:** `wiki`, `news`
**Unique Keys:** `form_type`, `category`, `value` (combined)

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

### View All Data
Display data from any table:

```bash
# View specific table (HTML)
https://kidsoverprofits.org/wp-content/themes/child/api/get-all-data.php?table=facilities_master&format=html

# View specific table (JSON)
https://kidsoverprofits.org/wp-content/themes/child/api/get-all-data.php?table=facilities_master

# View all tables
https://kidsoverprofits.org/wp-content/themes/child/api/get-all-data.php?show=all

# Export to CSV
https://kidsoverprofits.org/wp-content/themes/child/api/get-all-data.php?table=facilities_master&format=csv

# Limit results
https://kidsoverprofits.org/wp-content/themes/child/api/get-all-data.php?table=facilities_master&limit=10
```

---

## Example SELECT Queries

### Get all facilities
```sql
SELECT id, unique_name, json_data, created_at, updated_at
FROM facilities_master
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