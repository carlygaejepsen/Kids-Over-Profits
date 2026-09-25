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
require_once get_stylesheet_directory() . '/inc/country-rest-api.php';

// Admin Menu & Page Rendering
require_once get_stylesheet_directory() . '/inc/admin.php';

// Slug-level 301 redirects for renamed or retired pages
require_once get_stylesheet_directory() . '/inc/redirects.php';

// Generated facility pages (/facility/<slug>/) rendered from facilities_v2
require_once get_stylesheet_directory() . '/inc/facility-pages.php';
require_once get_stylesheet_directory() . '/inc/operator-pages.php';

// Inspection highlights: the reviewed severe findings the home page and the
// inspection reports hub show, most recent first
require_once get_stylesheet_directory() . '/inc/inspection-highlights.php';

// Bug report status emails (shared by inc/admin.php and api/save-bug-report.php)
require_once get_stylesheet_directory() . '/inc/bug-report-notify.php';

// One-line help for the submission form's collapsed panels
require_once get_stylesheet_directory() . '/inc/form-help.php';

// Admin notifications for public submissions (suggested edits, wiki, news, ...)
require_once get_stylesheet_directory() . '/inc/submission-notify.php';

// Features & Classes (Anonymous Portal, etc.)
require_once get_stylesheet_directory() . '/inc/features.php';

// Homepage Sidebar Widgets
require_once get_stylesheet_directory() . '/inc/widgets.php';

// Kadence content wrapper + sidebar (donate / newsletter widgets) around templates/*.php
require_once get_stylesheet_directory() . '/inc/template-layout.php';

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

// The /resources/ list (crisis lines, survivor support, advocacy, reading)
require_once get_stylesheet_directory() . '/inc/resources-list.php';

// Network map data access (graph metadata + facility profile URLs)
require_once get_stylesheet_directory() . '/inc/network-map.php';

// Where each long-form article sits: its trail back to a hub, and what to read next
require_once get_stylesheet_directory() . '/inc/article-parts.php';

// Hub pages that list one category's posts (Editorials, Investigatory Spotlight)
require_once get_stylesheet_directory() . '/inc/hub-posts.php';

// The frame a hub page is assembled in, and each hub's settings
require_once get_stylesheet_directory() . '/inc/hub-shell.php';

// The Document Archive: featured reports, collections, every program A to Z
require_once get_stylesheet_directory() . '/inc/document-archive.php';

// The pieces a long-form page is assembled from: era header, collapsible
// section, sources, "Why this matters" - as functions and as shortcodes
require_once get_stylesheet_directory() . '/inc/article-pieces.php';

// Keeps crawlers out of the staging copy of the site
require_once get_stylesheet_directory() . '/inc/staging-links.php';
