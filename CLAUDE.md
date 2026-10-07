# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Kids Over Profits is a WordPress child theme (Kadence parent) that powers a data management system for tracking "Troubled Teen Industry" (TTI) facilities. The theme lives at `app/public/wp-content/themes/child/` inside Flywheel Local development environment.

## Development Environment

> **DEFAULT TARGET: the live site.** Unless the user explicitly says otherwise,
> always assume work, testing, URLs, and diagnostics refer to the production
> site at `https://kidsoverprofits.org`. Only treat the task as local
> (Flywheel) when the user explicitly says "local", "Flywheel", or
> "kids-over-profits.local".

- **Production (default)**: `https://kidsoverprofits.org` — NixiHost shared hosting (Apache/LiteSpeed, PHP 8.2, MySQL)
- **Local (only when specified)**: Flywheel Local at `https://kids-over-profits.local`
- **CMS**: WordPress with Kadence parent theme
- **No build process**: Plain PHP, vanilla JavaScript (plain scripts loaded in order by `wp_enqueue_script` dependencies, not ES modules), CSS

## Key Commands

```bash
# After editing any program data files in js/data/
node scripts/aggregate-all-programs.js

# Other data pipeline scripts
node scripts/rebuild-search-index.js
node scripts/extract-reddit-wiki-links.js

# After editing the network map CSVs or network-overrides.json
node scripts/build-network-graph.js
node scripts/test-network-graph.js

# The map rebuilds itself: inc/network-rebuild.php hashes what the build reads (staff, owners, names, years, status,
# consultants) hourly and, when it moves, starts .github/workflows/build-network-map.yml (sync 5 tables over SSH,
# build, test, commit graph.json, deploy). Status and "Rebuild now" at KOP Tools > Map Rebuild.
# Needs repo secret KOP_SSH_KEY and KOP_GITHUB_DISPATCH_TOKEN in api/config.local.php or .env on the server
php scripts/test-network-rebuild.php            # fingerprint + dispatch, against tmp/prod.sqlite, never calls GitHub
# After editing anything in js/network-map/
node scripts/test-network-modules.js
# The map on facility pages (embed.js + kop_network_map_slice_from_graph, PHP must match focus.js)
node scripts/test-network-embed.js [--php=<Local php.exe>]
node scripts/test-network-timeline.js           # the timeline (store years, timeline.js), seconds
# Researched map years reviewed at KOP Tools > Map Years (inc/network-years.php); accepted ones go on the map at once
node scripts/build-years-candidates.js [tmp/years-research]   # results -> js/data/network/years-candidates.json
php scripts/test-network-years.php && python scripts/check-network-years-page.py
# Renamed programs reviewed at KOP Tools > Map Renames (inc/network-renames.php): the rename year splits the two names' years on the map at once
php scripts/test-network-renames.php

# After editing the reporting directory data in js/data/reporting/
node scripts/build-reporting-directory.js
node scripts/verify-reporting-links.js          # slow, hits every agency site
node scripts/pull-childusa-sol.js               # monthly: CHILD USA sexual-abuse deadlines, review the diff, then rebuild
php scripts/test-reporting-directory.php        # renders the page offline
# The glossary is in SQL tables (inc/glossary-store.php), edited at KOP Tools > Glossary Editor; both tests import
# js/data/glossary/glossary.md into an in-memory SQLite copy (scripts/lib-glossary-test-db.php)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-glossary.php         # renders /glossary/ offline, checks every #link
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-glossary-store.php   # import == markdown build, add/edit/delete/undo, refs, sections
# After editing the FL/NC adapters' text readers or api/lib-inspection-text-signals.php (PHP must match JS exactly)
node scripts/test-inspection-text-signals.js --php=<Local php.exe>
php scripts/test-inspections-read-lite.php     # inspections-read.php ?lite=1 / ?text= against tmp/prod.sqlite
# State hub inspection cards say what the state's own /xx-reports/ page says (badges, tone, count): one PHP reader per state in
# api/inspection-verdicts/<st>.php (api/lib-inspection-verdicts.php), used by kop_state_collect_inspection_summaries(). After
# editing an adapter in js/inspections/states/ or a reader there, run the parity test (real adapters vs PHP on every report)
node scripts/test-inspection-verdicts.js --php=<Local php.exe> [--states=PA,NC] [--ids=..]
# NC rows the old nc_scraper.py matcher misfiled (adult-only, one facility under many licences): seeds/nc-inspection-cleanup.json
php -d extension=pdo_sqlite scripts/test-nc-inspection-cleanup.php   # on an in-memory copy of tmp/prod.sqlite
php api/clean-nc-inspections.php [apply]      # on the server (ea-php82): dry run; apply backs up to ~/kop-backups/ first
# Generated /facility/ and /operator/ (parent company) pages, against tmp/prod.sqlite
# Facility Profile posts listed in kop_facility_pages_merged_profiles() (hyde) print unchanged on their /facility/ page, the post 301s there
php scripts/test-facility-pages.php
# Citations on those pages read as a small "source" link and nothing more: one that only points back to us (the network map, a page of
# this site; kop_facility_pages_is_own_source()) is not shown, and the Woodbury Reports wording ("Woodbury Reports, May 2007, p. 20") is
# not printed or put in a preview, only the link to our copy of the issue (kop_facility_pages_woodbury_clean(), _tidy_citations(), _cited_html())
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-citation-cleanup.php [--no-db] [--id=9607]   # functions, then real pages from tmp/prod.sqlite
# The mobile app (github.com/carlygaejepsen/kids-over-profits-mobile) reads kop/v1/facility/<slug>, /operator/<slug|?name=>,
# /operators (the Companies list) and /news (inc/mobile-api.php): named keys copied from the page data, so a new page section goes in the keep lists there
# The app lists document libraries itself (/facility/<slug>/documents, /operator/<slug>/documents: the folder tree with file links) and
# opens our own PDFs (sources included, #page=N kept) in its own viewer, never the website
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-api.php [--id=14182] [--fixture]   # payloads, privacy walk, news filters; --fixture = made-up DB, no mirror needed
# A renamed program's page is cut into one section per name, earliest first ("As Copper Canyon Academy", "As Sedona Sky
# Academy"; inc/facility-eras.php): names from the map's rename lines, years from Map Renames; each holds the deaths, serious
# findings, lawsuits, incidents, news and staff of its years, another name's record included (both pages print every name).
# No usable rename year = the page stays whole. A new dated list on the page goes in kop_facility_eras_kinds()
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-eras.php [--list]   # every renamed program, rendered to the temp dir
# Facility pages show approved serious findings (inspection_highlights), staff with their other industry roles
# (other records' staff lists + network map), incidents as a timeline, and news cards with pictures:
# inc/news-images.php copies each article's share image (else the outlet's logo) into uploads/kop-news-images/, hourly cron
php scripts/test-news-images.php [--live=20]      # parser fixture; --live fetches real articles (needs -d extension=curl)
php api/fetch-news-images.php apply --limit=400 --minutes=25   # on the server (ea-php82): fill the backlog now
php scripts/test-operator-pages.php               # renders every /operator/<slug>/ page to tmp/operator-pages/
# Operator hubs (inc/operator-history.php): /operator/ lists every company (major = 5+ programs, a published history or
# a large map node); each page has a written history (operator.history, draft = admins only, published with the pencil;
# drafts come from seeds/operator-histories.json, which fills only empty records), a year-by-year timeline from the map's
# "operated" years + programs' years + lawsuits + deaths, the map's people, and the documents filed under its programs
python scripts/build-operator-histories.py      # tmp/operator-histories/*.json -> seeds/operator-histories.json; bump
                                                # KOP_OPERATOR_HISTORY_SEED_VERSION to apply (replaces only drafts nobody edited)
php scripts/test-operator-history-seed.php      # fill, replace untouched drafts, keep edited/published, on a temp copy
# Homes and cottages of one program (inc/program-homes.php): each licensed home keeps its record; {prefix}kop_program_homes ties it
# to a program record (never a field in the facility document, which the form's normalizer would drop). KOP Tools > Program Homes
# suggests groups from "Program – Home" names in one state, an admin confirms each (new or existing program record), Undo deletes
# a record it made if untouched. Program pages list homes + their news/lawsuits/serious findings; homes name their program;
# company pages fold homes under it. Directory, map and search still list homes one by one (docs/PLAN.md)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-program-homes.php   # suggestions, group Newport Academy CA, pages, undo, on a temp copy
# Facility records linked to state inspection rows whose names differ (KOP Tools > Inspection Links, inc/inspection-links.php)
php scripts/test-inspection-links.php --file=<scraper --out json> [--state=PA]   # prints the suggestions, checks a linked row reaches the page
# Inspection rankings (KOP Tools > Inspection Rankings, inc/inspection-rollup.php): worst companies, facilities and states by
# approved serious findings (deaths, staff assaults, ...) and the states' own verdicts (citations, high-risk, repeat,
# substantiated complaints; kop_irl_state_measures() says which state publishes what, the rest show a dash). An hourly batch
# counts each report once into inspection_report_counts (bump kop_irl_version() after changing a rule); records and companies
# take inspection rows by the facility pages' name rule + Inspection Links. Pending findings are admin-only, never public
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-inspection-rollup.php [--list]   # rules, full count on an in-memory copy, page parity, the screen
# Plain-language summaries for hard-to-read serious findings (inc/highlight-summaries.php): kop_hs_reasons() picks approved findings with
# codes, numbered people, stitched fragments, run-on sentences or legal citations (about 10%); an hourly job asks the AI (alternating Groq/Gemini)
# for 1-3 plain sentences from the state's text only; drafts wait at KOP Tools > Review inbox > Plain summaries (edit, approve, reject, write
# again) and show above the state's wording on facility pages, home cards and /severe-reports/ only once approved. A summary belongs to the
# exact excerpt it was written from (excerpt_hash): a changed excerpt hides it and a new draft is made. Table inspection_highlight_summaries
php scripts/test-highlight-summaries.php           # which findings are picked (the owner's examples), answer checks, printing
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=highlight-summaries   # the queue with a made-up AI
php api/scan-highlight-summaries.php [--try|apply] [--limit=10] [--ids=704]   # on the server (ea-php82): dry run lists what would get a draft
# Duplicate facility records (KOP Tools > Merge Duplicates, inc/facility-merge.php + facility-merge-match.php): pairs found
# automatically (same words, one word apart, spelling, company name in front, same street address; renamed programs never),
# merged with one click: doc fields join, every table/JSON/option pointing at the dropped id moves (kop_fmerge_ref_tables(),
# kop_fmerge_json_tables()), its FileBird library moves into the kept folder, its page 301s; exact Undo from the Merged tab.
# A new table holding facility ids goes in kop_fmerge_ref_tables(); files keyed by id read kop_facility_merge_expand_ids()
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-merge.php [--list]   # real merges + undo on an in-memory copy
# Review inbox: every approval queue on the Submissions Review page (inc/review-inbox.php, js/review-inbox.js). Each queue is
# a source in inc/review-inbox/<name>.php, registered with kop_rinbox_register(), calling the queue's own apply/undo functions;
# cards edit name/details, category, tags (shared {prefix}kop_review_tags unless the queue keeps its own), "Move to" another queue,
# "Fill empty fields with AI" (news/lawsuits: kop_enrich_*_row(), else a generic filler). A new queue gets a source file there.
# Every approve/reject action carries 'help' (one sentence: what clicking does), shown on the card. inc/review-inbox-log.php:
# Recently done ({prefix}kop_review_log, Undo = the item's 'undo' action, or 'restore' for the five native types, logged from
# api/manage-submissions.php), Later = snooze / hand to an admin ({prefix}kop_review_holds, off everyone else's list), Preview
# (review-inbox/preview: framed where allowed, else a reading copy)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php [--source=closure]   # every source on a scratch copy; checks in scripts/review-inbox-tests/
# Pending items already in our records leave the waiting list: news/lawsuits/bills (inc/review-inbox/_on-file.php: same link on a kept
# record or in a facility's resourceLinks, same headline+outlet, case number, bill) go to the Submissions Review "Already on file" tab
# Any other queue gives its source an 'on_file' => fn(): [key => {label, url}] (kop_rinbox_on_file_keys()): those leave the waiting view and
# its count for an "Already on file" view. Facilities from News (article already listed on the record meant), Websites sent in (link on a
# facility or kept record), State Lists (listed name now exactly one record's in that state: own tab, Link offered first, kop_sl_on_file())
# Woodbury Facts and Fornits: what Add would find already on the record (kop_wbf_on_record(); a different value is a conflict and waits),
# filtered in their own SQL ('on_file_in_list'), cached by kop_on_file_cached() until the queue or any record changes
# Conflicts are always marked: an item's 'conflict' (another value on the record, an open record for a closure, another role, a
# data-audit 'from' that no longer holds) draws a Conflict banner and is never ticked; Add keeps a conflict waiting (kop_wbf_conflict())
# Review inbox > Conflicts (inc/review-inbox/conflicts.php) settles them in one place: a queue names its conflicts ('conflicts', which leave its
# waiting list) and settles them ('resolve': keep the record / use this value / edit; kop_wbf_doc_overwrite() writes over a value, exact Undo);
# Woodbury Facts, Fornits and Data audit (approved over a changed 'from') take part. A new queue with conflicts adds both keys
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-on-file.php [--list] [--db=...]
python scripts/test-review-inbox-ui.py           # the page in a browser against fixtures: tabs, card edits, tags, AI, actions, phone width
# Volunteer reviewers (inc/review-volunteers.php, /volunteer-review/, js/volunteer-review.js): KOP Tools > Volunteer Reviewers makes a
# personal link per name (no account; only the token's hash stored, cookie kop_vol), turns one off, picks the queues they see
# (kop_vol_never_sources() can never open). Volunteers only recommend approve/reject/not sure ({prefix}kop_volunteer_recs); items reach
# them as kop_vol_item() copies (no fields/actions/tags, emails and phones blanked). Inbox cards show 'recs', "Volunteers recommend"
# lists them; an approve/reject in the log closes them (agreement rate per volunteer), Undo reopens them
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-volunteers.php [--list]   # links, copies, recommend, resolve, holds
# "Email me when this has been reviewed" on every public form (inc/submission-followup.php, js/submission-followup.js): forms post
# notify_email, the endpoint calls kop_followup_register(); a 10-minute cron reads each item's own status and mails once the decision
# has stood 10 minutes (an Undo inside that cancels it), then blanks the address. A new public form adds the block and a kind there.
# The block also has "Also sign me up for the newsletter" (never ticked by default; also in the bug reporter): newsletter_email ->
# inc/newsletter-signup.php -> MailerLite via the plugin's API key, group = KOP_NEWSLETTER_GROUP_ID / kop_newsletter_group_id option,
# else the plugin form's groups
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-submission-followup.php
# Program wiki entries (wiki editor, /wiki-feed/): the contact is the r/troubledteens modmail (js/wiki-generation.js CONTACT_LINK ==
# api/lib-wiki-contact.php; saved rows rewritten once by inc/wiki-contact.php, bump KOP_WIKI_CONTACT_VERSION to rerun). Each entry
# is compared with its LIVE Reddit page (never markdown_output/, which is stale): fetch reads the pages in a real Chrome window
# (solve Reddit's human check when it asks; resumable), compare pairs entries by the page they were imported from, never by name
# -> js/data/reddit-wiki/live-compare.json (commit it) -> editor index badge + /wiki-feed/ note; no result = no mark
python scripts/reddit-wiki-live.py fetch [--slugs a b] [--refresh] && python scripts/reddit-wiki-live.py compare [--list]   # sync tmp/prod.sqlite first
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-contact.php [--list]   # rewrite, PHP == JS, migration on a copy, live-result states
# Wiki entries brought up to date from KOP (docs/PLAN.md 3.12, inc/wiki-updates.php): each entry (newest row per Reddit page) is
# linked to its facilities_v2 record at KOP Tools > Review inbox > Wiki links (wiki_submissions.facility_unique_name, 'suggested',
# Undo); gaps = what kop_facility_page_data() holds that the entry's markdown lacks (closure, names, operator, news, lawsuits,
# deaths, approved findings, incidents, staff); an entry about an earlier name gets only its own years, never today's status
php -d extension=pdo_sqlite -d extension=mbstring scripts/wiki-gaps.php [--id=471] [--list]   # -> tmp/wiki-updates/{links.json,gaps/<id>.json,report.md}
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-gaps.php                 # helpers, matching, gaps on real entries
# Data audit (docs/PLAN.md 3.13): flag data outside sources should check (renamed programs ending with their earlier name, status vs
# years, operators after a sale, one person under two spellings, memorials) -> tmp/data-audit/; research agents per tmp/data-audit/INSTRUCTIONS.md;
# typed proposals in js/data/data-audit/proposals.json, reviewed at Review inbox > Data audit (inc/data-audit.php: every "from" checked, exact Undo)
python scripts/data-audit.py [--kind rename_end_year ...] [--list]   # offline, against tmp/prod.sqlite
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=data-audit
# The sources the audit read that KOP does not hold yet (every proposal's sources, incl. tmp/data-audit/proposals*.json) ->
# tmp/data-audit/audit-links.json, copied to ~/kop-import/gdocs/; reviewed at Drive Docs (source "Data audit") as news/court/resource links
python scripts/data-audit-links.py
# Drafts (tmp/wiki-updates/drafts/<id>/, written by a Haiku/Sonnet/Opus workflow, assembled by scripts/wiki-drafts.py) are reviewed at
# Review inbox > Wiki updates (inc/wiki-update-drafts.php): export ships only the ops, the site applies them to the entry's text as it is
# now; Approve writes KOP's copy (exact Undo), then Ready for Reddit has the whole text with Copy and Reddit's edit link
python scripts/wiki-drafts.py assemble <ids> && python scripts/wiki-drafts.py export <ids>   # -> js/data/reddit-wiki/update-drafts.json (commit it)
# Hub pages that list a category's posts (Editorials, Investigatory Spotlight; inc/hub-posts.php)
php scripts/test-hub-posts.php
# Every hub page through templates/page-hub.php + inc/hub-shell.php (per-hub settings), against tmp/prod.sqlite
# A hub links a page about institutional abuse OUTSIDE the TTI (Indian boarding schools) only in its boxed 'outside' block
# (kop_hub_outside(), headed and tagged as not the troubled teen industry), never among its own actions or reading list
php scripts/test-hub-pages.php
# Pages whose words live in js/data/pages/<slug>.json, edited at KOP Tools > Page Text
# (inc/page-text.php + inc/page-text-editor.php; e.g. /indian-boarding-schools/ and /faq/, both published)
php scripts/test-page-text.php
# Utility and legal page templates: shortcode/share behavior, case metadata and image alt text
php scripts/test-utility-pages.php
# Live content with local utility/legal template CSS, screenshots at 390/768/1440
python scripts/preview-utility-pages.py --shots tmp/utility-preview
# Live hub frame preview at 390, 768 and 1440 px; defaults to all 12 hubs
python scripts/preview-hub-pages.py --shots tmp/hub-preview
# The legacy news posts + 2024 index going into news_submissions (against tmp/prod.sqlite)
php scripts/test-news-post-import.php
# Facility closures reported in the news (inc/closure-reports.php): hourly WP-Cron scan of saved
# articles -> KOP Tools > Closure Reports; confirming one sets the facility's status, which the
# /facility/ page and the network map (kop_network_map_status_overrides) follow at once
php scripts/test-closure-reports.php              # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-closure-reports.php --type=closure   # on the server (ea-php82): dry run; "apply" stores reports
# Bill and lawsuit statuses (inc/status-checks.php): a nightly WP-Cron run (09:00 UTC, then every 10 min until done, 30 runs max)
# checks every published bill and lawsuit that is not finished: federal bills from govinfo BILLSTATUS (no AI), state bills from the
# official page (California via CalMatters Digital Democracy), federal lawsuits from the CourtListener docket feed (found by docket
# number + CourtListener court id, never guessed; remembered per case; anonymous = 5 requests/min, so spaced 13 s; a free token in
# KOP_COURTLISTENER_TOKEN (api/config.local.php or .env) = 5,000/hour), read by the AI; an unchanged page is not sent to the AI again. Changes
# wait at Review inbox > Bill and lawsuit updates (record_status_proposals; Apply, exact Undo, a dismissed change never comes back)
# Bill corrections and new bills by hand: seeds/legislation-updates.json + seeds/legislation.json (inc/legislation-updates.php, bump 'version')
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-status-checks.php   # parsing, matching, what is a change, runs with a made-up AI
php api/check-record-statuses.php [--kind=bill --ids=19] [apply]   # on the server (ea-php82): dry run prints what it finds
# Facilities the news mentions that have no record (inc/facility-discovery.php): the hourly scan creates
# them from the article, or links a known one; KOP Tools > Facilities from News to remove or create by hand
php scripts/test-facility-discovery.php           # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-new-facilities.php --ids=502         # on the server: dry run; "apply" creates records
# Indigenous residential schools (inc/indigenous-schools.php): their own records, never TTI facilities; listed on
# /indian-boarding-schools/ with their articles, managed at KOP Data Tools > Indigenous Schools (Move here, news scan names)
php scripts/test-indigenous-schools.php          # the first move and the page, on an in-memory copy of tmp/prod.sqlite
# Young adult programs (18+, inc/young-adult-programs.php): part of the industry (same companies, often the next step after a teen
# program), but kept in their own records, not facilities_v2; never grouped with the Indigenous schools as "outside the TTI"; listed on
# /young-adult-programs/, managed at KOP Tools > Young Adult Programs, filled from Woodbury Facts' "Young adult programs (18+)" tab
php scripts/test-young-adult-programs.php       # records, facts + exact undo, every no-record Woodbury item, the page
php scripts/test-young-adult-move.php           # the first move of 18+ facility records, on an in-memory copy (sync the mirror first)
# Edit in place (inc/inline-edit.php, js/inline-edit.js): admins get a pencil on every marked element
# (kop_ie_attr('<source>:<what>')) that saves through the source's own save path; a new page or field gets a marker there
php scripts/test-inline-edit.php                  # every facility sent back unchanged is unchanged, edits land, against tmp/prod.sqlite
# A document on the wrong page: its tile's pencil (doc:<id>:h<page folder>) lists where it is filed and moves it to another
# facility or a folder found by name, takes it off the page, or deletes it (inc/doc-placement.php; move/remove have Undo)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-placement.php   # real moves + undo on TEMP copies of the folder tables
# Survivor Testimony "Move to testimony" in the admin data form (js/data-form/testimony.js): every copy of a
# moved note can be cleared ("Remove copy"), moved text leaves the fields the public pages list
node scripts/test-testimony-move.js
# Person ids (inc/people.php, KOP Tools > People): everyone on a facility staff list (administrator, notableStaff) gets a
# {prefix}kop_people id, stamped into the entry as personId by an hourly sync (same name key = same id); operators and network
# map people are linked by name, each map node keeps its own id (two board people with one name stay two until merged);
# {prefix}kop_person_roles is rebuilt from the records. The map build reads both tables (readPeople: personId on every person
# node, a merged person drawn as one node). Facility/company careers group by kop_people_group_key(). "Separate" splits one name.
# KOP Tools > Merge People (inc/people-merge.php): pairs found automatically (short first name, maiden/married name, one letter
# apart, swapped, same initial at one program, same name under two ids), one-click merge, exact Undo from the Merged tab
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-people.php [--list] [--no-build]   # sync, pairs, merge+undo, PHP/JS key parity, a map build into tmp/
# Public data form (/tti-data-submission/) opens on a guided start (js/data-form/data-wizard.js, css/data-wizard.css): find the
# record or add a new facility/company/provider/transporter/referrer, pick topics, see only those sections; saves still go
# through submitSuggestion(). Topic -> section ids in TOPICS there; ?find=<name>, ?add=<type>, ?full=1. Its text inputs need
# data-growing-text-field="true" or ui-events.js swaps them for textareas
python scripts/test-data-wizard.py               # live page + working-tree wizard, 1280 and 390 px
# Admin facility id boxes: always kop_facility_finder_field() (inc/facility-finder.php), never a bare id input
php scripts/test-facility-finder.php              # search by name/past name/id against tmp/prod.sqlite, no bare id boxes left
# Facility directory (/tti-program-index/, both tabs) loads in steps: lists from kop/v1/facilities?view=index (each facility
# cut to names/place/years/status/report counts by kop_directory_feed_slim_facility()), a company's or place's full records when
# opened (?view=detail&key[]=), file cached in uploads/kop-cache/directory/<fingerprint>/ (inc/directory-feed.php). A field the
# closed lists read goes in kop_directory_feed_slim_facility()
python scripts/test-directory-feed.py [--refresh] [--cpu 4]   # split feed draws the same page as the whole feed, timings
# Alternate names always show up in every search box and autocomplete: a facility's or company's pastNames, otherNames
# and currentName match, and the row says which, worded by kop_alias_label() ("Formerly X" / "Also known as X" / "Now
# known as X"). kop_v2_search() (header dropdown, search widget, search.php, facility-suggest, research tags) ranks from
# the cached kop_v2_alias_index() and keeps a third of the slots for alias hits; list filters use kop_v2_alias_match_ids();
# the admin finder, facility-search.php (Data Manager, news processor, wiki picker), the data forms' autocompletes
# (js/autocomplete.js alternateNameDetail()), the wizard and the map search carry the kind too. A new box does the same
php scripts/test-search-aliases.php               # against tmp/prod.sqlite
# Reader's site map (/site-map/, a route like /operator/, inc/site-map.php + templates/site-map.php + js/site-map.js): every public
# page by section (hubs with their articles from kop_article_parents(), placed by template in kop_site_map_template_sections(), admin
# tools/redirected/password pages never), posts by category, every /facility/ and /operator/ page A to Z, a "Find a page" filter.
# kop_site_map_quick_links() also fills the search popup before typing (js/global-search.js) and the 404 page (404.php: "Did you mean"
# from the address's words). A new page template gets a section there. /sitemap/ 301s here; XML sitemaps stay Yoast's
# How to use this site (/how-to-use-this-site/, a route too, inc/how-to-use.php + templates/how-to-use.php): the main destinations grouped by
# what a reader came to do (kop_how_to_use_groups(), hand-picked: add new main pages there) and how to search; linked from the footer and the site map
php scripts/test-site-map.php [--out=tmp/site-map-preview/site-map.html]   # placement, A to Z, cache, 404 helpers, PHP == JS filter folding
# Search results link to the record's own page (/facility/, /operator/; wiki entries and inspection rows to the matching
# facility page in the same state), falling back to the state hub or directory search: kop_search_v2_result_url() and
# kop_search_record_page_url() in inc/ajax-search-lite.php, used by the dropdown, the search bar and search.php
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-search-links.php
# Facility names in free-text lists (inc/facility-suggest.php): kop/v1/facility-suggest feeds the data forms'
# 'facilityref' autocomplete (ttiReferrals, knownReferrals, facilitiesReferred; reuses kop_v2_search); kop/v1/facility-links
# resolves names to /facility/<slug>/ for "Refers young people to" on /mental-health-providers/ (one batch, ambiguous never links)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-links.php   # prints the Billings list's linked/ambiguous/unmatched names
node scripts/test-facility-ref-split.js           # the forms split a pasted comma list into entries and mark names that will not link
# "Send to KOP" Chrome extension (browser-extension/send-to-kop/, loaded unpacked, not deployed) posts to
# kop/v1/extension/* (inc/source-submissions.php): articles -> news_submissions, lawsuits, legislation,
# anything else -> KOP Tools > Websites Sent In; same duplicate rules as the public forms
php -d extension=pdo_sqlite scripts/test-source-submissions.php   # against an in-memory copy of tmp/prod.sqlite
# The app's "Send" tab (inc/mobile-submit.php): public kop/v1/mobile/submit + /mobile/check, the extension's inserts for links,
# suggested_edits for facility_new/facility_correction; duplicates come back as {type, status} only (never ids/titles); 12 an hour per sender.
# Signed-in reviewers in the app use kop/v1/extension/* with an application password, like the Chrome extension
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-submit.php [--db=...]
# The same route serves the browser extension without an account (via=extension; Chrome/Edge/Firefox from one folder, Safari via
# xcrun safari-web-extension-converter; package.ps1 zips it for the stores) and the /send/ page + bookmarklet for any browser (via=web;
# inc/send-page.php, templates/send-page.php, js/send-page.js, prefill ?url=&title=&text=)
php scripts/test-send-page.php
npx web-ext lint --source-dir browser-extension/send-to-kop   # 1 expected warning: Firefox ignores background.service_worker
# Journalist extraction from news bylines (api/lib-journalists.php), against tmp/prod.sqlite
php scripts/test-journalists.php [--list]
# Facility lists that states publish instead of inspection reports (MO license-exempt registry, KY, AK, LA, IN, KS/MS PRTF) checked
# against facilities_v2 in tmp/prod.sqlite with the same name key as the facility pages; matched / no record / ambiguous (never forced),
# our open records the list lacks (only where the list covers that type), and an added/gone diff against the previous dated snapshot in
# tmp/state-lists/<st>/<date>/ (MO vanishing from the registry is news). Writes tmp/state-lists/report.md + report.json, no DB writes
py -3 scripts/state-lists-check.py [--state MO ...] [--refresh] [--full]   # --refresh refetches (1 request/s) and adds today's snapshot
py -3 scripts/state-lists-check.py --selftest     # parsers + matcher + diff on made-up fixtures
# Rows with no record (or more than one) carry candidates (record + why: part of the name, one name holds the other, spelling,
# shared words; same town only adds); the export is reviewed at KOP Tools > State Lists (inc/state-lists.php, {prefix}kop_state_list_rows):
# link (one click per candidate or the finder; the listed name becomes an other name only when ticked), create (kop_facdisc_create(),
# citing the list), not TTI, later, exact Undo on Done; "Left the list" never changes a record. Keys: list + licence, else name key + town
py -3 scripts/state-lists-check.py --export tmp/state-lists/state-lists.json   # copy to ~/kop-import/state-lists/state-lists.json on the server
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-state-lists.php   # PHP/script key parity, import, link/create/undo, re-import, left; writes a preview
# Subfolders for the largest flat FileBird folders: plan from tmp/prod.sqlite into
# seeds/media-subfolders.json (report in tmp/), applied on deploy by kop_apply_media_subfolders()
python scripts/build-media-subfolders.py
php scripts/test-media-subfolders.php
# Open data bulk downloads (/open-data/, inc/open-data.php; built daily by WP-Cron into uploads/kop-open-data/).
# Every dataset names its columns; a new public table or column is added there, never via SELECT *
php scripts/test-open-data.php [--skip-fulltext]  # builds from tmp/prod.sqlite into tmp/kop-open-data/, privacy checks
php scripts/build-open-data.php                   # on the server (ea-php82), rebuild now
# SVG icons that replace emojis (inc/icons.php: kop_icon() / kopIcon(), and the
# render filter for emojis in post content, widgets and the ACF facility-key field)
php scripts/test-icons.php
# Pictures in editor content: 640px+ ones fill the text column (.kop-img-fill from inc/content-images.php), smaller ones
# are centred, left/right-aligned ones keep their float (css/content-images.css)
php scripts/test-content-images.php
# Links read as words, never as web addresses: kop_url_label() (inc/url-labels.php, a late the_content filter) and
# kopUrlLabel() (js/url-labels.js, on every page, relabels JS-drawn lists as they appear) must give the same label;
# a new renderer that prints a link uses one of them for its text. data-kop-keep-url opts an element out
php -d extension=mbstring scripts/test-url-labels.php    # labels, PHP == JS (needs node), the content filter
# After any template or page CSS change: no text may sit on the gradient body background
python scripts/check-bare-text.py               # one page per child template, live site
# ...and no text unreadable against its own panel, incl. forced :hover/:focus (see "Kadence colour traps")
python -u scripts/check-contrast.py [--local]   # WCAG AA, text and icons; --local serves the working tree css/js on the live pages
python scripts/test-colour-contrast.py          # static: inks, fills, no accent text or white-on-accent in any stylesheet
# Woodbury Reports pages about each program (inc/woodbury-mentions.php): scan every issue in the
# media library (reads tmp/prod.sqlite), cut the pages into C:/tmp/kop-woodbury/pending/, copy that
# folder to ~/kop-import/woodbury/ on the server, review and file at KOP Tools > Woodbury Reports
python scripts/woodbury-scan.py [--no-cut]
php scripts/test-woodbury-mentions.php            # "File under" facilities and parent companies (c<id>), company search, rows
# Woodbury Reports facts (inc/woodbury-facts.php): staff and careers, incidents, openings/closings, names,
# owners, moves read from every issue into tmp/woodbury-extract/facts/ (readers follow INSTRUCTIONS.md there);
# the build checks each quote, matches records, drops what they hold -> C:/tmp/kop-woodbury/pending/facts.json,
# copied to ~/kop-import/woodbury/; owner adds them at KOP Tools > Woodbury Facts (live at once, Undo)
python scripts/woodbury-facts.py
php scripts/test-woodbury-facts.php               # applies every proposal to the real docs, validator, exact undo
# HEAL's archived site (heal-online.org, gone; Wayback copies) into tmp/heal/: staff lists and program pages are read
# like Woodbury issues (tmp/heal/INSTRUCTIONS.md -> facts/), PDFs one by one (DOCS-INSTRUCTIONS.md -> docs/).
# Fetch is resumable and slow (the Archive refuses connections past ~15 requests a minute)
python scripts/heal-archive.py fetch && python scripts/heal-archive.py text && python scripts/heal-batches.py
python scripts/woodbury-facts.py --also tmp/heal   # HEAL facts join Woodbury Facts, cited to the archived copy, never auto-added
python scripts/heal-docs.py                         # tmp/heal/heal-links.json -> ~/kop-import/gdocs/, reviewed at Drive Docs
# The r/troubledteens wiki (markdown_output/ + the wiki editor's copies in tmp/prod.sqlite) read the same way:
# text -> tmp/wiki/text/ + INSTRUCTIONS.md, batches of pages not read yet; readers write tmp/wiki/facts/
python scripts/wiki-source.py text && python scripts/wiki-source.py batches
python scripts/woodbury-facts.py --also tmp/heal --also tmp/wiki   # wiki facts cite the wiki page, never auto-added
python scripts/wiki-links.py --gdocs <server links.json>   # the pages' links -> tmp/wiki/wiki-links.json, copied to ~/kop-import/gdocs/, reviewed at Drive Docs
php scripts/test-drive-docs.php --links=tmp/wiki/wiki-links.json
# Links from the owner's Google Docs and Sheets (docs/PLAN.md 3.9): tmp/gdocs/export.gs (from scripts/gdocs-select.py)
# exports them to G:/My Drive/KOP Doc Export; the links pass ties each link to a facility and drops what is on file ->
# tmp/gdocs/links.json, copied to ~/kop-import/gdocs/; reviewed at KOP Tools > Drive Docs (inc/drive-docs.php): news,
# court records and bills to their queues (no emails), program sites to profileLinks, the rest to the record's
# resourceLinks ({url, label, kind, source}, listed on the /facility/ page under "Materials and links")
python scripts/gdocs-extract.py
php scripts/test-drive-docs.php                   # every link on its real record, validator, exact undo, the page list
# SCIAD NET (a Zotero archive, survey in tmp/sciad/survey.md) links that are not Drive documents: news
# (Wayback unwrapped), court records (neutral titles, never a party's name), program pages, media; private classes and what
# KOP holds dropped -> tmp/sciad/sciad-links.json (report sciad-links-report.md), copied to ~/kop-import/gdocs/, never
# committed; reviewed at Drive Docs (source filter, Add all per facility), every row it fills credits SCIAD NET, linked
python scripts/sciad-links.py [--selftest]       # --selftest: court titles and privacy rules on a made-up fixture
php scripts/test-drive-docs.php                   # also SCIAD NET: credit on the page, no private class, court titles, paging
# Unsilenced's archive (public Drive folders) on facility/operator pages: only documents KOP has no copy of.
# Server lists every file with its md5 (read-only; ~/kop-import/unsilenced/files.jsonl -> tmp/unsilenced/),
# then the build compares with the media library md5s and inspection scrapers -> js/data/unsilenced/
php api/list-unsilenced-files.php [probe] [--minutes=25]   # on the server (ea-php82), resumable
php api/list-unsilenced-files.php restart --check          # monthly cron: lists into ~/kop-import/unsilenced/check/, mails when Unsilenced added enough
python scripts/build-unsilenced-links.py                   # report in tmp/unsilenced/build-report.md
php scripts/test-unsilenced-archive.php                    # inc/unsilenced-archive.php, fixture + the build
# Backup + content titles for the listed files (docs/PLAN.md 3.11): md5-checked copies to I:/My Drive/Unsilenced archive backup,
# title evidence -> tmp/unsilenced-titles/; titles.json is read by the build (link shows the title, Unsilenced's name muted).
# Generated titles never name a person (court: type/case/date; reports: type/program/date; clippings: headline/paper/date)
python scripts/unsilenced-backup.py run [--limit N] [--dest DIR] [--no-keep]   # resumable, ~2-3 s/file, pauses when C: < 4 GB
python scripts/unsilenced-backup.py titles && python scripts/unsilenced-backup.py status
# The same for survivor-run sites (inc/survivor-archives.php; ssi, wwasp, straights = thestraights.net (http only), nhym =
# nhym-alumni.org): fetch hashes every document their pages link (nothing kept; straights/nhym crawl same-host pages,
# 1 request a second), build drops what the media library holds or an earlier site lists for the record, drops private
# records by pattern and by js/data/survivor-archives/privacy.json (sha1 of url keys only, never a name; the repo is
# public; "include" keeps a reviewed document a pattern would drop), and files the rest under the record RULES in the
# script -> js/data/survivor-archives/<site>/. Site sciad = SCIAD NET's Google Drive files (not fetched: the build reads
# the survey in tmp/sciad/ and tmp/unsilenced/files.jsonl; a file whose name Unsilenced has is dropped, records from the
# exact program match or SCIAD_RULES, court records titled from kind/court/year/number only and grouped by case, the
# block credits SCIAD NET linked to its archive page; every listed title -> tmp/survivor-archives/sciad-review.tsv)
python scripts/survivor-archives.py fetch [--site ssi|wwasp|straights|nhym]   # resumable, into tmp/survivor-archives/
python scripts/survivor-archives.py build                      # needs tmp/prod.sqlite (+ tmp/sciad/ for sciad); report in tmp/survivor-archives/build-report.md
python scripts/survivor-archives.py privacy-review             # every listed document + sha1 -> tmp/survivor-archives/privacy-review.md
python scripts/survivor-archives.py selftest                   # privacy patterns, SCIAD court titles, privacy.json shape
php scripts/test-survivor-archives.php
# Fornits survivor forum (inc/fornits.php): the crawl copies the treatment-abuse boards into tmp/fornits/ (never committed);
# the hourly Windows task "KOP Fornits" ties new topics to facilities and uploads them to ~/kop-import/fornits/; on the
# server an hourly read, Groq and Gemini free tiers in turn (daily caps KOP_FORNITS_GEMINI_CALLS / KOP_FORNITS_DAILY_CALLS),
# proposes staff, incidents, survivor accounts (unpublished) and leads; reviewed at KOP Tools > Fornits, with exact Undo.
# Each read thread gets a headline from its summary (20 a call) that replaces the forum title on its links, on records too unless
# a person changed the label; a lead sent to News/Lawsuits is retitled from the article by the hourly enrich (api/lib-record-enrich.php)
# "Check AI keys" there sends one request per provider through the site's own code (keys never shown)
python scripts/test-gemini.py                     # is a Gemini key usable? lists its models, names the fix
python scripts/fornits-crawl.py                   # resumable, one request per 3 s
python scripts/fornits-process.py [--no-upload] [--keep-crawling]   # --no-upload: dry run, marks nothing read
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-fornits.php   # every link + each kind on real docs, exact undo
# Scraper report PDFs on Drive -> wp-content/uploads/inspection-reports/<st>/ + index.json,
# linked as "Archived copy" on the state report pages (server CLI + nightly cron; no apply = dry run)
php api/sync-inspection-archive.php apply --limit=2000 --minutes=25
# See the working tree's map in a browser before pushing it
python scripts/preview-network-map.py --shots tmp/map-preview
# Program websites scanned for instructions to AI (llms.txt/ai.txt, the copy Exceed's plugin hides in every page: sources
# to ignore, critics framed, AI-only notes), AI crawler rules, city doorway pages and pages answering critics. Domains from
# facilities_v2 profileLinks, tmp/gdocs/links.json program sites and the reputation series; resumable, one request a second
# per site -> tmp/seo-scan/report.html (filterable), report.csv, raw/<domain>/ copies of everything quoted
python scripts/seo-scan.py [--limit N] [--domains a.com b.org] [--refresh] [--no-sitemaps] [--report]
```

