<?php
/**
 * scripts/test-review-inbox.php checks for the 'people-merge' source
 * (inc/review-inbox/people-merge.php over inc/people-merge.php). The person
 * tables are not in the mirror: they are made in the scratch copy and filled
 * by one sync, as scripts/test-people.php does. Then a pair opens with both
 * names, "Not the same" and back, and a real merge with Undo putting the
 * person rows and the facility documents back exactly.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/people.php';
require_once dirname(__DIR__, 2) . '/inc/people-merge.php';
$GLOBALS['kop_test_options']['kop_people_db'] = KOP_PEOPLE_DB_VERSION;

if (kop_rinbox_test_wants('people-merge')) {
    kop_rinbox_test_copy_tables(array('memorial_victims', 'referrers_master'));
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
        aliases TEXT, merged_into INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)");
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_person_roles (record_kind TEXT NOT NULL, record_id INTEGER NOT NULL, list TEXT NOT NULL,
        position INTEGER NOT NULL, person_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', role TEXT, ref TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (record_kind, record_id, list, position))");
    if (!$GLOBALS['pdo']->query('SELECT COUNT(*) FROM wpdl_kop_people')->fetchColumn()) {
        kop_people_sync(array('pdo' => $GLOBALS['pdo'], 'prefix' => 'wpdl_',
            'graph' => json_decode(file_get_contents(dirname(__DIR__, 2) . '/js/data/network/graph.json'), true)));
    }
}

function kop_rinbox_test_people_merge(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    list($a, $b) = array_map('intval', explode(':', $item['key']));
    $check('people-merge: both people are named', strpos($item['text'], '#' . $a) !== false && strpos($item['text'], '#' . $b) !== false);
    $merge = null;
    foreach ($item['actions'] as $x) if ($x['id'] === 'merge') $merge = $x;
    $check('people-merge: merge asks which name to keep', $merge && array_keys($merge['params'][0]['options']) == array((string) $a, (string) $b));

    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('people-merge: dismiss, merge and undo', 'update_option does not store in this harness');
        return;
    }
    $res = call_user_func($src['act'], $item['key'], 'dismiss', array());
    $got = kop_rinbox_get_item('people-merge', $item['key']);
    $check('people-merge: not the same', $got['status'] === 'dismissed' && isset(kop_pmerge_dismissed()[$item['key']]), $res['message']);
    call_user_func($src['act'], $item['key'], 'undismiss', array());
    $got = kop_rinbox_get_item('people-merge', $item['key']);
    $check('people-merge: back on the list', $got['status'] !== 'dismissed');

    // Person rows up to the last one there before the merge: on SQLite each sync
    // through $wpdb adds fresh rows for a few map-only people, which is not the merge's doing.
    $max = (int) $pdo->query('SELECT MAX(id) FROM wpdl_kop_people')->fetchColumn();
    $snap = function () use ($pdo, &$max) {
        return md5(json_encode(array(
            $pdo->query('SELECT id, name, name_key, aliases, merged_into FROM wpdl_kop_people WHERE id <= ' . (int) $max . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM),
            $pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM),
        )));
    };
    // The queue writes through $wpdb (query() runs here for these calls).
    kop_rinbox_test_with_wpdb_writes(function () use ($src, $item, $check, $merge, $a, $b, $pdo, $snap) {
        // Settle the people as the site's own sync leaves them, so the snapshot
        // holds only what the merge changes.
        kop_people_sync();
        $max = (int) $pdo->query('SELECT MAX(id) FROM wpdl_kop_people')->fetchColumn();
        $before = $snap();
        $keep = (int) $merge['params'][0]['value'];
        $drop = $keep === $a ? $b : $a;
        $res = call_user_func($src['act'], $item['key'], 'merge', array('keep' => (string) $keep));
        $into = (int) $pdo->query('SELECT merged_into FROM wpdl_kop_people WHERE id = ' . $drop)->fetchColumn();
        $check('people-merge: merge points one id at the other', $into === $keep && !empty($res['key']), $res['message']);
        if (empty($res['key'])) return;
        $merged = kop_rinbox_get_item('people-merge', $res['key']);
        $check('people-merge: the merge is on the Merged tab with Undo', $merged['status'] === 'merged'
            && array_filter($merged['actions'], function ($x) { return $x['id'] === 'undo'; }));
        $res = call_user_func($src['act'], $res['key'], 'undo', array());
        $check('people-merge: Undo puts the people and documents back exactly', $snap() === $before, $res['message']);
    });
}
