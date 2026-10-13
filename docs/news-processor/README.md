# News Processor

The News Processor is the structured intake workflow for TTI-related news coverage. It helps staff and researchers capture article metadata, trauma-sensitive summaries, tagged entities, and publication details before saving the record into `news_submissions`. The same save endpoint is fed twice a day by automated article discovery, and signed-in reviewers get the same form inside the Send to KOP browser extension.

## Overview

The processor is a WordPress page backed by a large client-side form. It supports both manual entry and AI-assisted extraction from article URLs or pasted article text.

Typical workflow:
1. Open the News Processor page.
2. Optionally pick a Quick Start template (pre-sets article type and content warnings).
3. Admins: turn on the AI Assistant, paste an article URL or source text, and run extraction. Everyone else fills the fields by hand (the AI panel is printed for `manage_options` accounts only).
4. Review and edit the extracted fields.
5. Submit. Duplicates are checked before AI processing and again before saving.
6. An admin reviews the row (status `submitted`) in the Submissions Review screen; the public news feed shows only `approved` and `published` rows.

## Architecture

### Page and Markup
- `templates/page-news-processor.php` ("News Article Processor") is the page template.
- It requires `api/news_processor.php`, which holds the markup, the submission modal, and an inline copy of `KOP_NewsProcessor_Settings`. It also loads `js/autocomplete.js` with `api/get-autocomplete.php` as its endpoint.

### Frontend
- `enqueue_news_processor_scripts()` in `inc/enqueue.php` loads `css/news-processor.css` and `js/news-processor.js` on that template.
- `kop_enqueue_tool_tutorials()` in the same file adds the guided tour (`js/tool-tutorials.js`).
- The frontend receives `KOP_NewsProcessor_Settings`:
  - `apiUrl` for AI extraction (`api/process-news-ai.php`)
  - `submissionUrl` for saving (`api/save-news-submission.php`)
  - `savedValuesUrl` for saved-value lists (`api/saved-values.php`)
  - `duplicateCheckUrl` for duplicate detection (`api/check-duplicate-url.php`)
  - `facilitySearchUrl` for the facility picker (`api/facility-search.php`)
  - `nonce` (`news_processor_nonce`), currently sent only with saved-value requests
- `js/news-archive-links.js` (loaded on every page by `inc/news-archive-links.php`) gives the form `kopNewsIsArchiveUrl()` / `kopNewsUnwrapArchiveUrl()` for the article/archive split (see "The Real Link and the Archived Copy").
- `js/submission-followup.js` draws the "Email me when this has been reviewed" and "Also sign me up for the newsletter" boxes in the submission modal (`kop_followup_fields()`, `inc/submission-followup.php`).
- `js/news-processor.js` manages the UI, templates, dynamic fields, the facility picker, local draft persistence, AI calls, JSON/text export, and submission. A `?prefill_state=<State>` query parameter pre-fills the location field.

### Backend
- `api/process-news-ai.php` fetches the article (falling back to the archive.org Wayback Machine when the page looks paywalled or blocked), refuses a page with under 300 characters of text ("Website Disabled", a 404 notice) and asks for pasted text instead, truncates it to about 20,000 bytes, and asks the AI provider for structured JSON. The prompt, the per-provider calls and the answer parser live in `api/lib-news-ai.php` (`buildPrompt()`, `parseAIResponse()`), shared with the extension's route.
  - It answers only a signed-in admin on this site (`manage_options` plus a same-site Origin/Referer, `kop_request_same_site()`) or the site's own jobs, which send `X-KOP-Internal` with the `news-ai` key from `api/lib-internal-token.php` (an HMAC of wp-config's `AUTH_KEY`/`AUTH_SALT`; discovery reads the salts from wp-config.php as text, `KOP_INTERNAL_AI_TOKEN` in the environment overrides). Anyone else gets a 403.
- `api/save-news-submission.php` inserts or updates `news_submissions`. Only a signed-in admin (same-site) or the site's own jobs (`X-KOP-Internal`, `news-save` key; the hourly enrich) may read rows back with GET, edit a row by id, or set a status; everyone else can only add a new article, always as `submitted`. On every save it:
  - splits the article link from its archived copy (`kop_news_split_urls()`) and writes both `article_url` and `archive_url`
  - normalizes tags (`api/news-tags.php`) and facility mentions (`api/news-mentions.php`)
  - syncs `news_facility_links` for facilities picked from the database
  - assigns a story group (`api/news-story-groups.php`) and a story arc (`api/news-story-arcs.php`)
  - syncs `lawsuit_news_links` (`api/lawsuit-news-links.php`)
  - on insert, notifies admins through `kop_notify_admins()` (`inc/submission-notify.php`) and registers the submitter's "Email me when this has been reviewed" address (`kop_followup_register('news', ...)`, which also queues a ticked newsletter box for MailerLite through `inc/newsletter-signup.php`)
