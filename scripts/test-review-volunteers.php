<?php
/**
 * Volunteer reviewers (inc/review-volunteers.php): invite links, what a
 * volunteer sees of each queue (no contact details, no edit fields or
 * actions), recommending, the admin side (recs on cards, "Volunteers
 * recommend", agreement after an approve / reject, reopened by Undo).
 * Runs against a temporary copy of the tables in tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-volunteers.php [--db=tmp/prod.sqlite] [--list]
 *
 * --list prints the first item of each open queue as a volunteer gets it.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-review-volunteers-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
// The same tables as scripts/test-review-inbox.php: every source's test file loads with them.
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

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'vol-test'; public $display_name = 'Vol Test'; public function exists() { return true; } }; }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('wp_salt')) { function wp_salt() { return 'test-salt'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('kop_facility_finder_label')) require_once dirname(__DIR__) . '/inc/facility-finder.php';
foreach (glob(__DIR__ . '/review-inbox-tests/*.php') ?: array() as $f) require_once $f;
require_once dirname(__DIR__) . '/inc/review-inbox.php';
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$GLOBALS['kop_test_options']['kop_volunteers_db'] = KOP_VOL_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE wpdl_kop_volunteers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, link_at TEXT NOT NULL, last_seen TEXT, revoked_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_volunteer_recs (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, source TEXT NOT NULL,
    item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', verdict TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '', outcome_by TEXT NOT NULL DEFAULT '', outcome_at TEXT, UNIQUE (volunteer_id, source, item_key))");

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};

echo "Invite links\n";
list($id, $url) = kop_vol_create('Sam Example', 'from the Discord');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$token = (string) ($q['invite'] ?? '');
$check('link carries a 48-character token', strlen($token) === 48, $url);
$check('only the hash is stored', !$pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteers WHERE token_hash = " . $pdo->quote($token))->fetchColumn());
$check('the token finds the volunteer', (int) (kop_vol_by_token($token)['id'] ?? 0) === $id);
$check('a wrong token finds nobody', kop_vol_by_token(str_repeat('a', 48)) === null && kop_vol_by_token('x') === null);
$_COOKIE[KOP_VOL_COOKIE] = $token;
$vol = kop_vol_current();
$check('the cookie signs the browser in', $vol && (int) $vol['id'] === $id);
$check('the page header depends on the token', kop_vol_csrf($token) !== kop_vol_csrf(str_repeat('b', 48)) && strlen(kop_vol_csrf($token)) === 32);
kop_vol_revoke($id);
$check('turned off: the link stops working', kop_vol_by_token($token) === null);
$url2 = kop_vol_new_link($id);
parse_str((string) parse_url($url2, PHP_URL_QUERY), $q2);
$token2 = (string) $q2['invite'];
$check('a new link turns them back on', (int) (kop_vol_by_token($token2)['id'] ?? 0) === $id);
$check('the old link stays dead', kop_vol_by_token($token) === null);
$vol = kop_vol_by_token($token2);
list($id2) = kop_vol_create('Alex Example');
$vol2 = kop_vol_by_token(substr(kop_vol_new_link($id2), -48));
$check('second volunteer', $vol2 && (int) $vol2['id'] === $id2);

echo "\nWhat volunteers see\n";
$check('scrub removes emails and phone numbers',
    kop_vol_scrub('Write jane.doe@example.com or call (555) 123-4567, or 555.123.4567') === 'Write [email removed] or call [phone removed], or [phone removed]',
    kop_vol_scrub('Write jane.doe@example.com or call (555) 123-4567, or 555.123.4567'));
$check('scrub keeps years and case numbers', kop_vol_scrub('Filed 2021-04-05, case 2:21-cv-00123') === 'Filed 2021-04-05, case 2:21-cv-00123');
$open = array_keys(kop_vol_sources());
$check('default queues are open', in_array('news', $open, true) && in_array('lawsuit', $open, true), implode(', ', $open));
foreach (kop_vol_never_sources() as $never) {
    $GLOBALS['kop_test_options']['kop_volunteer_sources'] = array('news', $never);
    $check("$never can never be opened", !isset(kop_vol_sources()[$never]));
}
unset($GLOBALS['kop_test_options']['kop_volunteer_sources']);
$check('a closed queue is refused', $throws(function () { kop_vol_items(0, 'bug-reports', 0, 5); }) !== '');

$forbidden = array('fields', 'actions', 'moves', 'tags', 'hold', 'recs', 'details');
$first = null;
foreach (kop_vol_sources() as $key => $src) {
    $err = $throws(function () use ($key, &$res, $vol) { $res = kop_vol_items((int) $vol['id'], $key, 0, 5); });
    if ($err !== '') { $check("$key lists", false, $err); continue; }
    $items = $res['items'];
    $bad = '';
    foreach ($items as $it) {
        foreach ($forbidden as $f) if (array_key_exists($f, $it)) $bad = "has $f";
        $blob = json_encode($it);
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $blob)) $bad = 'an email address got through';
        if (!$it['approve'] && !$it['reject']) $bad = 'no approve / reject explanation';
    }
    $check("$key: " . count($items) . ' item(s), copies only', $bad === '', $bad);
    if (isset($args['list']) && $items) echo json_encode($items[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "
";
    if (!$first && $items) $first = $items[0];
}

echo "\nRecommending\n";
if (!$first) {
    $check('some open queue has an item to recommend on', false);
} else {
    $s = $first['source'];
    $k = $first['key'];
    $check('not sure needs a note', $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'unsure', ''); }) !== '');
    $check('only approve / reject / not sure', $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'publish', ''); }) !== '');
    $check('a closed queue is refused', $throws(function () use ($vol) { kop_vol_recommend($vol, 'bug-reports', '1', 'approve', ''); }) !== '');
    $msg = $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'reject', 'Off topic.'); });
    $check("recommend reject on $s:$k", $msg === '', $msg);
    $msg = $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'approve', 'On second look, it is about a program.'); });
    $check('changing it keeps one row', $msg === '' && (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_volunteer_recs')->fetchColumn() === 1);
    kop_vol_recommend($vol2, $s, $k, 'approve', '');
    $after = kop_vol_items((int) $vol['id'], $s, 0, 50);
    $check('the item leaves that volunteer\'s list', !in_array($k, array_column($after['items'], 'key'), true));
    $recs = kop_vol_recs_for($s, array($k));
    $check('the admin card gets both recommendations', count($recs[$k] ?? array()) === 2 && $recs[$k][0]['name'] === 'Sam Example');
    $finished = kop_rinbox_finish_items($s, array(array('key' => $k, 'title' => 'x')));
    $check('finish_items adds recs', count($finished[0]['recs']) === 2);
    $check('open count', kop_vol_open_count() === 1);
    $admin = kop_vol_admin_items();
    $check('"Volunteers recommend" lists it', in_array($k, array_column($admin['items'], 'key'), true), count($admin['items']) . ' item(s)');

    // The admin rejects it through the log (what api/manage-submissions.php and the inbox both write).
    kop_rinbox_log_insert(array('source' => $s, 'item_key' => $k, 'title' => $first['title'], 'action' => 'reject', 'style' => 'reject'));
    $rows = $pdo->query('SELECT volunteer_id, outcome FROM wpdl_kop_volunteer_recs ORDER BY volunteer_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $check('a reject closes both', $rows[$id] === 'reject' && $rows[$id2] === 'reject');
    $check('closed recs leave the card', !kop_vol_recs_for($s, array($k)));
    $check('a decided one cannot be changed', $throws(function () use ($vol, $s, $k) { kop_vol_withdraw($vol, $s, $k); kop_vol_recommend($vol, $s, $k, 'reject', ''); }) !== '');
    $list = array();
    foreach (kop_vol_list() as $v) $list[(int) $v['id']] = $v;
    $check('agreement: Sam differed, Alex differed', (int) $list[$id]['decided'] === 1 && (int) $list[$id]['agreed'] === 0 && (int) $list[$id2]['agreed'] === 0);
    $mine = kop_vol_mine((int) $vol['id']);
    $check('"Your recommendations" shows the outcome', ($mine[0]['outcome'] ?? '') === 'reject' && $mine[0]['verdict'] === 'approve');
    kop_vol_reopen($s, $k);
    $check('Undo reopens them', count(kop_vol_recs_for($s, array($k))[$k] ?? array()) === 2);
    kop_vol_resolve($s, $k, 'approve', 'admin');
    $list = array();
    foreach (kop_vol_list() as $v) $list[(int) $v['id']] = $v;
    $check('agreement after approve: both agreed', (int) $list[$id]['agreed'] === 1 && (int) $list[$id2]['agreed'] === 1);
}

// A held item is never shown.
if ($first) {
    $pdo->exec("DELETE FROM wpdl_kop_volunteer_recs");
    $pdo->prepare("INSERT INTO wpdl_kop_review_holds (source, item_key, assigned_to, created_at) VALUES (?, ?, 1, ?)")->execute(array($first['source'], $first['key'], gmdate('Y-m-d H:i:s')));
    kop_rinbox_holds_active(null, true);
    $res = kop_vol_items((int) $vol2['id'], $first['source'], 0, 50);
    $check('an item handed to an admin is not shown', !in_array($first['key'], array_column($res['items'], 'key'), true));
    $check('and cannot be recommended', $throws(function () use ($vol2, $first) { kop_vol_recommend($vol2, $first['source'], $first['key'], 'approve', ''); }) !== '');
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
