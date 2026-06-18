<?php
/**
 * Data Manager API (admin-only)
 *
 * Backs the "Data Manager" admin page. Provides the operations that didn't
 * exist before: a unified cross-table listing, moving a record to a different
 * category (between master tables), and reassigning a nested facility from one
 * operator/program record to another.
 *
 * Rename / delete reuse api/save-master.php; document-folder edits reuse
 * api/facility-picker.php; wiki link/confirm/unlink reuse
 * api/link-wiki-facility.php. This file only adds what was missing.
 *
 * Endpoints
 * ---------
 * GET  ?action=list&q=&category=&limit=&offset=
 *      Cross-table listing with category, doc-folder id, facility count and
 *      linked-wiki counts per record.
 *
 * GET  ?action=get_facilities&unique_name=...
 *      The nested facilities of one record (for the reassign picker).
 *
 * GET  ?action=get_wiki_links&unique_name=...
 *      Wiki entries (submissions + master) linked to one program.
 *
 * POST {action:"move_category", unique_name, target_category}
 *      Move a record between facilities/referrers/transporters tables.
 *
 * POST {action:"reassign_facility", from_unique_name, to_unique_name,
 *       facility_index?|facility_id?}
 *      Move a nested facility object from one record to another.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/facility-promotion.php'; // kop_promote_single_nested_facility()

// Ensure the full WordPress API is loaded so we can check capabilities.
if (!function_exists('current_user_can')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            require_once $current . '/wp-load.php';
            break;
        }
    }
}

// ---- Admin gate ----
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

$CATEGORY_TABLE = [
    'companies'    => 'facilities_master',
    'referrers'    => 'referrers_master',
    'transporters' => 'transporters_master',
    'locations'    => 'locations_master',
];
$TABLE_CATEGORY = array_flip($CATEGORY_TABLE);

/**
 * Decode a stored json_data payload AND normalize nested-wrapping, mirroring the
 * REST layer (kop_unwrap_project_payload). Records are sometimes saved as
 * {data:{data:{operator,facilities}}}; without unwrapping, facilities are
 * invisible here and the program index (which unwraps) would disagree.
 */
function kop_dm_decode($json): array {
    $p = json_decode($json ?: '{}', true);
    if (!is_array($p)) {
        $p = [];
    }
    if (function_exists('kop_unwrap_project_payload')) {
        $p = kop_unwrap_project_payload($p);
    } elseif (isset($p['data']['data']) && is_array($p['data']['data'])
        && !isset($p['data']['operator']) && !isset($p['data']['facilities'])) {
        // Fallback unwrap (one level) if the theme helper isn't loaded.
        $p['data'] = $p['data']['data'];
    }
    return $p;
}

/** Human-friendly name + category metadata extracted from a decoded payload. */
function kop_dm_describe(array $project, string $table): array {
    $data = isset($project['data']) && is_array($project['data']) ? $project['data'] : $project;

    $name = $project['name']
        ?? ($data['operator']['name'] ?? null)
        ?? ($data['operator']['currentName'] ?? null)
        ?? ($data['identification']['name'] ?? null);

    $facilities = [];
    if (isset($data['facilities']) && is_array($data['facilities'])) {
        $facilities = $data['facilities'];
    } elseif (isset($project['facilities']) && is_array($project['facilities'])) {
        $facilities = $project['facilities'];
    }

    $folderId = null;
    if (!empty($data['documentFolderId'])) {
        $folderId = (int)$data['documentFolderId'];
    } elseif (!empty($project['documentFolderId'])) {
        $folderId = (int)$project['documentFolderId'];
    }

    return [
        'display_name'       => $name,
        'facility_count'     => count($facilities),
        'document_folder_id' => $folderId,
        'is_stub'            => !empty($project['_stub']),
    ];
}

/**
 * Decide where the facilities array lives in a decoded payload. Resolves to
 * wherever the facilities ACTUALLY are (matching kop_dm_describe): prefer
 * data.facilities, then root-level facilities. Some records keep `operator`
 * under `data` but `facilities` at the root, so picking the `data` branch just
 * because a `data` key exists would wrongly report zero facilities.
 *
 * Only creates an empty array when neither location has one yet. Returns
 * 'data' (new format: data.facilities) or 'root' (legacy: top-level facilities).
 */
function kop_dm_facilities_path(array &$project): string {
    if (isset($project['data']['facilities']) && is_array($project['data']['facilities'])) {
        return 'data';
    }
    if (isset($project['facilities']) && is_array($project['facilities'])) {
        return 'root';
    }
    // Neither exists yet — create one. Prefer the data wrapper when present.
    if (isset($project['data']) && is_array($project['data'])) {
        $project['data']['facilities'] = [];
        return 'data';
    }
    $project['facilities'] = [];
    return 'root';
}

