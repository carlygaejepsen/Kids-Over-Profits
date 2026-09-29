<?php
/**
 * Look for facilities the saved news mentions that are not in the database,
 * the same scan the hourly WP-Cron job runs (inc/facility-discovery.php).
 * Decisions land under KOP Data Tools > Facilities from News.
 *
 * CLI only:
 *   php api/scan-new-facilities.php                        dry run: print what it would do
 *   php api/scan-new-facilities.php --ids=502,501 --limit=5
 *   php api/scan-new-facilities.php apply --limit=200 --minutes=20   create records (the backlog)
 *
 * A dry run creates nothing, and looks at articles already scanned too.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 20, 'minutes' => 10, 'ids' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|ids)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'ids' ? trim($m[2]) : max(1, (int) $m[2]);
    }
}
$apply = in_array('apply', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_facdisc_scan_batch')) {
    fwrite(STDERR, "WordPress or inc/facility-discovery.php did not load\n");
    exit(2);
}
$pdo = kop_closure_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}

$ids = array_slice(array_filter(array_map('intval', preg_split('/[\s,]+/', $opts['ids']))), 0, $opts['limit']);
$log = static function ($news, $outcome, $decisions, $detail) {
    echo '#' . $news['id'] . ' [' . $outcome . '] ' . mb_substr($news['article_title'], 0, 90)
        . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    foreach ($decisions as $name => $d) {
        list($decision, $fid, $e) = $d;
        $place = trim(implode(', ', array_filter(array($e['city'], $e['state'] ?: $e['country']))));
        echo '    ' . str_pad($decision, 12) . ' ' . $name
            . ($decision === 'created' || $decision === 'needs_place' ? ' => ' . $e['officialName'] . ($place ? " ($place)" : '')
                . ($e['type'] ? ', ' . $e['type'] : '') . ($e['status'] !== 'Unknown' ? ', ' . $e['status'] : '') : '')
            . ($fid ? ' #' . $fid : '') . "\n";
    }
};

$result = kop_facdisc_scan_batch($pdo, $ids ? count($ids) : $opts['limit'], $opts['minutes'] * 60, $apply, $ids, $log);
$c = $result['counts'];
printf("\n%s: %d articles scanned, %d with unknown names, %d without, %d failed; %d facilities %s.\n",
    $apply ? 'Stored' : 'Dry run', $c['scanned'], $c['found'], $c['none'], $c['error'], $c['created'], $apply ? 'created' : 'would be created');
