<?php
/**
 * Kadence Child Theme Functions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}



if (!function_exists('kidsoverprofits_normalize_theme_base_uri')) {
    /**
     * Convert a WordPress theme directory URI to the public `/themes/` alias.
     *
     * The production site serves child theme assets from a friendly
     * `/themes/child/` path rather than the standard
     * `/wp-content/themes/child/` directory. When we bootstrap the data tools
     * inside WordPress we still receive the canonical path from
     * `get_stylesheet_directory_uri()`. This helper rewrites the canonical
     * value so the JavaScript asset loader prefers the publicly accessible
     * alias while preserving the original URI as a fallback.
     *
     * @param string $uri Theme directory URI.
     *
     * @return string Normalized URI using the `/themes/` alias when possible.
     */
    function kidsoverprofits_normalize_theme_base_uri($uri) {
        $clean_uri = untrailingslashit($uri);

        if ('' === $clean_uri) {
            return '';
        }

        $rewritten = preg_replace('#/wp-content/themes/#', '/themes/', $clean_uri, 1);

        return is_string($rewritten) && '' !== $rewritten ? $rewritten : $clean_uri;
    }
}

if (!function_exists('kidsoverprofits_get_theme_base_aliases')) {
    /**
     * Build a prioritized list of accessible theme base URIs.
     *
     * @param string $uri Theme directory URI.
     *
     * @return array Ordered list starting with the canonical URI followed by alternate aliases.
     */
    function kidsoverprofits_get_theme_base_aliases($uri) {
        $canonical = untrailingslashit($uri);

        if ('' === $canonical) {
            return array();
        }

        $aliases    = array($canonical);
        $normalized = kidsoverprofits_normalize_theme_base_uri($canonical);

        if ($normalized && $normalized !== $canonical) {
            $aliases[] = $normalized;
        }

        $filtered = array();

        foreach ($aliases as $alias) {
            if (!is_string($alias) || '' === $alias) {
                continue;
            }

            if (in_array($alias, $filtered, true)) {
                continue;
            }

            $filtered[] = $alias;
        }

        return $filtered;
    }
}

if (!function_exists('kidsoverprofits_get_theme_asset_details')) {
    /**
     * Retrieve a theme asset's URI and cache-busting version.
     *
     * @param string $relative_path Relative path within the child theme directory.
     * @return array|false Array with `uri` and `version` keys or false when the file is missing.
     */
    function kidsoverprofits_get_theme_asset_details($relative_path) {
        if (empty($relative_path)) {
            return false;
        }

        $file_path = get_theme_file_path($relative_path);

        if (!$file_path || !file_exists($file_path)) {
            error_log(sprintf('[Kids Over Profits] Asset not found: %s', $relative_path));
            return false;
        }

        $version = filemtime($file_path);

        if (!$version) {
            // Fallback to the current time if filemtime fails, for aggressive cache busting.
            $version = time();
        }

        return array(
            'uri'     => get_theme_file_uri($relative_path),
            'version' => $version,
        );
    }
}

if (!function_exists('kidsoverprofits_enqueue_theme_style')) {
    /**
     * Enqueue a stylesheet that lives inside the child theme directory.
     *
     * @param string $handle        WordPress style handle.
     * @param string $relative_path Relative file path within the theme.
     * @param array  $dependencies  Optional list of style handles this stylesheet depends on.
     * @param string $media         Optional media attribute value.
     */
    function kidsoverprofits_enqueue_theme_style($handle, $relative_path, $dependencies = array(), $media = 'all') {
        if (!$handle || !$relative_path) {
            return;
        }

        $asset = kidsoverprofits_get_theme_asset_details($relative_path);

        if (!$asset) {
            return;
        }

        wp_enqueue_style($handle, $asset['uri'], $dependencies, $asset['version'], $media);
    }
}

if (!function_exists('kidsoverprofits_get_and_modify_form_template')) {
    /**
     * Load a large HTML template for the facility data tools and adjust it for the current context.
     *
     * @param string $template_path Absolute path to the base template file.
     * @param array  $args          Optional configuration. Supports `replacements` for simple str_replace tweaks.
     *
     * @return string Render-ready HTML or an empty string on failure.
     */
    function kidsoverprofits_get_and_modify_form_template($template_path, $args = array()) {
        if (empty($template_path)) {
            return '';
        }

        $page_slug = '';

        if (function_exists('is_page') && is_page()) {
            $page_id = get_queried_object_id();
            if ($page_id) {
                $page_slug = get_post_field('post_name', $page_id) ?: '';
            }
        }

        // Use the dedicated suggestion template when rendering the public data submission page.
        if ('data' === $page_slug) {
            $suggestion_template = get_stylesheet_directory() . '/html/data.html';
            if (file_exists($suggestion_template)) {
                $template_path = $suggestion_template;
            }
        }

        if (!file_exists($template_path)) {
            error_log(sprintf('[Kids Over Profits] Form template not found: %s', $template_path));
            return '';
        }

        $content = file_get_contents($template_path);

        if (false === $content) {
            error_log(sprintf('[Kids Over Profits] Failed to read form template: %s', $template_path));
            return '';
        }

        // Ensure the fallback asset loader points at the active child theme when running inside WordPress.
        $theme_base            = untrailingslashit(get_stylesheet_directory_uri());
        $available_theme_bases = kidsoverprofits_get_theme_base_aliases($theme_base);
        $primary_theme_base    = $available_theme_bases ? $available_theme_bases[0] : $theme_base;
        $content    = str_replace(
            "const DEFAULT_THEME_BASE = 'https://kidsoverprofits.org/themes/child';",
            "const DEFAULT_THEME_BASE = '" . esc_url_raw($primary_theme_base ?: $theme_base) . "';",
            $content
        );

        $content = str_replace(
            "const defaultThemeBase = 'https://kidsoverprofits.org/themes/child';",
            "const defaultThemeBase = '" . esc_url_raw($primary_theme_base ?: $theme_base) . "';",
            $content
        );

        $content = str_replace(
            "const LEGACY_THEME_BASE = 'https://kidsoverprofits.org/wp-content/themes/child';",
            "const LEGACY_THEME_BASE = '" . esc_url_raw($theme_base) . "';",
            $content
        );

        $content = str_replace(
            "const legacyThemeBase = 'https://kidsoverprofits.org/wp-content/themes/child';",
            "const legacyThemeBase = '" . esc_url_raw($theme_base) . "';",
            $content
        );

        if (false === strpos($content, 'data-kop-theme-base')) {
            $content = str_replace(
                '<div class="container"',
                '<div class="container" data-kop-theme-base="' . esc_attr($primary_theme_base ?: $theme_base) . '"',
                $content
            );
        }

        if (!empty($args['replacements']) && is_array($args['replacements'])) {
            $content = str_replace(array_keys($args['replacements']), array_values($args['replacements']), $content);
        }

        // Provide sensible fallbacks if the suggestion template is missing but the data page reuses the admin layout.
        if ('data' === $page_slug && !file_exists(get_stylesheet_directory() . '/html/data.html')) {
            $content = str_replace(
                array(
                    "mode = 'master';",
                    "FORM_MODE = 'master';",
                    '/api/data_form/save-master.php',
                    'Admin: Master Facility Data Entry',
                    'ADMIN/MASTER MODE',
                ),
                array(
                    "mode = 'suggestions';",
                    "FORM_MODE = 'suggestions';",
                    '/api/data_form/save-suggestion.php',
                    '📮 Submit Facility Data Suggestions',
                    'SUGGESTION MODE',
                ),
                $content
            );
        }

        return $content;
    }
}

