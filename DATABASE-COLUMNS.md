# Database Columns Reference

Quick reference for all database tables and their columns.

## Core Data Tables

### facilities_master
**Columns:** `id`, `unique_name`, `json_data`, `created_at`, `updated_at`

**Purpose:** Master facility database - stores all projects as JSON
**Primary Key:** `id`
**Unique Keys:** `unique_name`

---

### suggested_edits
**Columns:** `id`, `unique_name`, `json_data`, `status`, `submitted_by`, `submission_reason`, `created_at`, `updated_at`

**Purpose:** Public suggestions for facility data edits
**Primary Key:** `id`
**Status Values:** `pending`, `approved`, `rejected`

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
**Columns:** `id`, `article_title`, `alternate_title`, `author`, `publication_name`, `publication_date`, `article_url`, `article_type`, `facilities_mentioned`, `staff_mentioned`, `survivors_mentioned`, `content_warnings`, `summary`, `json_data`, `generated_output`, `status`, `submitted_by`, `submission_notes`, `reviewer_notes`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`

**Purpose:** News processor form submissions
**Primary Key:** `id`
**Article Types:** `lawsuit`, `event`, `expose`, `arrest`, `closure`, `corporate`, `general`
**Status Values:** `draft`, `submitted`, `approved`, `published`, `rejected`

---

### submission_attachments
**Columns:** `id`, `submission_type`, `submission_id`, `file_name`, `file_path`, `file_type`, `file_size`, `description`, `created_at`

**Purpose:** File attachments for submissions
**Primary Key:** `id`
**Submission Types:** `wiki`, `news`

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
SELECT id, unique_name, status, submitted_by, created_at
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