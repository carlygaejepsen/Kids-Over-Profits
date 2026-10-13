# Wiki Editor

The **Wiki Editor** (page title "TTI Wiki Entry Generator") helps users create, format and manage Reddit wiki entries for TTI programs and parent organizations. It turns a structured form into standardized Reddit Markdown, can parse existing wiki Markdown back into the form, and saves entries to the `wiki_submissions` table for review. Approved entries are shown publicly on the Wiki Feed page.

## Architecture

- **Page template:** `templates/page-wiki-editor.php` (Template Name "TTI Wiki Entry Generator").
- **Asset loading:** `kop_enqueue_wiki_editor_assets()` in `inc/enqueue.php`, which runs only on that template. It localizes `wikiEditorSettings` (`isAdmin`, `nonce`, `saveApi`, `stubsApi`, `redditDiffApi`, `facilitySearchUrl`, `facilityPickerApi`, `foldersUrl`, `markdownBaseUrl`) and prints `window.KOP_NAME_VARIANTS` before `js/wiki-generation.js` (see "One Person, Many Names"). `kop_enqueue_document_viewer_panel()` in the same file adds the docked FileBird document viewer to this page.
- **Frontend scripts** (plain scripts, loaded in this order):
  - `js/auto-linker.js` - links mentioned program names; reads `js/data/reddit-wiki/` (`autoLinkerSettings.basePath`).
  - `js/wiki-parser.js` - Markdown to form data (import, bulk upload, loading entries).
  - `js/wiki-generation.js` - form data to Reddit Markdown (staff sentences, names line, contact link, spacing; see "Generated Text").
  - `js/wiki-program-picker.js` - program index picker popup; uses the shared FileBird folder browser and `kop/v1/folders`.
  - `js/wiki-editor.js` - form state, index browser, import/export and API calls.
  - `js/tutorial-overlay.js` + `js/wiki-editor-tutorial.js` - guided tour.
- **Styles:** `css/wiki-editor.css`, `css/wiki-program-picker.css`, `css/tutorial-overlay.css`.
- **Backend APIs:**
  - `api/save-wiki-submission.php` - GET lists/searches (`?search=`, `?status=`, `?limit=`, `?offset=`) or fetches one (`?id=`) submission; POST creates or updates one.
  - `api/wiki-stubs.php` - empty wiki slugs still needing an entry (from `markdown_output/empty_files_updated.md` plus `markdown_output/wiki_stub_overrides.json`) and the names/slugs already completed.
  - `api/facility-search.php`, `api/facility-picker.php` - program index lookup for the picker.
  - `api/extract-wiki-from-prose.php` - AI extraction of form fields from free prose (shared provider layer in `api/ai-providers.php`; Groq and Gemini take turns by default).
  - `api/wiki-reddit-diff.php` - which saved entries differ from their live r/troubledteens wiki page, for the index badges (`kop_wiki_reddit_compare_all()` in `api/lib-wiki-contact.php`).
  - `api/lib-wiki-contact.php` - the modmail contact link and the live Reddit comparison; `inc/wiki-contact.php` runs its one-time rewrite of saved rows.
  - `api/lib-wiki-link-label.php` - the name to show for what an entry is linked to.

## Key Features

### 1. Form-Based Entry
- **Entry type:** individual facility/program or parent organization (organization mode shows "Facilities Operated" and hides facility-only fields).
- **Program details:** name, previous & alternate names (comma separated, with the years each was used in brackets when known: "Copper Canyon Academy (1998-2014)"), location, years active, owner, affiliations, additional campuses, ownership changes.
- **Staff:** current and former staff, with previous roles.
- **Structure:** education, therapy/treatment types, targeted clinical diagnoses and behavioral issues.
- **Rules and punishments:** individual items or a full advanced section.
- **Allegations:** checkbox groups (abuse, neglect and deprivation, confinement and restraints, mistreatment and practices) plus custom entries.
- **Lawsuits, news articles, survivor testimonies, related programs, media links.**
- **Linked program index entry:** every entry must be tied to a program index record (stored in `wiki_submissions.facility_unique_name`) through the program picker before it can be submitted. The picker can also set the program's document folder. The column holds a program's `unique_name`, a company's name, or a token for another kind of record (`consultant:12`, `provider:3`, `transporter:7`, `marketer:2`, `tradeorg:1`); the editor's "Linked to" line and the picker always show the record's name (`api/lib-wiki-link-label.php`), never the stored value.

