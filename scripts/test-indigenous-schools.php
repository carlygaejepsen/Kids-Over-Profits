<?php
/**
 * Offline checks for the Indigenous residential school records
 * (inc/indigenous-schools.php, the admin screen, and the news scan's
 * indigenous_school kind in inc/facility-discovery.php).
 *
 *   php scripts/test-indigenous-schools.php [--db=tmp/prod.sqlite]
 *
 * The tables involved are copied from the production mirror into an
 * in-memory database, so the first move really runs (facility rows out,
 * schools and article links in) without touching the mirror. No Groq calls.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['kop_test_options'] = array();
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');

function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['kop_test_options'][$k]); return true; }
function add_action() {}
function add_filter() {}
function do_action() {}
function apply_filters($t, $v) { return $v; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_textarea($s) { return esc_html($s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function wp_unslash($v) { return $v; }
function get_page_by_path() { return null; }
function clean_post_cache() {}
function current_user_can() { return true; }
function check_admin_referer() { return true; }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function wp_create_nonce() { return 'n'; }
function wp_get_current_user() { return new class { public $display_name = 'Tester'; public function exists() { return true; } }; }
function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); }
function wp_date($f, $t) { return date($f, $t); }
function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function kop_facility_finder_field($name) { return '<input type="number" name="' . $name . '" data-kop-facility-finder="1">'; }
function kop_tools_parent_slug() { return 'kop-data-tools'; }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
// MySQL's GET_LOCK has no SQLite form: run the work in a transaction.
function kop_v2_with_write_lock(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ---- A writable copy of the tables involved ----------------------------- */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE " . $pdo->quote($mirror) . " AS m");
$ids = '11605, 100211, 13578, 13576, 100238';
$pdo->exec("CREATE TABLE facilities_v2 AS SELECT * FROM m.facilities_v2 WHERE id IN ($ids) OR id IN (SELECT id FROM m.facilities_v2 LIMIT 50)");
foreach (array('wpdl_kop_facility_locations', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_identity') as $t) {
    $pdo->exec("CREATE TABLE $t AS SELECT * FROM m.$t WHERE facility_id IN (SELECT id FROM facilities_v2)");
}
$pdo->exec("CREATE TABLE wpdl_kop_facility_addresses AS SELECT * FROM m.wpdl_kop_facility_addresses");
foreach (array('news_submissions', 'news_facility_links', 'news_facility_candidates', 'lawsuit_facility_links', 'facility_closure_reports') as $t) {
    $pdo->exec("CREATE TABLE $t AS SELECT * FROM m.$t");
}
$pdo->exec("DETACH DATABASE m");
// SQLite forms of the two tables kop_ischools_install() makes in MySQL.
$pdo->exec("CREATE TABLE indigenous_schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    other_names TEXT, country TEXT NOT NULL DEFAULT '', region TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', nations TEXT,
    run_by TEXT NOT NULL DEFAULT '', opened INTEGER, closed INTEGER, status TEXT NOT NULL DEFAULT 'Unknown', notes TEXT, links TEXT,
    review TEXT NOT NULL DEFAULT 'approved', source TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE indigenous_school_news (school_id INTEGER NOT NULL, news_id INTEGER NOT NULL, created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (school_id, news_id))");
$GLOBALS['pdo'] = $pdo;
update_option('kop_ischools_db', '1');

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-migration.php';
require_once dirname(__DIR__) . '/inc/page-text.php';
require_once dirname(__DIR__) . '/inc/indigenous-schools.php';
require_once dirname(__DIR__) . '/inc/indigenous-schools-admin.php';

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}
function count_rows(PDO $pdo, $sql, array $p = array()) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
}

/* ---- The first move ------------------------------------------------------ */

