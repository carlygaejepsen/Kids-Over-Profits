<?php
/**
 * Submissions Review is listed as "Review Inbox" with how many items wait.
 * Every screen, the queues the inbox covers included, stays on the KOP Tools
 * sidebar in its own category (each inbox tab also links "Open the full ...
 * screen"), and the inbox's "All tools" tab lists every tool.
 * Never remove_submenu_page()/remove_menu_page(): a screen taken out of the
 * menu loses its registration and its address answers 403.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Items waiting across every queue, from the minute-old cache only (never counted on a menu load). */
function kop_rinbox_menu_waiting() {
    $counts = get_transient('kop_review_inbox_counts');
    return is_array($counts) ? array_sum(array_map('intval', array_filter($counts, 'is_numeric'))) : 0;
}

/**
 * The KOP Tools registry: Submissions Review becomes "Review Inbox". Every other
 * screen stays in its own category and on the sidebar, the queues the inbox covers
 * included (the owner wants every tool one click away, 2026-10-13); the inbox's
 * "All tools" tab lists the same registry (kop_rinbox_all_tools()).
 */
add_filter('kop_tools_registry', 'kop_rinbox_menu_registry');
function kop_rinbox_menu_registry($tools) {
    if (!is_array($tools)) return $tools;
    foreach ($tools as $category => $list) {
        foreach ((array) $list as $i => $tool) {
            if (($tool['type'] ?? '') === 'wp-page' && ($tool['template'] ?? '') === 'page-admin-submissions.php') {
                $tools[$category][$i]['title'] = 'Review Inbox';
                $tools[$category][$i]['desc'] = 'Every approval queue in one place: news, data, wiki, lawsuits, legislation; what readers send in; '
                    . 'the news scans (closures, new facilities, scraper finds, bill and lawsuit updates); imports (Woodbury, Fornits, Drive Docs, '
                    . 'young adult programs); suggestions (duplicates, people, program homes, map years and renames, inspection and wiki links, '
                    . 'wiki updates, data audit, federal funding); serious violations and plain summaries; document types; Nominated; Conflicts. '
                    . 'Your inbox: Assigned to you, Snoozed, Volunteers recommend, Recently done with Undo. All tools: every KOP tool.';
            }
        }
    }
    return $tools;
}

/**
 * Every KOP tool for the inbox's "All tools" tab, in registry order:
 * [{category, title, desc, url, external, action, available, missing}].
 * Empty when the KOP Tools plugin is not active.
 */
function kop_rinbox_all_tools() {
    if (!function_exists('kop_tools_registry') || !function_exists('kop_tools_resolve')) return array();
    $out = array();
    foreach (kop_tools_registry() as $category => $list) {
        foreach ((array) $list as $tool) {
            $where = kop_tools_resolve($tool);
            $out[] = array(
                'category'  => (string) $category,
                'title'     => (string) ($tool['title'] ?? ''),
                'desc'      => (string) ($tool['desc'] ?? ''),
                'url'       => (string) $where['url'],
                'external'  => !empty($where['external']),
                'action'    => ($tool['type'] ?? '') === 'action',
                'available' => !empty($where['available']),
                'missing'   => (string) ($where['missing'] ?? ''),
            );
        }
    }
    return $out;
}

/** Put the waiting count on Review Inbox in the sidebar. */
add_action('admin_menu', function () {
    if (!function_exists('kop_rinbox_sources') || !function_exists('kop_tools_parent_slug')) return;
    $parent = kop_tools_parent_slug();

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
