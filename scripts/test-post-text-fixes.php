<?php
/**
 * Offline check for the page and post entries of seeds/text-fixes.json
 * (kop_apply_text_fixes() and kop_text_fix_swaps() in inc/admin.php).
 *
 *   php -d extension=pdo_sqlite scripts/test-post-text-fixes.php [--db=tmp/prod.sqlite]
 *
 * Runs every entry's swaps on the live text of its page in the SQLite mirror:
 * each must apply whole, and applying it again must find nothing (so a
 * second run never changes the page). Then checks the all-or-none rule on a
 * made-up page.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
define('ABSPATH', dirname(__DIR__) . '/');
function add_action() {}

require dirname(__DIR__) . '/inc/admin.php';

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}

$db = dirname(__DIR__) . '/tmp/prod.sqlite';
foreach ($argv as $a) {
    if (strpos($a, '--db=') === 0) {
        $db = substr($a, 5);
    }
}
$entries = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/text-fixes.json'), true);
check(is_array($entries), 'seeds/text-fixes.json parses');
$posts = array_values(array_filter((array) $entries, function ($e) { return !empty($e['post']); }));

echo "Entries on the mirror's pages\n";
if (!is_readable($db)) {
    echo "  (no mirror at $db; skipped)\n";
} else {
    $pdo = new PDO('sqlite:' . $db);
    $q = $pdo->prepare("SELECT post_content FROM wpdl_posts WHERE post_name = ? AND post_type IN ('page','post') AND post_status = 'publish' ORDER BY ID DESC LIMIT 1");
    $ids = array();
    foreach ($posts as $e) {
        check(!empty($e['fix']) && !isset($ids[$e['fix']]), $e['post'] . ': has its own fix id');
        $ids[$e['fix'] ?? ''] = true;
        $q->execute(array($e['post']));
        $content = $q->fetchColumn();
        if ($content === false) {
            check(false, $e['post'] . ': page found in the mirror');
            continue;
        }
        $new = kop_text_fix_swaps($content, $e['replace']);
        check($new !== null && $new !== str_replace(array("\r\n", "\r"), "\n", $content), $e['post'] . ' (' . $e['fix'] . '): every swap applies');
        if ($new !== null) {
            check(kop_text_fix_swaps($new, $e['replace']) === null, $e['post'] . ': a second run finds nothing to change');
            foreach ($e['replace'] as $pair) {
                if ($pair[1] !== '') {
                    check(strpos($new, $pair[1]) !== false, $e['post'] . ': new text is on the page (' . mb_substr(strip_tags($pair[1]), 0, 50) . ')');
                }
            }
            $seed = dirname(__DIR__) . '/seeds/history/' . $e['post'] . '.html';
            if (is_readable($seed)) {
                check(kop_text_fix_swaps((string) file_get_contents($seed), $e['replace']) === null, $e['post'] . ': the repo seed copy already has the fix');
            }
        }
    }
}

echo "All or none\n";
$page = "a one b two c";
check(kop_text_fix_swaps($page, array(array('one', '1'), array('two', '2'))) === "a 1 b 2 c", 'swaps in order');
check(kop_text_fix_swaps($page, array(array('one', '1'), array('three', '3'))) === null, 'one phrase missing: nothing changes');
check(kop_text_fix_swaps("x x", array(array('x', 'y'))) === null, 'a phrase found twice: nothing changes');
check(kop_text_fix_swaps("a\r\nb", array(array("a\nb", 'c'))) === 'c', 'line endings do not matter');
check(kop_text_fix_swaps($page, array()) === null, 'no swaps: nothing to do');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
