<?php
/**
 * Offline check of the generated facility pages (inc/facility-pages.php)
 * against a SQLite mirror of production (scripts/sync-prod-sqlite.py writes
 * tmp/prod.sqlite). WordPress is stubbed and $wpdb runs the module's real
 * SQL through PDO/SQLite, so the index build, the eligibility rule, the
 * slugs, the routing decisions and the template all run as they would on
 * the site. Read-only: nothing is written to the mirror.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-facility-pages.php [--db=tmp/prod.sqlite] [--render=8] [--id=14182,10371] [--out=<dir>]
 *
 * Prints the index size, how many records qualified and why, slug checks,
 * the routing outcomes for the interesting cases, and renders sample pages
 * to HTML files for a look in a browser.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'render::', 'id::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$render_n = isset($args['render']) ? (int) $args['render'] : 8;
$want_ids = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();
$out_dir = $args['out'] ?? (sys_get_temp_dir() . '/kop-facility-pages');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['kop_test_query_vars'] = array();
$GLOBALS['kop_test_transients'] = array();
$GLOBALS['kop_test_status'] = 0;

class KOP_Test_Redirect extends Exception {
    public $url;
    public $status;
    public function __construct($url, $status) { parent::__construct("redirect $status -> $url"); $this->url = $url; $this->status = $status; }
}

function add_action() {}
function add_filter() {}
function remove_filter() {}
function has_action() { return false; }
function do_action() {}
function apply_filters($tag, $value) { return $value; }
function register_rest_route() {}
function rest_ensure_response($v) { return $v; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function home_url($path = '') { return 'https://example.test' . ($path === '' ? '' : '/' . ltrim($path, '/')); }
function admin_url($path = '') { return home_url('wp-admin/' . $path); }
function site_url($path = '') { return home_url($path); }
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://example.test/theme'; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : $s; }
function remove_accents($s) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s);
    return $t === false ? preg_replace('/[^\x20-\x7E]/', '', (string) $s) : $t;
}
function sanitize_title($s) {
    $s = remove_accents(strip_tags((string) $s));
    $s = strtolower($s);
    $s = preg_replace('/&[a-z]+;/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}
function sanitize_html_class($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $s); }
function esc_url_raw($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function add_query_arg($key, $value = null, $url = null) {
    if (is_array($key)) { $pairs = $key; $url = (string) $value; } else { $pairs = array($key => $value); }
    $q = array();
    foreach ($pairs as $k => $v) $q[] = rawurlencode((string) $k) . '=' . (string) $v;
    return $url . (strpos($url, '?') === false ? '?' : '&') . implode('&', $q);
}
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_list_pluck($list, $field) {
    $out = array();
    foreach ((array) $list as $k => $item) $out[$k] = is_object($item) ? ($item->$field ?? null) : ($item[$field] ?? null);
    return $out;
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time($type = 'mysql') { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function absint($v) { return abs((int) $v); }
function wp_cache_get() { return false; }
function wp_cache_set() { return true; }
function get_transient($k) { return $GLOBALS['kop_test_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['kop_test_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['kop_test_transients'][$k]); return true; }
function get_option($name, $default = false) {
    if ($name === 'kop_data_model_areas') return array('location_pages', 'program_index', 'search', 'homepage_stats', 'facility_profiles');
    if ($name === 'date_format') return 'F j, Y';
    return $default;
}
function update_option() { return true; }
function get_page_by_path() { return null; }
function get_permalink($post = null) { return is_object($post) && isset($post->post_name) ? home_url('/' . $post->post_name . '/') : ''; }
function get_posts($args = array()) {
    global $wpdb;
    if (($args['meta_key'] ?? '') !== '_wp_page_template') return array();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_name, p.post_title FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template'
          WHERE m.meta_value = %s AND p.post_status = 'publish' AND p.post_type IN ('post','page') ORDER BY p.ID",
        $args['meta_value']
    ));
    return $rows;
}
function get_post_meta($id, $key = '', $single = false) {
    global $wpdb;
    $v = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM wpdl_postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1', $id, $key));
    return $v === null ? '' : $v;
}
function get_the_title() { return ''; }
function has_post_thumbnail() { return false; }
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function wp_schedule_single_event() {}
function spawn_cron() {}
function wp_verify_nonce() { return true; }
function wp_create_nonce() { return 'nonce'; }
function current_user_can() { return false; }
function is_user_logged_in() { return false; }
function is_admin() { return false; }
function get_bloginfo() { return 'KOP'; }
function get_query_var($k, $d = '') { return $GLOBALS['kop_test_query_vars'][$k] ?? $d; }
function status_header($code) { $GLOBALS['kop_test_status'] = (int) $code; }
function nocache_headers() {}
function wp_safe_redirect($url, $status = 302) { throw new KOP_Test_Redirect($url, $status); }
function date_i18n($format, $ts = null) { return date($format, $ts ?? time()); }
function shortcode_exists() { return false; }
function do_shortcode($s) { return ''; }
function get_header() { echo "<!doctype html><html><head><meta charset=\"utf-8\"><link rel=\"stylesheet\" href=\"../../css/colors.css\"><link rel=\"stylesheet\" href=\"../../css/facility-profile.css\"></head><body>\n"; }
function get_footer() { echo "\n</body></html>\n"; }
class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_Query { public $is_404 = false; public function set_404() { $this->is_404 = true; } }

/** $wpdb over PDO/SQLite: WordPress-style prepare(), MySQL SHOW TABLES rewritten. */
class wpdb {
    public $prefix = 'wpdl_';
    public $posts = 'wpdl_posts';
    public $postmeta = 'wpdl_postmeta';
    public $last_error = '';
    public $queries = 0;
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = array_values($args[0]);
        $i = 0;
        $pdo = $this->pdo;
        $query = str_replace('%%', "\0PCT\0", $query);
        $out = preg_replace_callback('/%[sdfF]/', function ($m) use (&$i, $args, $pdo) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%d') return (string) (int) $v;
            if ($m[0] === '%f' || $m[0] === '%F') return (string) (float) $v;
            return $pdo->quote((string) $v);
        }, $query);
        return str_replace("\0PCT\0", '%', $out);
    }
    private function rows($sql) {
        $this->queries++;
        if (preg_match("/^\s*SHOW TABLES LIKE\s+('[^']*')/i", $sql, $m)) {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $m[1]";
        }
        try {
            $st = $this->pdo->query($sql);
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            fwrite(STDERR, "SQL error: {$e->getMessage()}\n  " . substr($sql, 0, 300) . "\n");
            return array();
        }
    }
    public function get_results($sql, $output = OBJECT) {
        $rows = $this->rows($sql);
        if ($output === ARRAY_A) return $rows;
        if ($output === ARRAY_N) return array_map('array_values', $rows);
        return array_map(function ($r) { return (object) $r; }, $rows);
    }
    public function get_row($sql, $output = OBJECT, $y = 0) { $rows = $this->get_results($sql, $output); return $rows[$y] ?? null; }
    public function get_var($sql, $x = 0, $y = 0) { $rows = $this->rows($sql); if (!isset($rows[$y])) return null; $v = array_values($rows[$y]); return $v[$x] ?? null; }
    public function get_col($sql, $x = 0) { $out = array(); foreach ($this->rows($sql) as $r) { $v = array_values($r); $out[] = $v[$x] ?? null; } return $out; }
    public function esc_like($s) { return addcslashes((string) $s, '_%\\'); }
    public function query($sql) { return 0; }
}

