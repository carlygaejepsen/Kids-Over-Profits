<?php
/**
 * Offline check of the name a renamed program is listed under on the
 * directory's parent company tab (kop_facility_eras_name_under(), stamped as
 * nameUnderOperator by kop_v2_get_facilities_projects()), against the SQLite
 * mirror of production (tmp/prod.sqlite). Read-only.
 *
 * Usage (Local's bundled PHP):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-name-under-operator.php [--db=tmp/prod.sqlite] [--list]
 *
 * --list prints every company link that gets another name.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/directory-feed.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Company names --\n";
$check('punctuation and suffixes do not matter', kop_facility_eras_company_key('Psychiatric Solutions, Inc.') === kop_facility_eras_company_key('Psychiatric Solutions Inc'));
$check('an abbreviation in brackets does not matter', kop_facility_eras_company_key('Universal Health Services (UHS)') === kop_facility_eras_company_key('Universal Health Services'));

$raw = $wpdb->get_var("SELECT option_value FROM wpdl_options WHERE option_name = 'kop_network_rename_review'");
$decisions = $raw ? @unserialize($raw) : array();
$GLOBALS['kop_test_options']['kop_network_rename_review'] = is_array($decisions) ? $decisions : array();

echo "\n-- Every company link (from the mirror) --\n";
$rows = $wpdb->get_results(
    "SELECT ofc.operator_id, ofc.facility_id, ofc.relationship, o.name AS oname, o.json_data AS ojson, f.name AS fname, f.json_data
       FROM wpdl_kop_operator_facilities ofc
       JOIN wpdl_kop_operators o ON o.id = ofc.operator_id
       JOIN facilities_v2 f ON f.id = ofc.facility_id",
    ARRAY_A
);
$found = array();
foreach ($rows as $r) {
    $doc = json_decode($r['json_data'], true);
    $o = json_decode($r['ojson'], true);
    $o = is_array($o['operator'] ?? null) ? $o['operator'] : array();
    $names = array_merge(array($r['oname']), array_filter((array) ($o['otherNames'] ?? array()), 'is_string'), array_filter((array) ($o['pastNames'] ?? array()), 'is_string'));
    $then = kop_facility_eras_name_under((int) $r['facility_id'], is_array($doc) ? $doc : array(), $names, (string) $r['relationship']);
    if ($then === null) continue;
    $found[$r['operator_id'] . ':' . $r['facility_id']] = $then['name'];
    if ($list) echo "  {$r['oname']} ({$r['relationship']}): {$r['fname']} listed as {$then['name']}" . ($then['years'] !== '' ? " ({$then['years']})" : '') . "\n";
}
$check('some company links get the name of their years', count($found) > 0, count($found) . ' of ' . count($rows) . ' links');

// Known cases: [company, facility id, expected name ('' = the record's own)].
$by_name = array();
foreach ($rows as $r) $by_name[$r['oname'] . ':' . $r['facility_id']] = $r['operator_id'] . ':' . $r['facility_id'];
$cases = array(
    array('Three Springs Inc.', 11095, 'Three Springs of Marion'),
    array('Youth Opportunity Investments', 11095, ''),
    array('Sequel TSI', 11144, 'Charles Britt Academy'),
    array('Wayne Halfway House', 11144, ''),
    array('Psychiatric Solutions, Inc.', 10370, 'Rivendell of Utah'),
    array('Universal Health Services (UHS)', 10370, ''),
    array('Three Springs Inc.', 13690, 'Three Springs Paint Rock Valley'),
    array('The Brown Schools', 10815, 'The Brown Schools at Laurel Ridge'),
    array('Psychiatric Solutions, Inc.', 10815, ''),
);
foreach ($cases as $c) {
    $k = $by_name[$c[0] . ':' . $c[1]] ?? null;
    if ($k === null) {
        echo "SKIP {$c[0]} / {$c[1]}: no such link in this mirror\n";
        continue;
    }
    $got = $found[$k] ?? '';
    $check("{$c[0]} lists {$c[1]} as " . ($c[2] !== '' ? $c[2] : 'its own name'), $got === $c[2], $got !== '' ? $got : 'own name');
}

echo "\n-- The directory feed --\n";
$feed = kop_v2_get_facilities_projects();
$stamped = 0;
$wrong = array();
foreach ($feed['projects'] as $p) {
    if (($p['source_table'] ?? '') !== 'facilities_master' || empty($p['id'])) continue;
    foreach ((array) ($p['data']['facilities'] ?? array()) as $f) {
        $want = $found[$p['id'] . ':' . ($f['facility_id'] ?? 0)] ?? '';
        $got = (string) ($f['nameUnderOperator'] ?? '');
        if ($got !== '') $stamped++;
        if ($got !== $want) $wrong[] = "{$p['label']} / " . ($f['facility_id'] ?? '?') . ": '$got', expected '$want'";
    }
    if (isset($p['data']['facilities'][0])) {
        $slim = kop_directory_feed_slim_facility($p['data']['facilities'][0]);
    }
}
$check('each company\'s copy carries its own name then', !$wrong, $wrong ? implode('; ', array_slice($wrong, 0, 5)) : $stamped . ' stamped');
$location_stamped = 0;
foreach ($feed['projects'] as $p) {
    if (($p['category'] ?? '') !== 'locations') continue;
    foreach ((array) ($p['data']['facilities'] ?? array()) as $f) if (!empty($f['nameUnderOperator'])) $location_stamped++;
}
$check('state and country lists keep the record\'s own name', $location_stamped === 0, $location_stamped . ' stamped');
$sample = array('facility_id' => 1, 'nameUnderOperator' => 'X', 'nameUnderOperatorYears' => '1995 to 1999');
$slim = kop_directory_feed_slim_facility($sample);
$check('the index feed keeps the name then', ($slim['nameUnderOperator'] ?? '') === 'X' && ($slim['nameUnderOperatorYears'] ?? '') === '1995 to 1999');

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
