<?php
/**
 * Kadence content layout for the child theme's page templates.
 *
 * Kadence's own page.php wraps the page in #primary > .content-container >
 * #main, then calls get_sidebar(), and that sidebar is where the site-wide
 * search, donate (Givebutter) and newsletter (MailerLite) widgets live
 * (Customizer > Page Layout: left sidebar, "sidebar-primary"). The child
 * theme's templates/*.php call get_header() and get_footer() directly, so
 * they never printed that wrapper or the sidebar.
 *
 * This file prints the same wrapper around every child template by hooking
 * the top and bottom of Kadence's #inner-wrap, so each template's own
 * markup lands in the content column with the sidebar beside it. Whether a
 * sidebar shows still follows Kadence's normal rules (Customizer default,
 * per-page Layout setting), except for the admin tools listed in
 * kop_template_layout_no_sidebar(), which are forced full width.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template file (relative to the theme, e.g. "templates/page-state.php")
 * when the current request is a page using one of the child templates.
 */
function kop_template_layout_current_template() {
    if (!is_page()) {
        return '';
    }
    $slug = (string) get_page_template_slug();
    return strpos($slug, 'templates/') === 0 ? $slug : '';
}

/**
 * Child templates that render full width with no sidebar (admin tools with
 * wide forms and tables). Filter 'kop_template_layout_no_sidebar' to change.
 */
function kop_template_layout_no_sidebar() {
    return apply_filters('kop_template_layout_no_sidebar', array(
        'templates/page-admin-data.php',
        'templates/page-admin-data-manager.php',
        'templates/page-admin-lawsuits.php',
        'templates/page-admin-legislation.php',
        'templates/page-admin-submissions.php',
        'templates/page-admin-volunteers.php',
        'templates/page-news-processor.php',
    ));
}

/**
 * Kadence resolves the page layout lazily (first has_sidebar() call, which
 * happens while building the body class), so a filter registered at load
 * time is enough to switch the admin tools to no sidebar.
 */
add_filter('kadence_post_layout', 'kop_template_layout_filter_sidebar');
function kop_template_layout_filter_sidebar($layout) {
    $template = kop_template_layout_current_template();
    if ($template && in_array($template, kop_template_layout_no_sidebar(), true)) {
        $layout['sidebar'] = 'disable';
    }
    return $layout;
}

/** Open the Kadence content wrapper (mirrors template-parts/content/single.php). */
add_action('kadence_before_content', 'kop_template_layout_open', 999);
function kop_template_layout_open() {
    if (!kop_template_layout_current_template()) {
        return;
    }
    if (function_exists('kadence')) {
        kadence()->print_styles('kadence-content');
    }
    do_action('kadence_hero_header');
    echo '<div id="primary" class="content-area kop-template-content-area">' . "\n";
    echo '<div class="content-container site-container">' . "\n";
    echo '<div id="main" class="site-main">' . "\n";
    do_action('kadence_before_main_content');
    echo '<div class="content-wrap">' . "\n";
}

/** Close the wrapper and print the sidebar, exactly where Kadence would. */
add_action('kadence_after_content', 'kop_template_layout_close', 1);
function kop_template_layout_close() {
    if (!kop_template_layout_current_template()) {
        return;
    }
    echo "\n" . '</div><!-- .content-wrap -->' . "\n";
    do_action('kadence_after_main_content');
    echo '</div><!-- #main -->' . "\n";
    get_sidebar();
    echo '</div><!-- .content-container -->' . "\n";
    echo '</div><!-- #primary -->' . "\n";
}