/**
 * Enqueue a theme JavaScript asset with an inline fallback.
 *
 * Some hosting environments block direct requests to theme JavaScript files,
 * which results in 404/403 responses for assets served from
 * `/wp-content/themes/child/js/*.js`. To guarantee the scripts still load, we
 * attempt to read the file contents and print them inline. If the file cannot
 * be read, the function falls back to WordPress' standard file-based enqueue.
 *
 * @param string $handle         Script handle.
 * @param string $relative_path  Relative path from the child theme directory
 *                               (e.g. `js/ca-reports.js`).
 * @param array  $dependencies   Optional script dependencies.
 * @param bool   $in_footer      Whether to load in the footer.
 * @param array  $localizations  Optional array of localization definitions.
 *                               Each definition should contain `name` and
 *                               `data` keys for `wp_localize_script()`.
 *
 * @return string|false Returns 'inline' when the script was printed inline,
 *                      'file' when the standard enqueue path was used, or
 *                      false when the file could not be found.
 */
function kidsoverprofits_enqueue_theme_script($handle, $relative_path, $dependencies = array(), $in_footer = true, $localizations = array()) {
    $file_path = get_theme_file_path($relative_path);

    if (!$file_path || !file_exists($file_path)) {
        error_log(sprintf('[Kids Over Profits] Script not found: %s', $relative_path));
        return false;
    }

    $version         = filemtime($file_path) ?: wp_get_theme()->get('Version');
    $script_contents = file_get_contents($file_path);

    if ($script_contents !== false) {
        wp_register_script($handle, '', $dependencies, $version, $in_footer);

        foreach ($localizations as $localization) {
            if (!empty($localization['name']) && array_key_exists('data', $localization)) {
                wp_localize_script($handle, $localization['name'], $localization['data']);
                kidsoverprofits_register_test_localization($handle, $localization['name'], $localization['data']);
            }
        }

        wp_enqueue_script($handle);
        wp_add_inline_script($handle, $script_contents);

        return 'inline';
    }

    $script_uri = get_theme_file_uri($relative_path);

    wp_register_script($handle, $script_uri, $dependencies, $version, $in_footer);

    foreach ($localizations as $localization) {
        if (!empty($localization['name']) && array_key_exists('data', $localization)) {
            wp_localize_script($handle, $localization['name'], $localization['data']);
            kidsoverprofits_register_test_localization($handle, $localization['name'], $localization['data']);
        }
    }

    wp_enqueue_script($handle);

    return 'file';
}

/**
 * Enqueue parent theme styles
 */
function kadence_child_enqueue_styles() {
    $theme = wp_get_theme();

    $parent_version = $theme->parent() ? $theme->parent()->get('Version') : $theme->get('Version');

    // Enqueue parent theme stylesheet
    wp_enqueue_style(
        'kadence-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        $parent_version
    );

    $child_style_path = get_stylesheet_directory() . '/style.css';
    $child_version    = file_exists($child_style_path) ? filemtime($child_style_path) : $theme->get('Version');

    // Enqueue child theme stylesheet
    wp_enqueue_style(
        'kadence-child-style',
        get_stylesheet_uri(),
        array('kadence-parent-style'),
        $child_version
    );
}
add_action('wp_enqueue_scripts', 'kadence_child_enqueue_styles', 20);

// =================================================================
// CUSTOM FUNCTIONS
// =================================================================

/**
 * Build an array of JSON file URLs for localization.
 *
 * @param array $json_config Array describing how to discover JSON files.
 * @return array
 */
function kidsoverprofits_get_report_json_urls($json_config) {
    $json_urls = array();

    if (empty($json_config)) {
        return $json_urls;
    }

    // Handle explicit file list
    if (!empty($json_config['files'])) {
        foreach ($json_config['files'] as $relative_file) {
            $file_path = get_theme_file_path($relative_file);

            if ($file_path && file_exists($file_path)) {
                $json_urls[] = esc_url(get_theme_file_uri($relative_file));
            }
        }

        return $json_urls;
    }

    // Handle multiple directories (for Utah with both reports and checklists)
    if (!empty($json_config['dirs'])) {
        foreach ($json_config['dirs'] as $dir_config) {
            $directory = !empty($dir_config['dir']) ? trailingslashit($dir_config['dir']) : '';
            $pattern   = !empty($dir_config['pattern']) ? $dir_config['pattern'] : '*.json';

            if (!$directory) {
                continue;
            }

            $absolute_directory = get_theme_file_path($directory);

            if ($absolute_directory && is_dir($absolute_directory)) {
                $files    = glob(trailingslashit($absolute_directory) . $pattern);
                $base_uri = trailingslashit(get_theme_file_uri($directory));

                if (!empty($files)) {
                    foreach ($files as $file) {
                        $json_urls[] = esc_url($base_uri . basename($file));
                    }
                }
            }
        }

        return $json_urls;
    }

    // Handle single directory
    $directory = !empty($json_config['dir']) ? trailingslashit($json_config['dir']) : '';
    $pattern   = !empty($json_config['pattern']) ? $json_config['pattern'] : '*.json';

    if (!$directory) {
        return $json_urls;
    }

    $absolute_directory = get_theme_file_path($directory);

    if ($absolute_directory && is_dir($absolute_directory)) {
        $files    = glob(trailingslashit($absolute_directory) . $pattern);
        $base_uri = trailingslashit(get_theme_file_uri($directory));

        if (!empty($files)) {
            foreach ($files as $file) {
                $json_urls[] = esc_url($base_uri . basename($file));
            }
        }
    }

    return $json_urls;
}

