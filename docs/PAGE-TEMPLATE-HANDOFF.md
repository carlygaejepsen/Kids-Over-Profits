# Page Template Work: Handoff Plan

Written 2026-09-25 for the next agent picking up
[PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md). Read that plan
and [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md) first; this file says
what is already done, what is left, in what order, and how to check each
step. Work through the sections in order. Stop and ask the owner at every
point marked **Owner decision**; do not guess those.

## 1. Where things stand

| Phase | State |
| --- | --- |
| 0. Classify default-template pages | Done. [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md) |
| 1. Finish the hub family | Mostly done 2026-09-25. Remaining work in section 4 |
| 2. News and document archives | Done. `/news/` and `/news-2/` 301 to `/tti-news-feed/`; `/document-archive/` has its own template |
| 3. Legal and utility pages | Not started. Section 5 |
| 4. Posts | Not started. Section 6 |

### What Phase 1 shipped

Commits on `main`:
[021e5a7e](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/021e5a7e8c201eb39984f772bbbd735c08a65226),
[ee706edf](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/ee706edf0bc62602630857a84f71705c93f35e66),
[2e2ae95f](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/2e2ae95ffbe9e90ad3d28c169fe7e443da2040a4).

- [templates/page-hub.php](../templates/page-hub.php) builds every hub in this
  order: breadcrumb, H1, standfirst, action buttons; the page's editor
  content; the live module (`kop_hub_module_for()`); the articles filed under
  the hub; a "contribute" band; and a footer with the updated date, edit link
  and AddToAny share buttons.
- [inc/hub-shell.php](../inc/hub-shell.php) holds `kop_hub_config()`, one
  settings array per hub (standfirst, actions, content on or off, reading
  list, contribute links), plus the helpers that print each part. The doc
  comment at the top of that function lists every key.
- On hubs, Easy Table of Contents is switched off. Version 2.0.88 honours only
  the new `eztoc_maybe_apply_the_content_filter` hook, not the legacy one;
  both are filtered. AddToAny's in-content row is lifted while the hub prints
  its content, and the footer prints `[addtoany]` once instead.
- Law & Policy leaves out its editor text (`'content' => false`). Its module
  opens each column with that text, then shows the count and five newest
  records. Each record links to its own card: `/lawsuits/#lawsuit-<id>`,
  `/legislative-efforts/#bill-<id>`. The cards got those ids and a `:target`
  highlight in [css/public-records.css](../css/public-records.css).
- Every other hub keeps its editor content and has a standfirst. Most also
  have actions and a contribute band. Editorials and Investigatory Spotlight
  take their standfirst and next step from
  [inc/hub-posts.php](../inc/hub-posts.php) and have no entry in
  `kop_hub_config()`.
- `php scripts/test-hub-pages.php` renders all 13 hubs against
  `tmp/prod.sqlite` and writes each `<article>` to `tmp/hub-pages/<slug>.html`.
  It checks:
  - the H1 and footer;
  - that the TOC box and the in-content share row are gone;
  - the Law & Policy structure;
  - that every configured action and contribute link resolves;
  - that every reading-list article has a description line.

  It loads only the Law & Policy and Where Are the Kids modules.

The 13 hubs (from `kop_template_assignments()` in
[inc/admin.php](../inc/admin.php)):

- https://kidsoverprofits.org/history/
- https://kidsoverprofits.org/survivors/
- https://kidsoverprofits.org/researchreports/
- https://kidsoverprofits.org/families/
- https://kidsoverprofits.org/where-are-the-kids/
- https://kidsoverprofits.org/advocates/
- https://kidsoverprofits.org/journalists/
- https://kidsoverprofits.org/support/
- https://kidsoverprofits.org/volunteer/
- https://kidsoverprofits.org/law-policy/
- https://kidsoverprofits.org/resources/
- https://kidsoverprofits.org/editorials/
- https://kidsoverprofits.org/investigatory-spotlight/

## 2. Ground rules for this repository

These come from the owner and from past sessions. They are not optional.

- **Target is production** (`https://kidsoverprofits.org`) unless the owner
  says "local".
- **Work on `main`.** No feature branches, no PRs. Commit and
  `git push origin HEAD:main`. Pushing to `main` deploys through the
  `deploy.yml` workflow to cPanel.
