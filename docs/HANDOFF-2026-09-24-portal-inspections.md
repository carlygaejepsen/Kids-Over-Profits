# Handoff 2026-09-24: docs, the anonymous portal, inspections, AI providers

This covers one working session on 2026-09-24 (roughly 02:00 to 04:30
server time). Everything listed as shipped is on `main` and was
deployed and checked on https://kidsoverprofits.org. A separate
handoff for the network map, written the same day, is
`docs/HANDOFF-2026-09-24-network-map.md`. This file does not cover
the map.

Read sections 0 and 1, then the section for whatever you are picking
up. Section 8 is the open work, in the order I would do it.

---

## 0. Before you touch anything

### Working rules the owner has set

- **Work on `main`.** Commit and push to `main`. Do not create
  branches or open PRs. A push to `main` deploys through
  `.github/workflows/deploy.yml` and `.cpanel.yml`.
- **Other sessions share this checkout.** Stage files by path, never
  `git add -A` or `git add .`. Before committing, run
  `git status --short` and leave other people's modified files alone.
  At the time of writing, another session has uncommitted work across
  about 25 files: `CLAUDE.md`, `README.md`, and the data forms
  (`api/save-master.php`, `api/save-suggestion.php`,
  `js/data-form-modules/*`, `templates/data-form-*.php`, a new
  `js/data-form/provider-form.js`, and parts of `inc/enqueue.php` and
  `inc/rest-api.php`). None of it is from this session. Do not commit
  it or revert it.
- **No emojis** anywhere: chat, code, UI text or commit messages.
- **Reports to the owner** carry full clickable URLs: live pages,
  GitHub commits
  (`https://github.com/carlygaejepsen/Kids-Over-Profits/commit/<full sha>`)
  and admin screens. Never bare paths or short SHAs.
- **The default target is the live site.** Test against it after
  every deploy.

### Access you have and do not have

- **Production reads over SSH were blocked** by the auto-mode
  classifier partway through this session. File listings, database
  queries and even `curl` from the server were refused. Do not try to
  get around it. Use the SQLite mirror instead, and ask the owner for
  anything only the server can show.
- **The SQLite mirror**: `tmp/prod.sqlite`, refreshed at 03:22 on
  2026-09-24. To refresh it, run `python scripts/sync-prod-sqlite.py`.
  That script is allowed; inline password greps are not.
- **PHP**: none on PATH. Use Local's binary:
  `$LOCALAPPDATA/Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win64/php.exe`
  with `-n -d extension_dir=<that bin>/ext -d extension=<name>`. The
  extensions used here are `sodium`, `mbstring`, `pdo_sqlite`,
  `fileinfo` and `zip`.
- **Checking a deploy**:
  `gh run list --workflow=deploy.yml --limit 5`, then curl a static
  asset with a browser User-Agent. HTML pages are bot-walled for
  plain curl.
- **Browser checks**: Python Playwright with `channel="chrome"` works
  against the live site. That is how every page check below was done.

---

## 1. What shipped

| When | Commit | What |
|---|---|---|
| 02:26 | [797831d](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/797831db40e14863a3a3cea4e007da38580d8734) | `docs/README.md` rewritten as a current index of the docs |
| 02:34 | [1b4265f](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/1b4265fb77d3e449f6c9525ca0b2e062c4f9062b) | The seven workflow docs in `docs/*/README.md` checked against the code and corrected |
| 02:46 | [6ce2e93](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/6ce2e9352c2a11ad7a47420d49e50d84a6efe122) | Anonymous portal: uploads encrypted on arrival with a public key |
| 03:01 | [45193bb](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/45193bb37ca9db9052f76c0967a0dca5851efb02) | Portal: migration also handles the October 2025 naming (`SUB-2025-...`) and PDF preview images |
| 03:31 | [63a482f](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/63a482f8488cce0bcfa56fac882ff44926fabc95) | Portal: refuses uploads it cannot scan, and files whose contents do not match their extension |
| 03:40 | [9c62ef4](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/9c62ef41dc04a8b8a18d56a488217bb1af70e937) | Retired Claude, Gemini and Hugging Face models replaced in both AI helpers |
| 03:47 | [3842507](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/38425073e14be4f7ab5bd6678d7921d4160fc46c) | `CLAUDE.md`: three wrong statements corrected |
| 03:50 | [e7be085](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/e7be085df9426d8b865871afa2c68664ddab1fab) | Notification mails link to Submissions Review on the right tab; wiki drafts no longer mail |
| 03:56 | [c58f429](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/c58f429118eefd90432eda0680e3b7a3d0973801) | Three references to files or elements that never existed removed |
| 04:01 | [1ed0cf5](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/1ed0cf55ccdf2857bd15920c01676861fb9991f1) | Cron line for the nightly severe-findings scan documented |
| 04:13 | [11d0dbb](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/11d0dbbbfc3bafd37bf14837e933db3841d7d32f) | FL and NC report pages load a lite list; each report's text is fetched when it is opened |
| 04:19 | [fd9a74e](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/fd9a74e2a8908985f470b5b07e79353ccf0d9f1b) | Docs and `CLAUDE.md` test commands for the lite list |

