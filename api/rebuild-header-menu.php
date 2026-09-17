<?php
/**
 * Rebuild the site header navigation around task-based categories
 * (Learn More / Monitor / Get Support / Get Involved) instead of the old
 * audience menus (For Concerned Citizens, For Journalists, ...).
 *
 * Builds a separate nav menu and assigns it to the primary + mobile header
 * locations. The old "Header" menu is left untouched, so ?restore=1 simply
 * points the locations back at whatever they used before.
 *
 * Pages are looked up by slug; a missing page is skipped and reported.
 * Re-running replaces the generated menu (never the original one).
 *
 * GET              - dry run: show the planned menu, change nothing
 * GET ?apply=1     - build the menu and assign it to the header
 * GET ?restore=1   - put the previous menus back on the header locations
 *
 * Requires manage_options.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

const KOP_HEADER_MENU_NAME = 'Header (categories)';
const KOP_HEADER_MENU_LOCATIONS = ['primary', 'mobile'];
const KOP_HEADER_MENU_PREVIOUS_OPTION = 'kop_header_menu_previous_locations';

// Top-level entries: a page slug, or a section label with child page slugs.
// Child entries are [slug, label]; a null label keeps the page title.
$kop_menu_plan = [
    ['page' => 'kids-over-profits', 'label' => 'Home'],
    // Learn More = background and context; Monitor = what the industry is doing now.
    ['section' => 'Learn More', 'children' => [
        ['history', null],
        ['researchreports', null],
        ['law-policy', 'Law & Policy'],
        ['document-archive', null],
        ['in-loving-memory', 'In Loving Memory'],
        ['journalists', null],
    ]],
    ['section' => 'Monitor', 'children' => [
        ['tti-news-feed', 'News Feed'],
        ['where-are-the-kids', null],
        ['tti-program-index', null],
        ['referrers-educational-consultants', 'Referrers & Educational Consultants'],
        ['location-index', null],
        ['lawsuits', null],
        ['legislative-efforts', null],
    ]],
    ['section' => 'Get Support', 'children' => [
        ['survivors', null],
        ['families', 'For Family & Friends'],
        ['resources', null],
        ['contact', null],
    ]],
    ['section' => 'Get Involved', 'children' => [
        ['volunteer', null],
        ['donate', null],
        ['tti-data-submission', 'Submit Program Data'],
        ['submit-lawsuit', null],
        ['submit-legislation', null],
        ['anon-submit', 'Anonymous Document Submission'],
    ]],
];

function kop_header_menu_page($slug) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    return ($page && $page->post_status === 'publish') ? $page : null;
}

/** Resolve the plan against the database: [[label, page|null, children[]], ...] and missing slugs. */
function kop_header_menu_resolve(array $plan) {
    $resolved = [];
    $missing = [];
    foreach ($plan as $entry) {
        if (isset($entry['page'])) {
            $page = kop_header_menu_page($entry['page']);
            if (!$page) { $missing[] = $entry['page']; continue; }
            $resolved[] = ['label' => $entry['label'], 'page' => $page, 'children' => []];
            continue;
        }
        $children = [];
        foreach ($entry['children'] as [$slug, $label]) {
            $page = kop_header_menu_page($slug);
            if (!$page) { $missing[] = $slug; continue; }
            $children[] = ['label' => $label ?? html_entity_decode(get_the_title($page), ENT_QUOTES), 'page' => $page];
        }
        $resolved[] = ['label' => $entry['section'], 'page' => null, 'children' => $children];
    }
    return [$resolved, $missing];
}

function kop_header_menu_summary(array $resolved) {
    $out = [];
    foreach ($resolved as $item) {
        $row = ['label' => $item['label'], 'url' => $item['page'] ? get_permalink($item['page']) : '#'];
        if ($item['children']) {
            $row['children'] = array_map(function ($c) {
                return ['label' => $c['label'], 'url' => get_permalink($c['page'])];
            }, $item['children']);
        }
        $out[] = $row;
    }
    return $out;
}

$locations = get_theme_mod('nav_menu_locations', []);
if (!is_array($locations)) $locations = [];

if (!empty($_GET['restore'])) {
    $previous = get_option(KOP_HEADER_MENU_PREVIOUS_OPTION);
    if (!is_array($previous)) {
        echo json_encode(['success' => false, 'error' => 'No saved previous header menu to restore.']);
        exit;
    }
    foreach ($previous as $loc => $menu_id) {
        $locations[$loc] = (int) $menu_id;
    }
    set_theme_mod('nav_menu_locations', $locations);
    echo json_encode(['success' => true, 'restored_locations' => $previous], JSON_PRETTY_PRINT);
    exit;
}

[$resolved, $missing] = kop_header_menu_resolve($kop_menu_plan);

if (empty($_GET['apply'])) {
    echo json_encode([
        'success' => true,
        'dry_run' => true,
        'current_locations' => array_intersect_key($locations, array_flip(KOP_HEADER_MENU_LOCATIONS)),
        'missing_pages' => $missing,
        'menu' => kop_header_menu_summary($resolved),
        'next' => 'Add ?apply=1 to build this menu and put it in the header.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Remember what the header used before the first apply (re-runs keep the original).
$existing = wp_get_nav_menu_object(KOP_HEADER_MENU_NAME);
if (!get_option(KOP_HEADER_MENU_PREVIOUS_OPTION)) {
    $previous = [];
    foreach (KOP_HEADER_MENU_LOCATIONS as $loc) {
        $previous[$loc] = isset($locations[$loc]) ? (int) $locations[$loc] : 0;
    }
    update_option(KOP_HEADER_MENU_PREVIOUS_OPTION, $previous, false);
}
if ($existing) {
    wp_delete_nav_menu($existing->term_id);
}

$menu_id = wp_create_nav_menu(KOP_HEADER_MENU_NAME);
if (is_wp_error($menu_id)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $menu_id->get_error_message()]);
    exit;
}

$errors = [];
$position = 0;
$add = function (array $args) use ($menu_id, &$position, &$errors) {
    $args['menu-item-position'] = ++$position;
    $args['menu-item-status'] = 'publish';
    $id = wp_update_nav_menu_item($menu_id, 0, $args);
    if (is_wp_error($id)) {
        $errors[] = ($args['menu-item-title'] ?? '') . ': ' . $id->get_error_message();
        return 0;
    }
    return $id;
};
$page_args = function ($page, $label, $parent_id = 0) {
    return [
        'menu-item-title' => $label,
        'menu-item-object' => 'page',
        'menu-item-object-id' => $page->ID,
        'menu-item-type' => 'post_type',
        'menu-item-parent-id' => $parent_id,
    ];
};

foreach ($resolved as $item) {
    if ($item['page']) {
        $parent_id = $add($page_args($item['page'], $item['label']));
    } else {
        $parent_id = $add([
            'menu-item-title' => $item['label'],
            'menu-item-type' => 'custom',
            'menu-item-url' => '#',
        ]);
    }
    if (!$parent_id) continue;
    foreach ($item['children'] as $child) {
        $add($page_args($child['page'], $child['label'], $parent_id));
    }
}

foreach (KOP_HEADER_MENU_LOCATIONS as $loc) {
    $locations[$loc] = $menu_id;
}
set_theme_mod('nav_menu_locations', $locations);

echo json_encode([
    'success' => empty($errors),
    'menu_id' => $menu_id,
    'items_added' => $position - count($errors),
    'errors' => $errors,
    'missing_pages' => $missing,
    'menu' => kop_header_menu_summary($resolved),
    'undo' => 'Open this URL with ?restore=1 to put the previous header menu back.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
