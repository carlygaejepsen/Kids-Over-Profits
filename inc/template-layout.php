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
    // The generated facility pages (inc/facility-pages.php) are not posts;
    // they are rendered from the database at /facility/<slug>/.
    if (function_exists('kop_facility_pages_is_page') && kop_facility_pages_is_page()) {
        return 'templates/facility-page.php';
    }
    // Pages and posts alike: a post can carry a "Template Post Type: post"
    // template (templates/single-facility-profile.php) via the same meta.
    if (!is_singular()) {
        return '';
    }
    $slug = (string) get_page_template_slug();
    return strpos($slug, 'templates/') === 0 ? $slug : '';
}

/**
 * Child templates that render with the site's default post layout (sidebar
 * side and content width) regardless of the per-post Layout setting. The
 * facility profiles were authored under the "narrow" post layout, which
 * drops the sidebar and squeezes the column; they should look like every
 * other post.
 */
function kop_template_layout_normal_width() {
    return apply_filters('kop_template_layout_normal_width', array(
        'templates/single-facility-profile.php',
        'templates/facility-page.php',
    ));
}

/**
 * Child templates that render full width with no sidebar (admin tools with
 * wide forms and tables). Filter 'kop_template_layout_no_sidebar' to change.
 * The facility profiles are not here: they keep the site's standard sidebar
 * layout and content width, with the facts rail floated inside the column.
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
        // Not an admin tool, but the map wants every pixel of width it can get.
        'templates/page-network-map.php',
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
    if ($template && in_array($template, kop_template_layout_normal_width(), true)) {
        // Mirror Kadence's own "default" branch for posts. The theme's
        // accessor is namespaced (Kadence\kadence()), not a global function.
        $default = function_exists('Kadence\kadence') ? (string) \Kadence\kadence()->option('post_layout') : '';
        if ($default === 'left' || $default === 'right') {
            $layout['layout']  = $default;
            $layout['side']    = $default;
            $layout['sidebar'] = 'enable';
        } else {
            $layout['layout']  = in_array($default, array('narrow', 'fullwidth'), true) ? $default : 'normal';
            $layout['sidebar'] = 'disable';
        }
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

/**
 * Internal tool templates that must never be indexed: admin screens and the
 * logged-in editors. Filter 'kop_template_layout_noindex' to change.
 */
function kop_template_layout_noindex() {
    return apply_filters('kop_template_layout_noindex', array(
        'templates/page-admin-data.php',
        'templates/page-admin-data-manager.php',
        'templates/page-admin-lawsuits.php',
        'templates/page-admin-legislation.php',
        'templates/page-admin-submissions.php',
        'templates/page-admin-volunteers.php',
        'templates/page-news-processor.php',
        'templates/page-wiki-editor.php',
    ));
}

function kop_template_layout_is_noindex() {
    if (!is_singular()) {
        return false;
    }
    if (in_array(kop_template_layout_current_template(), kop_template_layout_noindex(), true)) {
        return true;
    }
    // Tool pages built without a child template.
    return is_page(array('tti-wiki-entry-generator', 'wiki-editor', 'news-processor'));
}

// Core robots output (used when Yoast is not handling the tag).
add_filter('wp_robots', 'kop_template_layout_wp_robots', 20);
function kop_template_layout_wp_robots($robots) {
    if (!kop_template_layout_is_noindex()) {
        return $robots;
    }
    unset($robots['max-image-preview']);
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    return $robots;
}

// Yoast SEO replaces the core tag with its own; it reads this filter.
add_filter('wpseo_robots', 'kop_template_layout_wpseo_robots', 20);
function kop_template_layout_wpseo_robots($robots) {
    if (!kop_template_layout_is_noindex()) {
        return $robots;
    }
    return 'noindex, nofollow';
}
