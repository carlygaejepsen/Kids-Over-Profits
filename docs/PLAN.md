# Work plan

The one place for open work on the theme and on https://kidsoverprofits.org:
how to work in this repository, what is waiting on the owner, and what a
session can pick up next, in order. It replaces four handoffs (2026-09-18,
the two of 2026-09-24, and the page-template handoff of 2026-09-25) and the
open-work parts of the September fix plan. Those handoffs are in git history
if you need their wording.

Last checked against `main` at
[58b45532](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/58b455321b8a36123835f85d94cffac0ed6b4c4f)
on 2026-09-28. Check `git log origin/main` for what has landed since, and
update this file in the same commit as the work it tracks.

The record of finished work, with the reasoning behind each decision, stays
where it was written:

- [FIX-PLAN-2026-09.md](FIX-PLAN-2026-09.md): items 1 to 20 of the
  September fix list (code comments cite its item numbers).
- [NETWORK-MAP.md](NETWORK-MAP.md): the map's phases, with a "Built" note
  per step.
- [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md) and
  [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md): the template families and
  what became of every default-template page.
- The area READMEs listed in [README.md](README.md), for example
  `anonymous-portal/`, `state-inspection-reports/` and `news-processor/`.

---

## 1. Working in this repository

The owner's rules:

- **The default target is the live site.** Test against it after every
  deploy. Treat work as local (Flywheel) only when the owner says so.
- **Work on `main`.** No branches, no PRs. A push to `main` deploys through
  `.github/workflows/deploy.yml` and `.cpanel.yml`.
- **No emojis** anywhere: chat, UI text, code, comments, commits.
- **Reports to the owner carry full clickable URLs**: live pages, GitHub
  commits as `https://github.com/carlygaejepsen/Kids-Over-Profits/commit/<full sha>`,
  admin endpoints. Never bare paths or short SHAs.
- **No text directly on the gradient background.** Check with
  `python scripts/check-bare-text.py` after any template or page CSS change.
- **Colours** from `var(--kop-*)` in `css/colors.css`. Chartreuse, coral pink
  and bubblegum pink are for borders and highlights; accent colours as text
  use the `-ink` tokens.
- **Never link `/tti-program-index/` for a facility.** Facility links go to
  `/facility/<slug>/` or the state hub's `?search=`.
- **Program and organisation websites** link to the Wayback Machine first,
  and to the live site only through `/go/`.
- **Ask before any write to production.** Reads are fine.

The checkout at `C:\Users\daniu\source\repos\Kids-Over-Profits` is shared with
other sessions that commit and push to `main` at the same time:

- Stage files by path. Never `git add -A`, `git add .`, `git stash`,
  `git reset --hard` or `git clean`.
- Read `git diff --cached` before committing, and `git show --stat HEAD`
  after. Other sessions have swept staged files into their commits.
- When a file you need carries someone else's uncommitted hunk, stage a blob
  of `HEAD` plus only your change (`git hash-object -w`, then
  `git update-index --cacheinfo`). Committing another session's
  `require_once` of an uncommitted file would fatal production.
- Push with `git pull --rebase --autostash origin main`, then
  `git push origin HEAD:main`.
- If Source Control fills with files already on `main`, compare each to
  `origin/main`, `git reset --mixed origin/main` (moves the branch, touches no
  file), refresh only files equal to an older committed version, and set the
  upstream to `origin/main`.
- **Deleting a file in git does not delete it on the server.** Add an
  `rm -f` task to `.cpanel.yml` for each removed file.
- Template assignments are `kop_template_assignments()` in `inc/admin.php`;
  bump the `$version` in `kop_maybe_apply_template_assignments()` when the
  list changes. Redirects are `kop_redirect_map()` in `inc/redirects.php`.

Mechanics:

- **Line endings are mixed**, CRLF and LF, sometimes in one file. Keep each
  file's endings; match whitespace-tolerantly; assert each target string
  matches once.
- **The Bash tool collapses backslashes.** Put regex-bearing edit scripts in
  a file and run the file, or use the Edit tool. A Python script containing
  `unlink` was refused as destructive where the same Edit went through.
- **PHP**: none on PATH. Use
  `$LOCALAPPDATA/Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win64/php.exe`
  with `-n -l` to lint, and `-n -d extension_dir=<that bin>/ext -d
  extension=pdo_sqlite -d extension=mbstring` (plus `sodium`, `fileinfo`,
  `zip` where a test needs them) for the harnesses.
