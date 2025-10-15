<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!isset($GLOBALS['kidsoverprofits_test_localizations']) || !is_array($GLOBALS['kidsoverprofits_test_localizations'])) {
    $GLOBALS['kidsoverprofits_test_localizations'] = array();
}

/**
 * Record localized script data so debugging tools can inspect the
 * configuration that was passed to front-end scripts.
 *
 * @param string $handle Script handle.
 * @param string $name   Localized object name.
 * @param mixed  $data   Data assigned to the localized object.
 */
function kidsoverprofits_register_test_localization($handle, $name, $data) {
    global $kidsoverprofits_test_localizations;

    if (!is_array($kidsoverprofits_test_localizations)) {
        $kidsoverprofits_test_localizations = array();
    }

    $kidsoverprofits_test_localizations[] = array(
        'handle' => $handle,
        'name'   => $name,
        'data'   => $data,
    );
}

/**
 * Build a simple context array describing the current front-end request.
 *
 * @return array
 */
function kidsoverprofits_get_current_page_context() {
    $context = array(
        'id'    => 0,
        'slug'  => '',
        'title' => '',
        'type'  => '',
    );

    if (is_admin()) {
        return $context;
    }

    if (is_page()) {
        $page_id = get_queried_object_id();

        if ($page_id) {
            $context['id']    = (int) $page_id;
            $context['slug']  = get_post_field('post_name', $page_id) ?: '';
            $context['title'] = get_the_title($page_id) ?: '';
            $context['type']  = 'page';
        }

        return $context;
    }

    if (is_singular()) {
        $object = get_queried_object();

        if ($object && !empty($object->post_name)) {
            $context['id']    = (int) $object->ID;
            $context['slug']  = $object->post_name;
            $context['title'] = get_the_title($object) ?: '';
            $context['type']  = 'singular';
        }

        return $context;
    }

    if (is_archive()) {
        $context['type']  = 'archive';
        $context['title'] = wp_get_document_title();
    } elseif (is_home()) {
        $context['type']  = 'home';
        $context['title'] = wp_get_document_title();
    } else {
        $context['type']  = 'other';
        $context['title'] = wp_get_document_title();
    }

    $context['slug'] = get_query_var('pagename', '') ?: '';

    return $context;
}

/**
 * Return the definitions used by the CSS diagnostic tool.
 *
 * @return array
 */
