<?php
/**
 * Template Name: Data Form Submission
 *
 * Public-facing form for submitting data suggestions.
 */

get_header();

// Load the complete HTML structure that the data form expects
$template_file = get_stylesheet_directory() . '/templates/data-form-public.php';
if (file_exists($template_file)) {
    include $template_file;
} else {
    echo '<div class="container"><p>Error: Template file not found at: ' . esc_html($template_file) . '</p></div>';
}

get_footer();