- **The checkout is shared with other sessions.** Other agents edit and commit
  in the same working tree.
  - Never `git add -A`, `git add .`, `git stash`, `git reset --hard` or
    `git clean`. Stage files by path.
  - Before each commit, read `git diff --cached` and make sure every hunk is
    yours.
  - When a file you need also has someone else's uncommitted hunk, stage a
    blob built from `HEAD` plus only your change (`git hash-object -w` then
    `git update-index --cacheinfo`). On 2026-09-25, `functions.php` carried
    another session's `require_once` of an uncommitted file; committing that
    line alone would have fatalled production.
  - A file you just wrote can vanish (it happened once to
    `inc/hub-shell.php`). Check `ls` before linting, and commit promptly.
- **Line endings are mixed** (CRLF and LF, sometimes in one file).
  - Edit with whitespace-tolerant matching, or with Python reading and
    writing bytes, and keep each file's existing endings.
  - The Bash tool collapses backslashes; use a quoted heredoc
    (`<<'EOF'`) and raw strings.
- **Deleting a file with git does not delete it on the server.** cPanel deploy
  never deletes. Add an `rm -f` task to `.cpanel.yml` for each removed file.
- **Template assignments** live in `kop_template_assignments()` in
  [inc/admin.php](../inc/admin.php). They are applied once per version by
  `kop_maybe_apply_template_assignments()`; bump its `$version` string
  whenever the list changes. Redirects live in `kop_redirect_map()` in
  [inc/redirects.php](../inc/redirects.php).
- **No text directly on the gradient body background.** Every page's text
  sits on a solid panel. Check with `python scripts/check-bare-text.py`.
- **Colors** come from `var(--kop-*)` in [css/colors.css](../css/colors.css).
  Chartreuse, coral pink and bubblegum pink are for borders and highlights
  only. White text needs `--kop-teal-ink` or darker behind it, not
  `--kop-teal`.
- **No emojis** anywhere: UI text, code, commits, reports.
- **Never link `/tti-program-index/` for a facility.** It lists operators and
  chains only. Facility links go to `/facility/<slug>/` or the state hub
  `?search=`.
- **Reports to the owner** use full clickable URLs: live pages, GitHub commit
  URLs with the full SHA, admin endpoints. Never bare paths or short SHAs.

### Tools

- **PHP:** there is none on `PATH`. Use Local's binary:
  `"$LOCALAPPDATA/Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win32/php.exe"`.
  - Lint with `-n -l`.
  - Run the SQLite tests with
    `-n -d extension_dir=<that dir>/ext -d extension=pdo_sqlite -d extension=mbstring`.
- **Production mirror:** `tmp/prod.sqlite` was last synced 2026-09-24 and is
  already stale (pages were trashed and created on 2026-09-25). Refresh it
  with `python scripts/sync-prod-sqlite.py` before any inventory work.
- **Production reads over SSH** (read-only; ask before any write):
  - `ssh -i ~/.ssh/kop_nixihost -p 1157 kidsover@dfw-s07.nixihost.com`.
  - Database `kidsover_production`, table prefix `wpdl_`, credentials in
    `~/public_html/wp-config.php`, client `/usr/bin/mysql`.
  - There is no wp-cli.
  - Plugin source is under `~/public_html/wp-content/plugins/` if you need to
    confirm a hook.
- **Fetching live HTML:** outside curl is bot-walled, but curl on the server
  with a browser user agent works:
  `ssh ... 'curl -s -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128 Safari/537.36" "https://kidsoverprofits.org/<slug>/?nc=$RANDOM"'`.
- **Checking a deploy:**
  - Wait with
    `gh run list --workflow=deploy.yml --limit 1 --json headSha,status,conclusion`
    until your SHA shows `completed success`.
  - Then fetch the live HTML and grep for the classes you added.
  - A deploy can succeed and still serve stale files; if so, re-run the
    workflow.
- **Screenshots:** Python Playwright is installed. The pattern used on
  2026-09-25:
  1. Fetch the live page's HTML as above.
  2. Replace its `<article ... kop-hub ...>` with the harness output from
     `tmp/hub-pages/<slug>.html`.
  3. Replace the `css/hub.css` `<link>` with an inline `<style>` of the local
     file.
  4. Open the result as `file://` in Chromium and take full-page screenshots.

  Remote theme assets still load from the live site, so the page looks real.

## 3. Definition of done for every task

1. PHP lints clean. The relevant offline test passes (`test-hub-pages.php`,
   plus any new one you add).
2. Screenshots at 390, 768 and 1440 px wide, looked at, with no clipped text,
   overlap or horizontal scroll.