- `api/check-duplicate-url.php` checks for existing entries by URL (both `article_url` and `archive_url`, with Wayback links unwrapped; `api/url-dedupe.php`) and, for news, by title + outlet.
- `api/saved-values.php` stores and returns reusable saved values.
- `api/manage-submissions.php` handles admin review (approve, reject, publish, delete) for the Submissions Review screen (`templates/page-admin-submissions.php`). Pending articles already in the records leave Pending for an "Already on file" tab that names the record holding them (`inc/review-inbox/_on-file.php`): the same address on an approved, published or Industry PR article, or in a facility's resourceLinks ("Materials and links"), or the same headline from the same outlet.
- Hourly enrichment (`inc/record-enrich.php` -> `api/lib-record-enrich.php`, CLI `api/enrich-imported-records.php`) runs incomplete `submitted` rows that skipped the AI step (Drive Docs imports, the browser extension) through `api/process-news-ai.php` and saves them by id: only empty fields are filled, except that the extracted headline replaces the stored title. Test: `php scripts/test-record-enrich.php`.

## AI Providers

The page has no provider selector: `js/news-processor.js` sends `provider: 'auto'`, as do discovery and the record enrichment. The extension's route (`inc/extension-news-processor.php`) calls `kop_ai_generate_alternating()` directly, the same Groq/Gemini alternation every other AI job on the site goes through. `api/process-news-ai.php` accepts:
- `auto` (default): Groq and Gemini take turns (a shared counter in `api/ai-providers.php`, `kop_ai_turn_order()`), and when the one whose turn it is fails, the other reads the same article; the error joins both messages
- `groq`: tries `GROQ_MODEL` if set, then `openai/gpt-oss-120b`, then `openai/gpt-oss-20b`, moving on when a model is retired, gated or rate limited
- `claude`: `ANTHROPIC_MODEL` if set, else `claude-opus-5` at low effort, with server-side refusal fallbacks (`fallbacks: "default"`)
- `gemini`: `GEMINI_MODEL` if set, else `gemini-3.5-flash-lite` (2.0 Flash-Lite was shut down on 2026-06-01)
- `huggingface`: `meta-llama/Llama-3.1-8B-Instruct:fastest`
- `ollama`: `llama3.2` on a local instance at `127.0.0.1:11434`

Only Groq and Gemini are used in practice; the others run only when a request names them. Their model IDs were brought up to date on 2026-09-24 and can be overridden from `.env`. Groq retires model IDs on a schedule, so when extraction starts failing, check the Groq deprecations page and set `GROQ_MODEL`.

## Key Features

### Structured Article Intake
The form captures:
- article title, publication, author, URL, archived copy (optional), and publication date
- location and tags
- facilities and companies mentioned (linked to database facilities when picked from the suggestions, plain text otherwise)
- optional featured company/facility name and logo image URL for stories centered on that organization (HTTPS; a site Media Library image is preferred)
- staff, owners, survivors, and victims mentioned
- trauma-sensitive summary and alternate title when needed
- content warnings and article-type specific details (types: lawsuit, event, expose, arrest, closure, corporate, general)

### AI-Assisted Extraction
- Can process either a URL or pasted article text
- Applies optional custom AI instructions (remembered in `localStorage`)
- The prompt enforces trauma-sensitive rules (for example, never describing a suicide method)
- Returns structured values that fill the form for review; nothing is saved automatically

### Tags
- `api/news-tags.php` is the canonical tag vocabulary. Synonyms collapse to one label on save and on display (for example, "youth detention" becomes "Juvenile Justice"), and generic tags (Abuse, Lawsuit, Youth and similar) are kept in the database but hidden from the feed.
- The AI prompt is given the same vocabulary through `kop_news_tag_prompt_vocabulary()`, and the form also drops generic tags client-side.
- Celebrity and Viral are aside tags (`kop_news_is_aside()`): the prompt tells the AI when to add each, and facility and company pages list those articles after the rest in their own collapsed "articles about celebrities and viral stories" list. Admins add or remove them like any other tag.
- To merge a new variant, add it to `kop_news_tag_synonyms()`; to rewrite stored tags, run `api/normalize-news-tags.php` as an admin. Offline checks: `php scripts/test-news-tags.php`.

### Local Draft Persistence
- `js/news-processor.js` stores working form state and saved values in `localStorage`
- the optional organization name and logo URL are saved with the article's `json_data`, can be edited in the Submissions Review structured editor, and are rendered in the news card when supplied
- the AI toggle and custom instructions are also remembered
- accidental refreshes should not wipe the current draft

### Duplicate Protection
- the processor calls `api/check-duplicate-url.php` before AI processing and again before submission
- matching is by normalized URL (tracking params like `utm_*` stripped) and, at submit time, by identical title on the same outlet, so URL variants of one article are caught too
- `api/save-news-submission.php` enforces the same checks server-side with a 409 on both insert and update, so the pre-check failing open never admits a duplicate

