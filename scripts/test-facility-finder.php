<?php
/**
 * Offline checks for the admin facility finder (inc/facility-finder.php)
 * against the production mirror in tmp/prod.sqlite, plus a guard that no
 * admin screen asks for a bare facility id without it.
 *
 *   php scripts/test-facility-finder.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Search --\n";
$names = function ($rows) { return array_map(static function ($r) { return $r['name'] . ' #' . $r['id']; }, $rows); };

$r = kop_facility_finder_search($pdo, 'Wellspring');
$check('"Wellspring" finds Wellspring Academies', in_array(9741, array_column($r, 'id'), true), implode('; ', array_slice($names($r), 0, 4)));

$r = kop_facility_finder_search($pdo, 'Academy of the Sierras');
$hit = array_values(array_filter($r, static function ($x) { return $x['id'] === 9741; }));
$check('a past name finds the facility and says which name matched', $hit && $hit[0]['matched'] === 'Academy of the Sierras');

$r = kop_facility_finder_search($pdo, '9741');
$check('an id finds that facility first', $r && $r[0]['id'] === 9741);

$r = kop_facility_finder_search($pdo, 'academy');
$check('a common word is capped at 12', count($r) === 12);
$starts = array_map(static function ($x) { return stripos($x['name'], 'academy') === 0; }, $r);
$check('names starting with the word sort first', $starts[0] === true, $r[0]['name']);

$check('empty query returns nothing', kop_facility_finder_search($pdo, '  ') === array());
$check('LIKE wildcards are literal', kop_facility_finder_search($pdo, '%%%') === array());
$check('rows carry place and status', isset($r[0]['state'], $r[0]['status'], $r[0]['city']));

echo "-- Field --\n";
$html = kop_facility_finder_field('kop_cr_facility', 42);
$check('field keeps the form name and value', strpos($html, 'name="kop_cr_facility"') !== false && strpos($html, 'value="42"') !== false);
$check('field is marked for the finder', strpos($html, 'data-kop-facility-finder') !== false);
$check('empty id renders an empty box', strpos(kop_facility_finder_field('x', 0), 'value=""') !== false);

echo "-- Every admin facility id box has the finder --\n";
$bare = array();
foreach (array_merge(glob(dirname(__DIR__) . '/inc/*.php'), glob(dirname(__DIR__) . '/api/*.php')) as $file) {
    foreach (file($file) as $n => $line) {
        if (preg_match('/facility\s*id\s*<input/i', $line)) {
            $bare[] = basename($file) . ':' . ($n + 1);
        }
    }
}
$check('no "facility id <input" left without the finder', !$bare, implode(', ', $bare));
foreach (array('closure-reports.php', 'facility-discovery.php', 'woodbury-mentions.php') as $f) {
    $check("$f uses kop_facility_finder_field()", strpos(file_get_contents(dirname(__DIR__) . '/inc/' . $f), 'kop_facility_finder_field(') !== false);
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
