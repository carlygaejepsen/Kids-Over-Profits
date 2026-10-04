<?php
/**
 * scripts/test-review-inbox.php checks for the 'young-adult-programs' source
 * (inc/review-inbox/young-adult-programs.php over inc/young-adult-programs.php
 * and the old screen's inc/young-adult-programs-admin.php). A hidden test
 * program with two facts is added to the scratch copy.
 */

require_once dirname(__DIR__, 2) . '/inc/young-adult-programs.php';
require_once dirname(__DIR__, 2) . '/inc/young-adult-programs-admin.php';
$GLOBALS['kop_test_options']['kop_ya_db'] = KOP_YA_DB_VERSION;

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS young_adult_programs (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT, other_names TEXT, city TEXT DEFAULT '',
        state TEXT DEFAULT '', country TEXT DEFAULT '', ages TEXT DEFAULT '', program_type TEXT DEFAULT '', run_by TEXT DEFAULT '', opened INTEGER, closed INTEGER,
        status TEXT DEFAULT 'Unknown', notes TEXT, links TEXT, facts TEXT, review TEXT DEFAULT 'approved', source TEXT DEFAULT '', created_by TEXT DEFAULT '',
        created_at TEXT, updated_at TEXT)");
    $facts = array(
        array('key' => 'inbox-test-1', 'group' => 'history', 'label' => 'Opened in 2004 (Woodbury Reports, May 2004, p. 3)',
            'cites' => array(array('label' => 'May 2004', 'number' => 1, 'page' => 3, 'url' => 'https://example.test/wb.pdf#page=3'))),
        array('key' => 'inbox-test-2', 'group' => 'staff', 'label' => 'Director: A. Tester', 'cites' => array()),
    );
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO young_adult_programs (name, name_key, other_names, city, state, country, ages, program_type, run_by, opened, closed, status,
                   notes, links, facts, review, source, created_by, created_at, updated_at)
                   VALUES ('Inbox Test Transition House', 'inbox test transition house', '', 'Bend', 'OR', '', '18-26', 'transitional living', '', 2004, NULL,
                   'Unknown', '', '', ?, 'pending', 'Woodbury Reports', 'test', ?, ?)")
        ->execute(array(json_encode($facts), $now, $now));
})();

function kop_rinbox_test_young_adult_programs(array $src, array $item, callable $check) {
    $pdo = kop_ya_pdo();
    $id = (int) $item['key'];
    $check('young-adult-programs: the hidden test program waits', $item['title'] === 'Inbox Test Transition House' && $item['status'] === 'hidden', $item['title']);

    call_user_func($src['save'], $item['key'], array('ages' => '18 and older', 'closed' => '2012', 'status' => 'Closed', 'other_names' => array('Inbox Test House')));
    $p = kop_ya_get($pdo, $id);
    $check('young-adult-programs: save changes what it is given and keeps the rest',
        $p['ages'] === '18 and older' && (int) $p['closed'] === 2012 && (int) $p['opened'] === 2004 && $p['city'] === 'Bend' && $p['other_names'] === 'Inbox Test House'
        && $p['review'] === 'pending' && count(kop_ya_facts($p)) === 2);
    foreach (array(array('opened' => 'long ago'), array('status' => 'Maybe'), array('name' => ' ')) as $bad) {
        try {
            call_user_func($src['save'], $item['key'], $bad);
            $check('young-adult-programs: bad ' . key($bad) . ' is refused', false);
        } catch (RuntimeException $e) {
            $check('young-adult-programs: bad ' . key($bad) . ' is refused', true, $e->getMessage());
        }
    }

    $res = call_user_func($src['act'], $item['key'], 'show', array());
    $check('young-adult-programs: Show lists it on the page', kop_ya_get($pdo, $id)['review'] === 'approved', $res['message']);
    call_user_func($src['act'], $item['key'], 'hide', array());
    $check('young-adult-programs: Hide takes it off again', kop_ya_get($pdo, $id)['review'] === 'pending');

    $again = kop_rinbox_get_item('young-adult-programs', $item['key']);
    $drop = array_values(array_filter($again['actions'], function ($a) { return $a['id'] === 'drop_fact'; }))[0] ?? null;
    $check('young-adult-programs: the facts can be taken off one by one', $drop && isset($drop['params'][0]['options']['inbox-test-2']));
    call_user_func($src['act'], $item['key'], 'drop_fact', array('key' => 'inbox-test-2'));
    $check('young-adult-programs: take a fact off', array_column(kop_ya_facts(kop_ya_get($pdo, $id)), 'key') === array('inbox-test-1'));

    $counts = call_user_func($src['view_counts'], array());
    $check('young-adult-programs: every tab has a count', array_keys($counts) === array('hidden', 'listed') && $counts['hidden'] >= 1, json_encode($counts));
    $res = call_user_func($src['tool'], 'add', array('name' => 'Inbox Tool Added Program', 'state' => 'UT', 'hidden' => '1'));
    $added = kop_ya_get($pdo, (int) $res['key']);
    $check('young-adult-programs: Add a program, kept off the page when asked', $added && $added['review'] === 'pending' && $added['state'] === 'UT', $res['message']);
    if ($added) kop_ya_delete($pdo, (int) $added['id']);

    $res = call_user_func($src['act'], $item['key'], 'delete', array());
    $check('young-adult-programs: delete', kop_ya_get($pdo, $id) === null, $res['message']);
    try {
        call_user_func($src['act'], $item['key'], 'show', array());
        $check('young-adult-programs: acting on a deleted program is refused', false);
    } catch (RuntimeException $e) {
        $check('young-adult-programs: acting on a deleted program is refused', true, $e->getMessage());
    }
}
