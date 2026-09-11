<?php
/**
 * Slug-level 301 redirects for retired or renamed pages. Kept in git so the
 * mapping deploys with the theme instead of living in a plugin's database.
 *
 * Old path (no slashes) => destination path or absolute URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_redirect_map() {
    return array(
        // Renamed (phase 1): the country template derives the name from the slug.
        'uk'            => '/united-kingdom/',

        // Retired shells (phase 3). Each was an empty container or hand-typed
        // list that a template-driven page now covers.
        'edcons'        => '/referrers-educational-consultants/',
        'international' => '/location-index/?type=country',
        '405-2'         => '/',
        'test-scripts'  => '/',
        'admin-tools'   => '/wp-admin/admin.php?page=kop-tools',
    );
}

function kop_apply_redirect_map() {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $request = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $path = trim((string) $request, '/');
    if ($path === '') {
        return;
    }
    $map = kop_redirect_map();
    if (!isset($map[$path])) {
        return;
    }
    $target = $map[$path];
    if (strpos($target, 'http') !== 0) {
        $target = home_url($target);
    }
    wp_safe_redirect($target, 301);
    exit;
}
add_action('template_redirect', 'kop_apply_redirect_map', 1);