### Story Grouping and Arcs
- articles about the same event from different outlets are not duplicates; they are clustered under a shared `story_group_id` on save (`api/news-story-groups.php`)
- the news feed (`templates/page-news-feed.php`) renders one card per story with an "Also covered by" list of the other outlets
- `api/rebuild-news-story-groups.php` (admin-only, POST) re-clusters the whole archive
- an admin corrects a wrong grouping with the pencil on a card's "Also covered by" list (`storycov:<id>`, `inc/story-coverage.php`; admins see it on cards with no coverage too): untick an article to take it off, paste a link or `#id` to add one, exact Undo. Every row the save touches gets `story_group_manual = 1`, which the automatic grouping (each save, the rebuild) never changes or joins to another story. Test: `php -d extension=pdo_sqlite -d extension=mbstring scripts/test-story-coverage.php`
- story arcs are long-running, admin-curated stories (`news_story_arcs`, managed in `api/manage-story-arcs.php`); an article joins an arc on save when its text matches one of the arc's match terms

### Admin Notifications
- each new row calls `kop_notify_admins()` in `inc/submission-notify.php`
- a person's submission (type `news`) is mailed at once; rows from the discovery run (type `news_auto`, recognized by `auto-discovery` in `submitted_by` or the notes) are queued for a daily digest
- recipients come from the `kop_submission_notify_emails` option, falling back to the site admin email; `KOP_SUBMISSION_DIGEST_TYPES` changes which types are digested

### The Real Link and the Archived Copy
- Every article keeps two links: `article_url`, the article itself, and `archive_url`, an archived copy (Wayback Machine, archive.today, Ghostarchive, perma.cc and the other hosts listed in `kop_news_is_archive_url()`). The form has an optional "Archived copy" box under the URL.
- A Wayback link given as the article's address is split: the address inside it becomes `article_url` and the Wayback link `archive_url`. The form does this as soon as the URL box changes, and the save endpoint does it again on every save. An archive.today short link (`archive.ph/AbCd`) hides the original address, so it stays the only link. An original address pasted into the archive box is moved back to the article link.
- The rules are written three times and must agree: `api/lib-news-archive.php` (server), `js/news-archive-links.js` (lists drawn in the browser) and `browser-extension/send-to-kop/archive.js` (the extension). `inc/news-archive-links.php` adds the `archive_url` column and splits rows whose only link was an archive copy, once.
- Readers print the title linked to the original with a small "archived copy" link beside it (`kop_news_archive_link_html()` / `kopNewsArchiveLinkHtml()`, class `kop-archived-link`); with only one link, the title points at it and nothing is added. The admin editor, duplicate check, facility, operator, state, country, directory and news-feed pages, the mobile API and open data all carry both links.
- Test: `php -d extension=pdo_sqlite scripts/test-news-archive.php [--list]` (rules, PHP against both JS copies, the one-time split on a copy of the mirror).

### Essential Reporting
- The processor does not pick essential reporting; it is chosen afterwards from stored articles (`inc/featured-reporting.php`, table `news_featured` in the records DB): the pencil on any article's headline (`feat:<id>`) or KOP Tools > Essential Reporting, each with an optional note, every change logged with Undo. Picks show on /essential-reporting/, in a strip on /news/ and the home page, with a star beside the headline on news feed cards and the note as a banner over the summary, flagged and listed first on facility and company pages, and as `featured` in the app's news payloads. The byline is the article's `author` field, left out when it is empty or only the outlet's name.
- Nominations wait at Submissions Review > Nominated (`inc/featured-nominations.php`, table `news_featured_nominations`, seeded from `js/data/featured/nominations.json`): Feature picks one with the note as edited, Pass over changes nothing.
- Tests: `php -d extension=pdo_sqlite -d extension=mbstring scripts/test-featured-reporting.php` and `php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=featured-nominations`.

## News Processor in the Send to KOP Extension

Reviewers signed in to the "Send to KOP" browser extension (`browser-extension/send-to-kop/`, an application password on an account with `edit_posts`) get the processor's fields in the popup when the page is an article. The site side is `inc/extension-news-processor.php`:
- `POST /wp-json/kop/v1/extension/process` (`url`, `text`, `instructions`) runs the processor's prompt (`buildPrompt()` from `api/lib-news-ai.php`) through `kop_ai_generate_alternating()` over the text the extension read from the tab, so paywalled and script-drawn articles work. Under 300 characters of tab text, it fetches the address the way the page does; still under 300, it refuses. The answer is mapped to the popup's fields (summary, alternate title, type and its details, location, facilities, staff, survivors, content warnings, tags).
- `GET /extension/archive?url=` returns the newest Wayback copy (and the Wayback save address); the popup also has "Archive now".
- `GET /extension/news-form` returns the article types, content warnings and per-type detail fields (the same lists as `js/news-processor.js`) and whether the account may publish.
- Saving goes through `/extension/submit` with `full=1` (`kop_ext_news_full_fields()` in `inc/source-submissions.php`): "Add to the review queue" files it as `submitted`; "Publish on the site now" (admins, `manage_options`) files it `approved`, listed under Recently done on the review page with Undo.

