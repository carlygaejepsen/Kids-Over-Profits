# News Processor

The News Processor is the structured intake workflow for TTI-related news coverage. It helps staff and researchers capture article metadata, trauma-sensitive summaries, tagged entities, and publication details before saving the record into `news_submissions`. The same save endpoint is fed every night by automated article discovery.

## Overview

The processor is a WordPress page backed by a large client-side form. It supports both manual entry and AI-assisted extraction from article URLs or pasted article text.

Typical workflow:
1. Open the News Processor page.
2. Optionally pick a Quick Start template (pre-sets article type and content warnings).
3. Turn on the AI Assistant, paste an article URL or source text, and run extraction.
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
- `js/news-processor.js` manages the UI, templates, dynamic fields, the facility picker, local draft persistence, AI calls, JSON/text export, and submission. A `?prefill_state=<State>` query parameter pre-fills the location field.

### Backend
- `api/process-news-ai.php` fetches the article (falling back to the archive.org Wayback Machine when the page looks paywalled or blocked), truncates it to about 20,000 bytes, and asks the AI provider for structured JSON.
- `api/save-news-submission.php` inserts or updates `news_submissions` (GET also lists and reads rows). On every save it:
  - normalizes tags (`api/news-tags.php`) and facility mentions (`api/news-mentions.php`)
  - syncs `news_facility_links` for facilities picked from the database
  - assigns a story group (`api/news-story-groups.php`) and a story arc (`api/news-story-arcs.php`)
  - syncs `lawsuit_news_links` (`api/lawsuit-news-links.php`)
  - on insert, notifies admins through `kop_notify_admins()` (`inc/submission-notify.php`)
- `api/check-duplicate-url.php` checks for existing entries by URL and, for news, by title + outlet.
- `api/saved-values.php` stores and returns reusable saved values.
- `api/manage-submissions.php` handles admin review (approve, reject, publish, delete) for the Submissions Review screen (`templates/page-admin-submissions.php`).

## AI Providers

The page has no provider selector: `js/news-processor.js` always sends `provider: 'groq'`. `api/process-news-ai.php` still accepts other providers from API callers:
- `groq` (default): tries `GROQ_MODEL` if set, then `openai/gpt-oss-120b`, then `openai/gpt-oss-20b`, moving on when a model is retired, gated or rate limited
- `claude`: `ANTHROPIC_MODEL` if set, else `claude-opus-5` at low effort, with server-side refusal fallbacks (`fallbacks: "default"`)
- `gemini`: `GEMINI_MODEL` if set, else `gemini-3.5-flash-lite` (2.0 Flash-Lite was shut down on 2026-06-01)
- `huggingface`: `meta-llama/Llama-3.1-8B-Instruct:fastest`
- `ollama`: `llama3.2` on a local instance at `127.0.0.1:11434`

Only Groq is used in practice: the page always sends `provider: 'groq'`, so the others run only when a request names them. Their model IDs were brought up to date on 2026-09-24 and can be overridden from `.env`. Groq retires model IDs on a schedule, so when extraction starts failing, check the Groq deprecations page and set `GROQ_MODEL`.

## Key Features

### Structured Article Intake
The form captures:
- article title, publication, author, URL, and publication date
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
- story arcs are long-running, admin-curated stories (`news_story_arcs`, managed in `api/manage-story-arcs.php`); an article joins an arc on save when its text matches one of the arc's match terms

### Admin Notifications
- each new row calls `kop_notify_admins()` in `inc/submission-notify.php`
- a person's submission (type `news`) is mailed at once; rows from the discovery run (type `news_auto`, recognized by `auto-discovery` in `submitted_by` or the notes) are queued for a daily digest
- recipients come from the `kop_submission_notify_emails` option, falling back to the site admin email; `KOP_SUBMISSION_DIGEST_TYPES` changes which types are digested

## Automated Article Discovery

`scripts/discover-articles.php` runs on the NixiHost cPanel cron (nightly at 01:30 Central, plus a midday `--no-facilities` run). It pulls candidates from r/troubledteens and Google News RSS (topic queries, then one query per facility in the day's shard, with facilities read live from `/wp-json/kop/v1/facilities`), scores them, and sends survivors through `api/process-news-ai.php` and `api/save-news-submission.php` with `submittedBy: auto-discovery` and the score details in the submission notes.

- Search terms: `scripts/discovery-queries.json`; blocked sources: `scripts/discovery-blacklist.json`
- Runtime state (gitignored): `scripts/.discovery-state.json` (seen URLs, carried-over `pending` candidates) and `scripts/.discovery-rejected.json`
- Flags: `--dry-run`, `--limit N`, `--max-facilities N`, `--no-topics`, `--no-facilities`
- The crontab must call `/opt/cpanel/ea-php82/root/usr/bin/php`; bare `php` under cron is php-cgi and the script exits with "CLI only."
- `scripts/discover-articles.js` is the Node original, kept for the manual-only `.github/workflows/discover-articles.yml`

## Usage Notes

- AI output is a draft, not a final record. Review all extracted people, facilities, and allegations before saving.
- The processor is designed for trauma-sensitive editorial handling, so summaries should remain factual and concise.

## Configuration

Environment keys (loaded by `api/config.php` from `.env`, with `api/config.local.php` as the local override):
- `GROQ_API_KEY` or `GROK_API_KEY`, and optionally `GROQ_MODEL`
- optionally `ANTHROPIC_MODEL` and `GEMINI_MODEL`, to replace a retired model without a code change
- `HUGGINGFACE_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` (only for non-default providers)

Discovery reads its own settings from the cron environment: `NEWS_API_BASE`, `AI_PROVIDER` (default `groq`), `SHARD_COUNT`, request delays and time budgets, and optional `REDDIT_CLIENT_ID` / `REDDIT_CLIENT_SECRET`. See the header of `scripts/discover-articles.php`.
