<?php
/**
 * scripts/test-review-inbox.php checks for inc/review-inbox-menu.php: run
 * over the KOP Tools plugin's own registry, Submissions Review becomes
 * "Review Inbox", every other tool stays in its own category (nothing moves
 * off the sidebar), every category is a sidebar category, and the inbox's
 * "All tools" tab lists every tool. Defines no source; the checks run once,
 * at the end of the source loop.
 */

register_shutdown_function(function () {
    if (!empty($GLOBALS['only']) || !function_exists('kop_rinbox_menu_registry')) return;
    if (!function_exists('kop_tools_registry')) {
        if (!function_exists('get_stylesheet_directory_uri')) {
            function get_stylesheet_directory_uri() { return 'https://example.test/wp-content/themes/child'; }
        }
        require_once dirname(__DIR__, 2) . '/wp-plugins/kop-tools/kop-tools.php';
    }
    $before = kop_tools_registry();
    $after = kop_rinbox_menu_registry($before);
    $titles = function (array $tools, $category) { return array_column((array) ($tools[$category] ?? array()), 'title'); };

    $ok = in_array('Review Inbox', $titles($after, 'Review queue'), true);
    echo ($ok ? 'PASS ' : 'FAIL ') . "menu: Submissions Review is listed as Review Inbox\n";
    $ok = !isset($after['Old review screens']) && in_array('Closure Reports', $titles($after, 'Review queue'), true);
    echo ($ok ? 'PASS ' : 'FAIL ') . "menu: covered screens stay in their own category\n";
    $ok = in_array('Data Manager', $titles($after, 'Records & editors'), true) && in_array('Glossary Editor', $titles($after, 'Records & editors'), true);
    echo ($ok ? 'PASS ' : 'FAIL ') . "menu: editors stay where they were\n";
    $count = 0;
    foreach ($before as $list) $count += count($list);
    $count_after = 0;
    foreach ($after as $list) $count_after += count($list);
    echo ($count === $count_after ? 'PASS ' : 'FAIL ') . "menu: no tool is lost  ($count before, $count_after after)\n";
    $missing = array_diff(array_keys(kop_tools_registry()), kop_tools_sidebar_categories());
    echo (!$missing ? 'PASS ' : 'FAIL ') . 'menu: every category is on the sidebar' . ($missing ? '  (' . implode(', ', $missing) . ')' : '') . "\n";
    if (function_exists('kop_rinbox_all_tools') && function_exists('admin_url')) {
        $all = kop_rinbox_all_tools();
        echo (count($all) === $count_after ? 'PASS ' : 'FAIL ') . 'menu: All tools lists every tool  (' . count($all) . ")\n";
    }
});
