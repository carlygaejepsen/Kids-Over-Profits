<?php
/**
 * scripts/test-review-inbox.php checks for the 'facility-merge' source
 * (inc/review-inbox/facility-merge.php over inc/facility-merge.php): a pair
 * opens with both records, "Not the same" and back, and a real merge of the
 * first pair with Undo putting facilities_v2 back exactly.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/facility-merge.php';

if (kop_rinbox_test_wants('facility-merge')) {
    // Every table a merge reads or moves rows in (as scripts/test-facility-merge.php copies).
    $kop_rinbox_fm_tables = array('lawsuit_facility_links', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_fbv',
        'wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links', 'wpdl_kop_migration_state');
    foreach (kop_fmerge_ref_tables('wpdl_') as $r) $kop_rinbox_fm_tables[] = $r['t'];
    foreach (kop_fmerge_json_tables('wpdl_') as $r) $kop_rinbox_fm_tables[] = $r['t'];
    kop_rinbox_test_copy_tables($kop_rinbox_fm_tables);
    unset($kop_rinbox_fm_tables);
    // "Homes of one program" writes the Program Homes tables (as scripts/review-inbox-tests/program-homes.php makes them).
    require_once dirname(__DIR__, 2) . '/inc/program-homes.php';
    $GLOBALS['kop_test_options']['kop_program_homes_db'] = KOP_PROGRAM_HOMES_DB_VERSION;
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_homes (home_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, home_name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_groups (program_id INTEGER PRIMARY KEY, created_record INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
}

// The mirror is a copy of production, where facility saves go to facilities_v2
// (the real check reads MySQL's SHOW TABLES, which SQLite has not got).
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}

function kop_rinbox_test_facility_merge(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    list($a, $b) = array_map('intval', explode(':', $item['key']));
    $heads = implode(' | ', $item['compare']['heads'] ?? array());
    $check('facility-merge: the two records side by side', count($item['compare']['heads'] ?? array()) === 2
        && strpos($heads, '#' . $a . ')') !== false && strpos($heads, '#' . $b . ')') !== false && count($item['compare']['rows']) >= 3, $heads);
    $labels = array();
    foreach ($item['compare']['rows'] as $r) $labels[$r['label']] = $r;
    $check('facility-merge: the names row is marked when the names differ', isset($labels['Name']) && $labels['Name']['differs'] === ($labels['Name']['values'][0] !== $labels['Name']['values'][1]));
    $counts = call_user_func($src['view_counts'], array());
    $check('facility-merge: every tab has a count', array_keys($counts) == array_keys($src['views']), json_encode($counts));
    $tool = $src['tools'][0] ?? array();
    $check('facility-merge: "merge any two records" asks for both records', ($tool['id'] ?? '') === 'merge_any'
        && array_column($tool['params'], 'type') === array('facility', 'facility'));
    try {
        call_user_func($src['tool'], 'merge_any', array('keep' => (string) $a, 'drop' => (string) $a));
        $check('facility-merge: one record twice is refused', false);
    } catch (RuntimeException $e) {
        $check('facility-merge: one record twice is refused', true, $e->getMessage());
    }
    $merge = null;
    foreach ($item['actions'] as $x) if ($x['id'] === 'merge') $merge = $x;
    $opts = $merge ? array_keys($merge['params'][0]['options']) : array();
    $check('facility-merge: merge asks which record to keep', $merge && $opts == array((string) $a, (string) $b), implode(',', $opts));

    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('facility-merge: dismiss, merge and undo', 'update_option does not store in this harness');
        return;
    }
    $res = call_user_func($src['act'], $item['key'], 'dismiss', array());
    $got = kop_rinbox_get_item('facility-merge', $item['key']);
    $check('facility-merge: not the same', $got['status'] === 'dismissed' && isset(kop_fmerge_dismissed()[$item['key']]), $res['message']);
    call_user_func($src['act'], $item['key'], 'undismiss', array());
    $got = kop_rinbox_get_item('facility-merge', $item['key']);
    $check('facility-merge: back on the list', $got['status'] !== 'dismissed' && !isset(kop_fmerge_dismissed()[$item['key']]));

    $snap = function () use ($pdo) {
        return md5(json_encode($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
    };
    $before = $snap();
    $keep = (int) $merge['params'][0]['value'];
    $drop = $keep === $a ? $b : $a;
    $res = call_user_func($src['act'], $item['key'], 'merge', array('keep' => (string) $keep));
    $gone = !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . $drop)->fetchColumn();
    $check('facility-merge: merge folds one record into the other', $gone && !empty($res['key']), $res['message']);
    if (empty($res['key'])) return;
    $merged = kop_rinbox_get_item('facility-merge', $res['key']);
    $undo = array_filter($merged['actions'], function ($x) { return $x['id'] === 'undo'; });
    $check('facility-merge: the merge is on the Merged tab with Undo', $merged['status'] === 'merged' && $undo);
    $res = call_user_func($src['act'], $res['key'], 'undo', array());
    $check('facility-merge: Undo puts facilities_v2 back exactly', $snap() === $before, $res['message']);

    // The same pair through "Merge these two records", the other way round, then its Undo.
    $res = call_user_func($src['tool'], 'merge_any', array('keep' => (string) $drop, 'drop' => (string) $keep));
    $gone = !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . $keep)->fetchColumn();
    $log = kop_fmerge_log();
    $check('facility-merge: the tool merges any two records', $gone && (int) ($log[0]['keep']['id'] ?? 0) === $drop, $res['message']);
    $res = call_user_func($src['act'], 'merge:' . $log[0]['id'], 'undo', array());
    $check('facility-merge: and its Undo puts facilities_v2 back exactly', $snap() === $before, $res['message']);

    kop_rinbox_test_fmerge_homes($src, $item, $check);
}

/** "Homes of one program": the name both share, tie both to a program, the pair leaves the list, Undo. */
function kop_rinbox_test_fmerge_homes(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $plan = kop_fmerge_homes_plan(999991, "Newport Academy \u{2013} Acre", 999992, 'Newport Academy - Aracena');
    $check('facility-merge: homes: the program name is the part both names share', $plan['program_name'] === 'Newport Academy'
        && $plan['homes'][999991] === 'Acre' && $plan['homes'][999992] === 'Aracena' && $plan['program_id'] === 0, json_encode($plan));
    $plan = kop_fmerge_homes_plan(999991, 'Sunrise Ranch Boys Home', 999992, 'Sunrise Ranch Girls Home');
    $check('facility-merge: homes: names without a dash share their first words', $plan['program_name'] === 'Sunrise Ranch', $plan['program_name']);

    $item = kop_rinbox_get_item('facility-merge', $item['key']);
    $act = array_values(array_filter($item['actions'], function ($a) { return $a['id'] === 'homes'; }))[0] ?? null;
    $check('facility-merge: a pair offers "Homes of one program"', $act && array_column($act['params'], 'name') === array('program', 'program_name', 'program_id', 'home_a', 'home_b'));
    $check('facility-merge: a "Looks like homes of one program" tab', isset($src['views']['homes']) && array_key_exists('homes', call_user_func($src['view_counts'], array())));
    if (!$act) return;
    list($a, $b) = array_map('intval', explode(':', $item['key']));
    // Another record is the program (making a new record writes MySQL-only memberships).
    $pid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE id NOT IN ($a, $b) ORDER BY id LIMIT 1")->fetchColumn();
    $tables = function () use ($pdo) {
        return md5(json_encode(array($pdo->query('SELECT home_id, program_id, home_name FROM wpdl_kop_program_homes ORDER BY home_id')->fetchAll(PDO::FETCH_NUM),
            $pdo->query('SELECT program_id FROM wpdl_kop_program_groups ORDER BY program_id')->fetchAll(PDO::FETCH_NUM))));
    };
    kop_rinbox_test_with_wpdb_writes(function () use ($src, $item, $check, $pdo, $a, $b, $pid, $tables) {
        $before = $tables();
        try {
            call_user_func($src['act'], $item['key'], 'homes', array('program' => 'other', 'program_id' => ''));
            $check('facility-merge: homes: "another record" needs the record', false);
        } catch (RuntimeException $e) {
            $check('facility-merge: homes: "another record" needs the record', true, $e->getMessage());
        }
        $res = call_user_func($src['act'], $item['key'], 'homes', array('program' => 'other', 'program_id' => (string) $pid, 'home_a' => 'Home A', 'home_b' => ''));
        $tied = $pdo->query('SELECT home_id, program_id FROM wpdl_kop_program_homes WHERE home_id IN (' . $a . ',' . $b . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
        $name_a = (string) $pdo->query('SELECT home_name FROM wpdl_kop_program_homes WHERE home_id = ' . $a)->fetchColumn();
        $check('facility-merge: homes: both records are homes of the program, with the typed name', ($tied[$a] ?? 0) === $pid && ($tied[$b] ?? 0) === $pid
            && $name_a === 'Home A' && (bool) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_program_groups WHERE program_id = ' . $pid)->fetchColumn(), $res['message']);
        $got = kop_rinbox_get_item('facility-merge', $item['key']);
        $check('facility-merge: homes: the pair is not offered again, with its Undo', $got['status'] === 'dismissed' && $got['actions'][0]['id'] === 'undo_homes');
        $check('facility-merge: homes: both records are still there', (int) $pdo->query("SELECT COUNT(*) FROM facilities_v2 WHERE id IN ($a, $b)")->fetchColumn() === 2);
        $res = call_user_func($src['act'], $item['key'], 'undo_homes', array());
        $back = kop_rinbox_get_item('facility-merge', $item['key']);
        $check('facility-merge: homes: Undo unties them and puts the pair back', $tables() === $before && $back['status'] !== 'dismissed', $res['message']);
    });
}
