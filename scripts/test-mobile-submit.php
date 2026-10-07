<?php
/**
 * Offline test for the public mobile app intake (inc/mobile-submit.php).
 *
 * Copies the record queues and suggested_edits from the SQLite mirror into
 * memory, then calls the REST callbacks directly: each link type lands in its
 * queue, duplicates come back as {type, status} only (never an id, title or
 * review link), facilities become pending suggested_edits, the honeypot and
 * the hourly cap hold, and the follow-up/newsletter hooks get the sender's email.
 * Nothing is written to the mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-submit.php [--db=path/to/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
ini_set('log_errors', '0'); // the link-sync helpers log MySQL-only DDL failures under SQLite; harmless
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
$mirror = $root . '/tmp/prod.sqlite';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--db=') === 0) {
        $mirror = substr($a, 5);
    }
}
if (!file_exists($mirror)) {
    exit("$mirror not found (scripts/sync-prod-sqlite.py, or pass --db=).\n");
}

define('ABSPATH', $root . '/');
define('HOUR_IN_SECONDS', 3600);

// ---- WordPress stubs -------------------------------------------------------

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request implements ArrayAccess {
    private $params; private $json;
    public function __construct(array $params = array(), $json = null) { $this->params = $params; $this->json = $json; }
    public function get_json_params() { return $this->json; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    #[\ReturnTypeWillChange] public function offsetGet($k) { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}
class KOP_Test_User {
    public $ID = 0; public $display_name = ''; public $user_login = '';
    public function exists() { return false; }
}

$GLOBALS['kop_hooks'] = array();
function add_action($tag, $cb, $prio = 10) { $GLOBALS['kop_hooks'][$tag][] = $cb; }
function add_filter($tag, $cb, $prio = 10) { $GLOBALS['kop_hooks'][$tag][] = $cb; }
function add_meta_box() {}
function register_post_type() {}
function register_rest_route() {}
function get_stylesheet_directory() { return dirname(__DIR__); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function esc_url_raw($u, $protocols = null) { $u = trim((string) $u); return preg_match('#^https?://#i', $u) ? $u : ''; }
function wp_http_validate_url($u) { return filter_var($u, FILTER_VALIDATE_URL) ? $u : false; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function get_current_user_id() { return 0; }
function wp_get_current_user() { return new KOP_Test_User(); }
function current_user_can() { return false; }
function kop_submission_review_url($type) { return 'https://kidsoverprofits.org/review/?type=' . $type; }
function kop_notify_admins($type, $title) { $GLOBALS['kop_notified'][] = $type; return true; }

// A tiny post store for the Websites queue.
$GLOBALS['kop_posts'] = array();
$GLOBALS['kop_meta'] = array();
function wp_insert_post($arr) { $id = 9000 + count($GLOBALS['kop_posts']); $GLOBALS['kop_posts'][$id] = $arr; return $id; }
function update_post_meta($id, $k, $v) { $GLOBALS['kop_meta'][$id][$k] = $v; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['kop_meta'][$id][$k] ?? ''; }
function get_post_status($id) { return $GLOBALS['kop_posts'][$id]['post_status'] ?? false; }
function get_the_title($id) { return $GLOBALS['kop_posts'][$id]['post_title'] ?? ''; }
function get_edit_post_link($id) { return 'https://kidsoverprofits.org/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_posts($args) {
    foreach ($GLOBALS['kop_meta'] as $id => $meta) {
        if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
            return array($id);
        }
    }
    return array();
}

// Rate limit store, salt, and the follow-up / newsletter hooks (calls recorded).
$GLOBALS['transients'] = array();
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
$GLOBALS['followups'] = array();
$GLOBALS['newsletter'] = array();
function kop_followup_register($kind, $id, $p) { $GLOBALS['followups'][] = array('kind' => $kind, 'id' => $id, 'email' => $p['notify_email'] ?? null); }
function kop_newsletter_queue($email, $source) { $GLOBALS['newsletter'][] = array('email' => $email, 'source' => $source); }
function kop_newsletter_email_from($p) { return (string) ($p['notify_email'] ?? ''); }

// ---- Records DB: an in-memory copy of the queues ---------------------------

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
foreach (array('news_submissions', 'lawsuits', 'legislation', 'news_facility_links', 'news_story_arcs',
    'lawsuit_news_links', 'lawsuit_facility_links', 'journalists', 'journalist_articles', 'facilities_master', 'suggested_edits') as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) {
        if ($t === 'suggested_edits') {
            $pdo->exec('CREATE TABLE suggested_edits (id INTEGER PRIMARY KEY AUTOINCREMENT, master_id TEXT, edited_json_data TEXT, reason TEXT, submitter_ip TEXT, status TEXT, created_at TEXT)');
        }
        continue;
    }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->exec('DETACH DATABASE prod');
$GLOBALS['pdo'] = $pdo;
function kop_seed_pdo() { return $GLOBALS['pdo']; }

require $root . '/inc/source-submissions.php';
require $root . '/inc/mobile-submit.php';

// ---- Checks ----------------------------------------------------------------

$fails = 0;
$passes = 0;
function check($ok, $label) {
    global $fails, $passes;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passes++ : $fails++;
}
$ipn = 0;
function new_sender() { global $ipn; $ipn++; $_SERVER['REMOTE_ADDR'] = '203.0.113.' . $ipn; }
function submit(array $payload) { return kop_msub_rest_submit(new WP_REST_Request(array(), $payload)); }
function is_resp($r, $status) { return $r instanceof WP_REST_Response && $r->status === $status; }
function is_err($r, $code, $status) { return $r instanceof WP_Error && $r->code === $code && $r->data['status'] === $status; }
function no_leaks($data) {
    $json = json_encode($data);
    foreach (array('"id"', '"title"', '"review_url"', '"url"') as $k) {
        if (strpos($json, $k) !== false) { return false; }
    }
    return true;
}
function only_type_status($dupes) {
    if (!is_array($dupes) || !$dupes) { return false; }
    foreach ($dupes as $d) {
        $keys = array_keys($d);
        sort($keys);
        if ($keys !== array('status', 'type') || !in_array($d['status'], array('on the site', 'in review'), true)) { return false; }
    }
    return true;
}
function last_followup() { $f = $GLOBALS['followups']; return end($f); }

new_sender();

// Link types, fresh URLs.
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/10/01/mobile-story', 'title' => 'Mobile story',
    'submitter_name' => 'Pat Reader', 'notify_email' => 'pat@example.test'));
check(is_resp($r, 201) && $r->data === array('ok' => true, 'type' => 'article', 'queue' => 'news review'), 'article -> 201 {ok, type, queue}');
$n = $pdo->query("SELECT * FROM news_submissions WHERE article_url = 'https://example-news.test/2026/10/01/mobile-story'")->fetch();
check($n && $n['submitted_by'] === 'Pat Reader (mobile app)', 'article lands in news_submissions, submitter "Pat Reader (mobile app)"');
$f = last_followup();
check($f && $n && $f['kind'] === 'news' && $f['email'] === 'pat@example.test' && $f['id'] === (int) $n['id'], 'follow-up registered as news with the email');

$r = submit(array('type' => 'lawsuit', 'url' => 'https://www.courtlistener.com/docket/888888/doe-v-mobile/', 'title' => 'Doe v. Mobile', 'notify_email' => 'a@example.test'));
check(is_resp($r, 201) && $r->data === array('ok' => true, 'type' => 'lawsuit', 'queue' => 'lawsuit review'), 'lawsuit -> 201 {ok, type, queue}');
$l = $pdo->query("SELECT * FROM lawsuits ORDER BY id DESC LIMIT 1")->fetch();
check($l && strpos($l['source_urls'], 'doe-v-mobile') !== false && $l['submitted_by'] === '(mobile app)', 'lawsuit lands in lawsuits, submitter "(mobile app)" with no name');
$f = last_followup();
check($f['kind'] === 'lawsuit' && $f['email'] === 'a@example.test', 'follow-up registered as lawsuit with the email');

$r = submit(array('type' => 'legislation', 'url' => 'https://www.congress.gov/bill/119th-congress/house-bill/7777', 'title' => 'Mobile Act',
    'bill_number' => 'HR 7777', 'jurisdiction' => 'US', 'submitter_name' => 'Sam', 'notify_email' => 'b@example.test'));
check(is_resp($r, 201) && $r->data === array('ok' => true, 'type' => 'legislation', 'queue' => 'legislation review'), 'legislation -> 201 {ok, type, queue}');
$b = $pdo->query("SELECT * FROM legislation ORDER BY id DESC LIMIT 1")->fetch();
check($b && $b['official_url'] === 'https://www.congress.gov/bill/119th-congress/house-bill/7777' && $b['submitted_by'] === 'Sam (mobile app)', 'legislation lands in legislation, submitter "Sam (mobile app)"');
$f = last_followup();
check($f['kind'] === 'legislation' && $f['email'] === 'b@example.test', 'follow-up registered as legislation with the email');

$before = count($GLOBALS['followups']);
$r = submit(array('type' => 'website', 'url' => 'https://www.mobile-academy.test/', 'title' => 'Mobile Academy', 'submitter_name' => 'Lee', 'notify_email' => 'c@example.test'));
check(is_resp($r, 201) && $r->data === array('ok' => true, 'type' => 'website', 'queue' => 'website review'), 'website -> 201 {ok, type, queue}');
$pid = array_key_last($GLOBALS['kop_posts']);
check(($GLOBALS['kop_posts'][$pid]['post_status'] ?? '') === 'pending' && get_post_meta($pid, '_kop_submitted_by') === 'Lee (mobile app)', 'website lands in the Websites queue, pending, submitter "Lee (mobile app)"');
check(count($GLOBALS['followups']) === $before && end($GLOBALS['newsletter'])['email'] === 'c@example.test', 'website has no follow-up kind; the newsletter hook gets the email');

// Duplicates.
$news = $pdo->query("SELECT article_url, status FROM news_submissions WHERE article_url LIKE 'http%' AND status IN ('approved','published') ORDER BY id DESC LIMIT 1")->fetch();
if (!$news) {
    $news = $pdo->query("SELECT article_url, status FROM news_submissions WHERE article_url LIKE 'http%' AND status <> 'deleted' ORDER BY id DESC LIMIT 1")->fetch();
}
$r = submit(array('type' => 'article', 'url' => $news['article_url'], 'title' => 'Whatever'));
check(is_resp($r, 409) && $r->data['code'] === 'kop_duplicate' && only_type_status($r->data['duplicates']) && no_leaks($r->data), 'known news URL -> 409, duplicates are only {type, status}, no id/title/review_url');
check(is_resp($r, 409) && $r->data['duplicates'][0]['type'] === 'news', 'the duplicate is typed news');
$pending = $pdo->query("SELECT article_url FROM news_submissions WHERE article_url LIKE 'http%' AND status = 'submitted' LIMIT 1")->fetch();
$r = submit(array('type' => 'article', 'url' => $pending ? $pending['article_url'] : 'https://example-news.test/2026/10/01/mobile-story', 'title' => 'x'));
check(is_resp($r, 409) && $r->data['duplicates'][0]['status'] === 'in review', 'an unreviewed (submitted) item reports "in review"');

// Facilities.
$r = submit(array('type' => 'facility_new', 'name' => 'Mobile Test Academy', 'city' => 'Provo', 'state' => 'ut', 'country' => 'US',
    'other_names' => 'Old Mobile Ranch, Mobile Camp', 'operator' => 'Mobile Holdings LLC', 'start_year' => '1998', 'end_year' => 'soon',
    'website' => 'https://mobile-academy.test', 'notify_email' => 'd@example.test'));
check(is_resp($r, 201) && $r->data === array('ok' => true, 'type' => 'facility_new', 'queue' => 'facility review'), 'facility_new -> 201 {ok, type, queue}');
$e = $pdo->query("SELECT * FROM suggested_edits ORDER BY id DESC LIMIT 1")->fetch();
$fac = json_decode($e['edited_json_data'], true)['facilities'][0] ?? array();
check($e['status'] === 'pending' && ($fac['identification']['name'] ?? '') === 'Mobile Test Academy', 'facility_new writes a pending suggested_edits row with identification.name');
check(($fac['identification']['otherNames'] ?? null) === array('Old Mobile Ranch', 'Mobile Camp') && ($fac['identification']['currentOperator'] ?? '') === 'Mobile Holdings LLC', 'otherNames and currentOperator stored');
check(($fac['locationDetails']['city'] ?? '') === 'Provo' && ($fac['locationDetails']['state'] ?? '') === 'UT', 'locationDetails stored');
check(($fac['operatingPeriod'] ?? null) === array('startYear' => '1998'), 'operatingPeriod keeps the 4-digit start year and drops "soon"');
$f = last_followup();
check($f['kind'] === 'suggested_edit' && $f['email'] === 'd@example.test' && $f['id'] === (int) $e['id'], 'follow-up registered as suggested_edit with the email');
check(strpos($e['reason'], '(mobile app)') !== false, 'facility reason names the mobile app as sender');

$r = submit(array('type' => 'facility_new', 'name' => 'Years Only Academy', 'start_year' => '98', 'end_year' => '20x5'));
$e = $pdo->query("SELECT * FROM suggested_edits ORDER BY id DESC LIMIT 1")->fetch();
$fac = json_decode($e['edited_json_data'], true)['facilities'][0] ?? array();
check(is_resp($r, 201) && !isset($fac['operatingPeriod']), 'years that are not 4-digit years are dropped entirely');

$r = submit(array('type' => 'facility_correction', 'facility' => 'Mobile Test Academy', 'facility_id' => 4321));
check(is_err($r, 'kop_no_notes', 400), 'facility_correction without notes -> 400 kop_no_notes');
$r = submit(array('type' => 'facility_correction', 'facility' => 'Mobile Test Academy', 'facility_id' => 4321,
    'notes' => 'It closed in 2019.', 'url' => 'https://example-news.test/closure', 'notify_email' => 'e@example.test'));
check(is_resp($r, 201) && $r->data['queue'] === 'facility review', 'facility_correction with notes -> 201');
$e = $pdo->query("SELECT * FROM suggested_edits ORDER BY id DESC LIMIT 1")->fetch();
$fac = json_decode($e['edited_json_data'], true)['facilities'][0] ?? array();
check(($fac['facilityId'] ?? null) === 4321 && strpos($e['reason'], 'It closed in 2019.') !== false && strpos($e['reason'], 'Source: https://example-news.test/closure') !== false, 'correction row has facilityId, the notes and "Source: <url>" in reason');
check(last_followup()['kind'] === 'suggested_edit', 'correction follow-up registered as suggested_edit');

// Rejections.
$r = submit(array('type' => 'article', 'title' => 'No link'));
check(is_err($r, 'kop_bad_url', 400), 'missing url on a link type -> 400');
$r = submit(array('type' => 'nonsense', 'url' => 'https://example.test/x'));
check(is_err($r, 'kop_bad_type', 400), 'unknown type -> 400');

$counts = function () use ($pdo) {
    return array($pdo->query('SELECT COUNT(*) FROM news_submissions')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM lawsuits')->fetchColumn(),
        $pdo->query('SELECT COUNT(*) FROM legislation')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM suggested_edits')->fetchColumn(), count($GLOBALS['kop_posts']));
};
$before = $counts();
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/bot', 'title' => 'Bot', 'website_hp' => 'http://spam.test'));
check(is_resp($r, 201) && $counts() === $before, 'non-empty website_hp -> 201 and nothing written');

// Rate limit: a fresh sender, KOP_MSUB_HOURLY_CAP successes, then 429.
new_sender();
$ok = 0;
for ($i = 1; $i <= KOP_MSUB_HOURLY_CAP; $i++) {
    $r = submit(array('type' => 'article', 'url' => "https://example-news.test/rate/$i", 'title' => "Rate $i"));
    if (is_resp($r, 201)) { $ok++; }
}
check($ok === KOP_MSUB_HOURLY_CAP, KOP_MSUB_HOURLY_CAP . ' submits in an hour succeed');
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/rate/over', 'title' => 'Over'));
check(is_err($r, 'kop_rate_limited', 429), 'the next submit -> 429 kop_rate_limited');
new_sender();
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/rate/other-sender', 'title' => 'Other'));
check(is_resp($r, 201), 'a different sender is not limited');

// The check route.
$r = kop_msub_rest_check(new WP_REST_Request(array('url' => $news['article_url'], 'type' => 'article')));
check(is_array($r) && $r['duplicate'] === true && only_type_status($r['duplicates']) && no_leaks($r), 'check: known URL -> duplicate true, only {type, status}, no ids/titles');
$r = kop_msub_rest_check(new WP_REST_Request(array('url' => 'https://example-news.test/never-seen-before')));
check($r === array('duplicate' => false, 'duplicates' => array()), 'check: new URL -> duplicate false, no duplicates');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
