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
 * Browser, as an administrator: a dry run by default, ?apply=1 to save. A
 * browser run is one batch; reload until it reports 0 remaining.
 */

$kop_ih_cli = php_sapi_name() === 'cli';

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

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
    $apply = !empty($_GET['apply']);
    $limit = min(5000, max(100, (int) ($_GET['limit'] ?? 3000)));
    $states = !empty($_GET['states']) ? explode(',', preg_replace('/[^A-Za-z,]/', '', (string) $_GET['states'])) : array();
}

if (!$pdo) {
    echo "No database connection.\n";
    exit(1);
}

try {
    do {
        $result = kop_ih_scan($pdo, $limit, $apply, $states);
        echo ($apply ? 'Saved' : 'Dry run') . ": scanned {$result['scanned']} reports, {$result['remaining']} remaining";
        if ($apply) {
            echo "; {$result['added']} new candidates, {$result['refreshed']} refreshed, {$result['kept']} already reviewed and left alone, "
                . "{$result['dropped']} withdrawn, {$result['duplicate']} duplicates skipped.\n";
        } else {
            $n = count($result['candidates']);
            echo "; $n candidates, {$result['duplicate']} duplicates skipped.\n";
            usort($result['candidates'], static function ($a, $b) { return $b['score'] <=> $a['score']; });
            foreach (array_slice($result['candidates'], 0, 25) as $c) {
                echo "  {$c['score']}  {$c['state']}  {$c['category']}  {$c['facility_name']}  {$c['report_date']}  {$c['state_label']}\n"
                    . '      ' . mb_substr($c['excerpt'], 0, 300) . "\n";
            }
        }
        // The CLI works through the whole backlog; a browser run is one batch.
    } while ($kop_ih_cli && $apply && $result['scanned'] > 0 && $result['remaining'] > 0);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

if (!$kop_ih_cli && !$apply) {
    echo "\nNothing was saved. Add ?apply=1 to save the candidates as pending.\n";
}
