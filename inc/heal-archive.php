<?php
/**
 * HEAL (heal-online.org) is permanently offline. Every link to it, wherever it
 * is stored or generated (post content, seeded records, resource links, data
 * rendered by scripts), goes to the Wayback Machine copy instead.
 *
 * Three layers, so no link is missed:
 *   1. kop_heal_archive_url() for code that builds a link.
 *   2. An output filter over the whole front-end page (template_redirect), which
 *      rewrites href values in the HTML WordPress prints.
 *   3. js/heal-archive.js, for links a script adds after the page loads.
 *
 * /web/<url> resolves to the newest snapshot Wayback holds for that address.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_heal_archive_url')) {
    /** The Wayback address for a heal-online.org URL; any other URL comes back unchanged. */
    function kop_heal_archive_url($url) {
        $url = trim((string) $url);
        if (!preg_match('#^(?:https?:)?//(?:www\.)?heal-online\.org(?:[/:?\#]|$)#i', $url)) return $url;
        if (strpos($url, '//') === 0) $url = 'http:' . $url;
        return 'https://web.archive.org/web/' . $url;
    }
}

if (!function_exists('kop_heal_archive_html')) {
    /** Rewrite every href that points at heal-online.org in a block of HTML. */
    function kop_heal_archive_html($html) {
        if (!is_string($html) || stripos($html, 'heal-online.org') === false) return $html;
        return preg_replace_callback(
            '#(\bhref\s*=\s*)(["\'])((?:https?:)?//(?:www\.)?heal-online\.org[^"\']*)\2#i',
            static function ($m) {
                return $m[1] . $m[2] . htmlspecialchars(kop_heal_archive_url(html_entity_decode($m[3], ENT_QUOTES)), ENT_QUOTES, 'UTF-8', false) . $m[2];
            },
            $html
        );
    }
}

if (!function_exists('kop_heal_archive_start_buffer')) {
    function kop_heal_archive_start_buffer() {
        if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return;
        ob_start('kop_heal_archive_html');
    }
}

if (function_exists('add_action')) {
    add_action('template_redirect', 'kop_heal_archive_start_buffer', 0);
    add_filter('the_content', 'kop_heal_archive_html', 99);
    add_filter('widget_text', 'kop_heal_archive_html', 99);
    add_filter('widget_block_content', 'kop_heal_archive_html', 99);
    add_filter('render_block', 'kop_heal_archive_html', 99);

    add_action('wp_enqueue_scripts', function () {
        $path = get_stylesheet_directory() . '/js/heal-archive.js';
        if (file_exists($path)) {
            wp_enqueue_script('kop-heal-archive', get_stylesheet_directory_uri() . '/js/heal-archive.js', array(), filemtime($path), true);
        }
    }, 20);
}
