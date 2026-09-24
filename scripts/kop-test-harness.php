<?php
/**
 * WordPress stubs and a $wpdb over PDO/SQLite for the offline page tests
 * (scripts/test-facility-pages.php, scripts/test-operator-pages.php).
 *
 * Set $db_path before requiring this file. It loads the theme modules the
 * generated pages need and leaves $wpdb in $GLOBALS; nothing is written to
 * the mirror.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

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
define('WEEK_IN_SECONDS', 604800);

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
/* Only the pages the templates actually look up by slug. Returning null for
 * everything used to mean the facility page's "where to report this" callout
 * was never rendered by this harness at all, so a break in it would have gone
 * unnoticed until it reached the site. */
function get_page_by_path($slug = '') {
    if ($slug === 'report-abuse') return (object) array('ID' => 1, 'post_name' => 'report-abuse');
    return null;
}
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
require_once dirname(__DIR__) . '/inc/operator-pages.php';
// Loaded before the index is built: a facility the network map draws
// qualifies for a page, and the page lists its connections.
require_once dirname(__DIR__) . '/inc/network-map.php';
// The facility template asks this for the "where to report" deep link.
require_once dirname(__DIR__) . '/inc/reporting-directory.php';
