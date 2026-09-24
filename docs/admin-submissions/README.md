# Admin Submissions

The **Submissions Review** page is the admin queue for everything the public sends in: wiki entries, news articles, data form suggestions (facility edits), legislation tips and lawsuit tips. Approving here is what moves a submission into the live data.

## Where it lives

- **Page:** `templates/page-admin-submissions.php` (Template Name "Admin - Submissions Review"). Auto-created by `inc/admin.php` as a private page with the slug `submissions-review` (https://kidsoverprofits.org/submissions-review/). The template calls `kop_require_page_capability('manage_options')`, so logged-out visitors are sent to the login screen.
- **Menu:** listed as "Submissions Review" under **KOP Data Tools** in wp-admin and the admin bar (`kop_data_tool_pages()` in `inc/admin.php`). When the KOP Tools plugin is active, the plugin's **KOP Tools** menu lists it instead.
- **Assets:** `js/admin-submissions.js` and `css/admin-submissions.css`, enqueued by `kop_enqueue_admin_submissions()` in `inc/enqueue.php`. It localizes `adminSubmissionsConfig` (`apiBase`, `manageApi`, `scanApi`, `reviewer`). `reviewer` is the logged-in user's display name or email, used as `reviewed_by`.
- **Backend:** `api/manage-submissions.php` (admin only: `manage_options`, 403 otherwise).

## Submission types

| Type | Table | Status column | Unreviewed value |
|---|---|---|---|
| `wiki` | `wiki_submissions` | `status` | `submitted` |
| `news` | `news_submissions` | `status` | `submitted` |
| `data` | `suggested_edits` | `status` | `pending` |
| `legislation` | `legislation` | `publication_status` | `pending` |
| `lawsuit` | `lawsuits` | `publication_status` | `pending` |

The UI's "Pending Review" filter sends `submitted`; the API maps it to each table's own unreviewed value (`kop_submission_schema()`).

Public insert paths: `api/save-wiki-submission.php`, `api/save-news-submission.php` (also used by nightly article discovery), `api/save-suggestion.php`, `api/save-legislation-suggestion.php`, `api/save-lawsuit-suggestion.php`.

## Page features

- **Stats:** Pending / Approved / Published / Rejected counts for the selected type.
- **Filters:** type, status, and keyword search (per-type columns: program name/location for wiki, title/publication/author for news, `master_id` for data, title/number/jurisdiction for legislation and lawsuits).
- **Reject All Pending:** rejects every pending submission currently listed.
- **Duplicate URL warning:** flags news and wiki submissions whose URL appears in more than one listed submission.
- **URL safety check:** scans a submission's URLs through Cloudmersive via `api/scan-submission-urls.php`.
- **Wiki markdown editor:** side-by-side "Original / Uploaded" vs. "Generated / Editable" markdown with a diff view; Save Edits writes `generated_markdown`.
- **Structured field editor** (legislation, lawsuit, news, data): edits a whitelist of fields (`kop_editable_fields()`); saving never changes status. For data submissions the editable surface is the raw `edited_json_data` plus `reason`.
- **Facility link** (wiki only): suggest, confirm, set or remove the wiki entry's link to a facility via `api/link-wiki-facility.php`.
- **Full form data:** collapsible JSON view of the stored record.
- **Reviewer notes** and the previous review (by/at) where the table has those columns (wiki, news).

## What each action does

- **Approve**
  - `wiki`: status `approved`, then upserts `wiki_master` by slug and fills blank fields on the linked facility (`kop_merge_wiki_into_facility()` in `api/sync-wiki-facilities.php`, no overwrites).
  - `news`: status `approved`, then re-syncs `lawsuit_news_links`.
  - `data`: not a status flip. `kop_apply_suggested_edit()` in `api/lib-suggested-edits.php` applies the edit (see Facility data below) and marks the row `approved`. Only rows still `pending` can be applied. `publish` is treated the same as approve.
  - `legislation` / `lawsuit`: go straight to `published` and stamp `published_at` (no separate publish step). A published lawsuit also syncs `lawsuit_facility_links` and `lawsuit_news_links`, resolves a FileBird folder from its first linked facility if none is set, and files its case documents into that folder.
- **Reject:** sets the rejected status; for news it also re-syncs lawsuit links so the article drops off case cards.
- **Mark as Published:** sets `published` (wiki also updates `wiki_master` as on approve).
- **Delete:** removes the rows and their `submission_attachments` rows.

### Facility data (v2 model)

Since 2026-09-18 facility writes go to the v2 tables (`facilities_v2` and related; `inc/facility-v2-writer.php`, `inc/facility-store.php`) and the legacy `facilities_master` / `locations_master` tables are frozen. When v2 writes are active (`kop_v2_writes_active()`), an approved operator or location suggestion is saved into v2 as a partial update: only the fields the suggestion lists change, nothing is removed. Referrer and transporter suggestions still write `referrers_master` / `transporters_master`. The wiki facility merge also reads and writes v2 when active. See `docs/DATA-MODEL-MIGRATION.md`.

## Notifications

Each public insert path calls `kop_notify_admins()` (`inc/submission-notify.php`), which emails the addresses in the `kop_submission_notify_emails` option (comma or newline separated; defaults to the site admin email) with a link to the right review screen:

- `suggested_edit`, `wiki`, `news` and `news_auto` -> this page, opened on the matching tab (`?type=data`, `?type=wiki`, `?type=news`; `js/admin-submissions.js` reads the parameter)
- `lawsuit` -> Lawsuit Admin (`templates/page-admin-lawsuits.php`), `legislation` -> Legislation Admin (`templates/page-admin-legislation.php`)
- `news_auto` (articles posted by nightly discovery) is queued and sent as a daily digest; change the digested types with the `KOP_SUBMISSION_DIGEST_TYPES` constant.

Bug reports use their own mailer (`inc/bug-report-notify.php`) and are triaged under **KOP Data Tools > Bug Reports**. Glossary reader notes are reviewed under **KOP Data Tools > Glossary Feedback** (`inc/glossary-feedback.php`); neither goes through this page.

## Other review screens

- **Approve Edits** (wp-admin menu, `api/approve-edits.php`): older combined queue for suggestions, news and wiki; suggestions go through `api/process-edit.php`, news and wiki through `api/manage-submissions.php`.
- `templates/page-wiki-editor.php` and `templates/page-news-processor.php` also work on wiki and news submissions.
- `templates/page-admin-lawsuits.php`, `templates/page-admin-legislation.php`, `templates/page-admin-volunteers.php` and `templates/page-admin-data-manager.php` are the other admin tool pages in the same menu.

## API reference

`api/manage-submissions.php`. Every call needs `type` = `wiki` | `news` | `data` | `legislation` | `lawsuit`.

GET:
- `?action=list&type=news&status=submitted&search=...&limit=100&offset=0`: paginated list (limit capped at 200). Returns `data`, `total`, `limit`, `offset`.
- `?action=get&type=news&id=123`: one submission.

POST (JSON body):
- `{ "action": "approve" | "reject" | "publish", "type": "news", "ids": [123], "reviewerNotes": "...", "reviewedBy": "..." }`
- `{ "action": "delete", "type": "news", "ids": [123] }`
- `{ "action": "update_status", "type": "news", "ids": [123], "status": "approved" }`: plain status change, no side effects.
- `{ "action": "update_fields", "type": "lawsuit", "id": 123, "fields": { ... } }`: structured editor save (not wiki). Editing news or lawsuit facility mentions re-syncs `news_facility_links` / `lawsuit_facility_links`.
- `{ "action": "update_markdown", "type": "wiki", "id": 123, "generated_markdown": "..." }`
- `{ "action": "stats", "type": "news" }`: counts by status (wiki and news also return each other's counts and news counts by `article_type`).

`id` may be passed in place of `ids` for single-row actions.