---

## 2. Anonymous document portal

The page is https://kidsoverprofits.org/anon-submit/ (shortcode
`[anonymous_doc_portal]`). The admin screen is
https://kidsoverprofits.org/wp-admin/admin.php?page=anonymous-docs.
The code is the `AnonymousDocPortal` class in `inc/features.php`. The
full reference is `docs/anonymous-portal/README.md`. Read it before
changing anything here.

### Why it changed

The portal told submitters that their files were "End-to-End
Encrypted" and "stored in an encrypted directory". Since the portal
was moved out of `functions.php` in October 2025, it had stored files
exactly as uploaded. The old version (last in commit `92a044c`,
2025-10-16) encrypted only the message and contact fields, never the
files. The owner chose public-key encryption, so that someone who
breaks into the server cannot read past submissions.

### How it works now

- Each upload and its notes are sealed together with
  `sodium_crypto_box_seal` and the public key in
  `inc/anonymous-portal-public.key` (fingerprint `e2f2be273231e1ff`).
  A `KOP_ANON_PORTAL_PUBLIC_KEY` constant overrides the file.
- Only `<id>.sealed` is written to
  `wp-content/uploads/anonymous-submissions/`. The original filename
  exists only inside the sealed box. Layout inside the box:
  `"KOPANON1"`, then a 4-byte big-endian header length, then the JSON
  header (`id`, `name`, `notes`, `received`), then the file bytes.