$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('REGEXP', function ($pattern, $value) {
    return preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/', (string) $value) ? 1 : 0;
}, 2);
$wpdb = new wpdb($pdo);
$GLOBALS['wpdb'] = $wpdb;

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-v2-readers.php';
require_once dirname(__DIR__) . '/inc/database.php';
require_once dirname(__DIR__) . '/inc/rest-api.php';
require_once dirname(__DIR__) . '/inc/country-rest-api.php';
require_once dirname(__DIR__) . '/inc/facility-pages.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

echo "-- Index --\n";
$t = microtime(true);
$index = kop_facility_pages_index(true);
$build_s = microtime(true) - $t;
$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM facilities_v2');
$eligible = count($index['ids']);
$editorial = 0;
foreach ($index['ids'] as $e) if ($e['editorial'] !== '') $editorial++;
printf("  facilities_v2 rows: %d\n  with a page: %d (%.1f%%), of which editorial: %d\n  build time: %.2fs, queries: %d, serialized: %d KB\n",
    $total, $eligible, $eligible * 100 / max(1, $total), $editorial, $build_s, $wpdb->queries, (int) (strlen(serialize($index)) / 1024));
$check('index has entries', $eligible > 0);
$check('fingerprint set', $index['fingerprint'] !== '');
$check('cached read returns the same index', kop_facility_pages_index() === $index);