function kidsoverprofits_get_css_test_definitions() {
    return array(
        'kidsoverprofits-common' => array(
            'label' => __('Common UI Styles', 'kidsoverprofits'),
            'source' => 'css/common.css',
            'selectors' => array(
                array(
                    'description'    => __('Primary button background color', 'kidsoverprofits'),
                    'selector'       => '.btn',
                    'property'       => 'background-color',
                    'invalidValues'  => array('rgba(0, 0, 0, 0)', 'transparent', ''),
                    'markup'         => '<button class="btn" type="button">Test</button>',
                ),
                array(
                    'description'    => __('Privacy notice border radius', 'kidsoverprofits'),
                    'selector'       => '.privacy-notice',
                    'property'       => 'border-radius',
                    'invalidValues'  => array('0px', ''),
                    'markup'         => '<div class="privacy-notice">Notice</div>',
                ),
            ),
        ),
        'kidsoverprofits-layout' => array(
            'label' => __('Layout & Containers', 'kidsoverprofits'),
            'source' => 'css/layout.css',
            'selectors' => array(
                array(
                    'description'    => __('Admin container max width', 'kidsoverprofits'),
                    'selector'       => '.container',
                    'property'       => 'max-width',
                    'invalidValues'  => array('none', '0px', ''),
                    'markup'         => '<div class="container"></div>',
                ),
                array(
                    'description'    => __('Facility alphabet filter flex layout', 'kidsoverprofits'),
                    'selector'       => '.facility-report-container .alphabet-filter',
                    'property'       => 'display',
                    'expectedValue'  => 'flex',
                    'markup'         => '<div class="facility-report-container"><div class="alphabet-filter"></div></div>',
                ),
            ),
        ),
        'kidsoverprofits-forms' => array(
            'label' => __('Form Utilities', 'kidsoverprofits'),
            'source' => 'css/forms.css',
            'selectors' => array(
                array(
                    'description'    => __('Utility flex helper', 'kidsoverprofits'),
                    'selector'       => '.d-flex',
                    'property'       => 'display',
                    'expectedValue'  => 'flex',
                    'markup'         => '<div class="d-flex"></div>',
                ),
                array(
                    'description'    => __('Form field border radius', 'kidsoverprofits'),
                    'selector'       => '.input-form',
                    'property'       => 'border-radius',
                    'invalidValues'  => array('0px', ''),
                    'markup'         => '<input class="input-form" type="text" />',
                ),
            ),
        ),
        'kidsoverprofits-tables' => array(
            'label' => __('Table of Contents Styling', 'kidsoverprofits'),
            'source' => 'css/tables.css',
            'selectors' => array(
                array(
                    'description'    => __('TOC widget background', 'kidsoverprofits'),
                    'selector'       => '.widget_toc',
                    'property'       => 'background-color',
                    'expectedValue'  => 'rgb(255, 255, 255)',
                    'markup'         => '<div class="widget_toc"></div>',
                ),
                array(
                    'description'    => __('TOC toggle border styling', 'kidsoverprofits'),
                    'selector'       => '.ez-toc-toggle',
                    'property'       => 'border-style',
                    'invalidValues'  => array('none', ''),
                    'markup'         => '<button class="ez-toc-toggle">Toggle</button>',
                ),
            ),
        ),
        'kidsoverprofits-modals' => array(
            'label' => __('Modal Overlay Styles', 'kidsoverprofits'),
            'source' => 'css/modals.css',
            'selectors' => array(
                array(
                    'description'    => __('Notes modal overlay background', 'kidsoverprofits'),
                    'selector'       => '.notes-modal',
                    'property'       => 'background-color',
                    'expectedValue'  => 'rgba(0, 0, 0, 0.5)',
                    'markup'         => '<div class="notes-modal"></div>',
                ),
                array(
                    'description'    => __('Notes modal position', 'kidsoverprofits'),
                    'selector'       => '.notes-modal',
                    'property'       => 'position',
                    'expectedValue'  => 'fixed',
                    'markup'         => '<div class="notes-modal"></div>',
                ),
            ),
        ),
        'kidsoverprofits-admin' => array(
            'label' => __('Admin Interface Enhancements', 'kidsoverprofits'),
            'source' => 'css/admin.css',
            'selectors' => array(
                array(
                    'description'    => __('Admin header background', 'kidsoverprofits'),
                    'selector'       => '.admin-header',
                    'property'       => 'background-color',
                    'expectedValue'  => 'rgb(239, 144, 52)',
                    'markup'         => '<div class="admin-header"><h1>Title</h1></div>',
                ),
                array(
                    'description'    => __('Success status message background', 'kidsoverprofits'),
                    'selector'       => '.status-message.success',
                    'property'       => 'background-color',
                    'expectedValue'  => 'rgb(236, 253, 245)',
                    'markup'         => '<div class="status-message success"></div>',
                ),
            ),
        ),
    );
}

/**
 * Map expected CSS handles for key pages.
 *
 * @return array
 */
function kidsoverprofits_get_css_expected_handles_map() {
    return array(
        'admin-data'     => array('kidsoverprofits-common', 'kidsoverprofits-layout', 'kidsoverprofits-forms', 'kidsoverprofits-tables', 'kidsoverprofits-modals', 'kidsoverprofits-admin'),
        'data'           => array('kidsoverprofits-common', 'kidsoverprofits-layout', 'kidsoverprofits-forms', 'kidsoverprofits-tables', 'kidsoverprofits-modals', 'kidsoverprofits-admin'),
        'data-organizer' => array('kidsoverprofits-common', 'kidsoverprofits-layout', 'kidsoverprofits-forms', 'kidsoverprofits-tables', 'kidsoverprofits-admin'),
    );
}

