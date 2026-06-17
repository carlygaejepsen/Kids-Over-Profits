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
    // Check for page templates (root and templates/ paths supported)
    return is_page_template('page-admin-data.php')
        || is_page_template('templates/page-admin-data.php')
        || is_page_template('page-data.php')
        || is_page_template('templates/page-data.php')
        || is_page_template('templates/data-form-public.php')
        || is_page_template('templates/data-form-admin.php');
}

/**
 * Determine whether the current request targets the TTI Program Index page.
 *
 * @return bool
 */
function kop_is_tti_program_index_context() {
    // Check if using the page template (root or templates/ path)
    if (is_page_template('page-tti-program-index.php')
        || is_page_template('templates/page-tti-program-index.php')) {
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

    // Requested size first.
    $url = wp_get_attachment_image_url($attachment_id, $size);
    if ($url) {
        return $url;
    }

    // For PDFs (and any attachment whose requested sub-size wasn't generated)
    // fall back through the other standard sizes. WordPress only returns a URL
    // for a PDF when that exact preview sub-size exists, so a PDF missing its
    // "large" preview would otherwise show no image even when "medium" exists.
    foreach (array('large', 'medium_large', 'medium', 'thumbnail', 'full') as $fallback_size) {
        if ($fallback_size === $size) {
            continue;
        }
        $candidate = wp_get_attachment_image_url($attachment_id, $fallback_size);
        if ($candidate) {
            return $candidate;
        }
    }

    // Last resort: pick the largest generated size actually present in the
    // attachment metadata. Covers custom preview size names added by plugins.
    $meta = wp_get_attachment_metadata($attachment_id);
    if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
        $best_url = '';
        $best_width = -1;
        foreach ($meta['sizes'] as $size_name => $info) {
            $candidate = wp_get_attachment_image_url($attachment_id, $size_name);
            if (!$candidate) {
                continue;
            }
            $width = isset($info['width']) ? (int) $info['width'] : 0;
            if ($width > $best_width) {
                $best_width = $width;
                $best_url = $candidate;
            }
        }
        if ($best_url) {
            return $best_url;
        }
    }

    return '';
}

/**
 * Normalize a label into Title Case while preserving all-caps acronyms.
 *
 * @param string $text
 * @return string
 */
function kop_title_case($text) {
    if (!is_string($text)) {
        return $text;
    }

    $text = trim($text);
    if ($text == '') {
        return $text;
    }

    $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $output = '';

    foreach ($parts as $part) {
        if ($part == '') {
            continue;
        }

        if (preg_match('/^\s+$/u', $part)) {
            $output .= $part;
            continue;
        }

        if (preg_match('/^[A-Z0-9]{2,}$/', $part)) {
            $output .= $part;
            continue;
        }

        if (function_exists('mb_convert_case') && defined('MB_CASE_TITLE')) {
            $output .= mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        } else {
            $output .= ucwords(strtolower($part));
        }
    }

    return $output;
}
