<?php
/**
 * Scan the inspection reports for serious findings and queue them for review
 * (inc/inspection-highlights.php, docs/FIX-PLAN-2026-09.md item 14).
 *
 * Only reports not yet seen by this version of the rules are scanned, so the
 * nightly run after the scrapers is cheap. Nothing is published: candidates
 * are saved as pending for api/review-inspection-highlights.php.
 *
 * CLI (cron; use the CLI binary, /opt/cpanel/ea-php82/root/usr/bin/php):
 *   php api/scan-inspection-highlights.php [apply] [--limit=5000] [--states=TX,CA]
 *
 * Montana's surveys are copied into the database from js/data/mt_reports.json
 * first (api/lib-mt-reports.php); a dry run only counts them.
 *
 * Browser, as an administrator: a dry run by default, ?apply=1 to save. A
 * saving run works for about 25 seconds a load and, while reports remain,
 * reloads itself (a Refresh header), so one visit carries it to the end.
 * A dry run is one batch.
 */

$kop_ih_cli = php_sapi_name() === 'cli';
$kop_ih_started = microtime(true);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';
require_once __DIR__ . '/lib-mt-reports.php';

if ($kop_ih_cli) {
    while (ob_get_level() > 0) ob_end_clean();
    $apply = in_array('apply', $argv, true);
    $limit = 5000;
    $states = array();
    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int) $m[1];
        if (preg_match('/^--states=([A-Za-z,]+)$/', $arg, $m)) $states = explode(',', $m[1]);
    }
} else {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not authorized. Log in to WordPress as an administrator first.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    // Held until the end, so the reload header can still be sent once the count is known.
    ob_start();
    $apply = !empty($_GET['apply']);
    $limit = min(5000, max(100, (int) ($_GET['limit'] ?? ($apply ? 500 : 3000))));
    $states = !empty($_GET['states']) ? explode(',', preg_replace('/[^A-Za-z,]/', '', (string) $_GET['states'])) : array();
}

if (!$pdo) {
    echo "No database connection.\n";
    exit(1);
}

try {
    // Montana has no scraper posting to the database: copy its surveys from js/data/mt_reports.json first.
    if (!$states || in_array('MT', array_map('strtoupper', $states), true)) {
        $mt = kop_mt_reports_import($pdo, $apply);
        echo 'Montana surveys' . ($apply ? '' : ' (dry run, nothing copied)') . ": {$mt['added']} new, {$mt['updated']} changed, {$mt['unchanged']} already in the database"
            . ($apply ? '' : '; new ones are scanned once copied by an applied run') . ".\n";
    }
    do {
        $result = kop_ih_scan($pdo, $limit, $apply, $states);
        echo ($apply ? 'Saved' : 'Dry run') . ": scanned {$result['scanned']} reports, {$result['remaining']} remaining";
        if ($apply) {
            echo "; {$result['added']} new candidates, {$result['refreshed']} refreshed, {$result['kept']} already reviewed and left alone, "
                . "{$result['dropped']} withdrawn, {$result['relabelled']} reviewed moved between self-harm and suicide attempt, "
                . "{$result['duplicate']} duplicates skipped.\n";
        } else {
            $n = count($result['candidates']);
            echo "; $n candidates, {$result['duplicate']} duplicates skipped.\n";
            usort($result['candidates'], static function ($a, $b) { return $b['score'] <=> $a['score']; });
            foreach (array_slice($result['candidates'], 0, 25) as $c) {
                echo "  {$c['score']}  {$c['state']}  {$c['category']}  {$c['facility_name']}  {$c['report_date']}  {$c['state_label']}\n"
                    . '      ' . mb_substr($c['excerpt'], 0, 300) . "\n";
            }
        }
        // The CLI works through the whole backlog; a saving browser run until about 25 seconds have passed.
    } while ($apply && $result['scanned'] > 0 && $result['remaining'] > 0
        && ($kop_ih_cli || microtime(true) - $kop_ih_started < 25));
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

if (!$kop_ih_cli && !$apply) {
    echo "\nNothing was saved. Add ?apply=1 to save the candidates as pending.\n";
}
if (!$kop_ih_cli && $apply) {
    echo $result['remaining'] > 0
        ? "\n{$result['remaining']} reports left. This page reloads itself in 2 seconds and carries on; leave it open.\n"
        : "\nDone: every report has been scanned with the current rules.\n";
    if ($result['remaining'] > 0 && !headers_sent()) header('Refresh: 2');
}
if (!$kop_ih_cli) ob_end_flush();
