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
/**
 * Does this attachment's file actually exist on the server?
 *
 * 66 of the 2,036 attachment records pointing at the uploads root have no file
 * on disk: rows that survived the staging-to-production move while the bytes
 * did not. Those render as a broken image over a dead download link.
 *
 * @param int $attachment_id
 * @return bool True when the file is missing.
 */
function kop_attachment_file_missing($attachment_id) {
    $path = get_attached_file((int) $attachment_id);
    return !($path && file_exists($path));
}

/**
 * Another attachment holding the same file, for a record whose own file is gone.
 *
 * Some documents exist twice: the real upload under a dated folder, plus a
 * later record pointing at a root path that never held a file (for example
 * 2024/08/Overt-Covert-Conversion-Therapy.pdf and the empty root-level twin
 * created on 2025-09-30). Serve the copy that is really there.
 *
 * @param string $file Attached file path of the broken record.
 * @return int Attachment ID of a copy whose file is present, or 0.
 */
function kop_find_live_attachment_copy($file) {
    global $wpdb;

    $base = basename((string) $file);
    if ($base === '') {
        return 0;
    }

    $candidates = $wpdb->get_col($wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'
           AND pm.meta_value LIKE %s
         LIMIT 10",
        '%' . $wpdb->esc_like($base)
    ));

    foreach ((array) $candidates as $id) {
        if (!kop_attachment_file_missing((int) $id)) {
            return (int) $id;
        }
    }
    return 0;
}

/**
 * The attachment whose file should actually be served for this record.
 *
 * Returns the record itself when its file is present, a same-named copy that
 * does exist when it is not, and 0 when the file is gone everywhere. Cached
 * per request: a folder listing asks about the same IDs repeatedly.
 *
 * @param int $attachment_id
 * @return int
 */
function kop_resolve_live_attachment($attachment_id) {
    static $cache = array();

    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) {
        return 0;
    }
    if (isset($cache[$attachment_id])) {
        return $cache[$attachment_id];
    }

    if (!kop_attachment_file_missing($attachment_id)) {
        $cache[$attachment_id] = $attachment_id;
    } else {
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $cache[$attachment_id] = kop_find_live_attachment_copy($file);
    }

    return $cache[$attachment_id];
}

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

/**
 * Gate a front-end page template behind a capability.
 *
 * Logged-out visitors are sent to the login screen (and back afterwards)
 * instead of a bare wp_die(), which answers with HTTP 500 and shows up as
 * a server error in the logs every time a crawler hits an admin tool page.
 * Logged-in users without the capability get a proper 403.
 *
 * Call before get_header() so the redirect can still send headers.
 */
function kop_require_page_capability($capability = 'manage_options') {
    if (current_user_can($capability)) {
        return;
    }
    if (!is_user_logged_in()) {
        $target = get_permalink();
        wp_safe_redirect(wp_login_url($target ? $target : home_url('/')));
        exit;
    }
    wp_die(
        'You do not have permission to access this page.',
        'Access Denied',
        array('response' => 403)
    );
}

/**
 * Notice for the record pages (lawsuits, legislation, memorial, volunteers)
 * when the database connection is down. api/config.php leaves $pdo null in
 * that case (see its catch block); the template sets $kop_db_error and calls
 * this under its page title.
 */
function kop_db_unavailable_notice($show = true) {
    if (!$show) {
        return;
    }
    echo '<div class="kop-db-notice" role="alert" style="margin:0.75em 0 0;padding:0.75em 1em;border:1px solid #f3c7c3;background:#fff5f4;border-radius:8px;color:#000435;">'
        . esc_html('Records are temporarily unavailable while the database is offline. Please try again shortly.')
        . '</div>';
}

/**
 * The state inspection trackers, as tracker slug => state name, alphabetical.
 *
 * Derived from kop_state_inspection_page_map() in inc/rest-api.php, which is
 * what the REST layer uses to link a state to its tracker, so a new state is
 * added in one place and appears on the home page, the inspection hub and the
 * state pages together. The fallback list is only reached if this file is
 * loaded without the REST layer.
 *
 * @return array<string,string>
 */
function kop_report_state_links() {
    $map = function_exists('kop_state_inspection_page_map')
        ? kop_state_inspection_page_map()
        : array(
            'Arkansas' => 'ar-reports', 'Arizona' => 'az-reports', 'California' => 'ca-reports',
            'Connecticut' => 'ct-reports', 'Florida' => 'fl-reports', 'Georgia' => 'ga-reports',
            'Minnesota' => 'mn-reports', 'Montana' => 'mt-reports', 'Nevada' => 'nv-reports',
            'North Carolina' => 'nc-reports', 'Oregon' => 'or-reports', 'Texas' => 'tx-reports',
            'Utah' => 'ut-reports', 'Washington' => 'wa-reports',
        );

    $links = array();
    foreach ($map as $state => $slug) {
        $links[(string) $slug] = (string) $state;
    }
    asort($links, SORT_NATURAL | SORT_FLAG_CASE);
    return $links;
}

/**
 * "Oregon, Minnesota and Texas" — the tracker states as a readable sentence
 * fragment, so page copy does not carry a hand-maintained list of them.
 */
function kop_report_state_sentence() {
    $names = array_values(kop_report_state_links());
    if (!$names) {
        return '';
    }
    if (count($names) === 1) {
        return $names[0];
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}
