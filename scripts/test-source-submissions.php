<?php
/**
 * Offline test for the browser extension intake (inc/source-submissions.php).
 *
 * Copies news_submissions, lawsuits and legislation from tmp/prod.sqlite into
 * memory, then sends the extension's payloads through the REST callbacks:
 * each type lands in its own table with the right columns, links already on
 * file come back as 409 with the matching record, and the fallback auth
 * header is only honoured on the extension's routes. Nothing is written to
 * the mirror.
 *
 *   php -d extension=pdo_sqlite scripts/test-source-submissions.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
$mirror = $root . '/tmp/prod.sqlite';
if (!file_exists($mirror)) {
    exit("tmp/prod.sqlite not found (scripts/sync-prod-sqlite.py).\n");
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
    public $ID = 7; public $display_name = 'Test Admin'; public $user_login = 'testadmin';
    public function exists() { return true; }
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
function get_current_user_id() { return 7; }
function wp_get_current_user() { return new KOP_Test_User(); }
function current_user_can() { return true; }
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

// ---- Records DB: an in-memory copy of the three tables ---------------------

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
// The three queues, plus what the news/lawsuit follow-ups read and write.
foreach (array('news_submissions', 'lawsuits', 'legislation', 'news_facility_links', 'news_story_arcs',
    'lawsuit_news_links', 'lawsuit_facility_links', 'journalists', 'journalist_articles', 'facilities_master') as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) {
        continue;
    }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->exec('DETACH DATABASE prod');
$GLOBALS['pdo'] = $pdo;
function kop_seed_pdo() { return $GLOBALS['pdo']; }

require $root . '/inc/source-submissions.php';

// ---- Checks ----------------------------------------------------------------

$fails = 0;
function check($ok, $label) {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}
function submit(array $payload) {
    return kop_ext_rest_submit(new WP_REST_Request(array(), $payload));
}
function row(PDO $pdo, $table, $id) {
    $s = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $s->execute(array($id));
    return $s->fetch();
}

$news = $pdo->query("SELECT id, article_url, article_title, publication_name FROM news_submissions WHERE article_url LIKE 'http%' AND status <> 'deleted' ORDER BY id DESC LIMIT 1")->fetch();
$suit = $pdo->query("SELECT id, source_urls FROM lawsuits WHERE source_urls LIKE '[\"http%' LIMIT 1")->fetch();
$bill = $pdo->query("SELECT id, bill_number, jurisdiction FROM legislation WHERE bill_number <> '' AND jurisdiction = 'California' LIMIT 1")->fetch();

// Duplicates, including a tracking-parameter variant of a known article.
$r = submit(array('type' => 'article', 'url' => $news['article_url'] . (strpos($news['article_url'], '?') === false ? '?' : '&') . 'utm_source=x', 'title' => 'Anything'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'news' && $r->data['duplicates'][0]['id'] === (int) $news['id'], 'article already on file (utm variant) -> 409 naming news #' . $news['id']);

$r = submit(array('type' => 'article', 'url' => 'https://example.org/some/other-url', 'title' => $news['article_title'], 'site_name' => $news['publication_name']));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['id'] === (int) $news['id'], 'same title on the same outlet -> 409');

$suitUrl = json_decode($suit['source_urls'], true)[0];
$r = submit(array('type' => 'website', 'url' => $suitUrl, 'title' => 'Sent as a website'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'lawsuit', 'a lawsuit source link sent as a website -> 409 naming the lawsuit');

$r = submit(array('type' => 'legislation', 'url' => 'https://legiscan.com/CA/bill/X/2026', 'title' => 'x',
    'bill_number' => str_replace(' ', '', $bill['bill_number']), 'jurisdiction' => 'CA'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'legislation' && $r->data['duplicates'][0]['id'] === (int) $bill['id'], 'same bill number, state as code -> 409 naming bill #' . $bill['id']);

$r = kop_ext_rest_check(new WP_REST_Request(array('url' => 'https://example.com/kop-connection-test')));
check(is_array($r) && $r['duplicate'] === false && $r['user'] === 'Test Admin', 'connection test URL is not a duplicate and names the user');

// New records, one per queue.
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/09/30/story', 'title' => 'A new story <b>about</b> a program',
    'site_name' => 'Example News', 'author' => 'Jo Reporter', 'published' => '2026-09-29', 'facility' => 'Example Academy',
    'tags' => array('Abuse', 'abuse', ''), 'notes' => 'Check the second half.', 'selection' => 'a quoted line', 'description' => 'Summary text'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'news', 'new article -> 201 in news');
$n = row($pdo, 'news_submissions', $r->data['id']);
check($n['status'] === 'submitted' && $n['article_type'] === 'general' && $n['article_title'] === 'A new story about a program', 'article saved as submitted/general, tags stripped from title');
check($n['publication_name'] === 'Example News' && $n['publication_date'] === '2026-09-29' && $n['author'] === 'Jo Reporter', 'article outlet, date and author');
check($n['submitted_by'] === 'Test Admin (browser extension)', 'article submitted_by names the user and the extension');
check(strpos($n['submission_notes'], 'Check the second half.') === 0 && strpos($n['submission_notes'], 'Highlighted: "a quoted line"') !== false, 'article notes carry the note and the highlight');
check(strpos($n['facilities_mentioned'], 'Example Academy') !== false, 'article facility mention stored');
$r2 = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/09/30/story/', 'title' => 'Again'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'sending the new article again -> 409');

$r = submit(array('type' => 'lawsuit', 'url' => 'https://www.courtlistener.com/docket/999999/doe-v-example/', 'title' => 'Doe v. Example Academy',
    'case_number' => '2:26-cv-01234', 'court' => 'U.S. District Court for the District of Utah', 'jurisdiction' => 'UT', 'facility' => 'Example Academy'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'lawsuit', 'new lawsuit -> 201 in lawsuits');
$l = row($pdo, 'lawsuits', $r->data['id']);
check($l['publication_status'] === 'pending' && $l['case_number'] === '2:26-cv-01234' && $l['jurisdiction'] === 'Utah', 'lawsuit pending, case number, jurisdiction spelled out');
check(json_decode($l['source_urls'], true) === array('https://www.courtlistener.com/docket/999999/doe-v-example/'), 'lawsuit link in source_urls');

$r = submit(array('type' => 'legislation', 'url' => 'https://www.congress.gov/bill/119th-congress/house-bill/9999', 'title' => 'Stop Institutional Child Abuse Act',
    'bill_number' => 'HR 9999', 'jurisdiction' => 'US', 'session' => '119th Congress'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'legislation', 'new bill -> 201 in legislation');
$b = row($pdo, 'legislation', $r->data['id']);
check($b['publication_status'] === 'pending' && $b['jurisdiction'] === 'Federal' && $b['session_year'] === '119th Congress' && $b['official_url'] === 'https://www.congress.gov/bill/119th-congress/house-bill/9999', 'bill pending, Federal, session, official_url');
$r2 = submit(array('type' => 'legislation', 'url' => 'https://www.govtrack.us/congress/bills/119/hr9999', 'title' => 'x', 'bill_number' => 'H.R. 9999', 'jurisdiction' => 'US'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'the same bill from another tracker -> 409');

$r = submit(array('type' => 'website', 'url' => 'https://www.example-academy.test/', 'title' => 'Example Academy home', 'facility' => 'Example Academy'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'website', 'new website -> 201 in the Websites queue');
check(($GLOBALS['kop_posts'][$r->data['id']]['post_status'] ?? '') === 'pending' && get_post_meta($r->data['id'], '_kop_submitted_by') === 'Test Admin (browser extension)', 'website pending with sender');
$r2 = submit(array('type' => 'website', 'url' => 'http://example-academy.test', 'title' => 'x'));
check($r2 instanceof WP_REST_Response && $r2->status === 409 && $r2->data['duplicates'][0]['type'] === 'website', 'same website without www or slash -> 409');

$r = submit(array('type' => 'article', 'url' => 'javascript:alert(1)'));
check($r instanceof WP_Error && $r->data['status'] === 400, 'non-http link -> 400');
check(($GLOBALS['kop_notified'] ?? array()) === array('news', 'lawsuit', 'legislation', 'website'), 'one admin email per new record');

// Fallback auth header: only on the extension's routes.
$cred = 'Basic ' . base64_encode('someone:abcd efgh');
$_SERVER = array('REQUEST_URI' => '/wp-json/kop/v1/extension/check?url=x', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(($_SERVER['PHP_AUTH_USER'] ?? '') === 'someone' && ($_SERVER['PHP_AUTH_PW'] ?? '') === 'abcd efgh', 'X-KOP-Authorization restored on an extension route');
$_SERVER = array('REQUEST_URI' => '/wp-json/wp/v2/users', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(!isset($_SERVER['PHP_AUTH_USER']), 'X-KOP-Authorization ignored elsewhere');
$_SERVER = array('REQUEST_URI' => '/?rest_route=/kop/v1/extension/submit', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(($_SERVER['PHP_AUTH_USER'] ?? '') === 'someone', 'restored on the ?rest_route= form too');

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
