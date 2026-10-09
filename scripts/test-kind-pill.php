<?php
/**
 * The "Company" / "Facility" pill (inc/kind-pill.php): kop_kind_pill() and
 * kopKindPill() print the same markup for every kind word, and unknown kinds
 * print nothing. Needs node on PATH for the JS half.
 *
 *   php scripts/test-kind-pill.php
 */

define('ABSPATH', __DIR__ . '/');
function add_action() {}
require dirname(__DIR__) . '/inc/kind-pill.php';

$kinds = array('operator', 'company', 'companies', 'facility', 'program', 'facilities', 'Facility', ' operator ', 'place', 'consultant', '', null);
$fail = 0;
$check = function ($ok, $what) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $fail++;
};

$check(kop_kind_pill('operator') === '<span class="kop-kind kop-kind--company">Company</span>', 'operator -> Company pill');
$check(kop_kind_pill('facility') === '<span class="kop-kind kop-kind--facility">Facility</span>', 'facility -> Facility pill');
$check(kop_kind_pill('place') === '' && kop_kind_pill(null) === '', 'place / null -> nothing');
$check(strpos(kop_kind_pill_css(), '.kop-kind--company') !== false, 'CSS styles the company pill');

$php = array();
foreach ($kinds as $k) $php[] = kop_kind_pill($k);

$js = tempnam(sys_get_temp_dir(), 'kp') . '.js';
file_put_contents($js, "var window = {};\n" . kop_kind_pill_js() . "\nprocess.stdout.write(JSON.stringify("
    . json_encode($kinds) . ".map(function (k) { return window.kopKindPill(k); })));\n");
$out = shell_exec('node ' . escapeshellarg($js));
@unlink($js);
if ($out === null || $out === '') {
    $check(false, 'node ran (is node on PATH?)');
} else {
    $check(json_decode($out, true) === $php, 'PHP == JS for ' . count($kinds) . ' kind words');
}

echo $fail ? "\n{$fail} failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