// Why records qualified (recomputed with the same link sets the build used).
$links = kop_facility_pages_link_sets();
$hist = array();
$only = array();
$thin_status = array();
$signal_counts = array();
foreach ($wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2', ARRAY_A) as $row) {
    $doc = kop_v2_decode($row['json_data']);
    if (!$doc) continue;
    $s = kop_facility_page_signals($doc, (int) $row['id'], $links, $row['unique_name']);
    foreach ($s as $sig) $hist[$sig] = ($hist[$sig] ?? 0) + 1;
    if (count($s) === 1) $only[$s[0]] = ($only[$s[0]] ?? 0) + 1;
    $signal_counts[min(count($s), 8)] = ($signal_counts[min(count($s), 8)] ?? 0) + 1;
    if (!$s) $thin_status[$doc['operatingPeriod']['status'] ?? '?'] = ($thin_status[$doc['operatingPeriod']['status'] ?? '?'] ?? 0) + 1;
}
arsort($hist);
echo "  signals (records carrying each):\n";
foreach ($hist as $sig => $n) printf("    %5d  %s%s\n", $n, $sig, isset($only[$sig]) ? "  ({$only[$sig]} qualify on this alone)" : '');
ksort($signal_counts);
echo '  signals per record: ' . implode(', ', array_map(function ($k, $v) { return ($k === 8 ? '8+' : $k) . ':' . $v; }, array_keys($signal_counts), $signal_counts)) . "\n";
echo '  records without a page, by status: ' . json_encode($thin_status) . "\n";

// ---------------------------------------------------------------------------
// Slugs
// ---------------------------------------------------------------------------

echo "\n-- Slugs --\n";
$bad = array();
$suffixed = array();
foreach ($index['ids'] as $id => $e) {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $e['slug'])) $bad[] = $id . ':' . $e['slug'];
    if (preg_match('/-' . $id . '$/', $e['slug'])) $suffixed[] = $e['slug'];
}
$check('every slug is lowercase a-z, 0-9 and single dashes', !$bad, implode(' | ', array_slice($bad, 0, 5)));
$check('slug map and id map agree', count($index['slugs']) === count($index['ids']));
$ok = true;
foreach ($index['slugs'] as $slug => $id) if (($index['ids'][$id]['slug'] ?? null) !== $slug) { $ok = false; break; }
$check('every slug resolves back to its id', $ok);
echo '  id-suffixed slugs (same name, place and city): ' . count($suffixed) . ($suffixed ? ' e.g. ' . implode(', ', array_slice($suffixed, 0, 4)) : '') . "\n";
$samples = array_slice($index['ids'], 0, 5, true);
foreach ($samples as $id => $e) echo "  $id  /facility/{$e['slug']}/  {$e['name']}" . ($e['editorial'] ? "  -> {$e['editorial']}" : '') . "\n";
$hyde = array();
foreach ($index['ids'] as $id => $e) if (stripos($e['name'], 'Hyde School') === 0 || stripos($e['name'], 'Beloved Ones') === 0) $hyde[] = "$id {$e['slug']}";
echo '  same-name examples: ' . implode(' | ', $hyde) . "\n";

