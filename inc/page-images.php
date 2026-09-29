<?php
/**
 * Photos placed on hub and article pages from seeds/page-images.json.
 *
 * Each entry names a page, an image by its source URL (the Wikimedia Commons
 * page the file came from, kept on the attachment as _kop_source_url by
 * api/import-documents.php), and where it goes:
 *
 *   {"page": "orphanages", "heading": null, "source_url": "https://commons..."}
 *     sets the page's featured image
 *   {"page": "orphanages", "heading": "18th Century", "source_url": "..."}
 *     puts an image block, captioned with the attachment's credit line,
 *     right after that heading in the page content
 *
 * The images are imported first (scp + api/import-documents.php, since the
 * repository is public and never holds them), so an entry whose attachment
 * is not in the library yet is skipped and tried again later. The seed counts
 * as applied, by its md5, only once every entry has found its image. Placing
 * is idempotent: a page that already shows the image (featured, or a block
 * with its wp-image-<id> class) is left alone, and content is saved through
 * wp_update_post so each change has a revision to roll back to.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_page_images_seed_path() {
    return trailingslashit(get_stylesheet_directory()) . 'seeds/page-images.json';
}

/** Attachment imported from this source URL, newest first; 0 when none. */
function kop_page_images_attachment($source_url) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT m.post_id FROM {$wpdb->postmeta} m
         JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
         WHERE m.meta_key = '_kop_source_url' AND m.meta_value = %s
         ORDER BY m.post_id DESC LIMIT 1",
        esc_url_raw($source_url)
    ));
}

/** Heading text as a reader sees it, for matching against the seed. */
function kop_page_images_heading_text($html) {
    $text = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8');
    $text = str_replace(array("\u{2013}", "\u{2014}"), '-', $text);
    return strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
}

/**
 * Bump when the block markup below changes: blocks already placed in an
 * older format are rebuilt on the next run.
 */
define('KOP_PAGE_IMAGES_FORMAT', '2');

/**
 * The image block inserted after a heading, captioned with the credit line.
 * Full size, not 'large': this site's large size is capped at 400px tall, so
 * a portrait came out 242px wide. The kop-page-photo class gives every placed
 * photo the same column-wide banner shape (css/article.css).
 */
function kop_page_images_block($attachment_id) {
    $src     = wp_get_attachment_image_url($attachment_id, 'full');
    $alt     = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    $caption = (string) wp_get_attachment_caption($attachment_id);
    $attrs   = wp_json_encode(array('id' => $attachment_id, 'sizeSlug' => 'full', 'linkDestination' => 'none', 'className' => 'kop-page-photo'));
    return "<!-- wp:image {$attrs} -->\n"
        . '<figure class="wp-block-image size-full kop-page-photo"><img src="' . esc_url($src) . '" alt="' . esc_attr($alt)
        . '" class="wp-image-' . (int) $attachment_id . '"/>'
        . ($caption !== '' ? '<figcaption class="wp-element-caption">' . esc_html($caption) . '</figcaption>' : '')
        . "</figure>\n<!-- /wp:image -->";
}

/**
 * Rebuild a block this file placed in an older format. Returns the new
 * content, or null when the block is missing or already current.
 */
function kop_page_images_restyle($content, $attachment_id) {
    $re = '#<!-- wp:image \{"id":' . (int) $attachment_id . ',[^}]*\} -->.*?<!-- /wp:image -->#s';
    if (preg_match_all($re, $content, $m) !== 1 || strpos($m[0][0], 'kop-page-photo') !== false) {
        return null;
    }
    return str_replace($m[0][0], kop_page_images_block($attachment_id), $content);
}

/** Save page content unfiltered (see the note in kop_apply_page_images()). */
function kop_page_images_save($page_id, $content) {
    $kses = has_filter('content_save_pre', 'wp_filter_post_kses');
    if ($kses) {
        kses_remove_filters();
    }
    $saved = wp_update_post(array('ID' => $page_id, 'post_content' => wp_slash($content)), true);
    if ($kses) {
        kses_init_filters();
    }
    return $saved;
}

