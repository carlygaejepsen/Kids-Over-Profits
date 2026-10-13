# Admin Submissions and the Review Inbox

The **Submissions Review** page is the admin queue for everything that waits on a person. It draws the five submission types the public sends in (news, data form suggestions, wiki entries, lawsuits, legislation) itself, and since October 2026 it is also the **Review Inbox**: every other approval queue on the site (closure reports, facilities from the news, Woodbury, Fornits, Drive Docs, merges, map years and renames, serious violations, conflicts...) is a tab on the same page with the same card controls. Approving here is what moves an item into the live data.

## Where it lives

- **Page:** `templates/page-admin-submissions.php` (Template Name "Admin - Submissions Review"). Auto-created by `inc/admin.php` (`kop_tool_page_specs()`) as a private page if no page uses the template yet; every link to it resolves through `kop_find_template_page_url('page-admin-submissions.php')`, so the page's slug does not matter. The template calls `kop_require_page_capability('manage_options')`, so logged-out visitors are sent to the login screen.
- **Menu:** listed as **Review Inbox** under **KOP Tools** in wp-admin and the admin bar, with the number of items waiting across every queue (`inc/review-inbox-menu.php`; the KOP Tools plugin, `wp-plugins/kop-tools/kop-tools.php`, owns the menu and the All Tools dashboard). Without the plugin, `inc/admin.php` registers a thinner **KOP Data Tools** menu that lists it as "Submissions Review".
- **Old screens:** the wp-admin screens of the queues the inbox covers are hidden from the sidebar with CSS (never `remove_submenu_page()`, which makes a screen answer 403) and are listed on the KOP Tools dashboard under "Old review screens". They still work, and every inbox tab links "Open the full ... screen" for what the inbox does not do. Program Homes and Essential Reporting stay on the sidebar (`kop_rinbox_menu_keep_listed()`): they hold tools the inbox does not (Companies that are one program; finding and picking any article, notes, Recent changes).
- **Assets:** `js/admin-submissions.js` + `css/admin-submissions.css` (the five types) and `js/review-inbox.js` + `css/review-inbox.css` (every other queue, and the shared card row on the five types), enqueued by `kop_enqueue_admin_submissions()` in `inc/enqueue.php`. `adminSubmissionsConfig` carries `apiBase`, `manageApi`, `scanApi` and `reviewer` (the logged-in user's display name or email, used as `reviewed_by`); `kopReviewInbox` carries the REST root and nonce.
- **Backend:** `api/manage-submissions.php` for the five types (admin only: `manage_options`, 403 otherwise); `inc/review-inbox.php` (REST `kop/v1/review-inbox/{sources, items, act, save, tags, ai}`) and `inc/review-inbox-log.php` (`{log, undo, hold, held, preview}`) for the rest. Each queue is an adapter in `inc/review-inbox/<name>.php` registered with `kop_rinbox_register()`; it calls the queue's own apply and undo functions, so approving here does exactly what the queue's old screen did.

## The five submission types

| Type | Table | Status column | Unreviewed value |
|---|---|---|---|
| `wiki` | `wiki_submissions` | `status` | `submitted` |
| `news` | `news_submissions` | `status` | `submitted` |
| `data` | `suggested_edits` | `status` | `pending` |
| `legislation` | `legislation` | `publication_status` | `pending` |
| `lawsuit` | `lawsuits` | `publication_status` | `pending` |

The UI's "Pending Review" filter sends `submitted`; the API maps it to each table's own unreviewed value (`kop_submission_schema()`).

Public insert paths: `api/save-wiki-submission.php`, `api/save-news-submission.php` (also used by nightly article discovery), `api/save-suggestion.php`, `api/save-legislation-suggestion.php`, `api/save-lawsuit-suggestion.php`; the Send to KOP browser extension, the /send/ page and the mobile app's Send tab (`inc/source-submissions.php`, `inc/mobile-submit.php`) insert news, lawsuits and bills through the same duplicate checks.

### Page features (five types)

- **Stats:** Pending / Approved / Published / Rejected counts for the selected type.
- **Filters:** type, status, and keyword search (per-type columns: program name/location for wiki, title/publication/author for news, `master_id` for data, title/number/jurisdiction for legislation and lawsuits).
- **Already on file** (news, lawsuits, legislation): pending rows already in our records (the same link on a kept record or in a facility's "Materials and links", the same headline from the same outlet, the same case number, the same bill) leave Pending and are listed under their own tab, each naming the record that holds it, to reject in a batch (`inc/review-inbox/_on-file.php`).
- **Reject All Pending:** rejects every pending submission currently listed.
- **Selection and bulk actions:** pending and rejected cards can be ticked, so rejected news can be refiled as Industry PR (or approved) in bulk. The list pages through the API 200 at a time until the whole filtered set is loaded. An action sent without a reviewer note keeps the notes already on the row.
- **Card row from the inbox:** rename, category, tags, "Move to" another queue (a news link that is really a lawsuit or a bill, or the other way, becomes a pending row there and the original is filed as rejected, with Undo) and "Fill empty fields with AI" (news through the News Processor's reader, lawsuits through the complaint extractor; only empty fields are filled).
- **Website / Resource on the card (news):** puts the link on a facility record as the program's website or a resource without opening the details: a small panel with the facility finder (the first facility the article mentions is searched at once) and, for a resource, its kind. The card then says where it went, with Undo. Same `refile` / `unrefile` action as the detail panel.
- **Industry PR index** (news only): the **Industry PR** status tab lists articles filed with status `promotional`, the internal index of news the facilities or their supporters put out (fundraisers, anniversaries, marketing, expansions, awards, hiring). File one with the **PR** button on a card, **File as Industry PR** in the detail panel, or **File selected as PR** in bulk; tag its kind with the "Industry PR kind" field in the structured editor (`json_data.promoKind`). Every public news reader lists only `approved`/`published`, so these never appear on the site, and the URL still blocks rediscovery by nightly discovery. **Approve** moves one back into the public news. The enum value is added on first use (`kop_news_status_enum_ensure()`).
- **Duplicate URL warning:** flags news and wiki submissions whose URL appears in more than one listed submission.
- **URL safety check:** scans a submission's URLs through Cloudmersive via `api/scan-submission-urls.php`.
- **Wiki markdown editor:** side-by-side "Original / Uploaded" vs. "Generated / Editable" markdown with a diff view; Save Edits writes `generated_markdown`.
- **Structured field editor** (legislation, lawsuit, news, data): edits a whitelist of fields (`kop_editable_fields()`); saving never changes status. For data submissions the editable surface is the raw `edited_json_data` plus `reason`.
- **Facility link** (wiki only): suggest, confirm, set or remove the wiki entry's link to a facility via `api/link-wiki-facility.php`.
- **Full form data:** collapsible JSON view of the stored record.
- **Reviewer notes** and the previous review (by/at) where the table has those columns (wiki, news).

### What each action does (five types)

- **Approve**
  - `wiki`: status `approved`, then upserts `wiki_master` by slug and fills blank fields on the linked facility (`kop_merge_wiki_into_facility()` in `api/sync-wiki-facilities.php`, no overwrites).
  - `news`: status `approved`, then re-syncs `lawsuit_news_links`.
  - `data`: not a status flip. `kop_apply_suggested_edit()` in `api/lib-suggested-edits.php` applies the edit (see Facility data below) and marks the row `approved`. Only rows still `pending` can be applied. `publish` is treated the same as approve.
  - `legislation` / `lawsuit`: go straight to `published` and stamp `published_at` (no separate publish step). A published lawsuit also syncs `lawsuit_facility_links` and `lawsuit_news_links`, resolves a FileBird folder from its first linked facility if none is set, and files its case documents into that folder.
- **File as Industry PR** (news only, action `promo`): sets `promotional` and re-syncs lawsuit links so the article drops off case cards.
- **Reject:** sets the rejected status; for news it also re-syncs lawsuit links so the article drops off case cards.
- **Mark as Published:** sets `published` (wiki also updates `wiki_master` as on approve).
- **Delete:** removes the rows and their `submission_attachments` rows.

Approve, reject, publish and PR on these five types are logged to Recently done (below) with a `restore` Undo that puts back the status the row had.

### Facility data (v2 model)

Since 2026-09-18 facility writes go to the v2 tables (`facilities_v2` and related; `inc/facility-v2-writer.php`, `inc/facility-store.php`) and the legacy `facilities_master` / `locations_master` tables are frozen. When v2 writes are active (`kop_v2_writes_active()`), an approved operator or location suggestion is saved into v2 as a partial update: only the fields the suggestion lists change, nothing is removed. Referrer and transporter suggestions still write `referrers_master` / `transporters_master`; provider, marketing company and trade organization suggestions merge into `providers_master`, `marketers_master` and `tradeorgs_master`. The wiki facility merge also reads and writes v2 when active. See `docs/DATA-MODEL-MIGRATION.md`.

## The review inbox queues

Tabs are grouped under headings (the source's `'group'`). Each tab's own help line (the source's `'help'`) says what its buttons do; the queue's views (Waiting, Added, Merged, Rejected...) sit above the cards, and most queues have an Undo on their done views.

**Your inbox** (across every queue)
- **Assigned to you:** items another admin handed to you with Later.
- **Snoozed:** items set aside until a date.
- **Volunteers recommend:** items a volunteer reviewer has recommended approving or rejecting, with their recommendations.
- **Recently done:** everything anyone did in the inbox and on the five types, newest first, each with its Undo.

**Conflicts to settle**
- **Conflicts:** waiting Woodbury Facts and Fornits items that disagree with what the record already says (another year, size or type, another role for the same person, a closure on a record marked open). Keep the record, Use this value (written over the record, citing its source) or Edit; items about the same program and the same kind of value roll up into one card. Undo is the queue's own.

**Sent in by readers**
- **Anonymous documents:** documents from the anonymous upload page, sealed with the portal's public key: download and open one with `scripts/anon-portal-decrypt.php` and the private key, then mark it handled.
- **Bug reports:** reports from the on-site bug widget; changing the status emails the reader when they asked for updates.
- **Glossary feedback:** reader notes from the glossary's "My facility used this too" and "Suggest a correction" buttons; make the change in the Glossary Editor, then mark the note added.
- **Websites sent in:** pages sent with the Send to KOP button that are not news, lawsuits or bills; keep, trash or restore.

**Found by the news scans**
- **Closure reports:** articles that say a facility closed (hourly scan). Confirming sets the facility's status and end year, cites the article and links it; the facility page and the network map follow at once.
- **Facilities from news:** names the hourly news scan found that match no record exactly: "Same program" lists the article on the record shown, "A different record" searches for the right one, "New program" makes a record, "Skip" when it is not a program we track; the scan's own new records are on their own tab.
- **Indigenous schools:** Indian boarding and residential schools the scan found; Approve lists one on the Indian Boarding Schools page.
- **Scraper finds:** articles the nightly news scraper turned away; send a good one to the news queue (or elsewhere with Move to), or dismiss it.
- **Bill and lawsuit updates:** status changes the nightly check of every unfinished published bill and lawsuit found (govinfo for federal bills, the state's page, CourtListener dockets); Apply, with exact Undo; a dismissed change never comes back.

**Imports to review**
- **Drive Docs:** links from the owner's Google Docs, HEAL's old site, the r/troubledteens wiki, SCIAD NET and the data audit that the site does not have yet: news, court records and bills go to their queues, program sites and other links onto the facility record.
- **Fornits:** discussion links, staff, incidents, survivor accounts (unpublished) and leads the AI read from the Fornits survivor forum.
- **Woodbury Facts:** staff, incidents, openings, closings, names, owners, size and ages from the Woodbury Reports newsletter (HEAL and wiki facts too) that the record lacks; has a tab for young adult programs.
- **Woodbury Reports:** newsletter pages about a program, filed into its "Woodbury Reports Mentions" folder.
- **Young adult programs:** programs for people 18 and older, kept apart from the facility records; Show lists one on the Young Adult Programs page.

**Suggestions to check**
- **Data audit:** facility data that news, court and SEC filings and licensing records show is wrong; tick the steps to make, each checked against what KOP says now; a rename that explains disagreeing years is shown with "Merge as a rename".
- **Duplicate facilities:** two records that look like one place; merge keeps one, moves everything onto it and forwards the other's page. Renamed programs merge only as a rename.
- **Duplicate people:** one person under two names or spellings; Merge makes them one person everywhere.
- **Federal funding:** Medicaid, foster care funds, ORR, TRICARE, PPP and Education money found for programs; Add puts it on the record and its facility page.
- **Folder links:** two document folders that are names of one facility; Same facility joins their document feeds, Alternate name keeps them apart.
- **Inspection links:** a facility record and a state licensing entry whose names differ; Same facility puts that entry's reports on the facility page.
- **Map renames:** programs that changed their name; give the rename year (or leave it blank), Swap order if the arrow points the wrong way.
- **Map years:** opening and closing years researched for map places and companies, with the sentence and link each came from.
- **Program homes:** records that may be the separate licensed homes of one program; Group ties them to one program record.
- **Wiki links:** each r/troubledteens wiki entry tied to the KOP record it describes (or a new record made for it), so it can be brought up to date.
- **Wiki updates:** wiki entries with what KOP has learned since they were written; Approve writes KOP's copy, then Ready for Reddit has the text to paste with Copy and the Reddit edit link.

**Found in state inspection reports**
- **Serious violations:** findings read out of state inspection reports; facilities of the companies watched closely come first. Approved ones above the severity score show on the home page and the inspection reports hub.
- **Plain summaries:** AI drafts of plain-language summaries for hard-to-read approved findings; approve only if every claim is in the state's text. Approved ones show above the state's wording.

**Documents**
- **Document types:** the AI's guess of what kind each document is (handbook, inspection report, news clipping, legal filing...) where the folder and title rules could not tell.

**Essential reporting**
- **Nominated:** stored articles put forward as especially good journalism, each with a draft note; Feature puts it on the Essential reporting page with the note as edited, Pass over leaves it off.

**Submissions**
- **Approved, not published:** lawsuits and bills approved before approving also published them; Publish puts one on the site.

## Controls on every card

- **Approve / Reject first, with help:** every action carries one sentence on what clicking it does, shown on the card.
- **Rename** beside the title, **category**, **tags** (the shared `{prefix}kop_review_tags` unless the queue keeps its own) and **Edit details** for the rest of the fields, with **Fill empty fields with AI** (news and lawsuits through their enrichers, the rest through a generic filler that reads the item's link, or text pasted in "Paste text for the AI").
- **Move to** another queue where the source allows it (news, lawsuits, legislation, Industry PR, a facility's website or resources, Indian boarding schools, young adult programs).
- **Home of a program** on every card about one facility, and **File under a young adult program** on every waiting card (except queues about something else, and Woodbury Facts and Fornits, which file young adult items their own way); both have Undo in Recently done.
- **Preview** beside each link: the page itself in a frame where the site allows it, else a reading copy (title, site, picture, text; PDFs read, Wayback copy as a fallback), cached 12 hours.
- **Later:** snooze an item until a date and/or hand it to another admin (who gets an email). It leaves everyone else's waiting list until then (`{prefix}kop_review_holds`); approving or rejecting it clears the hold.
- **Conflict** banner: an item that disagrees with its record is marked and never starts ticked.
- **Already on file:** queues that know what our records already hold (news, lawsuits, bills, Facilities from News, Websites sent in, Woodbury Facts, Fornits, Nominated, and cards filed under a young adult program) move those items out of the waiting view and its count into an "Already on file" view, each naming the record that holds it.
- **Queue tools and bulk actions:** buttons over a queue that are not about one item (scan now, add every sure match), ticked-card actions, and a "Came from" filter where the queue has origins (Drive Docs, the five types, Scraper finds).
- **Auto-connected imports:** a name that is exactly one record's is connected hourly with no click (`inc/auto-connect.php`); it shows in its queue's done view as auto and in Recently done with Undo, and an undone one is never redone.

## Recently done and Undo

Every action taken in the inbox, and approve / reject / publish / PR on the five types, is a row in `{prefix}kop_review_log` with the item's title, who did it and the action that takes it back: for a queue item, the undo action the item offers right after; for the five types, `restore` to the status it had. Undo from Recently done runs that action through the queue's own code, once.

## Volunteer reviewers

KOP Tools > Volunteer Reviewers (`inc/review-volunteers.php`) makes a personal link per volunteer (no account; only the token's hash is stored), turns one off, and picks the queues each one sees. Anonymous documents, bug reports, glossary feedback, Fornits, data suggestions and Approved, not published can never be opened by a volunteer (`kop_vol_never_sources()`). Volunteers work at /volunteer-review/ on copies of the items (no edit fields, actions or tags; emails and phone numbers blanked; held items left out) and only recommend approve, reject or not sure (`{prefix}kop_volunteer_recs`). Cards show the recommendations, "Volunteers recommend" lists them, and an admin's approve or reject closes them (the agreement rate per volunteer is kept); Undo reopens them.

## Notifications

Each public insert path calls `kop_notify_admins()` (`inc/submission-notify.php`), which emails the addresses in the `kop_submission_notify_emails` option (comma or newline separated; defaults to the site admin email) with a link that opens this page on the matching tab (`?type=`, read by the page's scripts):

- `suggested_edit` -> Data, `wiki` -> Wiki, `news` and `news_auto` -> News, `lawsuit` -> Lawsuits, `legislation` -> Legislation
- `document` -> Anonymous documents, `status_update` -> Bill and lawsuit updates, `closure_report` -> Closure reports, `new_facility` -> Facilities from news, `website` -> Websites sent in
- `news_auto` (articles posted by nightly discovery) is queued and sent as a daily digest; change the digested types with the `KOP_SUBMISSION_DIGEST_TYPES` constant.

Bug reports also have their own mailer to the reporter (`inc/bug-report-notify.php`). Readers who ticked "Email me when this has been reviewed" on a public form are mailed once the decision has stood ten minutes (`inc/submission-followup.php`).

## Other admin screens

The KOP Tools dashboard (All Tools) lists every admin screen with a one-line description, from the registry in `wp-plugins/kop-tools/kop-tools.php` (`kop_tools_registry()`). Related editors outside this page: `templates/page-wiki-editor.php` and `templates/page-news-processor.php` (wiki and news), `templates/page-admin-lawsuits.php`, `templates/page-admin-legislation.php`, `templates/page-admin-volunteers.php` and `templates/page-admin-data-manager.php`. The old "Approve Edits" screen is retired: `admin.php?page=approve-facility-edits` forwards to the Data tab here.

## API reference

`api/manage-submissions.php`. Every call needs `type` = `wiki` | `news` | `data` | `legislation` | `lawsuit`.

GET:
- `?action=list&type=news&status=submitted&search=...&limit=100&offset=0`: paginated list (limit capped at 200). Returns `data`, `total`, `limit`, `offset`.
- `?action=get&type=news&id=123`: one submission.

POST (JSON body):
- `{ "action": "approve" | "reject" | "publish" | "promo", "type": "news", "ids": [123], "reviewerNotes": "...", "reviewedBy": "..." }`
- `{ "action": "delete", "type": "news", "ids": [123] }`
- `{ "action": "update_status", "type": "news", "ids": [123], "status": "approved" }`: plain status change, no side effects.
- `{ "action": "update_fields", "type": "lawsuit", "id": 123, "fields": { ... } }`: structured editor save (not wiki). Editing news or lawsuit facility mentions re-syncs `news_facility_links` / `lawsuit_facility_links`.
- `{ "action": "update_markdown", "type": "wiki", "id": 123, "generated_markdown": "..." }`
- `{ "action": "stats", "type": "news" }`: counts by status (wiki and news also return each other's counts and news counts by `article_type`).

`id` may be passed in place of `ids` for single-row actions.

The other queues go through `kop/v1/review-inbox/*` (`manage_options`, `wp_rest` nonce); see the comment at the top of `inc/review-inbox.php` for a source's shape. Tests: `scripts/test-review-inbox.php [--source=<name>]` (every source on a scratch copy), `scripts/test-review-on-file.php`, `scripts/test-review-volunteers.php`, `python scripts/test-review-inbox-ui.py`.