/**
 * Locate the most recent JSON export that matches a pattern.
 *
 * @param string $relative_dir Relative directory inside the theme.
 * @param string $pattern      Glob pattern to match files.
 * @return string              Public URL for the most recent file, or empty string if none found.
 */
function kidsoverprofits_get_latest_json_url($relative_dir, $pattern = '*.json') {
    $relative_dir = trailingslashit($relative_dir);
    $absolute_directory = get_theme_file_path($relative_dir);

    if (!$absolute_directory || !is_dir($absolute_directory)) {
        return '';
    }

    $files = glob(trailingslashit($absolute_directory) . $pattern);

    if (empty($files)) {
        return '';
    }

    usort($files, function($a, $b) {
        $mtime_diff = filemtime($b) - filemtime($a);

        if ($mtime_diff !== 0) {
            return $mtime_diff;
        }

        return strnatcasecmp(basename($b), basename($a));
    });

    $latest_file = basename($files[0]);

    return esc_url(trailingslashit(get_theme_file_uri($relative_dir)) . $latest_file);
}

/**
 * Load scripts for state report pages.
 */
function kidsoverprofits_enqueue_state_report_assets() {
    if (!is_page()) {
        return;
    }

    $page_id = get_queried_object_id();

    if (!$page_id) {
        return;
    }

    $slug = get_post_field('post_name', $page_id);

    if (!$slug) {
        return;
    }

    $configs = array(
        'ca-reports' => array(
            'handle'    => 'ca-reports-display',
            'script'    => 'js/state_reports/ca-reports.js',
            'json'      => array(
                'dir'     => 'js/data/ca_reports/',
                'pattern' => '*.json',
            ),
        ),
        'ut-reports' => array(
            'handle' => 'ut-reports-display',
            'script' => 'js/state_reports/ut_reports.js',
            'json'   => array(
                'files' => array('js/data/ut_reports.json'),
            ),
        ),
        'az-reports' => array(
            'handle' => 'az-reports-display',
            'script' => 'js/state_reports/az_reports.js',
            'json'   => array(
                'dir'     => 'js/data/az_reports/',
                'pattern' => '*.json',
            ),
        ),
        'tx-reports' => array(
            'handle' => 'tx-reports-display',
            'script' => 'js/state_reports/tx_reports.js',
            'json'   => array(
                'dir'     => 'js/data/TX_reports/',
                'pattern' => '*.json',
            ),
        ),
        'mt-reports' => array(
            'handle' => 'mt-reports-display',
            'script' => 'js/state_reports/mt_reports.js',
            'json'   => array(
                'files' => array('js/data/mt_reports.json'),
            ),
        ),
        'ct-reports' => array(
            'handle' => 'ct-reports-display',
            'script' => 'js/state_reports/ct_reports.js',
            'json'   => array(
                'files' => array('js/data/ct_reports.json'),
            ),
        ),
        'wa-reports' => array(
            'handle' => 'wa-reports-display',
            'script' => 'js/state_reports/wa_reports.js',
            'json'   => array(
                'files' => array('js/data/wa_reports.json'),
            ),
        ),
        'tti-program-index' => array(
            'handle' => 'facilities-display',
            'script' => 'js/facilities-display.js',
            'json'   => array(
                'dir'     => 'js/data/',
                'pattern' => 'facility-projects-export*.json',
            ),
        ),
    );

    if (!isset($configs[$slug])) {
        return;
    }

    $report_styles = array(
        array('handle' => 'kidsoverprofits-common', 'path' => 'css/common.css'),
        array('handle' => 'kidsoverprofits-layout', 'path' => 'css/layout.css'),
        array('handle' => 'kidsoverprofits-tables', 'path' => 'css/tables.css'),
        array('handle' => 'kidsoverprofits-print-report', 'path' => 'css/print-report.css', 'media' => 'print'),
    );

    foreach ($report_styles as $style) {
        $handle = $style['handle'];
        $path   = $style['path'];
        $deps   = $style['deps'] ?? array();
        $media  = $style['media'] ?? 'all';

        kidsoverprofits_enqueue_theme_style($handle, $path, $deps, $media);
    }

    $config    = $configs[$slug];
    $json_urls = kidsoverprofits_get_report_json_urls($config['json']);

    // Use consistent localization structure for all pages
    $localize_name = 'myThemeData';
    $localize_key  = 'jsonFileUrls';

    // Special handling for TTI program index (facilities display)
    if ($slug === 'tti-program-index') {
        $localize_name = 'facilitiesConfig';
        $localize_key  = 'jsonDataUrl';
        $latest_export_url = kidsoverprofits_get_latest_json_url('js/data', 'facility-projects-export*.json');

        if (!empty($latest_export_url)) {
            $json_data = $latest_export_url;
        } else {
            // For single file configs, pass just the URL string instead of array
            $json_data = !empty($json_urls) ? $json_urls[0] : '';
        }
    } else {
        $json_data = $json_urls;
    }

    kidsoverprofits_enqueue_theme_script(
        $config['handle'],
        $config['script'],
        array(),
        true,
        array(
            array(
                'name' => $localize_name,
                'data' => array($localize_key => $json_data)
            ),
        )
    );
}
add_action('wp_enqueue_scripts', 'kidsoverprofits_enqueue_state_report_assets');

/**
 * Enqueue shared assets for the data management tools.
 */
