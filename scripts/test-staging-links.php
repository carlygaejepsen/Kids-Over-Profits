<?php
/**
 * Offline test for inc/staging-links.php, the robots.txt guard over the
 * staging copy of the site.
 *
 *   php scripts/test-staging-links.php
 *
 * Small file, small test: the line has to be added, it must not be added
 * twice (inc/facility-pages.php is on the same filter and either may run
 * first), and it has to survive whatever WordPress hands it. Nothing is
 * written and nothing is fetched. What a reader actually meets is
 * scripts/check-staging-links.py, which reads the rendered pages.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['kop_test_filters'] = array();
function add_filter($tag, $fn, $priority = 10, $args = 1) {
    $GLOBALS['kop_test_filters'][$tag][] = $fn;
}

require ABSPATH . 'inc/staging-links.php';

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, var_export($got, true), var_export($want, true));
}

function ok($label, $got) {
    global $failures;
    if ($got) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n", $label);
}

$stock = "User-agent: *\nDisallow:\n";

echo "-- robots.txt --\n";

$out = kop_staging_robots($stock);
ok('the staging path is disallowed', strpos($out, 'Disallow: /staging/') !== false);
ok('what was already there is kept', strpos($out, 'User-agent: *') === 0);

check('a file that already covers staging is untouched',
    kop_staging_robots("User-agent: *\nDisallow: /staging/\n"),
    "User-agent: *\nDisallow: /staging/\n");

/* inc/facility-pages.php adds its own line on this filter; whichever runs
 * second must not repeat the first one's work or undo it. */
$with_go = $stock . "\nDisallow: /go/\n";
$both = kop_staging_robots($with_go);
ok('the /go/ line survives', strpos($both, 'Disallow: /go/') !== false);
ok('and staging is added beside it', strpos($both, 'Disallow: /staging/') !== false);
check('running twice changes nothing the second time',
    kop_staging_robots($both), $both);

check('an empty file still gets the line',
    trim(kop_staging_robots('')), 'Disallow: /staging/');

echo "\n-- Wiring --\n";

ok('the filter is hooked', isset($GLOBALS['kop_test_filters']['robots_txt'])
    && in_array('kop_staging_robots', $GLOBALS['kop_test_filters']['robots_txt'], true));
ok('and nothing else is', count($GLOBALS['kop_test_filters']) === 1);

echo $failures ? "\n$failures FAILURES\n" : "\nstaging links: PASS\n";
exit($failures ? 1 : 0);
