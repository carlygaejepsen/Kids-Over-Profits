<?php
/**
 * inc/auto-connect.php: which waiting import items connect themselves (a name
 * exactly one record's) and which stay for approval, on a scratch copy of
 * tmp/prod.sqlite. Checks every pick is an exact name of its record (read
 * again from facilities_v2, not through the queue's own matcher), links the
 * wiki picks, logs them as 'auto' with Undo, and never links an undone one
 * again. Woodbury filing and Fornits links need MySQL and the media library,
 * so only their picks are checked.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-auto-connect.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-auto-connect-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach (array('facilities_v2', 'wiki_submissions', 'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_fornits_items',
        'wpdl_kop_fornits_topics', 'wpdl_kop_state_list_rows', 'referrers_master', 'wpdl_referrers_master', 'providers_master',
        'wpdl_providers_master', 'transporters_master', 'wpdl_transporters_master') as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { restore_error_handler(); $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['pdo']; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'test'; public function exists() { return true; } }; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
}
if (!function_exists('kop_facility_finder_label')) {
    require_once dirname(__DIR__) . '/inc/facility-finder.php';
}
require_once dirname(__DIR__) . '/inc/review-inbox.php';
require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/closure-reports.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/fornits.php';
require_once dirname(__DIR__) . '/inc/state-lists.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';
require_once dirname(__DIR__) . '/inc/auto-connect.php';
$pdo = $GLOBALS['pdo'];
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$GLOBALS['kop_test_options']['kop_auto_connect_db'] = KOP_AUTO_CONNECT_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_auto_connect (source TEXT NOT NULL, item_key TEXT NOT NULL, target TEXT NOT NULL DEFAULT '',
    outcome TEXT NOT NULL DEFAULT 'done', note TEXT, created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$has = function ($t) use ($pdo) {
    return (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
};

/* The independent check: every name of each record as a plain key. */
$key = 'kop_autoconn_key';   // scripts/woodbury-scan.py key(), which the queues' matches were made with
$names = array();   // facility id => [key => true]
$holders = array(); // key => [facility id => true]
foreach ($pdo->query('SELECT id, name, unique_name, json_data FROM facilities_v2') as $f) {
    $d = json_decode((string) $f['json_data'], true) ?: array();
    $ident = (array) ($d['identification'] ?? array());
    $all = array($f['name'], $f['unique_name'], $ident['name'] ?? '');
    foreach (array('otherNames', 'pastNames') as $k) {
        foreach ((array) ($ident[$k] ?? array()) as $n) $all[] = is_array($n) ? ($n['name'] ?? '') : $n;
    }
    foreach ($all as $n) {
        $k = $key($n);
        if ($k === '') continue;
        $names[(int) $f['id']][$k] = true;
        $holders[$k][(int) $f['id']] = true;
    }
}

/* ---- Woodbury Reports pages ---- */
echo "-- Woodbury Reports --\n";
if ($has('wpdl_kop_woodbury_mentions')) {
    $picks = kop_autoconn_woodbury_rows(100000);
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_woodbury_mentions WHERE status = 'pending'")->fetchColumn();
    $check('woodbury: some waiting pages connect themselves, the rest wait', $picks && count($picks) < $waiting, count($picks) . ' of ' . $waiting);
    $bad = array();
    foreach ($picks as $r) {
        $fid = (int) $r['facility_id'];
        $found = $r['kind'] === 'section' ? array($r['header']) : explode(', ', (string) $r['matched_name']);
        $exact = false;
        foreach ($found as $n) {
            $k = $key($n);
            if (isset($names[$fid][$k]) && count($holders[$k] ?? array()) === 1 && kop_wiki_upd_tokens($n)) $exact = true;
        }
        if (!$exact) $bad[] = '#' . $r['id'] . ' ' . ($r['header'] ?: $r['matched_name']) . ' -> ' . $r['facility_name'];
        if ($list) echo '  ' . $r['kind'] . ': "' . ($r['kind'] === 'section' ? $r['header'] : $r['matched_name']) . '" -> ' . $r['facility_name'] . ' (' . $r['facility_state'] . ")\n";
    }
    $check('woodbury: every pick is an exact, distinctive name of its record and of no other', !$bad, count($bad) . ' not: ' . implode('; ', array_slice($bad, 0, 5)));
    $kinds = array_count_values(array_column($picks, 'kind'));
    $check('woodbury: close names and pages with other possible records never connect themselves',
        !isset($kinds['fuzzy']) && !array_filter($picks, function ($r) { return trim((string) $r['alternatives'], '[] ') !== ''; }), json_encode($kinds));
} else {
    echo "  (no Woodbury table in the mirror)\n";
}

/* ---- Wiki links ---- */
echo "-- Wiki links --\n";
$check('wiki: exactly one same-name record is a pick', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'same name, same state', 'score' => 100),
    array('unique_name' => 'b', 'reason' => 'every word of the name, same state', 'score' => 65))) !== null);
$check('wiki: two records of the name wait', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'same name, same state', 'score' => 100),
    array('unique_name' => 'b', 'reason' => 'a past name of the record, same state', 'score' => 80))) === null);
$check('wiki: a record whose name only begins with the entry\'s waits', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'the record\'s name begins with the entry\'s name, same town', 'score' => 105))) === null);
$check('wiki: a record listing the name as its current name waits', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'the record\'s current name, same state', 'score' => 90))) === null);

