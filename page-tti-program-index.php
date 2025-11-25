<?php
/**
 * Template Name: TTI Program Index
 * Description: Template for displaying and editing TTI program facilities data
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Load the admin data form template that includes all fields with inputs
$template_file = get_stylesheet_directory() . '/templates/data-form-admin.php';

if (file_exists($template_file)) {
    include $template_file;
} else {
    echo '<div class="container"><p>Error: Data form template not found.</p></div>';
}

get_footer();
