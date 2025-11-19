<?php
/**
 * Template Name: Data Form Minimal Test
 */

get_header();

echo '<!-- Starting template include -->';
$template_file = get_stylesheet_directory() . '/templates/data-form-public-minimal.php';
if (file_exists($template_file)) {
    include $template_file;
    echo '<!-- Template included successfully -->';
} else {
    echo '<div class="container"><p>Error: Minimal template not found</p></div>';
}

get_footer();
