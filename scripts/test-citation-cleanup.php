<?php
/**
 * Citations on the generated /facility/ and /operator/ pages (inc/facility-pages.php):
 *   - a citation that only points back to us ("Kids Over Profits network map", a page of this site) is not shown;
 *   - the Woodbury Reports wording ("Woodbury Reports, February 2009, p. 20") is not printed or put in a preview,
 *     only the "source" link to our copy of the issue stays;
 *   - our copies of other people's documents (/wp-content/uploads/) still count as sources.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-citation-cleanup.php [--db=tmp/prod.sqlite] [--id=9607,...] [--no-db]
 *
 * --no-db runs only the function checks. Otherwise it also renders real pages (every facility the index
 * lists whose record mentions Woodbury Reports or the network map, up to 12) and checks the HTML.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'id::', 'no-db'));
$no_db = isset($args['no-db']);
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$want_ids = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();

if ($no_db) {
    $tmp = sys_get_temp_dir() . '/kop-citation-cleanup-' . getmypid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmp);
    $pdo->exec('CREATE TABLE facilities_v2 (id INTEGER PRIMARY KEY, unique_name TEXT, json_data TEXT, name TEXT)');
    register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
    $db_path = $tmp;
} elseif (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py, or pass --no-db).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/citations.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

echo "-- Woodbury wording --\n";
$clean = 'kop_facility_pages_woodbury_clean';
$check('a whole citation goes', $clean('Operator: CARE (Woodbury Reports, February 2009, p. 20)') === 'Operator: CARE', $clean('Operator: CARE (Woodbury Reports, February 2009, p. 20)'));
$check('years stay when only the source name is in the parentheses', $clean('Admissions (2009-2010, Woodbury Reports). Previously: Staff') === 'Admissions (2009-2010). Previously: Staff');
$check('issue number and page range', $clean('Opened in 1999 (Woodbury Reports, July 2007 (#155), pp. 21-22).') === 'Opened in 1999.');
$check('other parentheses are left alone', $clean('Runs two homes (boys and girls).') === 'Runs two homes (boys and girls).');
$check('text without the name is returned as it was', $clean('Plain text.') === 'Plain text.' && $clean('') === '');

echo "-- cited text --\n";
$url = 'https://example.test/wp-content/uploads/2024/12/woodbury-0209.pdf#page=20';
$html = kop_facility_pages_cited_html('Operator: CARE (Woodbury Reports, February 2009, p. 20) ' . $url);
$check('closed citation then address reads "(source)"', preg_match('#^Operator: CARE \(<a [^>]*>source</a>\)$#', $html) === 1, $html);
$check('no Woodbury wording in the link preview', stripos($html, 'woodbury reports') === false, $html);
$html = kop_facility_pages_cited_html('Opened (Woodbury Reports, May 2007, p. 20: ' . $url . ') in Utah.');
$check('open citation with the address inside reads "(source)"', preg_match('#^Opened \(<a [^>]*>source</a>\) in Utah\.$#', $html) === 1, $html);
$html = kop_facility_pages_cited_html('Form 10-K for 2003 (Form 10-K for 2003: https://example.org/10k).');
$check('other citations keep their words as before', strpos($html, 'Form 10-K for 2003') !== false && strpos($html, '>source</a>') !== false, $html);
$check('a note with no address is just escaped text', kop_facility_pages_cited_html('Fish & chips <b>') === 'Fish &amp; chips &lt;b&gt;');

echo "-- citations that are just us --\n";
$own = 'kop_facility_pages_is_own_source';
$check('network map citation', $own(array('source' => 'Network map', 'cite' => 'Kids Over Profits network map', 'url' => home_url('/network-map/#open=a'))));
$check('a page of this site', $own(array('source' => 'Facility page', 'cite' => '', 'url' => home_url('/facility/hyde-school-ct/'))));
$check('a relative address on this site', $own(array('source' => 'x', 'cite' => '', 'url' => '/network-map/')));
$check('another site is a source', !$own(array('source' => 'Reuters', 'cite' => 'Reuters, 2019', 'url' => 'https://www.reuters.com/a')));
$check('our copy of a Woodbury issue is a source', !$own(array('source' => 'Woodbury Reports, Oct 2010', 'cite' => 'Woodbury Reports, October 2010, p. 2', 'url' => home_url('/wp-content/uploads/2024/12/woodbury-1010.pdf#page=2'))));
$check('a source with no address and no name of ours is kept', !$own(array('source' => 'r/troubledteens wiki', 'cite' => 'r/troubledteens wiki: page "X"', 'url' => '')));
$tidy = kop_facility_pages_tidy_citations(array(
    array('text' => 'Brian Heath (Director)', 'source' => 'Network map', 'cite' => 'Kids Over Profits network map', 'url' => home_url('/network-map/#open=b'), 'name' => 'Brian Heath', 'from_map' => true),
    array('text' => 'Admissions (2009-2010, Woodbury Reports)', 'source' => 'Woodbury Reports, Oct 2010', 'cite' => 'Woodbury Reports, October 2010, p. 2', 'url' => home_url('/wp-content/uploads/2024/12/woodbury-1010.pdf')),
));
$check('tidy: the map entry loses its citation but keeps its name', $tidy[0]['source'] === '' && $tidy[0]['url'] === '' && $tidy[0]['name'] === 'Brian Heath' && $tidy[0]['from_map'] === true, json_encode($tidy[0]));
$check('tidy: the Woodbury entry keeps its link, loses the wording in its text', $tidy[1]['text'] === 'Admissions (2009-2010)' && $tidy[1]['url'] !== '', json_encode($tidy[1]));

// ---------------------------------------------------------------------------
// Real pages
// ---------------------------------------------------------------------------

if (!$no_db) {
    echo "-- real facility pages --\n";
    $index = kop_facility_pages_index(true);
    $ids = $want_ids;
    if (!$ids) {
        global $wpdb;
        $rows = $wpdb->get_col("SELECT id FROM facilities_v2 WHERE json_data LIKE '%Woodbury Reports%' ORDER BY id LIMIT 400");
        foreach ((array) $rows as $id) {
            if (isset($index['ids'][(int) $id])) $ids[] = (int) $id;
            if (count($ids) >= 12) break;
        }
    }
    $check('found facilities whose record mentions Woodbury Reports', count($ids) > 0, count($ids) . ' pages');
    $seen_source_link = 0;
    foreach ($ids as $id) {
        $data = kop_facility_page_data($id);
        if (!$data) { $check("data for $id", false, 'no row'); continue; }
        $GLOBALS['kop_facility_page'] = $data;
        ob_start();
        try {
            include dirname(__DIR__) . '/templates/facility-page.php';
        } catch (Throwable $e) {
            ob_end_clean();
            $check("render $id", false, $e->getMessage());
            continue;
        }
        $html = ob_get_clean();
        // The document library tiles can carry an issue's own title; look at the page's prose and citation links.
        $main = preg_replace('#<section class="kop-fp-section[^"]*" id="documents".*?</section>#s', '', $html);
        $links = array();
        preg_match_all('#<a [^>]*kop-citation-link[^>]*>.*?</a>#s', $main, $lm);
        foreach ($lm[0] as $a) $links[] = $a;
        $text = preg_replace('#<a [^>]*kop-citation-link[^>]*>.*?</a>#s', '', $main);
        $text = html_entity_decode(strip_tags($text));
        $previews = implode(' ', array_map(function ($a) { return preg_match('/data-kop-citation-preview="([^"]*)"/', $a, $m) ? $m[1] : ''; }, $links));
        $seen_source_link += count($links);
        $check("page $id {$data['name']}: no Woodbury wording in the text", stripos($text, 'Woodbury Reports') === false,
            stripos($text, 'Woodbury Reports') === false ? '' : trim(substr($text, max(0, stripos($text, 'Woodbury Reports') - 60), 140)));
        $check("page $id: no Woodbury wording in the previews", stripos($previews, 'Woodbury Reports') === false);
        $own_links = array_filter($links, function ($a) { return strpos($a, '/network-map/') !== false; });   // the "Connections on the network map" section links the map on purpose; a citation must not
        $check("page $id: no citation to our own map", stripos($previews, 'Kids Over Profits network map') === false && !$own_links, count($own_links) . ' links');
        foreach ($data['staff'] as $group => $people) {
            foreach ($people as $p) {
                if (!empty($p['from_map']) && $p['source'] !== '') $check("page $id: map person {$p['name']} has no source", false);
            }
        }
    }
    $check('the pages still carry "source" links', $seen_source_link > 0, $seen_source_link . ' links');
}

echo "\n" . ($failures ? "$failures FAILED\n" : "All checks passed\n");
exit($failures ? 1 : 0);
