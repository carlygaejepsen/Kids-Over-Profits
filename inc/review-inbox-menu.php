<?php
/**
 * One menu entry for reviewing: every queue the review inbox covers
 * (inc/review-inbox.php) leaves the KOP Tools sidebar, and Submissions
 * Review is listed as "Review Inbox" with how many items wait. The old
 * screens still work (each inbox tab links "Open the full ... screen") and
 * are listed on the KOP Tools dashboard under "Old review screens".
 */

if (!defined('ABSPATH')) {
    exit;
}

/** wp-admin screen slugs ("page=" values) of the queues the inbox covers. */
function kop_rinbox_menu_covered_screens() {
    $slugs = array();
    foreach (kop_rinbox_sources() as $src) {
        $url = (string) ($src['tool_url'] ?? '');
        if ($url !== '' && preg_match('/[?&]page=([^&#]+)/', $url, $m)) $slugs[] = urldecode($m[1]);
    }
    return array_values(array_unique($slugs));
}

/** Items waiting across every queue, from the minute-old cache only (never counted on a menu load). */
function kop_rinbox_menu_waiting() {
    $counts = get_transient('kop_review_inbox_counts');
    return is_array($counts) ? array_sum(array_map('intval', array_filter($counts, 'is_numeric'))) : 0;
}

/** The KOP Tools registry: Submissions Review becomes "Review Inbox"; the screens it covers move off the sidebar. */
add_filter('kop_tools_registry', 'kop_rinbox_menu_registry');
function kop_rinbox_menu_registry($tools) {
    if (!is_array($tools) || !function_exists('kop_rinbox_sources')) return $tools;
    $covered = kop_rinbox_menu_covered_screens();
    $moved = array();
    foreach ($tools as $category => $list) {
        foreach ((array) $list as $i => $tool) {
            if (($tool['type'] ?? '') === 'wp-page' && ($tool['template'] ?? '') === 'page-admin-submissions.php') {
                $tools[$category][$i]['title'] = 'Review Inbox';
                $tools[$category][$i]['desc'] = 'Every approval queue in one place: news, data, wiki, lawsuits, legislation, the news scans, imports, '
                    . 'Woodbury, Fornits, Drive Docs, merges, map years and renames, scraper finds and more.';
                continue;
            }
            $screen = ($tool['type'] ?? '') === 'screen' ? (string) ($tool['screen'] ?? '') : '';
            $path = (string) ($tool['path'] ?? '');
            if (($screen !== '' && in_array($screen, $covered, true))
                || $path === 'api/review-inspection-highlights.php' || $path === 'api/publish-approved-records.php') {
                $moved[] = $tool;
                unset($tools[$category][$i]);
            }
        }
        $tools[$category] = array_values((array) $tools[$category]);
    }
    if ($moved) $tools['Old review screens'] = $moved;
    return $tools;
}

/** Take the covered screens off the sidebar (still reachable by address) and put the waiting count on Review Inbox. */
add_action('admin_menu', function () {
    if (!function_exists('kop_rinbox_sources') || !function_exists('kop_tools_parent_slug')) return;
    $parent = kop_tools_parent_slug();
    foreach (kop_rinbox_menu_covered_screens() as $slug) {
        remove_submenu_page($parent, $slug);
    }
    remove_submenu_page($parent, 'edit.php?post_type=' . (defined('KOP_EXT_SOURCE_CPT') ? KOP_EXT_SOURCE_CPT : 'kop_source'));
    remove_menu_page('anonymous-docs');

    global $submenu;
    $waiting = kop_rinbox_menu_waiting();
    $inbox = function_exists('kop_find_template_page_url') ? kop_find_template_page_url('page-admin-submissions.php') : '';
    if ($inbox === '' || empty($submenu[$parent])) return;
    foreach ($submenu[$parent] as $i => $item) {
        if (($item[2] ?? '') !== $inbox) continue;
        $submenu[$parent][$i][0] = 'Review Inbox' . ($waiting > 0
            ? ' <span class="awaiting-mod count-' . (int) $waiting . '"><span class="pending-count">' . number_format_i18n($waiting) . '</span></span>'
            : '');
    }
}, 1001);
