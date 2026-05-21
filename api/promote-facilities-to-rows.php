<?php
/**
 * Promote nested facility entries to their own facilities_master rows.
 *
 * Background: `facilities_master` is keyed by operator/company name today, so
 * the individual facilities listed inside `data.facilities[]` of each
 * operator/location project don't have stable IDs of their own. That blocks
 * per-facility news linking and any other foreign-key relationship.
 *
 * This one-shot migration:
 *   - walks every project in facilities_master AND locations_master,
 *   - groups nested facility entries by normalized (name, state),
 *   - inserts a new facilities_master row for each unique (name, state) pair,
 *   - marks those new rows with `__facility_ref: true` in their json_data so
 *     the public directory loader can skip them,
 *   - stamps `facility_id` on the original nested entry and saves the project
 *     json_data back.
 *
 * Safe to re-run: an existing row matching by unique_name (or already-stamped
 * facility_id on the nested entry) is reused, not duplicated.
 *
 * Safety: must be invoked with ?run=1 from a logged-in admin, or from CLI.
 * Pass ?dry=1 (or `dry` CLI arg) to see what WOULD change without writing.
 *
 *   Browser:  /api/promote-facilities-to-rows.php?run=1
 *   Dry run:  /api/promote-facilities-to-rows.php?run=1&dry=1
 *   CLI:      php api/promote-facilities-to-rows.php
 *   CLI dry:  php api/promote-facilities-to-rows.php dry
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$is_cli = php_sapi_name() === 'cli';
$run_param = $_GET['run'] ?? null;
$dry_run = $is_cli
    ? (in_array('dry', $argv ?? [], true))
    : !empty($_GET['dry']);

if (!$is_cli) {
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
            'error'   => 'Migration requires ?run=1 and an admin session, or CLI execution.',
        ]);
        exit;
    }
}

/** Extract a display name from a nested facility entry. */
function promote_extract_name(array $facility): string {
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        $n = trim((string)($facility['identification']['name'] ?? ''));
        if ($n !== '') return $n;
        $n = trim((string)($facility['identification']['currentName'] ?? ''));
        if ($n !== '') return $n;
    }
    return trim((string)($facility['name'] ?? ''));
}

/** Pull a 2-letter state (uppercased) or full state name from a facility entry. */
function promote_extract_state(array $facility): ?string {
    $candidates = [];
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        $candidates[] = $facility['identification']['state'] ?? null;
        $candidates[] = $facility['identification']['locationState'] ?? null;
    }
    if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
        $candidates[] = $facility['locationDetails']['state'] ?? null;
    }
    $candidates[] = $facility['state'] ?? null;
    $candidates[] = $facility['locationState'] ?? null;

    foreach ($candidates as $c) {
        if (is_string($c)) {
            $s = trim($c);
            if ($s !== '') return $s;
        }
    }

    // Last resort: parse "City, ST" out of free-form fields.
    foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
        if (!empty($facility[$k]) && is_string($facility[$k])) {
            if (preg_match('/,\s*([A-Za-z .]+?)\s*$/', trim($facility[$k]), $m)) {
                return trim($m[1]);
            }
        }
    }
    return null;
}

/** Extract a city for unique_name disambiguation. */
function promote_extract_city(array $facility): ?string {
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        $c = trim((string)($facility['identification']['city'] ?? ''));
        if ($c !== '') return $c;
    }
    if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
        $c = trim((string)($facility['locationDetails']['city'] ?? ''));
        if ($c !== '') return $c;
    }
    if (!empty($facility['city']) && is_string($facility['city'])) {
        $c = trim($facility['city']);
        if ($c !== '') return $c;
    }
    foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
        if (!empty($facility[$k]) && is_string($facility[$k])) {
            if (preg_match('/^\s*([^,]+),/', trim($facility[$k]), $m)) {
                return trim($m[1]);
            }
        }
    }
    return null;
}