3. `python scripts/check-bare-text.py` passes for the touched templates.
4. Committed by path, pushed to `main`, deploy workflow green.
5. Live HTML fetched after deploy and checked for the change, with
   `critical error` absent.
6. [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md) "Status"
   section updated in the same commit or the next one.

## 4. Phase 1 wrap-up: the hubs

### 4.1 Commit the preview tool

Turn the screenshot pattern in section 2 into `scripts/preview-hub-pages.py`,
modelled on `scripts/preview-network-map.py`.

- **Input:** optional slugs (default: all 13) and `--shots <dir>`
  (default `tmp/hub-preview`).
- **Steps:**
  1. Run `scripts/test-hub-pages.php` via Local's PHP.
  2. Fetch each live page over SSH.
  3. Splice in the article and the local `css/hub.css`.
  4. Keep the live editor content: the harness strips block comments, so
     shortcodes such as `[display-map]` and `[wd_asl]` do not render in its
     copy. Replace the harness's `.entry-content` inner HTML with the live
     one.
  5. Map the harness's `[share buttons]` placeholder to the live AddToAny
     markup.
  6. Screenshot at 390, 768 and 1440.
- Add the command to the "Key Commands" block in [CLAUDE.md](../CLAUDE.md)
  next to `test-hub-pages.php`.

### 4.2 Visual QA of all 13 hubs

Run the preview tool and look at every screenshot. So far only Law & Policy
(desktop and mobile), History, Survivors and Support (desktop) were checked.
Things to look for:

- Action buttons wrapping on 390 px. "Upload documents anonymously" and
  "Parent companies and chains" are the longest labels.
- The contribute band's three-column grid at 768 px: it switches at 760 px, so
  768 is the tightest case.
- Hubs whose editor content ends in a wide block (Research & Reports' search
  block and image groups, Resources' grid groups, Where Are the Kids' map)
  sitting directly above a module or band with no spacing.
- The Research & Reports library module and the Resources module under the
  new header rule.

Fix in [css/hub.css](../css/hub.css), scoped to `.kop-hub*` classes. Do not
restyle the editor blocks themselves.

### 4.3 Small fixes in the hub frame

1. **"Updated" date on Law & Policy.** The footer shows the page's modified
   date (March 2026), but the page is now mostly live data. For a hub with
   `'content' => false`, show the newest `updated_at` across the module's
   tables instead, or drop the line. Add an optional `updated` callback key
   to `kop_hub_config()` rather than special-casing the slug in the template.
   Check the column names in `tmp/prod.sqlite` first (`lawsuits`,
   `legislation`).
2. **Test coverage.** `scripts/test-hub-pages.php` passed while the Survivors
   "Guide to legal action" link was dead on production, because the mirror
   still had the page. Add a live-check mode, e.g. `--live`: for every
   resolved action and contribute URL, a `HEAD` request run over SSH with the
   browser user agent, expecting 200 (or 301 to a 200). Keep it opt-in; the
   default run stays offline.
3. **Module stubs.** The harness does not load
   [inc/research-library.php](../inc/research-library.php),
   [inc/resources-list.php](../inc/resources-list.php) or
   [inc/hub-posts.php](../inc/hub-posts.php). Load `hub-posts.php` (its test
   already has the stubs it needs: `get_category_by_slug`, `get_posts` by
   `cat`) so the Editorials and Spotlight standfirsts are checked too. Leave
   the other two out if their stubs grow past a few functions; they have
   their own coverage.

### 4.4 Editor-content issues found on the hubs (Owner decision)

These are in the pages' WordPress content, not the code. Do not change them
without the owner's approval. Content fixes ship through the seed and text-fix
mechanism (`kop_apply_text_fixes()` in [inc/admin.php](../inc/admin.php)), not
by hand-editing production, so they survive and are reviewable. List them for
the owner with a recommendation each:

- **Where Are the Kids** links "click here for international programs" to
  `/international`, which 301s to `/location-index/?type=country`.
  Recommend: point the link at the destination.
- **Advocates** has the same `/international` link, and links
  `/tti-program-index/` as "Learn more about TTI program affiliations here".
  The second is correct as written: operators, not facilities.
- **Survivors** repeats a long list of support groups and social media
  accounts that the Resources module (`inc/resources-list.php`) also carries.
  Recommend: keep one list on Resources and link to it from Survivors.
  Otherwise the two drift apart.
- **Survivors** links "Tips for Enjoying Nature as a Wilderness Therapy
  Program Survivor" to the post `/11-12-2024/`. The reading list now links the
  page `survivor-resources-nature`. Check whether they are the same text; if
  so, redirect the post to the page.
