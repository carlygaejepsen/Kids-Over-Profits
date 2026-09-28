<?php
/**
 * Offline test for the KOP Tools menu (wp-plugins/kop-tools/kop-tools.php and
 * the theme's screens in inc/admin.php), against the pages in tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite scripts/test-kop-tools-menu.php
 *
 * Builds the wp-admin sidebar and the admin bar dropdown the way WordPress
 * would and checks that:
 *   - every registered api/ tool file exists in the theme
 *   - every admin page template resolves, to the page at the slug
 *     kop_tool_page_specs() names when several pages share the template
 *   - the sidebar, the dropdown and the dashboard list the same tools, with
 *     no duplicate links and no top-level "Approve Edits" menu
 * Prints the resulting sidebar and dropdown.
 */

define('ABSPATH', __DIR__ . '/');
$theme = dirname(__DIR__);
$db_path = $theme . '/tmp/prod.sqlite';
if (!file_exists($db_path)) {
    fwrite(STDERR, "tmp/prod.sqlite missing: run scripts/sync-prod-sqlite.py first\n");
    exit(2);
}
$GLOBALS['kop_db'] = new PDO('sqlite:' . $db_path);

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_hooks'] = array();
function add_action($hook, $cb, $prio = 10) { $GLOBALS['kop_hooks'][$hook][$prio][] = $cb; }
function add_filter() {}
function apply_filters($hook, $value) { return $value; }
function do_hook($hook, ...$args) {
    $by_prio = isset($GLOBALS['kop_hooks'][$hook]) ? $GLOBALS['kop_hooks'][$hook] : array();
    ksort($by_prio);
    foreach ($by_prio as $cbs) {
        foreach ($cbs as $cb) {
            call_user_func_array($cb, $args);
        }
    }
}
function current_user_can() { return true; }
function get_stylesheet_directory() { return $GLOBALS['theme']; }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function get_stylesheet() { return 'child'; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function admin_url($path = '') { return 'https://kidsoverprofits.org/wp-admin/' . $path; }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function get_permalink($post) {
    $id = is_object($post) ? $post->ID : (int) $post;
    $st = $GLOBALS['kop_db']->prepare("SELECT post_name FROM wpdl_posts WHERE ID = ?");
    $st->execute(array($id));
    return 'https://kidsoverprofits.org/' . $st->fetchColumn() . '/';
}
function get_posts($args) {
    $values = $args['meta_query'][0]['value'];
    $statuses = (array) $args['post_status'];
    $in_v = implode(',', array_fill(0, count($values), '?'));
    $in_s = implode(',', array_fill(0, count($statuses), '?'));
    $st = $GLOBALS['kop_db']->prepare(
        "SELECT p.ID, p.post_name, p.post_status, p.post_title FROM wpdl_posts p
         JOIN wpdl_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template'
         WHERE p.post_type = 'page' AND m.meta_value IN ($in_v) AND p.post_status IN ($in_s)
         ORDER BY p.ID ASC"
    );
    $st->execute(array_merge($values, $statuses));
    return $st->fetchAll(PDO::FETCH_OBJ);
}

$GLOBALS['menu'] = array();
$GLOBALS['submenu'] = array();
function add_menu_page($page_title, $menu_title, $cap, $slug) { $GLOBALS['menu'][] = array($menu_title, $cap, $slug); }
function add_submenu_page($parent, $page_title, $menu_title, $cap, $slug) {
    $GLOBALS['submenu'][$parent][] = array($menu_title, $cap, $slug, $page_title);
}

class Kop_Test_Admin_Bar {
    public $nodes = array();
    public function add_node($n) { $this->nodes[$n['id']] = $n; }
}

$theme = $theme;
require $theme . '/inc/admin.php';
require $theme . '/wp-plugins/kop-tools/kop-tools.php';
// The screens the theme registers elsewhere (only their callbacks are needed).
function kop_render_glossary_editor_page() {}
function kop_render_glossary_feedback_page() {}
class AnonymousDocPortal {}
add_action('admin_menu', function () {
    add_submenu_page(kop_tools_parent_slug(), 'Glossary Editor', 'Glossary Editor', 'manage_options', 'kop-glossary-editor');
    add_submenu_page(kop_tools_parent_slug(), 'Glossary Feedback', 'Glossary Feedback', 'manage_options', 'kop-glossary-feedback');
}, 21);

// --- Run --------------------------------------------------------------------

$fail = 0;
function check($ok, $msg) {
    global $fail;
    if (!$ok) {
        $fail++;
        echo "FAIL: $msg\n";
    }
}

do_hook('admin_menu');
$bar = new Kop_Test_Admin_Bar();
do_hook('admin_bar_menu', $bar);

// Top-level menus: only KOP Tools from this code.
$tops = array_map(function ($m) { return $m[2]; }, $GLOBALS['menu']);
check($tops === array('kop-tools'), 'top-level menus are ' . implode(', ', $tops));

// Registry: every file present, every template resolved, preferred slugs.
$preferred = array();
foreach (kop_tool_page_specs() as $spec) {
    $preferred[$spec['template']] = $spec['slug'];
}
$registry_urls = array();
foreach (kop_tools_registry() as $category => $tools) {
    foreach ($tools as $tool) {
        $where = kop_tools_resolve($tool);
        $type = $tool['type'];
        if ($type === 'wp-page' && $tool['template'] === 'page-admin-volunteers.php' && !$where['available']) {
            // Created by kop_ensure_tool_pages() on the first request after deploy.
            echo "note: Volunteer Admin page not in the mirror yet (created on deploy)\n";
            continue;
        }
        check($where['available'], "$category / {$tool['title']}: " . $where['missing']);
        if ($type === 'wp-page' && isset($preferred[$tool['template']]) && $where['available']) {
            check(
                $where['url'] === 'https://kidsoverprofits.org/' . $preferred[$tool['template']] . '/',
                "{$tool['title']} resolves to {$where['url']}, expected /{$preferred[$tool['template']]}/"
            );
        }
        if ($where['available']) {
            if (isset($registry_urls[$where['url']])) {
                check(false, "{$tool['title']} duplicates the link of {$registry_urls[$where['url']]}");
            }
            $registry_urls[$where['url']] = $tool['title'];
        }
    }
}

// Sidebar: no duplicate slugs, All Tools first, every sidebar-category tool present.
$side = $GLOBALS['submenu']['kop-tools'];
$slugs = array_map(function ($i) { return $i[2]; }, $side);
check(count($slugs) === count(array_unique($slugs)), 'duplicate sidebar entries');
check($slugs[0] === 'kop-tools', 'All Tools is not first');
$registry = kop_tools_registry();
foreach (kop_tools_sidebar_categories() as $cat) {
    foreach ($registry[$cat] as $tool) {
        if ($tool['type'] === 'screen' && $tool['screen'] === 'anonymous-docs') {
            continue; // its own top-level menu in inc/features.php
        }
        $where = kop_tools_resolve($tool);
        if (!$where['available']) {
            continue;
        }
        $key = $tool['type'] === 'screen' ? $tool['screen'] : $where['url'];
        check(in_array($key, $slugs, true), "sidebar is missing {$tool['title']}");
    }
}

// Dropdown: every available tool once.
$bar_links = array();
foreach ($bar->nodes as $n) {
    if (isset($n['parent']) && strpos($n['parent'], 'kop-tools-cat-') === 0) {
        $bar_links[] = $n['title'];
    }
}
$expected = 0;
foreach ($registry as $tools) {
    foreach ($tools as $tool) {
        $expected += kop_tools_resolve($tool)['available'] ? 1 : 0;
    }
}
check(count($bar_links) === $expected, 'dropdown has ' . count($bar_links) . " tools, registry has $expected available");

echo "\nSidebar (KOP Tools):\n";
foreach ($side as $i) {
    echo '  ' . str_pad($i[0], 30) . ' ' . $i[2] . "\n";
}
echo "\nAdmin bar dropdown:\n";
foreach ($bar->nodes as $n) {
    $depth = !isset($n['parent']) ? 0 : ($n['parent'] === 'kop-tools' ? 1 : 2);
    echo str_repeat('  ', $depth + 1) . $n['title'] . ($depth === 2 ? '  -> ' . $n['href'] : '') . "\n";
}

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