/** Read the facilities array given a path from kop_dm_facilities_path(). */
function kop_dm_get_facilities(array $project, string $path): array {
    return $path === 'data'
        ? ($project['data']['facilities'] ?? [])
        : ($project['facilities'] ?? []);
}

/** Write the facilities array back into a project at the given path. */
function kop_dm_set_facilities(array &$project, string $path, array $facilities): void {
    if ($path === 'data') {
        $project['data']['facilities'] = $facilities;
    } else {
        $project['facilities'] = $facilities;
    }
}

/** Resolve a facility's array index by facility_id (preferred) or index. */
function kop_dm_resolve_facility_index(array $facs, $facilityId, $facilityIndex): ?int {
    if ($facilityId !== null) {
        foreach ($facs as $i => $f) {
            if (is_array($f) && isset($f['facility_id']) && (int)$f['facility_id'] === (int)$facilityId) {
                return (int)$i;
            }
        }
    }
    if ($facilityIndex !== null && isset($facs[$facilityIndex])) {
        return (int)$facilityIndex;
    }
    return null;
}

/** Best-effort display name for a nested facility entry. */
function kop_dm_facility_name(array $facility): string {
    return $facility['identification']['name']
        ?? $facility['identification']['currentName']
        ?? 'facility';
}

/** Normalize a name for matching: lowercase, strip everything but a-z0-9. */
function kop_dm_norm($s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
}

/**
 * Best FileBird folder for a program, STRONG matches only:
 *   1. normalized-equal folder name, or
 *   2. folder name normalized-starts-with the program name (len >= 4).
 * Returns ['id','name'] or null. $names = candidate program names to try.
 */
function kop_dm_best_folder(array $names): ?array {
    if (!function_exists('kop_get_filebird_folders')) {
        return null;
    }
    $folders = kop_get_filebird_folders();
    if (!is_array($folders) || !$folders) {
        return null;
    }
    // Pass 1: exact normalized equality.
    foreach ($names as $name) {
        $n = kop_dm_norm($name);
        if ($n === '') continue;
        foreach ($folders as $f) {
            if (kop_dm_norm($f->name) === $n) {
                return ['id' => (int)$f->id, 'name' => $f->name];
            }
        }
    }
    // Pass 2: folder name starts with the program name (e.g. "Acadia" -> "Acadia Healthcare").
    foreach ($names as $name) {
        $n = kop_dm_norm($name);
        if (strlen($n) < 4) continue;
        foreach ($folders as $f) {
            $fn = kop_dm_norm($f->name);
            if ($fn !== '' && strpos($fn, $n) === 0) {
                return ['id' => (int)$f->id, 'name' => $f->name];
            }
        }
    }
    return null;
}

/**
 * Best wiki entry for a program, STRONG match only: program_name exactly equals
 * one of the candidate names (case-insensitive). Prefers unlinked entries.
 * Returns row with 'type' or null.
 */
function kop_dm_best_wiki(PDO $pdo, array $names): ?array {
    foreach (['submission' => 'wiki_submissions', 'master' => 'wiki_master'] as $type => $t) {
        foreach ($names as $name) {
            if (trim((string)$name) === '') continue;
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, program_name, facility_unique_name, facility_link_status
                     FROM `$t` WHERE LOWER(program_name) = LOWER(?)
                     ORDER BY (facility_unique_name IS NULL) DESC LIMIT 1"
                );
                $stmt->execute([$name]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $r['type'] = $type;
                    return $r;
                }
            } catch (PDOException $e) {
                // table/column missing; skip
            }
        }
    }
    return null;
}

