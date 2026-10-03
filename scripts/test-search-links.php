<?php
/**
 * Offline checks that search results link to the record's own page: a
 * facility to /facility/<slug>/, a company to /operator/<slug>/, a wiki
 * entry or inspection row to the facility page of the same program, and
 * only fall back to a state hub or a directory search when there is none.
 * Covers the header dropdown (inc/ajax-search-lite.php), the search bar
 * (inc/global-search.php) and the results page (search.php, by source).
 * Runs against tmp/prod.sqlite; writes nothing.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-search-links.php [--db=tmp/prod.sqlite]
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
require_once dirname(__DIR__) . '/inc/inspection-links.php';
require_once dirname(__DIR__) . '/inc/ajax-search-lite.php';
require_once dirname(__DIR__) . '/inc/global-search.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$facility_base = home_url('/' . kop_facility_pages_base() . '/');
$operator_base = home_url('/' . kop_operator_pages_base() . '/');
// An editorial Facility Profile post (kop_facility_page_url()'s first choice) is the page too.
$editorial = array();
foreach (kop_facility_pages_index()['ids'] as $entry) if ($entry['editorial'] !== '') $editorial[$entry['editorial']] = true;
$is_profile = function ($url) use ($facility_base, $operator_base, $editorial) {
    return strpos($url, $facility_base) === 0 || strpos($url, $operator_base) === 0 || isset($editorial[$url]);
};

echo "-- kop_v2_search() results --\n";
foreach (array('Aspen', 'Provo Canyon', 'Sequel', 'Trails') as $phrase) {
    $v2 = kop_v2_search($phrase, 20, 8, 0);
    $with_page = 0;
    $missed = array();
    foreach (array_merge($v2['operators'], $v2['facilities']) as $r) {
        $url = kop_search_v2_result_url($r);
        $has_page = !empty($r['profile_url'])
            || ($r['kind'] === 'operator' && kop_operator_page_url_for_name($r['display']) !== '');
        if (!$has_page) continue;
        $with_page++;
        $want = !empty($r['profile_url']) ? $r['profile_url'] : kop_operator_page_url_for_name($r['display']);
        if (!$is_profile($url) || $url !== $want) $missed[] = $r['display'] . ' -> ' . $url;
    }
    $check("\"$phrase\": every result with a page links to it", !$missed && $with_page > 0, $with_page . ' with a page' . ($missed ? '; ' . implode('; ', array_slice($missed, 0, 3)) : ''));
}

// The bug report: "Aspen Ranch" linked to /utah/ on the results page.
$v2 = kop_v2_search('Aspen Ranch', 5, 0, 0);
$ranch = null;
foreach ($v2['facilities'] as $r) if ($r['display'] === 'Aspen Ranch') $ranch = $r;
if ($ranch && !empty($ranch['profile_url'])) {
    $check('Aspen Ranch links to its facility page, not its state page', strpos(kop_search_v2_result_url($ranch), $facility_base) === 0, kop_search_v2_result_url($ranch));
}
$fake = array('kind' => 'facility', 'display' => 'No Such Place', 'profile_url' => '', 'url' => home_url('/utah/'));
$check('a facility with no page still falls back to its state page', kop_search_v2_result_url($fake) === home_url('/utah/'));
$fake = array('kind' => 'operator', 'display' => 'Zzqx Holdings Nobody', 'profile_url' => '', 'url' => '');
// The harness has no directory page, so '' (callers then link ?s=); on the site, ?search=.
$fallback = kop_search_v2_result_url($fake);
$check('a company with no page falls back to the directory search', $fallback === '' || strpos($fallback, 'search=') !== false, $fallback);

echo "-- Names from other tables --\n";
$index = kop_facility_pages_index();
$sample = array_slice($index['ids'], 0, 400, true);
$right = 0;
$tried = 0;
foreach ($sample as $id => $entry) {
    if (!preg_match('/-([a-z]{2})(?:-|$)/', substr($entry['slug'], strlen(sanitize_title($entry['name']))), $m)) continue;
    $tried++;
    $url = kop_search_record_page_url($entry['name'], $m[1]);
    // Two pages of one name in one state: either is a right answer.
    if ($url !== '' && $is_profile($url)) $right++;
}
$check('a facility name + its state finds a facility page', $tried > 0 && $right === $tried, "$right of $tried");

$wrong_state = 0;
foreach (array_slice($sample, 0, 100, true) as $id => $entry) {
    if (!preg_match('/-([a-z]{2})(?:-|$)/', substr($entry['slug'], strlen(sanitize_title($entry['name']))), $m)) continue;
    $other = $m[1] === 'zz' ? 'qq' : 'zz';
    if (kop_search_record_page_url($entry['name'], $other) !== '') $wrong_state++;
}
$check('the same name in another state finds nothing', $wrong_state === 0, "$wrong_state wrong");

$rows = $wpdb->get_results("SELECT id, facility_name, state FROM inspection_facilities ORDER BY id LIMIT 4000", ARRAY_A);
$linked = 0;
$checked_rows = 0;
foreach ((array) $rows as $row) {
    $url = kop_search_inspection_page_url($row['id'], $row['facility_name'], $row['state']);
    if ($url === '') continue;
    $checked_rows++;
    if ($is_profile($url)) $linked++;
}
$check('inspection rows that match a record link to its facility page', $checked_rows > 0 && $linked === $checked_rows, "$linked of $checked_rows matched, " . count((array) $rows) . ' rows read');

echo "-- Header dropdown and search bar --\n";
$GLOBALS['kop_test_search_report_pages'] = true;
$items = kop_asl_collect_database_matches('Aspen Ranch');
$hit = null;
foreach ($items as $it) if ($it['title'] === 'Aspen Ranch') { $hit = $it; break; }
$check('header dropdown: Aspen Ranch -> facility page', $hit && $is_profile($hit['link']), $hit ? $hit['link'] : 'not found');

$groups = kop_global_search_collect('Aspen Ranch');
$hit = null;
foreach ($groups as $g) foreach ($g['items'] as $it) if ($it['title'] === 'Aspen Ranch' && $g['key'] === 'facilities') $hit = $it;
$check('search bar: Aspen Ranch -> facility page', $hit && $is_profile($hit['url']), $hit ? $hit['url'] : 'not found');

$groups = kop_global_search_collect('Aspen Education');
$hit = null;
// Companies are listed before facilities; take the first hit (the company).
foreach ($groups as $g) foreach ($g['items'] as $it) if (!$hit && stripos($it['title'], 'Aspen Education') === 0) $hit = $it;
if ($hit && kop_operator_page_url_for_name($hit['title']) !== '') {
    $check('search bar: Aspen Education Group -> company page', strpos($hit['url'], $operator_base) === 0, $hit['url']);
}

echo "-- search.php --\n";
$src = file_get_contents(dirname(__DIR__) . '/search.php');
$check('results page links v2 results through kop_search_v2_result_url()', strpos($src, 'kop_search_v2_result_url(') !== false);
$check('results page links wiki entries to their program page', strpos($src, 'kop_search_record_page_url($name)') !== false);
$check('results page links inspection rows to their facility page', strpos($src, 'kop_search_inspection_page_url(') !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
