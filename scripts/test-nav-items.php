<?php
/**
 * Prove kop_ensure_nav_items() before it touches a live menu.
 *
 * It is the only thing in the theme that writes to a nav menu somebody curates
 * by hand in wp-admin, so the risk is not that it fails - it is that it runs
 * twice, or attaches to the wrong parent, or quietly re-adds an entry an
 * editor deleted on purpose. This stubs the WordPress menu API over an
 * in-memory copy of the live menu and checks exactly those things.
 *
 * Usage: php scripts/test-nav-items.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', true);

/* ---- A copy of the live menu, shortened to what matters ---------------- */

$GLOBALS['menus'] = array(
    (object) array('term_id' => 3, 'name' => 'Primary'),
    (object) array('term_id' => 9, 'name' => 'Footer'),
);

function kop_test_item($id, $parent, $title, $object_id = 0, $order = 0, $object = 'page') {
    return (object) array(
        'ID' => $id, 'menu_item_parent' => (string) $parent, 'title' => $title,
        'object_id' => $object_id, 'object' => $object, 'menu_order' => $order,
    );
}

$GLOBALS['items'] = array(
    3 => array(
        kop_test_item(10922, 0, 'Home', 1, 1),
        kop_test_item(10939, 0, 'Get Support', 0, 20, 'custom'),
        kop_test_item(10942, 10939, 'Resources', 55, 22),
        kop_test_item(10944, 0, 'Get Involved', 0, 30, 'custom'),
        kop_test_item(10945, 10944, 'Volunteer', 61, 31),
        kop_test_item(10950, 10944, 'Anonymous Document Submission', 66, 36),
    ),
    9 => array(kop_test_item(20001, 0, 'Home', 1, 1)),
);

$GLOBALS['inserted'] = array();
$GLOBALS['next_id'] = 30000;

/* ---- The WordPress surface the function touches ------------------------ */

function get_page_by_path($slug) {
    return $slug === 'report-abuse' ? (object) array('ID' => 777, 'post_name' => $slug) : null;
}
function wp_get_nav_menus() { return $GLOBALS['menus']; }
function wp_get_nav_menu_items($term_id) { return $GLOBALS['items'][$term_id] ?? array(); }
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function is_wp_error($t) { return false; }
function wp_update_nav_menu_item($menu_id, $item_id, $args) {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['inserted'][] = array('menu' => $menu_id, 'args' => $args);
    $GLOBALS['items'][$menu_id][] = kop_test_item(
        $id, $args['menu-item-parent-id'], $args['menu-item-title'],
        $args['menu-item-object-id'], $args['menu-item-position']
    );
    return $id;
}
/* Everything else inc/admin.php declares at load time. */
function add_action() {} function add_filter() {} function add_menu_page() {}
function add_submenu_page() {} function get_option() { return false; }
function update_option() { return true; }
function current_user_can() { return true; }
function get_stylesheet_directory() { return dirname(__DIR__); }

if (!defined('KOP_REPORTING_SLUG')) {
    define('KOP_REPORTING_SLUG', 'report-abuse');
}

/* Only the two functions under test, lifted out of inc/admin.php, so this
 * harness does not have to satisfy every dependency that file loads. */
$admin = file_get_contents(dirname(__DIR__) . '/inc/admin.php');
foreach (array('kop_nav_item_specs', 'kop_ensure_nav_items') as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(\).*?\n\}\n/s', $admin, $m)) {
        fwrite(STDERR, "Could not find $fn() in inc/admin.php\n");
        exit(2);
    }
    eval($m[0]);
}

/* ---- Checks ------------------------------------------------------------ */

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// 1. One insert, under the right parent, in the right menu.
$first = kop_ensure_nav_items();
check('adds one entry', count($GLOBALS['inserted']) === 1, count($GLOBALS['inserted']) . ' inserted');
$ins = $GLOBALS['inserted'][0] ?? array('menu' => 0, 'args' => array());
check('lands in the menu holding the parent', ($ins['menu'] ?? 0) === 3, 'menu ' . ($ins['menu'] ?? '?'));
check('nests under Get Involved', ($ins['args']['menu-item-parent-id'] ?? 0) === 10944,
    'parent ' . ($ins['args']['menu-item-parent-id'] ?? '?'));
check('points at the page, not a custom URL',
    ($ins['args']['menu-item-object'] ?? '') === 'page' && ($ins['args']['menu-item-object-id'] ?? 0) === 777);
check('appended after existing children', ($ins['args']['menu-item-position'] ?? 0) === 37,
    'position ' . ($ins['args']['menu-item-position'] ?? '?'));
check('reports what it did', $first === array('report-abuse => Primary > Get Involved'),
    implode('; ', $first) ?: 'nothing');

// 2. Idempotent: a second run must do nothing at all.
$before = count($GLOBALS['inserted']);
$second = kop_ensure_nav_items();
check('second run inserts nothing', count($GLOBALS['inserted']) === $before && $second === array(),
    (count($GLOBALS['inserted']) - $before) . ' added');

// 3. An editor who moved it elsewhere in the menu keeps their arrangement.
$GLOBALS['items'][3] = array(
    kop_test_item(10944, 0, 'Get Involved', 0, 30, 'custom'),
    kop_test_item(10945, 10944, 'Volunteer', 61, 31),
    kop_test_item(11000, 0, 'Report Abuse', 777, 5),
);
$GLOBALS['inserted'] = array();
kop_ensure_nav_items();
check('leaves a hand-moved entry alone', count($GLOBALS['inserted']) === 0,
    count($GLOBALS['inserted']) . ' re-added');

// 4. No parent with that label: add nothing rather than guess.
$GLOBALS['items'] = array(3 => array(kop_test_item(10922, 0, 'Home', 1, 1)), 9 => array());
$GLOBALS['inserted'] = array();
kop_ensure_nav_items();
check('adds nothing when the parent is missing', count($GLOBALS['inserted']) === 0,
    count($GLOBALS['inserted']) . ' added');

// 5. Label matching ignores case and stray markup, as menus in the wild do.
$GLOBALS['items'] = array(
    3 => array(kop_test_item(1, 0, '<span>get involved</span>', 0, 10, 'custom')),
    9 => array(),
);
$GLOBALS['inserted'] = array();
kop_ensure_nav_items();
check('matches the parent label loosely', count($GLOBALS['inserted']) === 1,
    count($GLOBALS['inserted']) . ' added');

echo "\n" . ($failures ? "$failures FAILED\n" : "ALL PASSED\n");
exit($failures ? 1 : 0);
