<?php
/**
 * Offline render of every hub page through templates/page-hub.php and
 * inc/hub-shell.php, against tmp/prod.sqlite.
 *
 *   php scripts/test-hub-pages.php
 *
 * Each hub's <article> is written to tmp/hub-pages/<slug>.html. Checks that
 * every hub renders with its title and footer; that no hub prints the table
 * of contents or AddToAny's in-content share row; and that Law & Policy, the
 * first hub with settings, leaves its editor text out, opens with the two
 * directory columns (each record linking to its own card), lists the
 * articles filed under it and says where to contribute.
 *
 * Only the Law & Policy and Where Are the Kids modules are loaded; the other
 * modules (research library, resources, category posts) have their own
 * tests. Nothing is written to the database.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

$mirror = ABSPATH . 'tmp/prod.sqlite';
if (!file_exists($mirror) || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    exit("Needs tmp/prod.sqlite and pdo_sqlite (scripts/sync-prod-sqlite.py).\n");
}
$GLOBALS['kop_db'] = new PDO('sqlite:' . $mirror);
$GLOBALS['kop_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_filters'] = array();
function add_action() {}
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['kop_filters'][$tag][$prio][] = $cb; return true; }
function has_filter($tag, $cb) {
    foreach ($GLOBALS['kop_filters'][$tag] ?? array() as $prio => $cbs) {
        if (in_array($cb, $cbs, true)) {
            return $prio;
        }
    }
    return false;
}
function remove_filter($tag, $cb, $prio = 10) {
    $GLOBALS['kop_filters'][$tag][$prio] = array_values(array_filter(
        $GLOBALS['kop_filters'][$tag][$prio] ?? array(),
        function ($c) use ($cb) { return $c !== $cb; }
    ));
    return true;
}
function apply_filters($tag, $value, ...$rest) {
    $by_prio = $GLOBALS['kop_filters'][$tag] ?? array();
    ksort($by_prio);
    foreach ($by_prio as $cbs) {
        foreach ($cbs as $cb) {
            $value = $cb($value, ...$rest);
        }
    }
    return $value;
}
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
function home_url($p = '/') { return 'https://kidsoverprofits.org' . $p; }
function get_stylesheet_directory() { return rtrim(ABSPATH, '/'); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function wp_enqueue_style() {}
function get_header() {}
function get_footer() {}
function date_i18n($fmt, $ts) { return date($fmt, $ts); }
function shortcode_exists($tag) { return $tag === 'addtoany'; }
function do_shortcode($s) { return $s === '[addtoany]' ? '<div class="a2a_kit addtoany_list">[share buttons]</div>' : $s; }
function wp_link_pages() {}
function comments_open() { return false; }
function get_comments_number() { return 0; }
function has_post_thumbnail() { return false; }
function is_singular($type = '') { return true; }
function get_page_template_slug() { return 'templates/page-hub.php'; }

/** Pages by slug from the mirror. */
function kop_test_page($slug) {
    static $cache = array();
    if (!array_key_exists($slug, $cache)) {
        $stmt = $GLOBALS['kop_db']->prepare(
            "SELECT ID, post_name, post_title, post_excerpt, post_content, post_status, post_modified
             FROM wpdl_posts WHERE post_name = ? AND post_type = 'page' AND post_status = 'publish' LIMIT 1"
        );
        $stmt->execute(array($slug));
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        $cache[$slug] = $row ?: null;
    }
    return $cache[$slug];
}
function get_page_by_path($slug) { return kop_test_page($slug); }
function get_permalink($p) { return home_url('/' . (is_object($p) ? $p->post_name : $p) . '/'); }
function get_the_title($p = null) {
    $p = $p ?: $GLOBALS['kop_test_post'];
    return html_entity_decode($p->post_title, ENT_QUOTES, 'UTF-8');
}
function has_excerpt($p = null) { $p = $p ?: $GLOBALS['kop_test_post']; return trim($p->post_excerpt) !== ''; }
function get_the_excerpt($p = null) { $p = $p ?: $GLOBALS['kop_test_post']; return trim($p->post_excerpt); }
function kop_asl_page_url_by_template($template) {
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT p.post_name FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID
         WHERE m.meta_key = '_wp_page_template' AND m.meta_value = ? AND p.post_status = 'publish' LIMIT 1"
    );
    $stmt->execute(array('templates/' . $template));
    $slug = $stmt->fetchColumn();
    return $slug ? home_url('/' . $slug . '/') : '';
}
function get_posts($args) {
    // Only the locations module asks: pages by template, by title.
    $stmt = $GLOBALS['kop_db']->prepare(
        "SELECT p.* FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID
         WHERE m.meta_key = '_wp_page_template' AND m.meta_value = ? AND p.post_status = 'publish' AND p.post_type = 'page'
         ORDER BY p.post_title"
    );
    $stmt->execute(array($args['meta_value']));
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}
function kop_hub_pdo() { return $GLOBALS['kop_db']; }

