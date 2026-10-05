<?php
/**
 * scripts/test-review-inbox.php checks for "Home of a program", the action
 * every card about one facility gets (inc/review-inbox/_homes-action.php):
 * offered on a made-up queue's card, not on Merge Duplicates or a program
 * record; ties the record to the picked program through the REST act and
 * logs an Undo that unties it; "Not a home of" takes it out and its Undo
 * puts it back with its home name. Run with --source=homes or with no --source.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/program-homes.php';
$GLOBALS['kop_test_options']['kop_program_homes_db'] = KOP_PROGRAM_HOMES_DB_VERSION;
if (kop_rinbox_test_wants('homes')) {
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_homes (home_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, home_name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_program_groups (program_id INTEGER PRIMARY KEY, created_record INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
}
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}

/** Called by scripts/test-review-inbox.php after the per-source checks. */
function kop_rinbox_test_homes_action(callable $check) {
    $pdo = $GLOBALS['pdo'];
    $grouped = 'SELECT home_id FROM wpdl_kop_program_homes UNION SELECT program_id FROM wpdl_kop_program_groups';
    $ids = $pdo->query("SELECT id FROM facilities_v2 WHERE name <> '' AND id NOT IN ($grouped) ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) < 2) {
        kop_rinbox_test_skip('homes: the action', 'no two ungrouped facility records in the mirror');
        return;
    }
    list($home, $program) = array_map('intval', $ids);
    $GLOBALS['kop_rinbox_homes_fid'] = $home;
    kop_rinbox_register('zz-homes', function () {
        $get = function ($k) {
            $fid = $k === 'p' ? $GLOBALS['kop_rinbox_homes_prog'] : $GLOBALS['kop_rinbox_homes_fid'];
            return array('key' => $k, 'title' => 'Card ' . $k, 'facility' => kop_rinbox_facility($fid),
                'actions' => array(array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve')));
        };
        return array('label' => 'Homes test', 'views' => array('waiting' => 'Waiting'),
            'count' => function () { return 1; },
            'list' => function () use ($get) { return array('items' => array($get('h')), 'total' => 1); },
            'get' => $get,
            'act' => function () { throw new RuntimeException('the queue\'s own act ran'); });
    });
    $GLOBALS['kop_rinbox_homes_prog'] = $program;
    kop_rinbox_sources(true);
    $act = function ($key, $action, array $params) {
        $req = new WP_REST_Request();
        foreach (array('source' => 'zz-homes', 'key' => $key, 'action' => $action, 'params' => $params) as $k => $v) $req->set_param($k, $v);
        return kop_rinbox_rest_act($req)->get_data();
    };
    $find = function (array $item, $id) {
        foreach ($item['actions'] as $a) if ($a['id'] === $id) return $a;
        return null;
    };
    $last_log = function () { return $GLOBALS['wpdb']->get_row('SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1', ARRAY_A); };
    $tie = function () use ($pdo, $home) { return $pdo->query('SELECT program_id, home_name FROM wpdl_kop_program_homes WHERE home_id = ' . $home)->fetch(PDO::FETCH_ASSOC); };

    $item = kop_rinbox_get_item('zz-homes', 'h');
    $a = $find($item, 'home_of_program');
    $check('homes: a card about one facility offers "Home of a program"', $a && $a['params'][0]['type'] === 'facility' && !empty($a['params'][0]['optional']), json_encode($a));
    $merge = kop_rinbox_finish_items('facility-merge', array(array('key' => 'x', 'title' => 'x', 'facility' => kop_rinbox_facility($home))));
    $check('homes: Merge Duplicates keeps its own homes action', $find($merge[0], 'home_of_program') === null);

    kop_rinbox_test_with_wpdb_writes(function () use ($check, $act, $find, $last_log, $tie, $home, $program) {
        $err = $act('h', 'home_of_program', array('home_program' => '', 'home_program_name' => ''));
        $check('homes: no program picked or named is refused', $err === null || isset($err['error']) || !$GLOBALS['pdo']->query('SELECT COUNT(*) FROM wpdl_kop_program_homes')->fetchColumn());
        $err = null;
        try { kop_rinbox_homes_act('zz-homes', 'h', 'home_of_program', array('home_program' => (string) $home)); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        $check('homes: the record itself is refused as its program', $err !== null, (string) $err);

        $res = $act('h', 'home_of_program', array('home_program' => (string) $program, 'home_program_name' => ''));
        $t = $tie();
        $check('homes: ties the record to the picked program', $t && (int) $t['program_id'] === $program, $res['message'] ?? json_encode($res));
        $row = $last_log();
        $check('homes: Recently done has its label and an Undo', $row['action_label'] === 'Home of a program' && $row['undo_action'] === 'undo_home_of_program', json_encode($row));
        $now = kop_rinbox_get_item('zz-homes', 'h');
        $check('homes: the card now offers "Not a home of"', $find($now, 'not_home_of_program') !== null && $find($now, 'home_of_program') === null);
        $prog = kop_rinbox_get_item('zz-homes', 'p');
        $check('homes: the program record\'s card offers neither', $find($prog, 'home_of_program') === null && $find($prog, 'not_home_of_program') === null);

        $u = kop_rinbox_log_undo((int) $row['id']);
        $check('homes: Undo unties it and drops the group it started', !$tie() && !$GLOBALS['pdo']->query('SELECT COUNT(*) FROM wpdl_kop_program_groups WHERE program_id = ' . $program)->fetchColumn(), $u['message']);

        kop_program_homes_group(array($home => 'East Cottage'), $program, '', kop_program_homes_opts());
        $res = $act('h', 'not_home_of_program', array());
        $check('homes: "Not a home of" takes it out', !$tie(), $res['message'] ?? json_encode($res));
        $u = kop_rinbox_log_undo((int) $last_log()['id']);
        $t = $tie();
        $check('homes: its Undo puts it back with its home name', $t && (int) $t['program_id'] === $program && $t['home_name'] === 'East Cottage', $u['message']);
        kop_program_homes_undo($program);
    });
}
