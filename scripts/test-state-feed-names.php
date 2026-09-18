<?php
/**
 * Offline check that the state and country feeds (kop/v1/state/<state>,
 * kop/v1/country/<country>) carry every alternate name facilities_v2 holds,
 * run against a SQLite mirror of production (scripts/sync-prod-sqlite.py
 * writes tmp/prod.sqlite). WordPress is stubbed and $wpdb runs the feed's
 * real SQL through PDO/SQLite. Read-only: nothing is written to the mirror.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=4G \
 *       scripts/test-state-feed-names.php [--db=tmp/prod.sqlite] [--theme=<dir>] [--legacy]
 *       [--pages=Utah,Texas] [--gap=<file.csv>]
 *
 * For each page, every facilities_v2 record on it that has an otherName, a
 * pastName or a currentName other than its own name must land on a tile
 * that shows all of those names, and no tile may show a name that is only
 * its operator's (provenance.sourceOperator.otherNames). --theme runs another
 * checkout's inc/ (to measure the code before a change); --legacy reads the
 * legacy copies instead of v2 (the location_pages switch off); --gap writes
 * the facilities_v2 records with no alternate name at all as CSV.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'theme::', 'legacy', 'pages::', 'gap::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$theme = rtrim($args['theme'] ?? dirname(__DIR__), '/\\');
$legacy = isset($args['legacy']);
$only_pages = isset($args['pages']) ? array_map('trim', explode(',', (string) $args['pages'])) : array();
$gap_path = $args['gap'] ?? '';
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
$GLOBALS['kop_test_legacy'] = $legacy;

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

define('ABSPATH', $theme . '/');
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
function get_stylesheet_directory() { return $GLOBALS['theme']; }
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
    if ($name === 'kop_data_model_areas') {
        return $GLOBALS['kop_test_legacy'] ? array() : array('location_pages', 'program_index', 'search', 'homepage_stats', 'facility_profiles');
    }
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

require_once $theme . '/inc/facility-store.php';
require_once $theme . '/inc/facility-v2-readers.php';
require_once $theme . '/inc/database.php';
require_once $theme . '/inc/rest-api.php';
require_once $theme . '/inc/country-rest-api.php';
require_once $theme . '/inc/facility-pages.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Trimmed, non-empty strings from a name list (bare strings or {name}). */
function kop_test_names($items) {
    $out = array();
    foreach ((array) $items as $item) {
        $n = is_array($item) ? ($item['name'] ?? '') : $item;
        if (is_scalar($n) && trim((string) $n) !== '') $out[] = trim((string) $n);
    }
    return $out;
}

/** A v2 document's own alternate names (currentName only when it differs from the name). */
function kop_test_record_names(array $doc) {
    $ident = $doc['identification'] ?? array();
    $name = trim((string) ($ident['name'] ?? ''));
    $names = array_merge(kop_test_names($ident['otherNames'] ?? array()), kop_test_names($ident['pastNames'] ?? array()));
    $current = trim((string) ($ident['currentName'] ?? ''));
    if ($current !== '' && strcasecmp($current, $name) !== 0) $names[] = $current;
    $out = array();
    foreach ($names as $n) {
        if (strcasecmp($n, $name) !== 0) $out[mb_strtolower($n)] = $n;
    }
    return $out;
}

/** Lowercased names a tile shows: its name plus the three alternate-name fields. */
function kop_test_tile_names(array $tile) {
    $names = array_merge(
        array((string) ($tile['name'] ?? ''), (string) ($tile['current_name'] ?? '')),
        kop_test_names($tile['other_names'] ?? array()),
        kop_test_names($tile['past_names'] ?? array())
    );
    $out = array();
    foreach ($names as $n) {
        if (trim($n) !== '') $out[mb_strtolower(trim($n))] = true;
    }
    return $out;
}