## Automated Article Discovery

`scripts/discover-articles.php` runs on the NixiHost cPanel cron twice a day (01:30 and 13:30 Central). It pulls candidates from r/troubledteens and Google News RSS (topic queries, then the facility sweep, with facilities read live from `/wp-json/kop/v1/facilities`), scores them, and sends survivors through `api/process-news-ai.php` and `api/save-news-submission.php` with `submittedBy: auto-discovery` and the score details in the submission notes.

The sweep (2026-10) queries every record and every name it has had (current, past and other names; closed programs too). Distinctive names (three words or more) go several to one Google News request, OR'd, up to 16 words of names a request (Google reads about 32 words of a query and drops the rest, so the `when:` window goes first and the batch keyword list is short); one- and two-word names go one to a request with the state name, as before. The sorted request list is walked by a cursor in the state file, half per run (`SWEEP_RUNS_PER_DAY`), so a run that dies resumes where it stopped. At the October 2026 size (5,300 names, 2,865 requests, 2.5 s each) a run's 45-minute Google budget covers about 1,000 requests, so a full pass takes about three runs, a day and a half; a third daily cron line would make it daily. Each run's log ends with `cursor X -> Y of T`. A batched hit whose headline names none of its programs is kept with its attribution pending; the AI step, which reads the article, confirms which program it is about (at most `AI_CONFIRM_PER_RUN` = 30 a run, the rest carried over) and an unconfirmed one is logged as `batch-unconfirmed` for the Scraper finds screen. Stock-market coverage of the parent companies is blocked by `financeDomains` in the blacklist.

- Search terms: `scripts/discovery-queries.json` (`facilityBatchKeywords`, `facilityBatchRecency` = `when:3d` for the batched sweep; `facilityKeywords`, `facilityRecency` for single names); blocked sources: `scripts/discovery-blacklist.json`
- Runtime state (gitignored): `scripts/.discovery-state.json` (version 3: seen URLs, carried-over `pending` candidates, `sweepCursor`, `sweptAt` per name) and `scripts/.discovery-rejected.json`
- Flags: `--dry-run`, `--limit N`, `--max-facilities N` (caps the sweep's requests), `--no-topics`, `--no-facilities`, `--sweep-share N` (1 = the whole list in one run), `--recency 30d` (the batched window for this run; the first run after a deploy, to pick up the backlog)
- Offline test: `php scripts/test-discovery-sweep.php`
- The AI step sends `X-KOP-Internal` (see Backend). Pages the server cannot read (403 to the host's IP, no archive.org copy, a form instead of an article) are counted as "pages unreadable" and retried next run; they do not make the run exit 1, which is kept for provider and submit errors.
- The crontab must call `/opt/cpanel/ea-php82/root/usr/bin/php`; bare `php` under cron is php-cgi and the script exits with "CLI only."
- `scripts/discover-articles.js` is the Node original, kept for the manual-only `.github/workflows/discover-articles.yml`

## Usage Notes

- AI output is a draft, not a final record. Review all extracted people, facilities, and allegations before saving.
- The processor is designed for trauma-sensitive editorial handling, so summaries should remain factual and concise.

## Configuration

Environment keys (loaded by `api/config.php` from `.env`, with `api/config.local.php` as the local override):
- `GROQ_API_KEY` or `GROK_API_KEY`, and optionally `GROQ_MODEL`
- optionally `ANTHROPIC_MODEL` and `GEMINI_MODEL`, to replace a retired model without a code change
- `GEMINI_API_KEY` (the other half of `auto`; without it every call goes to Groq)
- `HUGGINGFACE_API_KEY`, `ANTHROPIC_API_KEY` (only for non-default providers)
- no key for the site's own jobs: `X-KOP-Internal` is derived from wp-config's `AUTH_KEY` and `AUTH_SALT` (`api/lib-internal-token.php`), so changing those salts changes the key

Discovery reads its own settings from the cron environment: `NEWS_API_BASE`, `AI_PROVIDER` (default `auto`), `SWEEP_RUNS_PER_DAY`, `AI_CONFIRM_PER_RUN`, `KOP_INTERNAL_AI_TOKEN` (only to override the key read from wp-config.php), request delays and time budgets, and optional `REDDIT_CLIENT_ID` / `REDDIT_CLIENT_SECRET`. See the header of `scripts/discover-articles.php`.
