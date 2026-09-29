# Page Template Design Plan

Baseline: 2026-09-24

This plan covers the published pages that still use WordPress's default page
template, plus the broader group of published posts that do not have a
specialized template. The source inventory and repository relationship are in
[REPOSITORY-MAP.md](REPOSITORY-MAP.md).

## Status

- 2026-09-25: Phase 0 done: [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md)
  gives every default-template page a decision. First slice shipped:
  `editorials` and `investigatory-spotlight` are hubs whose posts list is
  `inc/hub-posts.php` (test: `php scripts/test-hub-posts.php`); `overview` is
  an article under Law & Policy; `page-hub.php` takes a standfirst from the
  `kop_hub_standfirst` filter when a page has no excerpt (no hub page has one).
  `news` is not a hub and cannot redirect yet: its 78 posts are not in the
  news feed.
- 2026-09-25, later: Phase 2 done. The 78 news posts and the 14 entries of the
  2024 index that no post covered are in the news feed
  (`api/lib-news-post-import.php`, test `php scripts/test-news-post-import.php`);
  `/news/` and `/news-2/` 301 to `/tti-news-feed/`. `/document-archive/` is
  `templates/page-document-archive.php` (`inc/document-archive.php`). The ten
  single-file court document pages and `edcons` are trashed behind their
  redirects.
- 2026-09-25, evening: Phase 1 mostly done. `templates/page-hub.php` builds
  every hub from `kop_hub_config()` (`inc/hub-shell.php`); all hubs have
  settings; test `php scripts/test-hub-pages.php`.
- 2026-09-25, late: Phase 3 done. `templates/page-utility.php`
  (`inc/utility-pages.php`) and `templates/page-legal-document.php`;
  `/support/` 301s to `/donate/`. Phase 4 has its inventory script
  (`scripts/inventory-posts.py`) and nothing else yet.
