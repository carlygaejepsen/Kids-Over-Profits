<?php
/**
 * Draft plain-language summaries for hard-to-read serious findings from the
 * server shell, the same job the hourly WP-Cron runs (inc/highlight-summaries.php).
 * Drafts land under KOP Tools > Review inbox > Plain summaries; nothing shows
 * on the site until someone approves one there.
 *
 * CLI only:
 *   php api/scan-highlight-summaries.php                    dry run: list the findings that would get a summary
 *   php api/scan-highlight-summaries.php --ids=704,512      dry run for those findings (the hard-to-read test is skipped)
 *   php api/scan-highlight-summaries.php --try --limit=3    ask the AI and print the answers, store nothing
 *   php api/scan-highlight-summaries.php apply --limit=20   store drafts (the backlog)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 10, 'ids' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|ids)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'ids' ? trim($m[2]) : max(1, (int) $m[2]);
    }
}
$apply = in_array('apply', $argv, true);
$try = in_array('--try', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_hs_run_batch')) {
    fwrite(STDERR, "WordPress or inc/highlight-summaries.php did not load\n");
    exit(2);
}
$pdo = kop_hs_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
kop_hs_ensure_table($pdo);
$ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $opts['ids'])));

if (!$apply && !$try) {
    $queue = kop_hs_candidates($pdo, $opts['limit'], $ids);
    echo count($queue) . " finding(s) would get a draft (showing up to {$opts['limit']}):\n";
    foreach ($queue as $r) {
        echo "  #{$r['id']} {$r['state']} {$r['facility_name']}: " . implode('; ', array_keys($r['reasons'])) . "\n";
    }
    exit(0);
}

$res = kop_hs_run_batch($pdo, $opts['limit'], $ids, $apply);
foreach ($res['items'] as $it) {
    echo "  #{$it['id']} " . (isset($it['summary']) ? $it['summary'] : 'no summary: ' . $it['error']) . "\n";
}
echo ($apply ? 'Stored' : 'Would store') . " {$res['written']} draft(s); {$res['unclear']} could not be summarised; {$res['errors']} error(s); {$res['left']} more waiting.\n";
exit(0);
