<?php
/**
 * Template Name: Data Form Submission
 *
 * Public-facing form for submitting data suggestions.
 */

error_log('=== DATA FORM PAGE: Starting ===');

get_header();

error_log('=== DATA FORM PAGE: Header loaded ===');

// Load the complete HTML structure that the data form expects
$template_file = get_stylesheet_directory() . '/templates/data-form-public.php';
error_log('=== DATA FORM PAGE: Template path: ' . $template_file . ' ===');
error_log('=== DATA FORM PAGE: File exists: ' . (file_exists($template_file) ? 'YES' : 'NO') . ' ===');

if (file_exists($template_file)) {
    error_log('=== DATA FORM PAGE: About to include template ===');
    include $template_file;
    error_log('=== DATA FORM PAGE: Template included successfully ===');
} else {
    error_log('=== DATA FORM PAGE: ERROR - Template file not found ===');
    echo '<div class="container"><p>Error: Template file not found at: ' . esc_html($template_file) . '</p></div>';
}

error_log('=== DATA FORM PAGE: About to load footer ===');
get_footer();
error_log('=== DATA FORM PAGE: Complete ===');