// The loop, one post.
function have_posts() { return !$GLOBALS['kop_test_done']; }
function the_post() { $GLOBALS['kop_test_done'] = true; }
function get_the_ID() { return (int) $GLOBALS['kop_test_post']->ID; }
function the_ID() { echo get_the_ID(); }
function get_post_field($f, $id) { return $GLOBALS['kop_test_post']->post_name; }
function post_class($c) { echo 'class="' . esc_attr($c) . '"'; }
function the_title() { echo esc_html(get_the_title()); }
function get_the_modified_date($fmt = '') { return date($fmt === 'c' ? 'c' : 'F j, Y', strtotime($GLOBALS['kop_test_post']->post_modified)); }
function edit_post_link($text, $before, $after) { echo $before . '<a href="#edit">' . esc_html($text) . '</a>' . $after; }

/**
 * The plugins' content filters, reduced to a marker each, so a test can see
 * whether the hub let them run.
 */
function A2A_SHARE_SAVE_add_to_content($c) { return $c . '<div class="addtoany_content">[in-content share]</div>'; }
add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', 98);
function kop_test_ez_toc($c) {
    return apply_filters('ez_toc_maybe_apply_the_content_filter', true)
        ? '<div id="ez-toc-container">[table of contents]</div>' . $c : $c;
}
add_filter('the_content', 'kop_test_ez_toc', 100);
function the_content() {
    $html = preg_replace('/<!--.*?-->/s', '', $GLOBALS['kop_test_post']->post_content);
    echo apply_filters('the_content', $html);
}

require ABSPATH . 'inc/admin.php';
require ABSPATH . 'inc/article-parts.php';
require ABSPATH . 'inc/hub-shell.php';

// --- Checks -----------------------------------------------------------------

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
}

function render_hub($slug) {
    $GLOBALS['kop_test_post'] = kop_test_page($slug);
    $GLOBALS['kop_test_done'] = false;
    if (!$GLOBALS['kop_test_post']) {
        return null;
    }
    ob_start();
    include ABSPATH . 'templates/page-hub.php';
    return ob_get_clean();
}

$out_dir = ABSPATH . 'tmp/hub-pages';
if (!is_dir($out_dir)) {
    mkdir($out_dir, 0777, true);
}

$hubs = array_keys(kop_article_hub_slugs());
echo count($hubs) . " hubs\n";
$rendered = array();
foreach ($hubs as $slug) {
    try {
        $html = render_hub($slug);
    } catch (Throwable $e) {
        check("$slug renders", false, $e->getMessage());
        continue;
    }
    if ($html === null) {
        echo "  --   $slug has no published page in the mirror\n";
        continue;
    }
    $rendered[$slug] = $html;
    file_put_contents("$out_dir/$slug.html", $html);
    echo "$slug\n";
    check('title in an h1', (bool) preg_match('#<h1 class="entry-title">[^<]+</h1>#', $html));
    check('footer with share buttons', strpos($html, 'kop-hub-footer') !== false && strpos($html, 'kop-hub-share') !== false);
    check('no table of contents', strpos($html, 'ez-toc-container') === false);
    check('no in-content share row', strpos($html, 'addtoany_content') === false);
}

echo "law-policy settings\n";
$law = $rendered['law-policy'] ?? '';
check('rendered', $law !== '');
check('standfirst', strpos($law, 'kop-hub-standfirst') !== false);
check('editor text left out', strpos($law, 'Read more here') === false && strpos($law, 'single-content') === false);
check('module leads the page', strpos($law, 'kop-hub-modules kop-hub-modules--lead') !== false);
preg_match_all('#<section class="kop-hub-col kop-hub-col--(\w+)"#', $law, $m);
check('two directory columns', $m[1] === array('lawsuits', 'legislation'), implode(',', $m[1]));
preg_match_all('#href="[^"]+/\#(lawsuit|bill)-(\d+)"#', $law, $m);
check('newest records link to their cards', count($m[0]) === 10, count($m[0]) . ' links');
$ok = true;
foreach ($m[1] as $i => $kind) {
    $table = $kind === 'lawsuit' ? 'lawsuits' : 'legislation';
    $stmt  = $GLOBALS['kop_db']->prepare("SELECT publication_status FROM $table WHERE id = ?");
    $stmt->execute(array((int) $m[2][$i]));
    $ok = $ok && in_array($stmt->fetchColumn(), array('approved', 'published'), true);
}
check('every linked record is published', $ok);
check('counts shown', (bool) preg_match('#\d+ tracked#', $law) && (bool) preg_match('#\d+ bills? tracked#', $law));
preg_match_all('#<ul class="kop-hub-reading-list">(.*?)</ul>#s', $law, $r);
$reading = isset($r[1][0]) ? substr_count($r[1][0], '<li>') : 0;
$expect  = count(array_filter(kop_article_children('law-policy'), 'kop_test_page'));
check('reading lists every published article under the hub', $reading === $expect, "$reading of $expect");
check('every reading item has a line under it', isset($r[1][0]) && substr_count($r[1][0], '<p>') === $reading);
preg_match('#<section class="kop-hub-contribute".*?</section>#s', $law, $c);
check('contribute has three links', isset($c[0]) && substr_count($c[0], '<a href=') === 3);

echo "hubs without settings keep their editor content\n";
foreach ($rendered as $slug => $html) {
    if ($slug === 'law-policy' || kop_hub_config($slug)) {
        continue;
    }
    check("$slug prints its editor content", strpos($html, 'entry-content single-content') !== false);
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
