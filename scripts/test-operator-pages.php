<?php
/**
 * Offline check of the generated operator pages (inc/operator-pages.php)
 * against the SQLite mirror of production (tmp/prod.sqlite, written by
 * scripts/sync-prod-sqlite.py). WordPress is stubbed by
 * scripts/kop-test-harness.php; the module's real SQL runs through
 * PDO/SQLite. Read-only.
 *
 * Usage (Local's bundled PHP):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-operator-pages.php [--db=tmp/prod.sqlite] [--out=<dir>]
 *
 * Renders every operator page to <out>/<slug>.html for a look in a browser.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/operator-pages');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/operator-pages.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

echo "-- Index --\n";
$index = kop_operator_pages_index(true);
$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operators');
printf("  operators: %d records, %d pages, %d folded into another\n", $total, count($index['ids']), count($index['alias_of']));
$check('every record has a page or folds into one', count($index['ids']) + count($index['alias_of']) === $total);
$bad = array();
foreach ($index['ids'] as $id => $e) {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $e['slug'])) $bad[] = "$id:{$e['slug']}";
}
$check('every slug is lowercase a-z, 0-9 and single dashes', !$bad, implode(' | ', array_slice($bad, 0, 5)));
$check('slug map and id map agree', count($index['slugs']) === count($index['ids']));
foreach ($index['alias_of'] as $dup => $lead) {
    echo "  duplicate $dup folds into $lead ({$index['ids'][$lead]['name']})\n";
}

// ---------------------------------------------------------------------------
// Name lookup, as the glossary and the facility pages use it
// ---------------------------------------------------------------------------

echo "\n-- Name lookup --\n";
$probes = array(
    'CEDU'                   => 'cedu',
    'Straight, Inc.'         => 'straight-inc',
    'Straight Inc.'          => 'straight-inc',
    'Teen Challenge'         => 'teen-challenge',
    'WWASPS'                 => 'world-wide-association-of-specialty-programs-and-schools',
    'UHS'                    => 'universal-health-services',
    'Universal Health Services' => 'universal-health-services',
    'Three Springs'          => 'three-springs-inc',
    'Aspen Education Group'  => 'aspen-education-group',
    'Acadia Healthcare'      => 'acadia-healthcare',
);
foreach ($probes as $name => $slug) {
    $url = kop_operator_page_url_for_name($name);
    $check("'$name' finds its page", $url === home_url('/operator/' . $slug . '/'), $url);
}
$check('an unknown name finds nothing', kop_operator_page_url_for_name('No Such Holdings 9000') === '');

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

echo "\n-- Routing --\n";
$route = function ($slug) {
    $GLOBALS['kop_test_query_vars'] = array('kop_operator' => $slug);
    $GLOBALS['kop_operator_page'] = null;
    $GLOBALS['kop_test_status'] = 0;
    $GLOBALS['wp_query'] = new WP_Query();
    try {
        kop_operator_pages_route();
    } catch (KOP_Test_Redirect $r) {
        return 'redirect ' . $r->status . ' ' . $r->url;
    }
    if (!empty($GLOBALS['wp_query']->is_404)) return '404';
    return kop_operator_pages_is_page() ? 'page ' . $GLOBALS['kop_test_status'] : 'nothing';
};
$first = array_key_first($index['ids']);
$check('a slug renders', $route($index['ids'][$first]['slug']) === 'page 200');
$check('an id redirects to its slug', strpos($route((string) $first), 'redirect 301') === 0);
$check('an unknown slug is a 404', $route('no-such-company') === '404');
$GLOBALS['kop_test_query_vars'] = array('kop_operator' => KOP_OPERATOR_INDEX_VAR);
$GLOBALS['kop_operator_index'] = null;
kop_operator_pages_route();
$check('/operator/ is the index of every company', kop_operator_pages_is_index() && count($GLOBALS['kop_operator_index']['rows']) === count($index['ids']));
$kop_majors = array_values(array_filter($GLOBALS['kop_operator_index']['rows'], function ($r) { return $r['major']; }));
printf("  index: %d companies, %d major
", count($GLOBALS['kop_operator_index']['rows']), count($kop_majors));
ob_start();
include dirname(__DIR__) . '/templates/operator-index.php';
$kop_index_html = ob_get_clean();
file_put_contents($out_dir . '/_index.html', $kop_index_html);
$check('the index prints a card per major company', substr_count($kop_index_html, 'class="kop-oi-card"') === count($kop_majors));
$GLOBALS['kop_operator_index'] = null;

// Written history: drafts for admins only, links made safe.
$draft = array('history' => array('Founded in [1990](https://example.org/a) by <b>X</b>.'), 'historyStatus' => 'draft', 'historySources' => array('A source | https://example.org/s'));
$check('a draft is hidden from readers', kop_operator_history_written($draft, false) === null);
$w = kop_operator_history_written($draft, true);
$check('a draft is shown to admins', $w && $w['status'] === 'draft' && $w['sources'][0]['url'] === 'https://example.org/s');
$published = $draft; $published['historyStatus'] = 'published';
$check('a published history is shown to readers', kop_operator_history_written($published, false) !== null);
$html = kop_operator_history_paragraph_html($draft['history'][0]);
$check('history text is escaped and its links kept', strpos($html, '&lt;b&gt;') !== false && strpos($html, '<a href="https://example.org/a"') !== false, $html);
$check('a javascript: link stays text', strpos(kop_operator_history_paragraph_html('[x](javascript:alert(1))'), '<a') === false);
foreach ($index['alias_of'] as $dup => $lead) {
    $check("duplicate record $dup redirects to the canonical page", strpos($route((string) $dup), 'redirect 301 ' . home_url('/operator/' . $index['ids'][$lead]['slug'] . '/')) === 0);
}

// ---------------------------------------------------------------------------
// Every page
// ---------------------------------------------------------------------------

echo "\n-- Pages --\n";
$template = dirname(__DIR__) . '/templates/operator-page.php';
$with = array('timeline' => 0, 'people' => 0, 'program_docs' => 0, 'facilities' => 0, 'news' => 0, 'lawsuits' => 0, 'memorials' => 0, 'network' => 0, 'parents' => 0, 'subsidiaries' => 0);
$facility_links = 0;
$facility_pages = 0;
foreach ($index['ids'] as $id => $e) {
    $data = kop_operator_page_data($id);
    $check("page data for {$e['slug']}", is_array($data));
    if (!$data) continue;
    foreach (array('facilities', 'news', 'lawsuits', 'memorials', 'parents', 'subsidiaries') as $k) {
        if (!empty($data[$k])) $with[$k]++;
    }
    if ($data['network_url'] !== '') $with['network']++;
    if (!empty($data['timeline'])) $with['timeline']++;
    if (!empty($data['people']['leaders'])) $with['people']++;
    if (!empty($data['program_docs']['programs'])) $with['program_docs']++;
    foreach ($data['facilities'] as $f) {
        $facility_links++;
        if ($f['has_page']) $facility_pages++;
    }
    $GLOBALS['kop_operator_page'] = $data;
    ob_start();
    include $template;
    $html = ob_get_clean();
    file_put_contents($out_dir . '/' . $e['slug'] . '.html', $html);
    $check("{$e['slug']} renders its name", strpos($html, esc_html($data['name'])) !== false);
    if (count($data['facilities']) > 0) {
        $check("{$e['slug']} lists its programs", substr_count($html, 'kop-fp-siblings') === 1);
    }
}
printf("  pages with programs: %d, news: %d, lawsuits: %d, deaths on record: %d, parents: %d, subsidiaries: %d, on the network map: %d\n",
    $with['facilities'], $with['news'], $with['lawsuits'], $with['memorials'], $with['parents'], $with['subsidiaries'], $with['network']);
printf("  pages with a timeline: %d, people: %d, program documents: %d
", $with['timeline'], $with['people'], $with['program_docs']);
printf("  program links: %d, of which to a facility page: %d\n", $facility_links, $facility_pages);
echo "  rendered to $out_dir\n";

echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
