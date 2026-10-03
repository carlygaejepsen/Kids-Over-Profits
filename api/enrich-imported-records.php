<?php
/**
 * Fill incomplete submitted news rows and imported bare-link lawsuits:
 * api/lib-record-enrich.php. The hourly WP-Cron job (inc/record-enrich.php)
 * does a few at a time; this runs a backlog now.
 *
 *   /opt/cpanel/ea-php82/root/usr/bin/php api/enrich-imported-records.php [apply] [--type=news|lawsuit|all]
 *       [--ids=12,13] [--limit=50] [--minutes=25]
 *
 * A dry run (no "apply") reads each article and lists what it would fill.
 * --ids can be used to deliberately rerun selected submitted news rows.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/config.php';
if (!defined('ABSPATH')) {
    $dir = __DIR__;
    for ($i = 0; $i < 6 && !defined('ABSPATH'); $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/wp-load.php')) {
            require_once $dir . '/wp-load.php';
        }
    }
}
set_time_limit(0);
require_once __DIR__ . '/lib-record-enrich.php';
require_once __DIR__ . '/lawsuit-facility-links.php';

// Read --name=value by hand: getopt() stops at the first plain word, so
// "apply --limit=250" silently ran with the defaults.
$apply = in_array('apply', $argv, true);
$opt = array();
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(type|ids|limit|minutes)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$type = $opt['type'] ?? 'all';
$ids = isset($opt['ids']) ? array_filter(array_map('intval', explode(',', $opt['ids']))) : array();
$limit = max(1, (int) ($opt['limit'] ?? 50));
$seconds = 60 * max(1, (int) ($opt['minutes'] ?? 25));

$pdo = kop_seed_pdo();
if (!$pdo) {
    exit("No records database.\n");
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo ($apply ? 'APPLY' : 'DRY RUN (add "apply" to save)') . "\n";
$start = time();
foreach ($type === 'all' ? array('news', 'lawsuit') : array($type) as $t) {
    $left = $seconds - (time() - $start);
    if ($left <= 0) {
        break;
    }
    $results = kop_enrich_run($pdo, $t, $limit, $left, $apply, $ids);
    $ok = 0;
    foreach ($results as $r) {
        $ok += $r['ok'] ? 1 : 0;
        echo sprintf("%s #%d %s: %s\n", $t, $r['id'], $r['ok'] ? 'ok' : 'FAILED',
            $r['ok'] ? mb_substr((string) $r['title'], 0, 70) . ' [' . implode(', ', $r['filled']) . ']' : $r['error']);
    }
    echo sprintf("%s: %d of %d filled\n", $t, $ok, count($results));
}