- **It fails closed.** Uploads are refused ("temporarily
  unavailable") when:
  - no usable public key is configured
  - sodium is missing
  - `CLOUDMERSIVE_API_KEY` is missing (since 63a482f; before that,
    unscanned files were accepted)
- A file's contents must match its extension
  (`AnonymousDocPortal::mime_matches()`, using PHP's fileinfo).
- Scanning happens on the plaintext before sealing: Cloudmersive
  receives the file over HTTPS. The UI now says "Encrypted on
  arrival". It must not say "end-to-end", because the browser does not
  encrypt anything.
- The admin screen lists sealed files with a **Download (encrypted)**
  button (`admin-post.php?action=kop_anon_download`, nonce-checked).
  It also offers an **Encrypt them now** button for plaintext left
  over from before this change, and shows red notices when either key
  is missing.

### Keys

- **The private key never goes into the repo or onto the server.**
  - It is in the owner's Bitwarden, as a secure note named "KOP
    anonymous portal private key".
  - A working copy is at `C:\Users\daniu\kop-anon-portal-private.key`
    (outside the repo and outside OneDrive).
  - The owner does not otherwise use a password manager. Bitwarden was
    set up for this.
- `scripts/anon-portal-keygen.php <private-key-file>` makes a key
  pair. It refuses to write inside the repo and refuses to overwrite
  either key file. Rotating the key means keeping the old private key
  for everything it sealed.
- To open downloads:
  `php -n -d extension_dir=<php>/ext -d extension=sodium scripts/anon-portal-decrypt.php <private key> <out folder> <file.sealed>...`
  The owner has been using `C:\Users\daniu\kop-anonymous-submissions\`
  as the output folder. Offer to run this for them when they download
  new submissions.
- Test: `scripts/test-anon-portal.php`, run with sodium, fileinfo and
  zip. 22 checks cover:
  - opening a sealed file, and rejecting the wrong key
  - refusing uploads when there is no key
  - migrating both naming schemes
  - the content-type checks, using genuine files plus a program and an
    HTML page renamed to `.pdf`

### What happened to the one old submission

Production held a single pre-encryption submission,
`SUB-2025-YLVUY0CL` (a PDF submitted 2025-10-02), plus 5 preview JPGs
that WordPress generated from it.

- It is now sealed. The owner clicked the migrate button and
  downloaded the `.sealed` file, and I opened it locally: 208,856
  bytes, a valid PDF.
- **It had also been public.** Someone added it to the media library
  on 2025-10-21 and filed it in the FileBird folder "Recent User
  Submissions" (folder 299, which the public "Document Archive" page,
  post 3774, appears to list). The May 2026 Drive restore made more
  copies. The mirror shows four attachments: 5876, 8468, 8707 and
  9362. There were 11 public URLs, on the main site and under
  `/staging/`, and the Wayback Machine captured the PDF on 2025-10-24.
- The owner said to take it down. They report that they deleted the 4
  media items and the staging files and emailed info@archive.org for
  removal. **I did not re-check afterwards; the owner told me not to.**
  If you want to confirm, all of these should now return 404:
  - `https://kidsoverprofits.org/wp-content/uploads/SUB-2025-YLVUY0CL_pJlaHQv1mxi2.pdf`
  - the same filename under `/staging/wp-content/uploads/`
  - the `-pdf.jpg` preview variants: `-pdf`, `-pdf-116x150`,
    `-pdf-232x300`, `-pdf-309x400`, `-pdf-768x994`
- **Still open:** NixiHost's own backups hold the plaintext until they
  rotate out, unless the owner asks support to purge them. The old
  `wpdl_anonymous_submissions` table has one row for this submission,
  with empty message and contact fields and a small `files_data` blob
  naming the original file. It is harmless but could be cleared.

### Confirmed and unconfirmed

- **`CLOUDMERSIVE_API_KEY` is set in production.** The owner checked
  the Anonymous Docs screen on 2026-09-24 and it shows no red "Malware
  scanning is not configured" box, so uploads are being accepted and
  scanned.
- Unconfirmed: no real upload was sent through the live form. The
  offline test covers that path.

---

## 3. Inspection reports: the lite list for FL and NC

The full reference is `docs/state-inspection-reports/README.md`
(API section and "Severe Findings").

### The problem it solved

`/fl-reports/` and `/nc-reports/` downloaded every report's full OCR
text at load:
- Florida: 106 MB raw, 22 MB compressed, 8 to 9 seconds to load
- North Carolina: 95 MB raw, 16 MB compressed

The text is displayed only inside an opened report. Both adapters did
read it at load, but only for a few values:
- NC: citation count, survey result, complaint outcome and opening
  sentence
- FL DJJ: QI compliance words, PREA date and standard counts, SPEP
  period, and the facility name in PREA audits

### How it works now

- `api/inspections-read.php?state=XX&lite=1` leaves out
  `raw_content`. Instead it sends:
  - `row_id`
  - `has_text`
  - `text_signals`: the values the page would have read from the text
- The signals are computed by `api/lib-inspection-text-signals.php`,
  a port of the adapters' readers:
  - `kop_its_nc_statement()` is the port of `readStatement()` in
    `js/inspections/states/nc.js`
  - `kop_its_fl_djj()` is the port of `djjTextSignals()` in
    `js/inspections/states/fl.js`
- The adapters use `report.text_signals` when present and fall back
  to reading the text themselves, so the full response still works.
- The lite response is cached as a file in
  `wp-content/uploads/kop-cache/inspections-XX-lite-<hash>.json`. The
  hash covers the state's report count, max id, max `updated_at` and
  `kop_its_version()`. Older files for that state are deleted when a
  new one is written. `Cache-Control: public, max-age=600`.
- `?state=XX&text=<row_id>` returns one report's `raw_content`,
  checked against the state.
- `KOP.reportPage.withText(report, state, build)` in
  `js/inspections/report-page.js` fetches that text when a report is
  opened. The engine's lazy body may now return a promise; it shows
  "Loading the report text..." until the promise settles.
- Every other caller of `inspections-read.php` (hub counts, facility
  pages, other states) is unchanged. Lite is opt-in.

Results measured on the live site:
- Florida: 0.13 MB transferred, list shown in about 2 seconds
- North Carolina: 0.21 MB transferred, list shown in about 2.4 seconds

The first request after new reports arrive rebuilds the cache, which
takes about 5 seconds server-side for FL.

### The one rule

**The PHP and JavaScript readers must give identical results.** If you
change `readStatement()` or the regex constants above it in `nc.js`,
or `djjTextSignals()` in `fl.js`, make the same change in
`api/lib-inspection-text-signals.php`. Then bump `kop_its_version()`
so the cache is rebuilt, and run:

```
node scripts/test-inspection-text-signals.js --php=<Local php.exe>
php scripts/test-inspections-read-lite.php   # with mbstring, pdo_sqlite, memory_limit=6G
```

The first test runs both readers over all 6,368 FL and NC reports in
the mirror and fails on any difference. It was confirmed to fail on a
one-word change. The second checks three things for FL, NC and CT:
- the lite list against the full list
- `?text=` against the original text
- the cache

Porting notes, also in the lib's header comment:
- JavaScript's `\s` includes Unicode spaces; the lib widens `\s` to
  match.
- `\b` and case-insensitivity behave the same in both.
- JavaScript's string length counts UTF-16 units; the lib counts the
  same way.

### How it was verified after deploy

Snapshots of both live pages before and after the change were
identical, comparing:
- facility and report counts
- every badge text
- every preview line
- every facility summary
- the top 15 of the violations sort

Opening a report of each FL DJJ kind (QI, PREA, SPEP) and an NC
statement fetched its text and showed the "Full report text" section.
AHCA surveys never have that section, by design.

### Rolling back

Remove `&lite=1` from the two `load()` URLs in `fl.js` and `nc.js`.
Nothing else needs to change.

---

## 4. Nightly severe-findings scan

`api/scan-inspection-highlights.php` already had a CLI mode. The cron
line is in `docs/state-inspection-reports/README.md` under "Severe
Findings":

```
15 4 * * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/scan-inspection-highlights.php apply >> /home/kidsover/logs/inspection-highlights-scan.log 2>&1
```

**The owner added this cron job in cPanel on 2026-09-24.** Its first
run was due at 04:15 server time on 2026-09-25. Nobody has checked the
log yet: ask the owner for its first lines, which should read "Saved:
scanned N reports, 0 remaining". The scan only queues candidates. Nothing reaches `/severe-reports/` until an
admin approves it at `api/review-inspection-highlights.php`. Use the
full PHP path: cron's plain `php` is php-cgi and exits "CLI only.".

---

## 5. AI providers

`api/process-news-ai.php` (news processor) and `api/ai-providers.php`
(used by `api/extract-wiki-from-prose.php` and
`api/retitle-from-content.php`) pointed their non-Groq options at
retired models. They now use:

| Provider | Default | Override in `.env` |
|---|---|---|
| Claude | `claude-opus-5`, `output_config.effort: low`, `fallbacks: "default"` (beta header `server-side-fallback-2026-07-01`) | `ANTHROPIC_MODEL` |
| Gemini | `gemini-3.5-flash-lite`, default temperature, `maxOutputTokens` at least 16384 | `GEMINI_MODEL` |
| Hugging Face (shared helper) | router chat completions, `meta-llama/Llama-3.1-8B-Instruct:fastest` | (none) |
| Groq | unchanged: `GROQ_MODEL`, then `openai/gpt-oss-120b`, then `openai/gpt-oss-20b` | `GROQ_MODEL` |

- Both Claude paths:
  - check `stop_reason == "refusal"`
  - join the `text` blocks, since the reply can start with thinking
    blocks
  - send no `temperature`
- Both Gemini paths skip `thought` parts.
- **The pages always send `provider: 'groq'`**, so none of the other
  providers run unless a request names them.
- **No live call was made to any of the new models.** There are no
  keys on this machine and production reads were blocked. The request
  shapes come from current docs.

---

## 6. Notifications and small fixes

- **Links in notification mails** (`inc/submission-notify.php`,
  `kop_submission_types()`): `suggested_edit`, `wiki`, `news` and
  `news_auto` now link to Submissions Review with `?type=data|wiki|news`.
  `js/admin-submissions.js` reads that parameter to select the tab.
  Test: `scripts/test-submission-notify.php`, 32 checks.
- **Wiki mails** go out only when an entry reaches `submitted`, either
  on insert or when a public draft is later submitted. The update path
  never mailed before. See `api/save-wiki-submission.php`.
- **Removed the missing `facilities_master.json` fallback.** The
  program index and `js/inspections/facilities-display.js` no longer
  try `js/data/facilities_master.json`, which never existed.
- **TX and AR are configured as API-only.** Their entries in
  `inc/enqueue.php` and `inc/rest-api.php` pointed at
  `tx_reports.json` / `ar_reports.json`, which do not exist. Both
  pages read `inspections-read.php`.
- **Deleted the wiki editor's dead entry browser.** `js/wiki-editor.js`
  lost 148 lines: `loadEntries`, `deleteEntry` and the pagination for
  a `#entriesList` element the page does not have.

---

## 7. Docs

- `docs/README.md` is the index. Add new docs there.
- The seven `docs/*/README.md` workflow docs were rewritten against
  the code on 2026-09-24. Things they report as found in the code:
  - The `program_index` v2 read area is controlled by the
    `kop_data_model` / `kop_data_model_areas` options. **Which mode
    production uses is unconfirmed** (a database option).
  - `kop_submission_notify_emails` has no admin screen; its production
    value is unknown.
  - `wpdl_anonymous_submissions` is a leftover table (section 2).

---

## 8. Open work, in the order I would take it

1. **Check the first severe-scan log** (section 4). The cron job is in
   place; ask the owner for the log's first lines, then look at any new
   candidates on the review screen.
2. **Move TX and CA to the shared viewer.** They are the last two
   states on legacy scripts (`js/inspections/tx_reports.js`,
   `js/inspections/ca-reports.js`). CA loads static
   `ccl_reports_batch_*.json` files and stores about 9,100 reports
   twice under two id schemes, which inflates hub counts; fix that
   first. Use the jsdom harness (memory note
   `jsdom-state-page-harness`) and the before/after snapshot method in
   section 3. `severe-flags.js` supports both the legacy and the
   shared markup, so check that the flags still land.
3. **Severe-finding extractors for more states.** Covered today: TX,
   CA, UT, AZ and CT (`kop_ih_supported_states()`, scanner version 3,
   in `inc/inspection-highlights.php`). Next are the raw-text states
   (NC, FL, GA, AR, MN, OR), then WA and NV. The rules: substantiated
   findings only, no child-on-child incidents, and elopement counts
   only with death or injury (see memory note
   `inspection-highlights-parser`).
4. **WA full scrape.** `wa_scraper.py --full` in the Tools repo needs
   `KOP_DATA_API_KEY`. The owner runs it.
5. **Remove the portal's leftover row.** Clear the leftover
   `wpdl_anonymous_submissions` row, or drop the table (section 2).
   Ask first.

---

## 9. Things that caught me out

- **Scripted edits and the classifier.** A Python script that
  rewrote `inc/features.php` and contained `unlink` calls was refused
  as destructive. The same change made with the Edit tool went
  through. For code, prefer Edit.
- **CRLF.** Many files are CRLF. Python rewrites should detect and
  keep the file's line endings, and assert that each target string
  matches exactly once.
- **Harnessing `inspections-read.php`.** `config.php` resets `$pdo`
  to null, so an offline harness cannot pass in a PDO. See how
  `scripts/test-inspections-read-lite.php` evals the endpoint with the
  config line removed, and with
  `ini_set('display_errors', 'stderr')` so `header()` warnings stay
  out of the captured body.
- **`Order Deny,Allow` in the upload folder's `.htaccess`** does work
  on LiteSpeed. `/wp-content/uploads/anonymous-submissions/` returned
  403 from outside. Files copied into the media library elsewhere are
  public, and that is how the old submission leaked.
- **Memory notes** relevant here: `anon-portal-encryption`,
  `inspection-redesign-backlog`, `prod-sqlite-mirror`,
  `php-lint-via-local`, `cpanel-cron-php-cgi`,
  `playwright-live-page-harness`.