- What is left (hub visual QA, Phase 4, and the owner's decisions) is
  tracked in [PLAN.md](PLAN.md) section 3.2.
- 2026-09-29: "In this article" and "In this section" name boxes for the
  history pages specified at the end of this document (PLAN.md 3.2 item 6).

## Goal

Every intentional public page should have a deliberate PHP rendering path,
solid-panel styling, responsive behavior, accessible navigation, and an
explicit owner for its page-specific assets. Pages that are obsolete should
redirect or be moved to Trash instead of receiving a new template merely to
make the inventory look complete.

## Design Principles

1. Use a small number of strong template families instead of one bespoke
   template per URL.
2. Preserve editorial content from the WordPress editor unless the page is a
   confirmed retired shell or duplicate.
3. Keep data-driven modules in `inc/` and keep presentation in templates and
   CSS. Do not make page templates perform large database transformations.
4. Load CSS and JavaScript through `inc/enqueue.php`, with `filemtime()`
   versions and explicit dependencies.
5. Every page must have a useful no-JavaScript state. JavaScript should add
   filtering, sorting, previews, and live data rather than supply the only
   meaningful content.
6. Hubs must help a reader choose a path. They should not become a wall of
   equally weighted cards or a duplicate of every child page.

## Template Families

| Family | Use for | Existing reference |
| --- | --- | --- |
| Editorial hub | Navigation into a subject area, with curated introduction and selected live modules | `templates/page-hub.php`, `css/hub.css` |
| Long-form article | Timelines, research essays, case analyses, and reading-focused pages | `templates/page-article.php` |
| Directory/index | Searchable collections of facilities, locations, referrers, transporters, reports, or news | Existing index and report templates |
| Document library | Research documents, legal filings, PDFs, and FileBird folder content | `templates/page-document-folder.php`, research-library module |
| Legal record/document | A lawsuit, complaint, motion, summons, or legislation record with source links and metadata | `templates/page-lawsuits.php`, `templates/page-legislation.php` |
| Utility/service | Donate, contact, access-denied, submission, or plugin-powered pages | New small templates where needed |
| Generated profile | Facility and operator records assembled from the database | `templates/facility-page.php`, `templates/operator-page.php` |

## Special Attention: Hub Pages

### Shared hub contract

The existing [page-hub.php](../templates/page-hub.php) is the right
foundation for editorial hubs. Keep one shared shell and make the content
module explicit by slug or a registered module callback.

Every hub should provide:

- A breadcrumb from Home to the current section.
- A clear H1 and a short standfirst explaining what belongs in the section.
- Curated editor content before live data modules.
- A small number of prioritized next steps, not an undifferentiated link dump.
- A visible route to the relevant directory, feed, document library, or
  submission form.
- A modified date and an editor-only edit affordance.
- A compact mobile layout that keeps headings, links, and counts readable.
- A meaningful empty state when a live module has no data.
- Structured heading levels and visible focus states.

### Hub composition

Each hub should be assembled in this order:

1. Orientation: breadcrumb, H1, standfirst, and optional image.
2. Editorial frame: the page's own introduction and context.
3. Primary action: the most useful next destination for the reader.
4. Live module: only the data most relevant to that hub.
5. Further reading: a restrained set of related articles, documents, or
   directories.
6. Footer metadata: updated date and edit link for authorized users.

### Existing hubs to preserve and improve

These already use `page-hub.php` and should be treated as one visual family:

- History
- Survivors
- Families
- Support
- Advocates
- Journalists
- Law & Policy
- Research & Reports
- Resources
- Where Are the Kids?
- Volunteer

The first hub-focused implementation should improve the shared shell and
module registration rather than creating separate templates for each of these
pages. Existing specialized modules already prove the pattern:

- Law & Policy: current counts plus newest lawsuits and legislation.
- Research & Reports: searchable/reorderable document cards.
- Resources: grouped help and support links.
- Where Are the Kids?: state and country navigation.

### Hub candidates among default-template pages

| Page | Proposed treatment | Priority |
| --- | --- | --- |
| `editorials` | Assign the editorial hub template after reviewing its current links and introduction | High |
| `investigatory-spotlight` | Assign the editorial hub template if it is a landing page; otherwise convert to a long-form article | High |
| `overview` | Assign the editorial hub template if it is the site's orientation page; otherwise merge its useful content into Home or a relevant hub | High |
| `links` | Do not blindly use the hub template. First classify links into Resources, Research & Reports, directories, and external references; then route the page to a focused resource template or redirect it | Medium |
| `news` | Treat as an archive/feed problem, not an editorial hub. Compare it with `tti-news-feed` and use the existing feed template or a redirect | High |
| `document-archive` | Treat as a document-library problem. Compare it with Research & Reports and FileBird pages before choosing a canonical archive | High |
| `international` | Retired; preserve the existing redirect to the location index and review for Trash | High |
| `edcons` | Retired; preserve the existing redirect to the referrer index and review for Trash | High |

## Default Page Work Queue

### Phase 0: Verify and classify

Before assigning templates:

- Export the current production page inventory, template meta, status, slug,
  content length, featured image, and parent page.
- Record redirects, menu references, internal links, shortcode usage, and
  document attachments for every candidate.
- Mark each page as `keep`, `convert`, `merge`, `redirect`, or `trash-review`.
- Confirm whether pages with plugin content, especially Donate and Anonymous
  Document Submission, depend on shortcode or block rendering.

Deliverable: a checked-in classification table with one canonical destination
per page.

### Phase 1: Finish the hub family

- Establish the shared hub shell as the standard for editorial landing pages.
- Add reusable module helpers for related pages, featured reading, and a
  primary action where they are genuinely useful.
- Assign `editorials`, `investigatory-spotlight`, and `overview` only after
  content review.
- Keep `links` separate until its link taxonomy is understood.
- Add a hub-specific visual QA pass at mobile, tablet, and desktop widths.

Deliverable: every retained editorial hub uses the same PHP shell and a
purposeful module configuration.

### Phase 2: News and document archives

- Compare `/news/` and `/tti-news-feed/` content, menus, and inbound links.
- Choose one canonical news URL and redirect the other if it is redundant.
- Compare `/document-archive/` with Research & Reports and organization
  document-folder pages.
- Create a focused archive template only if the page has a distinct audience
  and content contract; otherwise merge it into Research & Reports.

Deliverable: no duplicate news or document landing pages competing in search.

### Phase 3: Legal and utility pages

- Review the default legal document pages and confirm every source file remains
  reachable.
- Create a reusable legal-document template for pages that contain real
  explanatory text, metadata, and source links.
- Keep Donate, Contact, Anonymous Submission, and No Access as separate utility
  experiences when their integrations require it.
- Give each retained utility page a solid content panel, clear primary action,
  and accessible error/success states.

Deliverable: legal and utility pages have explicit rendering paths without
breaking plugin integrations or document links.

### Phase 4: Posts

- Classify the 102 published posts without specialized templates into news,
  research/article, legal record, facility profile, or obsolete content.
- Use the existing article template for reading-focused research content.
- Use the facility-profile template for canonical facility pages.
- Decide whether news posts need a dedicated single-news template or whether a
  styled Kadence single-post wrapper is sufficient.
- Add archive, 404, and other special-view templates only after the singular
  page families are stable.

Deliverable: all important public post types have intentional layouts, while
obsolete records have redirects or preserved historical access.

## Styling and Asset Plan

For every retained template:

1. Add or reuse a scoped page class.
2. Use the variables from `css/colors.css`.
3. Put readable text on a solid panel rather than the global gradient.
4. Keep cards for repeated records only; do not nest page sections inside
   decorative cards.
5. Move body-level stylesheet tags into `inc/enqueue.php`.
6. Add responsive rules for narrow content, long titles, tables, and embedded
   documents.
7. Test keyboard focus, reduced motion, empty data, failed data, and no-JS
   rendering.

## Acceptance Checks

- `python scripts/check-bare-text.py`
- Browser screenshots for each template family at desktop and mobile widths.
- Verify every retained page has one H1, a visible title, and no overlapping
  controls or clipped text.
- Verify every redirect candidate returns the intended 301 before Trash review.
- Check internal links and menu destinations after each merge or redirect.
- Confirm document links, embedded shortcodes, and plugin actions still work.
- Re-run the production inventory and compare counts before and after each
  phase.

## Recommended First Slice

Start with the hub family, not the legal-document pages:

1. Classify `editorials`, `investigatory-spotlight`, `overview`, `links`, and
   `news` from their current content and inbound links.
2. Improve the shared hub shell only where the current content contract is
   insufficient.
3. Convert one representative page, preferably `overview` or `editorials`.
4. Render it through the existing offline/browser checks.
5. Apply the same family to the other confirmed hubs.

This keeps the site's navigation coherent while allowing the page content to
determine whether a candidate is truly a hub, an archive, or a page that
should disappear behind a redirect.

## In this article, In this section (specified 2026-09-29)

The owner's suggestion of 2026-09-29: the history pages should preview
what is in them, the important facilities, events and people. Scoped
against what the templates already print, and against a count of what the
pages actually name.

*What the pages have.* `templates/page-article.php` prints a standfirst
(the excerpt), a Contents box from the era headings, the period band
(`kop_article_timeline`, dated entries with ids of their own) and the
next article in the section's reading order (`kop_article_continue`). The
History hub prints its standfirst, three actions and four timeline cards.
So the events of a timeline are previewed twice already; what no page
shows is who it is about.

*What the pages name.* Matching the network map's names against the
fifteen history seeds (whole word, six letters or more): Corporatization
names 20 programs, 9 people and 17 companies on the map; Survivors and
Families Fight Back 12, 4 and 1; How the Modern TTI Took Shape 11, 3 and
6; the Wilderness Therapy Timeline 11, 2 and 3; Fundamentalist Christian
Homes 7, 6 and 1; Experimental Group Psychology 6, 7 and 7; the Juvenile
Justice Timeline 4, 1 and 2. Across the section, 59 programs, 24 people
and 27 companies. Six pages (Antiquity, Early Child Control, Medieval
Oblation, Orphanages, Developmental Disabilities, Birth of the TTI) name
almost nothing on the map: they predate the industry.

### Decisions

- **Per article: "In this article".** A box beside Contents with up to
  three short lists, Programs, People, Companies. A program links to its
  facility page when it has one (`kop_facility_page_url`), otherwise to
  the location index search the map's drawer uses; a person links to the
  network map opened on them (`/network-map/#open=<id>`); a company to its
  operator page when it has one (`kop_operator_page_url_for_name`),
  otherwise to the map. Eight per list by the map's `importance`, then
  "and N more" opening the rest in place. The box does not print below
  three names in total, so the six early pages stay as they are.
- **Found, not typed.** The names come from matching graph.json's nodes
  (name and aliases, whole word, case-insensitive, six characters or
  more) against the rendered content, in PHP, once per article per map
  build (a transient keyed on the post's modified stamp and
  `kop_network_map_cache_key()`). No editor types a list; a new build of
  the map or an edit to the page refreshes it.
- **A hand on the result.** `kop_article_features()` in
  `inc/article-parts.php`, slug => `array('pin' => [...], 'skip' => [...])`,
  the way `kop_article_parents()` holds the reading order: pinned names
  lead their list, skipped names never print (short names such as "The
  Seed" will hit ordinary prose). It starts empty and is filled from the
  first offline render.
- **On the hub: "In this section".** The same three lists across the
  fourteen pages, eight names each by importance with the counts above
  them ("59 programs, 24 people and 27 companies from these pages are on
  the network map"), printed beside the Historical map preview from
  NETWORK-MAP.md 4.4 so the picture and the names read together. Each
  name links as above; each list ends with "all N" opening the rest.
- **Events on the hub only, chosen by the owner.** Six to eight dated
  lines, one per timeline, in `kop_hub_config()` under `history` as
  `events` (date, text, slug, entry id), linking to the entry's own
  anchor, which the period band already gives every dated line. Nothing
  automatic can tell the entry a page turns on from the other hundred.
  The per-article box has no events: the period band and Contents are
  that.
- **One renderer.** `kop_article_names($post_id)` finds the names;
  `kop_article_names_box($names, $args)` prints the box for either
  place; styles in `css/article-pieces.css` under `.kop-names*`, on the
  solid panel like everything else.

### Build steps

1. `kop_article_names()`: the matcher, its cache, the links; a unit test
   in `scripts/test-article-parts.php` on a fixture paragraph (a whole-word
   miss on "Seed" inside "Seedling", an alias hit, a pinned name first, a
   skipped name absent, the three-name floor).
2. The box in `templates/page-article.php` after Contents, and the
   stylesheet; render Corporatization and Antiquity offline (box present
   and absent) and screenshots at 390, 768 and 1440 through
   `scripts/preview-utility-pages.py` or a sibling flag; fill
   `kop_article_features()` from what the first render shows.
3. The hub box in `templates/page-hub.php` when the hub's config asks for
   it (`names_from => 'section'`), counts and lists from
   `kop_article_sequence('history')`; `scripts/test-hub-pages.php` asserts
   the counts match a fresh match of the seeds.
4. The owner's events list; the hub test checks every entry id resolves
   on its page.

Step 3 can ride with the first 4.4 commit in NETWORK-MAP.md (the history
seed is rewritten there anyway); steps 1 and 2 stand alone.

### Open decisions

1. The hub events: the owner picks the six to eight lines, or a first
   draft is proposed from the entries with the most names on the map?
2. Companies with no operator page: link to the map (proposed) or omit?
3. Should the box also appear on the Law & Policy and Survivors articles
   later? The matcher is the same; only the floor decides.
