<?php
/**
 * Offline checks for the facility name resolver and suggestions in
 * inc/facility-suggest.php, run on the real "Refers young people to" list of
 * the Billings Clinic provider record (providers_master) in the production
 * mirror. Prints which names resolved to which pages, which were ambiguous and
 * which matched nothing; asserts an ambiguous name never links. Writes nothing.
 *
 *   php scripts/test-facility-links.php [--db=tmp/prod.sqlite]
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
require_once dirname(__DIR__) . '/inc/facility-suggest.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Splitting a pasted list --\n";
$check('one name stays one entry', kop_facility_link_split_list('Provo Canyon School') === array('Provo Canyon School'));
$check('one comma stays one entry', kop_facility_link_split_list('Youth Care, Inc.') === array('Youth Care, Inc.'));
$check('a pasted list splits on its commas', kop_facility_link_split_list('A,  B,  C') === array('A', 'B', 'C'));
$check('an empty entry has no names', kop_facility_link_split_list('  ') === array());

echo "-- Key --\n";
$check('case and punctuation do not matter', kop_facility_link_key("St. Mary's Ranch") === kop_facility_link_key('st marys   ranch'));

echo "-- Billings Clinic list --\n";
$prov = $pdo->query("SELECT unique_name, json_data FROM providers_master WHERE unique_name LIKE '%MONTANA%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check('the Billings record is in the mirror', (bool) $prov);
$names = array();
if ($prov) {
    $doc = json_decode($prov['json_data'], true);
    $data = isset($doc['data']) ? $doc['data'] : $doc;
    foreach ((array) ($data['facilities'] ?? array()) as $site) {
        foreach ((array) ($site['providerDetails']['ttiReferrals'] ?? array()) as $entry) {
            foreach (kop_facility_link_split_list($entry) as $n) $names[] = $n;
        }
    }
}
$check('the list splits into many names', count($names) >= 30, count($names) . ' names');

$t0 = microtime(true);
$res = kop_facility_link_resolve($names);
$first = microtime(true) - $t0;
$t0 = microtime(true);
$again = kop_facility_link_resolve($names);
$second = microtime(true) - $t0;
$check('a second call returns the same answer', $again === $res);
$check('every name got an answer', count($res) === count(array_unique($names)));

$by = array('linked' => array(), 'ambiguous' => array(), 'nopage' => array(), 'unmatched' => array());
foreach ($res as $n => $r) $by[$r['status']][] = $n;
printf("linked %d, ambiguous %d, matched but no page %d, unmatched %d (resolved in %.2fs, cached %.3fs)\n",
    count($by['linked']), count($by['ambiguous']), count($by['nopage']), count($by['unmatched']), $first, $second);
foreach ($by['linked'] as $n) echo "  LINKED     $n -> " . str_replace('https://example.test', '', $res[$n]['url']) . "\n";
foreach ($by['ambiguous'] as $n) echo "  AMBIGUOUS  $n ({$res[$n]['matches']} records)\n";
foreach ($by['nopage'] as $n) echo "  NO PAGE    $n\n";
foreach ($by['unmatched'] as $n) echo "  UNMATCHED  $n\n";

foreach ($res as $n => $r) {
    if ($r['status'] !== 'linked' && $r['url'] !== '') $check("'$n' is not linked but carries a url", false);
}
$check('an ambiguous, unmatched or page-less name never links', !array_filter($res, function ($r) {
    return $r['status'] !== 'linked' && $r['url'] !== '';
}));
$check('every link is a /facility/<slug>/ page', !array_filter($by['linked'], function ($n) use ($res) {
    return !preg_match('#/facility/[^/]+/$#', $res[$n]['url']) || strpos($res[$n]['url'], 'tti-program-index') !== false;
}));

echo "-- Ambiguity is never guessed --\n";
// A name two records carry (found in the mirror, not assumed).
$map = kop_facility_link_name_ids();
$dup = null;
foreach ($map as $key => $ids) {
    if (count($ids) > 1 && strlen($key) > 8) { $dup = $key; break; }
}
$check('the mirror has a name two records share', $dup !== null);
if ($dup !== null) {
    $r = kop_facility_link_resolve(array($dup));
    $check("'$dup' (" . count($map[$dup]) . ' records) does not link', $r[$dup]['status'] === 'ambiguous' && $r[$dup]['url'] === '');
}
// A name with no record.
$r = kop_facility_link_resolve(array('Zzyzx Imaginary Academy'));
$check('an unknown name is unmatched with no link', $r['Zzyzx Imaginary Academy']['status'] === 'unmatched' && $r['Zzyzx Imaginary Academy']['url'] === '');
// A unique, paged record links, whatever the case or punctuation.
$id = 0;
foreach (kop_facility_pages_index()['ids'] as $pid => $e) {
    $ids = $map[kop_facility_link_key($e['name'])] ?? array();
    if (count($ids) === 1 && $ids[0] === $pid) { $id = $pid; $name = $e['name']; break; }
}
$check('a unique paged record is in the mirror', $id > 0);
if ($id > 0) {
    $r = kop_facility_link_resolve(array(strtoupper($name), $name));
    $url = kop_facility_page_url($id);
    $check("'$name' links in any case", $r[strtoupper($name)]['url'] === $url && $r[$name]['url'] === $url, $url);
}
// A past name resolves to the record that carries it, when only one does.
$past = null;
foreach ($pdo->query("SELECT id, json_data FROM facilities_v2") as $row) {
    $d = json_decode($row['json_data'], true);
    foreach (kop_v2_search_name_list($d['identification']['pastNames'] ?? null) as $pn) {
        $k = kop_facility_link_key($pn);
        if ($k !== '' && count($map[$k] ?? array()) === 1 && kop_facility_page_url((int) $row['id']) !== '') { $past = array((int) $row['id'], $pn); break 2; }
    }
}
if ($past) {
    $r = kop_facility_link_resolve(array($past[1]));
    $check("past name '{$past[1]}' links to its record's page", $r[$past[1]]['url'] === kop_facility_page_url($past[0]));
}

echo "-- Suggestions --\n";
$check('under 3 characters suggests nothing', kop_facility_suggest('Pr') === array());
foreach (array('Provo', 'Copper Canyon') as $q) {
    $s = kop_facility_suggest($q, 8);
    $check("'$q' suggests records", count($s) > 0, count($s) . ' items');
    foreach (array_slice($s, 0, 6) as $i) {
        echo '    ' . $i['name'] . ' | ' . $i['place'] . ($i['status'] !== '' ? ' (' . $i['status'] . ')' : '') . ($i['hint'] !== '' ? ' | ' . $i['hint'] : '') . "\n";
    }
}
$s = kop_facility_suggest('Copper Canyon', 10);
$check("'Copper Canyon' offers Sedona Sky Academy as a past-name hit", (bool) array_filter($s, function ($i) {
    return stripos($i['name'], 'Sedona Sky') !== false && stripos($i['hint'], 'Formerly') === 0;
}));
$check('suggestions carry only name, place, status and hint', !array_filter($s, function ($i) {
    return array_keys($i) !== array('name', 'place', 'status', 'hint');
}));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