/**
 * Determine which CSS handles should be evaluated for the current request.
 *
 * @param array $context Page context.
 *
 * @return array
 */
function kidsoverprofits_get_css_test_plan($context) {
    $definitions     = kidsoverprofits_get_css_test_definitions();
    $expected_map    = kidsoverprofits_get_css_expected_handles_map();
    $expected_handles = array();

    if (!empty($context['slug']) && isset($expected_map[$context['slug']])) {
        $expected_handles = $expected_map[$context['slug']];
    }

    $handles_to_report = array();

    if (!empty($expected_handles)) {
        $handles_to_report = $expected_handles;
    }

    $styles = wp_styles();

    if ($styles && is_array($styles->queue)) {
        foreach ($styles->queue as $handle) {
            if (isset($definitions[$handle]) && !in_array($handle, $handles_to_report, true)) {
                $handles_to_report[] = $handle;
            }
        }
    }

    $plan = array();

    foreach ($handles_to_report as $handle) {
        if (!isset($definitions[$handle])) {
            continue;
        }

        $definition = $definitions[$handle];
        $is_enqueued = wp_style_is($handle, 'enqueued');

        $plan[] = array(
            'handle'  => $handle,
            'label'   => $definition['label'],
            'source'  => $definition['source'],
            'status'  => array(
                'expected'   => in_array($handle, $expected_handles, true),
                'isEnqueued' => $is_enqueued,
            ),
            'selectors' => $definition['selectors'],
        );
    }

    return $plan;
}

/**
 * Definitions for JavaScript handles that should appear on specific pages.
 *
 * @return array
 */
function kidsoverprofits_get_script_test_definitions() {
    return array(
        'ca-reports-display'       => array('label' => __('California reports loader', 'kidsoverprofits'), 'source' => 'js/ca-reports.js'),
        'ut-reports-display'       => array('label' => __('Utah reports loader', 'kidsoverprofits'), 'source' => 'js/ut_reports.js'),
        'az-reports-display'       => array('label' => __('Arizona reports loader', 'kidsoverprofits'), 'source' => 'js/az_reports.js'),
        'tx-reports-display'       => array('label' => __('Texas reports loader', 'kidsoverprofits'), 'source' => 'js/tx_reports.js'),
        'mt-reports-display'       => array('label' => __('Montana reports loader', 'kidsoverprofits'), 'source' => 'js/mt_reports.js'),
        'ct-reports-display'       => array('label' => __('Connecticut reports loader', 'kidsoverprofits'), 'source' => 'js/ct_reports.js'),
        'wa-reports-display'       => array('label' => __('Washington reports loader', 'kidsoverprofits'), 'source' => 'js/wa_reports.js'),
        'facilities-display'       => array('label' => __('TTI program index loader', 'kidsoverprofits'), 'source' => 'js/facilities-display.js'),
        'kidsoverprofits-app-logic' => array('label' => __('Facility data core utilities', 'kidsoverprofits'), 'source' => 'js/app-logic.js'),
        'kidsoverprofits-utilities' => array('label' => __('Shared utility helpers', 'kidsoverprofits'), 'source' => 'js/utilities.js'),
        'kidsoverprofits-facility-form' => array('label' => __('Facility form UI', 'kidsoverprofits'), 'source' => 'js/facility-form.v3.js'),
        'kidsoverprofits-admin-data' => array('label' => __('Admin data dashboard', 'kidsoverprofits'), 'source' => 'js/admin-data.js'),
        'kidsoverprofits-data-form' => array('label' => __('Public data submission form', 'kidsoverprofits'), 'source' => 'js/data-form.js'),
        'kidsoverprofits-data-organizer' => array('label' => __('Data organizer interface', 'kidsoverprofits'), 'source' => 'js/data-organizer.js'),
    );
}

/**
 * Map page slugs to the script handles they are expected to load.
 *
 * @return array
 */
