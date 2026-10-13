<?php
/**
 * scripts/test-review-inbox.php checks for "File under a young adult program",
 * the action every content queue's card gets (inc/review-inbox/_ya-file-action.php):
 * offered on a made-up queue's card (its own name picked), not on Woodbury
 * Facts; files a fact citing the card's link on a program through the REST act
 * (the queue's own act never runs), moves the card to Already on file, logs an
 * Undo that takes the fact off and puts the card back; a new program by name
 * is made hidden and its Undo deletes it; "Only its link" puts the link on the
 * program. Run with --source=ya-file or with no --source.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/young-adult-programs.php';

/** Called by scripts/test-review-inbox.php after the per-source checks. */
function kop_rinbox_test_ya_file_action(callable $check) {
    $ya = kop_ya_pdo();
    $ya->exec("CREATE TABLE IF NOT EXISTS young_adult_programs (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT, other_names TEXT, city TEXT DEFAULT '',
        state TEXT DEFAULT '', country TEXT DEFAULT '', ages TEXT DEFAULT '', program_type TEXT DEFAULT '', run_by TEXT DEFAULT '', opened INTEGER, closed INTEGER,
        status TEXT DEFAULT 'Unknown', notes TEXT, links TEXT, facts TEXT, review TEXT DEFAULT 'approved', source TEXT DEFAULT '', created_by TEXT DEFAULT '',
        created_at TEXT, updated_at TEXT)");
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('ya-file: the action', 'update_option() does not store in this harness');
        return;
    }
    $yid = kop_ya_save($ya, array('name' => 'Filing Test Ranch', 'state' => 'UT', 'review' => 'approved'), 0, 'test');

    $GLOBALS['kop_rinbox_yaf'] = array(
        'a' => array('key' => 'a', 'title' => 'Filing Test Ranch', 'url' => 'https://example.test/news/ranch-closes', 'text' => 'The ranch closed.'),
        'b' => array('key' => 'b', 'title' => 'Staff list for a program nobody has', 'url' => ''),
    );
    kop_rinbox_register('zz-yaf', function () {
        $get = function ($k) {
            $it = $GLOBALS['kop_rinbox_yaf'][$k] ?? null;
            return $it ? $it + array('actions' => array(array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve'))) : null;
        };
        return array('label' => 'Filing test', 'views' => array('waiting' => 'Waiting'),
            'count' => function () { return count($GLOBALS['kop_rinbox_yaf']); },
            'list' => function () use ($get) { return array('items' => array_map($get, array_keys($GLOBALS['kop_rinbox_yaf'])), 'total' => count($GLOBALS['kop_rinbox_yaf'])); },
            'get' => $get,
            'act' => function () { throw new RuntimeException('the queue\'s own act ran'); });
    });
    kop_rinbox_sources(true);
    $act = function ($key, $action, array $params) {
        $req = new WP_REST_Request();
        foreach (array('source' => 'zz-yaf', 'key' => $key, 'action' => $action, 'params' => $params) as $k => $v) $req->set_param($k, $v);
        return kop_rinbox_rest_act($req)->get_data();
    };
    $find = function (array $item, $id) {
        foreach ($item['actions'] as $a) if ($a['id'] === $id) return $a;
        return null;
    };
    $param = function (array $a, $name) {
        foreach ($a['params'] as $p) if ($p['name'] === $name) return $p;
        return null;
    };
    $last_log = function () { return $GLOBALS['wpdb']->get_row('SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1', ARRAY_A); };
    $facts = function ($id) use ($ya) { $p = kop_ya_get($ya, $id); return $p ? kop_ya_facts($p) : null; };
    $waiting = function () {
        $req = new WP_REST_Request();
        $req->set_param('source', 'zz-yaf');
        $req->set_param('view', 'waiting');
        $d = kop_rinbox_rest_items($req)->get_data();
        return array_map(function ($it) { return $it['key']; }, (array) ($d['items'] ?? array()));
    };

    $item = kop_rinbox_get_item('zz-yaf', 'a');
    $a = $find($item, 'ya_file');
    $check('ya-file: a card offers "File under a young adult program"', $a !== null, json_encode($item['actions']));
    $check('ya-file: the card\'s own name picks its program', $a && $param($a, 'ya_id')['value'] === (string) $yid, json_encode($a ? $param($a, 'ya_id')['value'] : null));
    $check('ya-file: a card with a link can file only the link', $a && isset($param($a, 'ya_group')['options']['link']));
    $b = $find(kop_rinbox_get_item('zz-yaf', 'b'), 'ya_file');
    $check('ya-file: a card without a link cannot', $b && !isset($param($b, 'ya_group')['options']['link']));
    $wbf = kop_rinbox_finish_items('woodbury-facts', array(array('key' => 'x', 'title' => 'x')));
    $check('ya-file: Woodbury Facts keeps its own young adult filing', $find($wbf[0], 'ya_file') === null);

    kop_rinbox_test_with_wpdb_writes(function () use ($check, $act, $find, $last_log, $facts, $waiting, $yid, $ya) {
        $res = $act('a', 'ya_file', array('ya_id' => (string) $yid, 'ya_name' => '', 'ya_group' => 'history', 'ya_text' => 'Closed in 2019 after a state investigation.'));
        $f = $facts($yid);
        $check('ya-file: files the line as a fact citing the card\'s link', count($f) === 1 && $f[0]['group'] === 'history'
            && $f[0]['label'] === 'Closed in 2019 after a state investigation.' && $f[0]['cites'][0]['url'] === 'https://example.test/news/ranch-closes'
            && $f[0]['cites'][0]['cite'] === 'Filing Test Ranch', ($res['message'] ?? '') . ' ' . json_encode($f));
        $check('ya-file: the card leaves the list', array_key_exists('item', $res) && $res['item'] === null);
        kop_rinbox_sources(true);
        $check('ya-file: the card is under Already on file, not waiting', !in_array('a', $waiting(), true)
            && isset(kop_rinbox_sources()['zz-yaf']['views']['on_file']), json_encode($waiting()));
        $row = $last_log();
        $check('ya-file: Recently done has its label and an Undo', $row['action_label'] === 'File under a young adult program' && $row['undo_action'] === 'ya_unfile', json_encode($row));
        $err = null;
        try { kop_rinbox_ya_file_act('zz-yaf', 'a', 'ya_file', array('ya_id' => (string) $yid)); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        $check('ya-file: a filed card is not filed twice', $err !== null);

        $u = kop_rinbox_log_undo((int) $row['id']);
        kop_rinbox_sources(true);
        // (The waiting list itself is read once a request, kop_rinbox_on_file_keys(); the next request lists the card again.)
        $check('ya-file: Undo takes the fact off and puts the card back', $facts($yid) === array() && !isset(kop_rinbox_ya_filed()['zz-yaf']['a'])
            && $find(kop_rinbox_get_item('zz-yaf', 'a'), 'ya_file') !== null, $u['message']);

        $res = $act('b', 'ya_file', array('ya_id' => '0', 'ya_name' => 'Brand New Transition House', 'ya_group' => 'staff', 'ya_text' => ''));
        $p = kop_ya_find_by_name($ya, 'Brand New Transition House');
        $check('ya-file: a new name makes a hidden program with the line', $p && $p['review'] === 'pending'
            && kop_ya_facts($p)[0]['label'] === 'Staff list for a program nobody has' && kop_ya_facts($p)[0]['cites'] === array(), $res['message'] ?? json_encode($res));
        kop_rinbox_log_undo((int) $last_log()['id']);
        $check('ya-file: its Undo deletes the program it made', kop_ya_find_by_name($ya, 'Brand New Transition House') === null);

        $res = $act('b', 'ya_file', array('ya_id' => (string) $yid, 'ya_group' => 'link'));
        $check('ya-file: "Only its link" is refused on a card with no link', isset($res['error']) || $facts($yid) === array(), json_encode($res));
        $act('a', 'ya_file', array('ya_id' => (string) $yid, 'ya_group' => 'link'));
        $check('ya-file: "Only its link" puts the link on the program', strpos((string) kop_ya_get($ya, $yid)['links'], 'https://example.test/news/ranch-closes') !== false);
        kop_rinbox_log_undo((int) $last_log()['id']);
        $check('ya-file: and its Undo takes it off', strpos((string) kop_ya_get($ya, $yid)['links'], 'example.test') === false);
    });
    kop_ya_delete($ya, $yid);
}