- **Production mirror**: `tmp/prod.sqlite`, refreshed by
  `python scripts/sync-prod-sqlite.py`. It goes stale fast; refresh before
  inventory work, and confirm a finding on the rendered page before
  reporting it (item 20 of the fix plan was wrong for reading the mirror).
- **Production over SSH**: `ssh -i ~/.ssh/kop_nixihost -p 1157
  kidsover@dfw-s07.nixihost.com`, database `kidsover_production`, prefix
  `wpdl_`, no wp-cli. The auto-mode classifier has refused SSH reads in some
  sessions; do not work around it, use the mirror and ask the owner.
- **Deploy check**: `gh run list --workflow=deploy.yml --limit 5`. A failure
  reading "VersionControl/update failed after 3 attempts" is the host; the
  next push deploys your commit too (`git merge-base --is-ancestor`). Then
  fetch a static asset with a browser user agent; HTML pages are bot-walled
  for outside curl, but curl over SSH with a browser user agent works.
- **Browser checks**: Python Playwright with `channel="chrome"` against the
  live site. `scripts/preview-network-map.py`, `scripts/preview-hub-pages.py`
  and `scripts/preview-utility-pages.py` splice the working tree into live
  pages.

---

## 2. Waiting on the owner

Actions only the owner can take (admin browser sessions, hosting, editorial
calls). Tools that change data show a dry run first; add `?apply=1` to act.
The date is when each was last confirmed open.

### Admin runs

1. (Done 2026-09-29, no action: Severe Reports joins the Monitor menu
   through `kop_nav_item_specs()` on deploy. `rebuild-header-menu.php`
   was retired (its apply would have dropped TTI Glossary, Open Data and
   Report Abuse) and deleted 2026-09-30.)
2. **PDF covers** (2026-09-29: 177 left).
   [regenerate-pdf-previews.php?apply=1](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php?apply=1)
   until it reports 0 remaining. A PDF that gets the process killed (the
   browser sees a 503) is marked and skipped on the next run; two full
   Woodbury issues and one Sequel TSI PDF are marked so far.
