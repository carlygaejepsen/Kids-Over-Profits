<?php
/**
 * Offline test of api/inspections-read.php ?lite=1 and ?text=, against the
 * SQLite mirror (tmp/prod.sqlite, scripts/sync-prod-sqlite.py).
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=4G \
 *       scripts/test-inspections-read-lite.php [--states=FL,NC,CT] [--db=tmp/prod.sqlite]
 *
 * For each state: the lite response carries every report of the full one with
 * the same fields except raw_content, plus row_id / has_text / text_signals;
 * ?text=<row_id> returns the full response's raw_content exactly; the second
 * lite request is served from the cache file and is byte-identical.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

// header() warns once output has started; keep warnings out of the captured bodies.
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
$db = $root . '/tmp/prod.sqlite';
$states = array('FL', 'NC', 'CT');
foreach ($argv as $a) {
    if (strpos($a, '--db=') === 0) $db = substr($a, 5);
    if (strpos($a, '--states=') === 0) $states = explode(',', strtoupper(substr($a, 9)));
}
if (!is_file($db)) {
    fwrite(STDERR, "No mirror at $db (run scripts/sync-prod-sqlite.py).\n");
    exit(1);
}

$cacheDir = sys_get_temp_dir() . '/kop-inspections-lite-test-' . getmypid();
$api = $root . '/api';
$source = file_get_contents($api . '/inspections-read.php');
$source = preg_replace('/^<\?php/', '', $source);
$source = str_replace("require_once __DIR__ . '/config.php';", '', $source);
$source = str_replace("dirname(__DIR__, 3) . '/uploads/kop-cache'", var_export($cacheDir, true), $source);
$source = str_replace('__DIR__', var_export($api, true), $source);
// exit inside the endpoint would end the test; return from the closure instead.
$source = preg_replace('/\bexit\s*;/', 'return;', $source);

/** Run the endpoint with $get as the query string; returns the body. */
function kop_call_endpoint($source, $db, array $get) {
    $_GET = $get;
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $run = function () use ($source, $pdo) {
        eval($source);
    };
    ob_start();
    $run();
    return ob_get_clean();
}

$fails = 0;
function check($ok, $label) {
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

foreach ($states as $state) {
    echo "$state\n";
    $fullBody = kop_call_endpoint($source, $db, array('state' => $state));
    $full = json_decode($fullBody, true);
    $t = microtime(true);
    $liteBody = kop_call_endpoint($source, $db, array('state' => $state, 'lite' => '1'));
    $firstMs = (int) ((microtime(true) - $t) * 1000);
    $lite = json_decode($liteBody, true);
    check(is_array($full) && is_array($lite), 'both responses are JSON');
    if (!is_array($full) || !is_array($lite)) continue;

    printf("  full %.1f MB, lite %.2f MB, lite built in %d ms\n", strlen($fullBody) / 1e6, strlen($liteBody) / 1e6, $firstMs);
    check(strlen($liteBody) < strlen($fullBody), 'lite is smaller');

    $fullReports = array();
    foreach ($full['facilities'] as $f) foreach ($f['reports'] as $r) $fullReports[] = $r;
    $liteReports = array();
    foreach ($lite['facilities'] as $f) foreach ($f['reports'] as $r) $liteReports[] = $r;
    check(count($fullReports) === count($liteReports), 'same number of reports (' . count($liteReports) . ')');
    check(count($full['facilities']) === count($lite['facilities']), 'same number of facilities');

    $shapeOk = true;
    $noRaw = true;
    foreach ($liteReports as $i => $r) {
        if (array_key_exists('raw_content', $r)) $noRaw = false;
        $expect = $fullReports[$i];
        unset($expect['raw_content']);
        $got = $r;
        unset($got['row_id'], $got['has_text'], $got['text_signals']);
        if ($got !== $expect || !isset($r['row_id']) || !array_key_exists('text_signals', $r)) $shapeOk = false;
    }
    check($noRaw, 'no raw_content in the lite list');
    check($shapeOk, 'every other field matches the full list, in order');

    // ?text= returns the exact text, for a sample of reports with text.
    $textOk = true;
    $sampled = 0;
    foreach ($liteReports as $i => $r) {
        if (!$r['has_text'] || $i % max(1, (int) (count($liteReports) / 25)) !== 0) continue;
        $body = json_decode(kop_call_endpoint($source, $db, array('state' => $state, 'text' => (string) $r['row_id'])), true);
        if (!is_array($body) || $body['raw_content'] !== $fullReports[$i]['raw_content']) $textOk = false;
        $sampled++;
    }
    check($textOk && $sampled > 0, "?text= returns the exact text ($sampled sampled)");
    $wrong = json_decode(kop_call_endpoint($source, $db, array('state' => ($state === 'FL' ? 'NC' : 'FL'), 'text' => (string) $liteReports[0]['row_id'])), true);
    check(is_array($wrong) && isset($wrong['error']), '?text= refuses a report from another state');

    // The second lite request comes from the cache file.
    $cached = glob($cacheDir . '/inspections-' . $state . '-lite-*.json');
    check(count($cached) === 1, 'one cache file written');
    $t = microtime(true);
    $again = kop_call_endpoint($source, $db, array('state' => $state, 'lite' => '1'));
    printf("  cached lite served in %d ms\n", (int) ((microtime(true) - $t) * 1000));
    check($again === $liteBody, 'cached response is byte-identical');
}

foreach (glob($cacheDir . '/*') ?: array() as $f) unlink($f);
@rmdir($cacheDir);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
