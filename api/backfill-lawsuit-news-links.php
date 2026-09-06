<?php
/**
 * One-time backfill: populate lawsuit_news_links for every existing lawsuit
 * by matching it against every approved/published news article (see
 * lawsuit-news-links.php for the signals).
 *
 * Safe to re-run: the sync is idempotent and never removes manual links.
 * Run backfill-lawsuit-facility-links.php first so facility overlap is
 * available to the matcher.
 *
 * GET  ?dry_run=1  - report what would link (with the reason), change nothing
 * GET/POST         - apply
 *
 * Requires manage_options.
 */

header('Content-Type: application/json');
set_time_limit(300);

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

require_once __DIR__ . '/lawsuit-news-links.php';

$dry_run = !empty($_GET['dry_run']);

try {
    $rows = $pdo->query("SELECT id, case_name, case_number, filing_date, plaintiffs, source_urls, publication_status FROM lawsuits ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
    $facilityMap = kop_lawsuit_facility_map($pdo);
    $live = kop_lawsuit_news_live_news($pdo);

    $titles = [];
    foreach ($live['rows'] as $n) {
        $titles[(int)$n['id']] = $n['alternate_title'] ?: $n['article_title'];
    }

    $report = [
        'lawsuits_seen'   => count($rows),
        'live_articles'   => count($live['rows']),
        'lawsuits_linked' => 0,
        'links_total'     => 0,
        'by_reason'       => [],
        'matches'         => [],  // case_name => [ "news_id: title (reason)", ... ]
    ];

    foreach ($rows as $law) {
        $lid = (int)$law['id'];
        $desired = kop_lawsuit_news_matches_for_lawsuit($pdo, $law, $facilityMap[$lid] ?? []);

        if (!$dry_run) {
            kop_lawsuit_news_write_links($pdo, 'lawsuit_id', $lid, 'news_id', $desired, 'backfill');
        }

        if (empty($desired)) continue;
        $report['lawsuits_linked']++;
        $report['links_total'] += count($desired);
        $label = $law['case_name'] . ' (#' . $lid . ')';
        foreach ($desired as $nid => $reason) {
            $report['by_reason'][$reason] = ($report['by_reason'][$reason] ?? 0) + 1;
            $report['matches'][$label][] = $nid . ': ' . ($titles[$nid] ?? '?') . ' [' . $reason . ']';
        }
    }

    echo json_encode(['success' => true, 'dry_run' => $dry_run] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('backfill-lawsuit-news-links: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