/** Find a record by unique_name across the given tables. */
function kop_dm_find_record(PDO $pdo, array $tables, string $uniqueName): ?array {
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT id, unique_name, json_data FROM `$table` WHERE unique_name = ? LIMIT 1");
        $stmt->execute([$uniqueName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['table'] = $table;
            return $row;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';

        // ---- list ----
        if ($action === 'list') {
            $q        = trim((string)($_GET['q'] ?? ''));
            $category = trim((string)($_GET['category'] ?? ''));
            $limit    = min(max((int)($_GET['limit'] ?? 100), 1), 500);
            $offset   = max((int)($_GET['offset'] ?? 0), 0);

            // Pre-aggregate wiki link counts keyed by facility_unique_name.
            $wikiCounts = [];
            foreach (['wiki_submissions', 'wiki_master'] as $wTable) {
                try {
                    $stmt = $pdo->query(
                        "SELECT facility_unique_name, facility_link_status, COUNT(*) AS c
                         FROM `$wTable`
                         WHERE facility_unique_name IS NOT NULL AND facility_unique_name != ''
                         GROUP BY facility_unique_name, facility_link_status"
                    );
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $un = $r['facility_unique_name'];
                        if (!isset($wikiCounts[$un])) {
                            $wikiCounts[$un] = ['suggested' => 0, 'confirmed' => 0, 'total' => 0];
                        }
                        $status = $r['facility_link_status'] ?: 'suggested';
                        if (!isset($wikiCounts[$un][$status])) $wikiCounts[$un][$status] = 0;
                        $wikiCounts[$un][$status] += (int)$r['c'];
                        $wikiCounts[$un]['total'] += (int)$r['c'];
                    }
                } catch (PDOException $e) {
                    // Table or columns may not exist yet; ignore.
                }
            }

            $tables = $category && isset($CATEGORY_TABLE[$category])
                ? [$category => $CATEGORY_TABLE[$category]]
                : $CATEGORY_TABLE;

            $items = [];
            foreach ($tables as $cat => $table) {
                $sql = "SELECT id, unique_name, json_data, updated_at FROM `$table`";
                $params = [];
                if ($q !== '') {
                    $sql .= " WHERE unique_name LIKE ?";
                    $params[] = '%' . $q . '%';
                }
                $sql .= " ORDER BY unique_name ASC";
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                } catch (PDOException $e) {
                    continue; // table missing
                }
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $project = kop_dm_decode($row['json_data']);
                    if (!empty($project['__facility_ref'])) continue; // hidden id rows

                    $meta = kop_dm_describe($project, $table);
                    $un = $row['unique_name'];
                    $items[] = [
                        'id'                 => (int)$row['id'],
                        'unique_name'        => $un,
                        'category'           => $cat,
                        'table'              => $table,
                        'display_name'       => $meta['display_name'] ?: $un,
                        'facility_count'     => $meta['facility_count'],
                        'document_folder_id' => $meta['document_folder_id'],
                        'is_stub'            => $meta['is_stub'],
                        'wiki_links'         => $wikiCounts[$un] ?? ['suggested' => 0, 'confirmed' => 0, 'total' => 0],
                        'updated_at'         => $row['updated_at'] ?? null,
                    ];
                }
            }

            // Stable sort by name then paginate in PHP.
            usort($items, fn($a, $b) => strcasecmp($a['unique_name'], $b['unique_name']));
            $total = count($items);
            $page  = array_slice($items, $offset, $limit);

            echo json_encode([
                'success' => true,
                'data'    => $page,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ]);
            exit;
        }

        // ---- get_facilities ----
        if ($action === 'get_facilities') {
            $uniqueName = trim((string)($_GET['unique_name'] ?? ''));
            if ($uniqueName === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'unique_name is required']);
                exit;
            }
            $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $uniqueName);
            if (!$rec) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Record not found']);
                exit;
            }
            $project = kop_dm_decode($rec['json_data']);
            $facs = kop_dm_get_facilities($project, kop_dm_facilities_path($project));

            // Batch-resolve each facility's own facilities_master.unique_name (the
            // wiki-link target) from its facility_id, plus wiki link counts.
            $facIds = [];
            foreach ($facs as $f) {
                if (is_array($f) && !empty($f['facility_id'])) $facIds[] = (int)$f['facility_id'];
            }
            $idToUnique = [];
            if ($facIds) {
                $in = implode(',', array_map('intval', array_unique($facIds)));
                foreach ($pdo->query("SELECT id, unique_name FROM facilities_master WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $idToUnique[(int)$r['id']] = $r['unique_name'];
                }
            }
            // Wiki link counts keyed by facility unique_name.
            $wikiCounts = [];
            if ($idToUnique) {
                $names = array_values($idToUnique);
                $place = implode(',', array_fill(0, count($names), '?'));
                foreach (['wiki_submissions', 'wiki_master'] as $wTable) {
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT facility_unique_name, COUNT(*) c FROM `$wTable`
                             WHERE facility_unique_name IN ($place) GROUP BY facility_unique_name"
                        );
                        $stmt->execute($names);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $wikiCounts[$r['facility_unique_name']] = ($wikiCounts[$r['facility_unique_name']] ?? 0) + (int)$r['c'];
                        }
                    } catch (PDOException $e) { /* table/column missing */ }
                }
            }

            $out = [];
            foreach ($facs as $i => $f) {
                $ident = is_array($f) && isset($f['identification']) ? $f['identification'] : [];
                $fid = is_array($f) && isset($f['facility_id']) ? (int)$f['facility_id'] : null;
                $funique = $fid && isset($idToUnique[$fid]) ? $idToUnique[$fid] : null;
                $out[] = [
                    'index'                => $i,
                    'facility_id'          => $fid,
                    'facility_unique_name' => $funique,
                    'wiki_count'           => $funique && isset($wikiCounts[$funique]) ? $wikiCounts[$funique] : 0,
                    'name'                 => $ident['name'] ?? ($ident['currentName'] ?? ('Facility #' . ($i + 1))),
                    'location'             => is_array($f) ? ($f['location'] ?? ($f['address'] ?? '')) : '',
                    'document_folder_id'   => is_array($f) && !empty($f['documentFolderId']) ? (int)$f['documentFolderId'] : null,
                ];
            }
            echo json_encode(['success' => true, 'unique_name' => $uniqueName, 'facilities' => $out]);
            exit;
        }

        // ---- get_wiki_links ----
        if ($action === 'get_wiki_links') {
            $uniqueName = trim((string)($_GET['unique_name'] ?? ''));
            if ($uniqueName === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'unique_name is required']);
                exit;
            }
            $links = [];
            foreach (['submission' => 'wiki_submissions', 'master' => 'wiki_master'] as $type => $wTable) {
                try {
                    $stmt = $pdo->prepare(
                        "SELECT id, program_name, city_state, status, facility_link_status
                         FROM `$wTable` WHERE facility_unique_name = ? ORDER BY updated_at DESC"
                    );
                    $stmt->execute([$uniqueName]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $r['type'] = $type;
                        $links[] = $r;
                    }
                } catch (PDOException $e) {
                    // table/column missing; skip
                }
            }
            echo json_encode(['success' => true, 'unique_name' => $uniqueName, 'links' => $links]);
            exit;
        }

        // ---- search_wiki ----
        // Find wiki entries (submissions + master) to link to a program. Returns
        // each entry's current link state so the admin can see what's free.
        if ($action === 'search_wiki') {
            $q     = trim((string)($_GET['q'] ?? ''));
            $limit = min(max((int)($_GET['limit'] ?? 25), 1), 100);
            $results = [];
            if ($q !== '') {
                $like  = '%' . $q . '%';
                $starts = $q . '%';
                foreach (['submission' => 'wiki_submissions', 'master' => 'wiki_master'] as $type => $wTable) {
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT id, program_name, city_state, facility_unique_name, facility_link_status
                             FROM `$wTable`
                             WHERE program_name LIKE ? OR city_state LIKE ?
                             ORDER BY (CASE WHEN program_name LIKE ? THEN 0 ELSE 1 END), program_name ASC
                             LIMIT $limit"
                        );
                        $stmt->execute([$like, $like, $starts]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $r['type'] = $type;
                            $results[] = $r;
                        }
                    } catch (PDOException $e) {
                        // table/columns missing; skip
                    }
                }
            }
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Unknown GET action '$action'"]);
        exit;
    }

    // -----------------------------------------------------------------------
    // POST
    // -----------------------------------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $action = $input['action'] ?? '';

    // ---- move_category ----
    if ($action === 'move_category') {
        $uniqueName = trim((string)($input['unique_name'] ?? ''));
        $target     = trim((string)($input['target_category'] ?? ''));

        if ($uniqueName === '' || $target === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name and target_category are required']);
            exit;
        }
        if (!isset($CATEGORY_TABLE[$target])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid target_category']);
            exit;
        }
        if ($target === 'locations') {
            http_response_code(400);
            echo json_encode(['success' => false,
                'error' => 'Locations are auto-generated aggregates. Use "Rebuild locations" in the data form instead of moving records into them.']);
            exit;
        }

        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $uniqueName);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            exit;
        }
        $sourceTable = $rec['table'];
        $sourceCat   = $TABLE_CATEGORY[$sourceTable] ?? null;
        $targetTable = $CATEGORY_TABLE[$target];

        if ($sourceTable === 'locations_master') {
            http_response_code(400);
            echo json_encode(['success' => false,
                'error' => 'Location aggregates cannot be moved to another category.']);
            exit;
        }
        if ($sourceTable === $targetTable) {
            echo json_encode(['success' => true, 'message' => 'Record is already in that category.',
                'unique_name' => $uniqueName, 'category' => $target, 'moved' => false]);
            exit;
        }

        // Collision check in the target table.
        $collide = $pdo->prepare("SELECT 1 FROM `$targetTable` WHERE unique_name = ? LIMIT 1");
        $collide->execute([$uniqueName]);
        if ($collide->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false,
                'error' => "A record named '$uniqueName' already exists in the target category. Rename one first."]);
            exit;
        }

        // Update the embedded category label, then move the row atomically.
        $project = kop_dm_decode($rec['json_data']);
        $project['category'] = $target;
        $newJson = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                "INSERT INTO `$targetTable` (unique_name, json_data, created_at, updated_at)
                 VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $ins->execute([$uniqueName, $newJson]);

            $del = $pdo->prepare("DELETE FROM `$sourceTable` WHERE id = ?");
            $del->execute([$rec['id']]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'      => true,
            'moved'        => true,
            'unique_name'  => $uniqueName,
            'from_category' => $sourceCat,
            'category'     => $target,
            'message'      => "Moved '$uniqueName' to '$target'.",
        ]);
        exit;
    }

    // ---- reassign_facility ----
    if ($action === 'reassign_facility') {
        $from = trim((string)($input['from_unique_name'] ?? ''));
        $to   = trim((string)($input['to_unique_name'] ?? ''));
        $hasIndex = array_key_exists('facility_index', $input) && $input['facility_index'] !== '' && $input['facility_index'] !== null;
        $facilityIndex = $hasIndex ? (int)$input['facility_index'] : null;
        $facilityId    = isset($input['facility_id']) && $input['facility_id'] !== '' ? (int)$input['facility_id'] : null;

        if ($from === '' || $to === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'from_unique_name and to_unique_name are required']);
            exit;
        }
        if ($from === $to) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Source and destination are the same record']);
            exit;
        }
        if ($facilityIndex === null && $facilityId === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Provide facility_index or facility_id']);
            exit;
        }

        $srcRec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $from);
        $dstRec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $to);
        if (!$srcRec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Source record '$from' not found"]);
            exit;
        }
        if (!$dstRec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Destination record '$to' not found"]);
            exit;
        }

        $srcProject = kop_dm_decode($srcRec['json_data']);
        $dstProject = kop_dm_decode($dstRec['json_data']);

        $srcPath = kop_dm_facilities_path($srcProject);
        $srcFacs = kop_dm_get_facilities($srcProject, $srcPath);

        // Resolve which facility to move.
        $moveIdx = null;
        if ($facilityId !== null) {
            foreach ($srcFacs as $i => $f) {
                if (is_array($f) && isset($f['facility_id']) && (int)$f['facility_id'] === $facilityId) {
                    $moveIdx = $i;
                    break;
                }
            }
        }
        if ($moveIdx === null && $facilityIndex !== null && isset($srcFacs[$facilityIndex])) {
            $moveIdx = $facilityIndex;
        }
        if ($moveIdx === null || !isset($srcFacs[$moveIdx])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Facility not found in source record']);
            exit;
        }

        $facility = $srcFacs[$moveIdx];
        // Remove from source (reindex) and write back.
        array_splice($srcFacs, $moveIdx, 1);
        kop_dm_set_facilities($srcProject, $srcPath, $srcFacs);

        // Append to destination and write back.
        $dstPath = kop_dm_facilities_path($dstProject);
        $dstFacs = kop_dm_get_facilities($dstProject, $dstPath);
        $dstFacs[] = $facility;
        kop_dm_set_facilities($dstProject, $dstPath, $dstFacs);

        $srcJson = json_encode($srcProject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dstJson = json_encode($dstProject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            $u1 = $pdo->prepare("UPDATE `{$srcRec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $u1->execute([$srcJson, $srcRec['id']]);
            $u2 = $pdo->prepare("UPDATE `{$dstRec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $u2->execute([$dstJson, $dstRec['id']]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        $facName = is_array($facility) && isset($facility['identification']['name'])
            ? $facility['identification']['name']
            : 'facility';

        echo json_encode([
            'success'         => true,
            'moved'           => true,
            'facility_name'   => $facName,
            'facility_id'     => is_array($facility) && isset($facility['facility_id']) ? (int)$facility['facility_id'] : null,
            'from_unique_name' => $from,
            'to_unique_name'  => $to,
            'message'         => "Moved '$facName' from '$from' to '$to'.",
        ]);
        exit;
    }

    // ---- rename ----
    // Operates on the record's ACTUAL table (save-master.php only handled
    // facilities_master) and keeps wiki links + location aggregates in sync.
    if ($action === 'rename') {
        $uniqueName = trim((string)($input['unique_name'] ?? ''));
        $newName    = trim((string)($input['new_unique_name'] ?? $input['newProjectName'] ?? ''));

        if ($uniqueName === '' || $newName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name and new_unique_name are required']);
            exit;
        }
        if ($newName === $uniqueName) {
            echo json_encode(['success' => true, 'renamed' => false, 'message' => 'Name unchanged.']);
            exit;
        }

        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $uniqueName);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            exit;
        }
        $table = $rec['table'];

        // Collision check in the same table.
        $collide = $pdo->prepare("SELECT 1 FROM `$table` WHERE unique_name = ? LIMIT 1");
        $collide->execute([$newName]);
        if ($collide->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "A record named '$newName' already exists."]);
            exit;
        }

        // Keep the embedded name in sync.
        $project = kop_dm_decode($rec['json_data']);
        if (isset($project['name'])) $project['name'] = $newName;
        $newJson = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $wikiUpdated = 0;
        $locUpdated  = [];

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE `$table` SET unique_name = ?, json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$newName, $newJson, $rec['id']]);

            // Repoint wiki links that referenced the old unique_name.
            foreach (['wiki_submissions', 'wiki_master'] as $wTable) {
                try {
                    $w = $pdo->prepare("UPDATE `$wTable` SET facility_unique_name = ? WHERE facility_unique_name = ?");
                    $w->execute([$newName, $uniqueName]);
                    $wikiUpdated += $w->rowCount();
                } catch (PDOException $e) { /* table/column missing */ }
            }

            // Best-effort: update sourceProject references inside location aggregates.
            try {
                $locStmt = $pdo->query("SELECT id, unique_name, json_data FROM `locations_master`");
                $locRows = $locStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($locRows as $loc) {
                    $lp = json_decode($loc['json_data'] ?: '{}', true);
                    if (!is_array($lp)) continue;
                    $changed = false;
                    $scan = function (&$arr) use (&$changed, $uniqueName, $newName) {
                        if (!is_array($arr)) return;
                        foreach ($arr as &$entry) {
                            if (is_array($entry) && isset($entry['sourceProject']) && $entry['sourceProject'] === $uniqueName) {
                                $entry['sourceProject'] = $newName;
                                $changed = true;
                            }
                        }
                        unset($entry);
                    };
                    if (isset($lp['data']['facilities'])) $scan($lp['data']['facilities']);
                    if (isset($lp['data']['referrers']))  $scan($lp['data']['referrers']);
                    if (isset($lp['facilities']))         $scan($lp['facilities']);
                    if ($changed) {
                        $lu = $pdo->prepare("UPDATE `locations_master` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $lu->execute([json_encode($lp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $loc['id']]);
                        $locUpdated[] = $loc['unique_name'];
                    }
                }
            } catch (PDOException $e) { /* locations_master may not exist */ }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'      => true,
            'renamed'      => true,
            'unique_name'  => $newName,
            'wiki_links_updated'      => $wikiUpdated,
            'location_projects_updated' => $locUpdated,
            'message'      => "Renamed to '$newName'." .
                ($wikiUpdated ? " Updated $wikiUpdated wiki link(s)." : '') .
                ($locUpdated ? ' Updated locations: ' . implode(', ', $locUpdated) . '.' : ''),
        ]);
        exit;
    }

    // ---- delete ----
    if ($action === 'delete') {
        $uniqueName = trim((string)($input['unique_name'] ?? ''));
        if ($uniqueName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name is required']);
            exit;
        }
        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $uniqueName);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            exit;
        }
        $table = $rec['table'];
        $wikiUnlinked = 0;

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
            $del->execute([$rec['id']]);

            // Unlink (don't orphan) any wiki entries that pointed at this program.
            foreach (['wiki_submissions', 'wiki_master'] as $wTable) {
                try {
                    $w = $pdo->prepare(
                        "UPDATE `$wTable`
                         SET facility_unique_name = NULL, facility_link_status = NULL
                         WHERE facility_unique_name = ?"
                    );
                    $w->execute([$uniqueName]);
                    $wikiUnlinked += $w->rowCount();
                } catch (PDOException $e) { /* table/column missing */ }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'        => true,
            'deleted'        => true,
            'unique_name'    => $uniqueName,
            'wiki_unlinked'  => $wikiUnlinked,
            'message'        => "Deleted '$uniqueName'." .
                ($wikiUnlinked ? " $wikiUnlinked wiki entr(ies) are now unlinked and need re-linking." : ''),
        ]);
        exit;
    }

    // ---- auto_apply ----
    // Auto-assign the best STRONG matches for a program: a FileBird document
    // folder and a wiki link. Never overwrites an existing doc folder, never
    // steals a wiki entry already linked elsewhere, and links wiki as
    // 'suggested' for later confirmation. Leaves things empty when no strong
    // match exists.
    if ($action === 'auto_apply') {
        $uniqueName = trim((string)($input['unique_name'] ?? ''));
        if ($uniqueName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name is required']);
            exit;
        }
        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $uniqueName);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            exit;
        }
        $project = kop_dm_decode($rec['json_data']);
        $meta = kop_dm_describe($project, $rec['table']);
        $names = array_values(array_unique(array_filter([$meta['display_name'], $uniqueName])));

        $result = ['folder' => null, 'wiki' => null, 'notes' => []];

        // --- Document folder (only if empty) ---
        if (!empty($meta['document_folder_id'])) {
            $result['notes'][] = 'Document folder already set (#' . $meta['document_folder_id'] . ') — left as is.';
        } else {
            $folder = kop_dm_best_folder($names);
            if ($folder) {
                if (!isset($project['data']) || !is_array($project['data'])) {
                    $project['data'] = [];
                }
                $project['documentFolderId'] = $folder['id'];
                $project['data']['documentFolderId'] = $folder['id'];
                $upd = $pdo->prepare("UPDATE `{$rec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rec['id']]);
                $result['folder'] = $folder;
            } else {
                $result['notes'][] = 'No strong folder match found.';
            }
        }

        // --- Wiki link (only if a strong, not-already-linked-elsewhere match) ---
        $wiki = kop_dm_best_wiki($pdo, $names);
        if (!$wiki) {
            $result['notes'][] = 'No strong wiki match found.';
        } elseif ($wiki['facility_unique_name'] === $uniqueName) {
            $result['notes'][] = 'Best wiki entry is already linked here.';
        } elseif (!empty($wiki['facility_unique_name'])) {
            $result['notes'][] = 'Best wiki entry ("' . $wiki['program_name'] . '") is already linked to ' . $wiki['facility_unique_name'] . ' — left as is.';
        } else {
            $wTable = $wiki['type'] === 'master' ? 'wiki_master' : 'wiki_submissions';
            try {
                $w = $pdo->prepare(
                    "UPDATE `$wTable` SET facility_unique_name = ?, facility_link_status = 'suggested',
                     updated_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $w->execute([$uniqueName, (int)$wiki['id']]);
                $result['wiki'] = ['id' => (int)$wiki['id'], 'type' => $wiki['type'], 'program_name' => $wiki['program_name']];
            } catch (PDOException $e) {
                $result['notes'][] = 'Wiki link failed: ' . $e->getMessage();
            }
        }

        $parts = [];
        if ($result['folder']) $parts[] = 'folder “' . $result['folder']['name'] . '” (#' . $result['folder']['id'] . ')';
        if ($result['wiki'])   $parts[] = 'wiki “' . $result['wiki']['program_name'] . '”';
        $msg = $parts ? ('Auto-linked ' . implode(' and ', $parts) . '.') : 'No new strong matches to apply.';

        echo json_encode([
            'success'     => true,
            'unique_name' => $uniqueName,
            'folder'      => $result['folder'],
            'wiki'        => $result['wiki'],
            'notes'       => $result['notes'],
            'message'     => $msg,
        ]);
        exit;
    }

    // ---- facility_link_target ----
    // Resolve (and if needed create) the facilities_master.unique_name that wiki
    // entries link to for a specific nested facility. If the facility has no
    // facility_id yet, promote it (creates a hidden __facility_ref row) so it
    // gets a stable unique_name to link against.
    if ($action === 'facility_link_target') {
        $operator = trim((string)($input['operator_unique_name'] ?? ''));
        $facilityId = isset($input['facility_id']) && $input['facility_id'] !== '' ? (int)$input['facility_id'] : null;
        $hasIndex = array_key_exists('facility_index', $input) && $input['facility_index'] !== '' && $input['facility_index'] !== null;
        $facilityIndex = $hasIndex ? (int)$input['facility_index'] : null;

        if ($operator === '' || ($facilityId === null && $facilityIndex === null)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'operator_unique_name and facility_id/facility_index are required']);
            exit;
        }
        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $operator);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Operator record '$operator' not found"]);
            exit;
        }
        $project = kop_dm_decode($rec['json_data']);
        $path = kop_dm_facilities_path($project);
        $facs = kop_dm_get_facilities($project, $path);
        $idx = kop_dm_resolve_facility_index($facs, $facilityId, $facilityIndex);
        if ($idx === null || !isset($facs[$idx]) || !is_array($facs[$idx])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Facility not found in that record']);
            exit;
        }

        $facility = $facs[$idx];
        $fid = !empty($facility['facility_id']) ? (int)$facility['facility_id'] : null;

        // Promote on demand to get a stable id + unique_name.
        if (!$fid && function_exists('kop_promote_single_nested_facility')) {
            $fid = kop_promote_single_nested_facility($pdo, $facility);
            if ($fid) {
                $facs[$idx] = $facility; // promotion stamped facility_id
                kop_dm_set_facilities($project, $path, $facs);
                $upd = $pdo->prepare("UPDATE `{$rec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rec['id']]);
            }
        }
        if (!$fid) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'This facility has no name to create a linkable record from.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT unique_name FROM facilities_master WHERE id = ? LIMIT 1");
        $stmt->execute([$fid]);
        $funique = $stmt->fetchColumn();
        if (!$funique) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not resolve the facility record.']);
            exit;
        }

        echo json_encode([
            'success'              => true,
            'facility_id'          => $fid,
            'facility_unique_name' => $funique,
            'facility_name'        => kop_dm_facility_name($facility),
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Facility-level actions: operate on one nested facility inside an operator
    // record's data.facilities[]. The facility is identified by facility_id
    // (preferred) or facility_index within the named operator record.
    // -----------------------------------------------------------------------
    if (in_array($action, ['rename_facility', 'set_facility_doc_folder', 'delete_facility'], true)) {
        $operator = trim((string)($input['operator_unique_name'] ?? $input['unique_name'] ?? ''));
        $facilityId = isset($input['facility_id']) && $input['facility_id'] !== '' ? (int)$input['facility_id'] : null;
        $hasIndex = array_key_exists('facility_index', $input) && $input['facility_index'] !== '' && $input['facility_index'] !== null;
        $facilityIndex = $hasIndex ? (int)$input['facility_index'] : null;

        if ($operator === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'operator_unique_name is required']);
            exit;
        }
        if ($facilityId === null && $facilityIndex === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Provide facility_id or facility_index']);
            exit;
        }

        $rec = kop_dm_find_record($pdo, $CATEGORY_TABLE, $operator);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Operator record '$operator' not found"]);
            exit;
        }
        $project = kop_dm_decode($rec['json_data']);
        $path = kop_dm_facilities_path($project);
        $facs = kop_dm_get_facilities($project, $path);

        $idx = kop_dm_resolve_facility_index($facs, $facilityId, $facilityIndex);
        if ($idx === null || !isset($facs[$idx]) || !is_array($facs[$idx])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Facility not found in that record']);
            exit;
        }

        // -- rename_facility --
        if ($action === 'rename_facility') {
            $newName = trim((string)($input['new_name'] ?? ''));
            if ($newName === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'new_name is required']);
                exit;
            }
            if (!isset($facs[$idx]['identification']) || !is_array($facs[$idx]['identification'])) {
                $facs[$idx]['identification'] = [];
            }
            $facs[$idx]['identification']['name'] = $newName;
        }

        // -- set_facility_doc_folder --
        if ($action === 'set_facility_doc_folder') {
            $folderRaw = $input['document_folder_id'] ?? null;
            if ($folderRaw !== null && $folderRaw !== '' && (int)$folderRaw > 0) {
                $facs[$idx]['documentFolderId'] = (int)$folderRaw;
            } else {
                unset($facs[$idx]['documentFolderId']);
            }
        }

        // -- delete_facility --
        $removedName = kop_dm_facility_name($facs[$idx]);
        if ($action === 'delete_facility') {
            array_splice($facs, $idx, 1);
        }

        kop_dm_set_facilities($project, $path, $facs);
        $newJson = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upd = $pdo->prepare("UPDATE `{$rec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$newJson, $rec['id']]);

        $messages = [
            'rename_facility'         => "Renamed facility to '" . ($input['new_name'] ?? '') . "'.",
            'set_facility_doc_folder' => 'Facility document folder updated.',
            'delete_facility'         => "Removed facility '$removedName'.",
        ];
        echo json_encode([
            'success'     => true,
            'operator'    => $operator,
            'facility_id' => $facilityId,
            'message'     => $messages[$action],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false,
        'error' => "Unknown action '$action'. Use: move_category, reassign_facility, rename, delete, "
            . "rename_facility, set_facility_doc_folder, delete_facility"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