/** Does the card face show an alternate name (anything besides its own name)? */
function kop_test_tile_has_alt(array $tile) {
    $shown = kop_test_tile_names($tile);
    unset($shown[mb_strtolower(trim((string) ($tile['name'] ?? '')))]);
    return !empty($shown);
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

$state_upper = array();
foreach (kop_state_us_states() as $s) $state_upper[mb_strtoupper($s)] = $s;
$pages = array();
foreach (kop_state_us_states() as $s) $pages[] = array('state', $s);
foreach ($wpdb->get_col("SELECT DISTINCT location_key FROM wpdl_kop_facility_locations ORDER BY location_key") as $key) {
    if ($key === '' || $key === 'UNKNOWN' || isset($state_upper[$key])) continue;
    $pages[] = array('country', ucwords(strtolower($key)));
}
if ($only_pages) {
    $pages = array_values(array_filter($pages, function ($p) use ($only_pages) {
        foreach ($only_pages as $want) if (strcasecmp($want, $p[1]) === 0) return true;
        return false;
    }));
}

printf("Theme: %s\nModel: %s\nWrites: %s\nPages: %d\n\n", $theme, $legacy ? 'legacy copies' : 'v2',
    kop_v2_writes_on() ? 'v2' : 'legacy', count($pages));
printf("%-22s %6s %6s %8s %8s %8s %6s\n", 'page', 'tiles', 'alt', 'v2named', 'shown', 'missing', 'opname');

$failures = array();
$totals = array('tiles' => 0, 'alt' => 0, 'v2named' => 0, 'shown' => 0, 'missing' => 0, 'opname' => 0);
foreach ($pages as $page) {
    list($kind, $name) = $page;
    $feed = $kind === 'state' ? kop_state_collect_facilities($name) : kop_country_collect_facilities($name);
    $tiles = array_merge($feed['active'] ?? array(), $feed['closed'] ?? array());

    $tiles_by_id = array();
    $alt = 0;
    foreach ($tiles as $i => $tile) {
        if (kop_test_tile_has_alt($tile)) $alt++;
        foreach ((array) ($tile['facility_ids'] ?? array()) as $fid) $tiles_by_id[(int) $fid][] = $i;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, json_data FROM facilities_v2
          WHERE id IN (SELECT facility_id FROM wpdl_kop_facility_locations WHERE location_key = %s) ORDER BY id",
        mb_strtoupper($name)
    ), ARRAY_A);
    $v2named = 0; $shown = 0; $missing = 0;
    $own_by_id = array();
    $operator_by_id = array();
    foreach ($rows as $row) {
        $doc = json_decode($row['json_data'], true);
        if (!is_array($doc)) continue;
        $id = (int) $row['id'];
        $want = kop_test_record_names($doc);
        $own_by_id[$id] = $want;
        $operator_by_id[$id] = kop_test_names($doc['provenance']['sourceOperator']['otherNames'] ?? array());
        if (!$want) continue;
        $v2named++;
        $label = sprintf('%s #%d %s', $name, $id, $doc['identification']['name'] ?? '');
        if (empty($tiles_by_id[$id])) {
            $missing++;
            $failures[] = "$label: no tile carries this facility (wants " . implode(' | ', $want) . ')';
            continue;
        }
        $have = array();
        foreach ($tiles_by_id[$id] as $i) $have += kop_test_tile_names($tiles[$i]);
        $lost = array_diff_key($want, $have);
        if ($lost) {
            $missing++;
            $failures[] = "$label: tile lacks " . implode(' | ', $lost);
        } else {
            $shown++;
        }
    }

    // Operator names must not reach a card unless the facility itself lists them.
    $opname = 0;
    foreach ($tiles as $tile) {
        $operator = array();
        $own = array();
        foreach ((array) ($tile['raw_records'] ?? array()) as $raw) {
            foreach (kop_test_names($raw['data']['sourceOperator']['otherNames'] ?? array()) as $n) $operator[mb_strtolower($n)] = $n;
        }
        foreach ((array) ($tile['facility_ids'] ?? array()) as $fid) {
            foreach ($operator_by_id[(int) $fid] ?? array() as $n) $operator[mb_strtolower($n)] = $n;
            $own += $own_by_id[(int) $fid] ?? array();
        }
        $shown_alt = kop_test_tile_names($tile);
        unset($shown_alt[mb_strtolower(trim((string) $tile['name']))]);
        $leaked = array_diff_key(array_intersect_key($operator, $shown_alt), $own);
        if ($leaked) {
            $opname++;
            $failures[] = "$name tile {$tile['name']}: shows operator name " . implode(' | ', $leaked);
        }
    }

    printf("%-22s %6d %6d %8d %8d %8d %6d\n", $name, count($tiles), $alt, $v2named, $shown, $missing, $opname);
    foreach (array('tiles' => count($tiles), 'alt' => $alt, 'v2named' => $v2named, 'shown' => $shown, 'missing' => $missing, 'opname' => $opname) as $k => $v) {
        $totals[$k] += $v;
    }
    unset($feed, $tiles);
}
printf("%-22s %6d %6d %8d %8d %8d %6d\n\n", 'TOTAL', $totals['tiles'], $totals['alt'], $totals['v2named'], $totals['shown'], $totals['missing'], $totals['opname']);
echo "tiles: tiles on the page; alt: tiles whose card shows an alternate name;\n";
echo "v2named: facilities_v2 records on the page with an alternate name; shown: of those, all names on their tile;\n";
echo "missing: records whose names do not all reach a tile; opname: tiles showing an operator-only name.\n\n";

foreach ($failures as $f) echo "FAIL $f\n";

// ---------------------------------------------------------------------------
// Coverage gap: facilities_v2 records with no alternate name at all
// ---------------------------------------------------------------------------

if ($gap_path !== '') {
    $fh = fopen($gap_path, 'w');
    fputcsv($fh, array('id', 'name', 'city', 'state', 'country', 'status', 'facility_page'));
    $gap = 0; $all = 0; $by_place = array();
    foreach ($wpdb->get_results('SELECT id, json_data FROM facilities_v2 ORDER BY id', ARRAY_A) as $row) {
        $doc = json_decode($row['json_data'], true);
        if (!is_array($doc)) continue;
        $all++;
        if (kop_test_record_names($doc)) continue;
        $gap++;
        $loc = $doc['location'] ?? array();
        $place = (string) ($loc['state'] ?? '') !== '' ? (string) $loc['state'] : ((string) ($loc['country'] ?? '') ?: '(none)');
        $by_place[$place] = ($by_place[$place] ?? 0) + 1;
        fputcsv($fh, array(
            (int) $row['id'],
            (string) ($doc['identification']['name'] ?? ''),
            (string) ($loc['city'] ?? ''),
            (string) ($loc['state'] ?? ''),
            (string) ($loc['country'] ?? ''),
            (string) ($doc['operatingPeriod']['status'] ?? ''),
            // The stubbed home_url() is example.test; the list is read against the live site.
            str_replace(home_url(), 'https://kidsoverprofits.org', kop_facility_page_url((int) $row['id'])),
        ));
    }
    fclose($fh);
    arsort($by_place);
    printf("\nNo alternate name: %d of %d facilities_v2 records -> %s\n", $gap, $all, $gap_path);
    foreach ($by_place as $place => $n) printf("  %-16s %5d\n", $place, $n);
}

exit($failures ? 1 : 0);