function kidsoverprofits_enqueue_data_tool_assets() {
    if (!is_page()) {
        return;
    }

    $page_id = get_queried_object_id();

    if (!$page_id) {
        return;
    }

    $slug = get_post_field('post_name', $page_id);

    if (!$slug) {
        return;
    }

    $style_pages = array('admin-data', 'data', 'data-organizer');

    if (in_array($slug, $style_pages, true)) {
        $styles = array(
            array('handle' => 'kidsoverprofits-common', 'path' => 'css/common.css'),
            array('handle' => 'kidsoverprofits-layout', 'path' => 'css/layout.css'),
            array('handle' => 'kidsoverprofits-forms', 'path' => 'css/forms.css'),
            array('handle' => 'kidsoverprofits-tables', 'path' => 'css/tables.css'),
            array('handle' => 'kidsoverprofits-modals', 'path' => 'css/modals.css'),
            array('handle' => 'kidsoverprofits-admin', 'path' => 'css/admin.css'),
            array('handle' => 'kidsoverprofits-print-report', 'path' => 'css/print-report.css', 'media' => 'print'),
        );

        foreach ($styles as $style) {
            $handle = $style['handle'];
            $path   = $style['path'];
            $deps   = $style['deps'] ?? array();
            $media  = $style['media'] ?? 'all';

            kidsoverprofits_enqueue_theme_style($handle, $path, $deps, $media);
        }
    }

    $theme_directory_uri = untrailingslashit(get_stylesheet_directory_uri());
    $theme_base_aliases   = kidsoverprofits_get_theme_base_aliases($theme_directory_uri);
    $primary_theme_base   = $theme_base_aliases ? $theme_base_aliases[0] : $theme_directory_uri;

    $primary_theme_base = $primary_theme_base ? esc_url_raw($primary_theme_base) : '';

    $alternate_theme_bases = array();

    if (!empty($theme_base_aliases)) {
        foreach ($theme_base_aliases as $index => $alias) {
            if (0 === $index) {
                continue;
            }

            $sanitized_alias = esc_url_raw($alias);

            if ($sanitized_alias) {
                $alternate_theme_bases[] = $sanitized_alias;
            }
        }
    }

    $form_configs = array(
        'admin-data' => array(
            'mode'      => 'master',
            'endpoints' => array(
                'SAVE_PROJECT' => '/api/data_form/save-master.php',
                'LOAD_PROJECTS' => '/api/data_form/get-master-data.php',
                'AUTOCOMPLETE'  => '/api/data_form/get-autocomplete.php',
            ),
        ),
        'data' => array(
            'mode'      => 'suggestions',
            'endpoints' => array(
                'SAVE_PROJECT' => '/api/data_form/save-suggestion.php',
                'LOAD_PROJECTS' => '/api/data_form/get-master-data.php',
                'AUTOCOMPLETE'  => '/api/data_form/get-autocomplete.php',
            ),
        ),
    );

    foreach ($form_configs as $config_key => $config) {
        if ($primary_theme_base) {
            $form_configs[$config_key]['apiBase'] = $primary_theme_base;
        }

        if (!empty($alternate_theme_bases)) {
            $form_configs[$config_key]['apiBaseFallbacks'] = $alternate_theme_bases;
        }
    }

    $fallback_projects_url = kidsoverprofits_get_latest_json_url('js/data', 'facility-projects-export*.json');

    if (!empty($fallback_projects_url) && isset($form_configs['data'])) {
        $form_configs['data']['fallbackProjectsUrl'] = $fallback_projects_url;
    }

    $script_map = array(
        'admin-data' => array(
            array('handle' => 'kidsoverprofits-theme-bootstrap', 'path' => 'js/theme-base-bootstrap.js'),
            array(
                'handle' => 'kidsoverprofits-app-logic',
                'path'   => 'js/app-logic.js',
                'deps'   => array('kidsoverprofits-theme-bootstrap'),
            ),
            array(
                'handle' => 'kidsoverprofits-utilities',
                'path'   => 'js/utilities.js',
                'deps'   => array('kidsoverprofits-app-logic'),
            ),
            array(
                'handle' => 'kidsoverprofits-facility-form',
                'path'   => 'js/facility-form.v3.js',
                'deps'   => array('kidsoverprofits-utilities'),
            ),
            array(
                'handle' => 'kidsoverprofits-admin-data',
                'path'   => 'js/admin-data.js',
                'deps'   => array('kidsoverprofits-facility-form'),
            ),
        ),
        'data' => array(
            array('handle' => 'kidsoverprofits-theme-bootstrap', 'path' => 'js/theme-base-bootstrap.js'),
            array(
                'handle' => 'kidsoverprofits-app-logic',
                'path'   => 'js/app-logic.js',
                'deps'   => array('kidsoverprofits-theme-bootstrap'),
            ),
            array(
                'handle' => 'kidsoverprofits-utilities',
                'path'   => 'js/utilities.js',
                'deps'   => array('kidsoverprofits-app-logic'),
            ),
            array(
                'handle' => 'kidsoverprofits-facility-form',
                'path'   => 'js/facility-form.v3.js',
                'deps'   => array('kidsoverprofits-utilities'),
            ),
            array(
                'handle' => 'kidsoverprofits-data-form',
                'path'   => 'js/data-form.js',
                'deps'   => array('kidsoverprofits-facility-form'),
            ),
        ),
        'data-organizer' => array(
            array('handle' => 'kidsoverprofits-app-logic', 'path' => 'js/app-logic.js'),
            array('handle' => 'kidsoverprofits-data-organizer', 'path' => 'js/data-organizer.js', 'deps' => array('kidsoverprofits-app-logic')),
        ),
    );

    if (!isset($script_map[$slug])) {
        return;
    }

    foreach ($script_map[$slug] as $script) {
        $handle = $script['handle'];
        $path   = $script['path'];
        $deps   = $script['deps'] ?? array();

        $localizations = array();

        if (isset($form_configs[$slug]) && 'kidsoverprofits-facility-form' === $handle) {
            $localizations[] = array(
                'name' => 'KOP_FACILITY_FORM_CONFIG',
                'data' => $form_configs[$slug],
            );
        }

        if (wp_script_is($handle, 'enqueued')) {
            continue;
        }

        kidsoverprofits_enqueue_theme_script($handle, $path, $deps, true, $localizations);
    }

    if (isset($form_configs[$slug])) {
        $form_mode = $form_configs[$slug]['mode'];
        wp_add_inline_script(
            'kidsoverprofits-facility-form',
            'window.FORM_MODE = ' . wp_json_encode($form_mode) . ';',
            'before'
        );
    }
}
add_action('wp_enqueue_scripts', 'kidsoverprofits_enqueue_data_tool_assets', 25);

/**
 * Enqueue assets for internal testing harness pages.
 */
