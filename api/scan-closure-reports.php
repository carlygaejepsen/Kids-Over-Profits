<?php
/**
 * Scan saved news for facility closures from the server shell, the same scan
 * the hourly WP-Cron job runs (inc/closure-reports.php). Reports land under
 * KOP Data Tools > Closure Reports; nothing changes a facility until someone
 * confirms one there.
 *
 * CLI only:
 *   php api/scan-closure-reports.php                         dry run: print what it would report
 *   php api/scan-closure-reports.php --type=closure          dry run over articles typed "closure"
 *   php api/scan-closure-reports.php --ids=446,367 --limit=5
 *   php api/scan-closure-reports.php apply --limit=200 --minutes=20   store reports (the backlog)
 *
 * A dry run skips the hourly job's bookkeeping, so it looks at articles
 * already scanned too; --ids rescans those articles even when applying.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 20, 'minutes' => 10, 'ids' => '', 'type' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|ids|type)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = in_array($m[1], array('ids', 'type'), true) ? trim($m[2]) : max(1, (int) $m[2]);
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
if (!defined('ABSPATH') || !function_exists('kop_closure_scan_batch')) {
    fwrite(STDERR, "WordPress or inc/closure-reports.php did not load\n");
    exit(2);
}
$pdo = kop_closure_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}

$ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $opts['ids'])));
if (!$ids && $opts['type'] !== '') {
    $stmt = $pdo->prepare("SELECT id FROM news_submissions WHERE article_type = ? AND status NOT IN ('rejected','deleted','promotional') ORDER BY id DESC");
    $stmt->execute(array($opts['type']));
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
if ($ids) {
    $ids = array_slice($ids, 0, $opts['limit']);
}

$stages = kop_closure_report_stages();
$log = static function ($news, $outcome, $reports, $detail) use ($stages) {
    echo '#' . $news['id'] . ' [' . $outcome . '] ' . mb_substr($news['article_title'], 0, 90)
        . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    foreach ($reports as $r) {
        echo '    ' . str_pad($r['report_status'], 9) . ' ' . $stages[$r['stage']]['label']
            . ($r['date'] ? ' ' . $r['date'] : '') . ': ' . $r['program']
            . ($r['facility_id'] ? ' -> #' . $r['facility_id'] . ' (now ' . ($r['current_status'] ?: '?') . ')' : ' -> no match')
            . ($r['quote_found'] ? '' : '  [quote not found]') . "\n";
        if ($r['quote'] !== '') {
            echo '        "' . mb_substr($r['quote'], 0, 200) . "\"\n";
        }
    }
};

$result = kop_closure_scan_batch($pdo, $ids ? count($ids) : $opts['limit'], $opts['minutes'] * 60, $apply, $ids, $log);
$c = $result['counts'];
printf("\n%s: %d scanned, %d with a closure, %d without, %d not about closures, %d failed; %d reports need a person.\n",
    $apply ? 'Stored' : 'Dry run', $c['scanned'], $c['found'], $c['none'], $c['skipped'], $c['error'], count($result['new']));