### 2. Generated Text
`js/wiki-generation.js` writes every entry to the same rules as the wiki update drafts (`scripts/wiki-drafts.py`), for programs and parent organizations alike:
- **Names line:** previous and alternate names go on a bold line between the title and the *type* line, `**Previous & alternate names: Copper Canyon Academy (1998-2014), Aspen Ranch**`, followed by a blank line (`alternateNamesLine()`). The parser reads it back into the field, the older bold-italic form included.
- **Staff sentences:** a title one person holds at a time takes "the" ("**Name** is the Executive Director"); any other job takes "a"/"an" ("was a therapist", "an RN"); a department reads "worked in admissions", never "was the Admissions"; "Staff" becomes "a staff member". "Former", "previous" and "current" never stay in the role (the verb says it), and abbreviations are written out (exec, asst, dir, biz dev, mktg, ops, VP, special ed). A year appears only as a span ("from 2008 to 2010", or "from 2014" for "2014-present"); a lone year that only dates a source is dropped.
- **Tense:** a program whose years end in a year ("1994-2010") is closed (`isClosedYears()`), so all its staff are written in the past tense. Otherwise a staff member marked Former, or whose role says former, is written in the past.
- **One paragraph per person:** entries naming the same person (titles such as "Dr." or "Rabbi" and punctuation ignored, plus the known name variants below) are merged, with the other roles after the first ("Roe was also the program director. Roe also worked in admissions.").
- **Contact:** every "please contact ..." line names the subreddit's modmail, `[r/troubledteens modmail](https://www.reddit.com/message/compose?to=/r/troubledteens)` (`CONTACT_LINK`), never one person's account. Old contact handles are rewritten to it (`normalizeContactTag()`); `kop_wiki_contact_normalize()` in `api/lib-wiki-contact.php` is its PHP twin and must match. `inc/wiki-contact.php` rewrote the saved rows once; bump `KOP_WIKI_CONTACT_VERSION` to run it again.
- **Media sections:** "In the Media" lists news articles (the form's news list); "Related Media" lists websites about the program and other resource links (its site, survivor sites, documents, forums). On import, a Related Media line that carries a news source and date is moved to In the Media, and the generator leaves out an article whose link is still in a preserved Related Media section, so nothing is listed twice.
- **Spacing:** `normalizePunctSpacing()` removes the space the Reddit conversion leaves before punctuation after a link and drops an empty date after a source; it must match `punct_spacing()` in `scripts/wiki-drafts.py` and `kop_wiki_drafts_reddit_format()` in `inc/wiki-update-drafts.php`.

### 3. One Person, Many Names
`inc/name-variants.php` builds `window.KOP_NAME_VARIANTS` from the people table's other names and merges (KOP Tools > People and Merge People), the network map's people and their other names, and the names checked by hand in `js/data/people/name-variants-reviewed.json` ("same" groups, "distinct" pairs; Merge People's "Not the same" pairs are distinct too). `js/data/people/nicknames.json` has two lists: "same" first names that can only be one name (Charlie = Charles) are merged, "maybe" ones (Sam = Samuel or Samantha) are only warned about. The editor lists names that may be one person above the staff list, for the staff entries and for names in imported staff text (`renderStaffNameCheck()`).

### 4. Markdown Import/Export
- **Generate Wiki Code:** builds the Reddit Markdown preview; it can be converted to past tense and copied to the clipboard.
- **Markdown Editor mode:** edit the raw Markdown instead of the form.
- **Import from Clipboard:** paste (or load a `.txt`/`.md` file of) existing wiki Markdown or plain text to fill the form.
- **Bulk Upload:** upload several `.md`/`.txt` files at once; each is parsed and saved as a `draft` (bulk uploads carry no program link, so they must be linked before submission).
- **Extract from Prose (AI):** paste prose about a program and fill the fields it can infer.

### 5. Index Browser
- **Locations** and **Organizations** tabs list wiki index pages from `js/data/reddit-wiki/index.json` and `js/data/reddit-wiki/programs-XX.json` / `programs-CORPORATE.json`; picking a program loads its saved submission (matched by name through `save-wiki-submission.php?search=`) or its local index Markdown from `markdown_output/`.
- **Stubs** lists every wiki entry that is still empty (from `api/wiki-stubs.php`, falling back to `js/data/reddit-wiki/empty-slugs.json`, then `markdown_output/empty_files_updated.md`).
- **Reddit badges:** a row whose saved entry does not match the live wiki page is marked "Differs from Reddit" ("Edited here, differs from Reddit" when it was saved in the editor), "Not on the Reddit wiki" or "Saved here since the Reddit check"; the tooltip gives the check date and sample lines. Matching rows and rows the check never reached get no mark. See "Live Reddit Comparison".

### 6. Database and Review
- **Save:** "Submit to Database" posts to `api/save-wiki-submission.php`, which writes to `wiki_submissions`.
- **Permissions:** non-admins may only set `draft` or `submitted` and may only update rows still in one of those statuses. Other statuses (approved, published, rejected) are admin-only; deleting (a soft `status = 'deleted'`) also requires the `kop_wiki_editor` nonce sent as `X-KOP-Nonce`. Submitter contact details are returned to admins only.
- **Program link:** any non-draft save without `facilityUniqueName` is rejected (HTTP 422, `facility_link_required`).
- **Email notification:** when a non-admin's entry reaches `submitted` (saved as submitted, or a draft later submitted), `kop_notify_admins('wiki', ...)` (`inc/submission-notify.php`) mails the admins a link to Submissions Review on the wiki tab. Saving a draft sends nothing. Recipients come from the `kop_submission_notify_emails` option (falling back to the site admin email); admin saves are not mailed.
- **Review:** submissions are approved, rejected or published on the Submissions Review page (`templates/page-admin-submissions.php`, `api/manage-submissions.php`; see [Admin Submissions](../admin-submissions/README.md)). Approving or publishing a wiki submission upserts it into `wiki_master`. Admins can also confirm or repoint the program link through `api/link-wiki-facility.php`.

### 7. Wiki Feed
`templates/page-wiki-feed.php` (Template Name "Wiki Feed") lists `approved` and `published` rows from `wiki_submissions`, 50 per page, sortable by newest or name, filterable with `?search=`, and renders their Markdown with `js/marked.min.js` sanitized by `js/purify.min.js`. Site search and the generated facility pages link to it with `?search=`. Each card notes whether the entry is the same as, differs from, or is not on its r/troubledteens wiki page (or was updated here since the check), linked to that page with the check date; an entry with no comparison result gets no note.

### 8. Live Reddit Comparison
Entries are compared with the live r/troubledteens wiki, never with `markdown_output/`, which is stale.
- `python scripts/reddit-wiki-live.py fetch [--pages index/a active-programs/b] [--refresh]` reads every wiki page in a real Chrome window into `tmp/reddit-wiki-live/` (Reddit refuses scripts; solve its "Prove your humanity" check in the window when it asks). Resumable; pages already read are skipped unless `--refresh`.
- `python scripts/reddit-wiki-live.py compare [--list]` (sync `tmp/prod.sqlite` first) pairs each entry with the page it was imported from (`json_data.sourceSlug` or the bulk-upload note), never by name, compares the words (ignoring the contact line, link addresses and formatting) and writes `js/data/reddit-wiki/live-compare.json`. Commit that file; the site reads it through `kop_wiki_reddit_compare()`.
- States: same, differs, missing (no such page on Reddit), changed (saved here after the check).

### 9. Wiki Updates from KOP
Entries are brought up to date from KOP's records (docs/PLAN.md 3.12), through the Review inbox on the Submissions Review page:
- **Wiki links** (`inc/review-inbox/wiki-links.php`, `inc/wiki-updates.php`): each current entry (the newest row per Reddit page) is tied to the record it is about, saved as `suggested` in `wiki_submissions.facility_unique_name` (the entry's text does not change). Tabs: One clear match (Link, or "Link every clear match"), Pick the record, No record found, Linked here and Set aside, the last two with Undo. The finder picks a program, company, educational consultant, mental health provider, transporter, marketing company or trade organization, and "Create the record and link it" makes a missing one (Undo takes the link off; the record stays).
- **Gaps** (`kop_wiki_upd_gaps()`): what the record's facility page data holds that the entry's text lacks (closure, names, operator, news, lawsuits, deaths, approved serious findings, incidents, staff). An entry about an earlier name gets only that name's years, never today's status; a record that contradicts the entry is a conflict, not an update.
- **Wiki updates** (`inc/review-inbox/wiki-updates.php`, `inc/wiki-update-drafts.php`): drafts written under `tmp/wiki-updates/drafts/<id>/` and assembled by `scripts/wiki-drafts.py` are exported to `js/data/reddit-wiki/update-drafts.json` (commit it). Only the operations travel (add to a section, add a section, set the header years, move news articles from Related Media to In the Media, past tense for a closed program); the site applies them to the entry's text as it is now. A reviewer can edit or empty any added line. Approve writes KOP's copy of the entry (exact Undo); the entry then waits on Ready for Reddit with the whole text, a Copy button and "Edit it on Reddit", until marked as pasted (On Reddit).

## Tests

```bash
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-contact.php [--list]   # rewrite, PHP == JS, migration on a copy, live-result states
php -d extension=pdo_sqlite scripts/test-name-variants.php   # every merge and other name is a group, on tmp/prod.sqlite; writes tmp/name-variants.json (run before the JS test)
node scripts/test-wiki-generation-rules.js       # role sentences, merging, a whole page; spacing JS == Python on every draft line
php -d extension=pdo_sqlite -d extension=mbstring scripts/wiki-gaps.php [--id=471] [--list]   # -> tmp/wiki-updates/{links.json,gaps/<id>.json,report.md}
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-gaps.php                 # helpers, matching, gaps on real entries
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=wiki-links
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=wiki-updates
python scripts/wiki-drafts.py assemble <ids> && python scripts/wiki-drafts.py export <ids>   # -> js/data/reddit-wiki/update-drafts.json
```

## Usage

1. **Navigate** to the Wiki Editor page, or pick an entry from the index browser or Stubs tab.
2. **Input data:** fill in the form, or import existing Markdown or prose. Check the list of names that may be one person above the staff list.
3. **Add list items:** use the "Add" buttons for staff, lawsuits, news and so on.
4. **Link the program:** choose the matching program index entry in the program picker.
5. **Generate:** click "Generate Wiki Code" to preview the Markdown.
6. **Copy/Submit:** copy the code for Reddit, or click "Submit to Database" to save it for review.