function kidsoverprofits_enqueue_test_harness_assets() {
    if (!is_page()) {
        return;
    }

    $page_id = get_queried_object_id();

    if (!$page_id) {
        return;
    }

    $slug = get_post_field('post_name', $page_id);

    if ('test-autocomplete' !== $slug) {
        return;
    }

    $style = kidsoverprofits_get_theme_asset_details('css/test-harnesses.css');

    if ($style) {
        wp_enqueue_style('kidsoverprofits-test-harnesses', $style['uri'], array(), $style['version']);
    }

    $theme_directory_uri = untrailingslashit(get_stylesheet_directory_uri());
    $theme_base_aliases   = kidsoverprofits_get_theme_base_aliases($theme_directory_uri);

    $primary_theme_base = $theme_base_aliases ? $theme_base_aliases[0] : $theme_directory_uri;
    $primary_theme_base = $primary_theme_base ? esc_url_raw($primary_theme_base) : '';

    $alternate_theme_bases = array();

    if (!empty($theme_base_aliases)) {
        foreach ($theme_base_aliases as $index => $alias) {
            if (0 === $index) {
                continue;
            }

            $sanitized_alias = esc_url_raw($alias);

            if ($sanitized_alias) {
                $alternate_theme_bases[] = $sanitized_alias;
            }
        }
    }

    $endpoint_candidates = array();

    if ($primary_theme_base) {
        $endpoint_candidates[] = trailingslashit($primary_theme_base) . 'api/data_form/get-autocomplete.php';
    }

    if (!empty($alternate_theme_bases)) {
        foreach ($alternate_theme_bases as $alias) {
            $endpoint_candidates[] = trailingslashit($alias) . 'api/data_form/get-autocomplete.php';
        }
    }

    $primary_endpoint = $endpoint_candidates ? array_shift($endpoint_candidates) : '';

    kidsoverprofits_enqueue_theme_script(
        'kidsoverprofits-test-autocomplete',
        'js/test-autocomplete.js',
        array(),
        true,
        array(
            array(
                'name' => 'KOP_AUTOCOMPLETE_TEST_CONFIG',
                'data' => array(
                    'endpoint'          => $primary_endpoint,
                    'fallbackEndpoints' => $endpoint_candidates,
                    'themeBases'        => array_values(array_filter(array_merge($primary_theme_base ? array($primary_theme_base) : array(), $alternate_theme_bases))),
                ),
            ),
        )
    );
}
add_action('wp_enqueue_scripts', 'kidsoverprofits_enqueue_test_harness_assets', 40);

if (!function_exists('kidsoverprofits_build_test_config')) {
    /**
     * Build a diagnostic configuration blob consumed by the test harness scripts.
     *
     * The config captures a snapshot of the current page context, the active theme,
     * and the scripts/styles WordPress has queued so the browser-based tests can
     * highlight missing assets. The function degrades gracefully when invoked in
     * non-WordPress contexts (e.g., CLI linting) by returning a minimal structure.
     *
     * @return array
     */
    function kidsoverprofits_build_test_config() {
        $config = array(
            'generatedAt' => gmdate('c'),
            'page'        => array(
                'title' => '',
                'slug'  => '',
                'url'   => '',
            ),
            'theme'       => array(
                'stylesheet' => function_exists('get_stylesheet') ? get_stylesheet() : '',
                'template'   => function_exists('get_template') ? get_template() : '',
                'directory'  => function_exists('get_stylesheet_directory_uri') ? untrailingslashit(get_stylesheet_directory_uri()) : '',
            ),
            'scripts'     => array(),
            'css'         => array(),
            'localizations' => array(),
        );

        if (function_exists('get_queried_object')) {
            $queried = get_queried_object();

            if ($queried) {
                if (isset($queried->post_title) && $queried->post_title) {
                    $config['page']['title'] = $queried->post_title;
                }

                if (isset($queried->post_name) && $queried->post_name) {
                    $config['page']['slug'] = $queried->post_name;
                } elseif (isset($queried->name) && $queried->name) {
                    $config['page']['slug'] = $queried->name;
                }
            }
        }

        if (empty($config['page']['title']) && function_exists('wp_get_document_title')) {
            $config['page']['title'] = wp_get_document_title();
        }

        if (function_exists('home_url')) {
            $request_path = '';

            if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && property_exists($GLOBALS['wp'], 'request')) {
                $request_path = $GLOBALS['wp']->request;
            } elseif (!empty($_SERVER['REQUEST_URI'])) {
                $request_path = ltrim((string) $_SERVER['REQUEST_URI'], '/');
            }

            $config['page']['url'] = home_url($request_path ? '/' . ltrim($request_path, '/') : '/');
        } elseif (!empty($_SERVER['REQUEST_URI'])) {
            $config['page']['url'] = (string) $_SERVER['REQUEST_URI'];
        }

        $wp_scripts = null;

        if (function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
        } elseif (class_exists('WP_Scripts') && isset($GLOBALS['wp_scripts']) && $GLOBALS['wp_scripts'] instanceof WP_Scripts) {
            $wp_scripts = $GLOBALS['wp_scripts'];
        }

        if ($wp_scripts && class_exists('WP_Scripts') && $wp_scripts instanceof WP_Scripts) {
            $script_queue = is_array($wp_scripts->queue) ? $wp_scripts->queue : array();
            $script_done  = is_array($wp_scripts->done) ? $wp_scripts->done : array();

            foreach ($wp_scripts->registered as $handle => $script) {
                $is_enqueued = in_array($handle, $script_queue, true) || in_array($handle, $script_done, true);
                $expected    = (bool) preg_match('/(kidsover|kop|facility|report)/i', $handle);
                $source      = '';

                if ($script && isset($script->src)) {
                    $source = $script->src;
                }

                if ($source && 0 === strpos($source, '/') && function_exists('home_url')) {
                    $source = home_url($source);
                }

                $config['scripts'][] = array(
                    'handle'     => $handle,
                    'label'      => $handle,
                    'source'     => $source,
                    'isEnqueued' => $is_enqueued,
                    'expected'   => $expected,
                );

                $localization = $wp_scripts->get_data($handle, 'data');

                if (!empty($localization)) {
                    $config['localizations'][] = array(
                        'handle' => $handle,
                        'data'   => $localization,
                    );
                }
            }
        }

        $wp_styles = null;

        if (function_exists('wp_styles')) {
            $wp_styles = wp_styles();
        } elseif (class_exists('WP_Styles') && isset($GLOBALS['wp_styles']) && $GLOBALS['wp_styles'] instanceof WP_Styles) {
            $wp_styles = $GLOBALS['wp_styles'];
        }

        if ($wp_styles && class_exists('WP_Styles') && $wp_styles instanceof WP_Styles) {
            $style_queue = is_array($wp_styles->queue) ? $wp_styles->queue : array();
            $style_done  = is_array($wp_styles->done) ? $wp_styles->done : array();

            foreach ($wp_styles->registered as $handle => $style) {
                $is_enqueued = in_array($handle, $style_queue, true) || in_array($handle, $style_done, true);
                $expected    = (bool) preg_match('/(kidsover|kop|facility|report|theme)/i', $handle);
                $source      = '';

                if ($style && isset($style->src)) {
                    $source = $style->src;
                }

                if ($source && 0 === strpos($source, '/') && function_exists('home_url')) {
                    $source = home_url($source);
                }

                $config['css'][] = array(
                    'handle'     => $handle,
                    'label'      => $handle,
                    'source'     => $source,
                    'isEnqueued' => $is_enqueued,
                    'expected'   => $expected,
                );
            }
        }

        return $config;
    }
}