3. **Severe findings review** (2026-09-29). The nightly scan cron is not
   installed (no crontab entry, no log on 2026-09-29). Add it in cPanel >
   Cron Jobs, the line in `docs/state-inspection-reports/README.md`. Then
   send the first lines of
   `/home/kidsover/logs/inspection-highlights-scan.log` (expect "Saved:
   scanned N reports, 0 remaining"), then review candidates in
   [review-inspection-highlights.php](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php).
   Nothing reaches [/severe-reports/](https://kidsoverprofits.org/severe-reports/)
   unapproved. Scanner version 4 (2026-09-28) adds NC, GA, MN, AR and FL
   and tightens two rules; approved findings the new rules no longer back
   stay published until rejected by hand:
   [13 approved findings to recheck](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php?status=approved&ids=230,1285,1123,1306,561,1307,616,859,875,114,199,408,894)
   (minors' sexual activity without an adult, single medication errors).
4. **Featured report notes** (2026-09-18). The two featured UHS of Provo
   Canyon reports have no `featured_note`; add one each in
   [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php).
5. **Research facility tags** (2026-09-23).
   [propose-research-facility-tags.php](https://kidsoverprofits.org/wp-content/themes/child/api/propose-research-facility-tags.php)
   writes `kop-research-tag-proposals.json` to uploads; prune it, then
   `?apply=1`.
6. **Research relevance tiers** (2026-09-23). Rate documents in the
   [research library](https://kidsoverprofits.org/researchreports/) editor.
7. **Staging pingback** (2026-09-23). Delete comment 453 on
   [/hyde/](https://kidsoverprofits.org/hyde/), a pingback from the staging
   copy.
8. **WA full scrape** (2026-09-24). `wa_scraper.py --full` in the Tools repo,
   with `KOP_DATA_API_KEY`.
9. **Woodbury Reports pages** (2026-09-29). Review at
   [KOP Data Tools > Woodbury Reports](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-woodbury-reports):
   2,208 candidates from 73 issues (2006-2014). "File it" puts the pages in
   the program's doc library. Start with Articles (exact matches are
   pre-ticked), then News items. "No record yet" holds ~230 program articles
   with no facility record, a lead list for new records.
10. **Monthly Unsilenced check** (2026-09-30). Add in cPanel > Cron Jobs
    (Once Per Month, or this line as is):
    `0 4 1 * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/list-unsilenced-files.php restart --check --minutes=120 >> /home/kidsover/logs/unsilenced-check.log 2>&1`
    It lists Unsilenced's archive (read-only, about 20 minutes) and mails the
    submission notification list only when 10+ new documents sit in programs
    the pages list, 100+ anywhere, or the run failed. On such a mail, ask for
    a rebuild: copy `~/kop-import/unsilenced/check/files.jsonl` to
    `tmp/unsilenced/files.jsonl`, sync the mirror, run the build, commit. The
    first full list ran 2026-09-30 (148,269 files); about 5,900 of their
    folders stay unmatched (`tmp/unsilenced/build-report.md`); a past or
    other name on a facility record ties one in.

### Hosting

9. **Close `/staging/`.** It answers 200 and its inner pages carry no
    `noindex`. Password it in cPanel, set "Discourage search engines" in the
    staging install, or take it down; nothing on the live site depends on it.
10. **Portal leftovers** (optional). NixiHost backups still hold the one
    pre-encryption submission (`SUB-2025-YLVUY0CL`) until they rotate, unless
    support purges them. The owner deleted the public copies and asked
    archive.org to remove its capture on 2026-09-24; not re-checked, at the
    owner's request.

### Decisions

11. **Hub editor content** (page templates 4.4). Changes ship through
    `kop_apply_text_fixes()` in `inc/admin.php`, never by hand on
    production. Recommendations:
    - Where Are the Kids and Advocates link `/international`, which 301s to
      `/location-index/?type=country`: point the links at the destination.
      (Advocates' `/tti-program-index/` link is correct: operators.)
    - Survivors repeats the support groups and accounts the Resources module
      carries: keep one list on Resources and link to it.
    - Survivors links "Tips for Enjoying Nature..." to the post
      `/11-12-2024/`; if it matches the page `survivor-resources-nature`,
      redirect the post to the page.
    - Research & Reports: "Summary coming soon" on six entries and "Click
      here for notes" links; the library module is the canonical list, so
      trim the editor content to the introduction.
    - Survivors, Research & Reports, Advocates and Resources use `h6` for
      section headings, History `h5` under `h3`: use `h2` and `h3`.
12. **Development leftover posts** (page templates 6.2, most exposed).
    Published and public: `facility-form-test`, `data-organizer`, `data`,
    `data-analysis`, `arizona-adhs-inspections` (2.7 MB). Recommendation:
    unpublish to draft, after checking no link or redirect depends on them.
    `tmp/post-inventory.csv` shows zero inbound links for the first four.
13. **News posts** (6.3). The 75 News posts are now duplicated in the news
    feed. Recommended: 301 each to its feed entry, as `/news/` was.
    Alternative: a `templates/single-news.php`.
14. **Lawsuit posts** (6.4). Per post: 301 to `/lawsuits/#lawsuit-<id>` when
    the row covers it, or keep the analysis and link it from the row.
15. **OG Image snippet.** Code Snippets snippet 6 duplicates Yoast's five
    `og:` tags on the front page. Recommended: deactivate it.
16. **Network map.** Kind marks answered 2026-09-28 (a small-caps kind
    word under the name; open work 3.1.3). Still open: the judgement
    calls in `tmp/network-qa.md` (rebuilt by every map build):
    about 50 profiles naming another node as a past or other name, closed-
    to-closed rebrands, unplaced memorial programs, Silverado Academy,
    "Brent Hall" folded into Brent Charles Hall, and the 56 staff-list
    places not on the board.
17. **Portal table.** Clear the leftover `wpdl_anonymous_submissions` row
    (empty message and contact, a `files_data` blob naming the file) or drop
    the table.

### Unconfirmed settings

18. Which mode production's `kop_data_model` / `kop_data_model_areas`
    options use for the `program_index` read area, and the value of
    `kop_submission_notify_emails` (no admin screen; default `admin_email`).

---

## 3. Open work, in order

### 3.1 Network map

Live at https://kidsoverprofits.org/network-map/. Build and ship each on its
own.

1. **Phase 4 integration** (owner's go-ahead 2026-09-28; specified
   2026-09-29 under "Phase 4: integration" in NETWORK-MAP.md, with a
   ten-commit sequence and six open decisions for the owner). Build
   order: 4.4 first step (built 2026-09-29: /history/ drops Miro for a
   still of the Historical view, `images/network-map-historical.jpg`,
   seed_version 2, open decision 6 taken as proposed); 4.1 the map on
   facility pages and profile posts (built 2026-09-29: the Focus view's
   own slice, not one hop, held to focus.js by
   `scripts/test-network-embed.js`; decisions 1 and 2 as proposed); 4.4
   second step, the
   `[kop_network_preview]` shortcode making that still live (built
   2026-09-29, seed_version 3); 4.3 the
   timeline, a year slider (owner 2026-09-29: names with no known years
   are left out while it is on, with a count; people follow their
   places; built 2026-09-29, and the undated names researched on the
   web: 250 with sourced years wait on the owner at KOP Data Tools >
   Map Years (a second pass on 2026-09-29 found 99 of the 180 the first missed); 81 still have none, worth a rerun with a raised web search cap). 4.2, the board re-import, is dropped (owner 2026-09-29:
   nobody edits the Miro board; corrections stay in
   network-overrides.json).
2. Later, not to be started without a word from the owner: chain hulls and
   group-by-network (2d.4), "Suggest a correction" from the map (Phase 3).

Shipped or closed from this list, all 2026-09-28: the route highlight
(2d.6), the list view with CSV download, the David Gilcrease checks
(2b.1, closed: the module test holds and the deployed build draws all
five), and the kind marks with their legend rows (2d.2 and the rest of
2d.9, the owner's small-caps kind word). The record of what was decided
is NETWORK-MAP.md.

After any map change: `node scripts/build-network-graph.js`,
`node scripts/test-network-graph.js`, and
`node scripts/test-network-modules.js > tmp/nm-test.log 2>&1` in the
background (9 to 12 minutes, silent until the end).

### 3.2 Page templates and posts

Phases 0 to 3 of [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md)
are done: hubs from `kop_hub_config()` (`inc/hub-shell.php`), the preview
tool, the Law & Policy updated date and `--live` link check in
`scripts/test-hub-pages.php`, the utility and legal-document templates
(`inc/utility-pages.php`), `/support/` 301 to `/donate/`, the Links card
image. `no-access` still prints a raw `[dlm_no_access]` (Download Monitor
inactive; the owner is not concerned). Never trash, rename or re-slug
`no-access`: Download Monitor points at it by ID.

Open:

1. **Visual QA of all hubs** (4.2). Run
   `python scripts/preview-hub-pages.py --shots tmp/hub-preview` and look at
   every shot at 390, 768 and 1440. Only Law & Policy, History, Survivors and
   Support were checked. Watch the longest action labels at 390, the
   contribute band at 768 (it switches at 760), and wide editor blocks
   (Research & Reports, Resources, Where Are the Kids' map) sitting on a
   module with no spacing. Fix in `css/hub.css` under `.kop-hub*` only.
2. **Post classification** (6.1). `scripts/inventory-posts.py` exists and
   `tmp/post-inventory.csv` was written on 2026-09-25 with a proposed class
   per post. Refresh the mirror, re-run it, and write
   `docs/POST-CLASSIFICATION.md` in the shape of PAGE-CLASSIFICATION.md
   (`news`, `article`, `legal-record`, `facility-profile`, `tool-or-test`,
   `obsolete`). Confirm `in_news_feed` is true for every News post before
   decision 14 is acted on.
3. **"Where are the kids?" posts** (6.5). For each of the 12, where a
   `/facility/<slug>/` page covers the same facility, recommend
   `single-facility-profile.php`, as the 12 facility-profile posts got.
4. **Act on decisions 12 to 14** once answered.
5. **Category archive layout and a 404 page** (6.6), with search and links to
   the main hubs, after 2 to 4. Both on a solid panel.
6. **"In this article" and "In this section" on the history pages**
   (owner's suggestion 2026-09-29; spec at the end of
   PAGE-TEMPLATE-DESIGN-PLAN.md). Programs, people and companies each
   article names, found by matching the network map's names against the
   content and linked to profiles and the map, with a pin/skip map in
   `inc/article-parts.php`; the same across the section on the History hub
   beside the map preview, plus six to eight owner-chosen dated events
   linking to their entries. No events per article: the period band and
   Contents already are that. Hidden below three names, which leaves the
   six pre-industry pages as they are.

Done for any template task: PHP lints, the offline test passes, screenshots
at 390, 768 and 1440 looked at, `check-bare-text.py` passes, deploy green,
live HTML checked (and `critical error` absent), and the design plan's Status
updated.

### 3.3 Inspection reports

1. **The first scan log** (owner item 3), then any new candidates.
2. **Move TX and CA to the shared viewer.** The last two states on legacy
   scripts (`js/inspections/tx_reports.js`, `js/inspections/ca-reports.js`).
   Fix CA first: it loads static `ccl_reports_batch_*.json` and holds about
   9,100 reports twice under two id schemes (`455002153-3` and
   `455002153-3-d92232c9bd`), which inflates hub counts. Use the jsdom
   harness and a before/after snapshot of counts, badges, previews and
   summaries (the FL/NC lite-list method). `severe-flags.js` reads both
   markups; check the flags still land.
3. **Severe-finding extractor for WA.** Built
   (scanner version 4, 2026-09-28): TX, CA, UT, AZ, CT, NC (harm tags only:
   V 131/132, 289-296, 314/315, 366/367, 512-525), GA, MN, AR (federal
   surveys only), FL (DJJ reviews, Failed/Limited indicators). Left out: OR
   (findings are the rule's own wording), WA (scraper interleaves the PDF
   columns), NV (no text). Rules: substantiated only; no child-on-child
   fights; minors' sexual activity only with an adult taking part or an
   assault; single medication errors never, a pattern of them yes;
   elopement only with death or injury. Flags on the trackers match NC,
   GA, MN, AR and FL reports by their document link (08172021).
   Scanner version 5 (2026-09-30): "Suicide attempt" and "Self-harm" are
   separate categories, and training, screenings, prevention plans and
   definitions no longer read as deaths or attempts. Version 6
   (2026-09-30) adds OK: substantiated complaint items at full score, NRS
   visit items at full score, other visit items as citations (17 candidates
   from 994 reports, 6 severe). A facility page used as every report's link
   (OK, MI) is never matched as a document; OK flags match by text.
4. **"What inspectors found" on facility pages** (the rest of fix-plan step
   14.5): approved highlights on `/facility/<slug>/`.
5. **Next state scrapers: Oklahoma, Michigan, Pennsylvania** (researched
   2026-09-30; 15 uncovered states checked, these three plus Idaho and
   Virginia publish report text). One build plan per state, ready to hand to
   an agent, in `docs/state-inspection-reports/plans/` (`README.md` first).
   Oklahoma shows a rolling 36 months and Michigan lets licensees have
   reports removed after two years, so both lose data while they wait. Owner
   items per state: create the `/xx-reports/` page and approve the first
   production post.
   **Michigan live 2026-09-30:** posted (110 facilities, 1,891 reports),
   `/mi-reports/` published. `mi_scraper.py` (Tools
   repo) and `js/inspections/states/mi.js`. A full `--no-post` run: 110
   facilities, 1,891 reports (1,300 special investigations, 281 renewal,
   229 interim, 81 original), 997 flagged, 2003-01-06 to 2026-09-30; every
   investigation but one scan yields its allegations. One posted document
   was a seclusion sheet naming a detained youth: documents that are not
   licensing reports are never posted. 51 of the 110 names reach a
   `facilities_v2` record (`php scripts/match-inspection-names.php
   --state=MI --file=<out.json>`; near misses such as "Calumet Center" /
   "Calumet RTC" are left for other names on the records). Owner: create
   `/mi-reports/` (State Reports template; the tracker lists pick it up once
   published) and approve the first post (both done). (The 1,890 PDFs that landed in the
   local `mi_pdfs/` fallback were moved to Drive on 2026-09-30.) Then the severe
   finding extractor (plan step 10).
   **Oklahoma live 2026-09-30:** posted (93 facilities, 994 reports) and
   `/ok-reports/` published (created on deploy by `kop_tool_page_specs()`). `ok_scraper.py` (Tools
   repo) and `js/inspections/states/ok.js`. A full `--no-post` run: 93
   programs (72 residential, 21 shelters), 994 reports: 844 monitoring
   visits (561 with non-compliances, 1,553 items, 38 marked numerous,
   repeated or serious) and 150 substantiated complaints; 711 flagged;
   2023-10-03 to 2026-09-30; no unparsed blocks. Every page is kept gzipped
   in the Drive folder `ok_html/` (the state shows only 36 months), and
   `--from-saved` rebuilds the same payload from those copies. 50 of the 93
   names reach a `facilities_v2` record (most unmatched are shelters KOP
   does not track); near misses left as they are: "Positive Outcome/St.
   Anthony" / "Positive Outcome - St. Anthony" and "Cookson Hills" /
   "Cookson Hills Christian Ministries". Owner: run the scraper **at
   least monthly** (Oklahoma in `scraper_launcher.py`; owner runs it by
   hand). Severe findings: scanner version 6, see item 3.

The FL/NC lite list has one standing rule: the PHP readers in
`api/lib-inspection-text-signals.php` must match `nc.js` and `fl.js`
exactly. Change both, bump `kop_its_version()`, and run
`node scripts/test-inspection-text-signals.js --php=<php.exe>`.

### 3.4 Long-form articles

The code is done (fix plan 15, 17, 19). What is left is mostly writing, and
the text goes in the page body, never in post meta, ACF or a table (owner,
2026-09-23), so the site search can find it:

1. **15B**: decide which sections of the long articles to wrap in
   `[kop_section]` and above what length (candidates: Corporatization,
   Advocacy History, Straight Inc, WWASP).
2. **15C** summaries (`[kop_summary]`), **15E** "Why this matters"
   (`[kop_why]`), **15D** era tags (`[kop_era]`).
3. **18A** Advocacy History (the brief's "Survivors and Families Fight Back"
   is the same page), **18B** Corporatization (a corporate-layering SVG, a
   key-concepts block, a link into the map on its chain), **18C** Lawsuits
   (intro, status labels, filter micro-copy, grouping, recent updates, how to
   submit).

### 3.5 Data research

- **Alternate names**: 4,334 of 4,663 facilities carry none;
  [alternate-names-gap-2026-09-18.csv](alternate-names-gap-2026-09-18.csv)
  lists them. Re-check with `scripts/test-state-feed-names.php`.
- **Years that disagree** between text and start/end years: New Dominion
  School of Virginia, Withlacoochee JRF, CEDU Middle School, Elevations RTC,
  Marion Youth Academy, Bartow Youth Training Center, Lexington Academy,
  Auldern Academy, Three Springs of Englishton Park, Three Springs Paint Rock
  Valley, Three Springs School of Madison, Oakley School, New Leaf Academy of
  Oregon.
- **Unplaced records**: New Directions Home for Boys (Three Springs), and
  "Aspen Education Group #2" (100001), an operator filed as a facility.

### 3.6 Small and unverified

- The admin stylesheets still use about fifty accent colours as text (16B).
- No real upload has gone through the live anonymous portal since it began
  sealing files; the offline test covers the path.
- No live call has been made to the new Claude, Gemini or Hugging Face
  models in the AI helpers (`docs/news-processor/README.md`); the pages send
  `provider: 'groq'`, so they only run when a request names them.

### 3.7 Name eras

Owner's rule, 2026-09-29. A name belongs to the years and operator it had.
Copper Canyon Academy was the Aspen-era name, 1998 to 2014, and Sedona Sky
Academy is the Family Help & Wellness name from 2014. Anything dated shows
the name in use at its date: the map's timeline, an article, a lawsuit, an
inspection, a closure. Each name is its own record and its own map name,
joined by a rename. Bethel Boys' Academy (#100180) and Eagle Point
Christian Academy (#13927) are two records, not duplicates. A facility is
listed under its current name unless the owner marks another.

What stood in the way:
- **Most renamed pairs carry the site's whole life on both names.** Of the
  map's 94 rename lines, 27 give both names the same years and 11 overlap,
  such as Copper Canyon 1998-2014 with Sedona Sky from 1998. So the timeline
  showed both at once.
- **48 lines have no years on one side.**
- **A few lines are drawn backwards**, such as KHK a Pathway Family Center
  (2006) to Kids Helping Kids (1982).
- **Past and other names are undated strings on a record.** #13927 lists
  Bethel Boys Academy, Gulf Coast Academy and Bethel Baptist Children's
  Home, so articles using those names link to the current name.

Done 2026-09-29:
- **Map Renames** (KOP Data Tools > Map Renames, `inc/network-renames.php`,
  `php scripts/test-network-renames.php`) lists every rename line with both
  names' operators and years and what looks wrong. The owner gives the
  rename year, and swaps a backwards line. A saved rename ends the earlier
  name and starts the later one on the map at once. It is layered over Map
  Years through the `kop_network_map_year_overrides` filter, and a swap
  reverses the line (`renameFlips`).
- **The news scan** (`inc/facility-discovery.php`) holds an earlier or
  later name as "Earlier or later name" instead of linking it to the
  current name's record.

Next, in order:
1. **Owner:** work through Map Renames. Every rename was researched on
   2026-09-29 (`tmp/rename-research`, built by
   `node scripts/build-rename-candidates.js` into
   `js/data/network/rename-candidates.json`). 50 of the 94 have a sourced
   year (16 high confidence; the 14 whose year is in the quoted words save
   with one button). Kids Over Profits' own reporting counts as a primary
   source, and 19 were
   found drawn backwards and start swapped. The notes also flag lines that
   are not renames: a reused campus, a road name, or two schools mixed up
   (Woodward Academy and Georgia Military School). The rest fall back to
   the board's own years.
2. **Rename years into the records.** A saved rename sets the two facility
   records' start and end years (facilities_v2, through the
   to_legacy/normalize path) and records the link as
   `identification.renamedFrom` / `renamedTo` (`{facility_id, year}`). The
   map build then draws rename lines from those links instead of guessing
   from listed names.
3. **Dated items follow the years.** An article, lawsuit, inspection or
   closure report links to the name whose years cover its date. A report
   lists items linked outside those years while another name covers them.
   A closure applies only to the name in use then: a renamed place that
   kept operating is not closed.
4. **Matching.** A name that belongs to another name's record never
   resolves through a different record's otherNames, pastNames or match
   aliases. On "Earlier or later name", Facilities from News offers
   "Create this name's record", prefilled with the rename link.
5. **Pages.** A facility page says "Earlier called Y (until 2014)" or
   "Later called X (from 2014)" with links. `identification.listAs` holds
   the owner's headline-name override.
6. **Owner pass on curated aliases that name another era**, restored on
   2026-09-29:
   - Bethel Boys Academy (#13927)
   - Laurel Ridge Treatment Center (#10602)
   - Agape Boarding School (#11361)
   - Refuge of Grace (#11462)
   - Change Academy at Lake of the Ozarks (#11355)
   - Cleo Wallace Academy (#12621)

   New curated aliases go in `api/match-aliases-seed.json` and are copied
   into the v2 documents by `api/restore-match-aliases.php`
   (`api/apply-match-aliases.php`, which wrote the frozen legacy tables,
   was deleted 2026-09-30).

---

### 3.8 Follow-through (session review, 2026-09-30)

A read of every session since 2026-09-02 for requests that were started and
not finished, checked against git, the code and the mirror. Everything else
asked for in that time shipped or is already listed above.

Each item names the model to run it with:

- **Opus 5.5** for open-ended work: a bug with no known cause, anything
  that deletes or rewrites production data, matching rules that need
  judgement, and writing in the owner's voice.
- **Sonnet 5** for a scoped change with a known cause and a test to run,
  and for web research that ends in data entry.
- **Haiku 4.5** for small mechanical edits that follow an existing pattern.

In order:

1. **Home page lawsuit and legislation previews** (Sonnet 5). Probably
   blank on the live site. `templates/page-home.php` reads `legislation`
   and `lawsuits` through `$wpdb`, but those tables are in the records
   database, so `$wpdb` finds nothing and the empty cards hide. Read them
   through `kop_seed_pdo()` (`inc/admin.php`), as the legal-document page
   fix did (c3a7c02d04f72c1cea4c22c81bbfba0cfb66e95a). Done when the
   home page HTML on the live site shows three bills and three lawsuits.
2. **Site search finds past and other names** (Sonnet 5). Since the v2
   switch, `kop_v2_search()` (`inc/facility-v2-readers.php`) matches only
   `name`, `name_key` and `unique_name`, so the header dropdown and global
   search miss a past name. The program index and the wiki picker still
   find them. Match `identification.otherNames` / `pastNames` too: a
   generated names column on `facilities_v2`, or a LIKE on `json_data`
   (4,700 rows), and show "formerly X" on the result. Done when searching
   "Copper Canyon" finds Sedona Sky Academy.
3. **Moved testimony still shows in the normal sections** (Opus 5.5).
   Reported 2026-09-30 on the Billings Clinic Psychiatric Center provider
   submission: notes moved into survivor testimony also stay in the
   original fields. No change since `js/data-form/testimony.js:194-208`.
   Reproduce in the approval editor first; the cause may be the
   submission's stored data or the notes cache rather than the move.
4. **Per-file retry in the report backup** (Haiku 4.5, Tools repo).
   `migrate_pair` in `backup_reports.py` counts a file as failed when
   OneDrive or Drive drops partway through. Use the existing
   `kop_paths.ensure_onedrive()` / `ensure_google_drive()` Retry box and
   redo that file. Three stray files are left: two in `.nc_ocr_cache`,
   `dra-media-19342.pdf` in `.ar_pdf_cache`.
5. **Guided tours for the newer admin screens** (Sonnet 5). Screens built
   after 2026-09-03 have no tutorial: Map Years, Map Renames, Woodbury
   Facts, Woodbury Reports, Closure Reports, Facilities from News and the
   Industry PR tab. Follow the existing tours in `js/tutorial-overlay.js`.
6. **Research tag proposals from document text** (Opus 5.5). Asked
   2026-09-29: read the contents of documents with no tagged facility and
   propose facilities and operators. Today's
   `api/propose-research-facility-tags.php` proposes facilities only and
   is reviewed as a JSON file. Build a one-page review screen (the owner's
   rule) and guard acronyms and short names against over-matching.
   Replaces owner item 5.
7. **Rest of the old /lawsuits/ page** (Sonnet 5). The 2026-09-03 import
   brought in ten cases. Still missing: the Dimple Dell canyon suit
   (DocumentCloud) and the cases behind two Salt Lake Tribune lawsuit
   stories on revision 926 of the old page. Add them through the lawsuit
   form path so facility links and folders follow.
8. **Renaissance Center, Fresno** (Sonnet 5). Record 100134 has no
   sources, no FileBird folder and status Unknown; the other three
   facilities submitted on 2026-09-21 got theirs. Find public sources,
   file them, and set the status. Empty duplicate folders 7765 and 7766
   under parent 1692 can go.
9. **Advocacy History and Survivors hub writing** (Opus 5.5, owner
   review). Advocacy History's "Key Milestones" list and "Submit your
   story" call to action, beside 18A above. 18D, the Survivors hub (a
   thesis line, a description under each link, icons), fell off this list
   when the handoffs were merged; it is back here. Draft in the page body
   (owner rule: never post meta, ACF or a table) and leave it for the
   owner to read.
10. **Data model phase 5** (Opus 5.5, owner go-ahead first). Not started
    (`docs/DATA-MODEL-MIGRATION.md`, "Phase 5: Remove the old model").
    The legacy tables are frozen; the dual write
    (`inc/facility-store.php`), `kop_unwrap_project_payload()`,
    `api/promote-facilities-to-rows.php` and the `_bak_20260916` tables
    remain. Ask the owner whether the bake is over before deleting
    anything, back up first, and do one step per commit.

Owner:

- **Read and publish the FAQ** (draft, 15 questions, KOP Tools > Page
  Text; `js/data/pages/faq.json`).
- **Provo Canyon School profile** (draft,
  [post 10796](https://kidsoverprofits.org/wp-admin/post.php?post=10796&action=edit)):
  the text says suspended, the record (10371) says Closed. Say which,
  then publish.
- **Document finder retries.** `.tti_doc_finder_state.json` holds 1,783
  uploads that failed before the OneDrive fix, plus 383 waiting for
  review: `py tti_doc_finder.py upload --retry-failed`, or tick "retry the
  ones that failed" in its window.
- **Industry PR refiling.** 148 rejected news items to sort through
  (4 refiled so far).
- **Midday news discovery cron.** The code and
  `docs/news-processor/README.md` support a second, `--no-facilities`
  run; confirm the line is in cPanel > Cron Jobs.
- **Scraper launcher window** (Tools 4a0df69): open it and confirm the
  size and Output pane look right.
- **Pushing local database changes to prod** (asked 2026-09-17).
  `scripts/local-db.pyw` only pulls. Recommendation: do not build a
  general push. Schema changes ship through `api/update-schema.php` and
  data changes through a seed or an admin tool, both reviewed, so the
  shared live database never takes an unreviewed bulk write.

---
