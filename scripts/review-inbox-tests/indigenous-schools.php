<?php
/**
 * scripts/test-review-inbox.php checks for the 'indigenous-schools' source
 * (inc/review-inbox/indigenous-schools.php over inc/indigenous-schools.php).
 * The mirror has no school waiting, so one is added to the scratch copy.
 */

require_once dirname(__DIR__, 2) . '/inc/indigenous-schools.php';
require_once dirname(__DIR__, 2) . '/inc/indigenous-schools-admin.php';
$GLOBALS['kop_test_options']['kop_ischools_db'] = KOP_ISCHOOLS_DB_VERSION;

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS indigenous_schools (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT, other_names TEXT, country TEXT, region TEXT,
        city TEXT, nations TEXT, run_by TEXT, opened INTEGER, closed INTEGER, status TEXT, notes TEXT, links TEXT, review TEXT, source TEXT,
        created_by TEXT, created_at TEXT, updated_at TEXT)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS indigenous_school_news (school_id INTEGER, news_id INTEGER, created_by TEXT, created_at TEXT, PRIMARY KEY (school_id, news_id))');
    $now = gmdate('Y-m-d H:i:s', time() + 60);
    $pdo->prepare("INSERT INTO indigenous_schools (name, name_key, other_names, country, region, city, nations, run_by, opened, closed, status, notes, links,
                   review, source, created_by, created_at, updated_at) VALUES (?, ?, ?, 'United States', 'SD', 'Pierre', '', '', 1891, NULL, 'Closed', '', '', 'pending', ?, 'news-discovery', ?, ?)")
        ->execute(array('Inbox Test Mission School', 'inbox test mission school', 'Inbox Test Indian School', 'Found by the news scan in: Test Times', $now, $now));
    $sid = (int) $pdo->lastInsertId();
    $nid = (int) $pdo->query('SELECT id FROM news_submissions ORDER BY id LIMIT 1')->fetchColumn();
    if ($nid) {
        $pdo->prepare('INSERT INTO indigenous_school_news (school_id, news_id, created_by, created_at) VALUES (?, ?, ?, ?)')->execute(array($sid, $nid, 'news-discovery', $now));
    }
})();

