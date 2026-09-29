<?php
/**
 * Build the /open-data/ downloads now (inc/open-data.php), instead of waiting
 * for the daily WP-Cron run. On the server, from the theme folder:
 *
 *   /opt/cpanel/ea-php82/root/usr/bin/php scripts/build-open-data.php [--no-fulltext]
 *
 * Bare `php` under cPanel is php-cgi and stops at the CLI guard below. The
 * datasets and ZIP take under a minute; the inspection full text a few
 * minutes more, in one go here rather than a slice per cron request.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "No wp-load.php at $wp_load; run this from the theme inside a WordPress install.\n");
    exit(2);
}
require $wp_load;

$args = getopt('', array('no-fulltext'));

$t = microtime(true);
$manifest = kop_open_data_build();
if (is_wp_error($manifest)) {
    fwrite(STDERR, $manifest->get_error_message() . "\n");
    exit(1);
}
foreach ($manifest['datasets'] as $key => $d) {
    printf("%-22s %7d rows\n", $key, $d['rows']);
}
foreach ($manifest['errors'] as $key => $error) {
    printf("FAILED %s: %s\n", $key, $error);
}
printf("datasets built in %.1fs\n", microtime(true) - $t);

if (!isset($args['no-fulltext'])) {
    $t = microtime(true);
    kop_open_data_build_fulltext(0);
    $manifest = kop_open_data_manifest();
    printf("full text: %d reports, %s, in %.1fs\n",
        (int) ($manifest['full_text']['rows'] ?? 0),
        kop_open_data_size($manifest['full_text']['bytes'] ?? 0),
        microtime(true) - $t);
}

echo 'published at ' . kop_open_data_dir()['url'] . "/\n";
exit($manifest['errors'] ? 1 : 0);
