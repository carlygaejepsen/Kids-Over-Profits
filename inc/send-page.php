<?php
/**
 * /send/: "Send to KOP" from any browser, nothing to install.
 *
 * A form for the link of an article, lawsuit, bill or website, posted to the public
 * route kop/v1/mobile/submit (inc/mobile-submit.php) with via = 'web'; a person reviews
 * everything before it appears. /send/?url=&title=&text= prefills the form, so the
 * bookmarklet on the page (and a share link) can send the page being read.
 *
 *   routing   a route, not a WordPress page (like /how-to-use-this-site/)
 *   assets    css/send-page.css + js/send-page.js, on this route only
 *
 * php scripts/test-send-page.php renders the template offline.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_SEND_PAGE_REWRITE_VERSION')) {
    define('KOP_SEND_PAGE_REWRITE_VERSION', '1');
}

if (!function_exists('kop_send_page_base')) {
    function kop_send_page_base() {
        return apply_filters('kop_send_page_base', 'send');
    }
}

if (!function_exists('kop_send_page_url')) {
    function kop_send_page_url() {
        return home_url('/' . kop_send_page_base() . '/');
    }
}

if (!function_exists('kop_send_page_register_rewrite')) {
    function kop_send_page_register_rewrite() {
        add_rewrite_rule('^' . preg_quote(kop_send_page_base(), '#') . '/?$', 'index.php?kop_send_page=1', 'top');
        if (get_option('kop_send_page_rewrite') !== KOP_SEND_PAGE_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_send_page_rewrite', KOP_SEND_PAGE_REWRITE_VERSION);
        }
    }
    add_action('init', 'kop_send_page_register_rewrite', 30);
}

if (!function_exists('kop_send_page_query_vars')) {
    function kop_send_page_query_vars($vars) {
        $vars[] = 'kop_send_page';
        return $vars;
    }
    add_filter('query_vars', 'kop_send_page_query_vars');
}

if (!function_exists('kop_send_page_is_request')) {
    function kop_send_page_is_request($query = null) {
        if ($query === null) {
            return function_exists('get_query_var') && (string) get_query_var('kop_send_page') === '1';
        }
        return (string) $query->get('kop_send_page') === '1';
    }
}

if (!function_exists('kop_send_page_is_page')) {
    /** True while /send/ is being rendered. */
    function kop_send_page_is_page() {
        return !empty($GLOBALS['kop_send_page']);
    }
}

if (!function_exists('kop_send_page_pre_get_posts')) {
    function kop_send_page_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query() || !kop_send_page_is_request($query)) return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    }
    add_action('pre_get_posts', 'kop_send_page_pre_get_posts');
}

if (!function_exists('kop_send_page_posts_pre_query')) {
    function kop_send_page_posts_pre_query($posts, $query) {
        if (is_admin() || !$query->is_main_query() || !kop_send_page_is_request($query)) return $posts;
        return array();
    }
    add_filter('posts_pre_query', 'kop_send_page_posts_pre_query', 10, 2);
}

if (!function_exists('kop_send_page_pre_handle_404')) {
    function kop_send_page_pre_handle_404($preempt, $query) {
        return kop_send_page_is_request($query) ? true : $preempt;
    }
    add_filter('pre_handle_404', 'kop_send_page_pre_handle_404', 10, 2);
}

if (!function_exists('kop_send_page_route')) {
    function kop_send_page_route() {
        if (!kop_send_page_is_request()) return;
        $GLOBALS['kop_send_page'] = array('prefill' => kop_send_page_prefill($_GET));
        status_header(200);
        nocache_headers();
    }
    add_action('template_redirect', 'kop_send_page_route', 0);
}

if (!function_exists('kop_send_page_template_include')) {
    function kop_send_page_template_include($template) {
        if (!kop_send_page_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/send-page.php';
        return file_exists($own) ? $own : $template;
    }
    add_filter('template_include', 'kop_send_page_template_include', 99);
}

if (!function_exists('kop_send_page_body_class')) {
    function kop_send_page_body_class($classes) {
        if (kop_send_page_is_page()) $classes[] = 'kop-send-page-body';
        return $classes;
    }
    add_filter('body_class', 'kop_send_page_body_class');
}

if (!function_exists('kop_send_page_prefill')) {
    /**
     * The query string's url / title / text as plain strings (never HTML); the template
     * and the script escape them where they print them. Only an http(s) link is kept as the url.
     */
    function kop_send_page_prefill($get) {
        $get = is_array($get) ? $get : array();
        $clean = function ($key, $max) use ($get) {
            $v = isset($get[$key]) && is_scalar($get[$key]) ? (string) $get[$key] : '';
            if (function_exists('wp_unslash')) $v = wp_unslash($v);
            $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v));
            return mb_substr($v, 0, $max);
        };
        $url = $clean('url', 2000);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
        return array('url' => $url, 'title' => $clean('title', 500), 'text' => $clean('text', 2000));
    }
}

if (!function_exists('kop_send_page_bookmarklet')) {
    /** The javascript: address that opens /send/ with the current page filled in. */
    function kop_send_page_bookmarklet() {
        return "javascript:(function(){window.open('" . kop_send_page_url()
            . "?url='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title)"
            . "+'&text='+encodeURIComponent(String(getSelection())).slice(0,2000),'kopsend','width=520,height=760')})()";
    }
}

if (!function_exists('kop_send_page_enqueue')) {
    function kop_send_page_enqueue() {
        if (!kop_send_page_is_page()) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        $css = $dir . '/css/send-page.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-send-page', $uri . '/css/send-page.css', array('kop-colors', 'kop-submission-followup'), filemtime($css));
        }
        $js = $dir . '/js/send-page.js';
        if (file_exists($js)) {
            wp_enqueue_script('kop-send-page', $uri . '/js/send-page.js', array('kop-submission-followup'), filemtime($js), true);
            wp_localize_script('kop-send-page', 'kopSendPage', array(
                'submitUrl'  => rest_url('kop/v1/mobile/submit'),
                'checkUrl'   => rest_url('kop/v1/mobile/check'),
                'suggestUrl' => rest_url('kop/v1/facility-suggest'),
                'prefill'    => $GLOBALS['kop_send_page']['prefill'] ?? array(),
            ));
        }
    }
    add_action('wp_enqueue_scripts', 'kop_send_page_enqueue', 20);
}

if (!function_exists('kop_send_page_document_title')) {
    function kop_send_page_document_title($title) {
        return kop_send_page_is_page() ? 'Send to KOP | ' . get_bloginfo('name') : $title;
    }
    add_filter('pre_get_document_title', 'kop_send_page_document_title', 20);
    add_filter('wpseo_title', 'kop_send_page_document_title', 20);
}

if (!function_exists('kop_send_page_robots')) {
    function kop_send_page_robots($robots) {
        return kop_send_page_is_page() ? 'noindex, follow' : $robots;
    }
    add_filter('wpseo_robots', 'kop_send_page_robots', 20);
}
