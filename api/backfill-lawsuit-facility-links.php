<?php
/**
 * One-time backfill: populate lawsuit_facility_links for every existing
 * lawsuit from its facilities_mentioned names, and auto-assign a FileBird
 * folder (from the first linked facility) to published lawsuits that lack one.
 *
 * Safe to re-run: the sync is idempotent and never removes admin-created
 * primary/related links, and folders are only filled in where empty.
 *
 * GET  ?dry_run=1  - report what would link, change nothing
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

require_once __DIR__ . '/lawsuit-facility-links.php';

$dry_run = !empty($_GET['dry_run']);

try {
    $rows = $pdo->query("SELECT id, case_name, facilities_mentioned, filebird_folder_id, publication_status FROM lawsuits")->fetchAll(PDO::FETCH_ASSOC);

    $report = [
        'lawsuits_seen'    => count($rows),
        'lawsuits_linked'  => 0,
        'links_total'      => 0,
        'folders_assigned' => 0,
        'unmatched'        => [],  // case_name => names that resolved to nothing
    ];

    if ($dry_run) {
        // Resolve without writing: reuse the alias index directly.
        $aliasIndex = kop_build_facility_alias_index($pdo);
        foreach ($rows as $law) {
            $mentions = kop_normalize_facility_mentions($law['facilities_mentioned']);
            $linked = 0;
            $misses = [];
            foreach ($mentions as $m) {
                $fid = !empty($m['facility_id'])
                    ? (int)$m['facility_id']
                    : kop_resolve_name_to_facility((string)$m['name'], $aliasIndex);
                if ($fid) { $linked++; } else { $misses[] = $m['name']; }
            }
            if ($linked > 0) { $report['lawsuits_linked']++; $report['links_total'] += $linked; }
            if ($misses) { $report['unmatched'][$law['case_name']] = $misses; }
        }
        echo json_encode(['success' => true, 'dry_run' => true] + $report, JSON_PRETTY_PRINT);
        exit;
    }

    foreach ($rows as $law) {
        $lid = (int)$law['id'];
        $facilityIds = kop_sync_lawsuit_facility_links($pdo, $lid, $law['facilities_mentioned'], 'backfill');
        if (!empty($facilityIds)) {
            $report['lawsuits_linked']++;
            $report['links_total'] += count($facilityIds);
        } else {
            $mentions = kop_facility_mention_names($law['facilities_mentioned']);
            if ($mentions) $report['unmatched'][$law['case_name']] = $mentions;
        }

        if ($law['publication_status'] === 'published' && empty($law['filebird_folder_id']) && !empty($facilityIds)) {
            $folderId = kop_lawsuit_resolve_facility_folder($pdo, $facilityIds);
            if ($folderId) {
                $pdo->prepare("UPDATE lawsuits SET filebird_folder_id = ? WHERE id = ?")->execute([$folderId, $lid]);
                $report['folders_assigned']++;
            }
        }
    }

    echo json_encode(['success' => true, 'dry_run' => false] + $report, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('backfill-lawsuit-facility-links: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
