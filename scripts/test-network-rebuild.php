<?php
/**
 * Offline checks for inc/network-rebuild.php against tmp/prod.sqlite: the
 * fingerprint is stable, moves when something the map reads changes (staff,
 * past names, operators, consultants) and stays put for edits the map
 * ignores; the hourly check starts a build only when it moved, keeps the old
 * hash when GitHub refuses so the next hour retries, and says when the token
 * is missing. GitHub is never called.
 *
 *   php scripts/test-network-rebuild.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$db_path = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['opts'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['answer'] = 204;
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
function add_action() {}
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_post($url, $args) { $GLOBALS['posts'][] = array($url, $args); return array('code' => $GLOBALS['answer']); }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['code'] === 204 ? '' : '{"message":"Bad credentials"}'; }

$pdo = new PDO('sqlite:' . $db_path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
/* The map's tables, with optional edits laid over the real rows. */
$GLOBALS['edit'] = null;
$query = function ($sql) use ($pdo) {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if ($GLOBALS['edit']) {
        foreach ($rows as &$row) $row = ($GLOBALS['edit'])($sql, $row);
    }
    return $rows;
};
class Fake_WPDB {
    public $prefix = 'wpdl_';
    public $last_error = '';
    public $query;
    function get_results($sql) { return ($this->query)($sql); }
}
$wpdb = new Fake_WPDB();
$wpdb->query = $query;

require dirname(__DIR__) . '/inc/network-rebuild.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

/* Change one facility's json (the first row with a staff block). */
$target = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE json_data LIKE '%\"staff\"%' ORDER BY id LIMIT 1")->fetchColumn();
$facility_edit = function ($change) use ($target) {
    return function ($sql, $row) use ($target, $change) {
        if (strpos($sql, 'FROM facilities_v2') === false || (int) $row['id'] !== $target) return $row;
        $doc = json_decode($row['json_data'], true);
        if (isset($doc['facility'])) $change($doc['facility']); else $change($doc);
        $row['json_data'] = json_encode($doc);
        return $row;
    };
};

echo "-- fingerprint --\n";
$t = microtime(true);
$base = kop_network_rebuild_fingerprint($query, 'wpdl_');
$check('hash of the five tables', strlen($base) === 64, round(microtime(true) - $t, 1) . 's');
$check('same records, same hash', kop_network_rebuild_fingerprint($query, 'wpdl_') === $base);

$cases = array(
    'a new notable staff name moves it'  => array(true, $facility_edit(function (&$f) { $f['staff']['notableStaff'][] = 'Test Person'; })),
    'a new past name moves it'           => array(true, $facility_edit(function (&$f) { $f['identification']['pastNames'][] = 'Test Old Name'; })),
    'operating years move it'            => array(true, $facility_edit(function (&$f) { $f['operatingPeriod']['startYear'] = 1901; })),
    'a description edit does not'        => array(false, $facility_edit(function (&$f) { $f['description'] = 'unrelated edit'; })),
    'an inspection edit does not'        => array(false, $facility_edit(function (&$f) { $f['inspections'] = array('x'); })),
    'an operator record change moves it' => array(true, function ($sql, $row) {
        if (strpos($sql, 'kop_operators ') !== false && isset($row['json_data'])) $row['json_data'] .= ' ';
        return $row;
    }),
    'a consultant change moves it'       => array(true, function ($sql, $row) {
        if (strpos($sql, 'referrers_master') !== false) $row['json_data'] .= ' ';
        return $row;
    }),
);
foreach ($cases as $label => $case) {
    $GLOBALS['edit'] = $case[1];
    $moved = kop_network_rebuild_fingerprint($query, 'wpdl_') !== $base;
    $GLOBALS['edit'] = null;
    $check($label, $moved === $case[0]);
}

echo "-- hourly check --\n";
define('KOP_GITHUB_DISPATCH_TOKEN', 'test-token');
$s = kop_network_rebuild_check();
$check('first check starts a build', count($GLOBALS['posts']) === 1 && $s['built'] === $base && $s['error'] === '');
$post = $GLOBALS['posts'][0];
$check('asks for build-network-map.yml on main', strpos($post[0], '/repos/carlygaejepsen/Kids-Over-Profits/actions/workflows/build-network-map.yml/dispatches') !== false
    && json_decode($post[1]['body'], true)['ref'] === 'main');
$check('sends the token', $post[1]['headers']['Authorization'] === 'Bearer test-token');

kop_network_rebuild_check();
$check('no change, no build', count($GLOBALS['posts']) === 1);

$GLOBALS['edit'] = $cases['a new notable staff name moves it'][1];
$GLOBALS['answer'] = 401;
$s = kop_network_rebuild_check();
$check('GitHub refuses: error kept, old hash kept', count($GLOBALS['posts']) === 2 && $s['built'] === $base
    && strpos($s['error'], '401: Bad credentials') !== false, $s['error']);
$GLOBALS['answer'] = 204;
$s = kop_network_rebuild_check();
$check('next hour retries and succeeds', count($GLOBALS['posts']) === 3 && $s['built'] !== $base && $s['error'] === '');
$GLOBALS['edit'] = null;

kop_network_rebuild_check();
$check('taking the edit back is a change too', count($GLOBALS['posts']) === 4);
$s = kop_network_rebuild_check(true, 'by hand');
$check('"Rebuild now" starts one even with no change', count($GLOBALS['posts']) === 5 && $s['reason'] === 'by hand');

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
