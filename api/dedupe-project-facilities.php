<?php
/**
 * Remove duplicate facility entries from one operator project and from the
 * clones of that project in the state/country location profiles.
 *
 * Background: the Teen Challenge row in facilities_master was imported in
 * September 2025 with a block of 13 facilities repeated verbatim, so the data
 * form listed those facilities twice. save-master.php's location sync copied
 * the doubled list into locations_master, so the state profiles hold the same
 * duplicates. The v2 tables were built by identity and are already clean.
 *
 * What it does, for the project named by ?project= (default "Teen Challenge"):
 *   1. Groups the project's facilities by name (identification.name, case and
 *      whitespace insensitive). In each group it keeps the copy carrying the
 *      most data (longest JSON, last copy on a tie) and drops the others only
 *      when they are identical to the keeper or every non-empty field they
 *      hold is present in the keeper. A same-named entry with information the
 *      keeper lacks is left alone and reported, so two distinct campuses that
 *      share a name are never merged.
 *   2. Applies the same rule inside every locations_master row, but only to
 *      entries whose sourceProject is this project (the synced clones).
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. ?dry=1 (CLI "dry")
 * previews without writing. Writes happen in one transaction. Rows are
 * re-read and rewritten whole; nothing else in the JSON changes.
 *
 *   Preview: /api/dedupe-project-facilities.php?run=1&dry=1&project=Teen%20Challenge
 *   Apply:   /api/dedupe-project-facilities.php?run=1&project=Teen%20Challenge
 *   CLI:     php api/dedupe-project-facilities.php "Teen Challenge" [dry]
 */

/** Display name used to group entries. */
function kop_dpf_name($facility) {
    if (!is_array($facility)) return '';
    $ident = $facility['identification'] ?? null;
    if (is_array($ident)) {
        foreach (['name', 'currentName'] as $key) {
            $value = trim((string)($ident[$key] ?? ''));
            if ($value !== '') return $value;
        }
    }
    return trim((string)($facility['name'] ?? ''));
}

function kop_dpf_name_key($facility) {
    $name = kop_dpf_name($facility);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return preg_replace('/\s+/u', ' ', $name);
}

function kop_dpf_is_empty($value) {
    return $value === null || $value === '' || $value === [];
}

function kop_dpf_is_list($value) {
    if (!is_array($value)) return false;
    if ($value === []) return true;
    return array_keys($value) === range(0, count($value) - 1);
}

/**
 * True when everything non-empty in $a is also present in $b: same scalars,
 * every list item of $a matched by some item of $b, every filled key of $a
 * present in $b with a subset value.
 */
function kop_dpf_is_subset($a, $b) {
    if (kop_dpf_is_empty($a)) return true;
    if (is_array($a)) {
        if (!is_array($b)) return false;
        if (kop_dpf_is_list($a)) {
            if (!kop_dpf_is_list($b)) return false;
            foreach ($a as $item) {
                $found = false;
                foreach ($b as $candidate) {
                    if (kop_dpf_is_subset($item, $candidate)) { $found = true; break; }
                }
                if (!$found) return false;
            }
            return true;
        }
        foreach ($a as $key => $value) {
            if (kop_dpf_is_empty($value)) continue;
            if (!array_key_exists($key, $b)) return false;
            if (!kop_dpf_is_subset($value, $b[$key])) return false;
        }
        return true;
    }
    if (is_scalar($a) && is_scalar($b)) {
        return $a === $b || (string)$a === (string)$b;
    }
    return false;
}

/**
 * Dedupe a facilities list. When $onlySource is set, only entries whose
 * sourceProject equals it take part; everything else is left untouched.
 *
 * Returns ['facilities' => [...], 'removed' => [...], 'kept_distinct' => [...]].
 */
