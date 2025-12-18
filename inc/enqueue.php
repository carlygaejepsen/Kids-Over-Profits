<?php
/**
 * Enqueueing functions for the Kadence Child theme.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue parent theme styles
 */
function kadence_child_enqueue_styles() {
    // Enqueue parent theme stylesheet
    wp_enqueue_style(
        'kadence-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme()->get('Version')
    );

    // Enqueue shared color variables for all pages
    $colors_path = get_stylesheet_directory() . '/css/colors.css';

    if (file_exists($colors_path)) {
        wp_enqueue_style(
            'kop-colors',
            get_stylesheet_directory_uri() . '/css/colors.css',
            array('kadence-parent-style'),
            filemtime($colors_path)
        );
    }
}
add_action('wp_enqueue_scripts', 'kadence_child_enqueue_styles');

/**
 * Enqueue admin submissions page scripts and styles
 */
function kop_enqueue_admin_submissions() {
    if (is_page_template('page-admin-submissions.php')) {
        wp_enqueue_style(
            'kop-admin-submissions',
            get_stylesheet_directory_uri() . '/css/admin-submissions.css',
            array(),
            filemtime(get_stylesheet_directory() . '/css/admin-submissions.css')
        );

        wp_enqueue_script(
            'kop-admin-submissions',
            get_stylesheet_directory_uri() . '/js/admin-submissions.js',
            array(),
            filemtime(get_stylesheet_directory() . '/js/admin-submissions.js'),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'kop_enqueue_admin_submissions');

/**
 * Enqueue the Kadence navigation guard script on headerless pages.
 * This intercepts DOM queries for navigation elements and suppresses errors.
 */
function kop_enqueue_kadence_nav_guard() {
    if (!kop_is_headerless_layout()) {
        return;
    }
    
    wp_enqueue_script(
        'kop-kadence-nav-guard',
        get_stylesheet_directory_uri() . '/js/data-form/kadence-nav-guard.js',
        array(), // No dependencies - load as early as possible
        '1.0.0',
        false // Load in header, not footer
    );
}
add_action('wp_enqueue_scripts', 'kop_enqueue_kadence_nav_guard', 1); // Priority 1 - load very early

/**
 * Remove Kadence navigation scripts when the page intentionally renders without a header.
 */
function kop_maybe_disable_kadence_navigation() {
    if (!kop_is_headerless_layout()) {
        return;
    }

    // List of Kadence navigation-related script handles to remove
    // Includes various possible handle names used by Kadence theme
    $nav_scripts = array(
        'kadence-navigation',
        'kadence-navigation-init',
        'kadence-navigation-mobile',
        'kadence-header',
        'kadence-sticky-header',
        'kadence-nav',
        'kadence-menu',
        'navigation', // Generic handle that might be used
    );

    foreach ($nav_scripts as $script_handle) {
        wp_dequeue_script($script_handle);
        wp_deregister_script($script_handle);
    }

    // Access global scripts registry
    global $wp_scripts;

    if (!($wp_scripts instanceof WP_Scripts)) {
        $wp_scripts = wp_scripts();
    }

    // Clear any inline scripts or extra data attached to navigation scripts
    if ($wp_scripts instanceof WP_Scripts) {
        foreach ($nav_scripts as $script_handle) {
            if (isset($wp_scripts->registered[$script_handle])) {
                $wp_scripts->registered[$script_handle]->extra = array();
                $wp_scripts->registered[$script_handle]->deps = array();
            }
        }
    }
}

add_action('wp_enqueue_scripts', 'kop_maybe_disable_kadence_navigation', 200);
add_action('wp_print_scripts', 'kop_maybe_disable_kadence_navigation', 200);
add_action('wp_print_footer_scripts', 'kop_maybe_disable_kadence_navigation', 200);

/**
 * Add inline script to block navigation on headerless pages as early as possible.
 */
function kop_add_early_navigation_blocker() {
    if (!kop_is_headerless_layout()) {
        return;
    }

    ?>
    <script>
    (function(){
        window.KADENCE_NAV_DISABLED = true;
        window.kadenceConfig = window.kadenceConfig || {};
        window.kadenceConfig.breakPoints = {desktop: 99999};
    })();
    </script>
    <?php
}
add_action('wp_head', 'kop_add_early_navigation_blocker', 1);

/**
 * Add global error suppressor for Kadence navigation.min.js errors.
 * This runs on ALL pages to catch getAttribute errors from missing nav elements.
 * Does NOT hide the header - just suppresses console errors.
 */
function kop_add_navigation_error_suppressor() {
    ?>
    <script>
    (function(){
        // Suppress navigation.min.js getAttribute errors globally
        window.addEventListener('error', function(e) {
            if (e.filename && e.filename.includes('navigation') && 
                e.message && e.message.includes('getAttribute')) {
                e.preventDefault();
                e.stopPropagation();
                return true;
            }
        }, true);
        
        // Patch querySelector to return safe objects for nav elements
        var origQuerySelector = Document.prototype.querySelector;
        Document.prototype.querySelector = function(selector) {
            var result = origQuerySelector.call(this, selector);
            // If looking for nav-related elements and nothing found, return a safe stub
            if (!result && typeof selector === 'string' && 
                (selector.includes('navigation') || selector.includes('nav-toggle') || 
                 selector.includes('mobile-toggle') || selector.includes('drawer'))) {
                return {
                    getAttribute: function() { return null; },
                    setAttribute: function() {},
                    addEventListener: function() {},
                    removeEventListener: function() {},
                    classList: { add: function(){}, remove: function(){}, toggle: function(){}, contains: function(){ return false; } },
                    style: {},
                    querySelectorAll: function() { return []; },
                    querySelector: function() { return null; }
                };
            }
            return result;
        };
    })();
    </script>
    <?php
}
add_action('wp_head', 'kop_add_navigation_error_suppressor', 0); // Priority 0 - very first thing

/**
 * Load facilities data for TTI program index page.
 * NOTE: This function is now disabled for pages using the page-tti-program-index.php template,
 * as they now use the full data form instead of the read-only display.
 */
function load_facilities_data() {
    // Skip if using the page template (which uses the full data form)
    if (is_page_template('page-tti-program-index.php')) {
        return;
    }

    if (!kop_is_tti_program_index_context()) {
        return;
    }

    $dataset_urls = kop_get_facility_projects_dataset_urls();
    $rest_endpoint = kop_get_facilities_rest_endpoint_url();

    $script_path = get_stylesheet_directory() . '/js/inspections/facilities-display.js';
    $script_version = file_exists($script_path) ? filemtime($script_path) : time();

    $primary_dataset = !empty($rest_endpoint) ? $rest_endpoint : '';

    if ($primary_dataset === '' && !empty($dataset_urls)) {
        $primary_dataset = $dataset_urls[0];
    }

    $reports_style_path = get_stylesheet_directory() . '/css/facility-reports.css';
    if (file_exists($reports_style_path)) {
        wp_enqueue_style(
            'kop-facility-reports-style',
            get_stylesheet_directory_uri() . '/css/facility-reports.css',
            array('kop-colors'),
            filemtime($reports_style_path)
        );
    }

    wp_enqueue_script(
        'facilities-display',
        get_stylesheet_directory_uri() . '/js/inspections/facilities-display.js',
        array(),
        $script_version,
        true
);
    $json_sources = array();

    if (!empty($rest_endpoint)) {
        $json_sources[] = $rest_endpoint;
    }

    if (!empty($dataset_urls)) {
        $json_sources = array_merge($json_sources, $dataset_urls);
    }

    wp_localize_script(
        'facilities-display',
        'facilitiesConfig',
        array(
            'jsonDataUrl' => $primary_dataset,
            'jsonFileUrls' => array_values(array_filter(array_unique($json_sources)))
        )
    );
}
add_action('wp_enqueue_scripts', 'load_facilities_data');

/**
 * Load educational consultant data for the "edcons" page.
 */
function load_edcons_data() {
    // Only run on the 'edcons' page.
    if (!is_page('edcons')) {
        return;
    }

    $dataset_urls = kop_get_facility_projects_dataset_urls();
    $rest_endpoint = kop_get_facilities_rest_endpoint_url();

    $script_path = get_stylesheet_directory() . '/js/edcons-display.js';
    if (!file_exists($script_path)) {
        return;
    }
    $script_version = filemtime($script_path);

    wp_enqueue_script(
        'edcons-display',
        get_stylesheet_directory_uri() . '/js/edcons-display.js',
        array(),
        $script_version,
        true
    );

    $json_sources = array();
    if (!empty($rest_endpoint)) {
        $json_sources[] = $rest_endpoint;
    }
    if (!empty($dataset_urls)) {
        $json_sources = array_merge($json_sources, $dataset_urls);
    }

    wp_localize_script(
        'edcons-display',
        'edconsConfig',
        array('jsonFileUrls' => array_values(array_filter(array_unique($json_sources))))
    );
}
add_action('wp_enqueue_scripts', 'load_edcons_data');

function kop_enqueue_report_scripts() {
    $reports = array(
        'ca-reports' => array(
            'script_handle' => 'ca-reports-script',
            'script_path'   => '/js/inspections/ca-reports.js',
            'data_object'   => 'caReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ccl*.json',
        ),
        'ut-reports' => array(
            'script_handle' => 'ut-reports-script',
            'script_path'   => '/js/inspections/ut_reports.js',
            'data_object'   => 'utReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ut_*.json',
        ),
        'az-reports' => array(
            'script_handle' => 'az-reports-script',
            'script_path'   => '/js/inspections/az_reports.js',
            'data_object'   => 'azReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/az_reports/*.json',
        ),
        'tx-reports' => array(
            'script_handle' => 'tx-reports-script',
            'script_path'   => '/js/inspections/tx_reports.js',
            'data_object'   => 'txReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/tx_reports.json',
        ),
        'mt-reports' => array(
            'script_handle' => 'mt-reports-script',
            'script_path'   => '/js/inspections/mt_reports.js',
            'data_object'   => 'mtReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/mt_reports.json',
        ),
        'ct-reports' => array(
            'script_handle' => 'ct-reports-script',
            'script_path'   => '/js/inspections/ct_reports.js',
            'data_object'   => 'ctReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/ct_reports.json',
        ),
        'wa-reports' => array(
            'script_handle' => 'wa-reports-script',
            'script_path'   => '/js/inspections/wa_reports.js',
            'data_object'   => 'waReportsData',
            'json_glob'     => get_stylesheet_directory() . '/js/data/wa_reports.json',
        ),
    );

    foreach ($reports as $page_slug => $config) {
        if (is_page($page_slug)) {
            $script_full_path = get_stylesheet_directory() . $config['script_path'];
            if (!file_exists($script_full_path)) {
                continue;
            }

            $reports_style_path = get_stylesheet_directory() . '/css/facility-reports.css';
            if (file_exists($reports_style_path)) {
                wp_enqueue_style(
                    'kop-facility-reports-style',
                    get_stylesheet_directory_uri() . '/css/facility-reports.css',
                    array('kop-colors'),
                    filemtime($reports_style_path)
                );
            }

            wp_enqueue_script(
                $config['script_handle'],
                get_stylesheet_directory_uri() . $config['script_path'],
                array('jquery'), // Assuming jQuery dependency
                filemtime($script_full_path),
                true
            );

            $json_files = glob($config['json_glob']);
            $json_urls  = array();
            if ($json_files) {
                foreach ($json_files as $file) {
                    // Construct URL relative to the theme root.
                    $relative_path = str_replace(get_stylesheet_directory(), '', $file);
                    $json_urls[]   = get_stylesheet_directory_uri() . $relative_path;
                }
            }

            wp_localize_script(
                $config['script_handle'],
                $config['data_object'],
                array('jsonFileUrls' => $json_urls)
            );

            // Stop after finding the first matching page to avoid unnecessary checks.
            break;
        }
    }
}
add_action('wp_enqueue_scripts', 'kop_enqueue_report_scripts');

/**
 * Enqueue shared scripts that power the autocomplete module.
 */
function kop_enqueue_autocomplete_dependencies() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    $utilities_relative_path = '/js/data-form/utilities.js';
    $utilities_file_path = $theme_dir . $utilities_relative_path;
    $utilities_uri = $theme_uri . $utilities_relative_path;

    wp_enqueue_script(
        'utilities-module-script',
        $utilities_uri,
        array('jquery'),
        file_exists($utilities_file_path) ? filemtime($utilities_file_path) : time(),
        true
    );

    $loader_relative_path = '/js/data-form/db-form-loader.js';
    $loader_file_path = $theme_dir . $loader_relative_path;
    $loader_uri = $theme_uri . $loader_relative_path;

    wp_enqueue_script(
        'db-form-loader',
        $loader_uri,
        array('jquery', 'utilities-module-script'),
        file_exists($loader_file_path) ? filemtime($loader_file_path) : time(),
        true
    );

    $autocomplete_relative_path = '/js/autocomplete.js';
    $autocomplete_file_path = $theme_dir . $autocomplete_relative_path;
    $autocomplete_uri = $theme_uri . $autocomplete_relative_path;

    wp_enqueue_script(
        'autocomplete-module-script',
        $autocomplete_uri,
        array('jquery', 'utilities-module-script', 'db-form-loader'),
        file_exists($autocomplete_file_path) ? filemtime($autocomplete_file_path) : time(),
        true
    );
}

/**
 * Load data form script
 */
function enqueue_data_form_script() {
    // DEBUG: Always output to browser (not dependent on WP_DEBUG)
    add_action('wp_footer', function() {
        echo '<script>console.log("🔍 DEBUG: enqueue_data_form_script() was CALLED");</script>';
    }, 1);

    // Only run on singular pages (posts, pages), not on archive pages.
    if (!is_singular()) {
        add_action('wp_footer', function() {
            echo '<script>console.error("❌ DEBUG: FAILED is_singular() check");</script>';
        }, 1);
        return;
    }

    add_action('wp_footer', function() {
        echo '<script>console.log("✅ DEBUG: Passed is_singular() check");</script>';
    }, 1);

    // Check for data form templates (multiple possible paths WordPress might store)
    // Also check by slug for pages that may not have template meta set correctly
    $is_data_template  = is_page_template('page-data.php')
        || is_page_template('child/page-data.php')
        || is_page_template('templates/data-form-public.php')
        || is_page('tti-data-submission')
        || is_page('data');
    $is_admin_template = is_page_template('page-admin-data.php')
        || is_page_template('child/page-admin-data.php')
        || is_page_template('templates/data-form-admin.php')
        || is_page_template('page-tti-program-index.php')
        || is_page('admin-data')
        || is_page('tti-admin-data');
    $is_data_form_page = $is_data_template || $is_admin_template;

    // DEBUG: Output template info (not dependent on WP_DEBUG)
    add_action('wp_footer', function() use ($is_data_template, $is_admin_template, $is_data_form_page) {
        $current_template = get_page_template_slug();
        $page_slug = get_post_field('post_name', get_post());
        echo '<script>';
        echo 'console.log("📄 Template: ' . esc_js($current_template) . '");';
        echo 'console.log("📝 Page slug: ' . esc_js($page_slug) . '");';
        echo 'console.log("🔍 is_data_template: ' . ($is_data_template ? 'TRUE' : 'FALSE') . '");';
        echo 'console.log("🔍 is_admin_template: ' . ($is_admin_template ? 'TRUE' : 'FALSE') . '");';
        echo 'console.log("🔍 is_data_form_page: ' . ($is_data_form_page ? 'TRUE' : 'FALSE') . '");';
        echo '</script>';
    }, 1);

    if (!$is_data_form_page) {
        add_action('wp_footer', function() {
            echo '<script>console.log("ℹ️ INFO: Data Form script skipped (Template check correctly identified this is not a data form page).");</script>';
        }, 1);
        return;
    }

    kop_enqueue_autocomplete_dependencies();

    add_action('wp_footer', function() {
        echo '<script>console.log("✅ DEBUG: Template check PASSED - proceeding to enqueue scripts");</script>';
    }, 1);

    // DEBUG: Log that we're enqueueing scripts
    if (WP_DEBUG) {
        error_log('enqueue_data_form_script: IS enqueueing scripts for data form');
        error_log('Template check - is_data_template: ' . ($is_data_template ? 'true' : 'false'));
        error_log('Template check - is_admin_template: ' . ($is_admin_template ? 'true' : 'false'));

        // Add diagnostic script to browser console
        add_action('wp_footer', function() {
            echo '<script>console.log("DEBUG: enqueue_data_form_script() was called and scripts should be enqueued");</script>';
        }, 1);
    }

    // Ensure the data-form stylesheet is queued before header output.
    $data_form_css = get_stylesheet_directory() . '/css/data-form.css';
    if (file_exists($data_form_css)) {
        wp_enqueue_style(
            'kop-data-form-style',
            get_stylesheet_directory_uri() . '/css/data-form.css',
            array('kadence-parent-style', 'kop-colors'),
            filemtime($data_form_css)
        );
    }

    // Enqueue the toolbar stylesheet
    $toolbar_css = get_stylesheet_directory() . '/css/toolbar.css';
    if (file_exists($toolbar_css)) {
        wp_enqueue_style(
            'kop-toolbar-style',
            get_stylesheet_directory_uri() . '/css/toolbar.css',
            array('kop-data-form-style'),
            filemtime($toolbar_css)
        );
    }

        // Enqueue the new configuration module

        $config_module_relative_path = '/js/data-form-modules/config.js';

        $config_module_file_path = get_stylesheet_directory() . $config_module_relative_path;

        $config_module_uri = get_stylesheet_directory_uri() . $config_module_relative_path;

    

        wp_enqueue_script(

            'kop-form-config-script',

            $config_module_uri,

            array('jquery'), // It accesses window but doesn't depend on other form modules

            file_exists($config_module_file_path) ? filemtime($config_module_file_path) : time(),

            true

        );

    

        // Enqueue the new data normalizer module

        $normalizer_module_relative_path = '/js/data-form-modules/data-normalizer.js';

        $normalizer_module_file_path = get_stylesheet_directory() . $normalizer_module_relative_path;

        $normalizer_module_uri = get_stylesheet_directory_uri() . $normalizer_module_relative_path;

    

        wp_enqueue_script(

            'kop-data-normalizer-script',

            $normalizer_module_uri,

            array('jquery', 'referrer-form-script'), // Depends on referrer form for create functions

            file_exists($normalizer_module_file_path) ? filemtime($normalizer_module_file_path) : time(),

            true

        );

    

        // Enqueue the new API module

        $api_module_relative_path = '/js/data-form-modules/api.js';

        $api_module_file_path = get_stylesheet_directory() . $api_module_relative_path;

        $api_module_uri = get_stylesheet_directory_uri() . $api_module_relative_path;

    

        wp_enqueue_script(

            'kop-api-script',

            $api_module_uri,

            array('jquery', 'kop-form-config-script', 'kop-data-normalizer-script'),

            file_exists($api_module_file_path) ? filemtime($api_module_file_path) : time(),

            true

        );

    

        // Enqueue location form

        $location_form_relative = '/js/data-form/location-form.js';

        $location_form_path = get_stylesheet_directory() . $location_form_relative;

        wp_enqueue_script(

            'location-form-script',

            get_stylesheet_directory_uri() . $location_form_relative,

            array('jquery'),

            file_exists($location_form_path) ? filemtime($location_form_path) : time(),

            true

        );

    

        // Enqueue referrer form

        $referrer_form_relative = '/js/data-form/referrer-form.js';

        $referrer_form_path = get_stylesheet_directory() . $referrer_form_relative;

        wp_enqueue_script(

            'referrer-form-script',

            get_stylesheet_directory_uri() . $referrer_form_relative,

            array('jquery', 'utilities-module-script', 'autocomplete-module-script'),

            file_exists($referrer_form_path) ? filemtime($referrer_form_path) : time(),

            true

        );

    

        // Enqueue notes module

        $notes_module_relative = '/js/data-form/notes.js';

        $notes_module_path = get_stylesheet_directory() . $notes_module_relative;

        wp_enqueue_script(

            'notes-module-script',

            get_stylesheet_directory_uri() . $notes_module_relative,

            array('jquery'),

            file_exists($notes_module_path) ? filemtime($notes_module_path) : time(),

            true

        );

    

        // Enqueue report generator

        $report_generator_relative = '/js/data-form/data-report-generator.js';

        $report_generator_path = get_stylesheet_directory() . $report_generator_relative;

        wp_enqueue_script(

            'data-report-generator',

            get_stylesheet_directory_uri() . $report_generator_relative,

            array('jquery'),

            file_exists($report_generator_path) ? filemtime($report_generator_path) : time(),

            true

        );

    

        // Enqueue project module

        $project_module_relative_path = '/js/data-form-modules/project.js';

        $project_module_file_path = get_stylesheet_directory() . $project_module_relative_path;

        $project_module_uri = get_stylesheet_directory_uri() . $project_module_relative_path;

    

        wp_enqueue_script(

            'kop-project-script',

            $project_module_uri,

            array('jquery', 'kop-api-script'),

            file_exists($project_module_file_path) ? filemtime($project_module_file_path) : time(),

            true

        );

    

        // Enqueue UI modules

        /*

        $ui_modules = ['tab-manager', 'form-population', 'event-handlers'];

        $ui_deps = ['jquery', 'kop-form-config-script', 'kop-project-script'];

    

        foreach ($ui_modules as $module) {

            $module_relative_path = "/js/data-form-modules/ui.{$module}.js";

            $module_file_path = get_stylesheet_directory() . $module_relative_path;

            $module_uri = get_stylesheet_directory_uri() . $module_relative_path;

    

            wp_enqueue_script(

                "kop-ui-{$module}-script",

                $module_uri,

                $ui_deps,

                file_exists($module_file_path) ? filemtime($module_file_path) : time(),

                true

            );

            $ui_deps[] = "kop-ui-{$module}-script"; // Each subsequent module depends on previous ones

        }

        */

        

        // Enqueue UI Render module (Core dependency)

        $ui_render_path = '/js/data-form-modules/ui-render.js';

        $ui_render_file = get_stylesheet_directory() . $ui_render_path;

        $ui_render_uri = get_stylesheet_directory_uri() . $ui_render_path;

        

        wp_enqueue_script(

            'kop-ui-render-script',

            $ui_render_uri,

            array('jquery'),

            file_exists($ui_render_file) ? filemtime($ui_render_file) : time(),

            true

        );

    

        $ui_deps = ['jquery', 'kop-form-config-script', 'kop-project-script', 'kop-ui-render-script'];

    

        $data_page_relative_path = '/js/data-form-modules/data-page.js';

        $data_page_file_path = get_stylesheet_directory() . $data_page_relative_path;

        $data_page_uri = get_stylesheet_directory_uri() . $data_page_relative_path;



        wp_enqueue_script(

            'kop-data-page-script',

            $data_page_uri,

            array_merge($ui_deps, ['kop-ui-render-script']), // Ensure UI Render is a direct dependency

            file_exists($data_page_file_path) ? filemtime($data_page_file_path) : time(),

            true

        );



        // Enqueue the new admin data page module (if on admin page)

        if ($is_admin_template) {

            $admin_page_relative_path = '/js/data-form-modules/admin-data-page.js';

            $admin_page_file_path = get_stylesheet_directory() . $admin_page_relative_path;

            $admin_page_uri = get_stylesheet_directory_uri() . $admin_page_relative_path;



                    wp_enqueue_script(



                        'kop-admin-data-page-script',



                        $admin_page_uri,



                        array_merge($ui_deps, ['data-report-generator', 'kop-ui-render-script', 'kop-data-page-script']),



                        file_exists($admin_page_file_path) ? filemtime($admin_page_file_path) : time(),



                        true



                    );

        }

    

                // Enqueue legacy script to handle remaining functionality until fully migrated

    

                $legacy_script_relative = '/js/data-form/data-form.v4.js';

    

                $legacy_script_path = get_stylesheet_directory() . $legacy_script_relative;

    

                wp_enqueue_script(

    

                    'data-form-legacy',

    

                    get_stylesheet_directory_uri() . $legacy_script_relative,

    

                    array('jquery', 'utilities-module-script', 'location-form-script', 'referrer-form-script', 'notes-module-script', 'data-report-generator', 'kop-ui-render-script'),

    

                    file_exists($legacy_script_path) ? filemtime($legacy_script_path) : time(),

    

                    true

    

                );

    

        

    

            // Enqueue data toolbar script

    

            $toolbar_script_relative = '/js/data-form/data-toolbar.js';

    

            $toolbar_script_path = get_stylesheet_directory() . $toolbar_script_relative;

    

            wp_enqueue_script(

    

                'kop-data-toolbar-script',

    

                get_stylesheet_directory_uri() . $toolbar_script_relative,

    

                array('jquery', 'kop-project-script'),

    

                file_exists($toolbar_script_path) ? filemtime($toolbar_script_path) : time(),

    

                true

    

            );

    

        

    

            $data_form_config = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => esc_url_raw(rest_url('kop/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'isAdmin' => current_user_can('manage_options'),
                'isMockData' => false,
                'mode' => $is_admin_template ? 'admin' : 'suggestion',
                'api' => array(
                    'root' => esc_url_raw(rest_url('kop/v1/')),
                    'nonce' => wp_create_nonce('wp_rest')
                )
            );

            wp_localize_script(
                'db-form-loader',
                'KOP_DATA_FORM_CONFIG',
                $data_form_config
            );

            wp_localize_script(

                'kop-form-config-script',

                'dataFormConfig',
                $data_form_config
            );
        }
add_action('wp_enqueue_scripts', 'enqueue_data_form_script');

/**
 * Enqueue assets for the News Processor page.
 */
function enqueue_news_processor_scripts() {
    // Only run on the news processor page template
    if (!is_page_template('page-news-processor.php')) {
        return;
    }

    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    // Enqueue Styles
    $style_path = '/css/news-processor.css';
    if (file_exists($theme_dir . $style_path)) {
        wp_enqueue_style(
            'news-processor-styles',
            $theme_uri . $style_path,
            array('kop-colors'),
            filemtime($theme_dir . $style_path)
        );
    }

    // Enqueue Scripts
    $script_path = '/js/news-processor.js';
    if (file_exists($theme_dir . $script_path)) {
        wp_enqueue_script(
            'news-processor-script',
            $theme_uri . $script_path,
            array('jquery'),
            filemtime($theme_dir . $script_path),
            true
        );

        // Localize script with API details
        wp_localize_script(
            'news-processor-script',
            'newsProcessorConfig',
            array(
                'apiEndpoint' => $theme_uri . '/api/process-news-ai.php',
                'submissionEndpoint' => $theme_uri . '/api/save-news-submission.php',
                'nonce' => wp_create_nonce('news_processor_nonce')
            )
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_news_processor_scripts');

/**
 * Enqueue scripts for TTI Program Index page
 */
function enqueue_tti_processor_scripts() {
    if (!is_page_template('page-tti-program-index.php')) {
        return;
    }

    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    // Styles
    wp_enqueue_style(
        'tti-program-index-styles',
        $theme_uri . '/css/tti-program-index.css',
        array('kadence-parent-style', 'kop-colors'),
        file_exists($theme_dir . '/css/tti-program-index.css') ? filemtime($theme_dir . '/css/tti-program-index.css') : time()
    );

    // Scripts
    wp_enqueue_script(
        'tti-program-index-script',
        $theme_uri . '/js/tti-program-index.js',
        array('jquery', 'facilities-display'), // Depends on the display logic
        file_exists($theme_dir . '/js/tti-program-index.js') ? filemtime($theme_dir . '/js/tti-program-index.js') : time(),
        true
    );

    // Localize
    wp_localize_script(
        'tti-program-index-script',
        'ttiIndexConfig',
        array(
            'isAdmin' => current_user_can('manage_options'),
            'restUrl' => esc_url_raw(rest_url('kop/v1/'))
        )
    );
}
add_action('wp_enqueue_scripts', 'enqueue_tti_processor_scripts');

/**
 * Enqueue the wiki editor generator assets when its template is used.
 */
function kop_enqueue_wiki_editor_assets() {
    if (!is_page_template('page-wiki-editor.php')) {
        return;
    }

    kop_enqueue_autocomplete_dependencies();

    $style_relative = '/css/wiki-editor.css';
    $style_path = get_stylesheet_directory() . $style_relative;
    wp_enqueue_style(
        'kop-wiki-editor-style',
        get_stylesheet_directory_uri() . $style_relative,
        array('kop-colors'),
        file_exists($style_path) ? filemtime($style_path) : time()
    );

    // Enqueue auto-linker first (wiki-editor depends on it)
    $autolinker_relative = '/js/auto-linker.js';
    $autolinker_path = get_stylesheet_directory() . $autolinker_relative;
    wp_enqueue_script(
        'kop-auto-linker-script',
        get_stylesheet_directory_uri() . $autolinker_relative,
        array(),
        file_exists($autolinker_path) ? filemtime($autolinker_path) : time(),
        true
    );

    // Enqueue wiki-parser (wiki-editor depends on it)
    $parser_relative = '/js/wiki-parser.js';
    $parser_path = get_stylesheet_directory() . $parser_relative;
    wp_enqueue_script(
        'kop-wiki-parser-script',
        get_stylesheet_directory_uri() . $parser_relative,
        array(),
        file_exists($parser_path) ? filemtime($parser_path) : time(),
        true
    );

    // Enqueue wiki-generation (wiki-editor depends on it)
    $generation_relative = '/js/wiki-generation.js';
    $generation_path = get_stylesheet_directory() . $generation_relative;
    wp_enqueue_script(
        'kop-wiki-generation-script',
        get_stylesheet_directory_uri() . $generation_relative,
        array('kop-wiki-parser-script'),
        file_exists($generation_path) ? filemtime($generation_path) : time(),
        true
    );

    $script_relative = '/js/wiki-editor.js';
    $script_path = get_stylesheet_directory() . $script_relative;
    wp_enqueue_script(
        'kop-wiki-editor-script',
        get_stylesheet_directory_uri() . $script_relative,
        array('kop-auto-linker-script', 'kop-wiki-parser-script', 'kop-wiki-generation-script', 'autocomplete-module-script'),
        file_exists($script_path) ? filemtime($script_path) : time(),
        true
    );
    wp_localize_script(
        'kop-wiki-editor-script',
        'wikiEditorSettings',
        array(
            'isAdmin' => current_user_can('manage_options'),
        )
    );
}
add_action('wp_enqueue_scripts', 'kop_enqueue_wiki_editor_assets');

function kop_enqueue_document_library_assets() {
    // Check if we are on a page with the document library shortcode or template
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'kop_document_library') || is_page('document-library'))) {
        
        $theme_uri = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();

        // Enqueue Styles
        $style_path = '/css/document-library.css';
        if (file_exists($theme_dir . $style_path)) {
            wp_enqueue_style(
                'kop-document-library-style',
                $theme_uri . $style_path,
                array('kop-colors'),
                filemtime($theme_dir . $style_path)
            );
        }

        // Enqueue Scripts
        $script_path = '/js/document-library.js';
        if (file_exists($theme_dir . $script_path)) {
            wp_enqueue_script(
                'kop-document-library-script',
                $theme_uri . $script_path,
                array('jquery'),
                filemtime($theme_dir . $script_path),
                true
            );

            // Localize script
            wp_localize_script(
                'kop-document-library-script',
                'docLibraryConfig',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('kop_doc_library_nonce')
                )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'kop_enqueue_document_library_assets');
