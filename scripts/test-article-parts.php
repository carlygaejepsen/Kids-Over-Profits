<?php
/**
 * Offline test for inc/article-parts.php, the trail and the next link on the
 * long-form articles.
 *
 *   php scripts/test-article-parts.php
 *
 * The map in that file is the only record of which article is read under
 * which, so this checks the things that would put a wrong or dead link in
 * front of a reader: an article placed under a page that is not a hub, a
 * slug in the map that no page answers to, a loop between two articles, a
 * trail that runs the wrong way round, and a next link that stops at the
 * end of a branch instead of carrying on through the hub. It also checks the
 * map against kop_template_assignments(), so an article that gains the
 * reading template and never gets placed is caught here rather than by a
 * reader. Nothing is written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

/* Every slug the map names exists here except one, which is left out on
 * purpose so the "drop a page that has gone" rule is exercised. */
$GLOBALS['kop_test_missing'] = 'overview';

/* inc/admin.php is loaded whole, for kop_template_assignments(): the map has
 * to be checked against the real list of pages using the reading template,
 * not a copy of it. It hooks itself up on the way in, which is all these do. */
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) {
    /* The map is filterable, and the loop test below swaps in a broken one
     * through that filter rather than editing the file. */
    if ($tag === 'kop_article_parents' && isset($GLOBALS['kop_test_parents'])) {
        return $GLOBALS['kop_test_parents'];
    }
    return $value;
}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_json_encode($value) { return json_encode($value); }
function get_page_by_path($slug) {
    if ($slug === $GLOBALS['kop_test_missing']) {
        return null;
    }
    return (object) array('post_name' => $slug, 'post_status' => 'publish');
}
function get_the_title($page) {
    return ucwords(str_replace('-', ' ', $page->post_name));
}
function get_permalink($page) { return 'https://example.test/' . $page->post_name . '/'; }
function is_singular() { return true; }
function get_the_ID() { return 7; }
function get_post_field($field, $id) { return $GLOBALS['kop_test_slug']; }

require ABSPATH . 'inc/admin.php';
require ABSPATH . 'inc/article-parts.php';

// --- Harness ----------------------------------------------------------------

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, json_encode($got), json_encode($want));
}

function ok($label, $got) {
    global $failures;
    if ($got) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n", $label);
}

function contains($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) !== false) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     missing: %s\n", $label, $needle);
}

function lacks($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) === false) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     present but should not be: %s\n", $label, $needle);
}

/** Just the slugs of a trail, outermost first. */
function trail_slugs($slug) {
    return array_map(function ($step) { return $step['slug']; }, kop_article_trail($slug));
}

echo "-- The map itself --\n";

$parents = kop_article_parents();
$hubs    = kop_article_hub_slugs();

ok('the hubs come from the template assignments', isset($hubs['history'], $hubs['researchreports']));
ok('an article template page is not treated as a hub', !isset($hubs['corporatization']));

/* Every article assigned the reading template should be placed, or its
 * readers get no trail and no next link. */
$articles = array();
foreach (kop_template_assignments() as $slug => $spec) {
    $template = is_array($spec) ? (isset($spec['template']) ? $spec['template'] : '') : $spec;
    if ($template === 'page-article.php') {
        $articles[] = $slug;
    }
}
$unplaced = array_values(array_diff($articles, array_keys($parents)));
check('every article the template assigns is placed in the map', $unplaced, array());

/* And nothing is placed under a page that is neither a hub nor itself
 * placed, which would leave a trail hanging in mid-air. */
$orphans = array();
foreach ($parents as $slug => $parent) {
    if (!isset($hubs[$parent]) && !isset($parents[$parent])) {
        $orphans[] = $slug . ' under ' . $parent;
    }
}
check('nothing is read under a page that is nowhere itself', $orphans, array());

echo "\n-- Trails --\n";

check('a timeline carries its index page and its hub',
    trail_slugs('war-on-drugs'), array('history', 'birth-of-the-tti'));
check('an index page carries only its hub',
    trail_slugs('early-child-control'), array('history'));
check('a research summary sits under Research & Reports',
    trail_slugs('child-welfare'), array('researchreports'));
check('a page the map does not name has no trail',
    trail_slugs('tti-program-index'), array());

echo "\n-- Reading order --\n";

$history = kop_article_sequence('history');
check('the hub reads depth first, index page then what it indexes',
    array_slice($history, 0, 4),
    array('tti-history-part-one', 'early-child-control', 'antiquity',
        'medieval-child-oblation-and-monastic-schools'));
check('and ends where the hub does', array_slice($history, -2),
    array('corporatization', 'advocacy-history'));

