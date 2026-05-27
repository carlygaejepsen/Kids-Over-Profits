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

// REST API Registration & Callbacks
require_once get_stylesheet_directory() . '/inc/rest-api.php';
require_once get_stylesheet_directory() . '/inc/country-rest-api.php';

// Admin Menu & Page Rendering
require_once get_stylesheet_directory() . '/inc/admin.php';

// Features & Classes (Anonymous Portal, etc.)
require_once get_stylesheet_directory() . '/inc/features.php';

// Homepage Sidebar Widgets
require_once get_stylesheet_directory() . '/inc/widgets.php';