$wpicks = kop_autoconn_wiki_picks($pdo);
$entries = kop_wiki_upd_entries($pdo);
$unlinked = count(array_filter($entries, function ($e) { return $e['kind'] !== 'list' && trim((string) $e['facility_unique_name']) === ''; }));
echo '  ' . count($wpicks) . ' of ' . $unlinked . " unlinked entries have one exact record\n";
$check('wiki: a generic name ("The Academy") never links itself', !array_filter($wpicks, function ($p) { return !array_filter(kop_wiki_upd_entry_names($p[0]), 'kop_wiki_upd_tokens'); }));
if (!$wpicks) {
    // None in the mirror: give one unlinked program entry (on this scratch copy) the exact name of a record in its states.
    foreach ($entries as $eid => $e) {
        if ($e['kind'] !== 'program' || trim((string) $e['facility_unique_name']) !== '') continue;
        $states = kop_wiki_upd_states($e);
        foreach ($pdo->query('SELECT id, name, state FROM facilities_v2 ORDER BY id') as $f) {
            if (($states && !in_array($f['state'], $states, true)) || !kop_wiki_upd_tokens($f['name']) || count($holders[$key($f['name'])] ?? array()) !== 1) continue;
            $pdo->prepare('UPDATE wiki_submissions SET program_name = ? WHERE id = ?')->execute(array($f['name'], $eid));
            break;
        }
        $wpicks = kop_autoconn_wiki_picks($pdo);
        if ($wpicks) break;
        $pdo->prepare('UPDATE wiki_submissions SET program_name = ? WHERE id = ?')->execute(array($e['program_name'], $eid));
    }
    echo '  (made-up: ' . implode(', ', array_map(function ($p) { return '"' . $p[0]['program_name'] . '"'; }, $wpicks)) . ")\n";
}
$bad = array();
foreach ($wpicks as $id => list($e, $c)) {
    $ek = array_map($key, kop_wiki_upd_entry_names($e));
    $fid = (int) ($c['id'] ?? 0);
    if ($fid > 0 && !array_intersect($ek, array_keys($names[$fid] ?? array()))) $bad[] = $e['program_name'] . ' -> ' . $c['name'];
    if ($list) echo '  "' . $e['program_name'] . '" -> ' . $c['name'] . ' (' . $c['reason'] . ")\n";
}
$check('wiki: every pick shares an exact name with its record', !$bad, implode('; ', array_slice($bad, 0, 5)));

$GLOBALS['kop_test_options']['kop_wiki_link_log'] = array();
$n = kop_autoconn_wiki(microtime(true) + 600);
$check('wiki: the run links every pick', $n === count($wpicks), $n . ' linked');
$id = (int) array_key_first($wpicks);
if ($id) {
    $st = $pdo->prepare('SELECT facility_unique_name, facility_link_status FROM wiki_submissions WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $check('wiki: a link is saved as suggested, to the exact record', $row['facility_unique_name'] === $wpicks[$id][1]['unique_name'] && $row['facility_link_status'] === 'suggested');
    $log = $pdo->query("SELECT * FROM wpdl_kop_review_log WHERE source = 'wiki-links' AND item_key = '" . $id . "'")->fetch(PDO::FETCH_ASSOC);
    $check('wiki: Recently done shows it by auto, with Undo', $log && $log['user_login'] === 'auto' && $log['undo_action'] === 'undo', $log['message'] ?? '');
    kop_wiki_upd_unlink($pdo, $id);
    kop_autoconn_wiki(microtime(true) + 600);
    $st->execute(array($id));
    $check('wiki: an entry a person undid is not linked again', trim((string) $st->fetchColumn()) === trim((string) $entries[$id]['facility_unique_name']));
}

/* ---- Fornits ---- */
echo "-- Fornits --\n";
if ($has('wpdl_kop_fornits_items')) {
    $fpicks = kop_autoconn_fornits_rows(100000);
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_fornits_items WHERE kind = 'link' AND status = 'pending'")->fetchColumn();
    $check('fornits: some discussion links connect themselves, the rest wait', $fpicks && count($fpicks) < $waiting, count($fpicks) . ' of ' . $waiting);
    $check('fornits: only names read in the posts, never a board or title alone, never a name other records share',
        !array_filter($fpicks, function ($r) {
            return trim((string) $r['also'], '[] ') !== '' || !preg_match('/opening post|named in \d+ posts/', $r['how']);
        }));
} else {
    echo "  (no Fornits table in the mirror)\n";
}

/* ---- State Lists ---- */
echo "-- State Lists --\n";
if ($has('wpdl_kop_state_list_rows')) {
    $spicks = kop_autoconn_state_list_picks($pdo);
    $bad = array();
    foreach ($spicks as $rid => $fid) {
        $r = kop_sl_row($pdo, $rid);
        $k = $key($r['name']);
        if (!isset($names[$fid][$k])) $bad[] = $r['name'];
    }
    $check('state lists: every pick is the record\'s exact name', !$bad, count($spicks) . ' picks; ' . implode('; ', array_slice($bad, 0, 5)));
} else {
    echo "  (no State Lists table in the mirror)\n";
}

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
