<?php
/**
 * Utility helper functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determine whether the current request is for a headerless layout.
 * This is used to conditionally remove the default theme header/footer
 * when the data form pages are being displayed.
 *
 * @return bool
 */
function kop_is_headerless_layout() {
    // Check for page templates (multiple possible paths/names)
    return is_page_template('page-admin-data.php') 
        || is_page_template('page-data.php')
        || is_page_template('page-data-test.php')
        || is_page_template('templates/data-form-public.php') 
        || is_page_template('templates/data-form-admin.php');
}

/**
 * Determine whether the current request targets the TTI Program Index page.
 *
 * @return bool
 */
function kop_is_tti_program_index_context() {
    // Check if using the page template (preferred method)
    if (is_page_template('page-tti-program-index.php')) {
        return true;
    }

    // Fallback to slug check for backwards compatibility
    if (function_exists('is_page') && is_page(array('tti-program-index'))) {
        return true;
    }

    if (function_exists('get_post')) {
        $post = get_post();
        if ($post && isset($post->post_name) && $post->post_name === 'tti-program-index') {
            return true;
        }
    }

    if (function_exists('get_post_field')) {
        $slug = get_post_field('post_name');
        if (is_string($slug) && $slug === 'tti-program-index') {
            return true;
        }
    }

    if (function_exists('get_queried_object')) {
        $queried = get_queried_object();
        if ($queried && isset($queried->post_name) && $queried->post_name === 'tti-program-index') {
            return true;
        }
    }

    global $post;
    if (isset($post) && isset($post->post_name) && $post->post_name === 'tti-program-index') {
        return true;
    }

    return false;
}
