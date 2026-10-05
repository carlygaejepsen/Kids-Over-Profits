<?php
/**
 * WordPress stubs and a writable $wpdb over an in-memory SQLite database for
 * the glossary tests (scripts/test-glossary.php, scripts/test-glossary-store.php).
 * The MySQL CREATE TABLEs in inc/glossary-store.php are rewritten for SQLite,
 * so the real install runs. Nothing touches a file or the live site.
 *
 * Define the page-specific stubs (kop_facility_page_url_for_name, ...) before
 * requiring this file. It loads inc/glossary.php and inc/glossary-editor.php.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

$GLOBALS['kop_test_options'] = array();
$GLOBALS['kop_test_user'] = 'tester';

function get_stylesheet_directory() { return dirname(__DIR__); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function add_option($k, $v = '', $dep = '', $autoload = 'yes') {
    if (array_key_exists($k, $GLOBALS['kop_test_options'])) return false;
    $GLOBALS['kop_test_options'][$k] = $v;
    return true;
}
function delete_option($k) { unset($GLOBALS['kop_test_options'][$k]); return true; }
function add_action() {}
function do_action() {}
if (!function_exists('apply_filters')) { function apply_filters($hook, $value) { return $value; } }
function current_time($type = 'mysql') { return $type === 'Y-m-d' ? gmdate('Y-m-d') : gmdate('Y-m-d H:i:s'); }
function wp_get_current_user() { return (object) array('user_login' => $GLOBALS['kop_test_user']); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function home_url($p = '') { return 'https://kidsoverprofits.org/' . ltrim($p, '/'); }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . ltrim($p, '/'); }
function get_page_by_path() { return null; }
function get_permalink() { return 'https://kidsoverprofits.org/glossary/'; }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); } }
function check_admin_referer() { return true; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_unslash($v) { return $v; }
function wp_slash($v) { return $v; }
function wp_create_nonce() { return 'nonce'; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
if (!function_exists('rest_url')) { function rest_url($p = '') { return 'https://kidsoverprofits.org/wp-json/' . $p; } }

/** $wpdb on SQLite: MySQL DDL rewritten, writes run. */
class wpdb {
    public $prefix = 'wpdl_';
    public $insert_id = 0;
    public $last_error = '';
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = array_values($args[0]);
        $i = 0;
        $pdo = $this->pdo;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args, $pdo) {
            $v = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $v : $pdo->quote((string) $v);
        }, $query);
    }
    private function ddl($sql) {
        $sql = preg_replace('/\)\s*ENGINE=.*$/s', ')', $sql);
        $sql = preg_replace('/BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/', 'INTEGER NOT NULL', $sql);
        $sql = preg_replace('/UNIQUE KEY \w+ \(([^)]*)\)/', 'UNIQUE ($1)', $sql);
        $sql = preg_replace('/,\s*KEY \w+ \((?:[^()]|\(\d+\))*\)/', '', $sql);
        return $sql;
    }
    public function query($sql) {
        if (preg_match('/^\s*CREATE TABLE/i', $sql)) $sql = $this->ddl($sql);
        if (preg_match('/^\s*START TRANSACTION/i', $sql)) $sql = 'BEGIN';
        return $this->pdo->exec($sql);
    }
    public function get_results($sql, $output = OBJECT) {
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return $output === ARRAY_A ? $rows : array_map(function ($r) { return (object) $r; }, $rows);
    }
    public function get_row($sql, $output = OBJECT) { $r = $this->get_results($sql, $output); return $r ? $r[0] : null; }
    public function get_var($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    public function insert($table, array $data) {
        $st = $this->pdo->prepare("INSERT INTO $table (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')');
        $st->execute(array_values($data));
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }
    public function update($table, array $data, array $where) {
        $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($data)));
        $w = implode(' AND ', array_map(function ($k) { return "$k = ?"; }, array_keys($where)));
        $st = $this->pdo->prepare("UPDATE $table SET $set WHERE $w");
        $st->execute(array_merge(array_values($data), array_values($where)));
        return $st->rowCount();
    }
    public function delete($table, array $where) {
        $w = implode(' AND ', array_map(function ($k) { return "$k = ?"; }, array_keys($where)));
        $st = $this->pdo->prepare("DELETE FROM $table WHERE $w");
        $st->execute(array_values($where));
        return $st->rowCount();
    }
}

$kop_test_pdo = new PDO('sqlite::memory:');
$kop_test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['wpdb'] = new wpdb($kop_test_pdo);

require_once dirname(__DIR__) . '/inc/glossary.php';
require_once dirname(__DIR__) . '/inc/glossary-editor.php';
