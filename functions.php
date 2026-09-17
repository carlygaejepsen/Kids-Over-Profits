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

// Bug report status emails (shared by inc/admin.php and api/save-bug-report.php)
require_once get_stylesheet_directory() . '/inc/bug-report-notify.php';

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