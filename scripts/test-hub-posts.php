<?php
/**
 * Offline test for inc/hub-posts.php, the category post lists on the
 * Editorials and Investigatory Spotlight hubs.
 *
 *   php scripts/test-hub-posts.php
 *
 * Posts come from tmp/prod.sqlite when the mirror is present (the real
 * titles and lengths), otherwise from two fixtures. Checks that both pages
 * are hubs with a module, that the page's own query block is skipped only
 * when it lists the same category, that each post prints once as a card
 * with a date and reading time, and that an empty category says so instead
 * of printing an empty list. Nothing is written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_filters'] = array();
function add_action() {}
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['kop_filters'][$tag][] = $cb; }
function apply_filters($tag, $value, ...$rest) {
    foreach ($GLOBALS['kop_filters'][$tag] ?? array() as $cb) {
        $value = $cb($value, ...$rest);
    }
    return $value;
}
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return (string) $u; }
function home_url($p = '/') { return 'https://example.test' . $p; }
function is_page() { return true; }
function get_queried_object_id() { return 1; }
function get_the_ID() { return 1; }
function get_post_field($f, $id) { return $GLOBALS['kop_test_slug']; }
function get_category_by_slug($slug) {
    $ids = array('editorials' => 80, 'investigatory-spotlight' => 81);
    return isset($ids[$slug]) ? (object) array('term_id' => $ids[$slug]) : false;
}
function get_posts($args) { return $GLOBALS['kop_test_posts'][$args['cat']] ?? array(); }
function get_page_by_path($slug) { return (object) array('post_name' => $slug, 'post_status' => 'publish'); }
function get_permalink($p) { return 'https://example.test/' . $p->post_name . '/'; }
function get_the_title($p = null) { return $p ? html_entity_decode($p->post_title) : 'Page'; }
function get_the_date($fmt, $p) { return $fmt === 'c' ? date('c', strtotime($p->post_date)) : date('F j, Y', strtotime($p->post_date)); }
function get_the_post_thumbnail($p) { return $p->thumb ? '<img src="t.jpg" alt="">' : ''; }
function has_excerpt($p) { return false; }
function strip_shortcodes($s) { return $s; }
function excerpt_remove_blocks($s) { return $s; }
function wp_strip_all_tags($s) { return trim(strip_tags(preg_replace('/<!--.*?-->/s', '', $s))); }
function wp_trim_words($t, $n, $more) {
    $w = preg_split('/\s+/', trim($t));
    return count($w) > $n ? implode(' ', array_slice($w, 0, $n)) . $more : implode(' ', $w);
}

require ABSPATH . 'inc/admin.php';
require ABSPATH . 'inc/hub-posts.php';

// --- Posts ------------------------------------------------------------------

$mirror = ABSPATH . 'tmp/prod.sqlite';
$posts  = array(80 => array(), 81 => array());
if (file_exists($mirror) && class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite:' . $mirror);
    foreach (array(80, 81) as $cat) {
        $stmt = $db->prepare(
            "SELECT p.post_name, p.post_title, p.post_date, p.post_content,
                    (SELECT 1 FROM wpdl_postmeta m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id') AS thumb
             FROM wpdl_posts p
             JOIN wpdl_term_relationships tr ON tr.object_id = p.ID
             JOIN wpdl_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.term_id = ? AND p.post_status = 'publish' AND p.post_type = 'post'
             ORDER BY p.post_date DESC"
        );
        $stmt->execute(array($cat));
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            $posts[$cat][] = $row;
        }
    }
    echo "posts from tmp/prod.sqlite\n";
}
if (!$posts[80] || !$posts[81]) {
    $posts = array(
        80 => array((object) array('post_name' => 'an-essay', 'post_title' => 'An Essay', 'post_date' => '2025-06-20', 'post_content' => str_repeat('word ', 2300), 'thumb' => null)),
        81 => array((object) array('post_name' => 'a-spotlight', 'post_title' => 'A &amp; Spotlight', 'post_date' => '2024-12-01', 'post_content' => '<p>' . str_repeat('word ', 500) . '</p>', 'thumb' => 1)),
    );
    echo "posts from fixtures\n";
}
$GLOBALS['kop_test_posts'] = $posts;

// --- Harness ----------------------------------------------------------------

$failures = 0;
function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, json_encode($got), json_encode($want));
}
function render($slug) {
    $GLOBALS['kop_test_slug'] = $slug;
    ob_start();
    kop_hub_module_category_posts();
    return ob_get_clean();
}

// --- Tests ------------------------------------------------------------------

$assign  = kop_template_assignments();
$modules = apply_filters('kop_hub_modules', array());
foreach (array('editorials' => 80, 'investigatory-spotlight' => 81) as $slug => $cat) {
    echo "\n-- $slug --\n";
    check('uses the hub template', $assign[$slug] ?? null, 'page-hub.php');
    check('has the posts module', $modules[$slug] ?? null, 'kop_hub_module_category_posts');
    check('has a standfirst', apply_filters('kop_hub_standfirst', '', $slug) !== '', true);
    check('an excerpt the editor wrote wins', apply_filters('kop_hub_standfirst', 'Mine.', $slug), 'Mine.');

    $GLOBALS['kop_test_slug'] = $slug;
    $own   = array('blockName' => 'core/query', 'attrs' => array('query' => array('taxQuery' => array('category' => array($cat)))));
    $other = array('blockName' => 'core/query', 'attrs' => array('query' => array('taxQuery' => array('category' => array(35)))));
    check('its own query block is skipped', kop_hub_posts_skip_query_block(null, $own), '');
    check('a query block for another category is kept', kop_hub_posts_skip_query_block(null, $other), null);
    check('a paragraph is kept', kop_hub_posts_skip_query_block(null, array('blockName' => 'core/paragraph')), null);

    $html = render($slug);
    check('one card per post', substr_count($html, '<li class="kop-hub-post'), count($posts[$cat]));
    foreach ($posts[$cat] as $p) {
        check('links ' . $p->post_name . ' once in the title', substr_count($html, '<h3 class="kop-hub-post-title"><a href="https://example.test/' . $p->post_name . '/">'), 1);
    }
    check('every card has a reading time', substr_count($html, ' min read'), count($posts[$cat]));
    check('titles are not double-escaped', strpos($html, '&amp;amp;'), false);
    check('offers a next step', strpos($html, 'class="kop-hub-more"') !== false, true);
    check('one h2, the list heading', substr_count($html, '<h2'), 1);
}

echo "\n-- edge cases --\n";
$GLOBALS['kop_test_posts'] = array();
$empty = render('editorials');
check('an empty category says so', strpos($empty, 'kop-hub-empty') !== false, true);
check('and prints no list', strpos($empty, '<ul'), false);
check('a page with no section prints nothing', render('history'), '');
$GLOBALS['kop_test_slug'] = 'history';
check('and its query blocks are left alone', kop_hub_posts_skip_query_block(null, $own), null);

echo $failures ? "\nhub posts: $failures FAILURES\n" : "\nhub posts: PASS\n";
exit($failures ? 1 : 0);
