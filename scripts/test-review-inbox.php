<?php
/**
 * The review inbox (inc/review-inbox.php and every source in inc/review-inbox/):
 * each source lists its waiting items in the shape js/review-inbox.js draws,
 * opens one by key, saves edited fields, keeps tags, and runs its actions
 * (with Undo where the queue has one). Runs against a temporary copy of the
 * tables in tmp/prod.sqlite; the mirror itself is never written.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php [--source=closure] [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'source::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$only = $args['source'] ?? '';
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

// The tables the sources read and write, copied into a scratch file.
$db_path = sys_get_temp_dir() . '/kop-review-inbox-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
$wanted = array(
    'facilities_v2', 'news_submissions', 'lawsuits', 'legislation', 'wiki_submissions', 'suggested_edits',
    'facility_closure_reports', 'news_closure_scans', 'lawsuit_facility_links', 'lawsuit_news_links', 'news_facility_candidates', 'news_facility_links', 'inspection_highlights',
    'inspection_facilities', 'indigenous_schools', 'indigenous_school_news', 'bug_reports', 'young_adult_programs',
    'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_operator_facilities', 'wpdl_kop_glossary_feedback',
    'wpdl_kop_woodbury_facts', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_gdoc_links', 'wpdl_kop_fornits_items',
    'wpdl_kop_fornits_topics', 'wpdl_posts', 'wpdl_postmeta', 'wpdl_kop_review_tags', 'wpdl_fbv', 'wpdl_fbv_attachment_folder',
);
foreach ($wanted as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

// Writes go to the scratch copy only.
$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
// MySQL functions the queues use.
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['pdo']; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'inbox-test'; public $display_name = 'Inbox Test'; public function exists() { return true; } }; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('kop_facility_finder_label')) {
    require_once dirname(__DIR__) . '/inc/facility-finder.php';
}
// One file per source in scripts/review-inbox-tests/: it loads its queue's
// code, marks the queue's tables as already created (their MySQL CREATE
// statements would not run on SQLite) and defines kop_rinbox_test_<source>()
// with that source's edit and action checks.
foreach (glob(__DIR__ . '/review-inbox-tests/*.php') ?: array() as $f) {
    require_once $f;
}
require_once dirname(__DIR__) . '/inc/review-inbox.php';
require_once dirname(__DIR__) . '/inc/review-inbox-menu.php';
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");
// Recently done and set-aside items (inc/review-inbox-log.php).
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");

/*
 * A made-up queue for the log / Undo / set-aside checks: five items; approve
 * sends one to 'done' (where it offers Undo), undo sends it back, reject drops it.
 */
$GLOBALS['kop_rinbox_zz'] = array('a' => 'waiting', 'b' => 'waiting', 'c' => 'waiting', 'd' => 'waiting', 'e' => 'waiting');
$kop_rinbox_zz_item = function ($k) {
    $st = $GLOBALS['kop_rinbox_zz'][$k] ?? null;
    if ($st === null) return null;
    return array('key' => $k, 'title' => 'Item ' . strtoupper($k), 'status' => $st, 'actions' => $st === 'waiting'
        ? array(array('id' => 'approve', 'label' => 'Approve: do it', 'style' => 'approve', 'help' => 'Does it.'), array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject'))
        : array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo')));
};
if ($only === '' || $only === 'log') kop_rinbox_register('zz-test', function () use ($kop_rinbox_zz_item) {
    return array(
        'label' => 'Test queue', 'views' => array('waiting' => 'Waiting', 'done' => 'Done'),
        'count' => function () { return count(array_filter($GLOBALS['kop_rinbox_zz'], function ($s) { return $s === 'waiting'; })); },
        'list' => function (array $q) use ($kop_rinbox_zz_item) {
            $keys = array_keys(array_filter($GLOBALS['kop_rinbox_zz'], function ($s) use ($q) { return $s === $q['view']; }));
            return array('items' => array_map($kop_rinbox_zz_item, array_slice($keys, $q['offset'], $q['limit'])), 'total' => count($keys));
        },
        'get' => $kop_rinbox_zz_item,
        'act' => function ($k, $action) {
            if ($action === 'approve') $GLOBALS['kop_rinbox_zz'][$k] = 'done';
            elseif ($action === 'undo') $GLOBALS['kop_rinbox_zz'][$k] = 'waiting';
            elseif ($action === 'reject') unset($GLOBALS['kop_rinbox_zz'][$k]);
            else throw new RuntimeException('unknown');
            return array('message' => ucfirst($action) . ' ' . $k . '.');
        },
    );
});
// WordPress user functions the set-aside code reads.
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $names = array(1 => 'Inbox Test', 2 => 'Pat');
        return isset($names[$id]) ? (object) array('ID' => $id, 'display_name' => $names[$id], 'user_login' => strtolower($names[$id]), 'user_email' => '') : false;
    }
}
if (!function_exists('get_users')) { function get_users() { return array(get_userdata(1), get_userdata(2)); } }
if (!function_exists('user_can')) { function user_can($id) { return (bool) get_userdata($id); } }
if (!function_exists('wp_mail')) { function wp_mail() { return true; } }
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $p = array();
        public function set_param($k, $v) { $this->p[$k] = $v; }
        public function get_param($k) { return $this->p[$k] ?? null; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $d;
        public function __construct($d = null, $status = 200) { $this->d = $d; }
        public function get_data() { return $this->d; }
    }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); } }
