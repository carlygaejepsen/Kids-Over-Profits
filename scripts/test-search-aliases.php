<?php
/**
 * Offline checks that kop_v2_search() (inc/facility-v2-readers.php) finds a
 * facility by a past or other name stored in identification.pastNames /
 * otherNames, not just its current name — the header search dropdown and the
 * site-wide search widget (inc/ajax-search-lite.php, inc/global-search.php,
 * search.php) all read this function. Runs against the production mirror in
 * tmp/prod.sqlite; writes nothing.
 *
 *   php scripts/test-search-aliases.php [--db=tmp/prod.sqlite]
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
require_once dirname(__DIR__) . '/inc/ajax-search-lite.php';
require_once dirname(__DIR__) . '/inc/global-search.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$find = function (array $facilities, $id) {
    foreach ($facilities as $f) {
        if ((int)$f['_id'] === (int)$id) return $f;
    }
    return null;
};

// kop_v2_search() doesn't carry the row id out to callers today (they only
// need display/url); pull it back in for the test via a thin wrapper that
// mirrors the real id lookup the function already does internally.
$search_with_ids = function ($phrase, $facility_limit = 10) use ($wpdb) {
    $out = kop_v2_search($phrase, $facility_limit, 0, 0);
    foreach ($out['facilities'] as &$f) {
        // profile_url is kop_facility_page_url($id) when a page exists, but
        // not every record has one; resolve the id directly instead.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM facilities_v2 WHERE name = %s LIMIT 1", $f['display']
        ), ARRAY_A);
        $f['_id'] = $row ? (int)$row['id'] : 0;
    }
    unset($f);
    return $out['facilities'];
};

echo "-- Current-name hits still work --\n";
$r = $search_with_ids('Wellspring', 20);
$check('"Wellspring" finds a Wellspring facility', (bool) array_filter($r, function ($f) { return stripos($f['display'], 'Wellspring') !== false; }));
$hit = $find($r, 9741);
if ($hit) {
    $check('current-name hit carries no alias hint', empty($hit['matched_name']));
}

echo "-- Past/other-name hits (the v2-switch regression) --\n";

// "Copper Canyon" is both a separate record's own name (Copper Canyon Academy,
// #12155) AND a past name on Sedona Sky Academy (#12161, renamed from it).
// The 3.7 name-era rule: the separate record's own name match must still
// win the top spot; the alias hit on the other record is additional, not a
// replacement.
$r = $search_with_ids('Copper Canyon', 10);
$cc = $find($r, 12155);
$sedona = $find($r, 12161);
$check('"Copper Canyon" finds Copper Canyon Academy (its own record)', $cc !== null);
if ($cc) $check('...with no alias hint (it is a direct name match)', empty($cc['matched_name']));
$check('"Copper Canyon" also finds Sedona Sky Academy (renamed from it)', $sedona !== null, $sedona ? $sedona['display'] : implode(', ', array_column($r, 'display')));
if ($sedona) {
    $check('...flagged as a past-name hit', $sedona['matched_kind'] === 'past' && stripos($sedona['matched_name'], 'Copper Canyon') !== false, json_encode($sedona['matched_name']) . ' / ' . json_encode($sedona['matched_kind']));
}
if ($cc && $sedona) {
    $ccPos = array_search(12155, array_column($r, '_id'), true);
    $sedonaPos = array_search(12161, array_column($r, '_id'), true);
    $check('the direct name match ranks before the alias match', $ccPos < $sedonaPos, "cc@$ccPos sedona@$sedonaPos");
}

// "Bethel Boys Academy" is both the name_key match of a separate record
// (Bethel Boys' Academy, #100180 - the apostrophe is stripped by
// kop_facility_name_key()) AND a curated otherName on Eagle Point Christian
// Academy (#13927). Same name-era shape as Copper Canyon above: the direct
// match must still outrank the alias hit on the other record.
$r = $search_with_ids('Bethel Boys Academy', 10);
$bethel = $find($r, 100180);
$eagle = $find($r, 13927);
$check('"Bethel Boys Academy" finds Bethel Boys\' Academy (its own record)', $bethel !== null, implode(', ', array_column($r, 'display')));
if ($bethel) $check('...with no alias hint (it is a direct name match)', empty($bethel['matched_name']));
$check('"Bethel Boys Academy" also finds Eagle Point Christian Academy', $eagle !== null, implode(', ', array_column($r, 'display')));
if ($eagle) {
    $check('...flagged as an other-name hit', $eagle['matched_kind'] === 'other' && $eagle['matched_name'] === 'Bethel Boys Academy');
}
if ($bethel && $eagle) {
    $bethelPos = array_search(100180, array_column($r, '_id'), true);
    $eaglePos = array_search(13927, array_column($r, '_id'), true);
    $check('the direct name match ranks before the alias match', $bethelPos < $eaglePos, "bethel@$bethelPos eagle@$eaglePos");
}

echo "-- Record-detail hits --\n";
$detail_hit = null;
foreach ($wpdb->get_results('SELECT id, name, unique_name, json_data FROM facilities_v2 ORDER BY name', ARRAY_A) as $row) {
    $doc = json_decode($row['json_data'], true);
    if (!is_array($doc)) continue;
    $ident = isset($doc['identification']) && is_array($doc['identification']) ? $doc['identification'] : array();
    $names = array_merge(
        array($row['name'], $row['unique_name'], $ident['name'] ?? '', $ident['currentName'] ?? ''),
        kop_v2_search_name_list($ident['pastNames'] ?? null),
        kop_v2_search_name_list($ident['otherNames'] ?? null)
    );
    $protected = implode(' ', array_filter($names, 'is_string'));
    preg_match_all('/(?<![\pL\pN])[\pL][\pL\pN\'-]{5,}(?![\pL\pN])/u', $row['json_data'], $matches);
    foreach (array_unique($matches[0]) as $term) {
        if (mb_stripos($protected, $term) !== false) continue;
        $count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE json_data LIKE %s', '%' . $wpdb->esc_like($term) . '%'));
        if ($count !== 1) continue;
        $detail_hit = array('id' => (int)$row['id'], 'term' => $term);
        break 2;
    }
}
if ($detail_hit) {
    $r = $search_with_ids($detail_hit['term'], 500);
    $hit = $find($r, $detail_hit['id']);
    $check('a unique field in facilities_v2.json_data finds its facility', $hit !== null, $detail_hit['term']);
} else {
    $check('found a searchable detail-only fixture in the mirror', false);
}

echo "-- Inspection PDF text --\n";
$report_text_hit = null;
$report_rows = $wpdb->get_results(
    "SELECT r.id, r.raw_content, r.summary, r.categories_json, f.facility_name, f.state
       FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id
      WHERE r.raw_content <> '' ORDER BY r.report_date DESC, r.id DESC LIMIT 30",
    ARRAY_A
);
$term_counts = array();
foreach ($report_rows as $row) {
    preg_match_all('/(?<![\pL\pN])[\pL][\pL\pN\'-]{7,}(?![\pL\pN])/u', $row['raw_content'], $matches);
    foreach (array_unique(array_map('mb_strtolower', $matches[0])) as $term) {
        $term_counts[$term] = ($term_counts[$term] ?? 0) + 1;
    }
}
foreach (array_slice($report_rows, 0, 5) as $row) {
    $protected = implode(' ', array($row['facility_name'], $row['summary'], $row['categories_json']));
    preg_match_all('/(?<![\pL\pN])[\pL][\pL\pN\'-]{7,}(?![\pL\pN])/u', $row['raw_content'], $matches);
    foreach (array_unique($matches[0]) as $term) {
        if (($term_counts[mb_strtolower($term)] ?? 0) !== 1 || mb_stripos($protected, $term) !== false) continue;
        $report_text_hit = array('term' => $term, 'facility' => $row['facility_name'], 'state' => strtolower($row['state']));
        break 2;
    }
}
if ($report_text_hit) {
    $GLOBALS['kop_test_search_report_pages'] = true;
    $matches = kop_global_search_inspection_text_matches($report_text_hit['term'], 5);
    $found = false;
    foreach ($matches as $match) {
        if ($match['title'] === $report_text_hit['facility']) {
            // Its facility page when a record matches the row, else the state report page.
            $found = strpos($match['url'], '/' . $report_text_hit['state'] . '-reports/') !== false
                || strpos($match['url'], '/' . kop_facility_pages_base() . '/') !== false;
            break;
        }
    }
    $check('an extracted PDF-text term returns its state inspection result', $found, $report_text_hit['term']);
} else {
    $check('found a unique extracted inspection-text fixture in the mirror', false);
}

echo "-- Guards --\n";

// The length gate: "Co" (2 chars) is a substring of "Copper Canyon Academy"
// (Sedona Sky's past name) but not of "Sedona Sky Academy" itself, so Sedona
// Sky must NOT appear for a query this short, however high the limit.
$r = $search_with_ids('Co', 200);
$check('a 2-character query does not trigger alias matching', $find($r, 12161) === null);

echo "-- Alternate names always show up --\n";
// Eight records are named "Aspen ..."; with room for three, the past name on
// Viewpoint Center (#10361, formerly Aspen Institute for Behavioral
// Assessment) used to be crowded out. A third of the slots go to alias hits.
$r = $search_with_ids('Aspen', 3);
$vp = $find($r, 10361);
$check('"Aspen" (3 slots) still lists Viewpoint Center by its past name', $vp !== null, implode(', ', array_column($r, 'display')));
if ($vp) $check('...with "Formerly Aspen Institute..."', strpos(kop_v2_search_alias_hint($vp), 'Formerly Aspen Institute') === 0);
$check('...after the direct name matches', $r && empty($r[0]['matched_name']));

// identification.currentName: the record carries the old name.
$r = $search_with_ids('Kissimmee Youth', 10);
$osc = $find($r, 9653);
$check('"Kissimmee Youth" finds Three Springs of Osceola (now that name)', $osc !== null, implode(', ', array_column($r, 'display')));
if ($osc) $check('...worded "Now known as"', kop_v2_search_alias_hint($osc) === 'Now known as Kissimmee Youth Academy');

// Operators match their other names too.
$ops = kop_v2_search('Eckerd Youth Alternatives', 0, 5, 0)['operators'];
$eck = null;
foreach ($ops as $o) if ($o['display'] === 'Eckerd Connects') $eck = $o;
$check('"Eckerd Youth Alternatives" finds the company Eckerd Connects', $eck !== null, implode(', ', array_column($ops, 'display')));
if ($eck) $check('...worded "Also known as"', kop_v2_search_alias_hint($eck) === 'Also known as Eckerd Youth Alternatives');

// 3 characters is the stated minimum and must work.
$r = $search_with_ids('Cop', 50);
$check('a 3-character query does trigger alias matching', $find($r, 12161) !== null);

$check('empty phrase returns nothing', kop_v2_search('', 10, 5, 5) === array('operators' => array(), 'facilities' => array(), 'places' => array()));

echo "-- kop_v2_search_alias_hint() --\n";
$check('formats a past-name hit', kop_v2_search_alias_hint(array('matched_name' => 'Copper Canyon Academy', 'matched_kind' => 'past')) === 'Formerly Copper Canyon Academy');
$check('formats an other-name hit', kop_v2_search_alias_hint(array('matched_name' => 'Bethel Boys Academy', 'matched_kind' => 'other')) === 'Also known as Bethel Boys Academy');
$check('no hint for a direct match', kop_v2_search_alias_hint(array('matched_name' => null, 'matched_kind' => null)) === '');

echo "-- Callers --\n";
foreach (array(
    'inc/ajax-search-lite.php' => 'kop_v2_search_alias_hint',
    'inc/global-search.php'    => 'kop_v2_search_alias_hint',
    'search.php'               => 'kop_v2_search_alias_hint',
) as $file => $needle) {
    $check("$file renders the alias hint", strpos(file_get_contents(dirname(__DIR__) . '/' . $file), $needle) !== false);
}
$global_search_source = file_get_contents(dirname(__DIR__) . '/inc/global-search.php');
$check('sitewide search includes extracted inspection report text', strpos($global_search_source, 'r.raw_content LIKE %s') !== false);
$check('sitewide search resolves eligible legacy facility profile URLs', strpos($global_search_source, 'kop_search_record_page_url') !== false);
$check('full results resolve eligible legacy facility profile URLs', strpos(file_get_contents(dirname(__DIR__) . '/search.php'), 'kop_search_record_page_url') !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
