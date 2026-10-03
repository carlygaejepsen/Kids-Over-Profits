<?php
/**
 * Pictures in editor content fill the text column or sit centred in it
 * (css/content-images.css). Marks each content <img> at least
 * KOP_CONTENT_IMAGE_FILL_MIN pixels wide with .kop-img-fill, so a large
 * photo stretches to the column and a small logo or icon is centred at its
 * own size instead of being blown up. Width comes from the tag's width
 * attribute, else the attachment's metadata.
 *
 * Tested by scripts/test-content-images.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_CONTENT_IMAGE_FILL_MIN = 640;

/** The <img> tag with .kop-img-fill added when the picture is wide enough. */
function kop_content_image_mark_fill($image, $attachment_id = 0) {
    if (!is_string($image) || stripos($image, '<img') !== 0) return $image;
    if (preg_match('/\bclass\s*=\s*(["\'])([^"\']*)\1/i', $image, $cls)) {
        $classes = ' ' . $cls[2] . ' ';
        if (strpos($classes, ' kop-img-fill ') !== false) return $image;
        // A floated picture keeps its size beside the text.
        if (preg_match('/\balign(left|right)\b/', $classes)) return $image;
    }
    $width = 0;
    if (preg_match('/\bwidth\s*=\s*(["\']?)(\d+)\1/i', $image, $m)) {
        $width = (int) $m[2];
    } elseif ($attachment_id > 0 && function_exists('wp_get_attachment_metadata')) {
        $meta = wp_get_attachment_metadata((int) $attachment_id);
        $width = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
    }
    if ($width < KOP_CONTENT_IMAGE_FILL_MIN) return $image;
    if (!empty($cls)) {
        return preg_replace('/\bclass\s*=\s*(["\'])([^"\']*)\1/i', 'class=$1$2 kop-img-fill$1', $image, 1);
    }
    return preg_replace('/^<img\b/i', '<img class="kop-img-fill"', $image, 1);
}

add_filter('wp_content_img_tag', function ($image, $context, $attachment_id) {
    return kop_content_image_mark_fill($image, (int) $attachment_id);
}, 10, 3);

add_action('wp_enqueue_scripts', function () {
    $path = get_stylesheet_directory() . '/css/content-images.css';
    if (file_exists($path)) {
        wp_enqueue_style('kop-content-images', get_stylesheet_directory_uri() . '/css/content-images.css', array(), filemtime($path));
    }
}, 20);
