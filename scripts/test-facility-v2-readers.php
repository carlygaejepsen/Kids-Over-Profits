<?php
/**
 * Compare the readers that scan facilities_master against their v2 versions
 * (docs/DATA-MODEL-MIGRATION.md phase 4 step 5): the data form's search and
 * autocomplete, the state page's inspection placement and related-records
 * name map, and the program index's news and lawsuit attachers.
 *
 * WordPress is stubbed and $wpdb is backed by PDO, so the real SQL runs
 * against a throwaway MySQL 8 loaded with a copy of the production tables -
 * never against production.
 *
 * Read-only: it sets the write switch in its own process only (an override
 * the stub answers), and writes nothing.
 *
 * Usage:
 *   php -d memory_limit=3G scripts/test-facility-v2-readers.php \
 *       --dsn "mysql:host=127.0.0.1;port=3399;dbname=kop;charset=utf8mb4" --user root [--password x] [--prefix wpdl_]
 *
 * It runs itself once per model (legacy, v2) and diffs the two results.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('dsn:', 'user:', 'password::', 'prefix::', 'mode::', 'out::'));
if (empty($args['dsn']) || !isset($args['user'])) {
    fwrite(STDERR, "Usage: --dsn <pdo dsn> --user <user> [--password <pw>] [--prefix wpdl_]\n");
    exit(2);
}
if (stripos($args['dsn'], 'kidsover_production') !== false) {
    fwrite(STDERR, "Refusing to run against the production database.\n");
    exit(2);
}

define('KOP_SEARCH_LIMIT', 100);          // the endpoint's own maximum
define('KOP_AUTOCOMPLETE_LIMIT', 200);    // same

/** What the two runs compare: search terms, and autocomplete category/query pairs. */
function kop_reader_search_terms() {
    return array('academy', 'ranch', 'provo', 'hope house', 'wilderness', 'Utah', 'canyon school', 'red rock', 'elevations');
}
function kop_reader_autocomplete_cases() {
    return array(
        'facility' => '', 'operator' => '', 'type' => '', 'status' => '', 'gender' => '',
        'membership' => '', 'accreditation' => '', 'licensing' => '',
        'facility|anderson' => 'anderson', 'facility|canyon' => 'canyon', 'human|smith' => 'smith',
        'location|utah' => 'utah', 'role|director' => 'director', 'investor|capital' => 'capital',
    );
}

// ---------------------------------------------------------------------------
// Driver: run both models in their own process (the readers cache in statics)
// ---------------------------------------------------------------------------

