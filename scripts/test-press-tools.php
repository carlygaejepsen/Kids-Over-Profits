<?php
/**
 * inc/press-tools.php offline: the citation text, the same text from
 * js/press-tools.js (needs node), and the press desk on /journalists/
 * rendered with WordPress stubbed.
 *
 *   php scripts/test-press-tools.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['kop_test_filters'] = array();
function add_action() {}
function add_filter($hook, $cb) { $GLOBALS['kop_test_filters'][$hook][] = $cb; }
function apply_filters($hook, $value) {
    foreach ($GLOBALS['kop_test_filters'][$hook] ?? array() as $cb) $value = $cb($value);
    return $value;
}
function home_url($path = '/') { return 'https://kidsoverprofits.org' . $path; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
// The hub frame's link resolver: a slug is a published page unless listed here.
$GLOBALS['kop_test_missing'] = array('marketing-companies');
function kop_hub_links($links) {
    $out = array();
    foreach ($links as $l) {
        $url = $l['url'] ?? '';
        if ($url === '' && !empty($l['slug']) && !in_array($l['slug'], $GLOBALS['kop_test_missing'], true)) {
            $url = home_url('/' . $l['slug'] . '/');
        }
        if ($url !== '') $out[] = array('label' => $l['label'], 'url' => $url, 'note' => $l['note'] ?? '');
    }
    return $out;
}

require dirname(__DIR__) . '/inc/press-tools.php';

$fail = 0;
function check($ok, $what) {
    global $fail;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $fail++;
}

// 1. The citation.
$cases = array(
    array('Provo Canyon School', 'facility', 'https://kidsoverprofits.org/facility/provo-canyon-school/', 'October 3, 2026', 'October 13, 2026'),
    array('Universal Health Services, Inc.', 'company', 'https://kidsoverprofits.org/operator/universal-health-services/', '', 'October 13, 2026'),
    array('  Trails Carolina ', 'facility', 'https://kidsoverprofits.org/facility/trails-carolina/', 'May 1, 2026', ''),
);
check(kop_press_citation($cases[0][0], $cases[0][1], $cases[0][2], $cases[0][3], $cases[0][4])
    === 'Kids Over Profits. "Provo Canyon School." Kids Over Profits facility database, last updated October 3, 2026. https://kidsoverprofits.org/facility/provo-canyon-school/. Accessed October 13, 2026.',
    'facility citation');
check(kop_press_citation($cases[1][0], $cases[1][1], $cases[1][2], $cases[1][3], $cases[1][4])
    === 'Kids Over Profits. "Universal Health Services, Inc." Kids Over Profits company database. https://kidsoverprofits.org/operator/universal-health-services/. Accessed October 13, 2026.',
    'company citation, no updated date, no doubled full stop');

// 2. PHP == JS.
$node = trim((string) shell_exec('node --version 2>&1'));
if (strpos($node, 'v') !== 0) {
    echo "skip PHP == JS (no node)\n";
} else {
    $js = dirname(__DIR__) . '/js/press-tools.js';
    $runner = 'global.window = {}; require(' . json_encode($js) . ');'
        . 'const cases = ' . json_encode($cases) . ';'
        . 'console.log(JSON.stringify(cases.map(c => window.kopPressCitation(c[0], c[1] === "company" ? "Kids Over Profits company database" : "Kids Over Profits facility database", c[3], c[2], c[4]))));';
    $tmp = tempnam(sys_get_temp_dir(), 'kop-press');
    file_put_contents($tmp, $runner);
    $out = json_decode((string) shell_exec('node ' . escapeshellarg($tmp)), true);
    unlink($tmp);
    foreach ($cases as $i => $c) {
        $php = kop_press_citation($c[0], $c[1], $c[2], $c[3], $c[4]);
        check(is_array($out) && ($out[$i] ?? null) === $php, 'PHP == JS: ' . trim($c[0]) . ($out[$i] ?? '' ? '' : ' (no JS output)'));
    }
}

// 3. The press desk.
$modules = apply_filters('kop_hub_modules', array());
check(($modules['journalists'] ?? '') === 'kop_hub_module_press_desk', '/journalists/ gets the press desk module');
$hubs = apply_filters('kop_hub_config', array('journalists' => array('standfirst' => 'old', 'reading' => 'kept')));
check(strpos($hubs['journalists']['standfirst'], 'For reporters') === 0, 'standfirst replaced');
check(($hubs['journalists']['reading'] ?? '') === 'kept', 'other settings kept');
check(count($hubs['journalists']['actions']) === 4 && !empty($hubs['journalists']['contribute']['links']), 'actions and contribute set');

ob_start();
kop_hub_module_press_desk();
$html = ob_get_clean();
$groups = kop_press_desk_groups();
check(substr_count($html, '<h2 class="kop-hub-h"') === count($groups), count($groups) . ' groups rendered');
check(strpos($html, 'Marketing companies') === false, 'a link whose page is missing is dropped');
check(strpos($html, 'href=""') === false, 'no empty links');
preg_match_all('/id="(kop-press-desk-h\d+)"/', $html, $ids);
check(count($ids[1]) === count(array_unique($ids[1])), 'heading ids unique');
foreach ($groups as $g) {
    foreach ($g['links'] as $l) {
        if (preg_match('/\b(we believe|shocking|horrific|clearly)\b/i', $l['note'])) check(false, 'plain wording: ' . $l['label']);
    }
}

$out_dir = dirname(__DIR__) . '/tmp';
if (is_dir($out_dir)) {
    file_put_contents($out_dir . '/press-desk-preview.html', '<!doctype html><meta charset="utf-8"><title>Press desk</title>' . $html);
    echo "wrote tmp/press-desk-preview.html\n";
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
