<?php
/**
 * Backfill `facility_id` on nested facility entries inside locations_master.json_data.
 *
 * For every locations_master row, walks data.facilities[] and tries to match each
 * nested facility by name to a row in facilities_master. When matched, stamps
 * `facility_id` (and `sourceProjectId` if the source operator project exists too).
 *
 * Safety: must be invoked with ?run=1 from a logged-in admin, or from CLI.
 * Pass ?dry=1 to see what WOULD change without writing.
 *
 *   Browser:  /api/backfill-location-facility-ids.php?run=1
 *   Dry run:  /api/backfill-location-facility-ids.php?run=1&dry=1
 *   CLI:      php api/backfill-location-facility-ids.php
 *   CLI dry:  php api/backfill-location-facility-ids.php dry
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$is_cli = php_sapi_name() === 'cli';
$run_param = $_GET['run'] ?? null;
$dry_run = $is_cli
    ? (in_array('dry', $argv ?? [], true))
    : !empty($_GET['dry']);

if (!$is_cli) {
    // Bootstrap WP so we can check the admin capability.
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) {
                require_once $current . '/wp-load.php';
                break;
            }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');

    if ($run_param !== '1' || !$is_admin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Backfill requires ?run=1 and an admin session, or CLI execution.',
        ]);
        exit;
    }
}

function backfill_facility_name(array $facility): string {
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        $n = trim((string)($facility['identification']['name'] ?? ''));
        if ($n !== '') return $n;
        $n = trim((string)($facility['identification']['currentName'] ?? ''));
        if ($n !== '') return $n;
    }
    return trim((string)($facility['name'] ?? ''));
}

try {
    // Build name -> id map from facilities_master (case-insensitive).
    $map = [];
    $rows = $pdo->query("SELECT id, unique_name FROM facilities_master")->fetchAll();
    foreach ($rows as $r) {
        $key = strtolower(trim((string)$r['unique_name']));
        if ($key !== '') $map[$key] = (int)$r['id'];
    }

    $stmt = $pdo->query("SELECT id, unique_name, json_data FROM locations_master");
    $locations = $stmt->fetchAll();

    $stats = [
        'locations_scanned' => 0,
        'locations_updated' => 0,
        'facilities_scanned' => 0,
        'facilities_stamped' => 0,
        'facilities_unmatched' => 0,
        'unmatched_names' => [],
    ];

    $updateStmt = $pdo->prepare("UPDATE locations_master SET json_data = ?, updated_at = NOW() WHERE id = ?");

    foreach ($locations as $loc) {
        $stats['locations_scanned']++;
        $project = json_decode($loc['json_data'] ?? '', true);
        if (!is_array($project) || empty($project['data']['facilities']) || !is_array($project['data']['facilities'])) {
            continue;
        }

        $touched = false;
        foreach ($project['data']['facilities'] as $idx => $facility) {
            $stats['facilities_scanned']++;

            if (!empty($facility['facility_id'])) {
                continue; // already stamped
            }

            $name = backfill_facility_name(is_array($facility) ? $facility : []);
            if ($name === '') {
                $stats['facilities_unmatched']++;
                continue;
            }

            $key = strtolower($name);
            if (isset($map[$key])) {
                $project['data']['facilities'][$idx]['facility_id'] = $map[$key];
                $stats['facilities_stamped']++;
                $touched = true;
            } else {
                $stats['facilities_unmatched']++;
                if (count($stats['unmatched_names']) < 200) {
                    $stats['unmatched_names'][] = $name;
                }
            }

            // Also stamp sourceProjectId if the parent operator project is in facilities_master.
            if (empty($facility['sourceProjectId']) && !empty($facility['sourceProject'])) {
                $opKey = strtolower(trim((string)$facility['sourceProject']));
                if (isset($map[$opKey])) {
                    $project['data']['facilities'][$idx]['sourceProjectId'] = $map[$opKey];
                    $touched = true;
                }
            }
        }

        if ($touched && !$dry_run) {
            $updateStmt->execute([json_encode($project), $loc['id']]);
            $stats['locations_updated']++;
        } elseif ($touched && $dry_run) {
            $stats['locations_updated']++;
        }
    }

    $stats['unmatched_names'] = array_values(array_unique($stats['unmatched_names']));

    echo json_encode([
        'success' => true,
        'dry_run' => (bool)$dry_run,
        'stats'   => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
