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
    'wpdl_kop_fornits_topics', 'wpdl_posts', 'wpdl_postmeta', 'wpdl_kop_review_tags',
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
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");

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

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
