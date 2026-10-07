<?php
/**
 * Check bill and lawsuit statuses from the server shell, the same check the
 * nightly WP-Cron job runs (inc/status-checks.php). Updates wait at Review
 * inbox > Bill and lawsuit updates; nothing changes a record until someone
 * applies one there.
 *
 * CLI only:
 *   php api/check-record-statuses.php                          dry run: print what it finds, store nothing
 *   php api/check-record-statuses.php --kind=bill --ids=19,47   only these records (dry run)
 *   php api/check-record-statuses.php apply --limit=200 --minutes=20   store updates for review
 *
 * A dry run looks at records checked today too; apply only at records due.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 20, 'minutes' => 10, 'ids' => '', 'kind' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|ids|kind)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = in_array($m[1], array('ids', 'kind'), true) ? trim($m[2]) : max(1, (int) $m[2]);
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
if (!defined('ABSPATH') || !function_exists('kop_sc_run')) {
    fwrite(STDERR, "WordPress or inc/status-checks.php did not load\n");
    exit(2);
}
$pdo = kop_sc_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
kop_sc_ensure_tables($pdo);

$kinds = in_array($opts['kind'], array('bill', 'lawsuit'), true) ? array($opts['kind']) : array('bill', 'lawsuit');
$ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $opts['ids']))));
if ($ids && count($kinds) > 1) {
    fwrite(STDERR, "--ids needs --kind=bill or --kind=lawsuit\n");
    exit(2);
}
if (!$apply && !$ids) {
    // A dry run stores nothing, so pick the records the way the night would, ignoring today's checks.
    foreach ($kinds as $k) {
        $table = $k === 'bill' ? 'legislation' : 'lawsuits';
        $final = "'" . implode("','", $k === 'bill' ? kop_sc_final_bill_statuses() : kop_sc_final_lawsuit_statuses()) . "'";
        $found = $pdo->query("SELECT id FROM $table WHERE publication_status = 'published' AND status NOT IN ($final) ORDER BY id LIMIT " . (int) $opts['limit'])->fetchAll(PDO::FETCH_COLUMN);
        $res = kop_sc_run($pdo, (int) $opts['limit'], $opts['minutes'] * 60, false, array($k), array_map('intval', $found), 'kop_sc_cli_log');
        kop_sc_cli_counts($k, $res);
    }
    exit(0);
}

$res = kop_sc_run($pdo, (int) $opts['limit'], $opts['minutes'] * 60, $apply, $kinds, $ids, 'kop_sc_cli_log');
kop_sc_cli_counts(implode('+', $kinds), $res);
if ($apply && $res['new'] && function_exists('kop_notify_admins')) {
    $c = count($res['new']);
    kop_notify_admins('status_update', $c . ' bill / lawsuit ' . ($c === 1 ? 'update' : 'updates') . ' to review', '', array());
}

function kop_sc_cli_log($kind, array $row, $outcome, array $changes, $detail) {
    echo str_pad($outcome, 9) . ' ' . $kind . ' #' . $row['id'] . ' ' . kop_sc_record_title($kind, $row)
        . ($changes ? '  =>  ' . kop_sc_changes_line($changes) : '') . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
}

function kop_sc_cli_counts($label, array $res) {
    $c = $res['counts'];
    echo sprintf("%s: %d checked, %d changed, %d same, %d no source, %d unreadable%s%s\n", $label, $c['checked'], $c['changed'], $c['same'],
        $c['no_source'], $c['error'], $res['remaining'] ? ', ' . $res['remaining'] . ' still due' : '', $res['new'] ? ', ' . count($res['new']) . ' new for review' : '');
}