/**
 * Insert the block after the heading whose text matches. Returns the new
 * content, or null when the heading is not there exactly once.
 */
function kop_page_images_insert_after_heading($content, $heading, $block) {
    $want = kop_page_images_heading_text($heading);
    $re   = '#<!-- wp:heading[^>]*-->\s*<h([1-6])[^>]*>(.*?)</h\1>\s*<!-- /wp:heading -->#s';
    if (!preg_match_all($re, $content, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $hits = array();
    foreach ($m[2] as $i => $inner) {
        if (kop_page_images_heading_text($inner[0]) === $want) {
            $hits[] = $m[0][$i][1] + strlen($m[0][$i][0]);
        }
    }
    if (count($hits) !== 1) {
        return null;
    }
    return substr($content, 0, $hits[0]) . "\n\n" . $block . substr($content, $hits[0]);
}

/** Apply the seed. Returns array('placed' => [...], 'waiting' => [...], 'failed' => [...]). */
function kop_apply_page_images() {
    $out  = array('placed' => array(), 'waiting' => array(), 'failed' => array());
    $spec = json_decode((string) @file_get_contents(kop_page_images_seed_path()), true);
    if (!is_array($spec)) {
        return $out;
    }
    foreach ($spec as $entry) {
        $slug    = (string) ($entry['page'] ?? '');
        $heading = isset($entry['heading']) ? (string) $entry['heading'] : '';
        $label   = $slug . ($heading !== '' ? ' / ' . $heading : ' / featured');
        $page    = $slug !== '' ? get_page_by_path($slug) : null;
        if (!$page) {
            $out['failed'][] = $label . ': no such page';
            continue;
        }
        $attachment_id = kop_page_images_attachment((string) ($entry['source_url'] ?? ''));
        if (!$attachment_id) {
            $out['waiting'][] = $label;
            continue;
        }
        if ($heading === '') {
            if ((int) get_post_thumbnail_id($page) !== $attachment_id) {
                set_post_thumbnail($page, $attachment_id);
                $out['placed'][] = $label;
            }
            continue;
        }
        if (strpos($page->post_content, 'wp-image-' . $attachment_id . '"') !== false) {
            $content = kop_page_images_restyle($page->post_content, $attachment_id);
            if ($content === null) {
                continue;
            }
            $label .= ' (restyled)';
        } else {
            $content = kop_page_images_insert_after_heading($page->post_content, $heading, kop_page_images_block($attachment_id));
            if ($content === null) {
                $out['failed'][] = $label . ': heading not found exactly once';
                continue;
            }
        }
        // The seed usually runs on a visitor's request, where kses would
        // filter the whole page on save and strip markup an editor put
        // there (embeds, iframes). The content is the stored page plus one
        // block built here, so save it unfiltered. wp_update_post expects
        // slashed data.
        $saved = kop_page_images_save($page->ID, $content);
        if (is_wp_error($saved)) {
            $out['failed'][] = $label . ': ' . $saved->get_error_message();
            continue;
        }
        $page->post_content = $content;
        $out['placed'][] = $label;
    }
    return $out;
}

/**
 * Run once per version of the seed on any request, like the template
 * assignments, so a deploy takes effect without anyone opening wp-admin.
 * While images are still waiting to be imported it tries again every ten
 * minutes rather than on every request.
 */
function kop_maybe_apply_page_images() {
    $path = kop_page_images_seed_path();
    if (!file_exists($path)) {
        return;
    }
    $version = md5_file($path) . ':' . KOP_PAGE_IMAGES_FORMAT;
    if (get_option('kop_page_images_applied') === $version || get_transient('kop_page_images_retry') === $version) {
        return;
    }
    $result = kop_apply_page_images();
    if ($result['waiting'] || $result['failed']) {
        set_transient('kop_page_images_retry', $version, 10 * MINUTE_IN_SECONDS);
        update_option('kop_page_images_last_result', $result, false);
        return;
    }
    update_option('kop_page_images_applied', $version);
    update_option('kop_page_images_last_result', $result, false);
}
add_action('init', 'kop_maybe_apply_page_images', 25);