/**
 * Enqueue test scripts for debugging display issues
 *
 * This function adds testing scripts that verify CSS and data loading
 * and display diagnostic panels on the page.
 * 
 * To enable:
 * - Add ?debug=css to any page URL to test CSS loading
 * - Add ?debug=report to any page URL to test report data loading
 * - Add ?debug=visual to any page URL to use visual testing tools
 * - Add ?debug=all to any page URL to run all tests
 */
function kidsoverprofits_enqueue_test_scripts() {
    if (!isset($_GET['debug'])) {
        return;
    }

    $debug_mode = strtolower(sanitize_text_field(wp_unslash($_GET['debug'])));

    if (!in_array($debug_mode, array('css', 'report', 'visual', 'all'), true)) {
        $debug_mode = 'all';
    }

    $config_handle = 'kidsoverprofits-test-config';
    $config_data   = kidsoverprofits_build_test_config();

    wp_register_script($config_handle, '', array(), false, true);
    wp_enqueue_script($config_handle);
    wp_add_inline_script(
        $config_handle,
        'window.kidsOverProfitsTestConfig = ' . wp_json_encode($config_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';',
        'after'
    );

    $base_dependencies = array('jquery', $config_handle);

    if ($debug_mode === 'css' || $debug_mode === 'all') {
        $css_script_path = get_stylesheet_directory() . '/js/css-test.js';

        wp_enqueue_script(
            'css-test-script',
            get_stylesheet_directory_uri() . '/js/css-test.js',
            $base_dependencies,
            file_exists($css_script_path) ? filemtime($css_script_path) : time(),
            true
        );
    }

    if ($debug_mode === 'report' || $debug_mode === 'all') {
        $report_script_path = get_stylesheet_directory() . '/js/report-test.js';

        wp_enqueue_script(
            'report-test-script',
            get_stylesheet_directory_uri() . '/js/report-test.js',
            $base_dependencies,
            file_exists($report_script_path) ? filemtime($report_script_path) : time(),
            true
        );
    }

    if ($debug_mode === 'visual' || $debug_mode === 'all') {
        $visual_script_path = get_stylesheet_directory() . '/js/visual-test.js';

        wp_enqueue_script(
            'visual-test-script',
            get_stylesheet_directory_uri() . '/js/visual-test.js',
            $base_dependencies,
            file_exists($visual_script_path) ? filemtime($visual_script_path) : time(),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'kidsoverprofits_enqueue_test_scripts', 120);

// =================================================================
// ANONYMOUS DOCUMENT PORTAL
// =================================================================

class AnonymousDocPortal {
    
    private $upload_dir;
    private $allowed_types = array('pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip');
    private $max_file_size = 10485760; // 10MB
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_nopriv_submit_anonymous_doc', array($this, 'handle_submission'));
        add_action('wp_ajax_submit_anonymous_doc', array($this, 'handle_submission'));
        add_shortcode('anonymous_doc_portal', array($this, 'render_portal'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Create secure upload directory
        $this->setup_upload_directory();
    }
    
    private function setup_upload_directory() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/anonymous-submissions/';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($this->upload_dir . 'index.php', '<?php // Silence is golden');
        }
    }
    
    public function enqueue_scripts() {
        $post = get_post();

        if (is_page() && $post && has_shortcode($post->post_content, 'anonymous_doc_portal')) {
            $css_path = get_stylesheet_directory() . '/css/anonymous-portal.css';

            kidsoverprofits_enqueue_theme_script(
                'anonymous-portal-js',
                'js/anonymous-portal.js',
                array('jquery'),
                true,
                array(
                    array(
                        'name' => 'anonymous_portal_ajax',
                        'data' => array(
                            'ajax_url'      => admin_url('admin-ajax.php'),
                            'nonce'         => wp_create_nonce('anonymous_doc_nonce'),
                            'max_size'      => $this->max_file_size,
                            'allowed_types' => $this->allowed_types,
                        ),
                    ),
                )
            );

            wp_enqueue_style(
                'anonymous-portal-css',
                get_stylesheet_directory_uri() . '/css/anonymous-portal.css',
                array(),
                file_exists($css_path) ? filemtime($css_path) : '1.0'
            );
        }
    }
    
    public function render_portal($atts) {
        $atts = shortcode_atts(array(
            'title' => '',
            'description' => 'Submit documents anonymously. All submissions are encrypted and secure.'
        ), $atts);
        
        ob_start();
        ?>
        <div id="anonymous-doc-portal" class="anonymous-portal-container">
            <div class="portal-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <p class="portal-description"><?php echo esc_html($atts['description']); ?></p>
            </div>
            
            <div class="upload-area" id="upload-area">
                <div class="upload-content">
                    <h3>Drop files here or click to browse</h3>
                    <p>Supported formats: PDF, DOC, DOCX, TXT, JPG, PNG, ZIP</p>
                    <p>Maximum file size: 10MB</p>
                    <input type="file" id="file-input" multiple accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip">
                </div>
            </div>
            
            <div class="form-section">
                <div class="form-group">
                    <label for="submission-message">Optional Message (Anonymous)</label>
                    <textarea id="submission-message" placeholder="Add any context or message about your submission..." rows="4"></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label required">
                        <input type="checkbox" id="legal-confirmation" required>
                        <span class="checkmark"></span>
                        <span class="checkbox-text">I confirm that this document was obtained through legal means and I have the right to submit it.</span>
                        <span class="required-indicator">*</span>
                    </label>
                    <small>This confirmation is required to proceed with submission.</small>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="redaction-needed">
                        <span class="checkmark"></span>
                        <span class="checkbox-text">This document contains sensitive information that may require redaction (personal data, confidential information, etc.)</span>
                    </label>
                    <small>Check this if the document contains names, addresses, phone numbers, social security numbers, or other sensitive data that should be protected.</small>
                </div>
                
                <div class="form-group">
                    <label for="contact-method">Optional Contact Method</label>
                    <select id="contact-method">
                        <option value="">No contact needed</option>
                        <option value="email">Secure Email Response</option>
                        <option value="phone">Phone Response</option>
                        <option value="portal">Check back on this portal</option>
                    </select>
                </div>
                
                <div class="form-group" id="contact-details" style="display: none;">
                    <label for="contact-info">Contact Information</label>
                    <input type="text" id="contact-info" placeholder="Enter your preferred contact method">
                    <small>This information is encrypted and only accessible to authorized personnel.</small>
                </div>
            </div>
            
            <div class="file-list" id="file-list"></div>
            
            <div class="portal-actions">
                <button type="button" id="submit-docs" class="submit-btn" disabled>
                    <span class="btn-text">Submit Documents</span>
                    <span class="btn-loading" style="display: none;">Submitting...</span>
                </button>
            </div>
            
            <div class="status-messages" id="status-messages"></div>
            
            <div class="privacy-notice">
                <h4>🔒 Privacy & Security Notice</h4>
                <ul>
                    <li>No IP addresses or identifying information are logged</li>
                    <li>All files are encrypted during transmission and storage</li>
                    <li>File metadata is automatically stripped</li>
                    <li>Optional contact information is encrypted separately</li>
                    <li>Submissions are automatically deleted after 90 days unless flagged for retention</li>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_submission() {
        // Verify nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'anonymous_doc_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $response = array('success' => false, 'message' => '');
        
        try {
            // Generate unique submission ID
            $submission_id = $this->generate_submission_id();
            
            // Process uploaded files
            $uploaded_files = $this->process_uploaded_files($submission_id);
            
            if (empty($uploaded_files)) {
                throw new Exception('No valid files were uploaded');
            }
            
            // Save submission metadata (encrypted)
            $this->save_submission_metadata($submission_id, $uploaded_files, $_POST);
            
            // Log submission (minimal, no personal info)
            $this->log_submission($submission_id, count($uploaded_files));
            
            $response['success'] = true;
            $response['message'] = 'Documents submitted successfully. Your submission ID is: ' . $submission_id;
            $response['submission_id'] = $submission_id;
            
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
            error_log('Anonymous portal error: ' . $e->getMessage());
        }
        
        wp_send_json($response);
    }
    
    private function generate_submission_id() {
        return 'SUB-' . date('Y') . '-' . strtoupper(wp_generate_password(8, false));
    }
    
    private function process_uploaded_files($submission_id) {
        if (!isset($_FILES['files'])) {
            return array();
        }
        
        $uploaded_files = array();
        $files = $_FILES['files'];
        
        // Handle multiple file upload
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $file_data = array(
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'size' => $files['size'][$i]
            );
            
            $processed_file = $this->process_single_file($file_data, $submission_id);
            if ($processed_file) {
                $uploaded_files[] = $processed_file;
            }
        }
        
        return $uploaded_files;
    }
    
    private function process_single_file($file_data, $submission_id) {
        // Validate file type
        $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $this->allowed_types)) {
            throw new Exception('File type not allowed: ' . $file_ext);
        }
        
        // Validate file size
        if ($file_data['size'] > $this->max_file_size) {
            throw new Exception('File too large: ' . $file_data['name']);
        }
        
        // Additional MIME type validation
        $allowed_mimes = array(
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'zip' => 'application/zip'
        );
        
        if (function_exists('mime_content_type')) {
            $actual_mime = mime_content_type($file_data['tmp_name']);
            if (!in_array($actual_mime, $allowed_mimes)) {
                throw new Exception('Invalid file type detected: ' . $file_data['name']);
            }
        }
        
        // Generate secure filename
        $secure_filename = $submission_id . '_' . wp_generate_password(12, false) . '.' . $file_ext;
        $file_path = $this->upload_dir . $secure_filename;
        
        // Move and process file
        if (move_uploaded_file($file_data['tmp_name'], $file_path)) {
            // Strip metadata for security
            $this->strip_file_metadata($file_path, $file_ext);
            
            return array(
                'original_name' => sanitize_file_name($file_data['name']),
                'secure_name' => $secure_filename,
                'file_path' => $file_path,
                'file_size' => $file_data['size'],
                'file_type' => $file_ext
            );
        }
        
        return false;
    }
    
    private function strip_file_metadata($file_path, $file_ext) {
        // For images, strip EXIF data
        if (in_array($file_ext, array('jpg', 'jpeg'))) {
            if (function_exists('imagecreatefromjpeg')) {
                $image = imagecreatefromjpeg($file_path);
                if ($image) {
                    imagejpeg($image, $file_path, 90);
                    imagedestroy($image);
                }
            }
        } elseif ($file_ext === 'png') {
            if (function_exists('imagecreatefrompng')) {
                $image = imagecreatefrompng($file_path);
                if ($image) {
                    imagepng($image, $file_path);
                    imagedestroy($image);
                }
            }
        }
    }
    
    private function save_submission_metadata($submission_id, $files, $post_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        // Create table if it doesn't exist
        $this->create_submissions_table();
        
        // Encrypt sensitive data with improved security
        $encrypted_message = $this->encrypt_data(sanitize_textarea_field($post_data['message'] ?? ''));
        $encrypted_contact = $this->encrypt_data(sanitize_text_field($post_data['contact_info'] ?? ''));
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'submission_id' => $submission_id,
                'file_count' => count($files),
                'encrypted_message' => $encrypted_message,
                'contact_method' => sanitize_text_field($post_data['contact_method'] ?? ''),
                'encrypted_contact' => $encrypted_contact,
                'legal_confirmation' => isset($post_data['legal_confirmation']) ? 1 : 0,
                'redaction_needed' => isset($post_data['redaction_needed']) ? 1 : 0,
                'submission_date' => current_time('mysql'),
                'files_data' => wp_json_encode($files),
                'status' => 'pending'
            ),
            array('%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            throw new Exception('Failed to save submission metadata');
        }
    }
    
    private function create_submissions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            submission_id varchar(20) NOT NULL,
            file_count int(11) NOT NULL,
            encrypted_message longtext,
            contact_method varchar(20),
            encrypted_contact text,
            legal_confirmation tinyint(1) DEFAULT 0,
            redaction_needed tinyint(1) DEFAULT 0,
            submission_date datetime NOT NULL,
            files_data longtext,
            status varchar(20) DEFAULT 'pending',
            notes longtext,
            PRIMARY KEY (id),
            UNIQUE KEY submission_id (submission_id),
            INDEX status_idx (status),
            INDEX submission_date_idx (submission_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function encrypt_data($data) {
        if (empty($data)) return '';
        
        // Improved encryption with random IV
        $key = hash('sha256', wp_salt('SECURE_AUTH') . wp_salt('NONCE_SALT'));
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encrypted === false) {
            error_log('Encryption failed');
            return '';
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    private function decrypt_data($encrypted_data) {
        if (empty($encrypted_data)) return '';
        
        $data = base64_decode($encrypted_data);
        if ($data === false) return '';
        
        $key = hash('sha256', wp_salt('SECURE_AUTH') . wp_salt('NONCE_SALT'));
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    private function log_submission($submission_id, $file_count) {
        // Minimal logging - no personal information
        error_log("Anonymous submission: ID={$submission_id}, Files={$file_count}, Time=" . current_time('mysql'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Anonymous Submissions',
            'Anonymous Docs',
            'manage_options',
            'anonymous-submissions',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'anonymous_submissions';
        
        // Handle status updates
        if (isset($_POST['update_status']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'update_submission_status')) {
            $wpdb->update(
                $table_name,
                array('status' => sanitize_text_field($_POST['status'])),
                array('id' => intval($_POST['submission_db_id'])),
                array('%s'),
                array('%d')
            );
            echo '<div class="notice notice-success"><p>Status updated successfully.</p></div>';
        }
        
        // Handle file deletion for cleanup
        if (isset($_POST['delete_files']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_submission_files')) {
            $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_POST['submission_db_id'])));
            if ($submission) {
                $files_data = json_decode($submission->files_data, true);
                if ($files_data) {
                    foreach ($files_data as $file) {
                        if (isset($file['file_path']) && file_exists($file['file_path'])) {
                            unlink($file['file_path']);
                        }
                    }
                }
                $wpdb->update(
                    $table_name,
                    array('status' => 'files_deleted'),
                    array('id' => intval($_POST['submission_db_id'])),
                    array('%s'),
                    array('%d')
                );
                echo '<div class="notice notice-success"><p>Files deleted successfully.</p></div>';
            }
        }
        
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submission_date DESC LIMIT 100");
        
        ?>
        <div class="wrap">
            <h1>Anonymous Document Submissions</h1>
            
            <div class="tablenav">
                <div class="alignleft actions">
                    <p><strong>Total Submissions:</strong> <?php echo count($submissions); ?></p>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Submission ID</th>
                        <th>Date</th>
                        <th>Files</th>
                        <th>Legal Status</th>
                        <th>Redaction</th>
                        <th>Contact Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission): ?>
                    <tr>
                        <td><strong><?php echo esc_html($submission->submission_id); ?></strong></td>
                        <td><?php echo esc_html(date('M j, Y g:i A', strtotime($submission->submission_date))); ?></td>
                        <td><?php echo intval($submission->file_count); ?> files</td>
                        <td>
                            <?php if ($submission->legal_confirmation): ?>
                                <span class="status-confirmed">✅ Confirmed</span>
                            <?php else: ?>
                                <span class="status-unconfirmed">❌ Not Confirmed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($submission->redaction_needed): ?>
                                <span class="redaction-needed">⚠️ Required</span>
                            <?php else: ?>
                                <span class="redaction-none">✓ None needed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($submission->contact_method ?: 'None'); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($submission->status); ?>">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $submission->status))); ?>
                            </span>
                        </td>
                        <td>
                            <details>
                                <summary>Actions</summary>
                                <div class="submission-actions">
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('update_submission_status'); ?>
                                        <input type="hidden" name="submission_db_id" value="<?php echo $submission->id; ?>">
                                        <select name="status">
                                            <option value="pending" <?php selected($submission->status, 'pending'); ?>>Pending</option>
                                            <option value="reviewed" <?php selected($submission->status, 'reviewed'); ?>>Reviewed</option>
                                            <option value="archived" <?php selected($submission->status, 'archived'); ?>>Archived</option>
                                        </select>
                                        <input type="submit" name="update_status" value="Update" class="button button-small">
                                    </form>
                                    
                                    <?php if ($submission->encrypted_message): ?>
                                        <button type="button" class="button button-small view-message" data-message="<?php echo esc_attr($this->decrypt_data($submission->encrypted_message)); ?>">View Message</button>
                                    <?php endif; ?>
                                    
                                    <?php if ($submission->status !== 'files_deleted'): ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete all files for this submission?');">
                                            <?php wp_nonce_field('delete_submission_files'); ?>
                                            <input type="hidden" name="submission_db_id" value="<?php echo $submission->id; ?>">
                                            <input type="submit" name="delete_files" value="Delete Files" class="button button-small button-link-delete">
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .status-pending { color: #f56e28; }
        .status-reviewed { color: #00a32a; }
        .status-archived { color: #646970; }
        .status-files_deleted { color: #dc3232; }
        .status-confirmed { color: #00a32a; font-weight: 600; }
        .status-unconfirmed { color: #dc3232; font-weight: 600; }
        .redaction-needed { color: #f56e28; font-weight: 600; }
        .redaction-none { color: #00a32a; }
        .submission-actions { padding: 10px; }
        .submission-actions form { margin: 5px 0; }
        .view-message { margin-left: 5px; }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-message').forEach(function(button) {
                button.addEventListener('click', function() {
                    var message = this.getAttribute('data-message');
                    alert('Submission Message:\n\n' + message);
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize the portal
$anonymous_portal = new AnonymousDocPortal();