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

/**
 * Screens that stay on the sidebar although the inbox covers them: they hold
 * tools the inbox does not (Program Homes: Companies that are one program).
 */
function kop_rinbox_menu_keep_listed() {
    return array('kop-program-homes');
}

/**
 * The sidebar entries hidden for the covered screens: [submenu slugs, top-level slugs].
 * Hidden with CSS, never remove_submenu_page()/remove_menu_page(): a screen taken
 * out of the menu loses its registration and its address answers 403
 * ("Sorry, you are not allowed to access this page").
 */
function kop_rinbox_menu_hidden() {
    if (!function_exists('kop_rinbox_sources')) return array(array(), array());
    $subs = array_values(array_diff(kop_rinbox_menu_covered_screens(), kop_rinbox_menu_keep_listed()));
    $subs[] = 'edit.php?post_type=' . (defined('KOP_EXT_SOURCE_CPT') ? KOP_EXT_SOURCE_CPT : 'kop_source');
    return array($subs, array('anonymous-docs'));
}

add_action('admin_head', function () {
    list($subs, $tops) = kop_rinbox_menu_hidden();
    $rules = array();
    foreach ($subs as $slug) {
        $href = strpos($slug, '.php') !== false ? $slug : 'admin.php?page=' . $slug;
        $rules[] = '#adminmenu .wp-submenu li:has(> a[href="' . esc_attr($href) . '"])';
    }
    foreach ($tops as $slug) $rules[] = '#adminmenu #toplevel_page_' . sanitize_html_class($slug);
    if ($rules) echo '<style id="kop-rinbox-menu">' . implode(",\n", $rules) . ' { display: none; }</style>';
});

/** Take the covered screens off the sidebar (still reachable by address) and put the waiting count on Review Inbox. */
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
