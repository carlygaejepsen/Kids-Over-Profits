# Wiki Editor

The **Wiki Editor** (page title "TTI Wiki Entry Generator") helps users create, format and manage Reddit wiki entries for TTI programs and parent organizations. It turns a structured form into standardized Reddit Markdown, can parse existing wiki Markdown back into the form, and saves entries to the `wiki_submissions` table for review. Approved entries are shown publicly on the Wiki Feed page.

## Architecture

- **Page template:** `templates/page-wiki-editor.php` (Template Name "TTI Wiki Entry Generator").
- **Asset loading:** `kop_enqueue_wiki_editor_assets()` in `inc/enqueue.php`, which runs only on that template. It localizes `wikiEditorSettings` (`isAdmin`, `nonce`, `saveApi`, `stubsApi`, `facilitySearchUrl`, `facilityPickerApi`, `foldersUrl`, `markdownBaseUrl`). `kop_enqueue_document_viewer_panel()` in the same file adds the docked FileBird document viewer to this page.
- **Frontend scripts** (plain scripts, loaded in this order):
  - `js/auto-linker.js` - links mentioned program names; reads `js/data/reddit-wiki/` (`autoLinkerSettings.basePath`).
  - `js/wiki-parser.js` - Markdown to form data (import, bulk upload, loading entries).
  - `js/wiki-generation.js` - form data to Reddit Markdown.
  - `js/wiki-program-picker.js` - program index picker popup; uses the shared FileBird folder browser and `kop/v1/folders`.
  - `js/wiki-editor.js` - form state, index browser, import/export and API calls.
  - `js/tutorial-overlay.js` + `js/wiki-editor-tutorial.js` - guided tour.
- **Styles:** `css/wiki-editor.css`, `css/wiki-program-picker.css`, `css/tutorial-overlay.css`.
- **Backend APIs:**
  - `api/save-wiki-submission.php` - GET lists/searches (`?search=`, `?status=`, `?limit=`, `?offset=`) or fetches one (`?id=`) submission; POST creates or updates one.
  - `api/wiki-stubs.php` - empty wiki slugs still needing an entry (from `markdown_output/empty_files_updated.md` plus `markdown_output/wiki_stub_overrides.json`) and the names/slugs already completed.
  - `api/facility-search.php`, `api/facility-picker.php` - program index lookup for the picker.
  - `api/extract-wiki-from-prose.php` - AI extraction of form fields from free prose (shared provider layer in `api/ai-providers.php`, Groq by default).

## Key Features

### 1. Form-Based Entry
- **Entry type:** individual facility/program or parent organization (organization mode shows "Facilities Operated" and hides facility-only fields).
- **Program details:** name, location, years active, owner, affiliations, additional campuses, ownership changes.
- **Staff:** current and former staff, with previous roles.
- **Structure:** education, therapy/treatment types, targeted clinical diagnoses and behavioral issues.
- **Rules and punishments:** individual items or a full advanced section.
- **Allegations:** checkbox groups (abuse, neglect and deprivation, confinement and restraints, mistreatment and practices) plus custom entries.
- **Lawsuits, news articles, survivor testimonies, related programs, media links.**
- **Linked program index entry:** every entry must be tied to a program index record (`facilities_master` `unique_name`) through the program picker before it can be submitted. The picker can also set the program's document folder.

### 2. Markdown Import/Export
- **Generate Wiki Code:** builds the Reddit Markdown preview; it can be converted to past tense and copied to the clipboard.
- **Markdown Editor mode:** edit the raw Markdown instead of the form.
- **Import from Clipboard:** paste (or load a `.txt`/`.md` file of) existing wiki Markdown or plain text to fill the form.
- **Bulk Upload:** upload several `.md`/`.txt` files at once; each is parsed and saved as a `draft` (bulk uploads carry no program link, so they must be linked before submission).
- **Extract from Prose (AI):** paste prose about a program and fill the fields it can infer.

### 3. Index Browser
- **Locations** and **Organizations** tabs list wiki index pages from `js/data/reddit-wiki/index.json` and `js/data/reddit-wiki/programs-XX.json` / `programs-CORPORATE.json`; picking a program loads its saved submission (matched by name through `save-wiki-submission.php?search=`) or its local index Markdown from `markdown_output/`.
- **Stubs** lists every wiki entry that is still empty (from `api/wiki-stubs.php`, falling back to `js/data/reddit-wiki/empty-slugs.json`, then `markdown_output/empty_files_updated.md`).

### 4. Database and Review
- **Save:** "Submit to Database" posts to `api/save-wiki-submission.php`, which writes to `wiki_submissions`.
- **Permissions:** non-admins may only set `draft` or `submitted` and may only update rows still in one of those statuses. Other statuses (approved, published, rejected) are admin-only; deleting (a soft `status = 'deleted'`) also requires the `kop_wiki_editor` nonce sent as `X-KOP-Nonce`. Submitter contact details are returned to admins only.
- **Program link:** any non-draft save without `facilityUniqueName` is rejected (HTTP 422, `facility_link_required`).
- **Email notification:** when a non-admin creates a new submission, `kop_notify_admins('wiki', ...)` (`inc/submission-notify.php`) mails the admins. Recipients come from the `kop_submission_notify_emails` option (falling back to the site admin email); admin saves are not mailed.
- **Review:** submissions are approved, rejected or published on the Submissions Review page (`templates/page-admin-submissions.php`, `api/manage-submissions.php`; see [Admin Submissions](../admin-submissions/README.md)). Approving or publishing a wiki submission upserts it into `wiki_master`. Admins can also confirm or repoint the program link through `api/link-wiki-facility.php`.

### 5. Wiki Feed
`templates/page-wiki-feed.php` (Template Name "Wiki Feed") lists `approved` and `published` rows from `wiki_submissions`, 50 per page, sortable by newest or name, filterable with `?search=`, and renders their Markdown with `js/marked.min.js` sanitized by `js/purify.min.js`. Site search and the generated facility pages link to it with `?search=`.

## Usage

1. **Navigate** to the Wiki Editor page, or pick an entry from the index browser or Stubs tab.
2. **Input data:** fill in the form, or import existing Markdown or prose.
3. **Add list items:** use the "Add" buttons for staff, lawsuits, news and so on.
4. **Link the program:** choose the matching program index entry in the program picker.
5. **Generate:** click "Generate Wiki Code" to preview the Markdown.
6. **Copy/Submit:** copy the code for Reddit, or click "Submit to Database" to save it for review.
