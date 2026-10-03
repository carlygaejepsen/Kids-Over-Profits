<?php
/**
 * scripts/test-review-inbox.php checks for inc/review-inbox-menu.php: run
 * over the KOP Tools plugin's own registry, Submissions Review becomes
 * "Review Inbox" and every screen the inbox covers moves to "Old review
 * screens" (off the sidebar), while the editors stay where they were.
 * Defines no source; the checks run once, at the end of the source loop.
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
    $all_after = array();
    foreach ($after as $cat => $list) foreach ($list as $t) $all_after[] = $cat . ': ' . $t['title'];

    $ok = in_array('Review Inbox', $titles($after, 'Review queue'), true);
    echo ($ok ? 'PASS ' : 'FAIL ') . "menu: Submissions Review is listed as Review Inbox\n";
    $old = $titles($after, 'Old review screens');
    $want = array('Closure Reports', 'Facilities from News', 'Woodbury Reports');
    $ok = !array_diff($want, $old);
    echo ($ok ? 'PASS ' : 'FAIL ') . 'menu: covered screens move to Old review screens  (' . implode(', ', $old) . ")\n";
    $ok = in_array('Data Manager', $titles($after, 'Records & editors'), true) && in_array('Glossary Editor', $titles($after, 'Records & editors'), true);
    echo ($ok ? 'PASS ' : 'FAIL ') . "menu: editors stay where they were\n";
    $count = 0;
    foreach ($before as $list) $count += count($list);
    $count_after = 0;
    foreach ($after as $list) $count_after += count($list);
    echo ($count === $count_after ? 'PASS ' : 'FAIL ') . "menu: no tool is lost  ($count before, $count_after after)\n";
});
