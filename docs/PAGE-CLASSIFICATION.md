# Default-Template Page Classification

Phase 0 deliverable of [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md).
Source: `tmp/prod.sqlite`, synced 2026-09-24. Classified 2026-09-25.

"Menus" means the menu assigned to the header (`Header (categories)`, the
`primary` and `mobile` locations). The `Sidebar` menu sits in the `secondary`
location, which the Kadence header builder does not place, so it renders
nowhere. "Inbound" counts links in published post and page content.

## Decisions

| Page | ID | Menus / inbound | Content | Decision | Treatment |
| --- | ---: | --- | --- | --- | --- |
| `editorials` | 969 | none / 0 | A core/query grid of category Editorials (2 posts) | convert | Hub template; the posts list is `inc/hub-posts.php`. Done 2026-09-25 |
| `investigatory-spotlight` | 1020 | Sidebar only / 0 | A core/query of category Investigatory Spotlight, 3 per page, no pagination (2 posts) | convert | Hub template, same module. Done 2026-09-25 |
| `overview` | 970 | none / 0 | Five paragraphs: what the TTI is and why it needs oversight | convert | Article template, placed first under Law & Policy. Done 2026-09-25 |
| `news` | 829 | Sidebar only / 0 | Four core/query grids over 78 WordPress news posts (Local, Independent, US, International; June 2024 to September 2025) | merge | Done 2026-09-25: the 78 posts were imported into `news_submissions` (`api/lib-news-post-import.php`, run once on deploy) and `/news/` 301s to `/tti-news-feed/` |
| `news-2` | 55 | none / 0 | Hand-written 2024 press index, already on the article template under Journalists | merge | Done 2026-09-25: its 14 entries not already in a post were imported; 301 to `/tti-news-feed/` |
| `document-archive` | 3774 | Header / 0 | Eight FileBird document-library blocks (user submissions, handbooks, academic, transporters, Sequel, Teen Challenge, government reports) | convert | Done 2026-09-25: `templates/page-document-archive.php` (featured reports, collections by type, networks, every program A to Z, recent additions; `?collection=<folder>` opens one). The old FileBird blocks are skipped at render time |
| `links` | 2562 | Sidebar ("Instagram Links") / 0 | Link-in-bio page: five link-preview cards and a volunteer note | keep | Utility page reached from Instagram. The link-preview plugin adds `http://` to the first card, so its link works; its image (attachment 3862, `2025/09/trauma_does_not_expire.jpg`) is gone from the media library and 404s, so the card shows no picture. Re-upload it in the editor. Phase 3 |
| `anon-submit` | 3615 | Header / 1 (Volunteer) | `[anonymous_doc_portal]` shortcode | keep | Utility; the portal owns its markup. Phase 3 |
| `donate` | 2251 | Header / 0 | Givebutter widget and funding copy | keep | Utility. Phase 3 |
| `contact` | 266 | Header / 0 | One paragraph with the contact email | keep | Utility. Phase 3 |
| `no-access` | 1813 | none / 0 | `[dlm_no_access]` | keep | Download Monitor's access-denied page; the plugin setting points here. Never trash. Phase 3 |
| `richardson-v-elevations-rtc-prelitigation-panel-opinion` | 317 | none / lawsuit 10 `document_urls` | Four images of the panel opinion | keep | Legal-document template candidate. Phase 3 |
| `edcons` | 6003 | none / 0 | Empty JS container | redirect | 301 to `/referrers-educational-consultants/` is live. Trashed 2026-09-25 |
| `international` | 775 | none / 0 | Hand-typed international facility list | redirect | 301 to `/location-index/?type=country` is live. Kept published: a hand-typed list, and the trash empties itself after 30 days |
| `a-survivors-guide-to-legal-action-against-troubled-teen-industry-programs` | 438 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `overt-and-covert-conversion-therapy-practices-in-therapeutic-boarding-schools` | 422 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `doe-v-hyde-complaint` | 886 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `doe-v-hyde-complaint-amended` | 890 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `richardson-complaint` | 209 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `shiver-v-southstone-complaint` | 444 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `shiver-v-southstone-summons` | 448 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `shiver-v-southstone-motion-for-default-judgement` | 452 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `trinity-teen-solutions-trinity-cross-ranch` | 400 | none / 0 | One file block | redirect | 301 to the PDF is live. Trashed 2026-09-25 |
| `trinity-teen-trinity-cross-complaint` | 393 | none / 0 | One file block; the PDF is missing from uploads | redirect | 301 to `/lawsuits/` until the PDF is re-uploaded. Trashed 2026-09-25 |

## Drafts

| Draft | ID | Decision |
| --- | ---: | --- |
| TTI & Race | 335 | Unpublished writing; the owner's call |
| TTI History Part 2 | 383 | Unpublished writing; the owner's call |
| Themes of Harm | 2632 | Unpublished writing; the owner's call |
| about-the-tti | 1031 | Empty (0 characters). Trash-review |
