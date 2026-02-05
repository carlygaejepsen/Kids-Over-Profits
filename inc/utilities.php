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

/**
 * Resolve a preview image URL for an attachment, allowing manual overrides.
 *
 * Supports:
 * - kop_cover_image_id (attachment ID of a cover image)
 * - kop_cover_image_url (direct URL)
 * - _thumbnail_id (attachment's featured image)
 *
 * @param int $attachment_id
 * @param string $size
 * @return string
 */
function kop_get_attachment_preview_url($attachment_id, $size = 'medium') {
    $attachment_id = absint($attachment_id);
    if (!$attachment_id) {
        return '';
    }

    $override_id = absint(get_post_meta($attachment_id, 'kop_cover_image_id', true));
    if ($override_id) {
        $override_url = wp_get_attachment_image_url($override_id, $size);
        if ($override_url) {
            return $override_url;
        }
    }

    $override_url = get_post_meta($attachment_id, 'kop_cover_image_url', true);
    if (is_string($override_url) && $override_url !== '') {
        return esc_url_raw($override_url);
    }

    $thumb_id = absint(get_post_meta($attachment_id, '_thumbnail_id', true));
    if ($thumb_id) {
        $thumb_url = wp_get_attachment_image_url($thumb_id, $size);
        if ($thumb_url) {
            return $thumb_url;
        }
    }

    $url = wp_get_attachment_image_url($attachment_id, $size);
    return $url ? $url : '';
}