- **Research & Reports** uses "Summary coming soon" on six entries and
  "Click here for notes" links. Its library module is the canonical list now.
  Recommend: trim the editor content to the introduction.
- **Several hubs** use `h6` for section headings (Survivors, Research &
  Reports, Advocates, Resources) and `h5` under `h3` (History). This skips
  heading levels. Recommend: `h2` and `h3`.
- **Support vs Donate:** `/support/` (a hub) and `/donate/` (a default page,
  section 5) both carry funding copy and the Givebutter widget. Recommend:
  merge into one and redirect the other; see 5.3.

## 5. Phase 3: legal and utility pages

Pages in scope, from [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md):
`links`, `anon-submit`, `donate`, `contact`, `no-access`, and
`richardson-v-elevations-rtc-prelitigation-panel-opinion`. Refresh the mirror
and re-confirm each is still published and on the default template before
starting.

### 5.1 Utility template

Create `templates/page-utility.php` with `css/utility.css`. The shape:

- A solid panel (the site's white content panel) with the breadcrumb
  (`kop_article_breadcrumbs()` only prints when the page is placed; for
  utility pages print Home plus the title directly).
- The H1, and an optional standfirst from a `kop_utility_config()` array in a
  new `inc/utility-pages.php`, following the pattern of `kop_hub_config()`.
- The editor content, printed through `the_content()` unchanged. Every one of
  these pages depends on a shortcode or plugin block that must keep
  rendering:
  - `[anonymous_doc_portal]`
  - `[dlm_no_access]`
  - the Givebutter widget
  - the link-preview blocks on `links`
- An optional primary action and an optional "other ways to reach us" list.
- No Easy TOC. Reuse `kop_hub_no_toc()`'s approach: a check on the
  utility template, hooked on both TOC filters.
- No AddToAny row on `no-access`, `anon-submit` and `contact`. Sharing an
  upload form or an error page is noise. Keep it on `donate` and `links`.

Assign the pages in `kop_template_assignments()` and bump the version.

Per-page checks after deploy, each against the live site:

| Page | Must still work |
| --- | --- |
| `anon-submit` | The portal renders its upload form. Uploads are sealed with the site's libsodium public key (`inc/anonymous-portal-public.key`); the form fails closed without it. Do not submit a real upload; confirm the form markup and script tags are present |
| `no-access` | Download Monitor's settings point at this page by ID. Never trash, rename or change its slug. Confirm `[dlm_no_access]` output is present |
| `donate` | The `<givebutter-widget>` element is present and its script loads |
| `contact` | The email link is a working `mailto:` |
| `links` | Five link-preview cards. The first card's image, attachment 3862 `2025/09/trauma_does_not_expire.jpg`, is missing and 404s. **Owner decision:** the owner re-uploads it in the editor; do not substitute another image |

The host firewall 403s POST fields containing HTML. If any utility page gains
a form, post document text as multipart file parts.

### 5.2 Legal-document template

One page qualifies today:
`richardson-v-elevations-rtc-prelitigation-panel-opinion` (ID 317). Its
content is four images of the panel opinion, and lawsuit 10's
`document_urls` points at it.

- Create `templates/page-legal-document.php` with:
  - the case name as H1;
  - a metadata block: court or panel, date, parties, and the lawsuit it
    belongs to, linked to `/lawsuits/#lawsuit-<id>`;
  - a short "what this document is" line;
  - the page images in order, each with alt text naming the page number;
  - a link to the source file if one exists.
- Take the metadata from the `lawsuits` row (look it up through
  `lawsuits.document_urls` containing the page URL). Do not hard-code it.
- Check first whether a PDF of the opinion exists in the media library (the
  ten other single-document pages were retired in favour of their PDFs; see
  the redirect rows in PAGE-CLASSIFICATION.md).
  - If one exists: **Owner decision**, whether to retire this page behind a
    301 to the PDF like the others instead of building a template for one
    page. Recommend the redirect if the PDF has the same content.
  - If no PDF exists: build the template.

### 5.3 Support and Donate (Owner decision)

Present the overlap from 4.4 with a recommendation: keep `/donate/` as the
page the header menu and the Donate buttons point to, move `/support/`'s
funding lists into it, and 301 `/support/` there. Removing `/support/` from
the hub list then means:

- dropping it from `kop_template_assignments()` (and bumping the version);
- dropping its `kop_hub_config()` entry;
- checking menu items and inbound links (query `wpdl_posts.post_content` for
  `/support/`).

Wait for the owner's answer before doing any of it.

## 6. Phase 4: posts

Numbers from `tmp/prod.sqlite` (2026-09-24):

- 114 published posts. 12 use `templates/single-facility-profile.php`; the
  rest render with Kadence's single-post layout.
- Categories overlap. News 75, Local News 59, US News 16, Lawsuits 13, "Where
  are the kids?" 12, Facility Profile 12, plus state categories.

### 6.1 Inventory

Write `scripts/inventory-posts.py` (reads `tmp/prod.sqlite` after a fresh
sync; writes `tmp/post-inventory.csv` and a summary to stdout). For every
published post, record:

- ID, slug, title, date, categories;
- content length and whether it has a featured image;
- template meta;
- inbound links (count of other published content linking its URL);
- whether it is in the news feed already (match against `news_submissions`
  by URL or title; see [api/lib-news-post-import.php](../api/lib-news-post-import.php)
  for how the 78 news posts were imported on 2026-09-25).

Then propose a class for each, as a column in
`docs/POST-CLASSIFICATION.md` in the same shape as PAGE-CLASSIFICATION.md:

- `news`
- `article` (research or editorial)
- `legal-record`
- `facility-profile`
- `tool-or-test`
- `obsolete`

### 6.2 Posts that look like development leftovers (Owner decision, high priority)

These are published and publicly reachable:

- `facility-form-test` (52 KB)
- `data-organizer` (62 KB)
- `data` (79 KB)
- `data-analysis`
- `arizona-adhs-inspections` (2.7 MB of content)

Read each and list for the owner what it contains and whether any public page
or menu links it. Recommend unpublishing to draft rather than trashing, so
nothing is lost. Check that no internal link or redirect depends on them
first. Do nothing to them until the owner answers.

### 6.3 News posts

The 75 News-category posts are now duplicated in the news feed. Options to
put to the owner, with a recommendation:

1. **Recommended:** 301 each news post to its news feed entry, or to the feed
   filtered to it if entries have no permalink, via `kop_redirect_map()` or a
   generated map. This matches how `/news/` was handled. Keeps one canonical
   copy.
2. Keep the posts and give them a `templates/single-news.php`
   (`Template Post Type: post`). The template shows source outlet, date and
   original link, plus a "More in the news feed" link.

Before either, confirm every news post's feed entry exists (6.1's
`in_news_feed` column must be true for all of them).

