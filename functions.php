<?php
/**
 * Kadence Child Theme Functions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modularized Theme Functions
 * 
 * The following files contain the logic previously stored in this functions.php file.
 * This improves maintainability and organization.
 */

// Core Utilities & Helper Functions
require_once get_stylesheet_directory() . '/inc/utilities.php';

// Inline SVG icons (kop_icon() / kopIcon()), used in place of emojis
require_once get_stylesheet_directory() . '/inc/icons.php';

// Script & Style Enqueuing
require_once get_stylesheet_directory() . '/inc/enqueue.php';

// Database Connection & Data Retrieval
require_once get_stylesheet_directory() . '/inc/database.php';

// Canonical facility store: normalizer, validator, location text parser
// (pure functions; see docs/FACILITY-SCHEMA.md)
require_once get_stylesheet_directory() . '/inc/facility-store.php';
require_once get_stylesheet_directory() . '/inc/facility-v2-readers.php';
require_once get_stylesheet_directory() . '/inc/facility-v2-sync.php';

// REST API Registration & Callbacks
require_once get_stylesheet_directory() . '/inc/rest-api.php';
require_once get_stylesheet_directory() . '/inc/directory-feed.php';
require_once get_stylesheet_directory() . '/inc/country-rest-api.php';

// Admin Menu & Page Rendering
require_once get_stylesheet_directory() . '/inc/admin.php';

// Slug-level 301 redirects for renamed or retired pages
require_once get_stylesheet_directory() . '/inc/redirects.php';

// Generated facility pages (/facility/<slug>/) rendered from facilities_v2
// Unsilenced archive documents KOP lacks, listed on facility and operator pages
require_once get_stylesheet_directory() . '/inc/unsilenced-archive.php';
// ...and those on Surviving Straight Inc. and WWASP Survivors
require_once get_stylesheet_directory() . '/inc/survivor-archives.php';
// Article pictures (share image, else the publication's logo) for the news cards
require_once get_stylesheet_directory() . '/inc/news-images.php';
require_once get_stylesheet_directory() . '/inc/facility-pages.php';
require_once get_stylesheet_directory() . '/inc/operator-pages.php';
require_once get_stylesheet_directory() . '/inc/operator-history.php';

// Inspection highlights: the reviewed severe findings the home page and the
// inspection reports hub show, most recent first
require_once get_stylesheet_directory() . '/inc/inspection-highlights.php';

// Bug report status emails (shared by inc/admin.php and api/save-bug-report.php)
require_once get_stylesheet_directory() . '/inc/bug-report-notify.php';

// One-line help for the submission form's collapsed panels
require_once get_stylesheet_directory() . '/inc/form-help.php';

// Admin notifications for public submissions (suggested edits, wiki, news, ...)
require_once get_stylesheet_directory() . '/inc/submission-notify.php';

// "Send to Kids Over Profits" browser extension intake (browser-extension/send-to-kop/)
require_once get_stylesheet_directory() . '/inc/source-submissions.php';

// Features & Classes (Anonymous Portal, etc.)
require_once get_stylesheet_directory() . '/inc/features.php';

// Homepage Sidebar Widgets
require_once get_stylesheet_directory() . '/inc/widgets.php';

// Kadence content wrapper + sidebar (donate / newsletter widgets) around templates/*.php
require_once get_stylesheet_directory() . '/inc/template-layout.php';

// Site footer on every page: links, Givebutter donate button, copyright
require_once get_stylesheet_directory() . '/inc/site-footer.php';

// Ajax Search Lite live-dropdown integration (KOP database results)
require_once get_stylesheet_directory() . '/inc/ajax-search-lite.php';

// Global search bar REST endpoint (site-wide search widget backend)
require_once get_stylesheet_directory() . '/inc/global-search.php';

// Research & Reports card library (Academia + Government FileBird folders)
require_once get_stylesheet_directory() . '/inc/research-library.php';

// Where to report an abusive therapist or program, state by state. Loaded
// before the resources list, which links to it by KOP_REPORTING_SLUG.
require_once get_stylesheet_directory() . '/inc/reporting-directory.php';
require_once get_stylesheet_directory() . '/inc/glossary.php';
require_once get_stylesheet_directory() . '/inc/glossary-feedback.php';
require_once get_stylesheet_directory() . '/inc/glossary-editor.php';

// Page text edited in wp-admin (KOP Data Tools > Page Text): pages whose words
// live in js/data/pages/<slug>.json, e.g. /indian-boarding-schools/
require_once get_stylesheet_directory() . '/inc/page-text.php';
require_once get_stylesheet_directory() . '/inc/page-text-editor.php';

