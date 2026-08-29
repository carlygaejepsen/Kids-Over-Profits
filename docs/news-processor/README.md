# News Processor

The News Processor is the structured intake workflow for TTI-related news coverage. It helps staff and researchers capture article metadata, trauma-sensitive summaries, tagged entities, and publication details before saving the record into `news_submissions`.

## Overview

The processor is a WordPress page backed by a large client-side form. It supports both manual entry and AI-assisted extraction from article URLs or pasted article text.

Typical workflow:
1. Open the News Processor page.
2. Paste an article URL or source text.
3. Optionally run AI extraction.
4. Review and edit the extracted fields.
5. Check duplicates.
6. Save the structured submission for review or publication workflows.

## Architecture

### Page and Markup
- `page-news-processor.php` is the WordPress page template.
- That template loads `api/news_processor.php`, which contains the markup for the processor UI.

### Frontend
- `inc/enqueue.php::enqueue_news_processor_scripts()` loads `css/news-processor.css` and `js/news-processor.js`.
- The frontend receives `KOP_NewsProcessor_Settings`, which includes:
  - `apiUrl` for AI extraction
  - `submissionUrl` for saving submissions
  - `savedValuesUrl` for saved-value lists
  - `duplicateCheckUrl` for duplicate detection
  - `nonce` for request validation
- `js/news-processor.js` manages the UI, dynamic fields, local draft persistence, AI interactions, and submission flow.

### Backend
- `api/process-news-ai.php` handles AI extraction requests.
- `api/save-news-submission.php` persists processed news submissions.
- `api/check-duplicate-url.php` checks for existing entries by URL (and, for news, by title + outlet) before AI processing or submission.
- `api/news-story-groups.php` provides the title/outlet duplicate check and clusters articles about the same story from different outlets (`story_group_id`).
- `api/saved-values.php` stores and returns reusable saved values.

## AI Providers

### Current UI
The current page UI exposes:
- Groq
- Hugging Face

### Backend Support
`api/process-news-ai.php` also contains provider support for:
- Ollama
- Claude
- Gemini
- Groq
- Hugging Face

That means the backend can support more providers than the current visible selector. If the UI is expanded later, update this doc to match the actual page controls.

## Key Features

### Structured Article Intake
The form captures:
- article title, publication, author, URL, and publication date
- location and tags
- facilities and companies mentioned
- staff, owners, survivors, and victims mentioned
- trauma-sensitive summary and alternate title when needed
- content warnings and article-type specific details

### AI-Assisted Extraction
- Can process either a URL or pasted article text
- Applies optional custom AI instructions
- Returns structured values for review instead of publishing automatically

### Local Draft Persistence
- `js/news-processor.js` stores working form state in `localStorage`
- saved values and AI preferences are also persisted locally
- accidental refreshes should not wipe the current draft

### Duplicate Protection
- the processor calls `api/check-duplicate-url.php` before AI processing and again before submission
- matching is by normalized URL (tracking params like `utm_*` stripped) and, at submit time, by identical title on the same outlet — so URL variants of one article are caught too
- `api/save-news-submission.php` enforces the same checks server-side with a 409 on both insert and update, so the pre-check failing open never admits a duplicate

### Story Grouping
- articles about the same story from different outlets are not duplicates; they are clustered under a shared `story_group_id` (assigned automatically on save by `api/news-story-groups.php`)
- the news feed renders one card per story with an "Also covered by" list of the other outlets
- `api/rebuild-news-story-groups.php` (admin-only, POST) re-clusters the whole archive — run it once after the schema migration

## Usage Notes

- AI output is a draft, not a final record. Review all extracted people, facilities, and allegations before saving.
- The processor is designed for trauma-sensitive editorial handling, so summaries should remain factual and concise.
- If the page UI and the backend provider list drift apart, trust the visible UI first and document any manual-only providers separately.

## Configuration

Common environment keys used by the AI backend include:
- `GROQ_API_KEY` or `GROK_API_KEY`
- `HUGGINGFACE_API_KEY`
- `ANTHROPIC_API_KEY`
- `GEMINI_API_KEY`

Ollama support is local and does not require a cloud API key, but it does require a reachable Ollama instance when used.
