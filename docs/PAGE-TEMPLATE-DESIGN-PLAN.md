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