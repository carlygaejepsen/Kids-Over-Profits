<?php
/**
 * Articles keep two links: the real one (news_submissions.article_url) and an
 * archived copy (news_submissions.archive_url). Rules in api/lib-news-archive.php;
 * js/news-archive-links.js draws the same "archived copy" link in the browser.
 *
 * Once per KOP_NEWS_ARCHIVE_VERSION: add the column, then split the rows whose
 * only link is an archive copy (a Wayback link gives up the address inside it).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/lib-news-archive.php';

const KOP_NEWS_ARCHIVE_VERSION = '1';

add_action('init', function () {
    if (get_option('kop_news_archive_version') === KOP_NEWS_ARCHIVE_VERSION) return;
    if (get_transient('kop_news_archive_running')) return;
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) return;
    set_transient('kop_news_archive_running', 1, 5 * MINUTE_IN_SECONDS);
    try {
        if (!kop_news_archive_ensure($pdo)) return;
        $r = kop_news_archive_split_existing($pdo, true);
        update_option('kop_news_archive_version', KOP_NEWS_ARCHIVE_VERSION, false);
        update_option('kop_news_archive_split_log', array(
            'at' => gmdate('c'), 'checked' => $r['checked'], 'changed' => $r['changed'],
        ), false);
    } catch (Throwable $e) {
        error_log('kop news archive split failed: ' . $e->getMessage());
    } finally {
        delete_transient('kop_news_archive_running');
    }
}, 20);

add_action('wp_enqueue_scripts', function () {
    $path = get_stylesheet_directory() . '/js/news-archive-links.js';
    if (file_exists($path)) {
        wp_enqueue_script('kop-news-archive-links', get_stylesheet_directory_uri() . '/js/news-archive-links.js', array(), filemtime($path), true);
    }
    wp_register_style('kop-news-archive-links', false, array(), KOP_NEWS_ARCHIVE_VERSION);
    wp_enqueue_style('kop-news-archive-links');
    wp_add_inline_style('kop-news-archive-links',
        '.kop-archived-link{font-size:.85em;font-weight:400;white-space:nowrap;color:inherit;text-decoration:underline}'
        . '.kop-archived-link:hover,.kop-archived-link:focus{color:inherit;text-decoration:none}'
        . '.kop-archived-link::before{content:"(";text-decoration:none;display:inline-block}'
        . '.kop-archived-link::after{content:")";text-decoration:none;display:inline-block}');
}, 1);
