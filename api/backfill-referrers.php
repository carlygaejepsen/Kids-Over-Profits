<?php
/**
 * One-time backfill: seed referrers_master from the crowdsourced educational
 * consultant list (Oct 2025) and the usable rows of the edcon site crawl.
 *
 * Reads referrer-backfill-seed.json (one entry per firm, independent consultants
 * get their own entry) and saves each entry the same way save-master.php does:
 * upsert into referrers_master, then mirror the consultants into the state
 * rows of locations_master (referrerConsultants, keyed by sourceProject).
 *
 * Existing rows are never overwritten unless their unique_name is listed in the
 * seed's "overwrite" array. Safe to re-run.
 *
 * GET  ?dry_run=1  - report inserts / updates / skips and state buckets, change nothing
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

$dry_run = !empty($_GET['dry_run']);

/**
 * Two-letter code or full name -> upper-case full state name (locations_master key).
 */
function kop_referrer_backfill_state($raw) {
    static $abbr = [
        'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
        'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
        'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO',
        'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS',
        'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
        'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI',
        'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA',
        'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK',
        'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA',
        'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
        'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH',
        'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA',
        'WI' => 'WISCONSIN', 'WY' => 'WYOMING',
    ];
    if (!is_string($raw)) return null;
    $s = strtoupper(trim($raw));
    if ($s === '') return null;
    if (isset($abbr[$s])) return $abbr[$s];
    if (in_array($s, $abbr, true)) return $s;
    return null;
}

/**
 * Same wrapper save-master.php stores for a referrers project.
 */
function kop_referrer_backfill_wrap($projectName, array $data) {
    return [
        'name' => $projectName,
        'data' => $data,
        'category' => 'referrers',
        'currentFacilityIndex' => 0,
        'timestamp' => date('c'),
    ];
}

/**
 * Group a record's consultants by normalized state, stamped with the source
 * project like updateLocationProjectsFromSave() does. The individual block is
 * an alias of the first consultant, so it is folded in by name rather than
 * duplicated.
 */
function kop_referrer_backfill_buckets($projectName, array $data) {
    $buckets = [];
    $people = [];
    foreach ($data['referrerConsultants'] ?? [] as $c) {
        if (is_array($c)) $people[] = $c;
    }
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $people[] = $data['referrerIndividual'];
    }
    $seen = [];
    foreach ($people as $c) {
        $name = strtolower(trim((string)($c['fullName'] ?? (($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')))));
        $state = kop_referrer_backfill_state($c['state'] ?? '');
        if (!$state || $name === '' || isset($seen[$state . '|' . $name])) continue;
        $seen[$state . '|' . $name] = true;
        $clone = $c;
        $clone['sourceProject'] = $projectName;
        $clone['sourceCategory'] = 'referrers';
        $buckets[$state][] = $clone;
    }
    return $buckets;
}

/**
 * Replace this project's consultants inside one locations_master row.
 */
function kop_referrer_backfill_sync_location(PDO $pdo, $state, $projectName, array $referrers) {
    $stmt = $pdo->prepare('SELECT json_data FROM locations_master WHERE unique_name = :name');
    $stmt->execute([':name' => $state]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $project = ($row && !empty($row['json_data'])) ? json_decode($row['json_data'], true) : null;
    $locationData = (is_array($project) && isset($project['data']) && is_array($project['data'])) ? $project['data'] : [];
    if (!isset($locationData['facilities']) || !is_array($locationData['facilities'])) {
        $locationData['facilities'] = [];
    }
    if (!isset($locationData['referrerConsultants']) || !is_array($locationData['referrerConsultants'])) {
        $locationData['referrerConsultants'] = [];
    }
    $locationData['referrerConsultants'] = array_values(array_filter(
        $locationData['referrerConsultants'],
        function ($r) use ($projectName) {
            return !isset($r['sourceProject']) || $r['sourceProject'] !== $projectName;
        }
    ));
    foreach ($referrers as $r) {
        $locationData['referrerConsultants'][] = $r;
    }

    $json = json_encode([
        'name' => $state,
        'data' => $locationData,
        'category' => 'locations',
        'currentFacilityIndex' => 0,
        'timestamp' => date('c'),
    ]);
    $sql = 'INSERT INTO locations_master (unique_name, json_data, updated_at)
            VALUES (:unique_name, :json_insert, NOW())
            ON DUPLICATE KEY UPDATE json_data = :json_update, updated_at = NOW()';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':unique_name', $state);
    $stmt->bindValue(':json_insert', $json);
    $stmt->bindValue(':json_update', $json);
    $stmt->execute();
}

try {
    $seedPath = __DIR__ . '/referrer-backfill-seed.json';
    if (!file_exists($seedPath)) {
        throw new RuntimeException('Seed file missing: referrer-backfill-seed.json');
    }
    $seed = json_decode(file_get_contents($seedPath), true);
    if (!is_array($seed) || empty($seed['records']) || !is_array($seed['records'])) {
        throw new RuntimeException('Seed file has no records.');
    }
    $overwrite = array_fill_keys(array_map('strval', $seed['overwrite'] ?? []), true);

    $existing = [];
    foreach ($pdo->query('SELECT unique_name FROM referrers_master')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $existing[$name] = true;
    }

    $report = [
        'seed_records'  => count($seed['records']),
        'inserted'      => [],
        'updated'       => [],
        'skipped_existing' => [],
        'consultants'   => 0,
        'states'        => [],   // STATE => consultant count placed there
        'errors'        => [],
    ];

    $upsert = $pdo->prepare(
        'INSERT INTO referrers_master (unique_name, json_data, updated_at)
         VALUES (:unique_name, :json_insert, NOW())
         ON DUPLICATE KEY UPDATE json_data = :json_update, updated_at = NOW()'
    );

    foreach ($seed['records'] as $record) {
        $name = trim((string)($record['projectName'] ?? ''));
        $data = $record['data'] ?? null;
        if ($name === '' || !is_array($data)) {
            $report['errors'][] = 'Record without projectName or data skipped.';
            continue;
        }

        $isExisting = isset($existing[$name]);
        if ($isExisting && !isset($overwrite[$name])) {
            $report['skipped_existing'][] = $name;
            continue;
        }

        $buckets = kop_referrer_backfill_buckets($name, $data);
        $report['consultants'] += count($data['referrerConsultants'] ?? []);
        foreach ($buckets as $state => $people) {
            $report['states'][$state] = ($report['states'][$state] ?? 0) + count($people);
        }

        if (!$dry_run) {
            $json = json_encode(kop_referrer_backfill_wrap($name, $data), JSON_UNESCAPED_UNICODE);
            $upsert->bindValue(':unique_name', $name);
            $upsert->bindValue(':json_insert', $json);
            $upsert->bindValue(':json_update', $json);
            $upsert->execute();
            foreach ($buckets as $state => $people) {
                try {
                    kop_referrer_backfill_sync_location($pdo, $state, $name, $people);
                } catch (PDOException $e) {
                    $report['errors'][] = "Location $state for $name: " . $e->getMessage();
                }
            }
        }

        if ($isExisting) {
            $report['updated'][] = $name;
        } else {
            $report['inserted'][] = $name;
        }
    }

    ksort($report['states']);
    echo json_encode(['success' => true, 'dry_run' => $dry_run] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('backfill-referrers: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