// Name lookup used by the story-arc "Learn more about X" buttons
// (kop_news_arc_facility_link in inc/features.php).
echo "\n-- Name lookup --\n";
foreach (array('Hyde School', 'Provo Canyon School', 'hyde school', 'Provo Canyon School, Inc.') as $probe) {
    $url = kop_facility_page_url_for_name($probe);
    echo "  $probe => " . ($url === '' ? '(none)' : $url) . "\n";
    $check("name lookup resolves '$probe'", $url !== '' && strpos($url, 'search=') === false, $url);
}
$check('name lookup ignores unknown names', kop_facility_page_url_for_name('No Such Place Academy 9000') === '');

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

echo "\n-- Routing --\n";
$route = function ($slug) {
    $GLOBALS['kop_test_query_vars'] = array('kop_facility' => $slug);
    $GLOBALS['kop_facility_page'] = null;
    $GLOBALS['kop_test_status'] = 0;
    $GLOBALS['wp_query'] = new WP_Query();
    try {
        kop_facility_pages_route();
    } catch (KOP_Test_Redirect $r) {
        return array('redirect' => $r->status, 'to' => $r->url);
    }
    if ($GLOBALS['wp_query']->is_404) return array('404' => true);
    return array('page' => $GLOBALS['kop_facility_page']['name'] ?? '', 'status' => $GLOBALS['kop_test_status']);
};

$first_generated = null;
$first_editorial = null;
foreach ($index['ids'] as $id => $e) {
    if ($e['editorial'] === '' && $first_generated === null) $first_generated = $id;
    if ($e['editorial'] !== '' && $first_editorial === null) $first_editorial = $id;
    if ($first_generated !== null && $first_editorial !== null) break;
}
$thin_id = (int) $wpdb->get_var('SELECT MIN(id) FROM facilities_v2 WHERE id NOT IN (' . implode(',', array_map('intval', array_keys($index['ids']))) . ')');

$r = $route($index['ids'][$first_generated]['slug']);
$check('canonical slug renders the page', isset($r['page']) && $r['page'] === $index['ids'][$first_generated]['name'] && $r['status'] === 200, json_encode($r));
$r = $route((string) $first_generated);
$check('numeric id 301s to the slug', isset($r['redirect']) && $r['redirect'] === 301 && $r['to'] === kop_facility_pages_url_for_slug($index['ids'][$first_generated]['slug']), json_encode($r));
$r = $route(strtoupper($index['ids'][$first_generated]['slug']));
$check('uppercase slug 301s to the canonical one', isset($r['redirect']) && $r['redirect'] === 301, json_encode($r));
$r = $route('stale-name-' . $first_generated);
$check('stale slug ending in the id 301s to the current slug', isset($r['redirect']) && $r['redirect'] === 301, json_encode($r));
if ($first_editorial !== null) {
    $r = $route($index['ids'][$first_editorial]['slug']);
    $check('facility with an editorial profile 301s to the post', isset($r['redirect']) && $r['redirect'] === 301 && $r['to'] === $index['ids'][$first_editorial]['editorial'], json_encode($r));
}
$r = $route((string) $thin_id);
$check('thin record 302s to a search', isset($r['redirect']) && $r['redirect'] === 302 && strpos($r['to'], 'search=') !== false, json_encode($r));
$r = $route('no-such-facility-xx');
$check('unknown slug is a 404', isset($r['404']), json_encode($r));
$r = $route('999999999');
$check('unknown id is a 404', isset($r['404']), json_encode($r));
$check('kop_facility_page_url for a generated page', kop_facility_page_url($first_generated) === kop_facility_pages_url_for_slug($index['ids'][$first_generated]['slug']));
$check('kop_facility_page_url for a thin record is empty', kop_facility_page_url($thin_id) === '');