function kop_rinbox_test_indigenous_schools(array $src, array $item, callable $check) {
    $pdo = kop_ischools_pdo();
    $id = (int) $item['key'];
    $check('indigenous-schools: the waiting item is the test school', $item['title'] === 'Inbox Test Mission School', $item['title']);

    call_user_func($src['save'], $item['key'], array('name' => 'Inbox Test Boarding School', 'closed' => '1931', 'status' => 'Closed', 'other_names' => array('Inbox Test Indian School', 'Pierre Test School')));
    $s = kop_ischools_get($pdo, $id);
    $check('indigenous-schools: save renames and keeps the fields it was not given',
        $s['name'] === 'Inbox Test Boarding School' && (int) $s['closed'] === 1931 && (int) $s['opened'] === 1891 && $s['city'] === 'Pierre'
        && $s['other_names'] === "Inbox Test Indian School\nPierre Test School" && $s['review'] === 'pending' && $s['source'] !== '');
    foreach (array(array('opened' => '18th century'), array('status' => 'Maybe'), array('name' => ' ')) as $bad) {
        try {
            call_user_func($src['save'], $item['key'], $bad);
            $check('indigenous-schools: bad ' . key($bad) . ' is refused', false);
        } catch (RuntimeException $e) {
            $check('indigenous-schools: bad ' . key($bad) . ' is refused', true, $e->getMessage());
        }
    }

    $res = call_user_func($src['act'], $item['key'], 'approve', array());
    $check('indigenous-schools: approve lists it', kop_ischools_get($pdo, $id)['review'] === 'approved', $res['message']);
    $listed = kop_rinbox_get_item('indigenous-schools', $item['key']);
    $check('indigenous-schools: a listed school can be taken off again', in_array('unapprove', array_column($listed['actions'], 'id'), true));
    call_user_func($src['act'], $item['key'], 'unapprove', array());
    $check('indigenous-schools: take off puts it back to waiting', kop_ischools_get($pdo, $id)['review'] === 'pending');

    // The old screen's article boxes: file one by its number, take it off again.
    $nid = (int) $pdo->query("SELECT id FROM news_submissions WHERE status <> 'deleted' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $filed = function ($school) use ($pdo, $nid) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = ? AND news_id = ?');
        $st->execute(array((int) $school, $nid));
        return (int) $st->fetchColumn();
    };
    $res = call_user_func($src['act'], $item['key'], 'link_news', array('news' => '#' . $nid));
    $check('indigenous-schools: file an article by its number', $filed($id) === 1, $res['message']);
    $after = kop_rinbox_get_item('indigenous-schools', $item['key']);
    $unlink = array_values(array_filter($after['actions'], function ($a) { return $a['id'] === 'unlink_news'; }))[0] ?? null;
    $check('indigenous-schools: Take off lists the filed articles', $unlink && isset($unlink['params'][0]['options'][(string) $nid]));
    call_user_func($src['act'], $item['key'], 'unlink_news', array('news_id' => $nid));
    $check('indigenous-schools: take an article off', $filed($id) === 0);
    foreach (array('' => 'nothing given', '#999999999' => 'no such number', 'zzqx no such title qxzz' => 'no match') as $bad => $why) {
        try {
            call_user_func($src['act'], $item['key'], 'link_news', array('news' => $bad));
            $check("indigenous-schools: filing with $why is refused", false);
        } catch (RuntimeException $e) {
            $check("indigenous-schools: filing with $why is refused", true, $e->getMessage());
        }
    }
    $general = kop_rinbox_get_item('indigenous-schools', '0');
    $check('indigenous-schools: the schools in general is an item with the article boxes', $general['title'] !== '' && in_array('link_news', array_column($general['actions'], 'id'), true) && !$general['fields']);
    $had0 = $filed(0);
    call_user_func($src['act'], '0', 'link_news', array('news' => (string) $nid));
    $check('indigenous-schools: file an article under the schools in general', $filed(0) === 1);
    if (!$had0) call_user_func($src['act'], '0', 'unlink_news', array('news_id' => $nid));
    $counts = call_user_func($src['view_counts'], array());
    $check('indigenous-schools: every tab has a count', array_keys($counts) === array_keys($src['views']), json_encode($counts));

    // "Add a school" by hand, and a name refused when it is taken.
    $res = call_user_func($src['tool'], 'add', array('name' => 'Inbox Tool Added School', 'country' => 'Canada', 'region' => 'BC', 'city' => ''));
    $added = kop_ischools_find_by_name($pdo, 'Inbox Tool Added School');
    $check('indigenous-schools: Add a school makes a listed record', $added && $added['review'] === 'approved' && $added['country'] === 'Canada', $res['message']);
    try {
        call_user_func($src['tool'], 'add', array('name' => 'Inbox Tool Added School'));
        $check('indigenous-schools: a name already taken is refused', false);
    } catch (RuntimeException $e) {
        $check('indigenous-schools: a name already taken is refused', true, $e->getMessage());
    }
    if ($added) kop_ischools_delete($pdo, (int) $added['id']);
    try {
        call_user_func($src['tool'], 'move', array('facility_id' => ''));
        $check('indigenous-schools: Move needs a facility', false);
    } catch (RuntimeException $e) {
        $check('indigenous-schools: Move needs a facility', true, $e->getMessage());
    }
    $name = call_user_func($src['list'], array('view' => 'names', 'search' => '', 'offset' => 0, 'limit' => 1))['items'][0] ?? null;
    if ($name) {
        $check('indigenous-schools: a set-aside name offers Add as a school', $name['key'][0] === 'c' && array_column($name['actions'], 'id') === array('add_school'));
    }

    $res = call_user_func($src['act'], $item['key'], 'delete', array());
    $links = $pdo->prepare('SELECT COUNT(*) FROM indigenous_school_news WHERE school_id = ?');
    $links->execute(array($id));
    $check('indigenous-schools: delete drops the school and its article links', kop_ischools_get($pdo, $id) === null && (int) $links->fetchColumn() === 0, $res['message']);
    try {
        call_user_func($src['act'], $item['key'], 'approve', array());
        $check('indigenous-schools: acting on a deleted school is refused', false);
    } catch (RuntimeException $e) {
        $check('indigenous-schools: acting on a deleted school is refused', true, $e->getMessage());
    }
}