function kop_dpf_dedupe(array $facilities, $onlySource = null) {
    $groups = [];
    foreach ($facilities as $index => $facility) {
        if (!is_array($facility)) continue;
        if ($onlySource !== null && (string)($facility['sourceProject'] ?? '') !== (string)$onlySource) continue;
        $key = kop_dpf_name_key($facility);
        if ($key === '') continue;
        $groups[$key][] = $index;
    }

    $drop = [];
    $removed = [];
    $keptDistinct = [];
    foreach ($groups as $key => $indexes) {
        if (count($indexes) < 2) continue;

        // Keeper: the copy holding the most data; the later copy on a tie.
        $keeper = null;
        $keeperSize = -1;
        foreach ($indexes as $index) {
            $size = strlen((string)json_encode($facilities[$index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($size >= $keeperSize) { $keeper = $index; $keeperSize = $size; }
        }

        foreach ($indexes as $index) {
            if ($index === $keeper) continue;
            $identical = $facilities[$index] == $facilities[$keeper];
            if ($identical || kop_dpf_is_subset($facilities[$index], $facilities[$keeper])) {
                $drop[$index] = true;
                $removed[] = [
                    'index'      => $index,
                    'name'       => kop_dpf_name($facilities[$index]),
                    'kept_index' => $keeper,
                    'identical'  => $identical,
                ];
            } else {
                $keptDistinct[] = [
                    'index'      => $index,
                    'name'       => kop_dpf_name($facilities[$index]),
                    'other_copy' => $keeper,
                    'reason'     => 'holds data the kept copy lacks',
                ];
            }
        }
    }

    $kept = [];
    foreach ($facilities as $index => $facility) {
        if (!isset($drop[$index])) $kept[] = $facility;
    }
    return ['facilities' => $kept, 'removed' => $removed, 'kept_distinct' => $keptDistinct];
}

/**
 * Find where a stored project keeps its facilities: at the root (old import
 * shape), under data, or under data.data. Returns the key path or null.
 */
function kop_dpf_locate(array $project) {
    if (isset($project['facilities']) && is_array($project['facilities'])) return ['facilities'];
    if (isset($project['data']) && is_array($project['data'])) {
        if (isset($project['data']['facilities']) && is_array($project['data']['facilities'])) return ['data', 'facilities'];
        if (isset($project['data']['data']['facilities']) && is_array($project['data']['data']['facilities'])) return ['data', 'data', 'facilities'];
    }
    return null;
}

function kop_dpf_get(array $project, array $path) {
    $node = $project;
    foreach ($path as $key) $node = $node[$key];
    return $node;
}

function kop_dpf_set(array $project, array $path, $value) {
    $ref = &$project;
    foreach ($path as $key) $ref = &$ref[$key];
    $ref = $value;
    unset($ref);
    return $project;
}

/**
 * Dedupe one stored project. Returns [project, report] where report is null
 * when nothing changed.
 */
function kop_dpf_process(array $project, $onlySource = null) {
    $path = kop_dpf_locate($project);
    if ($path === null) return [$project, null];
    $before = kop_dpf_get($project, $path);
    $result = kop_dpf_dedupe($before, $onlySource);
    if (!$result['removed'] && !$result['kept_distinct']) return [$project, null];
    $project = kop_dpf_set($project, $path, $result['facilities']);
    return [$project, [
        'path'          => implode('.', $path),
        'before'        => count($before),
        'after'         => count($result['facilities']),
        'removed'       => $result['removed'],
        'kept_distinct' => $result['kept_distinct'],
    ]];
}

// Library mode for offline tests: define this before including the file.
if (defined('KOP_DEDUPE_FACILITIES_LIB') && KOP_DEDUPE_FACILITIES_LIB) return;

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../inc/facility-v2-writer.php';
kop_v2_exit_if_legacy_frozen($pdo ?? null, 'dedupe-project-facilities.php');

$is_cli  = php_sapi_name() === 'cli';
$args    = $is_cli ? array_slice($argv ?? [], 1) : [];
$dry_run = $is_cli ? in_array('dry', $args, true) : !empty($_GET['dry']);
$run_ok  = $is_cli || (($_GET['run'] ?? null) === '1');
$project_name = 'Teen Challenge';
if ($is_cli) {
    foreach ($args as $arg) { if ($arg !== 'dry') { $project_name = $arg; break; } }
} elseif (isset($_GET['project']) && trim((string)$_GET['project']) !== '') {
    $project_name = trim((string)$_GET['project']);
}

if (!$is_cli) {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.']);
        exit;
    }
}

if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

$encode = function ($value) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    return $json;
};

try {
    $out = [
        'success'   => true,
        'dry_run'   => $dry_run,
        'project'   => $project_name,
        'operator'  => null,
        'locations' => [],
        'totals'    => ['operator_removed' => 0, 'location_rows_changed' => 0, 'location_removed' => 0, 'kept_distinct' => 0],
    ];

    $writes = [];

    // 1. The operator row.
    $stmt = $pdo->prepare("SELECT id, unique_name, json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
    $stmt->execute([$project_name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("No facilities_master row named '{$project_name}'.");
    $project = json_decode((string)$row['json_data'], true);
    if (!is_array($project)) throw new RuntimeException("facilities_master row '{$project_name}' did not decode.");
    if (!empty($project['__facility_ref'])) throw new RuntimeException("'{$project_name}' is a promoted per-facility row, not an operator project.");

    list($project, $report) = kop_dpf_process($project);
    $out['operator'] = ['row_id' => (int)$row['id']] + ($report ?? ['before' => null, 'after' => null, 'removed' => [], 'kept_distinct' => []]);
    if ($report && $report['removed']) {
        $writes[] = ['table' => 'facilities_master', 'id' => (int)$row['id'], 'json' => $encode($project)];
        $out['totals']['operator_removed'] = count($report['removed']);
    }
    if ($report) $out['totals']['kept_distinct'] += count($report['kept_distinct']);

    // 2. The synced clones in every location profile.
    $rows = $pdo->query("SELECT id, unique_name, json_data FROM locations_master ORDER BY unique_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $loc) {
        $data = json_decode((string)$loc['json_data'], true);
        if (!is_array($data)) continue;
        list($data, $report) = kop_dpf_process($data, $project_name);
        if (!$report) continue;
        $out['locations'][] = ['row_id' => (int)$loc['id'], 'name' => $loc['unique_name']] + $report;
        $out['totals']['kept_distinct'] += count($report['kept_distinct']);
        if ($report['removed']) {
            $writes[] = ['table' => 'locations_master', 'id' => (int)$loc['id'], 'json' => $encode($data)];
            $out['totals']['location_rows_changed']++;
            $out['totals']['location_removed'] += count($report['removed']);
        }
    }

    if (!$dry_run && $writes) {
        $pdo->beginTransaction();
        try {
            foreach ($writes as $w) {
                $upd = $pdo->prepare("UPDATE `{$w['table']}` SET json_data = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$w['json'], $w['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    $out['rows_written'] = $dry_run ? 0 : count($writes);

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