// Edit in place: a pencil on every piece of text for admins, saving to wherever it lives
require_once get_stylesheet_directory() . '/inc/inline-edit.php';

// Indigenous residential schools: their own records, never TTI facilities
// (listed on /indian-boarding-schools/, KOP Data Tools > Indigenous Schools)
require_once get_stylesheet_directory() . '/inc/indigenous-schools.php';
require_once get_stylesheet_directory() . '/inc/indigenous-schools-admin.php';

// Young adult programs (18+): their own records, never TTI facilities
// (listed on /young-adult-programs/, KOP Tools > Young Adult Programs, filled from Woodbury Facts)
require_once get_stylesheet_directory() . '/inc/young-adult-programs.php';
require_once get_stylesheet_directory() . '/inc/young-adult-programs-admin.php';

// Open data: daily bulk downloads of every public dataset (/open-data/)
require_once get_stylesheet_directory() . '/inc/open-data.php';

// The /resources/ list (crisis lines, survivor support, advocacy, reading)
require_once get_stylesheet_directory() . '/inc/resources-list.php';

// Network map data access (graph metadata + facility profile URLs)
require_once get_stylesheet_directory() . '/inc/network-map.php';
// Hourly: start the map build on GitHub when the records it reads change
require_once get_stylesheet_directory() . '/inc/network-rebuild.php';

// Find-by-name box beside every facility id field on the admin screens
require_once get_stylesheet_directory() . '/inc/facility-finder.php';
// Facility name suggestions for the data forms and links for free-text facility lists (providers' referrals)
require_once get_stylesheet_directory() . '/inc/facility-suggest.php';
// Facility closures reported in the news: hourly scan, review queue, confirm sets the status
require_once get_stylesheet_directory() . '/inc/closure-reports.php';
// Facility records linked to state inspection rows whose names differ (KOP Tools > Inspection Links)
require_once get_stylesheet_directory() . '/inc/inspection-links.php';
// Duplicate facility records found automatically and merged into one, with Undo (KOP Tools > Merge Duplicates)
require_once get_stylesheet_directory() . '/inc/facility-merge.php';
// A person id for everyone on a staff list, stamped as personId by an hourly sync (KOP Tools > People)
require_once get_stylesheet_directory() . '/inc/people.php';
require_once get_stylesheet_directory() . '/inc/people-admin.php';
require_once get_stylesheet_directory() . '/inc/people-merge.php';
// Facilities that surface in the news and are not in the database: the hourly scan creates their records
require_once get_stylesheet_directory() . '/inc/facility-discovery.php';
// Woodbury Reports pages about a program, cut by scripts/woodbury-scan.py, reviewed and filed in its doc library
require_once get_stylesheet_directory() . '/inc/woodbury-mentions.php';
require_once get_stylesheet_directory() . '/inc/woodbury-create.php';
require_once get_stylesheet_directory() . '/inc/woodbury-facts.php';
// Move or remove a document from the page it shows on (the document tile's pencil)
require_once get_stylesheet_directory() . '/inc/doc-placement.php';
// Links from the owner's Google Docs and Sheets (scripts/gdocs-extract.py), reviewed at KOP Tools > Drive Docs
require_once get_stylesheet_directory() . '/inc/drive-docs.php';
// What the old Fornits survivor forum says about each facility (scripts/fornits-crawl.py, fornits-process.py), reviewed at KOP Tools > Fornits
require_once get_stylesheet_directory() . '/inc/fornits.php';

// Where each long-form article sits: its trail back to a hub, and what to read next
require_once get_stylesheet_directory() . '/inc/article-parts.php';

// Hub pages that list one category's posts (Editorials, Investigatory Spotlight)
require_once get_stylesheet_directory() . '/inc/hub-posts.php';

// The frame a hub page is assembled in, and each hub's settings
require_once get_stylesheet_directory() . '/inc/hub-shell.php';

// Utility page presentation and legal document metadata
require_once get_stylesheet_directory() . '/inc/utility-pages.php';
require_once get_stylesheet_directory() . '/inc/legal-documents.php';

// The Document Archive: featured reports, collections, every program A to Z
require_once get_stylesheet_directory() . '/inc/document-archive.php';

// The pieces a long-form page is assembled from: era header, collapsible
// section, sources, "Why this matters" - as functions and as shortcodes
require_once get_stylesheet_directory() . '/inc/article-pieces.php';

// Keeps crawlers out of the staging copy of the site
require_once get_stylesheet_directory() . '/inc/staging-links.php';

// Featured and in-article photos placed from seeds/page-images.json
require_once get_stylesheet_directory() . '/inc/page-images.php';
