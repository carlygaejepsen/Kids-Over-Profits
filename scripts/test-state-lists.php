<?php
/**
 * KOP Tools > State Lists (inc/state-lists.php) against a temp copy of the
 * production mirror: the real export from scripts/state-lists-check.py
 * --export is imported, linked, created, set aside, re-imported (decisions
 * kept), left the list, and every action undone exactly. The tables a
 * decision writes (facilities_v2 and its identity/membership tables) are
 * copied into a temp database; everything else is read from tmp/prod.sqlite
 * attached beside it, so the mirror is never written.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-state-lists.php
 *       [--file=tmp/state-lists/state-lists.json] [--preview=tmp/state-lists/preview]
 *
 * Also checks PHP/script parity: every exported name_key must equal
 * kop_project_facility_name_key() of the listed name. --preview writes the
 * screen with the imported rows as a standalone page (for a browser look).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('file::', 'preview::'));
$root = dirname(__DIR__);
$prod = $root . '/tmp/prod.sqlite';
$file = $args['file'] ?? ($root . '/tmp/state-lists/state-lists.json');
$preview = $args['preview'] ?? ($root . '/tmp/state-lists/preview');
foreach (array($prod => 'scripts/sync-prod-sqlite.py', $file => 'py -3 scripts/state-lists-check.py --export ' . $file) as $need => $how) {
    if (!file_exists($need)) {
        fwrite(STDERR, "Missing $need (run $how).\n");
        exit(2);
    }
}

$db_path = sys_get_temp_dir() . '/kop-state-lists-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
// On production these columns are generated from json_data; here triggers fill them.
foreach (array('INSERT', 'UPDATE OF json_data') as $i => $when) {
    $copy->exec("CREATE TRIGGER facilities_v2_cols_$i AFTER $when ON facilities_v2 BEGIN
        UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name'),
            name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
            state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
            country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
            facility_type = json_extract(NEW.json_data, '$.facilityDetails.type')
        WHERE id = NEW.id; END");
}
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;   // the harness's options and $wpdb writes land on the temp copy
require __DIR__ . '/kop-test-harness.php';
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return (object) array('user_login' => 'tester'); }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); } }
if (!function_exists('kop_icon')) require_once $root . '/inc/icons.php';
require_once $root . '/inc/facility-v2-writer.php';
require_once $root . '/inc/facility-finder.php';
require_once $root . '/inc/closure-reports.php';
require_once $root . '/inc/facility-discovery.php';
require_once $root . '/inc/state-lists.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_', 'skip_memberships' => true);
$doc_of = function ($fid) use ($opts) { $l = kop_facility_load((int) $fid, $opts); return $l ? $l['doc'] : null; };
$row_by = function ($key) use ($pdo) {
    $st = $pdo->prepare('SELECT * FROM wpdl_kop_state_list_rows WHERE row_key = ?');
    $st->execute(array($key));
    return $st->fetch(PDO::FETCH_ASSOC);
};
$act = function ($row, $action, $params = array()) use ($pdo) { return kop_sl_act($pdo, (int) $row['id'], $action, $params, 'tester'); };
$refused = function ($fn) { try { $fn(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };

$export = kop_sl_read_file($file);

// ---------------------------------------------------------------------------
echo "-- The export and PHP/script parity --\n";
$bad = array();
foreach ($export['rows'] as $r) {
    $php = kop_project_facility_name_key($r['name']);
    if ($php !== $r['name_key']) $bad[] = $r['name'] . ': script "' . $r['name_key'] . '", PHP "' . $php . '"';
}
$check('every exported name key equals kop_project_facility_name_key() (' . count($export['rows']) . ' rows)', !$bad, implode('; ', array_slice($bad, 0, 3)));
$cids = array();
foreach ($export['rows'] as $r) foreach ($r['candidates'] as $c) $cids[(int) $c['id']] = true;
$have = kop_sl_records($pdo, array_keys($cids));
$check('every candidate is a facilities_v2 record', count($have) === count($cids), count($have) . ' of ' . count($cids));
$keys = array_column($export['rows'], 'key');
$check('row keys are unique', count($keys) === count(array_unique($keys)));
$by_state = array();
foreach ($export['rows'] as $r) {
    $s = $r['list'];
    $by_state[$s] = $by_state[$s] ?? array('none_with' => 0, 'none_without' => 0, 'ambiguous' => 0);
    if ($r['status'] === 'ambiguous') $by_state[$s]['ambiguous']++;
    else $by_state[$s][$r['candidates'] ? 'none_with' : 'none_without']++;
}
foreach ($by_state as $s => $c) printf("  %s: %d no record with a candidate, %d without, %d ambiguous\n", $s, $c['none_with'], $c['none_without'], $c['ambiguous']);

// ---------------------------------------------------------------------------
echo "\n-- Import --\n";
$c = kop_sl_import($pdo, $export);
$check('every row is imported', $c['added'] === count($export['rows']) && $c['updated'] === 0, json_encode($c));
$data = kop_sl_screen_data($pdo);
$check('the screen lists every row, all To review', count($data['items']) === count($export['rows'])
    && !array_filter($data['items'], function ($i) { return $i['status'] !== 'open' || !$i['on_list']; }));
$check('every list has its label and covers text', count($data['lists']) === count($export['lists']) && !array_filter($data['lists'], function ($l) { return $l['label'] === '' || $l['covers'] === ''; }));
$item = null;
foreach ($data['items'] as $i) if ($i['candidates'] && $i['list'] === 'KY') { $item = $i; break; }
$check('a card carries the list as words, its date and candidates with why', $item && $item['source_text'] !== '' && strpos($item['source_text'], 'http') === false
    && $item['list_date'] !== '' && $item['candidates'][0]['why'] !== '' && $item['candidates'][0]['name'] !== '', $item ? $item['name'] . ' -> ' . $item['candidates'][0]['name'] : '');
$check('an all-capitals name gets a readable suggestion', kop_sl_suggested_name('UNION HOUSE II') === 'Union House II' && kop_sl_suggested_name("CATHERINE'S PLACE GROUP HOME") === "Catherine's Place Group Home");

// Pick rows to work on: a KY row with a candidate, an IN one with a candidate, a row with none.
$pick = function ($want) use ($export) {
    foreach ($export['rows'] as $r) if ($want($r)) return $r;
    return null;
};
$e_link = $pick(function ($r) { return $r['list'] === 'KY' && $r['status'] === 'none' && $r['candidates']; });
$e_name = $pick(function ($r) use ($e_link) { return $r['list'] === 'IN' && $r['status'] === 'none' && $r['candidates'] && $r['candidates'][0]['id'] !== $e_link['candidates'][0]['id']; });
$e_new  = $pick(function ($r) { return $r['status'] === 'none' && !$r['candidates'] && $r['list'] === 'KY' && !$r['excluded']; });
$e_misc = $pick(function ($r) { return $r['list'] === 'MO' && $r['status'] === 'none' && !$r['candidates']; });
$check('fixtures found in the real export', $e_link && $e_name && $e_new && $e_misc);

// ---------------------------------------------------------------------------
echo "\n-- Link --\n";
$fid = (int) $e_link['candidates'][0]['id'];
$before = $doc_of($fid);
$msg = $act($row_by($e_link['key']), 'link', array('facility_id' => $fid, 'add_name' => 0));
$r = $row_by($e_link['key']);
$check('one click links the row to its candidate', $r['status'] === 'linked' && (int) $r['facility_id'] === $fid, $msg);
$check('without the box the record is not changed', kop_facility_same_document($before, $doc_of($fid)));
$check('a decided row cannot be linked again', $refused(function () use ($act, $row_by, $e_link, $fid) { $act($row_by($e_link['key']), 'link', array('facility_id' => $fid)); }) !== '');
$act($row_by($e_link['key']), 'undo');
$r = $row_by($e_link['key']);
$check('Undo puts it back To review, record untouched', $r['status'] === 'open' && $r['facility_id'] === null && kop_facility_same_document($before, $doc_of($fid)));
$check('linking to no record is refused', $refused(function () use ($act, $row_by, $e_link) { $act($row_by($e_link['key']), 'link', array('facility_id' => 999999999)); }) !== '');

$fid2 = (int) $e_name['candidates'][0]['id'];
$before2 = $doc_of($fid2);
$msg = $act($row_by($e_name['key']), 'link', array('facility_id' => $fid2, 'add_name' => 1));
$after2 = $doc_of($fid2);
$check('with the box ticked the listed name becomes an other name', in_array($e_name['name'], (array) $after2['identification']['otherNames'], true), $msg);
$check('kop_project_facility_name_key now reaches the record by the listed name', isset(kop_sl_doc_name_keys($after2)[kop_project_facility_name_key($e_name['name'])]));
$act($row_by($e_name['key']), 'undo');
$check('Undo restores the record exactly', kop_facility_same_document($before2, $doc_of($fid2)));
// Edited after the link: Undo takes off only the added name.
$act($row_by($e_name['key']), 'link', array('facility_id' => $fid2, 'add_name' => 1));
$edited = $doc_of($fid2);
$edited['identification']['otherNames'][] = 'An Edit Made Later';
kop_sl_save_doc($edited, $opts);
$act($row_by($e_name['key']), 'undo');
$names = (array) $doc_of($fid2)['identification']['otherNames'];
$check('Undo after someone edited the record removes only the added name', !in_array($e_name['name'], $names, true) && in_array('An Edit Made Later', $names, true), implode(' | ', $names));
$e3 = $doc_of($fid2);
$e3['identification']['otherNames'] = array_values(array_diff($names, array('An Edit Made Later')));
kop_sl_save_doc($e3, $opts);

// ---------------------------------------------------------------------------
echo "\n-- Create --\n";
$count = (int) $pdo->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn();
$msg = $act($row_by($e_new['key']), 'create', array('name' => kop_sl_suggested_name($e_new['name']), 'city' => $e_new['city'], 'state' => $e_new['state'], 'type' => 'Group Home'));
$r = $row_by($e_new['key']);
$nid = (int) $r['facility_id'];
$ndoc = $nid ? $doc_of($nid) : null;
$check('Create makes one new record and marks the row', $r['status'] === 'created' && $ndoc && (int) $pdo->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn() === $count + 1, $msg);
$check('the record has the name, place and type', $ndoc && $ndoc['identification']['name'] === kop_sl_suggested_name($e_new['name'])
    && $ndoc['location']['state'] === $e_new['state'] && $ndoc['location']['city'] === $e_new['city'] && $ndoc['facilityDetails']['type'] === 'Group Home');
$check('its notes and Materials and links cite the state list', $ndoc && strpos(implode(' ', (array) $ndoc['notes']), $e_new['source_url']) !== false
    && in_array($e_new['source_url'], array_column((array) $ndoc['resourceLinks'], 'url'), true), json_encode($ndoc['resourceLinks'] ?? null));
$check('provenance names the state list, not the news scan', $ndoc && $ndoc['provenance']['source'] === 'state-list');
$errors = $ndoc ? array_filter(kop_facility_validate($ndoc), function ($v) { return $v['severity'] === 'error'; }) : array('none');
$check('the new record passes the validator', !$errors, $errors ? json_encode(array_values($errors)[0]) : '');
$act($row_by($e_new['key']), 'undo');
$check('Undo deletes the untouched record and reopens the row', !$doc_of($nid) && $row_by($e_new['key'])['status'] === 'open');
$act($row_by($e_new['key']), 'create', array('state' => $e_new['state']));
$nid = (int) $row_by($e_new['key'])['facility_id'];
$nd = $doc_of($nid);
$nd['location']['city'] = 'Somewhere Else';
kop_sl_save_doc($nd, $opts);
$why = $refused(function () use ($act, $row_by, $e_new) { $act($row_by($e_new['key']), 'undo'); });
$check('Undo never deletes a created record someone edited since', $why !== '' && $doc_of($nid) && $row_by($e_new['key'])['status'] === 'created', $why);
$check('Create with no state is refused', $refused(function () use ($act, $row_by, $e_misc) { $act($row_by($e_misc['key']), 'create', array('state' => 'Missouri')); }) !== '');

// ---------------------------------------------------------------------------
echo "\n-- Not a TTI facility, Later --\n";
$act($row_by($e_misc['key']), 'not_tti');
$check('Not a TTI facility moves the row to Done', $row_by($e_misc['key'])['status'] === 'not_tti');
$act($row_by($e_misc['key']), 'undo');
$check('... and Undo brings it back', $row_by($e_misc['key'])['status'] === 'open');
$act($row_by($e_misc['key']), 'later');
$check('Later parks it', $row_by($e_misc['key'])['status'] === 'later');
$act($row_by($e_misc['key']), 'not_tti');
$act($row_by($e_misc['key']), 'undo');
$check('a row decided from Later goes back to Later on Undo', $row_by($e_misc['key'])['status'] === 'later');
$act($row_by($e_misc['key']), 'undo');
$check('Back to review from Later', $row_by($e_misc['key'])['status'] === 'open');
$act($row_by($e_misc['key']), 'not_tti');

// ---------------------------------------------------------------------------
echo "\n-- Young adult programs and Indigenous schools --\n";
$ya = $pdo->query('SELECT name, state FROM prod.young_adult_programs LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$fake = array('format' => KOP_STATE_LISTS_FORMAT, 'lists' => array(), 'rows' => array(array(
    'key' => 'ZZ|nm:test-ya', 'list' => 'MO', 'state' => $ya ? $ya['state'] : 'MO', 'name' => $ya ? $ya['name'] : 'Test YA', 'source_url' => 'https://example.org/list',
    'candidates' => array(), 'excluded' => array('table' => 'young_adult_programs', 'label' => 'Young adult program (18+)', 'id' => 1, 'name' => $ya ? $ya['name'] : 'Test YA'))), 'gone' => array());
$export_with = $export;
$export_with['rows'][] = $fake['rows'][0];
kop_sl_import($pdo, $export_with);
$why = $refused(function () use ($act, $row_by) { $act($row_by('ZZ|nm:test-ya'), 'create', array('state' => 'MO')); });
$check('a young adult program on a list is never created as a facility', $why !== '' && $row_by('ZZ|nm:test-ya')['status'] === 'open', $why);
$check('the excluded note reaches the card', (bool) array_filter(kop_sl_screen_data($pdo)['items'], function ($i) { return $i['key'] === 'ZZ|nm:test-ya' && $i['excluded']; }));
$pdo->exec("DELETE FROM wpdl_kop_state_list_rows WHERE row_key = 'ZZ|nm:test-ya'");

// ---------------------------------------------------------------------------
echo "\n-- Re-import keeps decisions --\n";
$act($row_by($e_link['key']), 'link', array('facility_id' => $fid));
$again = $export;
foreach ($again['rows'] as &$rr) if ($rr['key'] === $e_link['key']) $rr['city'] = 'Renamed Town';
unset($rr);
$c = kop_sl_import($pdo, $again);
$r = $row_by($e_link['key']);
$check('re-import updates by key, adds nothing', $c['added'] === 0 && $c['updated'] === count($export['rows']), json_encode($c));
$check('decisions survive a re-import; the listing is refreshed', $r['status'] === 'linked' && (int) $r['facility_id'] === $fid && $r['city'] === 'Renamed Town'
    && $row_by($e_misc['key'])['status'] === 'not_tti' && $row_by($e_new['key'])['status'] === 'created');

// ---------------------------------------------------------------------------
echo "\n-- Left the list --\n";
$status_before = $doc_of($fid)['operatingPeriod']['status'];
$gone_link = $e_link;
$gone_link['left_by'] = '2026-11-01';
$gone_link['record'] = array('id' => $fid, 'name' => 'x');
$gone_new = array('key' => 'MO|nm:a ranch that left|town', 'list' => 'MO', 'state' => 'MO', 'name' => 'A Ranch That Left', 'city' => 'Town',
    'source_url' => 'https://example.org/mo.pdf', 'list_date' => '2026-09-17', 'left_by' => '2026-10-17', 'record' => null, 'candidates' => array());
$third = $export;
$third['rows'] = array_values(array_filter($third['rows'], function ($x) use ($e_link) { return $x['key'] !== $e_link['key']; }));
$third['gone'] = array($gone_link, $gone_new);
$c = kop_sl_import($pdo, $third);
$check('gone rows are imported as having left', $c['left'] === 2 && (int) $row_by($e_link['key'])['on_list'] === 0 && (int) $row_by($gone_new['key'])['on_list'] === 0, json_encode($c));
$left = array_values(array_filter(kop_sl_screen_data($pdo)['items'], function ($i) { return !$i['on_list']; }));
$named = array_values(array_filter($left, function ($i) use ($e_link) { return $i['key'] === $e_link['key']; }))[0] ?? null;
$check('Left the list names the record it matched; its decision is kept', $named && $named['left_record']['id'] === $fid && $named['status'] === 'linked' && $named['left_date'] === '2026-11-01');
$check('leaving a list never changes the record', $doc_of($fid)['operatingPeriod']['status'] === $status_before);
$act($row_by($gone_new['key']), 'dismiss_left');
$check('Dismiss hides the notice', (int) $row_by($gone_new['key'])['left_seen'] === 1);
$act($row_by($gone_new['key']), 'undo_left');
$check('... and its undo shows it again', (int) $row_by($gone_new['key'])['left_seen'] === 0);
$check('Dismiss is refused on a row still listed', $refused(function () use ($act, $row_by, $e_misc) { $act($row_by($e_misc['key']), 'dismiss_left'); }) !== '');
kop_sl_import($pdo, $export);
$check('a row back on its list is listed again', (int) $row_by($e_link['key'])['on_list'] === 1 && $row_by($e_link['key'])['left_date'] === '');

// ---------------------------------------------------------------------------
echo "\n-- Merges carry the table --\n";
if (!function_exists('kop_fmerge_ref_tables')) @include_once $root . '/inc/facility-merge.php';
if (function_exists('kop_fmerge_ref_tables')) {
    $refs = array_filter(kop_fmerge_ref_tables('wpdl_'), function ($t) { return $t['t'] === 'wpdl_kop_state_list_rows'; });
    $json = array_filter(kop_fmerge_json_tables('wpdl_'), function ($t) { return $t['t'] === 'wpdl_kop_state_list_rows'; });
    $check('kop_fmerge_ref_tables() moves facility_id and left_record_id, kop_fmerge_json_tables() the candidates',
        array_column($refs, 'c') === array('facility_id', 'left_record_id') && array_column($json, 'c') === array('candidates'));
} else {
    echo "SKIP merge tables (inc/facility-merge.php did not load here)\n";
}

// ---------------------------------------------------------------------------
if ($preview !== '') {
    if (!is_dir($preview)) mkdir($preview, 0777, true);
    $data = kop_sl_screen_data($pdo);
    ob_start();
    kop_sl_render_page($data, '', 'about:blank', 'n');
    $page = ob_get_clean();
    $icons = function_exists('kop_icons_js') ? kop_icons_js() : '';
    $rel = function ($p) use ($preview, $root) { return 'file:///' . str_replace('\\', '/', realpath($root . '/' . $p)); };
    file_put_contents($preview . '/index.html', "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
        . '<link rel="stylesheet" href="' . $rel('css/colors.css') . '"><link rel="stylesheet" href="' . $rel('css/state-lists.css') . '">'
        . '<style>body{margin:0;padding:16px;background:#f0f0f1;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif}</style>'
        . "<script>$icons</script></head><body>$page<script src=\"" . $rel('js/state-lists.js') . "\"></script></body></html>");
    echo "\n  screen with the imported rows: $preview/index.html\n";
}

register_shutdown_function(function () use ($db_path) { try { @unlink($db_path); } catch (Throwable $e) {} });
echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
