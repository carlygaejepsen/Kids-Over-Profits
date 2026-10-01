<?php
/**
 * Offline checks for inc/inspection-links.php: the suggestions for one
 * state's records against a scraper's inspection rows, the save step, and a
 * facility page picking up a linked row.
 *
 *   php scripts/test-inspection-links.php --file=<pa_scraper --out json> [--state=PA] [--db=tmp/prod.sqlite]
 *
 * The records come from facilities_v2 in the mirror; the inspection rows from
 * the --out file (ids are their position, the mirror may not hold the state
 * yet). Prints the suggestions, so the list the review screen will show can
 * be read before it is deployed.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'file:', 'state::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$file = $args['file'] ?? '';
$state = strtoupper($args['state'] ?? 'PA');
if (!file_exists($db_path) || !is_readable($file)) {
    fwrite(STDERR, "Usage: php scripts/test-inspection-links.php --file=<scraper --out json> [--state=PA] [--db=tmp/prod.sqlite]\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/inspection-links.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};

// ---- Suggestions ------------------------------------------------------------
$records = kop_inspection_link_records($state);
$out = json_decode(file_get_contents($file), true);
$rows = array();
foreach ($out['facilities'] as $i => $f) {
    $rows[] = array('id' => 900000 + $i, 'facility_name' => $f['facility_info']['facility_name'],
                    'full_address' => $f['facility_info']['full_address'], 'reports' => count($f['reports']));
}
$empty = array('links' => array(), 'rejected' => array(), 'updated' => 0);
$suggestions = kop_inspection_link_suggestions($records, $rows, $empty);
$by_record = array();
$ticked = 0;
foreach ($suggestions as $s) {
    $by_record[$s['record']['name']] = $s;
    foreach ($s['candidates'] as $c) $ticked += $c['same_town'] ? 1 : 0;
}
echo count($records) . " records, " . count($rows) . " inspection rows, " . count($suggestions) . " records with suggestions, $ticked pairs ticked\n";
foreach ($suggestions as $s) {
    echo '  ' . $s['record']['name'] . ' [' . $s['record']['city'] . ']' . "\n";
    foreach ($s['candidates'] as $c) {
        echo '      ' . ($c['same_town'] ? '[x] ' : '[ ] ') . $c['row']['facility_name'] . ' | ' . $c['row']['full_address'] . "\n";
    }
}

$names_of = function ($record) use ($by_record) {
    return isset($by_record[$record]) ? array_map(function ($c) { return $c['row']['facility_name']; }, $by_record[$record]['candidates']) : array();
};
$ticked_of = function ($record) use ($by_record) {
    $out = array();
    foreach ($by_record[$record]['candidates'] ?? array() as $c) if ($c['same_town']) $out[] = $c['row']['facility_name'];
    return $out;
};

if ($state === 'PA') {
    $check('Adelphoi Benet Home -> Adelphoi Village: Benet, ticked',
        in_array('Adelphoi Village: Benet', $ticked_of('Adelphoi Benet Home'), true), implode('; ', $names_of('Adelphoi Benet Home')));
    $check('Sarah Reed gets its halls', count($names_of('Sarah Reed Children’s Center Residential Treatment')) >= 3,
        implode('; ', $names_of('Sarah Reed Children’s Center Residential Treatment')));
    $wrong = array_filter($ticked_of('Circle C Youth Center'), function ($n) { return stripos($n, 'Sandalwood') !== false; });
    $check('Circle C is not ticked against Sandalwood Circle', !$wrong, implode('; ', $wrong));
    $check('a record the name rule already reaches is not suggested (George Junior Republic cottages)',
        !array_filter($names_of('George Junior Republic'), function ($n) { return stripos($n, 'Cottage') !== false; }));
}

// ---- Save, reject, unlink ---------------------------------------------------
// The harness's update_option() stores nothing; the count of decisions saved is checked.
$GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] = $empty;
$fid = $records ? $records[0]['id'] : 1;
$n = kop_inspection_links_save(array('decide' => array("$fid-11" => 'link', "$fid-12" => 'reject', "$fid-13" => 'later')));
$check('save counts link and reject, not later', $n === 2, (string) $n);

// ---- A facility page picks up a linked row ----------------------------------
require_once dirname(__DIR__) . '/inc/facility-pages.php';
global $wpdb;
$pa = $wpdb->get_row("SELECT id, name FROM facilities_v2 WHERE state = 'PA' AND name = 'Adelphoi Benet Home'", ARRAY_A);
$other = $wpdb->get_row("SELECT id, state, facility_name FROM inspection_facilities ORDER BY id LIMIT 1", ARRAY_A);
if ($pa && $other) {
    $without = kop_facility_pages_inspections(array(kop_facility_pages_name_key($pa['name'])), 'PA', 'Pennsylvania', (int) $pa['id']);
    $GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] = array(
        'links' => array((int) $pa['id'] => array((int) $other['id'])), 'rejected' => array(), 'updated' => 1);
    $with = kop_facility_pages_inspections(array(kop_facility_pages_name_key($pa['name'])), 'PA', 'Pennsylvania', (int) $pa['id']);
    $check('no link: the name rule finds nothing for Adelphoi Benet Home in the mirror', $without === null);
    $check('linked row appears on the facility page', is_array($with) && in_array($other['facility_name'], $with['summary']['licensed_names'], true),
        is_array($with) ? implode('; ', $with['summary']['licensed_names']) : 'null');
    $check('the fingerprint moves with the links', strpos(kop_inspection_links_cache_key(), '1:') === 0);
    $GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] = $empty;
} else {
    $check('mirror has the records the page check needs', false);
}

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
