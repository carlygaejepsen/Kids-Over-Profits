<?php
/**
 * Offline checks for filing Woodbury Reports pages under parent companies
 * (inc/woodbury-mentions.php, inc/woodbury-create.php) against the
 * production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-woodbury-mentions.php [--db=tmp/prod.sqlite]
 *
 * The "File under" tokens, the parent companies offered for a facility, the
 * company search, and the rows the review screen draws. The MySQL writes,
 * locks and FileBird folders are not exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('selected')) {
    function selected($a, $b = true, $echo = true) { $out = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($echo) echo $out; return $out; }
}
// The create form's lists (inc/facility-discovery.php), not under test here.
if (!function_exists('kop_facdisc_types')) {
    function kop_facdisc_types() { return array('Therapeutic Boarding School'); }
}
if (!function_exists('checked')) {
    function checked($a, $b = true, $echo = true) { $out = (string) $a === (string) $b ? ' checked="checked"' : ''; if ($echo) echo $out; return $out; }
}
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/woodbury-create.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- File under tokens --\n";
$check('a number is a facility', kop_wb_parse_target('123') === array('facility', 123));
$check('c<number> is a company', kop_wb_parse_target('c45') === array('company', 45));
$check('junk and zero are refused', kop_wb_parse_target('x9') === null && kop_wb_parse_target('0') === null && kop_wb_parse_target('c') === null);
$check('clean token keeps good ones only', kop_wb_clean_token(' C45 ') === 'c45' && kop_wb_clean_token('1;drop') === '');
$check('token round trip', kop_wb_target_token('company', 45) === 'c45' && kop_wb_target_token('facility', 7) === '7'
    && kop_wb_target_token('consultant', 3) === '');

echo "-- Parent companies --\n";
$ofc = $wpdb->prefix . 'kop_operator_facilities';
$link = $pdo->query("SELECT operator_id, facility_id FROM `{$ofc}` ORDER BY facility_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$index = kop_operator_pages_index();
$lead = (int) ($index['alias_of'][(int) $link['operator_id']] ?? $link['operator_id']);
$parents = kop_wb_parent_companies(array((int) $link['facility_id']));
$check('a linked facility offers its company', in_array($lead, array_column($parents, 'id'), true),
    'facility #' . $link['facility_id'] . ' -> ' . json_encode($parents));
$check('each company is offered once', count($parents) === count(array_unique(array_column($parents, 'id'))));
$check('offers say how they relate', $parents && preg_match('/^(parent company|past owner) of /', $parents[0]['of']));
$check('no facility, no companies', kop_wb_parent_companies(array()) === array() && kop_wb_parent_companies(array(0)) === array());

// Past operators named on the record are matched by name.
$past_hit = null;
foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_data LIKE '%pastOperators\":[\"%' LIMIT 400") as $f) {
    $doc = json_decode($f['json_data'], true);
    foreach ((array) ($doc['identification']['pastOperators'] ?? array()) as $n) {
        $k = kop_facility_pages_name_key(is_array($n) ? ($n['name'] ?? '') : $n);
        if ($k !== '' && isset($index['names'][$k])) {
            $past_hit = array((int) $f['id'], (int) $index['names'][$k]);
            break 2;
        }
    }
}
if ($past_hit) {
    $ids = array_column(kop_wb_parent_companies(array($past_hit[0])), 'id');
    $check('a named past operator is offered', in_array($past_hit[1], $ids, true), 'facility #' . $past_hit[0]);
} else {
    echo "SKIP no facility names a past operator that has a company page\n";
}

$linked = (int) $pdo->query("SELECT COUNT(DISTINCT facility_id) FROM `{$ofc}`")->fetchColumn();
printf("  %d facilities have a parent company link\n", $linked);

echo "-- Company search --\n";
$name = $index['ids'][$lead]['display'] ?: $index['ids'][$lead]['name'];
$hits = kop_wbc_find_records($pdo, $name, 15, 'company');
$check('finds a company by its name', in_array($lead, array_column($hits, 'id'), true), $name);
$check('company-only search returns only companies', $hits && !array_diff(array_unique(array_column($hits, 'kind')), array('company')));
$check('duplicates collapse to one page', count($hits) === count(array_unique(array_column($hits, 'id'))));
$check('hits carry their /operator/ page', $hits && strpos($hits[0]['url'], '/') !== false);
// A name only the page knows (another name of a grouped company) finds the lead.
$other = null;
foreach ($index['names'] as $nk => $id) {
    if ((int) $id !== 0 && mb_stripos($index['ids'][$id]['name'], $nk) === false && mb_strlen($nk) > 5) {
        $other = array($nk, (int) $id);
        break;
    }
}
if ($other) {
    $check('finds a company by another name', in_array($other[1], array_column(kop_wbc_find_records($pdo, $other[0], 30, 'company'), 'id'), true), $other[0]);
}

echo "-- Places --\n";
$p = kop_wb_place($lead, $name, 0, false, 'company');
$check('a company place links its /operator/ page', $p['page'] !== '' && $p['key'] === 'c' . $lead && $p['kind'] === 'company', $p['page']);
$r = array('status' => 'filed', 'target_kind' => 'facility', 'facility_id' => (int) $link['facility_id'], 'target_id' => (int) $link['facility_id'],
    'facility_name' => 'X', 'folder_id' => 0,
    'also_facilities' => json_encode(array(array('id' => 9, 'name' => 'Old', 'folder' => 0), array('id' => $lead, 'kind' => 'company', 'name' => $name, 'folder' => 0))));
$places = kop_wb_places($r);
$check('old extras without a kind read as facilities', $places[1]['kind'] === 'facility' && $places[1]['key'] === '9');
$check('company extras keep their kind', $places[2]['kind'] === 'company' && $places[2]['key'] === 'c' . $lead);
$html = kop_wb_place_html($places[2]);
$check('Remove names the company token', strpos($html, 'data-fid="c' . $lead . '"') !== false && strpos($html, 'kop-wb-untag') !== false);
$rc = array('status' => 'filed', 'target_kind' => 'company', 'facility_id' => 0, 'target_id' => $lead, 'facility_name' => $name, 'folder_id' => 0, 'also_facilities' => null);
$check('a company holding the PDF links its page', kop_wb_places($rc)[0]['page'] !== '' && kop_wb_places($rc)[0]['key'] === 'c' . $lead);

echo "-- Review rows --\n";
$fac = $pdo->query('SELECT id, name, state FROM facilities_v2 WHERE id = ' . (int) $link['facility_id'])->fetch(PDO::FETCH_ASSOC);
$row = array('ckey' => 'abc123', 'kind' => 'section', 'status' => 'pending', 'issue_id' => 0, 'issue_label' => 'May 2007', 'issue_number' => '#153',
    'pages' => '3,4', 'header' => $fac['name'], 'place' => '', 'matched_name' => $fac['name'], 'facility_id' => (int) $fac['id'],
    'facility_name' => $fac['name'], 'facility_state' => (string) $fac['state'], 'alternatives' => '[]', 'note' => '', 'snippet' => 'text',
    'also_facilities' => null, 'target_kind' => 'facility', 'target_id' => 0, 'attachment_id' => 0, 'folder_id' => 0, 'reviewed_by' => '');
ob_start();
kop_wb_render_row($row, 'articles');
$html = ob_get_clean();
$check('pending row offers the parent company, unticked', strpos($html, 'value="c' . $lead . '">') !== false);
$check('pending row has the company search', strpos($html, 'kop-wbco-q') !== false);
$check('pending row still ticks the best match', strpos($html, 'value="' . (int) $fac['id'] . '" checked') !== false);

$row['status'] = 'filed';
$row['attachment_id'] = 1;
ob_start();
kop_wb_render_row($row, 'filed');
$html = ob_get_clean();
$check('filed row suggests the parent company', strpos($html, 'data-co="c' . $lead . '"') !== false);
$row['also_facilities'] = json_encode(array(array('id' => $lead, 'kind' => 'company', 'name' => $name, 'folder' => 0)));
ob_start();
kop_wb_render_row($row, 'filed');
$html = ob_get_clean();
$check('no suggestion once filed under it', strpos($html, 'data-co="c' . $lead . '"') === false);

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
