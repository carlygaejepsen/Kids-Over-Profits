<?php
/**
 * Template Name: Public Data Form
 * Template Post Type: page
 */

// Debug: Check if template is recognized
if (WP_DEBUG) {
    error_log('Page template detected: ' . get_page_template_slug());
    error_log('Is page template check: ' . (is_page_template('page-data.php') ? 'true' : 'false'));
}

include get_stylesheet_directory() . '/templates/data-form-public.php';