echo "First move\n";
$before_links = count_rows($pdo, 'SELECT COUNT(*) FROM news_facility_links WHERE facility_id = 100211');
check($before_links === 1, 'St. Paul\'s has its article link in the facility data before the move');
kop_ischools_maybe_migrate();
$log = get_option('kop_ischools_migration_log');
echo '       ' . implode("\n       ", $log['lines']) . "\n";
check(get_option('kop_ischools_migrated') === '1', 'the move is marked done');
check(count(preg_grep('/^Moved facility record/', $log['lines'])) === 4, 'four records moved');
foreach (array(11605, 100211, 13578, 13576) as $fid) {
    $left = count_rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid))
        + count_rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?', array($fid))
        + count_rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_identity WHERE facility_id = ?', array($fid))
        + count_rows($pdo, 'SELECT COUNT(*) FROM news_facility_links WHERE facility_id = ?', array($fid));
    check($left === 0, "facility record $fid is out of the facility tables");
}
check(count_rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = 100238') === 1
    && count_rows($pdo, 'SELECT COUNT(*) FROM news_facility_links WHERE facility_id = 100238') === 1, 'Ekalavya (India) stays a facility, with its article');
check(count_rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_facility_addresses WHERE facility = 'Carlisle Indian Industrial School'") === 0, 'Carlisle\'s address row is gone');
check(count_rows($pdo, 'SELECT COUNT(*) FROM facilities_v2') > 40, 'other facilities are untouched');

$schools = kop_ischools_all($pdo, 'approved');
$by = array();
foreach ($schools as $s) {
    $by[$s['name_key']] = $s;
}
check(count($schools) === 4, '4 schools listed');
$carlisle = $by['carlisle indian industrial school'] ?? null;
check($carlisle && (int) $carlisle['opened'] === 1879 && (int) $carlisle['closed'] === 1918 && $carlisle['city'] === 'Carlisle' && $carlisle['region'] === 'PA',
    'Carlisle keeps its place and gets its years');
check($carlisle && count(kop_ischools_parse_links($carlisle['links'])) === 2, 'Carlisle has its two source links');
$stpauls = $by['st pauls indian mission school'] ?? null;
check($stpauls && $stpauls['name'] === "St. Paul's Indian Mission School" && $stpauls['city'] === 'Marty', 'St. Paul\'s name is cleaned and its place kept');
check($stpauls && trim((string) $stpauls['notes']) === '', 'the news scan\'s bookkeeping note is not made public');
$news = kop_ischools_news($pdo);
check($stpauls && count($news[(int) $stpauls['id']] ?? array()) === 1 && (int) $news[(int) $stpauls['id']][0]['id'] === 346, 'St. Paul\'s article moved with it');
check(count($news[0] ?? array()) === 2, 'the two general articles are filed under the schools in general');
$cand = $pdo->query("SELECT decision, facility_id FROM news_facility_candidates WHERE id = 120")->fetch(PDO::FETCH_ASSOC);
check($cand && $cand['decision'] === 'indigenous_school' && $cand['facility_id'] === null, 'the news scan\'s decision for St. Paul\'s is re-filed as a school');
$mention = $pdo->query('SELECT facilities_mentioned FROM news_submissions WHERE id = 346')->fetchColumn();
check(strpos((string) $mention, '100211') === false, 'no article still points at a moved record');

$again = $log;
update_option('kop_ischools_migration_log', null);
kop_ischools_maybe_migrate();
check(get_option('kop_ischools_migration_log') === null, 'the move runs only once');
update_option('kop_ischools_migration_log', $again);

/* ---- The page ------------------------------------------------------------ */

echo "Page\n";
ob_start();
kop_ischools_render_public();
$html = ob_get_clean();
check(substr_count($html, 'class="kop-ibs-school"') === 4, 'four school cards');
check(strpos($html, '<h3>United States</h3>') !== false, 'grouped under United States');
check(strpos($html, 'Carlisle, PA · 1879 to 1918') !== false, 'Carlisle\'s place and years line');
check(strpos($html, 'grandforksherald.com') !== false && strpos($html, 'In the news') !== false, 'St. Paul\'s article is linked under it');
check(strpos($html, '<h3>News about the schools</h3>') !== false && strpos($html, 'nativenewsonline.net') !== false, 'the general articles are listed');
check(strpos($html, 'Oaks Indian Mission') !== false && strpos($html, "Murrow Indian Children&#039;s Home") !== false, 'Oaks and Murrow are listed');
check(strpos($html, 'Ekalavya') === false, 'Ekalavya is not');

/* ---- Records from the news scan ---------------------------------------- */

echo "News scan\n";
$sid = kop_ischools_from_news($pdo, array('name' => 'Genoa Indian School', 'officialName' => 'Genoa Indian Industrial School', 'kind' => 'indigenous_school',
    'city' => 'Genoa', 'state' => 'NE', 'country' => '', 'status' => 'Closed', 'startYear' => 1884, 'endYear' => 1934, 'operator' => '<b>BIA</b>'),
    array('id' => 518, 'article_title' => 'Week of Remembrance', 'publication_name' => 'Native News Online', 'publication_date' => ''));
$g = kop_ischools_get($pdo, $sid);
check($g && $g['review'] === 'pending' && $g['country'] === 'United States' && (int) $g['opened'] === 1884 && $g['other_names'] === 'Genoa Indian School',
    'a school from the news waits for review, with its place, years and the article\'s name as another name');
check(kop_ischools_from_news($pdo, array('name' => 'Genoa Indian School'), array('id' => 519)) === $sid, 'the same school named another way is found, not made twice');
check(count(kop_ischools_news($pdo, true)[$sid] ?? array()) === 2, 'both articles are filed under it');
ob_start();
kop_ischools_render_public();
$html2 = ob_get_clean();
check(strpos($html2, 'Genoa') === false, 'a school waiting for review is not on the page');
$pdo->exec("UPDATE indigenous_schools SET review = 'approved' WHERE id = " . (int) $sid);
ob_start();
kop_ischools_render_public();
$html3 = ob_get_clean();
if (getenv('KOP_DUMP')) {
    file_put_contents(getenv('KOP_DUMP'), $html3);
}
check(strpos($html3, 'Genoa, NE · 1884 to 1934') !== false && strpos($html3, '&lt;b&gt;BIA&lt;/b&gt;') !== false, 'once approved it is listed, and its text is escaped');

/* ---- Kept out of the regular news ------------------------------------ */

echo "News feeds
";
$linked = array_map('intval', $pdo->query('SELECT DISTINCT news_id FROM indigenous_school_news')->fetchAll(PDO::FETCH_COLUMN));
$feed = array_map('intval', $pdo->query("SELECT id FROM news_submissions WHERE status IN ('approved','published')" . kop_ischools_news_exclude_sql())->fetchAll(PDO::FETCH_COLUMN));
check($linked && !array_intersect($linked, $feed) && $feed, 'articles filed under a school are left out of the news feed query (' . count($linked) . ' articles)');

/* ---- The admin screen ---------------------------------------------------- */

echo "Admin screen\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$_POST = array();
ob_start();
kop_render_ischools_admin();
$screen = ob_get_clean();
check(strpos($screen, 'Schools (5)') !== false && strpos($screen, 'Move here') !== false && strpos($screen, 'data-kop-facility-finder') !== false,
    'the screen lists the schools and offers the move by facility search');
check(strpos($screen, 'First move') !== false && strpos($screen, 'Moved facility record 11605') !== false, 'the first move\'s log is shown');
$_GET = array('edit' => $sid);
ob_start();
kop_render_ischools_admin();
$form = ob_get_clean();
check(strpos($form, 'name="s[name]" value="Genoa Indian Industrial School"') !== false && strpos($form, 'File an article') !== false, 'the edit form and article search');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = array();
$_POST = array('kop_is_do' => 'save', 'kop_is_id' => $sid, 's' => array('name' => 'Genoa Indian Industrial School', 'country' => 'United States',
    'region' => 'NE', 'city' => 'Genoa', 'opened' => '1884', 'closed' => '1934', 'status' => 'Closed', 'nations' => 'Many Nations', 'notes' => 'A note.'));
ob_start();
kop_render_ischools_admin();
$saved = ob_get_clean();
check(strpos($saved, 'Saved.') !== false && kop_ischools_get($pdo, $sid)['nations'] === 'Many Nations', 'saving from the form');
$_POST = array('kop_is_do' => 'move', 'kop_is_facility' => 100238);
ob_start();
kop_render_ischools_admin();
ob_get_clean();
check(count_rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = 100238') === 0, '"Move here" moves a record the admin picks');
$_POST = array('kop_is_do' => 'delete', 'kop_is_id' => (int) kop_ischools_find_by_name($pdo, 'Ekalavya Model Residential School')['id']);
ob_start();
kop_render_ischools_admin();
ob_get_clean();
check(kop_ischools_find_by_name($pdo, 'Ekalavya Model Residential School') === null, 'and Delete removes a school');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