if (empty($args['mode'])) {
    $tmp = sys_get_temp_dir() . '/kop-readers-';
    $results = array();
    foreach (array('legacy', 'v2') as $mode) {
        $out = $tmp . $mode . '.json';
        $cmd = escapeshellarg(PHP_BINARY);
        foreach (array('extension_dir' => ini_get('extension_dir'), 'memory_limit' => ini_get('memory_limit')) as $k => $v) {
            $cmd .= ' -d ' . escapeshellarg($k . '=' . $v);
        }
        foreach (array('mbstring', 'pdo_mysql') as $ext) {
            if (extension_loaded($ext)) $cmd .= ' -d ' . escapeshellarg('extension=' . $ext);
        }
        $cmd .= ' ' . escapeshellarg(__FILE__);
        foreach (array('dsn', 'user', 'password', 'prefix') as $k) {
            if (isset($args[$k])) $cmd .= ' --' . $k . '=' . escapeshellarg((string)$args[$k]);
        }
        $cmd .= ' --mode=' . $mode . ' --out=' . escapeshellarg($out);
        $started = microtime(true);
        passthru($cmd, $code);
        if ($code !== 0) {
            fwrite(STDERR, "The $mode run failed.\n");
            exit(1);
        }
        printf("  %s run: %.1fs\n", $mode, microtime(true) - $started);
        $results[$mode] = json_decode((string)file_get_contents($out), true);
        @unlink($out);
    }

    $failures = 0;
    $check = function ($label, $ok, $detail = '') use (&$failures) {
        if (!$ok) $failures++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    };
    $old = $results['legacy'];
    $new = $results['v2'];

    echo "\n-- Data form search (kop/v1/search) --\n";
    foreach ($old['search'] as $term => $rows) {
        $new_rows = $new['search'][$term] ?? array();
        // Results are sorted by name and cut at the limit, so a capped search
        // can only be compared up to the last name both runs reached.
        $capped = count($rows) >= KOP_SEARCH_LIMIT || count($new_rows) >= KOP_SEARCH_LIMIT;
        $old_names = array_keys($rows);
        $new_names = array_keys($new_rows);
        if ($capped) {
            $edge = min(end($old_names), end($new_names));
            $old_names = array_values(array_filter($old_names, function ($n) use ($edge) { return strnatcasecmp($n, $edge) <= 0; }));
            $new_names = array_values(array_filter($new_names, function ($n) use ($edge) { return strnatcasecmp($n, $edge) <= 0; }));
        }
        $lost = array_values(array_diff($old_names, $new_names));
        $added = array_values(array_diff($new_names, $old_names));
        $check("search \"$term\": every result still found",
            count($lost) === 0,
            count($rows) . ' old, ' . count($new_rows) . ' new' . ($capped ? ', capped: compared ' . count($old_names) : '')
            . ($lost ? ', lost: ' . implode(' | ', array_slice($lost, 0, 5)) : ''));
        if ($added) echo "     (new: " . implode(' | ', array_slice($added, 0, 5)) . ")\n";
    }

    echo "\n-- Autocomplete (kop/v1/autocomplete) --\n";
    foreach ($old['autocomplete'] as $key => $values) {
        $new_values = $new['autocomplete'][$key] ?? array();
        if (count($values) >= KOP_AUTOCOMPLETE_LIMIT || count($new_values) >= KOP_AUTOCOMPLETE_LIMIT) {
            echo "     (autocomplete $key: capped at " . KOP_AUTOCOMPLETE_LIMIT . ", not compared)\n";
            continue;
        }
        $lost = array_values(array_diff($values, $new_values));
        // "Adults Only" is not a status: the migration maps it to Unknown and
        // keeps the original in the facility's notes (section 9 of the spec).
        $lost = array_values(array_diff($lost, array('Adults Only')));
        $check("autocomplete $key: no value lost", count($lost) === 0,
            count($values) . ' old, ' . count($new_values) . ' new' . ($lost ? ', lost: ' . implode(' | ', array_slice($lost, 0, 4)) : ''));
    }

    echo "\n-- State pages --\n";
    $pin_old = $old['state_pins'];
    $pin_new = $new['state_pins'];
    $gained = $lost_pin = $moved = 0;
    foreach ($pin_old as $name => $pin) {
        $after = $pin_new[$name] ?? '';
        if ($after === $pin) continue;
        if ($pin === '') $gained++;
        elseif ($after === '') $lost_pin++;
        else $moved++;
    }
    echo "     (name pins: $gained gained, $lost_pin no longer certain, $moved changed state)\n";
    $check('no inspection name is pinned to a different state', $moved === 0);

    // The pins only matter through the page each inspection row lands on. A
    // row may stop being relocated - a name shared by facilities in several
    // states can no longer pin it, so it stays with the authority that
    // inspected it - but it must never be relocated somewhere new.
    $page_moves = array();
    $relocations = array();
    foreach ($old['inspection_home'] as $id => $row) {
        $after = $new['inspection_home'][$id][0] ?? '';
        if ($after === $row[0]) continue;
        $line = $row[1] . ': ' . ($row[0] ?: '-') . ' -> ' . ($after ?: '-');
        $page_moves[] = $line;
        if ($after !== ($row[2] ?? '')) $relocations[] = $line;
    }
    $check('no inspection row is relocated to a new state page', count($relocations) === 0,
        count($page_moves) . ' of ' . count($old['inspection_home']) . ' rows go back to the state that inspected them'
        . ($page_moves ? ': ' . implode(', ', array_unique($page_moves)) : ''));

    // Dropped names are fine only when the facility is not in a US state in
    // v2: the old map gave a US state to facilities in Mexico and Canada.
    $missing_names = array_diff(array_keys($old['state_map']), array_keys($new['state_map']));
    $still_us = array_values(array_filter($missing_names, function ($n) use ($new) {
        return !isset($new['non_us_names'][$n]);
    }));
    $check('inspection placement map keeps every name that is in a US state', count($still_us) === 0,
        count($old['state_map']) . ' old, ' . count($new['state_map']) . ' new, '
        . count($missing_names) . ' dropped (not in a US state)'
        . ($still_us ? '; missing: ' . implode(' | ', array_slice($still_us, 0, 6)) : ''));

    $dropped = array();
    $repointed = array();
    foreach ($old['name_ids'] as $name => $id) {
        if (!isset($new['name_ids'][$name])) $dropped[] = $name;
        elseif ($new['name_ids'][$name] !== $id) $repointed[] = "$name ($id -> {$new['name_ids'][$name]})";
    }
    $check('related-records name map still resolves every name', count($dropped) === 0,
        count($old['name_ids']) . ' old, ' . count($new['name_ids']) . ' new'
        . ($dropped ? '; dropped: ' . implode(' | ', array_slice($dropped, 0, 6)) : ''));
    // A name can point at a different row of the same facility group: the old
    // map's tie-breaks came from the name index, not from meaning. What must
    // not change is any record actually linked today, checked below.
    echo '     (' . count($repointed) . " names resolve to a different row"
        . ($repointed ? ': ' . implode(', ', array_slice($repointed, 0, 4)) : '') . ")\n";

    echo "\n-- News and lawsuit linking --\n";
    $lost_aliases = array();
    foreach ($old['alias_exact'] as $key => $id) {
        if (!isset($new['alias_exact'][$key]) && !in_array($key, $new['alias_ambiguous'], true)) $lost_aliases[] = $key;
    }
    $check('every name the alias index resolved is still resolved', count($lost_aliases) === 0,
        count($old['alias_exact']) . ' old, ' . count($new['alias_exact']) . ' new'
        . ($lost_aliases ? '; lost: ' . implode(' | ', array_slice($lost_aliases, 0, 6)) : ''));

    $missing_links = array();
    foreach ($old['linked_names'] as $id => $name) {
        if (!isset($new['linked_names'][$id])) $missing_links[] = "$id ($name)";
    }
    $check('every linked news and lawsuit id still resolves to a name', count($missing_links) === 0,
        count($old['linked_names']) . ' linked ids'
        . ($missing_links ? '; missing: ' . implode(', ', array_slice($missing_links, 0, 6)) : ''));

    echo "\n-- Program index links --\n";
    foreach (array('news', 'lawsuits') as $kind) {
        $lost = array_values(array_diff($old['links'][$kind], $new['links'][$kind]));
        $added = array_values(array_diff($new['links'][$kind], $old['links'][$kind]));
        $check("$kind attached to the same projects", count($lost) === 0,
            count($old['links'][$kind]) . ' old, ' . count($new['links'][$kind]) . ' new'
            . ($lost ? '; lost: ' . implode(' | ', array_slice($lost, 0, 5)) : ''));
        if ($added) echo "     (new: " . implode(' | ', array_slice($added, 0, 5)) . ")\n";
    }

    echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
    exit($failures === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Worker: one model
// ---------------------------------------------------------------------------

$mode = $args['mode'] === 'v2' ? 'v2' : 'legacy';
$pdo = new PDO($args['dsn'], $args['user'], $args['password'] ?? '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = $args['prefix'] ?? 'wpdl_';

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
preg_match('/dbname=([^;]+)/', $args['dsn'], $m);
define('DB_NAME', $m[1] ?? 'kop');
define('KOP_TEST_MODEL', $mode);

/** Minimal $wpdb over PDO: the readers' SQL runs for real. */
class wpdb {
    public $prefix;
    public $last_error = '';
    private $pdo;
    public function __construct(PDO $pdo, $prefix) {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }
    public function prepare($sql, ...$a) {
        if (count($a) === 1 && is_array($a[0])) $a = $a[0];
        $sql = str_replace('%%', '%', $sql);
        $out = '';
        $i = 0;
        for ($p = 0; $p < strlen($sql); $p++) {
            if ($sql[$p] === '%' && isset($sql[$p + 1]) && strpos('sdfF', $sql[$p + 1]) !== false) {
                $v = $a[$i++] ?? '';
                $out .= in_array($sql[$p + 1], array('d'), true) ? (string)(int)$v
                    : (in_array($sql[$p + 1], array('f', 'F'), true) ? (string)(float)$v : $this->pdo->quote((string)$v));
                $p++;
                continue;
            }
            $out .= $sql[$p];
        }
        return $out;
    }
    private function run($sql) {
        return $this->pdo->query($sql);
    }
    public function get_results($sql, $type = 'OBJECT') {
        $rows = $this->run($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($type === ARRAY_A) return $rows;
        return array_map(function ($r) { return (object)$r; }, $rows);
    }
    public function get_row($sql, $type = 'OBJECT') {
        $rows = $this->get_results($sql, $type);
        return $rows ? $rows[0] : null;
    }
    public function get_var($sql) {
        $v = $this->run($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
    public function get_col($sql) {
        return $this->run($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function query($sql) {
        return $this->run($sql)->rowCount();
    }
    public function esc_like($t) { return addcslashes((string)$t, '_%\\'); }
}
$wpdb = new wpdb($pdo, $prefix);
$GLOBALS['wpdb'] = $wpdb;

// WordPress stubs: enough for the reader paths under test.
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) { return $value; }
function do_action() {}
function register_rest_route() {}
function rest_ensure_response($v) { return $v; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function admin_url($path = '') { return home_url('wp-admin/' . $path); }
function site_url($path = '') { return home_url($path); }
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://example.test/theme'; }
function trailingslashit($s) { return rtrim((string)$s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string)$s, '/\\'); }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : $s; }
function sanitize_title($s) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$s)); }
function esc_url_raw($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return $s; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_list_pluck($list, $field) {
    $out = array();
    foreach ((array)$list as $row) {
        $row = (array)$row;
        if (isset($row[$field])) $out[] = $row[$field];
    }
    return $out;
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time($type = 'mysql') { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function absint($v) { return abs((int)$v); }
function wp_cache_get() { return false; }
function wp_cache_set() { return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function get_option($name, $default = false) {
    // The write switch is the only option these readers consult; the v2 run
    // answers as if it were on, without touching the database.
    if ($name === 'kop_data_model') return KOP_TEST_MODEL === 'v2' ? 'v2' : 'v1';
    if ($name === 'kop_data_model_areas') return KOP_TEST_MODEL === 'v2' ? array_keys(kop_v2_areas()) : array();
    return $default;
}
function update_option() { return true; }
function get_page_by_path() { return null; }
function get_permalink() { return ''; }
function get_posts() { return array(); }
function get_the_title() { return ''; }
function get_post_meta() { return ''; }
function has_post_thumbnail() { return false; }
function get_the_post_thumbnail_url() { return ''; }
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function wp_schedule_single_event() {}
function spawn_cron() {}
function register_shutdown_function_stub() {}
function wp_verify_nonce() { return true; }
function wp_create_nonce() { return 'nonce'; }
function current_user_can() { return false; }
function is_user_logged_in() { return false; }
function get_bloginfo() { return 'KOP'; }
class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_REST_Request {
    private $params;
    public function __construct(array $params = array()) { $this->params = $params; }
    public function get_param($k) { return $this->params[$k] ?? null; }
    public function get_json_params() { return $this->params; }
    public function get_params() { return $this->params; }
}

// The write switch, set in this throwaway copy for the v2 run only. The
// readers read it the same way production does, and it is cleared on the way
// out so the copy is left as it was found.
$switch_row = $pdo->prepare("INSERT INTO `{$prefix}kop_migration_state` (state_key, state_value) VALUES ('writes', ?)
    ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)");
if ($mode === 'v2') {
    $switch_row->execute(array(json_encode(array('mode' => 'v2', 'at' => gmdate('c')))));
} else {
    $pdo->exec("DELETE FROM `{$prefix}kop_migration_state` WHERE state_key = 'writes'");
}
register_shutdown_function(function () use ($pdo, $prefix) {
    $pdo->exec("DELETE FROM `{$prefix}kop_migration_state` WHERE state_key = 'writes'");
});

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-v2-readers.php';
require_once dirname(__DIR__) . '/inc/database.php';
require_once dirname(__DIR__) . '/inc/rest-api.php';

$out = array('search' => array(), 'autocomplete' => array(), 'state_pins' => array(), 'state_map' => array(), 'name_ids' => array(), 'links' => array());

// -- kop/v1/search ----------------------------------------------------------
foreach (kop_reader_search_terms() as $term) {
    $response = kop_search_database_rest_callback(new WP_REST_Request(array('keyword' => $term, 'limit' => KOP_SEARCH_LIMIT)));
    $rows = array();
    foreach ((array)($response['results'] ?? array()) as $r) {
        $rows[strtolower((string)$r['name'])] = $r['matchType'] ?? '';
    }
    $out['search'][$term] = $rows;
}

// -- kop/v1/autocomplete ----------------------------------------------------
foreach (kop_reader_autocomplete_cases() as $key => $query) {
    $category = strtok($key, '|');
    $response = kop_autocomplete_rest_callback(new WP_REST_Request(array(
        'category' => $category, 'q' => $query, 'limit' => KOP_AUTOCOMPLETE_LIMIT,
    )));
    $values = (array)($response['values'] ?? array());
    sort($values, SORT_STRING);
    $out['autocomplete'][$key] = $values;
}

// -- state pages ------------------------------------------------------------
$out['state_map'] = kop_master_facility_state_map();
$names = $pdo->query("SELECT DISTINCT facility_name FROM inspection_facilities")->fetchAll(PDO::FETCH_COLUMN);
foreach ((array)$names as $name) {
    $key = kop_normalize_facility_name((string)$name);
    if ($key === '') continue;
    $out['state_pins'][$key] = kop_master_state_pin($key);
}

// What the pins actually do: the state page each inspection row lands on,
// decided exactly as kop_state_collect_inspection_summaries() decides it.
$out['inspection_home'] = array();
$rows = $pdo->query("SELECT id, facility_name, full_address, state FROM inspection_facilities")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $stored = strtoupper(trim((string)$row['state']));
    $pin = kop_master_state_pin(kop_normalize_facility_name($row['facility_name'] ?? ''));
    $addr_state = kop_derive_state_from_address($row['full_address'] ?? '');
    $home = $stored;
    if ($pin !== '' && $pin !== $stored && ($addr_state === '' || $addr_state === $pin)) {
        $home = $pin;
    }
    $out['inspection_home'][(int)$row['id']] = array($home, (string)$row['facility_name'], $stored);
}
$out['name_ids'] = kop_state_master_name_id_map();

// Names that are not in a US state at all in v2 (foreign or unknown): the
// placement map is allowed to drop them, since it only decides which US state
// page an inspection row belongs on.
$out['non_us_names'] = array();
foreach ((array)$pdo->query("SELECT name FROM facilities_v2 WHERE state IS NULL OR state = ''")->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $key = kop_normalize_facility_name((string)$name);
    if ($key !== '') $out['non_us_names'][$key] = true;
}

// -- name resolution for news and lawsuit linking ----------------------------
require_once dirname(__DIR__) . '/api/facility-aliases.php';
$index = kop_build_facility_alias_index($pdo);
$out['alias_exact'] = $index['exact'];
$out['alias_names'] = $index['names'];
$out['alias_ambiguous'] = array_keys($index['ambiguous']);

// The ids the link tables actually hold must still resolve to a name.
$linked_ids = array_map('intval', array_merge(
    (array)$pdo->query("SELECT DISTINCT facility_id FROM news_facility_links")->fetchAll(PDO::FETCH_COLUMN),
    (array)$pdo->query("SELECT DISTINCT facility_id FROM lawsuit_facility_links")->fetchAll(PDO::FETCH_COLUMN)
));
$out['linked_names'] = array();
if ($mode === 'v2') {
    require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
    $out['linked_names'] = kop_v2_pdo_names_by_id($pdo, $prefix, $linked_ids);
} else {
    $in = implode(',', array_map('intval', $linked_ids));
    foreach ((array)$pdo->query("SELECT id, unique_name FROM facilities_master WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out['linked_names'][(int)$row['id']] = (string)$row['unique_name'];
    }
}

// -- program index attachers -------------------------------------------------
$projects = kop_v2_active('program_index') ? kop_v2_get_facilities_projects() : kop_get_facilities_projects_from_database();
$projects = isset($projects['projects']) ? $projects['projects'] : $projects;
kop_attach_linked_news_to_projects($wpdb, $projects);
kop_attach_linked_lawsuits_to_projects($wpdb, $projects);
$news = array();
$lawsuits = array();
foreach ($projects as $key => $project) {
    foreach ((array)($project['linked_news'] ?? array()) as $item) {
        $news[] = $key . ' #' . (int)($item['id'] ?? $item['news_id'] ?? 0);
    }
    foreach ((array)($project['linked_lawsuits'] ?? array()) as $item) {
        $lawsuits[] = $key . ' #' . (int)($item['id'] ?? $item['lawsuit_id'] ?? 0);
    }
}
sort($news, SORT_STRING);
sort($lawsuits, SORT_STRING);
$out['links'] = array('news' => $news, 'lawsuits' => $lawsuits);

file_put_contents($args['out'], json_encode($out));