function kidsoverprofits_get_script_expected_handles_map() {
    return array(
        'ca-reports'         => array('ca-reports-display'),
        'ut-reports'         => array('ut-reports-display'),
        'az-reports'         => array('az-reports-display'),
        'tx-reports'         => array('tx-reports-display'),
        'mt-reports'         => array('mt-reports-display'),
        'ct-reports'         => array('ct-reports-display'),
        'wa-reports'         => array('wa-reports-display'),
        'tti-program-index'  => array('facilities-display'),
        'admin-data'         => array('kidsoverprofits-app-logic', 'kidsoverprofits-utilities', 'kidsoverprofits-facility-form', 'kidsoverprofits-admin-data'),
        'data'               => array('kidsoverprofits-app-logic', 'kidsoverprofits-utilities', 'kidsoverprofits-facility-form', 'kidsoverprofits-data-form'),
        'data-organizer'     => array('kidsoverprofits-app-logic', 'kidsoverprofits-data-organizer'),
    );
}

/**
 * Build the script status list for the current request.
 *
 * @param array $context Page context.
 *
 * @return array
 */
function kidsoverprofits_get_script_test_plan($context) {
    $definitions      = kidsoverprofits_get_script_test_definitions();
    $expected_map     = kidsoverprofits_get_script_expected_handles_map();
    $expected_handles = array();

    if (!empty($context['slug']) && isset($expected_map[$context['slug']])) {
        $expected_handles = $expected_map[$context['slug']];
    }

    $handles_to_report = $expected_handles;
    $scripts           = wp_scripts();

    if ($scripts && is_array($scripts->queue)) {
        foreach ($scripts->queue as $handle) {
            if (isset($definitions[$handle]) && !in_array($handle, $handles_to_report, true)) {
                $handles_to_report[] = $handle;
            }
        }
    }

    $plan = array();

    foreach ($handles_to_report as $handle) {
        $definition = isset($definitions[$handle]) ? $definitions[$handle] : array(
            'label'  => $handle,
            'source' => '',
        );

        $plan[] = array(
            'handle'      => $handle,
            'label'       => $definition['label'],
            'source'      => $definition['source'],
            'expected'    => in_array($handle, $expected_handles, true),
            'isEnqueued'  => wp_script_is($handle, 'enqueued'),
        );
    }

    return $plan;
}

/**
 * Summarise localized script data for debugging panels.
 *
 * @return array
 */
function kidsoverprofits_get_test_localization_summaries() {
    global $kidsoverprofits_test_localizations;

    if (empty($kidsoverprofits_test_localizations) || !is_array($kidsoverprofits_test_localizations)) {
        return array();
    }

    $summaries = array();

    foreach ($kidsoverprofits_test_localizations as $entry) {
        if (empty($entry['name'])) {
            continue;
        }

        $data = $entry['data'];
        $keys = array();

        if (is_array($data)) {
            $keys = array_keys($data);
        }

        $summaries[] = array(
            'handle' => $entry['handle'],
            'name'   => $entry['name'],
            'keys'   => $keys,
            'data'   => $data,
        );
    }

    return $summaries;
}

/**
 * Safely determine the current URL without the debug parameter.
 *
 * @return string
 */
function kidsoverprofits_get_current_url_without_debug() {
    $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '';
    $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

    if (empty($host)) {
        return '';
    }

    $scheme = is_ssl() ? 'https://' : 'http://';
    $url    = $scheme . $host . $uri;

    return remove_query_arg('debug', $url);
}

/**
 * Build the configuration object that will be exposed to the front-end tests.
 *
 * @return array
 */
function kidsoverprofits_build_test_config() {
    $context       = kidsoverprofits_get_current_page_context();
    $css_plan      = kidsoverprofits_get_css_test_plan($context);
    $script_plan   = kidsoverprofits_get_script_test_plan($context);
    $localizations = kidsoverprofits_get_test_localization_summaries();

    return array(
        'page'         => array(
            'id'    => $context['id'],
            'slug'  => $context['slug'],
            'title' => $context['title'],
            'type'  => $context['type'],
            'url'   => kidsoverprofits_get_current_url_without_debug(),
        ),
        'generatedAt'  => current_time('mysql'),
        'css'          => $css_plan,
        'scripts'      => $script_plan,
        'localizations'=> $localizations,
    );
}