$near = kop_article_neighbours('war-on-drugs');
check('the last timeline in a branch leads on to the next branch',
    $near['next']['slug'], 'corporatization');
check('and back to the one before it', $near['prev']['slug'], 'experimental-group-psychology');

$first = kop_article_neighbours('tti-history-part-one');
check('the first article in a hub has nothing before it', $first['prev'], null);
$last = kop_article_neighbours('advocacy-history');
check('the last has nothing after it', $last['next'], null);

/* The one slug with no page: it must not appear in its hub's order at all. */
$journalists = kop_article_sequence('journalists');
check('a slug whose page has gone is left out of the order', $journalists, array());
$gone = kop_article_neighbours($GLOBALS['kop_test_missing']);
check('and has no next link of its own', $gone['next'], null);

echo "\n-- A loop cannot hang the page --\n";

/* Two articles pointing at each other is the mistake a hand-edited map
 * invites, and the walk has to stop rather than spin. A page that is its own
 * parent is the same mistake with one line instead of two. */
$GLOBALS['kop_test_parents'] = array('one' => 'two', 'two' => 'one');
check('a loop between two articles stops at the first repeat',
    trail_slugs('one'), array('two'));

$GLOBALS['kop_test_parents'] = array('self' => 'self');
check('a page read under itself gets no trail at all',
    trail_slugs('self'), array());

unset($GLOBALS['kop_test_parents']);
check('and the real map is back', trail_slugs('corporatization'), array('history'));

echo "\n-- The markup --\n";

ob_start();
kop_article_breadcrumbs('war-on-drugs', 'Temperance & The War on Drugs');
$crumbs = ob_get_clean();

contains('the trail is a breadcrumb landmark', $crumbs, 'aria-label="Breadcrumb"');
contains('it starts at home', $crumbs, 'href="https://example.test/"');
contains('it names the hub', $crumbs, 'href="https://example.test/history/"');
contains('it names the index page', $crumbs, 'href="https://example.test/birth-of-the-tti/"');
contains('the current page is marked, not linked', $crumbs, 'aria-current="page"');
contains('the title is escaped', $crumbs, 'Temperance &amp; The War on Drugs');
lacks('and no second BreadcrumbList is printed beside Yoast\'s', $crumbs, 'BreadcrumbList');

ob_start();
kop_article_breadcrumbs('tti-program-index', 'TTI Program Index');
check('a page the map does not place prints no trail', ob_get_clean(), '');

ob_start();
kop_article_breadcrumbs('history', 'History');
$hub_crumbs = ob_get_clean();
contains('a hub gets its one step from home', $hub_crumbs, 'href="https://example.test/"');
contains('and is marked as where the reader is', $hub_crumbs, 'aria-current="page"');
ok('with nothing between the two', substr_count($hub_crumbs, '<li>') === 1);

ob_start();
kop_article_continue('war-on-drugs');
$next = ob_get_clean();

contains('the next card names the hub in its landmark', $next, 'aria-label="More in History"');
contains('previous is a link', $next, 'kop-article-continue__prev');
contains('next is a link', $next, 'href="https://example.test/corporatization/"');

ob_start();
kop_article_continue('advocacy-history');
$end = ob_get_clean();
lacks('the last article offers no next', $end, 'kop-article-continue__next');
contains('but still offers the one before', $end, 'kop-article-continue__prev');

ob_start();
kop_article_continue('tti-program-index');
check('an unplaced page prints nothing at all', ob_get_clean(), '');

echo "\n-- What Yoast is handed --\n";

/* Yoast's own list, as it arrives: home, then the page being viewed. */
$yoast_in = array(
    array('url' => 'https://example.test/', 'text' => 'Home'),
    array('url' => 'https://example.test/war-on-drugs/', 'text' => 'Temperance and the War on Drugs'),
);

$GLOBALS['kop_test_slug'] = 'war-on-drugs';
$yoast_out = kop_article_yoast_breadcrumbs($yoast_in);
check('the trail goes between home and the page',
    array_map(function ($l) { return $l['text']; }, $yoast_out),
    array('Home', 'History', 'Birth Of The Tti', 'Temperance and the War on Drugs'));

$GLOBALS['kop_test_slug'] = 'tti-program-index';
check('a page the map does not place is left alone',
    kop_article_yoast_breadcrumbs($yoast_in), $yoast_in);

check('and so is a list with nothing in it', kop_article_yoast_breadcrumbs(array()), array());

echo $failures ? "\n$failures FAILURES\n" : "\narticle parts: PASS\n";
exit($failures ? 1 : 0);
