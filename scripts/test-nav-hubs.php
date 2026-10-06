<?php
/**
 * Offline checks for inc/nav-hubs.php: hub pages for the menu's "#" group
 * labels, on a made-up menu (no database, WordPress stubbed).
 *
 *   php scripts/test-nav-hubs.php
 *
 * Checks that each "#" label with entries under it gets a published hub page
 * and is turned into a link to it, that a label already linking somewhere, a
 * "#" with nothing under it and a slug held by another page are left alone,
 * that an existing hub page is reused, that a second run changes nothing,
 * that the hub lists its entries with a line on each (Description, excerpt,
 * notes) and a heading over a nested group, and that Undo restores the menu.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('ABSPATH', dirname(__DIR__) . '/');
const HOME = 'https://kidsoverprofits.org';

// --- A tiny WordPress -------------------------------------------------------

$GLOBALS['opts'] = array();
$GLOBALS['posts'] = array();   // id => object (pages and menu items)
$GLOBALS['meta'] = array();    // id => key => value
$GLOBALS['filters'] = array();
$GLOBALS['next_id'] = 1000;
$GLOBALS['current'] = 0;

function add_action() {}
function add_filter($tag, $cb) { $GLOBALS['filters'][$tag][] = $cb; return true; }
function apply_filters($tag, $v) { foreach ($GLOBALS['filters'][$tag] ?? array() as $cb) { $v = $cb($v); } return $v; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function home_url($p = '') { return HOME . $p; }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_parse_url($u) { return parse_url($u); }
function untrailingslashit($s) { return rtrim($s, '/'); }
function is_wp_error($x) { return false; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_meta($id, $k, $single = true) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_permalink($p) { $p = is_object($p) ? $p : get_post($p); return HOME . '/' . $p->post_name . '/'; }
function get_the_ID() { return $GLOBALS['current']; }
function has_excerpt($p) { return !empty($p->post_excerpt); }
function get_the_excerpt($p) { return $p->post_excerpt; }
function get_nav_menu_locations() { return array('primary' => 2, 'mobile' => 2); }
function wp_setup_nav_menu_item($p) { return $p; }

function get_page_by_path($slug) {
    foreach ($GLOBALS['posts'] as $p) {
        if ($p->post_type === 'page' && $p->post_name === $slug) {
            return $p;
        }
    }
    return null;
}
function add_page($slug, $title, $template = '', $content = '', $excerpt = '') {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title,
        'post_status' => 'publish', 'post_content' => $content, 'post_excerpt' => $excerpt);
    if ($template !== '') {
        $GLOBALS['meta'][$id]['_wp_page_template'] = $template;
    }
    return $id;
}
function wp_insert_post($a) {
    $id = add_page($a['post_name'], $a['post_title'], '', $a['post_content']);
    $GLOBALS['posts'][$id]->post_status = $a['post_status'];
    $GLOBALS['inserted'][] = $a['post_name'];
    return $id;
}

$GLOBALS['menus'] = array(2 => (object) array('term_id' => 2, 'name' => 'Main'), 3 => (object) array('term_id' => 3, 'name' => 'Footer'));
$GLOBALS['items'] = array(); // menu id => list of item ids
function add_item($menu, $title, $parent = 0, $url = '#', $page = 0, $desc = '') {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'nav_menu_item', 'title' => $title, 'menu_item_parent' => (string) $parent,
        'type' => $page ? 'post_type' : 'custom', 'object' => $page ? 'page' : 'custom', 'object_id' => (string) $page,
        'url' => $page ? get_permalink($page) : ($url[0] === '/' ? HOME . $url : $url), 'menu_order' => count($GLOBALS['items'][$menu] ?? array()) + 1,
        'description' => $desc, 'attr_title' => '', 'target' => '', 'classes' => array(''), 'xfn' => '');
    $GLOBALS['items'][$menu][] = $id;
    return $id;
}
function wp_get_nav_menus() { return array_values(array_reverse($GLOBALS['menus'], true)); } // footer first: the code must sort
function wp_get_nav_menu_items($menu) {
    return array_map(function ($id) { return clone $GLOBALS['posts'][$id]; }, $GLOBALS['items'][$menu] ?? array());
}
function wp_update_nav_menu_item($menu, $id, $a) {
    $GLOBALS['updates'][] = $id;
    $p = $GLOBALS['posts'][$id];
    $p->title = $a['menu-item-title'];
    $p->menu_item_parent = (string) $a['menu-item-parent-id'];
    $p->menu_order = $a['menu-item-position'];
    $p->type = $a['menu-item-type'];
    if ($p->type === 'post_type') {
        $p->object = 'page';
        $p->object_id = (string) $a['menu-item-object-id'];
        $p->url = get_permalink($a['menu-item-object-id']);
    } else {
        $p->object = 'custom';
        $p->object_id = (string) $id;
        $p->url = $a['menu-item-url'];
    }
    return $id;
}

require dirname(__DIR__) . '/inc/nav-hubs.php';

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}

// --- The menu ---------------------------------------------------------------

$severe   = add_page('severe-reports', 'Severe Reports');
$glossary = add_page('glossary', 'TTI Glossary', '', '', 'Every term, explained by survivors.');
$start    = add_page('start-here', 'Start Here');
$resources_hub = add_page('resources', 'Resources', 'templates/page-hub.php', '<p>Lines.</p>');
$about_page = add_page('about', 'About us', '', '<p>We are...</p>');
add_page('help', 'Help', 'templates/page-utility.php', '<p>Real page.</p>');

$monitor = add_item(2, 'Monitor');
add_item(2, 'Severe Reports', $monitor, '', $severe);
add_item(2, 'Network Map', $monitor, '/network-map/');
add_item(2, 'Lawsuits', $monitor, '/lawsuits/', 0, 'Every case we track, with its filings.');
$learn = add_item(2, 'Learn More', 0, HOME . '/#');
add_item(2, 'Start Here', $learn, '', $start);
add_item(2, 'Glossary', $learn, '', $glossary);
add_item(2, 'Something unknown', $learn, 'https://example.org/x');
$get = add_item(2, 'Get Involved');
add_item(2, 'Report Abuse', $get, '/report-abuse/');
$submit = add_item(2, 'Submit', $get, '#');
add_item(2, 'Submit a Lawsuit', $submit, '/submit-lawsuit/');
add_item(2, 'Data Submission Tool', $submit, '/tti-data-submission/');
$res = add_item(2, 'Resources');
add_item(2, 'Crisis lines', $res, '/resources/#crisis');
$about = add_item(2, 'About', 0, '', $about_page);
add_item(2, 'Contact', $about, '/contact/');
$lone = add_item(2, 'Donate');
$help = add_item(2, 'Help');
add_item(2, 'FAQ', $help, '/faq/');
$fmon = add_item(3, 'Monitor');
add_item(3, 'Lawsuits', $fmon, '/lawsuits/');

// --- Apply ------------------------------------------------------------------

echo "Apply\n";
$GLOBALS['inserted'] = array();
$summary = kop_nav_hubs_apply();
check($GLOBALS['inserted'] === array('monitor', 'learn-more', 'get-involved'), 'pages made for Monitor, Learn More, Get Involved (' . implode(', ', $GLOBALS['inserted']) . ')');
foreach (array('monitor', 'learn-more', 'get-involved') as $slug) {
    $p = get_page_by_path($slug);
    check($p && $p->post_status === 'publish' && get_post_meta($p->ID, '_wp_page_template') === 'templates/page-hub.php', "/$slug/ is a published hub page");
}
$mon_page = get_page_by_path('monitor');
check(get_post($monitor)->type === 'post_type' && (int) get_post($monitor)->object_id === $mon_page->ID && get_post($monitor)->url === HOME . '/monitor/', 'Monitor now links to /monitor/');
check(get_post($monitor)->title === 'Monitor' && get_post($monitor)->menu_order === 1 && get_post($monitor)->menu_item_parent === '0', '...keeping its label and place');
check((int) get_post($fmon)->object_id === $mon_page->ID, 'the footer menu\'s Monitor links to the same page');
check(get_post($learn)->url === HOME . '/learn-more/', 'a "home + #" label counts as "#" too');
check((int) get_post($res)->object_id === $resources_hub, 'an existing hub page with the slug is reused (Resources)');
check(get_post($about)->object_id === (string) $about_page, 'a label that already links to a page is left alone');
check(get_post($lone)->url === '#', 'a "#" with nothing under it is left alone');
check(get_post($help)->url === '#' && in_array('Help: skipped, /help/ is another page', $summary, true), 'a slug held by another page is left alone and reported');
check(get_post($submit)->url === '#', 'a second-level "#" is not given a page');
$state = get_option('kop_nav_hubs');
check(array_keys($state) === array('monitor', 'learn-more', 'get-involved', 'resources') && count($state['monitor']['items']) === 2
    && $state['learn-more']['items'][0]['url'] === HOME . '/#', 'every change is recorded with the old link');

$GLOBALS['updates'] = array();
$GLOBALS['inserted'] = array();
kop_nav_hubs_apply();
check(!$GLOBALS['updates'] && !$GLOBALS['inserted'] && get_option('kop_nav_hubs') === $state, 'a second run changes nothing');

// --- The hub ----------------------------------------------------------------

echo "Hub\n";
$hubs = apply_filters('kop_hub_config', array('resources' => array('standfirst' => 'Own.')));
check(strpos($hubs['monitor']['standfirst'], 'The records we keep') === 0 && $hubs['monitor']['content'] === false, 'Monitor has its standfirst and the list leads');
check($hubs['resources'] === array('standfirst' => 'Own.'), 'a hub with its own settings and text keeps them');
$mods = apply_filters('kop_hub_modules', array('law-policy' => 'x'));
check($mods['monitor'] === 'kop_nav_hub_module' && $mods['law-policy'] === 'x', 'the hubs get the list module');

function render($page) {
    $GLOBALS['current'] = $page;
    ob_start();
    kop_nav_hub_module();
    return ob_get_clean();
}
$html = render($mon_page->ID);
check(substr_count($html, 'class="kop-nav-hub-card"') === 3, 'Monitor lists its 3 entries from the header menu');
check(strpos($html, '<a href="' . HOME . '/severe-reports/">Severe Reports</a><p>The most serious inspection findings') !== false, 'a page entry gets the line from the notes');
check(strpos($html, '<p>Every case we track, with its filings.</p>') !== false, 'the menu item\'s Description wins');
$html = render(get_page_by_path('learn-more')->ID);
check(strpos($html, '<p>Every term, explained by survivors.</p>') !== false, 'a page\'s excerpt beats the notes');
check(strpos($html, '>Something unknown</a></li>') !== false, 'an entry with no line is still listed');
$html = render(get_page_by_path('get-involved')->ID);
check(preg_match('#<section class="kop-nav-hub-group"[^>]*><h2 class="kop-hub-h" id="kop-nav-hub-g1">Submit</h2><ul class="kop-nav-hub-cards">(.*?)</ul></section>#', $html, $g)
    && substr_count($g[1], '<li') === 2, 'a nested group is a heading over its own cards, its "#" left out');
check(strpos($html, '<div class="kop-hub-module kop-nav-hub"><ul class="kop-nav-hub-cards"><li class="kop-nav-hub-card"><a href="' . HOME . '/report-abuse/">') === 0, 'loose entries come first');
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
check(!array_filter(libxml_get_errors(), function ($e) { return strpos($e->message, 'section') === false; }), 'the list parses as HTML');
check(render($severe) === '', 'a page that is not a hub prints nothing');

// --- Undo -------------------------------------------------------------------

echo "Undo\n";
kop_nav_hubs_undo();
check(get_post($monitor)->url === '#' && get_post($monitor)->type === 'custom' && get_post($fmon)->url === '#', 'Monitor goes back to "#" in both menus');
check(get_post($learn)->url === HOME . '/#' && get_post($learn)->title === 'Learn More' && get_post($learn)->menu_order === 5, 'Learn More gets its old link, label and place');
check(get_option('kop_nav_hubs') === array() && get_page_by_path('monitor'), 'the record is cleared, the pages stay');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