try {
    // Build a case-insensitive index of every existing facilities_master row so
    // we can reuse rather than duplicate.
    $existingByName = [];   // lower(unique_name) -> id
    $existingIds = [];      // id -> unique_name (for reverse lookup)
    $rows = $pdo->query("SELECT id, unique_name FROM facilities_master")->fetchAll();
    foreach ($rows as $r) {
        $existingByName[strtolower(trim((string)$r['unique_name']))] = (int)$r['id'];
        $existingIds[(int)$r['id']] = (string)$r['unique_name'];
    }

    // Track what we've promoted during this run so a facility appearing in
    // multiple projects (operator + location bucket) reuses one row.
    $promotedByKey = [];  // "name|state" lowercased -> id

    $stats = [
        'projects_scanned'      => 0,
        'projects_rewritten'    => 0,
        'facilities_scanned'    => 0,
        'facilities_skipped_no_name' => 0,
        'facilities_reused_existing' => 0,
        'facilities_newly_promoted'  => 0,
        'facilities_stamped'    => 0,
        'unique_name_collisions'=> 0,
        'sample_new_rows'       => [],
    ];

    // Helper: pick a unique_name that doesn't collide. Tries the bare name,
    // then "<name> (<state>)", then "<name> (<city>, <state>)", then numeric
    // suffixes. Records collisions in the stats.
    $allocateUniqueName = function (string $name, ?string $state, ?string $city) use (&$existingByName, &$stats): string {
        $tries = [];
        $tries[] = $name;
        if ($state) $tries[] = $name . ' (' . $state . ')';
        if ($city && $state) $tries[] = $name . ' (' . $city . ', ' . $state . ')';
        foreach ($tries as $candidate) {
            $key = strtolower(trim($candidate));
            if (!isset($existingByName[$key])) {
                return $candidate;
            }
        }
        $stats['unique_name_collisions']++;
        $n = 2;
        while (true) {
            $candidate = $name . ' #' . $n;
            $key = strtolower($candidate);
            if (!isset($existingByName[$key])) return $candidate;
            $n++;
            if ($n > 999) throw new RuntimeException('unique_name allocation runaway for ' . $name);
        }
    };

    $insertStmt = $pdo->prepare(
        "INSERT INTO facilities_master (unique_name, json_data, created_at, updated_at)
         VALUES (?, ?, NOW(), NOW())"
    );

    /**
     * Process one project's nested data.facilities[]: returns [modifiedProject, rowChanged].
     */
    $processProject = function (array $project) use (
        &$stats, &$promotedByKey, &$existingByName, &$existingIds,
        $insertStmt, $allocateUniqueName, $pdo, $dry_run
    ): array {
        if (empty($project['data']['facilities']) || !is_array($project['data']['facilities'])) {
            return [$project, false];
        }
        $rowChanged = false;
        foreach ($project['data']['facilities'] as $i => $facility) {
            if (!is_array($facility)) continue;
            $stats['facilities_scanned']++;
            $name = promote_extract_name($facility);
            if ($name === '') {
                $stats['facilities_skipped_no_name']++;
                continue;
            }

            // If the nested entry was already stamped by a prior run, trust it.
            if (!empty($facility['facility_id']) && isset($existingIds[(int)$facility['facility_id']])) {
                continue;
            }

            $state = promote_extract_state($facility);
            $city  = promote_extract_city($facility);
            $key   = strtolower($name) . '|' . strtolower((string)$state);

            $fid = null;

            // Already promoted earlier in this same run?
            if (isset($promotedByKey[$key])) {
                $fid = $promotedByKey[$key];
                $stats['facilities_reused_existing']++;
            }

            // Already exists in facilities_master by exact unique_name?
            if ($fid === null) {
                $lowerName = strtolower($name);
                if (isset($existingByName[$lowerName])) {
                    $fid = $existingByName[$lowerName];
                    $promotedByKey[$key] = $fid;
                    $stats['facilities_reused_existing']++;
                }
            }

            // Otherwise insert a new __facility_ref row.
            if ($fid === null) {
                $uniqueName = $allocateUniqueName($name, $state, $city);
                $rowPayload = [
                    'name'           => $uniqueName,
                    'displayName'    => $name,
                    'state'          => $state,
                    'city'           => $city,
                    '__facility_ref' => true,
                    'data'           => [
                        // Carry a snapshot of the original nested facility so
                        // the row is self-describing if the source project goes
                        // away later.
                        'facility' => $facility,
                    ],
                    'timestamp'      => date('c'),
                ];
                if ($dry_run) {
                    // Pretend an ID was allocated; use a negative sentinel so
                    // we don't collide with real ids.
                    static $sentinel = 0;
                    $sentinel--;
                    $fid = $sentinel;
                } else {
                    $insertStmt->execute([
                        $uniqueName,
                        json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                    $fid = (int)$pdo->lastInsertId();
                    $existingByName[strtolower($uniqueName)] = $fid;
                    $existingIds[$fid] = $uniqueName;
                }
                $promotedByKey[$key] = $fid;
                $stats['facilities_newly_promoted']++;
                if (count($stats['sample_new_rows']) < 30) {
                    $stats['sample_new_rows'][] = [
                        'unique_name' => $uniqueName,
                        'state' => $state,
                        'city' => $city,
                    ];
                }
            }

            $project['data']['facilities'][$i]['facility_id'] = $fid;
            $stats['facilities_stamped']++;
            $rowChanged = true;
        }
        return [$project, $rowChanged];
    };

    // Iterate facilities_master AND locations_master.
    // Skip __facility_ref rows in facilities_master (they don't have nested arrays).
    foreach (['facilities_master', 'locations_master'] as $table) {
        $select = $pdo->query("SELECT id, unique_name, json_data FROM {$table}");
        while ($row = $select->fetch()) {
            $project = json_decode($row['json_data'] ?? '', true);
            if (!is_array($project)) continue;
            if (!empty($project['__facility_ref'])) continue; // already a hidden row

            $stats['projects_scanned']++;
            list($project, $rowChanged) = $processProject($project);
            if ($rowChanged) {
                $stats['projects_rewritten']++;
                if (!$dry_run) {
                    $upd = $pdo->prepare("UPDATE {$table} SET json_data = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([
                        json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $row['id'],
                    ]);
                }
            }
        }
    }

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
