<?php
/**
 * Offline check of the Map Years review (inc/network-years.php).
 *
 *   php scripts/test-network-years.php
 *
 * The years forms, the accepted years reaching the map (overrides, slices),
 * the save endpoint (accept, edit, reject, undo, bad years refused, many at
 * once, admins only), and the screen itself rendered to
 * tmp/network-years/page.html, which scripts/check-network-years-page.py
 * clicks through in a browser. WordPress is stubbed; nothing is written
 * anywhere but tmp/.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_options'] = array();
$GLOBALS['kop_can'] = true;
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function get_stylesheet_directory() { return rtrim(ABSPATH, '/'); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function get_option($k, $d = false) { return $GLOBALS['kop_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '/') { return 'https://kidsoverprofits.org' . $p; }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function current_user_can() { return $GLOBALS['kop_can']; }
function check_ajax_referer() { return true; }
function wp_create_nonce() { return 'nonce'; }
function wp_unslash($v) { return $v; }
function sanitize_title($t) { return strtolower(preg_replace('/[^a-z0-9-]+/i', '-', (string) $t)); }
function wp_get_current_user() { return (object) array('user_login' => 'owner'); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
class KopJsonExit extends Exception { public $payload; public $ok; }
function wp_send_json_success($data) { $e = new KopJsonExit('json'); $e->ok = true; $e->payload = $data; throw $e; }
function wp_send_json_error($data, $code = 400) { $e = new KopJsonExit('json'); $e->ok = false; $e->payload = $data; throw $e; }
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

require ABSPATH . 'inc/network-map.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
}

// --- The candidates ---------------------------------------------------------

$candidates = kop_network_years_candidates();
check('the candidates file loads', count($candidates) > 0, count($candidates) . ' names');
$graph = kop_network_map_graph();
$ids = array();
foreach ($graph['nodes'] as $n) $ids[$n['id']] = $n;
$unknown = array_diff(array_keys($candidates), array_keys($ids));
check('every candidate is a name on the map', !$unknown, implode(', ', array_slice($unknown, 0, 5)));
$bad_quote = array();
foreach ($candidates as $id => $c) {
    $quotes = implode(' ', array_column((array) $c['sources'], 'quote'));
    foreach (array('start', 'end') as $end) {
        if (!empty($c[$end]) && strpos($quotes, (string) $c[$end]) === false) $bad_quote[] = $c['name'] . ' ' . $c[$end];
    }
}
check('every proposed year is in one of its quotes', !$bad_quote, implode('; ', array_slice($bad_quote, 0, 5)));

// --- The four forms ---------------------------------------------------------

check('both years', kop_network_years_format(1971, 2004) === '1971-2004');
check('opening only', kop_network_years_format(1971, 0) === 'from 1971');
check('closing only', kop_network_years_format(0, 2004) === 'until 2004');
check('one year', kop_network_years_format(1998, 1998) === '1998');
check('closing before opening is refused', kop_network_years_format(2004, 1971) === '');

// --- Saving -----------------------------------------------------------------

function kop_test_save(array $items) {
    $_POST = array('items' => json_encode($items), 'nonce' => 'nonce');
    try {
        kop_network_years_ajax();
    } catch (KopJsonExit $e) {
        return array($e->ok, $e->payload);
    }
    return array(null, null);
}

$first = array_keys($candidates)[0];
$second = array_keys($candidates)[1] ?? $first;
list($ok, $payload) = kop_test_save(array(array('id' => $first, 'decision' => 'accept', 'start' => 1971, 'end' => 2004)));
check('an accept saves', $ok && ($payload['saved'][$first]['years'] ?? '') === '1971-2004');
check('an accepted year reaches the map', (kop_network_map_year_overrides_fresh()[$first] ?? '') === '1971-2004');
list($ok, $payload) = kop_test_save(array(array('id' => $second, 'decision' => 'accept', 'start' => 2010, 'end' => 1990)));
check('a closing year before the opening one is refused, with a reason', $ok && !empty($payload['saved'][$second]['error']));
list($ok, $payload) = kop_test_save(array(array('id' => $second, 'decision' => 'accept', 'start' => 3000)));
check('a year in the future is refused', $ok && !empty($payload['saved'][$second]['error']));
list($ok, $payload) = kop_test_save(array(array('id' => $second, 'decision' => 'reject')));
check('a reject saves and adds no years', $ok && $payload['saved'][$second]['decision'] === 'rejected' && !isset(kop_network_map_year_overrides_fresh()[$second]));
list($ok, $payload) = kop_test_save(array(array('id' => $first, 'decision' => 'undo'), array('id' => $second, 'decision' => 'undo')));
check('undo takes both back', $ok && !kop_network_years_decisions());
list($ok) = kop_test_save(array(array('id' => 'not-a-name', 'decision' => 'accept', 'start' => 1990)));
check('a name that is not a candidate is ignored', $ok && !kop_network_years_decisions());
$many = array();
foreach (array_slice(array_keys($candidates), 0, 5) as $id) $many[] = array('id' => $id, 'decision' => 'accept', 'start' => 1990);
list($ok, $payload) = kop_test_save($many);
check('many at once (accept all)', $ok && count($payload['saved']) === count($many) && count(kop_network_map_year_overrides_fresh()) === count($many));
$GLOBALS['kop_can'] = false;
list($ok) = kop_test_save(array(array('id' => $first, 'decision' => 'reject')));
check('only an administrator can save', $ok === false);
$GLOBALS['kop_can'] = true;

// The slices carry the accepted years too.
$nodes = kop_network_map_apply_year_overrides(array(array('id' => $first, 'years' => '')), kop_network_map_year_overrides_fresh());
check('a slice node takes the accepted years', $nodes[0]['years'] === 'from 1990');

/** The overrides without the per-request memo, as the next request sees them. */
function kop_network_map_year_overrides_fresh() {
    $out = array();
    foreach (kop_network_years_decisions() as $id => $d) {
        if (($d['decision'] ?? '') === 'accepted' && !empty($d['years'])) $out[$id] = $d['years'];
    }
    return $out;
}

// --- The screen -------------------------------------------------------------

$GLOBALS['kop_options'] = array();
ob_start();
kop_network_years_page();
$html = ob_get_clean();
$dir = ABSPATH . 'tmp/network-years';
if (!is_dir($dir)) mkdir($dir, 0777, true);
file_put_contents($dir . '/page.html', '<!doctype html><html><head><meta charset="utf-8"><title>Map years</title></head><body>' . $html . '</body></html>');
check('the screen renders every candidate into its data', substr_count($html, '"id":') >= count($candidates), $dir . '/page.html');

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