// ---------------------------------------------------------------------------
// The network map's facility id => profile URL map (inc/network-map.php)
// ---------------------------------------------------------------------------
// The map is keyed by facility id and cached for a day. The first request
// after a cache miss returned it keyed correctly and every request after
// that got it back renumbered 0, 1, 2 (array_merge), so the drawer never
// linked a profile on the live page. Both the fresh and the cached copy
// have to carry the ids.
require_once dirname(__DIR__) . '/inc/network-map.php';
if (function_exists('kop_network_map_facility_urls') && function_exists('kop_network_map_graph') && kop_network_map_graph()) {
    $fresh = kop_network_map_facility_urls();
    $again = kop_network_map_facility_urls();
    $graph_ids = array();
    foreach (kop_network_map_graph()['nodes'] as $n) {
        if (!empty($n['facilityId'])) $graph_ids[(int) $n['facilityId']] = true;
    }
    $keyed = function ($urls) use ($graph_ids) {
        if (!is_array($urls) || !$urls) return false;
        foreach ($urls as $id => $url) {
            if (!isset($graph_ids[$id]) || !is_string($url) || $url === '') return false;
        }
        return true;
    };
    $check('network map facility URLs are keyed by facility id', $keyed($fresh), json_encode(array_slice(array_keys($fresh), 0, 5)));
    $check('network map facility URLs keep their ids through the cache', $keyed($again) && $again === $fresh, json_encode(array_slice(array_keys($again), 0, 5)));
    $check('network map facility URLs cover linked nodes', count($fresh) > 100, 'only ' . count($fresh) . ' of ' . count($graph_ids));
} else {
    $check('network map module loads with a graph', false, 'inc/network-map.php or js/data/network/graph.json missing');
}

// ---------------------------------------------------------------------------
// Render samples
// ---------------------------------------------------------------------------

echo "\n-- Render --\n";
$picks = $want_ids;
if (!$picks) {
    // The richest records, plus one qualifying on each linked table alone.
    $by_signals = $index['ids'];
    uasort($by_signals, function ($a, $b) { return $b['signals'] <=> $a['signals']; });
    foreach (array_keys($by_signals) as $id) {
        if ($by_signals[$id]['editorial'] !== '') continue;
        $picks[] = $id;
        if (count($picks) >= max(1, $render_n - 4)) break;
    }
    foreach ($wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2', ARRAY_A) as $row) {
        $doc = kop_v2_decode($row['json_data']);
        if (!$doc) continue;
        $s = kop_facility_page_signals($doc, (int) $row['id'], $links, $row['unique_name']);
        foreach (array('inspections', 'memorials', 'lawsuits', 'news') as $want) {
            if ($s === array($want) && !isset($picked_only[$want]) && ($index['ids'][(int) $row['id']]['editorial'] ?? '') === '') {
                $picked_only[$want] = true;
                $picks[] = (int) $row['id'];
            }
        }
    }
}
$picks = array_values(array_unique($picks));
$sections_seen = array();
foreach ($picks as $id) {
    $t = microtime(true);
    $data = kop_facility_page_data($id);
    $data_s = microtime(true) - $t;
    if (!$data) { $check("data for $id", false, 'no row'); continue; }
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    $rendered = true;
    try {
        include dirname(__DIR__) . '/templates/facility-page.php';
    } catch (Throwable $e) {
        $rendered = false;
        ob_end_clean();
        $check("render $id {$data['name']}", false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        continue;
    }
    $html = ob_get_clean();
    $file = $out_dir . '/' . $data['slug'] . '.html';
    file_put_contents($file, $html);
    preg_match_all('/<section class="kop-fp-section[^"]*" id="([a-z]+)"/', $html, $m);
    foreach ($m[1] as $sec) $sections_seen[$sec] = ($sections_seen[$sec] ?? 0) + 1;
    $check(sprintf('render %d %s', $id, $data['name']), $rendered && strpos($html, '<h1') !== false,
        sprintf('%.0f ms, %d KB, sections: %s', $data_s * 1000, strlen($html) / 1024, implode(',', $m[1]) ?: 'none'));
    echo '      ' . $data['summary'] . "\n";
    echo '      ' . $file . "\n";
}
echo '  sections rendered across samples: ' . json_encode($sections_seen) . "\n";

// Sitemap entries
$entries = kop_facility_pages_sitemap_entries();
$check('sitemap lists every generated page', count($entries) === $eligible - $editorial, count($entries) . ' entries');

echo "\n" . ($failures ? "$failures FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