if (!function_exists('wp_date')) { function wp_date($f, $t = null) { return gmdate($f, $t ?? time()); } }

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

/** Every item must carry what the browser draws. */
$shape_ok = function (array $it) {
    foreach (array('key', 'title', 'fields', 'actions', 'tags') as $k) if (!array_key_exists($k, $it)) return "missing $k";
    if (!is_string($it['key']) || $it['key'] === '') return 'empty key';
    foreach ($it['fields'] as $f) {
        if (empty($f['name']) || empty($f['label'])) return 'field without name/label';
        if (($f['type'] ?? 'text') === 'select' && empty($f['options'])) return 'select without options: ' . $f['name'];
    }
    foreach ($it['actions'] as $a) if (empty($a['id']) || empty($a['label'])) return 'action without id/label';
    return '';
};

$sources = kop_rinbox_sources(true);
$check('sources are registered', count($sources) > 0, implode(', ', array_keys($sources)));

foreach ($sources as $key => $src) {
    if ($only !== '' && $only !== $key) continue;
    echo "-- $key ({$src['label']}) --\n";
    try {
        $n = call_user_func($src['count']);
        $check("$key: count", is_int($n) || ctype_digit((string) $n), (string) $n);
        foreach (array_keys($src['views']) as $view) {
            $res = call_user_func($src['list'], array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 5));
            $items = kop_rinbox_finish_items($key, $res['items']);
            $bad = '';
            foreach ($items as $it) if (($bad = $shape_ok($it)) !== '') break;
            $check("$key/$view: " . count($items) . ' of ' . $res['total'] . ' items have the full shape', $bad === '', $bad);
        }
        $first = call_user_func($src['list'], array('view' => array_keys($src['views'])[0], 'search' => '', 'offset' => 0, 'limit' => 1))['items'][0] ?? null;
        if (!$first) {
            echo "  (nothing waiting in the mirror)\n";
            continue;
        }
        $again = kop_rinbox_get_item($key, $first['key']);
        $check("$key: an item opens by its key", $again['key'] === (string) $first['key'] && $again['title'] === $first['title']);

        $tags = kop_rinbox_set_tags($key, $first['key'], array('Needs Source', ' needs source ', 'follow up'));
        // The shared table keeps tags lower case and sorted; a queue with its own tag column keeps their case.
        $lower = array_map('mb_strtolower', $tags);
        sort($lower);
        $check("$key: tags are cleaned and kept", $lower === array('follow up', 'needs source') && (!empty($src['tags_set']) || $tags === $lower), implode('|', $tags));
        $read = kop_rinbox_tags_for($key, array($first['key']));
        $check("$key: tags read back", ($read[$first['key']] ?? array()) == $tags, json_encode($read));

        $hook = 'kop_rinbox_test_' . str_replace('-', '_', $key);
        if (function_exists($hook)) $hook($src, $first, $check);
    } catch (Throwable $e) {
        $check("$key: no errors", false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'log') {
    echo "-- Recently done, Undo, set aside --\n";
    try {
        global $wpdb;
        $act = function ($key, $action) {
            $req = new WP_REST_Request();
            foreach (array('source' => 'zz-test', 'key' => $key, 'action' => $action, 'params' => array()) as $k => $v) $req->set_param($k, $v);
            return kop_rinbox_rest_act($req)->get_data();
        };
        $act('a', 'approve');
        $row = $wpdb->get_row("SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1", ARRAY_A);
        $check('log: approving records title, label, style and the Undo to run', $row && $row['title'] === 'Item A' && $row['action_label'] === 'Approve: do it'
            && $row['style'] === 'approve' && $row['undo_action'] === 'undo', json_encode($row));
        $list = kop_rinbox_log_list(array('offset' => 0, 'limit' => 10));
        $check('log: Recently done lists it with Undo', $list['total'] >= 1 && $list['rows'][0]['can_undo'] && $list['rows'][0]['source_label'] === 'Test queue');
        $u = kop_rinbox_log_undo((int) $row['id']);
        $check("log: Undo runs the queue's own undo", $GLOBALS['kop_rinbox_zz']['a'] === 'waiting', $u['message']);
        $twice = '';
        try { kop_rinbox_log_undo((int) $row['id']); } catch (Throwable $e) { $twice = $e->getMessage(); }
        $check('log: a second Undo is refused', $twice !== '', $twice);
        $act('b', 'reject');
        $row = $wpdb->get_row("SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1", ARRAY_A);
        $check('log: an item that left the queue has no Undo', $row['style'] === 'reject' && $row['undo_action'] === '', json_encode($row));

        // Set aside: c handed to someone else, d snoozed; neither is in my waiting list, and the pages skip them.
        kop_rinbox_hold_set('zz-test', 'c', 'Item C', 0, 2, 'check it');
        kop_rinbox_hold_set('zz-test', 'd', 'Item D', 7, 0, '');
        kop_rinbox_holds_active(null, true);
        $src = kop_rinbox_source('zz-test');
        $hidden = kop_rinbox_hidden_keys('zz-test');
        $check('holds: assigned to someone else and snoozed leave my list', isset($hidden['c'], $hidden['d']) && count($hidden) === 2, json_encode(array_keys($hidden)));
        $q = array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 1, 'filters' => array());
        $p1 = kop_rinbox_list_unheld($src, 'zz-test', $q);
        $p2 = kop_rinbox_list_unheld($src, 'zz-test', array('offset' => $p1['next_offset']) + $q);
        $check('holds: pages skip set-aside items', array_column($p1['items'], 'key') === array('a') && array_column($p2['items'], 'key') === array('e')
            && $p1['total'] === 2, json_encode(array(array_column($p1['items'], 'key'), array_column($p2['items'], 'key'), $p1['total'])));
        $info = kop_rinbox_hold_info('zz-test', 'c');
        $check('holds: the card says who has it and the note', $info && $info['assigned_name'] === 'Pat' && $info['note'] === 'check it' && $info['hidden'], json_encode($info));
        $snoozed = kop_rinbox_held_items('snoozed');
        $check('holds: Snoozed lists it with its queue', array_column($snoozed['items'], 'key') === array('d') && $snoozed['items'][0]['source_label'] === 'Test queue');
        $act('d', 'approve');
        kop_rinbox_holds_active(null, true);
        $check('holds: approving clears the hold', kop_rinbox_hold_info('zz-test', 'd') === null);
        kop_rinbox_hold_set('zz-test', 'c', 'Item C', 0, 0, '');
        kop_rinbox_holds_active(null, true);
        $check('holds: Put back in the list removes the hold', kop_rinbox_hold_info('zz-test', 'c') === null);
    } catch (Throwable $e) {
        $check('log: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'homes') {
    echo "-- Home of a program (every card about one facility) --\n";
    try {
        kop_rinbox_test_homes_action($check);
    } catch (Throwable $e) {
        $check('homes: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
