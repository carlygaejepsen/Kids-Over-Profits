<?php
/**
 * The sitewide proper names list (inc/proper-names.php, js/proper-names.js).
 *
 *   php -d extension=mbstring scripts/test-proper-names.php
 *
 * Checks the rules (whole words, any capitals, possessives, curly
 * apostrophes, longest name first, several-word names), that kop_title_case()
 * and the other PHP case changers end with the list, that every JS case
 * changer is wrapped, and that PHP and JS give the same text (needs node).
 */

$root = dirname(__DIR__);
if (!defined('ABSPATH')) define('ABSPATH', $root . '/');
foreach (array('add_action', 'add_filter') as $fn) {
    if (!function_exists($fn)) eval("function $fn() { return true; }");
}
require $root . '/inc/proper-names.php';
require $root . '/inc/utilities.php';

$fail = 0;
function pn_check($label, $got, $want) {
    global $fail;
    if ($got === $want) {
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n";
    }
}

$list = array('NATSAP', 'WWASP', 'MacLaren', 'MacLaren Hall', "O'Brien", 'RedCliff', 'LLC', 'II');
$cases = array(
    'Natsap Member Program'            => 'NATSAP Member Program',
    'natsap'                           => 'NATSAP',
    "Natsap's members"                 => "NATSAP's members",
    'Natsapish'                        => 'Natsapish',          // not a whole word
    'Maclaren hall closed'             => 'MacLaren Hall closed',
    "Maclaren  Hall"                   => 'MacLaren Hall',      // any spacing, longest first
    'maclaren children'                => 'MacLaren children',
    "o\u{2019}brien"                   => "O'Brien",
    'Redcliff Ascent, Llc'             => 'RedCliff Ascent, LLC',
    'Phase Ii (wwasp)'                 => 'Phase II (WWASP)',
    'Iii'                              => 'Iii',                // not listed
    ''                                 => '',
);
foreach ($cases as $in => $want) {
    pn_check('apply ' . var_export($in, true), kop_proper_names_apply($in, $list), $want);
}

pn_check('clean: trims, one per name, first spelling wins, A to Z',
    kop_proper_names_clean(array(' wwasp ', 'NATSAP', 'WWASP', '', 'Mac  Laren')),
    array('Mac Laren', 'NATSAP', 'wwasp'));
pn_check('common words flagged', kop_proper_names_common_words(array('IT', 'NATSAP', 'Us', 'CO')), array('IT', 'Us', 'CO'));

// The starting list loads and the PHP case changers end with it.
$default = kop_proper_names_default();
pn_check('starting list has NATSAP', in_array('NATSAP', $default, true), true);
pn_check('starting list has no ordinary words', kop_proper_names_common_words($default), array());
pn_check('kop_title_case', kop_title_case('natsap member redcliff ascent, llc'), 'NATSAP Member RedCliff Ascent, LLC');

// Every JS case changer goes through kopProperNames().
foreach (array(
    'js/tti-program-index.js', 'js/location-index.js', 'js/inspections/wa_reports.js', 'js/inspections/ut_reports.js',
    'js/inspections/tx_reports.js', 'js/inspections/mt_reports.js', 'js/inspections/ct_reports.js',
    'js/inspections/ca-reports.js', 'js/inspections/az_reports.js', 'js/inspections/states/va.js',
    'js/inspections/states/mt.js', 'js/inspections/states/fl.js', 'js/data-form/data-wizard.js',
) as $rel) {
    pn_check("$rel wrapped", strpos((string) file_get_contents($root . '/' . $rel), 'kopProperNames(t)') !== false, true);
}

// PHP == JS on the cases and on the starting list against mixed text.
$inputs = array_keys($cases);
foreach ($default as $name) {
    $inputs[] = 'the ' . mb_strtolower($name) . ' program';
    $inputs[] = ucfirst(mb_strtolower($name)) . "'s staff";
}
$node = trim((string) shell_exec(stripos(PHP_OS, 'WIN') === 0 ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node === '') {
    echo "skip  PHP == JS (no node)\n";
} else {
    $tmp = tempnam(sys_get_temp_dir(), 'kpn');
    file_put_contents($tmp, json_encode(array('lists' => array($list, $default), 'inputs' => $inputs)));
    $js = "const apply=require(" . json_encode($root . '/js/proper-names.js') . ");"
        . "const d=JSON.parse(require('fs').readFileSync(" . json_encode($tmp) . ",'utf8'));"
        . "process.stdout.write(JSON.stringify(d.lists.map(l=>d.inputs.map(s=>apply(s,l)))));";
    $jsfile = $tmp . '.js';
    file_put_contents($jsfile, $js);
    $out = json_decode((string) shell_exec('node ' . escapeshellarg($jsfile)), true);
    @unlink($tmp); @unlink($jsfile);
    $diff = 0;
    foreach (array($list, $default) as $li => $l) {
        foreach ($inputs as $i => $s) {
            $php = kop_proper_names_apply($s, $l);
            if (($out[$li][$i] ?? null) !== $php) {
                $diff++;
                echo "      differs: " . var_export($s, true) . ' PHP ' . var_export($php, true) . ' JS ' . var_export($out[$li][$i] ?? null, true) . "\n";
            }
        }
    }
    pn_check('PHP == JS on ' . (2 * count($inputs)) . ' strings', $diff, 0);
}

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
