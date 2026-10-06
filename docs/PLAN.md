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
11. **Billings Clinic testimony** (2026-09-30). In the
    [admin data form](https://kidsoverprofits.org/admin-data/), refresh, open
    **MONTANA PROVIDERS** on the providers tab, and open Survivor Testimony
    (near the bottom; click its heading). Combined and moved on 2026-09-30:
    one 29-paragraph account. Left to do: delete the stray paragraph
    "Medication" (the custom philosophy, moved in by "Move all"), tick "OK
    to publish", save. The account then shows on
    [/mental-health-providers/](https://kidsoverprofits.org/mental-health-providers/)
    as "Submitted by a survivor, shared September 2026"; its source reads
    "Submitted by a survivor (submission #50)" once the page is reloaded
    (bc693647, and the follow-up that drops "Added by an admin" beside a
    survivor and keeps one-word labels out of "Move all").

12. **Export the facility Google Docs** (2026-09-30, step 1 of 3.9).
    Ask Claude for `tmp/gdocs/export.gs` (made by `python
    scripts/gdocs-select.py`; it lists Drive file ids, so it never goes in
    the repo). Signed in as ttiresearch.dani, open
    [script.google.com](https://script.google.com/), New project, paste it
    over Code.gs, Save, pick `exportAll` and press Run; allow the access it
    asks for. Press Run again while the log says "Run again". It saves 167
    Docs as HTML and 18 Sheets as one CSV per tab into My Drive > KOP Doc
    Export. "HEAL INFO NWBHS" lives on kidsoverprofitsdani and is expected
    to fail unless shared to ttiresearch.dani first. First run 2026-10-01:
    182 of 185 saved; "TTI Database - Master" and "Troubled Teen Industry
    Program Analysis - 2014" hit Google's rate limit (HTTP 429). The script
    now waits and retries; paste the regenerated `tmp/gdocs/export.gs` and
    Run once more (it skips the 182).

13. **State Lists** (2026-10-05). Facilities that seven state-published lists
    (MO license-exempt registry, KY, AK, LA, IN, KS and MS PRTF) name but our
    records do not: 168 rows, 81 with a suggested record and 13 that several fit. Ask Claude for
    `tmp/state-lists/state-lists.json` (`py -3 scripts/state-lists-check.py
    --export tmp/state-lists/state-lists.json`; it stays out of git) and put
    it on the server as `~/kop-import/state-lists/state-lists.json`: in
    cPanel File Manager make the folder `kop-import/state-lists` in the home
    directory (beside `public_html`, where `kop-import/gdocs` already is) and
    upload the file there. Then open
    [KOP Tools > State Lists](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-state-lists):
    it reads the file on opening. Per card: Link to this record (tick "Also
    add" only when the listed name should become an other name), Create
    record, Not a TTI facility (Missouri's ordinary boarding schools), or
    Later; Undo on Done. After each `--refresh` (monthly for Missouri) export
    and upload again: decisions are kept, and "Left the list" shows what
    dropped off.

### Mobile app

The app is built and its server routes are live (section 3.14). These steps
need the owner's accounts, devices or artwork.

1. **Try it on a phone** (2026-10-06, not done). Install Expo Go (the
   current version; the app is Expo SDK 57), then in
   `C:\Users\daniu\source\repos\kids-over-profits-mobile` run
   `npx expo start` and scan the QR code (`--tunnel` if the phone cannot
   reach the PC). Open a facility with incidents, staff and survivor accounts
   and say what reads wrong: those sections were empty in every record the
   tests used.
2. **An Expo account** (free) for `npx eas-cli@latest login`, then
   `eas build --profile preview --platform android` gives an APK to install
   without a store.
3. **Store accounts, only to publish**: Apple Developer Program (99 USD a
   year) and Google Play Console (25 USD once).
4. **Artwork and words**: the icon, splash and adaptive icon are still the
   Expo defaults (`assets/images/` in the app repo); a logo is needed. The
   store listing needs a short and a long description, screenshots, and an
   age rating (the pages describe abuse and deaths; the app opens with a
   notice and the Childhelp number). Privacy policy: the site's
   `/privacy-policy/` page works if it says the app asks only for the pages a
   reader opens.
5. **Site links that open the app** need the Apple Team ID and the Android
   signing SHA-256 from the first real build, then
   `/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json`
   shipped from the theme (`.cpanel.yml` copies files, it never deletes). The
   `kidsoverprofits://` links work without them.

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
18. **Survivor site archives** (2026-10-01, open work 3.10). Answered
    2026-10-01: go ahead with all of 3.10, redact all private information,
    credit SCIAD NET (its owner agreed to KOP using it).
    - Private information: KOP only links, so a document that is itself
      private (yearbooks, client, intake, medical or school records,
      personal letters of private people) is not listed at all, and a
      private person's name (a survivor, parent, minor, witness, victim; not
      staff, owners, officials or public figures) is taken out of every
      title and group KOP shows. A document whose URL carries a private name
      is not listed. The privacy list in the repo holds no names (keyed by a
      hash of the URL). This covers the New Horizons yearbooks: left out.
    - DDoSecrets' Teen Challenge release stays out: leaked residents'
      records cannot be redacted when KOP only links.
    - SCIAD NET is credited on every block it fills, linked to WWASP Survivor
      Truth's archive page.

19. **Parent company histories** (2026-10-02). 35 drafted histories sit on
    their [/operator/](https://kidsoverprofits.org/operator/) pages as drafts only
    admins see, each with "What to check before publishing" (conflicting dates,
    claims resting on KOP rows or the wiki alone). Read each against its
    sources, fix it with the pencil and set it to Published. Record questions the
    drafts raised: Kaizen Academy filed under CERTS (the Utah licence names
    Heritage Youth Services, a different company from the California operator of
    that name); PACE's homes may be adult; Vivant still listed in Alabama after the
    Senate report says it divested there; Wayne Halfway House's Florida sites
    listed open (Tampa Bay Times 2026 says it left Florida); TrueCore's lost state
    contract dated 2023 on the timeline, 2024 in the news; The Brown Schools page
    mixes in later UHS programs; Rite of Passage has two records (18, 832).

20. **Program homes** (2026-10-03). [KOP Tools > Program Homes](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-program-homes)
    suggests 139 programs whose homes or cottages are separate licensed records (Newport Academy CA 34,
    Muir Wood 18, Grafton 16, Hope Institute 15...). Confirm each (untick a record that is not one of its
    homes, rename the program), or mark it "Not one program". Groups with a warning start with a company's
    name (CERTS, Straight, Ascend) and are probably separate programs. Not done yet: the facility directory,
    the network map and search still list each home on its own.

### Unconfirmed settings

19. Which mode production's `kop_data_model` / `kop_data_model_areas`
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
   **Pennsylvania live 2026-10-01 (2019 on), older years posting:**
   `pa_scraper.py` (Tools repo), `js/inspections/states/pa.js`,
   `/pa-reports/` created on deploy. DHS Licensing Inspection Summaries per
   licensed unit (595 units: residential incl. PRTFs, transitional living,
   secure detention and care, outdoor, mobile). 2019 on, posted: 529
   units, 3,947 documents (446 citation, 846 plan-of-correction
   follow-ups, 31 licence actions incl. the 2019-20 ChildFirst
   non-renewals and Cove Prep's 2026 provisional licence, 1,615 clean, 998
   licences), 4,647 citations, 1,249 counted as violations; 11 documents
   unclassified, 18 cited ones without a parsed list (full text shown).
   Follow-ups count unless the inspection's citation document is listed
   (the state usually replaces it: 772 of 846). Scans (before mid-2019 and
   many single pages since) are OCR'd; 2009-12 tables give regulation
   numbers only. Long citation text rides in `categories.detail`, sent with
   the report text on open: the list is 3.8 MB (0.36 MB gzipped). The
   pre-2019 pass (about 6,000 mostly scanned documents) was started
   2026-10-01 00:35 and posts as it goes; if it stopped, run `python
   pa_scraper.py` again (seen reports skip, extractions are cached). Names:
   only 5 of 90 `facilities_v2` records match by name (the state lists
   buildings, the records campuses); **owner: KOP Tools > Inspection
   Links** has 30 records with suggested links, 48 same-town pairs ticked:
   check them and Save (`php scripts/test-inspection-links.php
   --file=<pa --out json>` prints the list). Then the severe finding
   extractor (plan step 11: each citation's description of violation;
   follow-ups that repeat a counted citation document skipped).
6. **Second round of state scrapers** (researched 2026-10-01; every state
   and DC has now been checked). Ten build plans in
   `docs/state-inspection-reports/plans/`, in build order: New Hampshire
   and Wyoming first (New Hampshire shows only three years; Wyoming removes
   a provider's documents when it leaves the list), then Virginia, Ohio,
   Idaho, Iowa, West Virginia, Maine, South Dakota, Maryland. `research-log.md`
   there lists what each state publishes and which publish nothing, so the
   research is not repeated. Owner items per
   state: approve the first production post; West Virginia and Maine also need their
   scope tables settled, and New Hampshire, Wyoming and Ohio need a monthly
   run.
   **New Hampshire built 2026-10-01, waiting for the owner's word to
   post:** `nh_scraper.py` (Tools repo), `js/inspections/states/nh.js`,
   `/nh-reports/` created on deploy (empty until the first post). A full
   `--no-post` run: 24 programs, 146 visits (108 complaint, 21 renewal, 5
   compliance, 5 monitoring, 4 quality assurance, 2 revision, 1 new), 141
   flagged, 240 rules not met (15 "Founded, Problem Resolved", counted as
   the state's own compliance level counts them), 2023-10-02 to
   2026-08-13. 133 statements of findings archived to the Drive folder
   `nh_pdfs/` (27 scans read by OCR) and every page kept gzipped in
   `nh_html/`; `--from-saved` rebuilds the same payload. Six visits whose
   counts and items disagree are the state's own (run log). One statement
   (The Ridge RTC, 2025-12-15) is held by the privacy check and the visit
   posts without it; **owner** to decide (children are lettered only; the
   trigger is "Child Advocacy Center"). 11 of 24 names reach a
   `facilities_v2` record; the seven Mount Prospect Academy sites and the
   Nashua Children's Home names are for KOP Tools > Inspection Links.
   Owner: approve the first post, then run it monthly from the launcher.
   **Wyoming built 2026-10-01, waiting for the owner's word to post:**
   `wy_scraper.py` (Tools repo), `js/inspections/states/wy.js`,
   `/wy-reports/` created on deploy. A full `--no-post` run: 26
   facilities (24 Family Services providers, 2 of them with no documents;
   2 health department PRTFs), 413 documents: 88 notices of
   non-compliance, 313 handwritten facility visits (shown as documents
   only, never transcribed, always neutral), 5 corrective action plans and
   recertifications, 7 federal surveys (3 with deficiencies). 69 flagged
   (66 notices where the evidence supports non-compliance, 3 surveys),
   2019-09-05 to 2026-08-12. Notices are scans read by OCR: 16 lack a
   readable allegation or finding (listed in the run log; a notice with no
   finding read shows neutral, never clean). Visit photos (JPEG) are
   wrapped as one-page PDFs so the archive sync picks them up; every
   document goes to the Drive folder `wy_pdfs/`, since the state removes a
   provider's documents when it leaves the list. One notice (Meadowlark,
   2024-04-04) is held by the privacy check, a false positive on "calling
   a youth Miley Cyrus": **owner** to decide; one (Cathedral Home,
   2024-01-29) is not shared by the state (Drive asks for a sign-in). 9
   of 26 names reach a `facilities_v2` record; Cathedral Home, VOA
   Milestone and the Fremont County group homes are near misses for KOP
   Tools > Inspection Links. Owner: approve the first post, then run it
   monthly from the launcher.
   **Idaho built 2026-10-01, waiting for the owner's word to post:**
   `id_scraper.py` (Tools repo), `js/inspections/states/id.js`,
   `/id-reports/` created on deploy. A full `--no-post` run: 40 facilities
   (39 residential, Blue Fire Wilderness Therapy), 156 documents: 117
   statements of deficiencies (all flagged; 503 deficiencies, 75 repeat)
   and 39 no-deficiency letters, 2021-11-16 to 2026-09-21. 12 facility
   folders are no longer on the state's provider list; their reports are
   kept and marked so (three share a licence number with a listed
   facility and say so). PDFs archived to the Drive folder `id_pdfs/`.
   Nothing held by the privacy check. 11 names reach 10 `facilities_v2`
   records; near misses for KOP Tools > Inspection Links: Hawks Landing,
   Hays Shelter Home, IYR Residential Center, Blue Fire. Owner: approve
   the first post.
   **Maine built 2026-10-02:** `me_scraper.py` and `me_scope.json` (Tools
   repo), `js/inspections/states/me.js`, `/me-reports/` created on deploy.
   Scope settled by the owner 2026-10-01 (16 licences of 11 operators in;
   Charlie Health, St. Andre, the Collaborative, Little Wanderers,
   Woodfords and Youth Villages out). A full `--no-post` run: 400 surveys,
   2013-02-25 to 2026-10-02 (256 desk reviews, 127 full agency surveys,
   17 waived; 46 complaint surveys), 136 flagged (accepted plan of
   correction), 69 documents since late 2024 archived to `me_pdfs/`; 3
   surveys of adult programs hidden by default. Nothing held. Only Day One
   and Summit Achievement reach a record by name; NFI North (Beacon House,
   Bridge Crossing, Dirigo Place, Oliver Place, Sidney Riverbend, Stetson
   Ranch, Summit View), Sweetser, Connections for Kids, Good Will-Hinckley,
   KidsPeace and Aroostook (Calais Children's Residential) are for KOP
   Tools > Inspection Links. Owner: run Maine from the launcher to post.
   **Ohio built 2026-10-02:** `oh_scraper.py` (Tools repo),
   `js/inspections/states/oh.js`, `/oh-reports/` created on deploy. One
   row per agency (owner's question: per-facility rows would reach 5 of our
   63 records instead of 3 and repeat each agency's reports across 228
   rows). A full `--no-post` run: 159 agencies in scope (303 residential
   facilities), 116 with reports, 296 compliance reviews (54 full, 18
   focused, 224 other), 169 flagged, 747 findings, 963 technical assistance
   items (never flagged), 2025-07-28 to 2026-09-30. Nothing held. Agency
   rows reach Christian Children's Home of Ohio, Gateway to Success and
   Wilson Children's Home; Michael's Resource & Treatment Center, Necco
   Center and The Buckeye Ranch are for KOP Tools > Inspection Links.
   Owner: run Ohio from the launcher to post, then monthly.
   **West Virginia prepared:** `wv_scraper.py` and `wv_scope.json` (Tools
   repo), with the report adapter and `/wv-reports/` page wired in. The scope
   currently has 66 included, 122 excluded and 16 unsure records; unsure
   records are not scraped. The Helvetica filter passed 55 varied report
   samples (35 State, 20 Federal; 2000-2026), with 98.1% mean OCR word recall
   and a 90.3% minimum; a real 2016 C 173 finding was verified in OCR.
   Owner: settle the 16 unsure rows, confirm Drive capacity for `wv_pdfs/`
   (about 1 GB), and approve the first post.
   **Iowa, Maryland, South Dakota and Virginia built 2026-10-03:**
   `ia_scraper.py`, `md_scraper.py`, `sd_scraper.py`, `va_scraper.py`
   (Tools repo, in the launcher and the guide), adapters
   `js/inspections/states/{ia,md,sd,va}.js`, `/ia-reports/`, `/md-reports/`,
   `/sd-reports/` and `/va-reports/` created on deploy as drafts, PDFs
   synced from `ia_pdfs/`, `md_pdfs/`, `sd_pdfs/`, `va_pdfs/`. Full
   `--no-post` runs: **Iowa** 29 PMICs with visits (46 listed), 242 survey
   visits 2018-09 to 2026-08, 78 with deficiencies (two visits the state's
   database counts as 0 are flagged from the published form, which cites 2
   and 4), nothing held, 2 PDFs the state fails to serve. **Maryland** 20
   providers, 148 inspection summaries 2019-01 to 2025-05, 114 with
   citations, 37 with citations that "may present safety risks"; 7 duplicate
   copies the state posted are skipped; **owner:** 15 summaries are held
   whole because citation comments carry youths' or staff initials ("(KR)"),
   most of Jumoke's 2022-2025 reports among them; say whether to post them
   with the initials cut out. **South Dakota** 25 providers, 118 documents
   2024-05 to 2026-09 (62 licensing studies, 14 with a section not met; 9
   corrective action plans; 47 fire and health inspections, 13 with items
   answered No, 20 scanned forms unread), nothing held. **Virginia** VDSS 19
   facilities and about 180 inspections (run 2026-10-01; on 2026-10-03 the
   state's facility pages returned empty inspection lists, so run VDSS again
   before posting); DBHDS 165 youth service licences, about 5 minutes each
   (one click per plan), a full run takes 10+ hours: the first was started
   detached 2026-10-03 into `tmp/scraper-out/va-dbhds.json` with the code from
   before that day's fixes, so run `va_scraper.py --no-post --source dbhds` once
   more when it ends (everything comes from cache) to mark "No Violation"
   plans clean. **Owner:** approve
   each state's first post (run it from the launcher), then publish its page.
   **Hawaii built 2026-10-05, waiting for the owner's word to post:**
   `hi_scraper.py` and `hi_scope.json` (Tools repo, in the launcher and the
   guide), `js/inspections/states/hi.js`, `/hi-reports/` created on deploy
   as a draft, PDFs synced from `hi_pdfs/`. Plan:
   `docs/state-inspection-reports/plans/hawaii.md`. Source: the Health
   department's inspection reports page (one static page, 2023 on); 43
   special treatment facilities and therapeutic living programs, 110
   statements. Scope is a youth allowlist, settled by the owner
   2026-10-05: 9 in (Benchmark Behavioral Health System, Bobby Benson
   Center, Hiki Mai Ka La, Na Ohana Pulama, Nova Luna, Pearl Haven, Pacific
   Quest Awapuhi, Olena and Reed's Bay), 34 out (33 adult programs, and E
   Ho'oulu Hou Elua Program, whose population is not established). A full
   `--no-post` run: 9 facilities, 21 statements 2023-02-17 to 2026-03-17,
   12 with deficiencies (14 cited, all flagged) and 9 with none; 13 read by
   OCR (scans of the signed form, columns cropped and read one by one); 4
   plans of correction handwritten and not transcribed (Benchmark 2024 and
   2025, Pearl Haven 2023 and 2025); nothing held by the privacy check, no
   not_a_report, nothing unread. The Drive folder `hi_pdfs/` lists without
   a sign-in (shared by link, like `wy_pdfs/` and `ia_pdfs/`). 4 names
   reach 2 `facilities_v2` records (the three Pacific Quest sites ->
   Pacific Quest, Pearl Haven); Benchmark, Bobby Benson, Hiki Mai Ka La, Na
   Ohana Pulama and Nova Luna have no record and no near miss.
   **Owner:** approve the first post (run Hawaii from the launcher), then
   publish `/hi-reports/`; the state adds reports a few times a year, so a
   quarterly run is enough.
   **Colorado built 2026-10-05, never run live:** `co_scraper.py` and
   `co_scope.json` (Tools repo, in the launcher and the guide),
   `js/inspections/states/co.js` (tested on a fixture only), registrations
   and the draft `/co-reports/` page spec (swept into another session's
   commit f7451626). Plan: `docs/state-inspection-reports/plans/colorado.md`.
   Source: the Health department's Tableau dashboard (last three years,
   citation text and plans of correction). Earlier that day scripted probes
   of its session commands hung the state's server, so the scraper reads
   citation text only by clicking in real Chrome, checks the page header
   names the one citation before downloading, waits 5 s between actions,
   stops on any 30 s/5xx/double failure and reads `--max-citations` (40) a
   run. Scope: 9 in (Southern Peaks, Third Way Center, Devereux Cleo Wallace,
   Cedar Springs, Peak View, Highlands, Denver Springs, Centennial Peaks,
   Tennyson Center), 9 unsure (West Pines, Sierra Vista, Johnstown Heights,
   West Springs, three Eating Recovery Center licences, HCA Aurora,
   M.I.K.I.D.), 54 out. No `--out` run exists: the server timed out at 18:27
   and answered 503 at 18:57 ET, and the owner stopped the attempt.
   Self-reported occurrences (Cedar Springs: 223 since 2023) are not read.
   **Owner:** on a healthy evening run the five steps in the plan's Status
   section (lists only, one citation watched with `--headed --shots`, one
   facility, then 40 a run), settle the 9 unsure facilities, then approve
   the first post.
   **Oregon extension planned 2026-10-05** (`docs/state-inspection-reports/plans/oregon.md`):
   `/or-reports/` has site visits for two program types and no abuse findings. The same
   library has 61 substantiated abuse cases (quarterly legislative reports, 2021-Q4 on),
   restraint and seclusion reports per program (940 PDFs to 2023, a workbook a quarter
   since) and site visits for the other types. Build into `or_scraper.py`; the abuse
   cases also give the serious-findings scan its first Oregon input. **Owner:**
   records request for complaint investigations and licensing actions (not published).

7. **Inspection rankings** (started 2026-10-05, `inc/inspection-rollup.php`,
   KOP Tools > Inspection Rankings). Built: the hourly count of every report's
   state verdicts into `inspection_report_counts`, the rollup by company,
   facility and state, and the admin screen (pending findings shown there
   only). Owner rule: public numbers are approved findings plus the states'
   own verdicts. Against the 2026-09-30 mirror only 22 companies and 876
   records reach any inspection row, because `kop_operator_facilities` links
   643 facilities to 47 companies; the company view is only as complete as
   those links. Next, in order: (a) look over the screen once prod has
   counted (about a day of hourly runs, or "Count the next reports now");
   (b) review the North Carolina queue (782 pending), since no NC finding is
   approved yet; (c) a public page, ranked within each state, with the source
   table under it; (d) a block on each facility and company page; (e)
   per-bed rates where the state gives capacity.

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
5. **Pages.** Done 2026-10-05 (`inc/facility-eras.php`,
   `php scripts/test-facility-eras.php --list`): a renamed program's page
   is cut into one section per name, earliest first ("As Copper Canyon
   Academy", "As Sedona Sky Academy"), each holding the deaths, serious
   findings, lawsuits, incidents, news and staff of its years. Where the
   other name has its own record, both pages print both names' sections and
   link each other. It reads the map's rename lines and Map Renames' years,
   so it covers only renames with a usable year (48 pages on the
   2026-09-30 mirror; 48 more records on a rename line stay whole until
   their year is saved at Map Renames, or because a name split or joined).
   Still open: records whose past names are not on the map (about 150)
   have no rename year anywhere; licensing reports and documents are not
   split by name; staff carry no dates, so one record holding two names
   keeps its staff in the ordinary section. `identification.listAs` holds
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

1. **Home page lawsuit and legislation previews** (Sonnet 5). Done
   2026-09-30 in
   [e169c90f](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/e169c90f20ae8ae77ad68e24732d089cc2d92f5f).
   `templates/page-home.php` now reads `legislation` and `lawsuits`
   through `kop_seed_pdo()`, as the legal-document page fix did
   (c3a7c02d04f72c1cea4c22c81bbfba0cfb66e95a); a null handle or a failed
   query still just hides the card, never fatals. The `inspection_reports`
   / `inspection_facilities` / `facilities_master` counts further down the
   same file stayed on `$wpdb`: they are WordPress-side tables, confirmed
   working that way elsewhere (`inc/facility-pages.php`,
   `inc/global-search.php`). Verified live at
   [kidsoverprofits.org](https://kidsoverprofits.org/): both cards render,
   three bills and three lawsuits.
2. **Site search finds past and other names** (Sonnet 5). Done 2026-09-30
   in
   [72d5e405](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/72d5e4053835a5d1021fbcf74babcbdd44cde82e).
   `kop_v2_search()` now also matches `identification.pastNames` /
   `otherNames` with a LIKE on `json_data` (~4,700 rows), run only when the
   direct name/name_key/unique_name match leaves the result limit unfilled
   and the query is 3+ characters (acronym guard); a hit carries
   `matched_name` / `matched_kind` and `kop_v2_search_alias_hint()` formats
   "Formerly X" / "Also known as X", shown by every caller: the Ajax
   Search Lite header dropdown (`inc/ajax-search-lite.php`), the
   site-wide search widget (`inc/global-search.php`), and the full search
   results page (`search.php`). A direct name match always ranks first, so
   a name belonging to its own separate record (3.7's name-era rule, e.g.
   Bethel Boys' Academy's own record vs. Eagle Point Christian Academy's
   "Bethel Boys Academy" otherName) outranks another record's alias.
   `scripts/test-search-aliases.php` checks this against `tmp/prod.sqlite`.
   Verified live: `/wp-json/kop/v1/global-search?q=Copper+Canyon` returns
   Copper Canyon Academy first, then Sedona Sky Academy marked "Formerly
   Copper Canyon Academy"; `?q=Bethel+Boys+Academy` returns Bethel Boys'
   Academy first, then Eagle Point Christian Academy marked "Also known as
   Bethel Boys Academy".
3. **Moved testimony still shows in the normal sections** (Opus 5.5).
   Done 2026-09-30 in
   [da753fae](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/da753fae737d39ab3a75ec0bf4070a53bc86804a).
   Two causes, read from the stored record (`providers_master` "MONTANA
   PROVIDERS", submission #50). (a) The six paragraphs still under "TTI
   practices used" on /mental-health-providers/ were never moved in the
   record: the moves after approval were saved while the project was named
   `MONTANA`, and the form saved that as Montana's location profile (fixed
   by the rename in 3c82628c). (b) The submission held the same account
   twice, a field note plus a copy under a key from the submitter's own
   form; once one was moved, the move list hid the other for good. It is
   now offered with "Remove copy", which deletes it without a second
   entry. `node scripts/test-testimony-move.js` (19 checks, 5 fail on the
   old code). Owner step: Admin runs item 11. Still open: the admin form's
   save (`saveProjectToCloud` in `js/data-form-modules/api.js`, and the
   state-name override in `api/save-master.php`) still files any project
   named after a state as a location profile even on the providers tab;
   a provider project typed as a bare state name would lose its saves the
   same way. Approval already names them "<STATE> PROVIDERS".
4. **Per-file retry in the report backup** (Done 2026-09-30 in
   [5f98a09f](https://github.com/carlygaejepsen/Kids-Over-Profits-Tools/commit/5f98a09fae021b528ad327201d3679433ae7dc7d)).
   `migrate_pair` in `backup_reports.py` now asks to retry when OneDrive or
   Drive drops partway through, using the existing `kop_paths.ask_retry()`
   prompt. Only cloud-provider errors (WinError 362, 404) trigger the prompt;
   other errors keep the current behaviour. No files are deleted unless the
   upload is verified. Three stray files remain in the `.nc_ocr_cache` and
   `.ar_pdf_cache` local caches; they will be picked up on the next migration
   attempt with the new retry logic.
5. **Guided tours for the public pages** (Done 2026-09-30,
   [583d17f5](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/583d17f560c24419019dd031340a4a24517bf96e)).
   Owner: public-facing tours only, no admin screens. Tours on the
   [network map](https://kidsoverprofits.org/network-map/), the
   [facility directory](https://kidsoverprofits.org/tti-program-index/) (both
   tabs), the state inspection report pages on `report-page.js` and the
   [glossary](https://kidsoverprofits.org/glossary/), loaded by
   `kop_enqueue_tour()` in `inc/enqueue.php`, each checked live. Skipped, as
   having no controls a visitor could miss: /severe-reports/, /report-abuse/,
   /open-data/, facility and operator pages; the data form's provider
   category is already a step of its tour; a site-wide search tour would put
   a second "?" button on every page. Open: CA and TX report pages get a
   tour when they move to the shared viewer (3.3.2).
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

- ~~**Read and publish the FAQ**~~ Done: the FAQ is published (15 questions,
  KOP Tools > Page Text; `js/data/pages/faq.json`), as are Young Adult
  Programs, Indian Boarding Schools and the Network Map.
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

### 3.9 Facility information from the Google Docs (2026-09-30)

The owner keeps program links and notes in Google Docs and Sheets on the
ttiresearch.dani Drive. `tmp/gdocs/inventory.json` lists all 426 Docs and
43 Sheets on G: and I:, read from DriveFS's `metadata_sqlite_db` (no
connector reads Drive in a Claude Code session). `scripts/gdocs-select.py`
picks 167 Docs and 18 Sheets by folder and title: Active/Closed Programs,
Altior, Focus Locations (Massachusetts, Israel, Ohio), Investigations,
Lawsuits, Legislation, Sequel, the staff and owner lists, the address and
website sheets, and the shared "TTI Database - Master" sheet (import it,
no credit, owner 2026-09-30). Left out by the owner: podcast transcripts
and survivor interviews. Also left out: the NATSAP directories (already
backfilled), board, IRS, FOIA templates and personal docs.

1. **Export** (owner, Admin runs item 12). HTML, not text, because a text
   export drops the address behind every linked word.
2. **Links pass** (Opus 5.5). `scripts/gdocs-extract.py` reads the HTML
   (unwrapping `google.com/url?q=`) and, for every link, keeps the linked
   text and its sentence. It assigns the facility from the folder path and
   nearest heading against `facilities_v2` names and past/other names in
   `tmp/prod.sqlite`, sorts the link (news, court, legislation, licensing,
   the program's own site, other) and drops anything
   `kop_ext_find_duplicates()` would. Program sites follow the `/go/` rule.
   Built 2026-10-01; writes `tmp/gdocs/links.json` and
   `tmp/gdocs/links-report.md`. On the first 182 files: 2,878 distinct
   links, 943 already on file (848 of them CA CCL reports, matched by
   facNum + inx against `inspection_reports.report_id`, which holds no
   URL), 1,935 new: 895 tied to a facility, 314 to a company only, 726 to
   neither (state docs, the consultant lists, Death List rows with no
   record). New by kind: 545 news, 270 social, 121 reference, 112 people,
   103 archive copies, 95 government, 88 licensing, 66 program sites,
   49 court, 16 legislation, 470 other. Matching: a record name in the
   words around the link (never inside the URL; names of only generic
   words skipped), else the heading, else an exact or close folder/title
   name; a sheet or a doc naming many programs does not hand its folder's
   program to every link; company folders ("Sequel", "UHS", "FHW") give
   the operator.
3. **Facts pass** (Opus 5.5). Staff and owners, years, past names,
   addresses, arrests and closures, read like Woodbury Facts: every fact
   carries its quote, the build drops a quote not found in the doc and
   anything the record already holds.
4. **Review screen**. Built 2026-10-01: KOP Tools > Drive Docs
   (`inc/drive-docs.php`, table `{prefix}kop_gdoc_links`, synced from
   `~/kop-import/gdocs/links.json` when the page opens). Owner decisions
   (2026-10-01): survivor/social posts and people links are not news; they
   and the licensing reports the scrapers lack go on the facility as
   resource links. So: news to the news queue, court records and bills to
   their queues (`kop_ext_insert_*`, no admin emails via the
   `kop_notify_admins_enabled` filter), the program's own site to
   `profileLinks`, everything else to the new `resourceLinks` field
   (`{url, label, kind, source}`, PHP and JS normalizers, listed by kind on
   the /facility/ page; live links except kind `other`, shown as a
   snapshot). Each row's "Goes to" can be changed; Undo takes a link off the
   record, or a queue row nobody has reviewed yet. Company-only and
   no-facility links need a record picked (news can go without one).
   `php scripts/test-drive-docs.php`. Not yet: operator pages have no
   resource links, and `resourceLinks` is not in the open data downloads.
5. **Sheets** (Sonnet 5). A column map per sheet; only cells that differ
   from the record reach the review screen.

### 3.10 More survivor site archives (2026-10-01)

Surviving Straight Inc. and WWASP Survivors are live on the facility and
operator pages since 13e8a328 (`scripts/survivor-archives.py`,
`inc/survivor-archives.php`): link only, documents KOP holds dropped by
md5 or distinctive name, records chosen by explicit RULES. Candidates
checked 2026-10-01; dead or too small: jeriwho.net (gone), cedulegacy.org
(domain lost), pfctruth.com and isaccorp.org (gone, no PDFs on Wayback),
elanabuse.nfshost.com and survivingcedu.wordpress.com (1-5 documents,
mostly HEAL copies), the Hephzibah House blog (a few scanned images).
Owner decisions in Waiting on the owner item 18.

1. **thestraights.net** (built 2026-10-01). `survivor-archives.py` has a
   generic same-host crawler (`crawl_site`: BFS from the home page plus
   `sitemap.xml`, 1 request a second, images/styles/scripts skipped, page
   path, title, nearest bold heading as section, folder from the document
   path) and the site `straights`. It has no https (the TLS handshake
   fails), so its links are http; `kop_survivor_archives_scheme()` in
   `inc/survivor-archives.php` allows http for that site only (tested).
   Real size: 388 live pages (89 dead links), 53 documents, not the 150-250
   guessed: 46 listed (Straight Inc. o21 42, KIDS Centers o14 6, KIDS of
   Bergen County f12760 2, Straight Tampa Bay f9632 1, SAFE/Substance Abuse
   - Family Education f11222 1), 4 dead, 2 private (a survivor's story and a
   list of named suicides), 1 Scientology press kit left unmapped. Also new:
   a document an earlier site (SITES order) already lists for the same
   record by md5 is not listed again.
2. **nhym-alumni.org** (built 2026-10-01). Site `nhym`: 45 documents, 31
   listed (New Horizons Youth Ministries f13339 28; Caribbean Mountain
   Academy f14115, past name Escuela Caribe, 3), 10 yearbooks left out, 3
   the site answers 403 to (never fetchable), 1 KOP already holds. KOP has
   no NHYM operator and no other NHYM facility record under that name.
   Privacy (owner decision 18), all four sites: the build drops yearbooks
   and documents that look like private records by file name, link text and
   section (client/student/medical records, progress reports on a youth,
   filled-in applications, victim statements and personal letters, survivor
   stories, named victims) and reads `js/data/survivor-archives/privacy.json`
   ({exclude, titles, groups}, keys are sha1 of the url or the group label,
   never a name; the repo is public). `python scripts/survivor-archives.py
   privacy-review` writes `tmp/survivor-archives/privacy-review.md` (every
   listed document with its hashes) for a reviewer to fill privacy.json,
   (filled for SCIAD NET in 4A; the other sites' name-by-name pass is still open). `selftest` checks
   the patterns. Automatic exclusions so far: ssi 1, wwasp 5 (the Ben
   Trane "Part 6 - Victim statements" group, which also holds two non-victim
   exhibits), straights 2, nhym 10.
3. **SCIAD NET survey** (Opus 5.5, a separate general-purpose agent, read
   only, runs alongside 1-2). The WWASP Survivor Truth archive is a public
   Zotero group (`api.zotero.org/groups/4552235`, no key needed): 104,282
   items in 11,406 collections, no attachments; a 300-item sample was
   archive.ph / Wayback page captures, forum posts, newspaper clippings
   and about one in seven Google Drive files, none of them in Unsilenced's
   archive. Page every item and the collection tree into `tmp/sciad/`
   (about 1,050 requests, resumable, honouring `Backoff`). Report in
   `tmp/sciad/survey.md`: items by type and host; how many collections are
   a program and match a `facilities_v2` name or past/other name exactly;
   the Drive files and how many KOP holds under the same name; the
   newspaper items already in `news_submissions` (URL, then title + date);
   overlap with Unsilenced; a recommendation for step 4. Nothing reaches
   the site.
4. **SCIAD NET build** (Opus 5.5, after the owner reads the survey). Likely
   two parts: Drive documents as a `sciad` site in the survivor archives
   (the collection gives the record, name dedupe only, since Drive gives
   no md5 without a key); archived articles and clippings into a review
   screen like KOP Tools > Drive Docs, through `kop_ext_find_duplicates()`,
   never published on their own. Credit per decision 18.
   - **Part A built 2026-10-01** (not committed yet). Site `sciad` in
     `scripts/survivor-archives.py` (`build_sciad()`): no fetch, the build
     reads the survey (`tmp/sciad/items.jsonl`, `programs.json`, the raw
     pages for tags and the Legal case subcollections) and
     `tmp/unsilenced/files.jsonl`. Of 76,769 distinct Drive files: 64,804
     dropped because Unsilenced has a file of the same distinctive name, 4,301
     a short name Unsilenced has in the same program's folder (as many times
     as it has it there), 8 a name in the media library, 0 a state report KOP
     holds for the same date (the survey's 625 were all Unsilenced names);
     2,554 private (1,634 photos and event media, 466 Texas CCL portal
     screenshots, 150 client/student/medical papers, 47 filled-in
     applications, 42 police, 40 juvenile/custody, 29 personal letters, 39
     survivor stories and blogs, 19 payroll and payment papers, 15 videos,
     18 by reviewed hash, the rest by title words); 1,618 on no KOP record.
     **3,484 listed on 232 facility and 12 operator pages** (WWASP o29 349,
     UHS o25 306, Devereux o8 191, Teen Challenge o22 178, Trinity Teen
     Solutions 148, Triangle Cross 128), 572 KB in
     `js/data/survivor-archives/sciad/`. Records: the program collection's
     exact match, or `SCIAD_RULES` (86 collections: WWASP HQ, Sequel Services
     HQ, Sequel and Devereux campuses KOP has no record of on the parent
     company, renamed programs, and for KOP's duplicate records the record
     that carries the name now); tags only for an unmatched collection (1
     item). Groups are SCIAD's categories (DHS Records, Public Records,
     Court records, Program Documents, News clippings ...), court records
     split by case as "Court records: case 2 (2010-2021)"; a campus's
     documents on a parent company say which campus. Court titles are made
     from the kind of filing, court, year and case or docket number only;
     a court record named for a party is never shown under that name.
     `inc/survivor-archives.php` accepts drive.google.com and
     docs.google.com for this site only (`kop_survivor_archives_hosts()`),
     and the block's heading is "From SCIAD NET, the WWASP Survivor Truth
     archive" linked to its program-archive page. Every listed title was
     read (`tmp/survivor-archives/sciad-review.tsv`, gitignored);
     `privacy.json` gained 18 exclusions, 5 neutral titles and an `include`
     list (the two WWASP Survivors exhibits the victim-statement page title
     had dropped: an admissions-criteria sheet and a redacted DHS affidavit
     attachment, both read first; wwasp now lists 144).
   - **Part B built 2026-10-01.**
     `scripts/sciad-links.py` writes `tmp/sciad/sciad-links.json` (and
     `sciad-links-report.md`) in the Drive Docs row format from the survey's
     `items.jsonl`/`programs.json`, plus group 772277's links not already in
     SCIAD NET (385 rows). 12,651 rows: 9,438 news, 2,244 reference (program
     info, wikis, research, media), 426 archived program pages, 265
     government, 167 court, 18 legislation, 89 other, 4 more. Tied by exact
     program collection only: 6,109 to a facility (680 facilities), 892 to a
     company only (14 operators), 358 to an ambiguous name (the KOP records
     listed, none tied), 5,292 to none. News goes in under its original
     address where the Wayback link gives it (archived copy kept for the
     reviewer and the queue note); archive.today short ids stay as the
     archive link with the title. Court records get neutral titles
     ("Complaint, 13 pages - SAFE", "Court opinion - Elevations RTC (case
     no. 1:2014cv00015)"); a court record whose address carries a party's
     name (120) and family or juvenile files are left out. Dropped: 77,779
     Drive documents (part A), the survey's private classes (3,180 survivor,
     185 photo, 33 police ...), 1,671 "Random" folder items not from a news
     outlet, court or government, 859 blog, forum and social posts, 708
     titles that are only a person's name, 680 by private title words
     (letters, statements, testimony, obituaries), 259 testimony videos, 252
     survivor-site pages, 1,115 already offered by the Google Docs, HEAL or
     wiki files, 2,154 survivor-archive and HEAL addresses, about 170 held in
     KOP's records, posts or news headlines; 943 news rows merged by headline
     and year, 1,750 by address. No abstract, tag, creator or Zotero user is
     written.
   - Drive Docs (`inc/drive-docs.php`, table version 2: `source`, `rhash`)
     reads `sciad-links.json` (and the wiki's file) through
     `kop_gdl_sources()`; a reload leaves unchanged rows alone and inserts
     in batches. The screen filters by source, shows 40 rows per card with
     the rest a click away (200 a page), pages cards with a windowed pager,
     and a facility card's **Add all** adds its sure matches under the
     filters, 40 per request. SCIAD NET rows put "SCIAD NET, the WWASP
     Survivor Truth archive" on the record (resourceLinks `source`) and in
     the queue note with its link and the archived copy; the facility page
     shows the credit, linked, under each group it fills
     (`kop_facility_pages_resource_link_credit()`). Google Docs, HEAL and
     wiki rows behave as before. `php scripts/test-drive-docs.php` and
     `python scripts/sciad-links.py --selftest`.
   - Main session: copy `tmp/sciad/sciad-links.json` to
     `~/kop-import/gdocs/` after the deploy; the first page load converts
     the table and loads about 12,700 rows.
5. **Review and ship** (Sonnet 5.5 running `/code-review medium` after
   steps 1-2 and again after 4). `php scripts/test-survivor-archives.php`,
   `test-facility-pages.php`, `test-operator-pages.php`,
   `python -u scripts/check-contrast.py --local` on one facility page with
   the new block, commit to main, confirm the deploy by fetching
   `js/data/survivor-archives/<site>/index.json` from the live site.

### 3.11 Unsilenced backup and titles (2026-10-02)

The facility and operator pages list 58,924 Unsilenced documents KOP has
no copy of (117.8 GB), under Unsilenced's file names, a third of which say
nothing ("0235.pdf", "DocumentInquiry-45.pdf", "Compliance History 38.pdf").
Owner decisions (2026-10-02):

- Back up Unsilenced's copies of every listed file to the owner's Google
  Drive (`I:/My Drive/Unsilenced archive backup`, I: being upgraded to
  2 TB), in Unsilenced's folder layout, each checked against their md5.
- Retitle the links from the content. No privacy pass on Unsilenced: their
  names and groups stay as they are. Titles made here never add a personal
  name: court papers by document type, court, case number and filing date;
  state reports by report type, program and date (Michigan names only
  institutions, agencies and county homes, Texas never a foster home);
  newspaper clippings by headline, paper, date and page (headlines kept).
  Anything else keeps its name.

Built (`scripts/unsilenced-backup.py`, not committed yet): `run` downloads
from `drive.usercontent.google.com` without a key (browser User-Agent, no
email anywhere, 1.75 s between files, the large-file virus-scan page
confirmed, 5xx retried, backoff on 429 or rate-limit pages), checks the md5,
keeps the file and writes title evidence (PDF Title, two pages of text, OCR
of a scan's first page or a small image) to
`tmp/unsilenced-titles/evidence.jsonl`; resumable from `state.jsonl`
(a done file is checked on disk by size); pauses while C: has under 4 GB
(Drive for desktop buffers uploads there) or the destination has under the
file + 2 GB. Opaque names go first, files over 200 MB last. `titles` writes
`tmp/unsilenced-titles/titles.json`, which `build-unsilenced-links.py` puts
in the shards as `title`; the page shows the title with Unsilenced's name
muted after it (and as the link's tooltip); `index.json` `titled` moves the
page cache key. Rules: newspapers.com clippings, federal court headers
(district and appeals), Connecticut Superior Court, state courts, Texas
compliance histories (with the span of entries), Arizona, Georgia, North
Carolina and CMS statements of deficiencies, Arkansas DHS reports and DPSQA
letters, Michigan, Minnesota and Pennsylvania licensing letters, Oregon
restraint reports, Ohio incident notices, Oklahoma monitoring, Form 990s.

Test batch (300 files into `tmp/unsilenced-titles/test-backup`): 3.3 s a
file with OCR (2.1 s the download), 300 of 300 md5 verified, 3 Google 503s
that succeeded on retry, 157 given a title (154 of the 271 opaque names).
Rebuilt the shards with those titles: 112 shards gained titles only; 7
shards came or went because `tmp/prod.sqlite` is newer than the last build.

Full run: about 2.5 days and 118 GB on I: (36.9 GB of it 62 videos at the
end). Owner, once I: has room:

1. Start it detached from Windows PowerShell in the repo (survives the
   session): `Start-Process python -ArgumentList 'scripts/unsilenced-backup.py','run' -WindowStyle Hidden`.
   Follow `tmp/unsilenced-titles/run.log`, or
   `python scripts/unsilenced-backup.py status` (counts, GB, paused or
   not). Starting it again resumes; a second copy refuses to start.
2. Keep C: above 4 GB free; the run waits, it does not fail.
3. Afterwards: `python scripts/unsilenced-backup.py titles`,
   `python scripts/build-unsilenced-links.py`,
   `php scripts/test-unsilenced-archive.php`, read
   `tmp/unsilenced-titles/titles-report.md` (samples per shape), commit
   `js/data/unsilenced/`. Titles can be rebuilt part way through.
4. `run --no-keep` reads PDFs and images for titles without keeping copies,
   if the backup has to wait for storage.

---

### 3.12 Wiki entries brought up to date from KOP (2026-10-05)

The 425 r/troubledteens program entries in `wiki_submissions` were bulk
imported from the Reddit wiki in Dec 2025 - Jan 2026 and have not been
updated since. In that time KOP has gained confirmed closures, rename
years, operator changes, news, lawsuits, deaths and approved serious
findings for many of the same programs. Nothing compares an entry with its
facility record: `scripts/reddit-wiki-live.py` compares KOP's copy only
with Reddit's. Owner decision (2026-10-05): existing entries first; stub
and missing pages (827 stubs, 724 index slugs with no page) come later, in
the same pipeline in "new entry" mode.

Facts that shape the work:

- The text of most entries is only in `original_markdown`; `json_data`
  holds the lists (staff, media, lawsuits) but almost none of the prose. An
  update edits the markdown in place. Regenerating it from the form
  (`generateWikiMarkdown`) would lose text.
- Only 46 entries are tied to a `facilities_v2` record
  (`facility_unique_name`, 58 filled, 12 of them operators); 246 match a
  record by exact name, 10 of those ambiguously.
- `kop_facility_page_data()` (`inc/facility-pages.php`) already gathers
  everything public about a record and runs offline against
  `tmp/prod.sqlite` (`scripts/test-facility-pages.php`).
- Publishing stays a manual paste: Reddit refuses scripts.

Model delegation. Every step that can be a script is a script; models only
write and check prose. Claude subagents do the one-off batch, run from a
workflow; anything that runs on the server later goes through
`kop_ai_generate_alternating()` (Groq/Gemini), never a fixed provider.

| Step | Who | Why |
|---|---|---|
| 0 Linking, 1 Gaps | Scripts, no model | Deterministic matching; free and repeatable |
| 0 Ambiguous links | Owner, in the review inbox | A wrong link puts another program's facts in an entry |
| 2a Mechanical edits | Haiku 4.5 | Adding links to In the Media / Related Media, header years and status, past tense for a closed program: fixed formats |
| 2b New prose | Sonnet 5.5 | Closure and rebrand paragraphs, lawsuit and finding summaries in the entry's own voice |
| 2c Check | Opus 5.5 | Every added claim against its source; anything about a death, an abuse allegation or a named person is written or rewritten here, not by 2b |
| 3 Screen, 5 Upkeep code | Sonnet 5.5, reviewed by `/code-review medium` | Ordinary inbox source and cron, existing patterns |
| 5 Daily check | Script; any prose on the server via Groq/Gemini | Project rule for on-site AI |

1. **Link every entry to its record** (script). Match by the Reddit page
   the entry came from (`sourceSlug` or the bulk note's file name, the
   pairing `reddit-wiki-live.py` uses), then name, past and other names
   (`kop_v2_alias_index()`) within the state from `city_state`. A single
   match is saved as `suggested`; ambiguous and unmatched ones become a
   review inbox source with candidates (one click, or
   `kop_facility_finder_field()`), saving through
   `api/link-wiki-facility.php`. Operator entries link to `kop_operators`.
   Built 2026-10-05: `inc/wiki-updates.php` + review inbox source
   `wiki-links` (tabs One clear match / Pick the record / No record found /
   Linked here / Set aside; tool "Link every clear match"; Undo restores the
   entry's old link from option `kop_wiki_link_log`). Matching: name, past,
   other and current names inside the entry's states, then every
   distinctive word of the name in the same state (town words never count);
   pages with no years or place match only an exact name or a company. On
   the 2026-10-05 mirror: 415 current entries (334 programs, 21 topic
   pages, 9 companies, 51 lists), 56 already linked, 225 clear, 25 to pick,
   41 with no record. **Owner:** open Wiki links, press "Link every clear
   match", then pick the 25.
2. **Gaps per entry** (script, `scripts/wiki-gaps.php`, offline against
   `tmp/prod.sqlite`). For each linked entry, `kop_facility_page_data()`
   against the entry's markdown, into `tmp/wiki-updates/gaps/<id>.json`:
   status and end year (confirmed closures only) against the header;
   rename years, past names and operator changes the text lacks; news,
   lawsuits and documents whose URL is not in the entry; memorial names
   not in it; approved serious findings at or above `kop_ih_min_score`.
   Public, approved data only: no pending Woodbury, Fornits or inspection
   items, no unpublished testimony, never `journalists`. A gap that
   contradicts the entry (open vs closed, different years) is marked
   `conflict`, never written over. `tmp/wiki-updates/report.md` counts
   gaps by kind and lists the entries with the most.
   Built 2026-10-05: `scripts/wiki-gaps.php`, `scripts/test-wiki-gaps.php`.
   An entry about an earlier name of its record (Integrity House RTC, now
   Havenwood Academy) gets "Later operated as", nothing about today's
   status or operator, and only items dated in its own years. Staff: leaders
   as `staff`, others only with a career elsewhere (`staff_other`).
   A Sonnet audit of 25 entries (351 gaps) found 59% right, 15% already in
   the entry in other words, 5% another program's, 21% doubtful; 23 of 25
   record matches right. Its rules are in: news must name the program or
   one of its names (else `news_mention`, kept for the owner but not
   drafted), same titles once, `newer_than_entry` marks news after the
   entry's latest year; lawsuits must name the program, KOP's own "not a
   case about" and money cases out; deaths inside the entry's years;
   findings risk High / Medium High or weight 70+; label names (LLC, dba,
   branch, "Academy at X") skipped; staff spelled one letter off or in
   "X and Y Surname" count as named, duplicates once; operators compared
   without Group/Inc/TSI; a one-year closing difference is no conflict.
   After: 268 compared, 200 with gaps, 5 conflicts; gaps 519 staff,
   377 staff_other, 147 news (+107 news_mention), 39 closure, 30 finding,
   27 lawsuit, 26 death, 25 operator, 17 name. Left for step 3's check: a
   record whose other names include another program at the same address
   (Olympus Academy lists Diamond Ranch Academy, so Diamond Ranch suits
   reach the Olympus entry). **Owner:** the closures behind Provo Canyon
   School (Closed 2026) and the closing years of Oakley School (record
   2007, wiki 2017), Old West Academy (2007 vs 2019) and Seneca Ranch
   (2009 vs 2017) are worth a look before drafting.
3. **Drafts** (subagents, per the table). Haiku makes the mechanical
   edits, Sonnet writes the new paragraphs, Opus checks the whole new
   entry against the gap file and the sources and returns a verdict per
   added line. Rules for every drafter: change nothing outside the added
   lines (the script diffs and refuses a draft that deletes or rewrites
   existing text); every added sentence cites its link (the KOP facility
   page, the article, the court record; KOP's own reporting is a primary
   source); plain stated facts, no framing the reader is told to conclude
  ; no emojis; the modmail contact line
   unchanged; a person is named only as the source names them, never from
   a pending item. Output: `tmp/wiki-updates/drafts/<id>.md` + `<id>.json`
   (each added line, its source, Opus's verdict).
4. **Pilot of 10** (run 2026-10-05: `scripts/wiki-pilot-prep.py` picks and
   writes `tmp/wiki-updates/drafts/<id>/{entry.md,gaps.json}`; a workflow ran
   Haiku, Sonnet and Opus per batch of 5 into ops-haiku.json, ops-sonnet.json
   and ops.json (every op with a verdict); `scripts/wiki-drafts.py assemble`
   applies only additions and refuses a draft that loses or changes a line or
   adds an uncited one. All 10 assembled; 66 ops; Opus opened sources and
   fixed 10 or so, dropped wrong-program items. Owner reads the pilot page;
   record fixes it raised: Clark Harman's memorial date (Feb 1 vs Feb 3,
   2024), Turn-About Ranch operator Acadia and status Transferred, Kelly
   Corey / Kelly Cole and Jeff Johnson (one name, several people), a Fornits
   link on kidsoverprofits.org, Oakley's 2007 closing year. Owner
   2026-10-05: a closed program's entry must read in the past tense, so
   step 3 has a tense pass (Sonnet) for entries whose record is Closed, that
   are about an earlier name, or whose header has a closing year:
   ops-tense.json pairs each line with its past-tense form, and the
   assembler takes a line only when the sole changes are verbs put in the
   past ("is" -> "was", "must" -> "had to", irregular verbs listed) and
   dropped "current"/"still"/"now"; quotes, testimony, titles, links and
   facts still true (pending suits, people's jobs elsewhere) stay as
   written. Pilot: 51 lines in 4 entries. Earlier plan text:) Picked for the most gaps across kinds (closure,
   rename, lawsuit, death, finding). Owner reads them on the screen in
   step 5; prompts are adjusted; then the rest (about 290 once linked) in
   batches of 25 from a background workflow, resumable by entry id.
5. **Review screen**: review inbox source `wiki-updates`. Before/after per
   entry with the added lines marked and their sources, each line editable
   or droppable, conflicts shown apart for a decision. Approve writes the
   new markdown to `wiki_submissions` (`original_markdown` for imported
   rows) and logs the old text for an exact Undo. Approved entries go to a
   **Ready for Reddit** list: Copy button and a link to the page's Reddit
   edit screen. After pasting, `reddit-wiki-live.py fetch --slugs ...` and
   `compare` turn the badge back to "same".
   Built 2026-10-06: `python scripts/wiki-drafts.py export [ids]` writes the
   passing drafts' ops (not their text) to
   `js/data/reddit-wiki/update-drafts.json`; `inc/wiki-update-drafts.php`
   applies them to the entry as it is now (PHP port of the script's apply,
   same output on every pilot draft), so an entry edited since drafting is
   still reviewable (the card says so). Source `wiki-updates`: tabs To
   review / Ready for Reddit / On Reddit / Set aside; each added line with
   Opus's note, the past tense side by side with a switch, conflicts;
   "Edit details" changes or empties any line; "Show the whole entry"
   (admin-post `kop_wiki_draft_view`) marks the changes and has Copy and
   Reddit's edit link. Approve writes the column the entry is read from,
   keeping the old text in option `kop_wiki_draft_old_<id>` for an exact
   Undo (refused if the entry was edited after). Tested by
   `scripts/test-review-inbox.php --source=wiki-updates`.
   Pilot accepted by the owner 2026-10-06, after these rules (each now in
   code): KOP's own record is a source (written without a KOP link,
   `kop_record`), names link to their r/troubledteens wiki pages, never KOP
   profiles; the entry's KOP facility page is the last Related Media item;
   the text is put back in Reddit's markdown (KOP's copies were converted
   from rendered pages: `reddit_format()`); heal-online.org links go to
   HEAL's own pre-2023 Wayback capture (the domain is now spam); a closed
   program's own description is always past tense; corrections of existing
   lines go in `ops-fix.json`.
   **Full run, cheap and in priority order (2026-10-06):**
   `scripts/wiki-update-order.py` ranks entries by what KOP adds (closure
   10, death 8, lawsuit 6, finding 5, newer news 4, name/operator 3, staff
   0.5; 194 entries); `scripts/wiki-script-drafts.py` writes every gap with
   a fixed form (staff, news links, names, operators, closures, findings);
   models only write lawsuits, deaths and news events (Sonnet, from
   `model-gaps.json` and `excerpt.md`, not the whole entry), Opus checks
   only those lines, Haiku does the past tense. Batches of 25 from
   `order.json`.
6. **Keep them current**. A daily cron reruns step 2's gap check for
   linked entries; an entry whose record gained a confirmed closure,
   approved news, a lawsuit, a death or a finding since its last update
   gets a "KOP has new information" badge in the wiki editor and a new
   inbox item.

Tests: `scripts/test-wiki-gaps.php` (linking on the real rows, gap kinds,
the no-deletion diff, approve and exact Undo on an in-memory copy) and the
existing `scripts/test-wiki-contact.php`.

---

### 3.13 Data audit against outside sources (2026-10-06)

The wiki pilot (3.12) turned up wrong KOP data: Oakley School closed in
2017, not 2007; Turn-About Ranch was never Acadia's; Clark Harman died on
Feb 3, 2024, not Feb 1 (zipped into a bivy sack, the restraint); Kelly Cole is Kelly
Corey misspelled. `scripts/data-audit.py` (offline, tmp/prod.sqlite + the map)
flags the patterns behind them: a renamed program's name ending the year its
earlier name did, an end year with Woodbury issues naming the program later,
status and years that disagree, closures confirmed from an order, operator
lines the record never names, a current operator that bought a past one (the
program may have been sold off first), two people one or two letters apart at
one program, one person id on 5+ programs, and every memorial entry.
`tmp/data-audit/{flags.json,report.md}`; research instructions in
`tmp/data-audit/INSTRUCTIONS.md`, results per flag in `tmp/data-audit/results/`.

Round 1 (2026-10-06): 134 flags researched (renames, status, operators,
people): 88 KOP wrong or partly wrong, 31 right, 16 unsure. Turned into 103
typed proposals in `js/data/data-audit/proposals.json` (field, past operator,
note, map years, company link current/past/removed, person merge, by hand),
each "from" checked against the mirror. Reviewed in the review inbox, queue
**Data audit** (`inc/data-audit.php`, `inc/review-inbox/data-audit.php`):
Approve checks every "from" first and changes nothing when the record moved
since; Undo puts back exactly what it changed. Map years go over Map Years and
Map Renames (option `kop_data_audit_map_years`). Test:
`scripts/test-review-inbox.php --source=data-audit`.

Owner decision 2026-10-06: a program running under a new company is **Open**
on its record; "Transferred" shows only on the page of the company it left
(its link in `kop_operator_facilities` is `past`; `kop_operator_program_status()`
in `inc/operator-pages.php`). The 29 records still saying Transferred are in
the queue as `transferred-<id>`.

Open:
1. **Owner:** work through the Data audit queue (30 high confidence, the
   "approve every high-confidence correction" tool skips any with a by-hand
   step). By-hand steps it lists: split Jenny Jones off person #181, two Glenn
   Bender role dates, the Kelly Cole consultant record, map line p0439
   (Wayne Halfway House -> Jacksonville Youth Academy) in
   `network-overrides.json`, several record merges (Timberline into Daytona,
   Island View into Elevations, Three Springs campuses into their successors).
2. Round 2 (done 2026-10-06): all 230 memorial entries researched (Sonnet),
   every proposed correction re-checked against its sources by Opus, which wrote
   the final change (op type `memorial`, one column of `memorial_victims`;
   init-memorial-db.php seeds only an empty table, so approved fixes stay):
   86 memorial proposals (52 high), among them Clark Harman (Feb 3, 2024;
   restraint in a bivy sack, homicide), Rylan Harris (drowned, not struck by a car), Alana
   Richardson (2023, not 2020), Kenneth Barkley and Corey Foster (dates and
   restraint findings). 91 entries checked out; 48 could not be settled and
   change nothing. The 31 programs with later Woodbury issues: 23 right, 4
   proposals. Queue total 193.
3. Turn-About Ranch's current operator (done 2026-10-06): the buyer in
   Aspen's March 7, 2014 sale was Escalante RTC, LLC, co-owned by its
   managers (Webster, Carter, Bartlett); still operating. Its proposal now
   sets current operator and status Open instead of a by-hand step. The same
   sale took Island View, the Aspen Institute for Behavioral Assessment and
   Copper Canyon, which became Family Help & Wellness partners; no source
   names Turn-About Ranch as one, so the record does not.

### 3.14 Mobile app (2026-10-06)

An iOS and Android app, [kids-over-profits-mobile](https://github.com/carlygaejepsen/kids-over-profits-mobile) (Expo SDK 57,
expo-router, TypeScript; the README there has the commands). Version one is
public and read-only: search a facility by any of its names, the news feed,
states and countries, parent companies, and a facility page with everything
the website page shows. No accounts, no analytics; it only asks
kidsoverprofits.org for the pages a reader opens.

**What the server gives it** (`inc/mobile-api.php`, live since
[5b48839e](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/5b48839ea39af308e861d724f5db4b2bbc396f27)): three public
GET routes under `kop/v1`, each a copy of named keys, never `SELECT *`, so a
new private column cannot leak: `/facility/<slug or id>`
([example](https://kidsoverprofits.org/wp-json/kop/v1/facility/falcon-ridge-ranch-ut)),
`/operator/<slug or id>` and `/operator?name=UHS`
([example](https://kidsoverprofits.org/wp-json/kop/v1/operator?name=UHS)),
and `/news` with `page`, `per_page` (50 at most), `archive=YYYY-MM`,
`story=<arc slug>` and `facility=<id>`
([example](https://kidsoverprofits.org/wp-json/kop/v1/news?per_page=5)). Each
sends an ETag and `Cache-Control: public, max-age=600`; a matching
`If-None-Match` gets a 304. The app also reads `facility-suggest` (now with
`id` and `url`), `global-search`, `state/<slug>`, `country/<slug>` and
`facilities?view=index`. Survivor testimony that is not published never
leaves the server (`kop_rest_redact_private_testimony()` still applies). A new
section on the facility or company page goes in the keep lists of
`kop_mobile_facility_payload()` / `kop_mobile_operator_payload()` or the app
never sees it. Test: `scripts/test-mobile-api.php` (`--fixture` needs no
mirror; `--dump <dir>` writes the files the app's tests read). It checks every
payload for private keys, HTML and fixture sentinel text.

**Rules in force on the website and in the app** ([68a622df](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/68a622df2bb188cca27715d4a576f8c2201997ef),
[84f93b2b](https://github.com/carlygaejepsen/kids-over-profits-mobile/commit/84f93b2b95d67843c888eade9517d46e283b0617)): a
citation is only a small "source" link, numbered when there are several. One
that only points back to us (the network map, a page of this site) is not
shown. The Woodbury Reports name, issue and page are not printed or put in a
link's preview; the link to our copy of the issue stays. Website:
`kop_facility_pages_is_own_source()`, `_woodbury_clean()`,
`_tidy_citations()`, `_cited_html()`, test `scripts/test-citation-cleanup.php`.
App: `src/lib/citations.ts`, `InlineSources` in `src/components/ui.tsx`.

**Open work, in order**

1. **Refresh the app's fixtures.** `__tests__/fixtures/` in the app were
   written before the citation clean-up and still hold the old
   "Kids Over Profits network map" sources; the tests pass because the app
   filters them. Run `php scripts/test-mobile-api.php --db=tmp/prod.sqlite
   --dump <dir>` (add `--id=` for the records below) and copy the files over.
2. **Fixtures for the sections no record in them filled**: incidents, survivor
   accounts, the Fornits block, name eras (a renamed program: Copper Canyon
   Academy / Sedona Sky Academy), program homes and "home of" (Newport
   Academy, California). The types for those are loose and the screens read
   them defensively, but nobody has seen them draw real data. Add a render
   test per section with `@testing-library/react-native` (the app has tests
   for its helpers and for the fixtures, none for screens).
3. **Citations on the app's alias lines.** The website shows a "source" after
   "Formerly ..." and after former locations (`fact_sources.formerly`,
   `fact_sources.former_locations`); the app shows the names without them.
   The at-a-glance rows take theirs by label, so a label that differs from
   its key (the server's `Past operators`, `Operated`) must be checked on a
   record that has them.
4. **A slim list of companies.** The Companies tab downloads
   `facilities?view=index`, 2.4 MB for 51 names. Add `kop/v1/operators`
   (name, slug, program count, status) to `inc/mobile-api.php` and read that.
   While there, give a company with no page slug a proper route instead of
   the `/operator/by-name?name=` stand-in the app uses now.
5. **Test on devices** (owner step 1 above), then a pass with VoiceOver and
   TalkBack, the largest font setting, and a tablet. Labels exist on every
   card, chip and link; nobody has listened to them. The site's
   `check-contrast.py` does not cover the app; the colours come from
   `src/theme/colors.ts`, which copies `css/colors.css` (keep the two in step).
6. **Deep links.** `npx uri-scheme open kidsoverprofits://facility/<slug>`
   on a device; site addresses (`/facility/<slug>/`, `/operator/<slug>/`)
   open in the app only after owner step 5.
7. **Build and publish.** `eas init`, the preview APK, then store builds
   (owner steps 2 to 4). `eas.json` and `app.json` are ready; bundle id
   `org.kidsoverprofits.app`.
8. **Code to tidy**: the facility screen is about 400 lines and wants its
   sections split into components; the news tab keeps its story and month
   filters in component state, so they reset when it unmounts.
9. **Later ideas, not started**: other outlets' coverage of the same story
   (`story_group_id` is already in each news item), paging through every
   inspection report (the app shows the newest 20 and links the rest), a
   "send to KOP" share target (the stray `expo-share-intent` packages that
   were in the theme's `package.json` until 2026-10-06 point at this), an
   offline snapshot of the index, and notifications for new articles.

**Known, not the app's to fix**: `scripts/test-facility-pages.php` has 12
failing checks on `main` (the merged Facility Profile posts: excerpt, jump
links, written sections first, old anchors); they predate this work and are
unchanged by it. The `notes` list in the app's payload still carries the
Woodbury wording and raw addresses; both the website and the app clean them
when they print.

---