### 6.4 Lawsuit posts

For the 13 Lawsuits-category posts, find the matching `lawsuits` row
(by case name, parties or `document_urls`).

- If the row exists and the post adds nothing, recommend a 301 to
  `/lawsuits/#lawsuit-<id>`.
- If the post has analysis the directory lacks, assign `page-article.php`'s
  post-type equivalent, or add a "Read the analysis" link to the lawsuit row.
- **Owner decision** per post.

### 6.5 Facility profiles and "Where are the kids?" posts

- The 12 facility-profile posts are done.
- For the 12 "Where are the kids?" posts, check each against `/facility/<slug>/`
  (generated pages, [inc/facility-pages.php](../inc/facility-pages.php)).
  - Where a generated page exists and covers the same facility, recommend
    assigning `single-facility-profile.php`, the same treatment the 12
    already got.
  - Remember that a facility has three JSON copies and the state hub reads the
    state aggregate first.

### 6.6 Archives and 404

Only after 6.1 to 6.5 are settled:

- a category archive layout (Kadence's is the default today);
- a 404 page with search and links to the main hubs.

Both need the solid-panel rule.

## 7. Suggested order and size

| Step | Size | Blocks on |
| --- | --- | --- |
| 4.1 preview tool | small | nothing |
| 4.2 visual QA and CSS fixes | small to medium | 4.1 |
| 4.3 frame fixes and tests | small | nothing |
| 4.4 content list to owner | small (writing) | owner reply before any edit |
| 6.2 dev-leftover posts to owner | small (writing) | owner reply; send early, it is the most exposed issue |
| 5.1 utility template | medium | nothing |
| 5.2 legal-document page | small | owner reply on redirect vs template |
| 5.3 Support/Donate | small | owner reply |
| 6.1 post inventory | medium | fresh mirror |
| 6.3 to 6.5 posts | medium to large | 6.1, owner replies |
| 6.6 archives and 404 | medium | 6.3 to 6.5 |

Send the owner decisions (4.4, 5.2, 5.3, 6.2) together in one message early,
so the answers arrive while the code work proceeds.