### Reporting directory data

`js/data/reporting/` holds where to report an abusive therapist or program,
state by state: `national.json`, one `states/<abbr>.json` per state, and the
generated `directory.json` the /report-abuse/ page reads. Every channel needs
a source URL and a `verified_on` date or the build refuses it. `README.md` in
that folder is the field-by-field schema. Rendered server-side by
`inc/reporting-directory.php`; the state hubs embed their own state's block.

### Glossary data

The glossary lives in SQL tables (`inc/glossary-store.php`): `{prefix}kop_glossary_nodes`
(sections, and program groups under them), `_entries` (term, qualifier, definition, `anchor` = the
page's #id, kept unless the term changes), `_aliases`, `_tags` (`used`/`reported` program + note) and
`_log` (every entry change, before/after, for Undo); title and intro in the `kop_glossary_meta` option.
Edited at KOP Tools > Glossary Editor (`inc/glossary-editor.php`: entries, sections, intro, Recent
changes with Undo) and with the pencil on each entry. Every save runs `kop_glossary_finish()`
(`inc/glossary-build.php`) over the tables with the change applied: a `**cross-reference**` must name
exactly one entry or the save is refused. Rendered server-side by `inc/glossary.php`
(`templates/page-glossary.php`), cached by the `kop_glossary_rev` revision. The open data download
writes the same data as `glossary.json`. `js/data/glossary/glossary.md` is only the source of the
one-time import into empty tables (the old editor's `kop_glossary_edits` overlay applied first);
editing it changes nothing on the site.

### Network map data

`js/data/network/` holds the Miro board export (`tti_nodes.csv`, `tti_edges.csv`)
and the generated `graph.json` that the network map reads. Corrections go in
`network-overrides.json`, never in the CSVs, so the next board export does not
undo them. `staff-movement.csv` holds the reviewed staff moves the build adds
as edges; `node scripts/extract-staff-movement.js` drafts it from the profile
text into `tmp/staff-movement.draft.csv` for comparison. Each build rewrites
`tmp/network-qa.md` (gitignored) listing every row the rules had to guess at.
Facility links are resolved against
`facilities_v2` in `tmp/prod.sqlite` when that mirror is present, and fall back
to the program aggregate otherwise.

## Architecture

### Dual-Workflow Data System
1. **Admin workflow**: Direct writes to `facilities_master` table via `api/save-master.php`
2. **Public workflow**: Writes to `suggested_edits` table for approval via `api/save-suggestion.php`

### Database Tables
- `facilities_master` - Official facility records
- `suggested_edits` - Public submissions pending approval
- `locations_master` / `referrers_master` - Related data
- `providers_master` - Mental health providers outside the TTI (psychiatric wards, PHP/IOP, day schools, respite, outpatient) that use TTI practices or refer to TTI facilities; the data form's "providers" category (`js/data-form/provider-form.js`), kept out of the facility tables
- `wiki_submissions` / `news_submissions` - Content submissions
- `{prefix}kop_volunteers` / `{prefix}kop_volunteer_recs` - Volunteer reviewers (name, hash of their link's token) and their approve/reject/not sure recommendations on review inbox items, closed with the admin's decision (`inc/review-volunteers.php`)
- `{prefix}kop_people` / `{prefix}kop_person_roles` - One id per person named on a staff list (`personId` on each `facilities_v2` staff entry) and where each is named (derived, rebuilt by the hourly sync; `inc/people.php`)
- `journalists` / `journalist_articles` - Internal-only list of journalists covering the TTI, extracted from news bylines (`api/lib-journalists.php`, managed in `api/manage-journalists.php`); never exposed publicly
- `inspection_report_counts` - Each inspection report's state verdicts (citations, high-risk, repeat, complaints, substantiated) as JSON, its year and a content hash so a report stored twice counts once; filled hourly (`inc/inspection-rollup.php`)
- `facility_closure_reports` / `news_closure_scans` - Closures the hourly news scan found (one row per article and program, pending until an admin confirms) and which articles it has read (`inc/closure-reports.php`)
- `news_facility_candidates` / `news_facility_scans` - Every facility name from the news with no record and what the scan decided (created, matched, possible duplicate, provider, not a facility), one row per name, and which articles it has read (`inc/facility-discovery.php`)
- `indigenous_schools` / `indigenous_school_news` - Indian boarding, residential and mission schools, kept out of the facility tables (never on facility pages, hubs, map, search or open data), and which articles are about each (school_id 0 = the schools in general); the news scan files a school it finds as `review = 'pending'` (`inc/indigenous-schools.php`)
- `young_adult_programs` - Programs for people 18 and older, connected to the industry but kept in their own table, out of the facility tables; `facts` is a JSON list of Woodbury items, each citing its issue page (`inc/young-adult-programs.php`; `ya` on `{prefix}kop_woodbury_facts` marks a no-record program's items for its tab)
- `lawsuit_facility_links` / `lawsuit_news_links` - Which facilities a lawsuit involves and which articles cover it (synced on save; `api/lawsuit-facility-links.php`, `api/lawsuit-news-links.php`)
- `{prefix}kop_woodbury_mentions` - Woodbury Reports pages about a program (article, news item or mention) found by `scripts/woodbury-scan.py`, pending until an admin files them in the program's "Woodbury Reports Mentions" folder
- `{prefix}kop_media_folder_tags` - Extra folder memberships (one document, many folders)
- `{prefix}kop_folder_links` - Legacy/current-name folder equivalence (curated in `api/link-folders.php`)
- `{prefix}kop_glossary_nodes` / `_entries` / `_aliases` / `_tags` / `_log` - The TTI glossary: sections and groups, terms, other names, program tags, and each entry change for Undo (`inc/glossary-store.php`)
- `{prefix}kop_glossary_feedback` - Reader notes from the glossary's "My facility used this too" / "Suggest a correction" buttons (`inc/glossary-feedback.php`; reviewed under KOP Tools > Glossary Feedback)
- `{prefix}kop_addresses` / `{prefix}kop_facility_addresses` - Physical address IDs and which facility stood where (`api/manage-addresses.php`; join table rebuilt from facility data on each seed)

### Page Template → Script Loading Pattern
Templates live in `templates/`; `inc/enqueue.php` loads each page's assets conditionally:
- `templates/page-admin-data.php` → Admin form assets, mode='admin'
- `templates/page-data.php` → Public form assets, mode='suggestions'
- `templates/page-tti-program-index.php` → Facility directory, two tabs: by parent company (`js/tti-program-index.js`) and by location (`?view=location`, `js/location-index.js`; `/location-index/` redirects here)
- `templates/page-wiki-editor.php` → Wiki content editor
- `templates/page-news-processor.php` → News processing
- State report pages: `kop_enqueue_report_scripts()` matches a fixed list of slugs (`ca-reports`, `ut-reports`, ...), not a `*-reports` pattern; a new state must be added there and in `inc/rest-api.php`

### JavaScript Module Structure
```
js/data-form-modules/    ← Core modules (config.js loads first, no deps)
js/data-form/            ← Form utilities (utilities.js, data-form.v4.js orchestrator)
js/inspections/          ← State report viewers
js/data/                 ← Static JSON fallbacks
```

Module dependency chain: `config.js` → `data-normalizer.js` → `api.js` → `project.js` → UI modules

### API Configuration
Credentials loaded from `.env`, WordPress constants, or `api/config.local.php` (gitignored). The data forms read `KOP_DATA_FORM_CONFIG` (also localized as `dataFormConfig`), which carries `ajaxUrl`, `restUrl`, `nonce`, `isAdmin`, `endpoints` and `mode`. There is no `apiBase` key.

All AI work (news processing, discovery, closure/facility scans, Fornits, lawsuit extraction, wiki prose,
retitling) alternates between Groq and Gemini: `kop_ai_generate_alternating()` in `api/ai-providers.php`
(provider `'auto'` on `api/process-news-ai.php`) starts each call with the other one from the call before
and gives the prompt to the other when one fails. A new AI call goes through it, never straight to one provider.

## Code Conventions

- **Versioned filenames**: Use explicit versions when iterating (e.g., `data-form.v4.js`)
- **Procedural PHP**: API endpoints use procedural style
- **No build tooling**: Avoid introducing bundlers/transpilers
- **CSS variables**: Use `var(--kop-*)` from `css/colors.css` for styling
- **Archive what you cite from the Wayback Machine**: any time a Wayback Machine (or other web archive) copy is used to read or cite something, download it as a PDF (keep the banner with the original URL and capture date) and import it into the media library with `api/import-documents.php` (`source_url` = the archive.org link), then cite our copy beside the archive link. Never rely on archive.org alone; never commit the PDFs (the repo is public)

## Color Palette (css/colors.css)
Primary: Midnight Blue (#000435), Navy (#000080), Teal (#33A7B5)
Accents: Orange (#EF9034), Chartreuse (#B2E102), Coral Pink (#FE8088)
Backgrounds: Sand (#F2EEDF), Soft Pastel Yellow (#FFF5CB), Mint Green (#B6E3D4)

Reserve bright accents (Chartreuse, Coral Pink, Bubblegum Pink) for borders/highlights, not backgrounds.

### Kadence colour traps (why text goes invisible)
Our stylesheets load **before** `kadence-global-css` on every page, so any
specificity tie goes to Kadence. Kadence also colours these elements directly,
so they do **not** inherit a panel's `color`:

- `h1`-`h6`: #1A202C / #2D3748. A navy panel with `color:#fff` still gets black headings.
- `a`: navy #000080 (hover #000435). A link in a navy panel is navy on navy.
- `button`, `.button`, `input[type=submit]`: white text on teal, and `button:hover`,
  `button:focus` (0,1,1) beat a one-class rule (0,1,0). A button styled
  `.x { background:#fff; color:navy }` or `.x:hover { background:sand }` goes
  white on white/sand on hover, or after a click (focus sticks).
- `input`/`select`/`textarea`: grey text on a white box. A transparent field on a dark panel is grey on navy;
  `select option` popups need their own background and colour.
- Undefined `var(--kop-x)` with no fallback resolves to *inherit*, so it silently
  takes the panel colour (the old `--kop-navy` white-on-white).
- Our own `.panel * { color:#fff !important }` blankets repaint components injected
  into the panel (doc tiles, pills, bug flags) white on their white backgrounds.

Rules: a dark panel sets colours for its headings, links and buttons explicitly.
Every `<button>` rule is `button.x` and names **both** colour and background in
the rest, `:hover`, `:focus` and `:active` states (links styled as buttons too:
Kadence's `a:focus` is midnight). A reusable component sets its own foreground
and background instead of inheriting. No `*` colour blankets.

Every text passes WCAG AA (4.5:1, large text 3:1) and every icon 3:1, in every state.
- Accent as **text or icon** on a light ground: `--kop-*-ink` (teal, orange, coral-pink,
  chartreuse, bubblegum-pink). On navy/midnight keep the bright accent.
- **White text on a fill**: `--kop-*-fill` (white on teal fill 5.35), never the bright
  accent (white on teal 2.86). Dark text on a bright accent is fine (midnight on teal 6.86).
- Secondary text (dates, sources, counts): `--kop-text-muted` (#4A5568), never Kadence
  greys like #718096 / #9ca3af / #999.
- Kadence's button and grey colours are restated from the palette in `css/colors.css`
  (`html:root`); plugin output (FileBird, MailerLite, AddToAny) in `css/plugin-contrast.css`.
- Icons (`kop_icon()` / `kopIcon()`) draw in `currentColor`: colour the text, not the SVG.

Check with `python -u scripts/check-contrast.py [--local] [paths]` (every text node and
small SVG against its real background, hover and focus forced, names the rule that set
each failing colour) and `python scripts/test-colour-contrast.py` (static: tokens, no
accent text, no white on an accent in any stylesheet).

## Program Data Pipeline

Source files in `js/data/reddit-wiki/programs-XX.json` (by state) and `js/data/tti-program-links.json` (uncategorized). After any edits:
```bash
node scripts/aggregate-all-programs.js
```
This generates `programs-array.json`, `search-index.json`, and `metadata.json`.

## REST API

- `GET /wp-json/kop/v1/facilities` - Returns facility data (registered in `functions.php`)
- Falls back to static JSON in `js/data/` when API unavailable

## Deployment

- Git deployment via `.cpanel.yml` or manual upload to NixiHost/cPanel
- Export database snapshots if schema changes
- Never commit `.env` or `config.local.php`
