<?php
/**
 * scripts/test-review-inbox.php checks for the 'program-homes' source
 * (inc/review-inbox/program-homes.php over inc/program-homes.php): the
 * program and home names are edited and kept, Group uses them (a cleared
 * home is left out), the group is on the Grouped tab, a home comes out, Undo
 * unties the rest (and deletes a program record it made), and "Not one
 * program" and back. The two program tables are made in the scratch copy.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/program-homes.php';
$GLOBALS['kop_test_options']['kop_program_homes_db'] = KOP_PROGRAM_HOMES_DB_VERSION;

if (kop_rinbox_test_wants('program-homes')) {
    $kop_rinbox_ph_pdo = $GLOBALS['pdo'];
    kop_rinbox_test_copy_tables(array('wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'lawsuit_facility_links'));
    $kop_rinbox_ph_pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_homes (home_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, home_name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $kop_rinbox_ph_pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_groups (program_id INTEGER PRIMARY KEY, created_record INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
    // On production these columns are generated from json_data (as scripts/test-program-homes.php fills them).
    $kop_rinbox_ph_pdo->exec("CREATE TRIGGER IF NOT EXISTS facilities_v2_cols AFTER INSERT ON facilities_v2 BEGIN
        UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name'),
            name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
            state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
            country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
            facility_type = json_extract(NEW.json_data, '$.facilityDetails.type'),
            start_year = json_extract(NEW.json_data, '$.operatingPeriod.startYear'), end_year = json_extract(NEW.json_data, '$.operatingPeriod.endYear')
        WHERE id = NEW.id; END");
    $kop_rinbox_ph_pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
        if (!is_string($v)) return $v;
        $d = json_decode($v);
        return is_string($d) ? $d : $v;
    }, 1);
    unset($kop_rinbox_ph_pdo);
}
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}

function kop_rinbox_test_program_homes(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    // A suggestion with a record named just "Program" there: grouping under it
    // runs on SQLite (making a new record writes MySQL-only memberships).
    foreach (kop_program_homes_suggestions() as $s) {
        if ($s['existing']) { $item = kop_rinbox_get_item('program-homes', $s['key']); break; }
    }
    $key = $item['key'];
    $check('program-homes: an existing record is offered as the program', $item['actions'][0]['params'][0]['value'] !== 'new', $item['title']);
    $homes = array();
    foreach ($item['fields'] as $f) if (preg_match('/^home_(\d+)$/', $f['name'], $m)) $homes[(int) $m[1]] = $f['value'];
    $check('program-homes: a field for the program and each home', $item['fields'][0]['name'] === 'program_name' && count($homes) >= 2, count($homes) . ' homes');
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('program-homes: edits, group and undo', 'update_option does not store in this harness');
        return;
    }
    $ids = array_keys($homes);
    $left_out = end($ids);
    call_user_func($src['save'], $key, array('program_name' => 'Test Program Name', 'home_' . $ids[0] => 'First Home Renamed', 'home_' . $left_out => ''));
    $again = kop_rinbox_get_item('program-homes', $key);
    $vals = array();
    foreach ($again['fields'] as $f) $vals[$f['name']] = $f['value'];
    $check('program-homes: the edits are kept', $vals['program_name'] === 'Test Program Name' && $vals['home_' . $ids[0]] === 'First Home Renamed' && $vals['home_' . $left_out] === '');
    $rows = $again['compare']['rows'] ?? array();
    $marked = array_values(array_filter($rows, function ($r) { return $r['differs']; }));
    $check('program-homes: every home in the table, the left-out one marked', count($rows) === count($ids) && count($marked) === 1
        && $marked[0]['values'][0] === 'left out' && strpos($marked[0]['label'], '#' . $left_out . ')') !== false, count($rows) . ' rows');
    $counts = call_user_func($src['view_counts'], array());
    $check('program-homes: every tab has a count', array_keys($counts) == array_keys($src['views']), json_encode($counts));
    $other = array_values(array_filter($again['actions'], function ($a) { return $a['id'] === 'group_other'; }));
    $check('program-homes: any record can be picked as the program', $other && $other[0]['params'][0]['type'] === 'facility');
    try {
        call_user_func($src['act'], $key, 'group_other', array('program_id' => (string) $ids[0]));
        $check('program-homes: one of the homes is refused as the program', false);
    } catch (RuntimeException $e) {
        $check('program-homes: one of the homes is refused as the program', true, $e->getMessage());
    }
    try {
        call_user_func($src['save'], $key, array('program_name' => '  '));
        $check('program-homes: an empty program name is refused', false);
    } catch (RuntimeException $e) {
        $check('program-homes: an empty program name is refused', true, $e->getMessage());
    }

    $count = function () use ($pdo) { return (int) $pdo->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn(); };
    $records = $count();
    $choice = $item['actions'][0]['params'][0]['value'];
    kop_rinbox_test_with_wpdb_writes(function () use ($src, $key, $check, $pdo, $ids, $left_out, $count, $records, $choice) {
        // The existing record picked with the finder ("Group under this record") is the same as picking it in the list.
        $res = call_user_func($src['act'], $key, 'group_other', array('program_id' => $choice));
        $pid = (int) substr((string) ($res['key'] ?? ''), 8);
        $tied = $pdo->query('SELECT home_id, home_name FROM wpdl_kop_program_homes WHERE program_id = ' . $pid)->fetchAll(PDO::FETCH_KEY_PAIR);
        $check('program-homes: group ties the homes, with the edited names, leaving out the cleared one', $pid > 0
            && ($tied[$ids[0]] ?? '') === 'First Home Renamed' && !isset($tied[$left_out]) && count($tied) === count($ids) - 1, $res['message']);
        $check('program-homes: the suggestion leaves the waiting list', kop_rinbox_ph_suggestion($key) === null);
        $grouped = kop_rinbox_get_item('program-homes', $res['key']);
        $check('program-homes: the group is on the Grouped tab with Undo', $grouped['status'] === 'grouped' && $grouped['actions'][0]['id'] === 'undo');

        $res2 = call_user_func($src['act'], $res['key'], 'remove_home', array('home_id' => (string) $ids[0]));
        $check('program-homes: a home comes out', !$pdo->query('SELECT COUNT(*) FROM wpdl_kop_program_homes WHERE home_id = ' . (int) $ids[0])->fetchColumn(), $res2['message']);
        $res2 = call_user_func($src['act'], $res['key'], 'undo', array());
        $check('program-homes: Undo unties every home and leaves the records there', !$pdo->query('SELECT COUNT(*) FROM wpdl_kop_program_homes')->fetchColumn()
            && $count() === $records, $res2['message']);
    });

    call_user_func($src['act'], $key, 'dismiss', array());
    $got = kop_rinbox_get_item('program-homes', $key);
    $check('program-homes: not one program', $got['status'] === 'dismissed');
    call_user_func($src['act'], $key, 'undismiss', array());
    $got = kop_rinbox_get_item('program-homes', $key);
    $check('program-homes: back in the suggestions', $got['status'] === 'suggested');
}
