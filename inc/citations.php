<?php
/**
 * Shared rendering for links that cite a source.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Print a concise citation link with a preview from citation data already on
 * the page. This deliberately does not fetch third-party pages.
 */
function kop_citation_link($url, $label, $preview = '', $new_tab = false, $class = '', $nofollow = null) {
    $url = trim((string) $url);
    $label = trim(wp_strip_all_tags((string) $label));
    if ($label === '') {
        $label = 'Source';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return esc_html($label);
    }

    $plain_preview = wp_strip_all_tags((string) $preview);
    $preview = trim(preg_replace('/\s+/u', ' ', $plain_preview) ?: $plain_preview);
    if ($preview === '') {
        $preview = $label;
    }

    $classes = trim('kop-citation-link ' . preg_replace('/[^a-zA-Z0-9 _-]/', '', (string) $class));
    if ($nofollow === null) {
        $nofollow = !$new_tab;
    }
    $rel = ($nofollow ? 'nofollow ' : '') . 'noopener';
    $attrs = ' class="' . esc_attr($classes) . '" data-kop-citation-preview="' . esc_attr($preview) . '"'
        . ' rel="' . esc_attr($rel) . '"';
    if ($new_tab) {
        $attrs .= ' target="_blank"';
    }

    return '<a' . $attrs . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
}
