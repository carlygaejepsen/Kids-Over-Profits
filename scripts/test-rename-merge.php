<?php
/**
 * Renamed programs merged as a rename (KOP Tools > Merge Duplicates >
 * Renamed programs; inc/facility-merge.php, api/merge-facility-duplicates.php,
 * inc/facility-eras.php), against the SQLite mirror of production.
 *
 * Merges Copper Canyon Academy (the Aspen-era name, 1998 to 2014) into Sedona
 * Sky Academy (2014 to 2025) on a scratch copy and checks that each name keeps
 * its own years, status, operator and notes, that everything filed under
 * Copper Canyon stands under Copper Canyon on the page whatever its date (and
 * Sedona Sky's under Sedona Sky), that a plain merge of the two is refused, that
 * a closure dated in Copper Canyon's years does not close the record, that the
 * data form's round trip keeps it all, and that Undo puts every table back.
 *
 * The tables a merge writes are copied into a temp database; everything else
 * is read from tmp/prod.sqlite attached beside it, so the mirror is never
 * written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-rename-merge.php [--list] [--out=tmp/rename-merge]
 *
 * --list prints every renamed pair the screen offers, with the years it would fill in.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('out::', 'list'));
$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/rename-merge');
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

// ---- The scratch copy: every table a merge can write -------------------------
$db_path = sys_get_temp_dir() . '/kop-rename-merge-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
$p = 'wpdl_';
$tables = array('facilities_v2', 'news_submissions', 'lawsuits', 'suggested_edits', 'wiki_submissions', 'news_facility_links', 'lawsuit_facility_links',
    'facility_closure_reports', 'news_facility_candidates', $p . 'kop_operator_facilities', $p . 'kop_facility_locations', $p . 'kop_woodbury_mentions',
    $p . 'kop_woodbury_facts', $p . 'kop_fornits_items', $p . 'kop_fornits_topics', $p . 'kop_gdoc_links', $p . 'kop_state_list_rows', $p . 'postmeta',
    $p . 'kop_facility_identity', $p . 'fbv', $p . 'fbv_attachment_folder', $p . 'kop_media_folder_tags', $p . 'kop_folder_links', $p . 'options', $p . 'kop_operators');
$copied = array();
foreach ($tables as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
    $copied[] = $t;
}
// On production these columns are generated from json_data; here triggers fill them.
$cols = "name = json_extract(NEW.json_data, '$.identification.name'), name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
    state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
    country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
    facility_type = json_extract(NEW.json_data, '$.facilityDetails.type'),
    start_year = json_extract(NEW.json_data, '$.operatingPeriod.startYear'), end_year = json_extract(NEW.json_data, '$.operatingPeriod.endYear')";
$copy->exec("CREATE TRIGGER facilities_v2_ins AFTER INSERT ON facilities_v2 BEGIN UPDATE facilities_v2 SET $cols WHERE id = NEW.id; END");
$copy->exec("CREATE TRIGGER facilities_v2_upd AFTER UPDATE OF json_data ON facilities_v2 BEGIN UPDATE facilities_v2 SET $cols WHERE id = NEW.id; END");
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);
$GLOBALS['pdo'] = $pdo;
$GLOBALS['wpdb'] = $wpdb = new class($pdo) extends wpdb {
    private $db;
    public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
    private function fix($sql) {
        if (preg_match("/^\s*SHOW TABLES LIKE\s+('[^']*')/i", $sql, $m)) {
            return "SELECT name FROM (SELECT name FROM main.sqlite_master WHERE type = 'table' UNION SELECT name FROM prod.sqlite_master WHERE type = 'table') WHERE name LIKE $m[1]";
        }
        return $sql;
    }
    public function get_var($sql, $x = 0, $y = 0) { return parent::get_var($this->fix($sql), $x, $y); }
    public function get_results($sql, $output = OBJECT) { return parent::get_results($this->fix($sql), $output); }
    public function query($sql) { return $this->db->exec($sql); }
};
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (file_exists(dirname(__DIR__) . '/inc/citations.php')) require_once dirname(__DIR__) . '/inc/citations.php';
require_once dirname(__DIR__) . '/inc/facility-merge.php';
kop_fmerge_load_mfd();

// The live site's Map Renames decisions, as the eras test reads them.
$raw = $pdo->query("SELECT option_value FROM prod.wpdl_options WHERE option_name = 'kop_network_rename_review'")->fetchColumn();
$decisions = $raw ? @unserialize($raw) : array();
$GLOBALS['kop_test_options']['kop_network_rename_review'] = is_array($decisions) ? $decisions : array();

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$doc_of = function ($id) use ($pdo) {
    $j = $pdo->query('SELECT json_data FROM main.facilities_v2 WHERE id = ' . (int) $id)->fetchColumn();
    return $j ? json_decode($j, true) : null;
};
$snapshot = function () use ($pdo, $copied) {
    $out = array();
    foreach ($copied as $t) {
        $lines = array();
        foreach ($pdo->query("SELECT * FROM main.\"$t\"")->fetchAll(PDO::FETCH_ASSOC) as $r) $lines[] = json_encode($r);
        sort($lines);
        $out[$t] = md5(implode("\n", $lines)) . ' (' . count($lines) . ')';
    }
    return $out;
};
$fresh = function () {
    // Page caches and memos built before a write.
    $GLOBALS['kop_test_transients'] = array();
    if (function_exists('kop_facility_eras_map')) kop_facility_eras_map(true);
};

$find = function ($name) use ($pdo) {
    $ids = $pdo->query('SELECT id FROM main.facilities_v2 WHERE name = ' . $pdo->quote($name))->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int) $ids[0] : 0;
};
$cc = $find('Copper Canyon Academy');
$ss = $find('Sedona Sky Academy');
if (!$cc || !$ss) {
    fwrite(STDERR, "The mirror has no single Copper Canyon Academy / Sedona Sky Academy record (were they merged already?).\n");
    exit(2);
}
echo "Copper Canyon Academy #$cc, Sedona Sky Academy #$ss\n";

// ---- The rules, on a made-up record ----------------------------------------
echo "\n-- Rules --\n";
$fake = array('facility_id' => 1, 'identification' => array('name' => 'Later Name', 'currentOperator' => 'New Co'),
    'operatingPeriod' => array('startYear' => 2014, 'endYear' => null, 'status' => 'Open'),
    'legacy' => array('mergedFacilities' => array(array('facility_id' => 2, 'name' => 'Earlier Name', 'rename' => array(
        'year' => 2014, 'startYear' => 1998, 'endYear' => 2014, 'status' => 'Transferred', 'notes' => array('Capacity: 90'), 'operators' => array('Old Co'),
        'facts' => array('news' => array('news:1')), 'laterName' => 'Later Name', 'laterFacts' => array('news' => array('news:2', 'news:4')))))));
$news = array(
    array('id' => 1, 'date' => '2020-05-01', 'title' => 'filed under the earlier name, dated after the rename'),
    array('id' => 2, 'date' => '2005-05-01', 'title' => 'filed under the later name, dated before it'),
    array('id' => 3, 'date' => '2009-05-01', 'title' => 'added after the merge, dated in the earlier name\'s years'),
    array('id' => 4, 'date' => '2016-01-01', 'title' => 'filed under the later name'),
);
$built = kop_facility_eras_build(1, array('news' => $news), $fake);
$at = array();
foreach ($built ? $built['list'] : array() as $i => $e) foreach ($e['news'] as $x) $at[$x['id']] = $i;
$check('a merged record\'s names come from its own document', $built && $built['list'][0]['name'] === 'Earlier Name' && $built['list'][1]['name'] === 'Later Name'
    && $built['list'][0]['years'] === '1998 to 2014' && $built['list'][1]['years'] === 'from 2014');
$check('an item filed under the earlier name stays there, though dated after the rename', ($at[1] ?? null) === 0);
$check('an item filed under the later name stays there, though dated before it', ($at[2] ?? null) === 1);
$check('an item filed under neither is placed by its date', ($at[3] ?? null) === 0 && ($at[4] ?? null) === 1);
$check('the earlier name\'s notes and operator print in its section', $built && $built['list'][0]['notes'] === array('Capacity: 90') && $built['list'][0]['operators'] === array('Old Co'));
$check('"Formerly Earlier Name (1998 to 2014)"', kop_facility_eras_former_years($fake) === array('earlier name' => '1998 to 2014'));

// ---- Finding renamed pairs ---------------------------------------------------
echo "\n-- Renamed pairs --\n";
$rows = $pdo->query('SELECT id, unique_name, json_data FROM main.facilities_v2')->fetchAll(PDO::FETCH_ASSOC);
$renames = kop_fmerge_find_renames($rows);
$pair = null;
foreach ($renames as $r) if ($r['earlier'] === $cc && $r['later'] === $ss) $pair = $r;
$check('Copper Canyon / Sedona Sky is offered as a rename, Copper Canyon first', $pair !== null, count($renames) . ' renamed pairs');
$check('...and never as a duplicate', !array_filter(kop_fmerge_find_pairs($rows), function ($x) use ($cc, $ss) {
    return ($x['a'] === $cc && $x['b'] === $ss) || ($x['a'] === $ss && $x['b'] === $cc);
}));
$by_id = array();
foreach ($rows as $r) $by_id[(int) $r['id']] = $r;
$ctx = kop_fmerge_side_ctx($pdo, 'wpdl_');
$plan = kop_fmerge_rename_plan($by_id[$cc], $by_id[$ss], kop_fmerge_side($by_id[$cc], $ctx), kop_fmerge_side($by_id[$ss], $ctx), $pair['why'] ?? '');
$check('the screen fills in 1998, renamed 2014, until 2025',
    $plan['year'] === 2014 && $plan['earlier']['start'] === 1998 && $plan['later']['start'] === 2014 && $plan['later']['end'] === 2025,
    json_encode(array($plan['earlier']['start'], $plan['year'], $plan['later']['start'], $plan['later']['end'])));
if (isset($args['list'])) {
    foreach ($renames as $r) {
        $x = kop_fmerge_rename_plan($by_id[$r['earlier']], $by_id[$r['later']], kop_fmerge_side($by_id[$r['earlier']], $ctx), kop_fmerge_side($by_id[$r['later']], $ctx), $r['why']);
        printf("  %-40s %-14s -> %-40s %-14s year %s%s\n", $x['earlier']['name'] . ' #' . $r['earlier'], kop_fmerge_years_text($x['earlier']),
            $x['later']['name'] . ' #' . $r['later'], kop_fmerge_years_text($x['later']), $x['year'] ?: '?', $x['map_year'] ? ' (Map Renames)' : '');
    }
}

// ---- A plain merge of the two is refused ------------------------------------
$refused = '';
try {
    kop_fmerge_do_merge($pdo, 'wpdl_', $ss, $cc, 'test');
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('a plain merge of a renamed pair is refused', stripos($refused, 'two names') !== false, $refused);
$refused = '';
try {
    kop_fmerge_do_merge($pdo, 'wpdl_', $cc, $ss, 'test', array('year' => 2014));
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('a rename that keeps the earlier name is refused', stripos($refused, 'later name') !== false, $refused);

// ---- What each record lists before the merge ---------------------------------
$before_cc = kop_facility_eras_list_keys(kop_facility_eras_record_items($cc));
$before_ss = kop_facility_eras_list_keys(kop_facility_eras_record_items($ss));
$flat = function ($keys) { $o = array(); foreach ($keys as $l) foreach ($l as $k) $o[$k] = true; return $o; };
$only_cc = array_diff_key($flat($before_cc), $flat($before_ss));
$only_ss = array_diff_key($flat($before_ss), $flat($before_cc));
echo '  Copper Canyon lists ' . count($flat($before_cc)) . ' items (' . count($only_cc) . ' its own), Sedona Sky ' . count($flat($before_ss)) . ' (' . count($only_ss) . " its own)\n";
$cc_doc = $doc_of($cc);

// ---- The merge -----------------------------------------------------------------
echo "\n-- Merge as a rename --\n";
$snap = $snapshot();
$entry = kop_fmerge_execute($pdo, 'wpdl_', $ss, $cc, array('by' => 'test', 'rename' => array(
    'year' => 2014, 'earlier' => array('start' => 1998, 'end' => 2014), 'later' => array('start' => 2014, 'end' => 2025))));
// What the screen's merge writes beside it: the dropped id now leads to the kept record.
$GLOBALS['kop_test_options']['kop_facility_merged_into'] = array('ids' => array($cc => $ss), 'slugs' => array());
$fresh();
$doc = $doc_of($ss);
$idn = $doc['identification'];
$op = $doc['operatingPeriod'];
$check('the earlier record is gone', $doc_of($cc) === null);
$check('Copper Canyon is a past name, not an other name',
    in_array('Copper Canyon Academy', (array) $idn['pastNames'], true) && !in_array('Copper Canyon Academy', (array) ($idn['otherNames'] ?? array()), true));
$check('Sedona Sky keeps its own years: 2014 to 2025, Closed', (int) $op['startYear'] === 2014 && (int) $op['endYear'] === 2025 && $op['status'] === 'Closed',
    json_encode(array($op['startYear'], $op['endYear'], $op['status'])));
$check('none of Copper Canyon\'s notes join Sedona Sky\'s', !array_intersect((array) $op['notes'], (array) ($cc_doc['operatingPeriod']['notes'] ?? array())));
$check('Aspen is a past operator, the current one unchanged',
    in_array('Aspen Education Group', (array) $idn['pastOperators'], true) && $idn['currentOperator'] === 'New Legal Name: Emoticare');
$m = null;
foreach ((array) $doc['legacy']['mergedFacilities'] as $x) if ((int) $x['facility_id'] === $cc) $m = $x;
$r = $m['rename'] ?? array();
$check('Copper Canyon keeps 1998 to 2014, its status and notes, on its merge entry',
    ($r['year'] ?? 0) === 2014 && ($r['startYear'] ?? 0) === 1998 && ($r['endYear'] ?? 0) === 2014 && ($r['status'] ?? '') === 'Transferred'
    && count($r['notes'] ?? array()) === count((array) ($cc_doc['operatingPeriod']['notes'] ?? array())), json_encode(array_diff_key($r, array('facts' => 1, 'laterFacts' => 1, 'notes' => 1))));
$check('...and what each name had filed under it', $flat($r['facts'] ?? array()) == $flat($before_cc) && $flat($r['laterFacts'] ?? array()) == $flat($before_ss));
$rel = $pdo->query("SELECT f.relationship FROM main.wpdl_kop_operator_facilities f JOIN main.wpdl_kop_operators o ON o.id = f.operator_id
    WHERE f.facility_id = $ss AND o.name = 'Aspen Education Group'")->fetchColumn();
$check('Aspen\'s company link moves over as a past operator', $rel === 'past', (string) $rel);

// The data form edits the legacy shape and saves it back: the rename entry survives.
$round = kop_facility_normalize(kop_facility_to_legacy($doc), array('facility_id' => $ss, 'unique_name' => $doc['provenance']['uniqueName'] ?? ''));
$rr = array();
foreach ((array) ($round['legacy']['mergedFacilities'] ?? array()) as $x) if ((int) $x['facility_id'] === $cc) $rr = $x['rename'] ?? array();
$check('the data form\'s round trip keeps the rename entry', $rr == $r);

// ---- The page ---------------------------------------------------------------
echo "\n-- The page --\n";
$data = kop_facility_page_data($ss);
$eras = $data['eras'];
$check('the page has one section per name', $eras && count($eras['list']) === 2
    && $eras['list'][0]['name'] === 'Copper Canyon Academy' && $eras['list'][1]['name'] === 'Sedona Sky Academy');
$check('each with its own years', $eras && $eras['list'][0]['years'] === '1998 to 2014' && $eras['list'][1]['years'] === '2014 to 2025',
    $eras ? $eras['list'][0]['years'] . ' / ' . $eras['list'][1]['years'] : '');
$check('Copper Canyon\'s section has Aspen and its notes', $eras && in_array('Aspen Education Group', $eras['list'][0]['operators'], true) && count($eras['list'][0]['notes']) > 0);
$check('"Formerly Copper Canyon Academy (1998 to 2014)"', ($data['formerly_years']['Copper Canyon Academy'] ?? '') === '1998 to 2014');
$check('the facts rail gives Sedona Sky\'s years', $data['operated'] === '2014 to 2025', $data['operated']);
$where = array();
foreach ($eras ? $eras['list'] : array() as $i => $entry_) {
    foreach (kop_facility_eras_kinds() as $kind) {
        $items = $entry_[$kind];
        if ($kind === 'staff') { $all = array(); foreach ($items as $g) foreach ($g as $x) $all[] = $x; $items = $all; }
        foreach ($items as $x) $where[kop_facility_eras_item_key($kind, $x)][] = $i;
    }
}
$miss_cc = array();
foreach (array_keys($only_cc) as $k) if (($where[$k] ?? array()) !== array(0)) $miss_cc[] = $k;
$miss_ss = array();
foreach (array_keys($only_ss) as $k) if (($where[$k] ?? array()) !== array(1)) $miss_ss[] = $k;
$check('everything filed under Copper Canyon stands under Copper Canyon, whatever its date', !$miss_cc, implode(', ', array_slice($miss_cc, 0, 5)));
$check('everything filed under Sedona Sky stands under Sedona Sky', !$miss_ss, implode(', ', array_slice($miss_ss, 0, 5)));
$late = 0;
if ($eras) foreach ($eras['list'][0]['news'] as $x) if (kop_facility_eras_item_year('news', $x) > 2014) $late++;
echo "  ($late of Copper Canyon's articles are dated after 2014 and still stand under it)\n";

$GLOBALS['kop_facility_page'] = $data;
ob_start();
$html = '';
try {
    include dirname(__DIR__) . '/templates/facility-page.php';
    $html = ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    $check('the page renders', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
if ($html !== '') {
    file_put_contents($out_dir . '/' . $data['slug'] . '.html', $html);
    $check('the page renders to the end, with both sections and the dated past name',
        strpos($html, 'kop-fp-footer') !== false && strpos($html, 'id="as-copper-canyon-academy"') !== false
        && strpos($html, 'Copper Canyon Academy (1998 to 2014)') !== false && strpos($html, 'kop-fp-era-notes') !== false,
        $out_dir . '/' . $data['slug'] . '.html');
    $check('Woodbury wording stays off the page', strpos($html, 'Woodbury Reports, December 2012') === false);
}

// ---- Closures --------------------------------------------------------------
$check('a closure dated 2010 is Copper Canyon\'s, not the record\'s', kop_facility_eras_doc_name_at($doc, 2010) === 'Copper Canyon Academy');
$check('a closure dated 2025 is the record\'s own', kop_facility_eras_doc_name_at($doc, 2025) === '');

// ---- Undo ------------------------------------------------------------------
echo "\n-- Undo --\n";
kop_fmerge_undo($pdo, 'wpdl_', $entry);
unset($GLOBALS['kop_test_options']['kop_facility_merged_into']);
$after = $snapshot();
$diff = array();
foreach ($snap as $t => $h) if (($after[$t] ?? '') !== $h) $diff[] = "$t $h -> " . ($after[$t] ?? 'missing');
$check('Undo puts every table back exactly', !$diff, implode('; ', $diff));

$pdo = $wpdb = null;
$GLOBALS['pdo'] = $GLOBALS['wpdb'] = null;
gc_collect_cycles();
try { unlink($db_path); } catch (Throwable $e) { echo "  (left the scratch copy at $db_path)
"; }
echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
