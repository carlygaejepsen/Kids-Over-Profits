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
