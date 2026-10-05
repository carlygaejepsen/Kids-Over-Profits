<?php
/**
 * Offline test for inc/submission-followup.php ("Email me when this has been reviewed").
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-submission-followup.php
 *
 * Runs against in-memory SQLite stand-ins for the records tables and the
 * WordPress table, with wp_mail captured: sign-up and receipt, a decision that
 * must stand before mail goes out, Undo inside the window, a move to another
 * queue, a deleted item, the daily cap, and that no mail carries the
 * submitter's own text. Nothing is sent.
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['kop_test_options'] = array('kop_followup_db' => '1');
$GLOBALS['kop_test_mail'] = array();

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function is_email($v) { return (bool) filter_var((string) $v, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($v) { return strip_tags((string) $v); }
function add_action() {}
function add_filter() {}
function home_url($p = '') { return 'https://example.org' . $p; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() {}
function wp_list_pluck($list, $f) { return array_map(function ($o) use ($f) { return is_object($o) ? $o->$f : $o[$f]; }, $list); }
function get_bloginfo() { return 'Kids Over Profits'; }
function sanitize_html_class($v) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $v); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function wp_mail($to, $subject, $body) { $GLOBALS['kop_test_mail'][] = compact('to', 'subject', 'body'); return true; }

$records = new PDO('sqlite::memory:');
$records->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$records->exec("CREATE TABLE suggested_edits (id INTEGER PRIMARY KEY, master_id TEXT, status TEXT)");
$records->exec("CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, article_title TEXT, status TEXT)");
$records->exec("CREATE TABLE wiki_submissions (id INTEGER PRIMARY KEY, program_name TEXT, status TEXT)");
$records->exec("CREATE TABLE lawsuits (id INTEGER PRIMARY KEY, case_name TEXT, publication_status TEXT)");
$records->exec("CREATE TABLE legislation (id INTEGER PRIMARY KEY, bill_title TEXT, publication_status TEXT)");
function kop_seed_pdo() { return $GLOBALS['records']; }

/** Just enough of $wpdb, over SQLite. */
class KOP_Followup_Test_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $this->db->quote((string) $v);
        }, $sql);
    }
    public function get_var($sql) { $r = $this->db->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    public function get_row($sql) { $r = $this->db->query($sql)->fetch(PDO::FETCH_OBJ); return $r ?: null; }
    public function get_results($sql, $out = 'OBJECT') { return $this->db->query($sql)->fetchAll($out === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ); }
    public function insert($table, $data) {
        $cols = array_keys($data);
        $st = $this->db->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $ok = $st->execute(array_values($data));
        $this->insert_id = (int) $this->db->lastInsertId();
        return $ok;
    }
    public function update($table, $data, $where) {
        $set = array(); $vals = array();
        foreach ($data as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
        $w = array();
        foreach ($where as $k => $v) { $w[] = "$k = ?"; $vals[] = $v; }
        $st = $this->db->prepare("UPDATE $table SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w));
        $st->execute($vals);
        return $st->rowCount();
    }
}
$wpdb = new KOP_Followup_Test_Wpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->db->exec("CREATE TABLE wp_kop_glossary_feedback (id INTEGER PRIMARY KEY, term TEXT, status TEXT)");
$wpdb->db->exec("CREATE TABLE wp_kop_submission_followups (
    id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, item_id INTEGER, email TEXT DEFAULT '', token TEXT,
    state TEXT DEFAULT 'waiting', moved INTEGER DEFAULT 0, outcome TEXT NULL, decided_seen_at TEXT NULL,
    created_at TEXT, sent_at TEXT NULL)");

require_once __DIR__ . '/../inc/submission-followup.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$cond) $fails++;
}
function mails() { $m = $GLOBALS['kop_test_mail']; $GLOBALS['kop_test_mail'] = array(); return $m; }
function row($kind, $id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM wp_kop_submission_followups WHERE kind = %s AND item_id = %d', $kind, $id));
}

$secret = 'MY PRIVATE NOTE xyz';
$records->exec("INSERT INTO suggested_edits (id, master_id, status) VALUES (11, 'Acme Ranch', 'pending')");
$records->exec("INSERT INTO news_submissions (id, article_title, status) VALUES (21, 'Ranch closes', 'submitted')");
$records->exec("INSERT INTO news_submissions (id, article_title, status) VALUES (22, 'Moved story', 'submitted')");
$records->exec("INSERT INTO lawsuits (id, case_name, publication_status) VALUES (31, 'Doe v. Acme', 'pending')");
$records->exec("INSERT INTO legislation (id, bill_title, publication_status) VALUES (41, 'HB 1', 'pending')");
$records->exec("INSERT INTO wiki_submissions (id, program_name, status) VALUES (51, 'Acme Wiki', 'submitted')");
$wpdb->db->exec("INSERT INTO wp_kop_glossary_feedback (id, term, status) VALUES (61, 'Phase system', 'new')");

echo "Sign-up\n";
check('no box ticked: nothing stored', !kop_followup_register('news', 21, array('notify_email' => '')));
check('bad address: nothing stored', !kop_followup_register('news', 21, array('notify_email' => 'not-an-email')));
check('unknown kind: nothing stored', !kop_followup_register('nope', 21, array('notify_email' => 'a@example.org')));
mails();
check('news sign-up stored', kop_followup_register('news', 21, array('notify_email' => 'a@example.org', 'submissionNotes' => $secret)));
$m = mails();
check('receipt sent to the submitter', count($m) === 1 && $m[0]['to'] === 'a@example.org');
check('receipt names the reference and has a stop link', strpos($m[0]['body'], '#21') !== false && strpos($m[0]['body'], 'kop_followup_stop=') !== false);
check('receipt carries no submitted text', strpos($m[0]['body'], $secret) === false && strpos($m[0]['body'], 'Ranch closes') === false);
check('same address twice: one row, no second receipt', kop_followup_register('news', 21, array('notify_email' => 'a@example.org')) && !mails()
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_kop_submission_followups WHERE item_id = 21") === 1);
foreach (array(array('suggested_edit', 11), array('news', 22), array('lawsuit', 31), array('legislation', 41), array('wiki', 51), array('glossary', 61)) as $s) {
    kop_followup_register($s[0], $s[1], array('notify_email' => 'b@example.org'));
}
mails();

echo "Waiting\n";
$t = time();
$c = kop_followup_run($t);
check('pending items send nothing', !mails() && $c['sent'] === 0 && $c['waiting'] === 7);

echo "Decision must stand\n";
$records->exec("UPDATE suggested_edits SET status = 'approved' WHERE id = 11");
$records->exec("UPDATE news_submissions SET status = 'rejected' WHERE id = 21");
$records->exec("UPDATE lawsuits SET publication_status = 'published' WHERE id = 31");
$records->exec("UPDATE legislation SET publication_status = 'rejected' WHERE id = 41");
$wpdb->db->exec("UPDATE wp_kop_glossary_feedback SET status = 'added' WHERE id = 61");
$c = kop_followup_run($t);
check('first sight of a decision only notes it', !mails() && $c['noted'] === 5);
$c = kop_followup_run($t + 300);
check('still inside the Undo window: nothing sent', !mails() && $c['sent'] === 0);

echo "Undo inside the window\n";
$records->exec("UPDATE legislation SET publication_status = 'pending' WHERE id = 41");
$c = kop_followup_run($t + 400);
check('undone item is back to waiting', $c['reopened'] === 1 && row('legislation', 41)->decided_seen_at === null);

echo "Sending\n";
$c = kop_followup_run($t + KOP_FOLLOWUP_SETTLE + 1);
$m = mails();
$by = array();
foreach ($m as $one) { $by[$one['subject']] = $one; }
check('four updates sent', $c['sent'] === 4 && count($m) === 4);
$added = array_values(array_filter($m, function ($x) { return strpos($x['subject'], 'was added') !== false; }));
check('approved edit, published lawsuit, added glossary note say added', count($added) === 3);
check('added mail names the approved title', (bool) array_filter($added, function ($x) { return strpos($x['body'], 'Doe v. Acme') !== false; }));
$declined = array_values(array_filter($m, function ($x) { return strpos($x['body'], 'not added') !== false; }));
check('rejected news says not added, without its title', count($declined) === 1 && strpos($declined[0]['body'], 'Ranch closes') === false && $declined[0]['to'] === 'a@example.org');
check('address blanked after sending', row('news', 21)->email === '' && row('news', 21)->state === 'sent');
check('undone legislation not sent', row('legislation', 41)->state === 'waiting');
check('second run sends nothing more', kop_followup_run($t + 2000)['sent'] === 0 && !mails());

echo "Moved to another queue\n";
kop_followup_mark_moved(kop_followup_kind_for_native('news'), 22);
$records->exec("UPDATE news_submissions SET status = 'rejected' WHERE id = 22");
kop_followup_run($t + 3000);
kop_followup_run($t + 3000 + KOP_FOLLOWUP_SETTLE + 1);
$m = mails();
check('moved item says kept and filed elsewhere', count($m) === 1 && strpos($m[0]['body'], 'filed it in another part') !== false);

echo "Deleted item\n";
$records->exec("UPDATE wiki_submissions SET status = 'deleted' WHERE id = 51");
kop_followup_run($t + 5000);
check('deleted item: no mail, address blanked', !mails() && row('wiki', 51)->state === 'gone' && row('wiki', 51)->email === '');

echo "Daily cap\n";
for ($i = 0; $i < KOP_FOLLOWUP_DAILY_CAP + 3; $i++) {
    $records->exec("INSERT INTO lawsuits (id, case_name, publication_status) VALUES (" . (100 + $i) . ", 'x', 'pending')");
    kop_followup_register('lawsuit', 100 + $i, array('notify_email' => 'flood@example.org'));
}
check('one address is capped per day', (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_kop_submission_followups WHERE email = 'flood@example.org'") === KOP_FOLLOWUP_DAILY_CAP
    && count(mails()) === KOP_FOLLOWUP_DAILY_CAP);

echo "Form markup\n";
$html = kop_followup_fields('x');
check('own address box when none is linked', strpos($html, 'data-kop-followup-email') !== false && strpos($html, 'data-kop-followup-check') !== false);
$html = kop_followup_fields('y', 'existingEmail');
check('linked form reuses its email box', strpos($html, 'data-kop-followup-for="existingEmail"') !== false && strpos($html, 'data-kop-followup-email') === false);

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
