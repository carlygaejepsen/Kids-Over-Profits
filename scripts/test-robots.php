<?php
/**
 * Offline test for inc/robots.php: every Disallow line lands inside the
 * "User-agent: *" group, where crawlers read it.
 *
 *   php scripts/test-robots.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');
function add_filter($tag, $fn, $priority = 10, $args = 1) {
    $GLOBALS['kop_test_filters'][$tag][] = array($fn, $priority);
}
require ABSPATH . 'inc/staging-links.php';
require ABSPATH . 'inc/robots.php';

$failures = 0;
function ok($label, $cond) {
    global $failures;
    echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$cond) $failures++;
}

/** The lines of the "User-agent: *" group, up to the next User-agent line. */
function star_group($txt) {
    $out = array();
    $in = false;
    foreach (explode("\n", $txt) as $line) {
        if (preg_match('/^user-agent\s*:\s*(.*)$/i', trim($line), $m)) {
            $in = trim($m[1]) === '*';
            continue;
        }
        if ($in) $out[] = trim($line);
    }
    return $out;
}

// What the live site served on 2026-10-12: our lines above Yoast's block, outside every group.
$yoast = "# START YOAST BLOCK\n# ---------------------------\nUser-agent: *\nDisallow:\n\n"
    . "Sitemap: https://kidsoverprofits.org/sitemap_index.xml\n# ---------------------------\n# END YOAST BLOCK";
$live = "Disallow: /go/\n\nDisallow: /staging/\n\n" . $yoast;
$out = kop_robots_txt($live);
$g = star_group($out);
foreach (array('/go/', '/staging/', '/tti-data-submission') as $p) {
    ok("live file: Disallow: $p is in the * group", in_array('Disallow: ' . $p, $g, true));
}
ok('live file: the empty Disallow is gone', !in_array('Disallow:', $g, true));
ok('live file: nothing sits before the first User-agent but comments',
    !preg_match('/\A(?:\s*#[^\n]*\n|\s*\n)*\s*disallow/i', $out));
ok('live file: the sitemap line stays', strpos($out, 'Sitemap: https://kidsoverprofits.org/sitemap_index.xml') !== false);
ok('running twice changes nothing', kop_robots_txt($out) === $out);
ok('no line twice', count($g) === count(array_unique(array_filter($g))) + count(array_filter($g, function ($l) { return $l === ''; })));

// WordPress's own file, no Yoast, after the staging filter.
$wp = kop_robots_txt(kop_staging_robots("User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n"));
$g = star_group($wp);
ok('stock file: wp-admin rule kept', in_array('Disallow: /wp-admin/', $g, true));
ok('stock file: data form disallowed', in_array('Disallow: /tti-data-submission', $g, true));
ok('stock file: staging still disallowed', in_array('Disallow: /staging/', $g, true));

ok('an empty file gets a group', array_values(array_filter(star_group(kop_robots_txt('')))) === array('Disallow: /tti-data-submission'));

$hooked = array();
foreach ($GLOBALS['kop_test_filters']['robots_txt'] as $f) $hooked[$f[0]] = $f[1];
ok('runs after Yoast (99999) and the other robots filters', ($hooked['kop_robots_txt'] ?? 0) > 99999);

echo $failures ? "\n$failures FAILURES\n" : "\nrobots: PASS\n";
exit($failures ? 1 : 0);
