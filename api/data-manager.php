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
 *      list also takes category=facilities (each facilities_v2 record),
 *      program_homes (programs and their homes), young_adult,
 *      indigenous_schools and people (person ids, inc/people.php; in "All"
 *      only for a search); every row carries a 'kind' (operator, legacy,
 *      facility, young_adult, indigenous_school, person).
 *
 * GET  ?action=get_person&id=   one person: details, where named, likely same-person picks
 * POST {action:"person_save", id, name, aliases, notes}
 * POST {action:"person_merge", id, into}  (into = "#id" or an exact name; Undo via person_undo_merge {log})
 * POST {action:"person_separate", id, facility_id, list, position}
 *
 *      list also takes category=merged, converted, news, lawsuits, bills and the
 *      facility filters state=, status=, type= ('-' = none), years=none|closed_no_end;
 *      their actions are in api/data-manager-extra.php.
 *
 * GET  ?action=get_designation&facility_id=   what one facility record is now
 * GET  ?action=find_facility&q=               facility records by name (admin finder)
 *
 * POST {action:"set_designation", facility_id, designation: home|not_home|
 *       young_adult|indigenous_school, program_id?, program_name?}
 * POST {action:"undo_home", facility_id, program_id, before, before_name, new_group}
 * POST {action:"rename_record"|"set_record_doc_folder", kind, id, new_name?|document_folder_id?}
 *
 * POST {action:"move_category", unique_name, target_category}
 *      Move a record between facilities/referrers/transporters/providers tables.
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

// The v2 helpers (kop_v2_writes_active() and the rest). Loaded here, not
// lazily inside kop_dm_v2_prefix(): PHP looks a function up before it
// evaluates the arguments, so kop_v2_writes_active($pdo, kop_dm_v2_prefix($pdo))
// failed with "undefined function" whenever nothing had loaded this file yet.
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
// Filters, merged records, converted companies, news/lawsuits/bills and their actions.
require_once __DIR__ . '/data-manager-extra.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

$CATEGORY_TABLE = [
    'companies'    => 'facilities_master',
    'referrers'    => 'referrers_master',
    'transporters' => 'transporters_master',
    'providers'    => 'providers_master',
    'locations'    => 'locations_master',
];
$TABLE_CATEGORY = array_flip($CATEGORY_TABLE);

/**
 * Decode a stored json_data payload AND normalize nested-wrapping, mirroring the
 * REST layer (kop_unwrap_project_payload). Records are sometimes saved as
 * {data:{data:{operator,facilities}}}; without unwrapping, facilities are
 * invisible here and the program index (which unwraps) would disagree.
 */
/**
 * [name, kind] when $q is in one of the record's alternate names (its
 * operator's or a facility's pastNames / otherNames / currentName), null
 * otherwise. kind: 'past', 'other' or 'current'.
 */
function kop_dm_alias_hit(array $project, string $q): ?array {
    $d = isset($project['data']) && is_array($project['data']) ? $project['data'] : $project;
    $holders = [];
    if (isset($d['operator']) && is_array($d['operator'])) $holders[] = $d['operator'];
    foreach ((isset($d['facilities']) && is_array($d['facilities']) ? $d['facilities'] : []) as $f) {
        if (is_array($f) && isset($f['identification']) && is_array($f['identification'])) $holders[] = $f['identification'];
    }
    foreach ($holders as $h) {
        foreach (['pastNames' => 'past', 'previousNames' => 'past', 'otherNames' => 'other'] as $k => $kind) {
            foreach ((isset($h[$k]) && is_array($h[$k]) ? $h[$k] : []) as $n) {
                if (is_array($n)) $n = $n['name'] ?? '';
                if (is_string($n) && trim($n) !== '' && mb_stripos($n, $q) !== false) return [trim($n), $kind];
            }
        }
        if (!empty($h['currentName']) && is_string($h['currentName']) && mb_stripos($h['currentName'], $q) !== false) {
            return [trim($h['currentName']), 'current'];
        }
    }
    return null;
}

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
        'is_stub'            => !empty($project['_stub']) || !empty($data['_stub']),
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

/** Find a record by unique_name across the given tables. Skips tables that
 *  don't exist on this install (e.g. transporters_master may be absent). */
function kop_dm_find_record(PDO $pdo, array $tables, string $uniqueName): ?array {
    foreach ($tables as $table) {
        if ($table === 'facilities_master' && kop_dm_v2_writes($pdo)) {
            $row = kop_v2_pdo_master_row_by_name($pdo, kop_dm_v2_prefix($pdo), $uniqueName);
            if ($row) {
                $row['table'] = 'facilities_master';
                return $row;
            }
            continue;
        }
        try {
            $stmt = $pdo->prepare("SELECT id, unique_name, json_data FROM `$table` WHERE unique_name = ? LIMIT 1");
            $stmt->execute([$uniqueName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            continue; // table missing on this install
        }
        if ($row) {
            $row['table'] = $table;
            return $row;
        }
    }
    return null;
}

/**
 * The v2 facility tables (inc/facility-v2-writer.php) are where operator
 * projects and facilities live once the write switch is on; facilities_master
 * is frozen from then on. These three keep the rest of this file unaware of
 * which model is active.
 */
function kop_dm_v2_prefix(PDO $pdo): string {
    static $prefix = null;
    if ($prefix === null) {
        $prefix = kop_v2_detect_prefix($pdo);
    }
    return $prefix;
}

function kop_dm_v2_writes(PDO $pdo): bool {
    static $on = null;
    if ($on === null) {
        $on = kop_v2_writes_active($pdo, kop_dm_v2_prefix($pdo));
    }
    return $on;
}

/** Legacy-shaped rows of facilities_master, from whichever model is active. */
function kop_dm_master_rows(PDO $pdo): array {
    if (kop_dm_v2_writes($pdo)) {
        return kop_v2_pdo_master_rows($pdo, kop_dm_v2_prefix($pdo));
    }
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT id, unique_name, json_data, updated_at FROM `facilities_master`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // table missing on this install
    }
    return $rows;
}

/** Write an edited record back to whichever model holds it. */
function kop_dm_save_project(PDO $pdo, array $rec, array $project): void {
    if (kop_dm_v2_writes($pdo) && ($rec['table'] ?? '') === 'facilities_master') {
        kop_v2_save_legacy_row($pdo, kop_dm_v2_prefix($pdo), (string)$rec['unique_name'], $project);
        return;
    }
    $upd = $pdo->prepare("UPDATE `{$rec['table']}` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $upd->execute([json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rec['id']]);
}

/**
 * The id of the facility at $index, saving the project first when it has none
 * (a v2 save assigns ids to new facilities and returns them).
 */
function kop_dm_assign_facility_id(PDO $pdo, array $rec, array $project, string $path, int $index): ?int {
    $facs = kop_dm_get_facilities($project, $path);
    if (!empty($facs[$index]['facility_id'])) {
        return (int)$facs[$index]['facility_id'];
    }
    $saved = kop_v2_save_legacy_row($pdo, kop_dm_v2_prefix($pdo), (string)$rec['unique_name'], $project);
    $id = $saved['ids'][$index] ?? null;
    return $id ? (int)$id : null;
}

/** A facility row's unique_name by id, for the wiki link target. */
function kop_dm_facility_unique_name(PDO $pdo, int $facilityId): ?string {
    if (kop_dm_v2_writes($pdo)) {
        $names = kop_v2_pdo_names_by_id($pdo, kop_dm_v2_prefix($pdo), [$facilityId]);
        return $names[$facilityId] ?? null;
    }
    $stmt = $pdo->prepare("SELECT unique_name FROM facilities_master WHERE id = ? LIMIT 1");
    $stmt->execute([$facilityId]);
    $name = $stmt->fetchColumn();
    return $name === false ? null : (string)$name;
}

// ---------------------------------------------------------------------------
// Designations: each facility record, programs and their homes
// (inc/program-homes.php), young adult programs (inc/young-adult-programs.php)
// and Indian boarding schools (inc/indigenous-schools.php). The last two are
// their own tables, never facility records.
// ---------------------------------------------------------------------------

/** Categories that are not one of the legacy master tables, with their row kind. */
$EXTRA_CATEGORIES = [
    'facilities'         => 'facility',
    'program_homes'      => 'facility',
    'young_adult'        => 'young_adult',
    'indigenous_schools' => 'indigenous_school',
];

/** facilities_v2 id => name. */
function kop_dm_v2_names(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    $rows = $pdo->query('SELECT id, name, unique_name FROM facilities_v2 WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[(int)$r['id']] = (string)($r['name'] !== '' && $r['name'] !== null ? $r['name'] : $r['unique_name']);
    }
    return $out;
}

function kop_dm_homes_ready(): bool {
    return function_exists('kop_program_homes_ready') && kop_program_homes_ready();
}

/**
 * What a facility record is in Program Homes: {role: 'home'|'program'|'',
 * program_id, program_name, homes: [{id, name}]}.
 */
function kop_dm_home_role(PDO $pdo, int $fid): array {
    $out = ['role' => '', 'program_id' => null, 'program_name' => '', 'homes' => []];
    if (!kop_dm_homes_ready()) return $out;
    if ($in = kop_program_homes_program_of($fid)) {
        $out['role'] = 'home';
        $out['program_id'] = (int)$in[0];
        $out['program_name'] = kop_dm_v2_names($pdo, [(int)$in[0]])[(int)$in[0]] ?? ('#' . (int)$in[0]);
    } elseif ($homes = kop_program_homes_homes_of($fid)) {
        $out['role'] = 'program';
        $names = kop_dm_v2_names($pdo, $homes);
        foreach ($homes as $h) $out['homes'][] = ['id' => (int)$h, 'name' => $names[(int)$h] ?? ('#' . (int)$h)];
    }
    return $out;
}

/** "Home of X" / "Program: N homes" / '' for a list row. */
function kop_dm_designation_label(array $role): string {
    if ($role['role'] === 'home') return 'Home of ' . $role['program_name'];
    if ($role['role'] === 'program') return 'Program: ' . count($role['homes']) . ' home' . (count($role['homes']) === 1 ? '' : 's');
    return '';
}

function kop_dm_facility_url(int $fid): string {
    return function_exists('kop_facility_page_url') ? (string)kop_facility_page_url($fid) : '';
}

/**
 * List rows for facilities_v2 records matching $q (name, unique name, or a
 * past/other name); $homesOnly keeps programs and their homes. The heavier
 * fields (folder, companies, designation) are filled for one page only by
 * kop_dm_enrich_facility_items().
 */
function kop_dm_facility_items(PDO $pdo, string $q, bool $homesOnly, array $filters = []): array {
    $sql = 'SELECT id, unique_name, name, city, state, country, status, facility_type, start_year, end_year' . ($q !== '' ? ', json_data' : '') . ' FROM facilities_v2';
    $params = [];
    $where = [];
    if ($q !== '') {
        $where[] = '(name LIKE ? OR unique_name LIKE ? OR json_data LIKE ?)';
        $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    if ($filters && ($f = kop_dm_filter_sql($filters, $params)) !== '') $where[] = $f;
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $keep = null;
    if ($homesOnly) {
        $keep = [];
        if (kop_dm_homes_ready()) {
            $map = kop_program_homes_map();
            foreach (array_keys($map['homes']) as $id) $keep[(int)$id] = true;
            foreach (array_keys($map['programs']) as $id) $keep[(int)$id] = true;
        }
    }

    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$r['id'];
        if ($keep !== null && !isset($keep[$id])) continue;
        $name = (string)($r['name'] !== '' && $r['name'] !== null ? $r['name'] : $r['unique_name']);
        $aka = null;
        if ($q !== '' && mb_stripos($name, $q) === false && mb_stripos((string)$r['unique_name'], $q) === false) {
            $doc = json_decode((string)$r['json_data'], true);
            $aka = is_array($doc) ? kop_dm_alias_hit(['facilities' => [$doc]], $q) : null;
            if (!$aka) continue; // the phrase was only in other text
        }
        $items[] = [
            'id'                 => $id,
            'unique_name'        => (string)$r['unique_name'],
            'category'           => 'facilities',
            'kind'               => 'facility',
            'table'              => 'facilities_v2',
            'display_name'       => $name,
            'place'              => trim(implode(', ', array_filter([(string)$r['city'], (string)($r['state'] ?: $r['country'])]))),
            'status'             => (string)$r['status'],
            'years'              => kop_dm_years($r['start_year'], $r['end_year']),
            'facility_type'      => (string)$r['facility_type'],
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'matched_name'       => $aka ? $aka[0] : null,
            'matched_kind'       => $aka ? $aka[1] : null,
        ];
    }
    return $items;
}

/** Folder, companies, designation and page link for the facility rows of one page. */
function kop_dm_enrich_facility_items(PDO $pdo, array &$items): void {
    $ids = [];
    foreach ($items as $it) {
        if (($it['kind'] ?? '') === 'facility') $ids[] = (int)$it['id'];
    }
    if (!$ids) return;
    $in = implode(',', $ids);
    $folders = [];
    foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $doc = json_decode((string)$r['json_data'], true);
        if (is_array($doc) && !empty($doc['documentFolderId'])) $folders[(int)$r['id']] = (int)$doc['documentFolderId'];
    }
    $companies = [];
    try {
        $t = kop_migration_tables(kop_dm_v2_prefix($pdo));
        $rows = $pdo->query("SELECT j.facility_id, o.name, o.unique_name FROM `{$t['operator_facilities']}` j
                              JOIN `{$t['operators']}` o ON o.id = j.operator_id
                             WHERE j.facility_id IN ($in) ORDER BY j.sort_order")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $companies[(int)$r['facility_id']][] = (string)($r['name'] ?: $r['unique_name']);
        }
    } catch (PDOException $e) { /* operator tables missing */ }
    foreach ($items as &$it) {
        if (($it['kind'] ?? '') !== 'facility') continue;
        $id = (int)$it['id'];
        $it['document_folder_id'] = $folders[$id] ?? null;
        $it['companies'] = $companies[$id] ?? [];
        $role = kop_dm_home_role($pdo, $id);
        $it['home_role'] = $role['role'];
        $it['designation'] = kop_dm_designation_label($role);
        $it['page_url'] = kop_dm_facility_url($id);
    }
    unset($it);
}

/** List rows for young adult programs or Indian boarding schools matching $q. */
function kop_dm_side_items(string $kind, string $q): array {
    if ($kind === 'young_adult') {
        if (!function_exists('kop_ya_all') || !($xpdo = kop_ya_pdo())) return [];
        $rows = kop_ya_all($xpdo, null);
        $category = 'young_adult';
        $admin = function_exists('kop_ya_admin_url') ? kop_ya_admin_url() : admin_url('admin.php?page=kop-young-adult-programs');
        $page = defined('KOP_YA_PAGE') ? home_url('/' . KOP_YA_PAGE . '/') : '';
    } else {
        if (!function_exists('kop_ischools_all') || !($xpdo = kop_ischools_pdo())) return [];
        $rows = kop_ischools_all($xpdo, null);
        $category = 'indigenous_schools';
        $admin = admin_url('admin.php?page=' . (defined('KOP_ISCHOOLS_ADMIN_PAGE') ? KOP_ISCHOOLS_ADMIN_PAGE : 'kop-indigenous-schools'));
        $page = defined('KOP_ISCHOOLS_PAGE') ? home_url('/' . KOP_ISCHOOLS_PAGE . '/') : '';
    }
    $items = [];
    foreach ($rows as $r) {
        $aka = null;
        if ($q !== '' && mb_stripos((string)$r['name'], $q) === false) {
            foreach (preg_split('/\r\n|\r|\n/', (string)($r['other_names'] ?? '')) as $n) {
                if (trim($n) !== '' && mb_stripos($n, $q) !== false) { $aka = [trim($n), 'other']; break; }
            }
            if (!$aka) continue;
        }
        $region = (string)($r['state'] ?? $r['region'] ?? '');
        $items[] = [
            'id'                 => (int)$r['id'],
            'unique_name'        => (string)$r['name'],
            'category'           => $category,
            'kind'               => $kind,
            'table'              => $kind === 'young_adult' ? 'young_adult_programs' : 'indigenous_schools',
            'display_name'       => (string)$r['name'],
            'place'              => trim(implode(', ', array_filter([(string)$r['city'], $region ?: (string)$r['country']]))),
            'status'             => (string)$r['status'],
            'review'             => (string)$r['review'],
            'designation'        => $r['review'] === 'pending' ? 'Waiting for review' : '',
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'admin_url'          => $admin,
            'page_url'           => $r['review'] === 'approved' ? $page : '',
            'matched_name'       => $aka ? $aka[0] : null,
            'matched_kind'       => $aka ? $aka[1] : null,
        ];
    }
    return $items;
}

// ---------------------------------------------------------------------------
// People (inc/people.php): one id per person named on a staff list. The
// actions are the People screen's own (kop_people_save_details(),
// kop_pmerge_do_merge() with its Undo, kop_people_separate()).
// ---------------------------------------------------------------------------

function kop_dm_people_ready(): bool {
    return function_exists('kop_people_load') && function_exists('kop_people_save_details') && get_option('kop_people_db');
}

/** List rows for people (not merged away) matching $q: name, other name, or "#id". */
function kop_dm_people_items(string $q): array {
    if (!kop_dm_people_ready()) return [];
    $t = kop_people_table('people');
    $r = kop_people_table('roles');
    $where = 'p.merged_into IS NULL';
    $params = [];
    if ($q !== '' && ctype_digit(ltrim($q, '#'))) {
        $where .= ' AND p.id = ?';
        $params[] = (int)ltrim($q, '#');
    } elseif ($q !== '') {
        $where .= ' AND (p.name LIKE ? OR p.aliases LIKE ?)';
        $like = '%' . $GLOBALS['wpdb']->esc_like($q) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $rows = kop_facility_db_rows("SELECT p.id, p.name, p.aliases, COUNT(DISTINCT CONCAT(r.record_kind, r.record_id)) AS records
        FROM {$t} p LEFT JOIN {$r} r ON r.person_id = p.id WHERE {$where} GROUP BY p.id", $params);
    $items = [];
    foreach ($rows as $p) {
        $aliases = kop_people_alias_list($p['aliases']);
        $aka = null;
        if ($q !== '' && !ctype_digit(ltrim($q, '#')) && mb_stripos((string)$p['name'], $q) === false) {
            foreach ($aliases as $a) {
                if (mb_stripos($a, $q) !== false) { $aka = [$a, 'other']; break; }
            }
        }
        $n = (int)$p['records'];
        $items[] = [
            'id'                 => (int)$p['id'],
            'unique_name'        => (string)$p['name'],
            'category'           => 'people',
            'kind'               => 'person',
            'table'              => $t,
            'display_name'       => (string)$p['name'],
            'aliases'            => $aliases,
            'record_count'       => $n,
            'designation'        => $n ? '' : 'Not named on any record',
            'facility_count'     => 0,
            'document_folder_id' => null,
            'is_stub'            => false,
            'admin_url'          => kop_people_admin_url(['person' => (int)$p['id']]),
            'matched_name'       => $aka ? $aka[0] : null,
            'matched_kind'       => $aka ? $aka[1] : null,
        ];
    }
    return $items;
}

/** One person for the Data Manager's modal: details, where named, likely same-person picks. */
function kop_dm_person_detail(int $id): array {
    $state = kop_people_load();
    if (!isset($state['rows'][$id])) throw new RuntimeException('There is no person #' . $id . '.');
    $resolved = kop_people_resolve($state, $id);
    if ($resolved !== $id) throw new RuntimeException('Person #' . $id . ' was joined into person #' . $resolved . '.');
    $p = $state['rows'][$id];
    $labels = ['administrator' => 'Administrator', 'notableStaff' => 'Staff', 'founders' => 'Founder', 'keyExecutives' => 'Executive', 'ceo' => 'CEO', 'map' => 'Person on the map'];
    $roles = kop_people_roles_of($id);
    $out = [];
    foreach ($roles as $r) {
        $rec = kop_people_admin_record($r['record_kind'], $r['record_id']);
        $isMap = $r['record_kind'] === 'map';
        if ($isMap && $rec['url'] !== '') $rec['url'] .= '#open=' . rawurlencode((string)$r['ref']);
        $out[] = [
            'record_kind' => (string)$r['record_kind'],
            'record_id'   => (int)$r['record_id'],
            'record_name' => (string)$rec['name'],
            'record_url'  => (string)$rec['url'],
            'what'        => $isMap ? 'node ' . $r['ref'] : ($r['record_kind'] === 'operator' ? 'company #' : 'facility #') . $r['record_id'],
            'list'        => (string)$r['list'],
            'list_label'  => $labels[$r['list']] ?? (string)$r['list'],
            'position'    => (int)$r['position'],
            'written_as'  => (string)$r['name'],
            'role'        => (string)$r['role'],
            'can_separate' => $r['record_kind'] === 'facility' && count($roles) > 1,
        ];
    }
    $similar = [];
    foreach (kop_people_similar($state, $id) as $o) $similar[] = ['id' => (int)$o['id'], 'name' => (string)$o['name']];
    return [
        'id'        => $id,
        'name'      => (string)$p['name'],
        'aliases'   => (string)$p['aliases'],
        'notes'     => (string)$p['notes'],
        'roles'     => $out,
        'similar'   => $similar,
        'admin_url' => kop_people_admin_url(['person' => $id]),
        'merge_url' => admin_url('admin.php?page=kop-merge-people'),
    ];
}

/** Who made a change, for the modules that keep it. */
function kop_dm_by(): string {
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return ($u && !empty($u->user_login)) ? (string)$u->user_login : 'data-manager';
}

/** The name a home goes by on its program's page: the part after "Program – " when the names match. */
function kop_dm_home_name(string $name, string $programName): string {
    $split = kop_program_homes_split_name($name);
    if ($split[1] !== '' && kop_program_homes_key($split[0]) === kop_program_homes_key($programName)) return $split[1];
    return trim($name);
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

            // Pre-aggregate UNLINKED wiki entries by organization name. These are
            // the "no-link fallback" cases: a wiki entry name-matches a program
            // but isn't explicitly linked, so we flag it for review/linking.
            $nameUnlinked = [];
            // Submissions carry a status; exclude rejected/deleted noise.
            try {
                $stmt = $pdo->query(
                    "SELECT LOWER(organization) AS org, COUNT(*) AS c FROM wiki_submissions
                     WHERE organization IS NOT NULL AND organization != ''
                       AND (facility_unique_name IS NULL OR facility_unique_name = '')
                       AND status NOT IN ('rejected','deleted')
                     GROUP BY LOWER(organization)"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nameUnlinked[$r['org']] = ($nameUnlinked[$r['org']] ?? 0) + (int)$r['c'];
                }
            } catch (PDOException $e) { /* table/column missing */ }
            // Master may not have a status column.
            try {
                $stmt = $pdo->query(
                    "SELECT LOWER(organization) AS org, COUNT(*) AS c FROM wiki_master
                     WHERE organization IS NOT NULL AND organization != ''
                       AND (facility_unique_name IS NULL OR facility_unique_name = '')
                     GROUP BY LOWER(organization)"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nameUnlinked[$r['org']] = ($nameUnlinked[$r['org']] ?? 0) + (int)$r['c'];
                }
            } catch (PDOException $e) { /* table/column missing */ }

            $filters = kop_dm_filters();
            $filtered = kop_dm_filters_set($filters);
            $recordKind = kop_dm_record_kind_of_category($category);
            if ($filtered || $recordKind !== '' || in_array($category, ['people', 'merged', 'converted'], true)
                || ($category !== '' && isset($EXTRA_CATEGORIES[$category]))) {
                // Filters are on facility records: only they are listed.
                $tables = [];
            } elseif ($category !== '' && isset($CATEGORY_TABLE[$category])) {
                $tables = [$category => $CATEGORY_TABLE[$category]];
            } else {
                $tables = $CATEGORY_TABLE;
            }

            $items = [];
            // Each facility record (or only programs and their homes), then the
            // two kinds that live outside the facility tables.
            if ($category === '' || $category === 'facilities' || $category === 'program_homes') {
                $items = kop_dm_facility_items($pdo, $q, $category === 'program_homes', $filters);
            }
            if (!$filtered) {
                if ($category === '' || $category === 'young_adult') {
                    $items = array_merge($items, kop_dm_side_items('young_adult', $q));
                }
                if ($category === '' || $category === 'indigenous_schools') {
                    $items = array_merge($items, kop_dm_side_items('indigenous_school', $q));
                }
                // People and news/lawsuits/bills join "All categories" only for a
                // search: thousands of rows would bury the records otherwise.
                if ($category === 'people' || ($category === '' && $q !== '')) {
                    $items = array_merge($items, kop_dm_people_items($q));
                }
                foreach (array_keys(kop_dm_record_kinds()) as $rk) {
                    if ($recordKind === $rk || ($category === '' && $q !== '')) {
                        $newsKind = $rk === 'news' && in_array($category, ['lawsuit_news', 'legislation_news'], true) ? substr($category, 0, -5) : '';
                        $items = array_merge($items, kop_dm_record_items($pdo, $rk, $q, $newsKind));
                    }
                }
                if ($category === 'merged' || ($category === '' && $q !== '')) {
                    $items = array_merge($items, kop_dm_merged_items($q));
                }
                if ($category === 'converted' || ($category === '' && $q !== '')) {
                    $items = array_merge($items, kop_dm_converted_items($pdo, $q));
                }
            }
            foreach ($items as &$it) {
                if (!in_array($it['kind'], ['facility', 'young_adult', 'indigenous_school'], true)) {
                    $it['wiki_links'] = ['suggested' => 0, 'confirmed' => 0, 'total' => 0];
                    $it['name_match_unlinked'] = 0;
                    continue;
                }
                $it['wiki_links'] = $wikiCounts[$it['unique_name']] ?? ['suggested' => 0, 'confirmed' => 0, 'total' => 0];
                $it['name_match_unlinked'] = $it['kind'] === 'facility' ? ($nameUnlinked[strtolower($it['unique_name'])] ?? 0) : 0;
            }
            unset($it);

            foreach ($tables as $cat => $table) {
                if ($table === 'facilities_master' && kop_dm_v2_writes($pdo)) {
                    $rows = kop_dm_master_rows($pdo);
                    if ($q !== '') {
                        // Past and other names find a record too.
                        $rows = array_values(array_filter($rows, static function ($row) use ($q) {
                            return mb_stripos($row['unique_name'], $q) !== false
                                || (mb_stripos((string)$row['json_data'], $q) !== false && kop_dm_alias_hit(kop_dm_decode($row['json_data']), $q));
                        }));
                    }
                } else {
                    $sql = "SELECT id, unique_name, json_data, updated_at FROM `$table`";
                    $params = [];
                    if ($q !== '') {
                        // json_data too, for past/other names (checked below).
                        $sql .= " WHERE unique_name LIKE ? OR json_data LIKE ?";
                        $params[] = '%' . $q . '%';
                        $params[] = '%' . $q . '%';
                    }
                    $sql .= " ORDER BY unique_name ASC";
                    try {
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                    } catch (PDOException $e) {
                        continue; // table missing
                    }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                foreach ($rows as $row) {
                    $project = kop_dm_decode($row['json_data']);
                    if (!empty($project['__facility_ref'])) continue; // hidden id rows
                    $aka = null;
                    if ($q !== '' && mb_stripos($row['unique_name'], $q) === false) {
                        $aka = kop_dm_alias_hit($project, $q);
                        if (!$aka) continue; // the phrase was only in other text
                    }

                    $meta = kop_dm_describe($project, $table);
                    $un = $row['unique_name'];
                    $items[] = [
                        'id'                 => (int)$row['id'],
                        'unique_name'        => $un,
                        'category'           => $cat,
                        'kind'               => $table === 'facilities_master' ? 'operator' : 'legacy',
                        'table'              => $table,
                        'display_name'       => $meta['display_name'] ?: $un,
                        'facility_count'     => $meta['facility_count'],
                        'document_folder_id' => $meta['document_folder_id'],
                        'is_stub'            => $meta['is_stub'],
                        'wiki_links'         => $wikiCounts[$un] ?? ['suggested' => 0, 'confirmed' => 0, 'total' => 0],
                        'name_match_unlinked' => $nameUnlinked[strtolower($un)] ?? 0,
                        'updated_at'         => $row['updated_at'] ?? null,
                        'matched_name'       => $aka ? $aka[0] : null,
                        'matched_kind'       => $aka ? $aka[1] : null,
                    ];
                }
            }

            // Stable sort by name then paginate in PHP.
            usort($items, fn($a, $b) => strcasecmp($a['display_name'] ?: $a['unique_name'], $b['display_name'] ?: $b['unique_name']));
            $total = count($items);
            $page  = array_slice($items, $offset, $limit);
            kop_dm_enrich_facility_items($pdo, $page);

            echo json_encode([
                'success' => true,
                'data'    => $page,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ]);
            exit;
        }

        // ---- filters, record_detail, company_convert, file_options (data-manager-extra.php) ----
        try {
            $extra = kop_dm_extra_get($pdo, $action);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        if ($extra !== null) {
            echo json_encode($extra);
            exit;
        }

        // ---- get_person ----
        if ($action === 'get_person') {
            try {
                if (!kop_dm_people_ready()) throw new RuntimeException('People is not set up on this site.');
                echo json_encode(['success' => true, 'person' => kop_dm_person_detail((int)($_GET['id'] ?? 0))]);
            } catch (RuntimeException $e) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
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
            if ($facIds && kop_dm_v2_writes($pdo)) {
                $idToUnique = kop_v2_pdo_names_by_id($pdo, kop_dm_v2_prefix($pdo), $facIds);
            } elseif ($facIds) {
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
                $role = $fid ? kop_dm_home_role($pdo, $fid) : ['role' => '', 'homes' => []];
                $out[] = [
                    'home_role'            => $role['role'],
                    'designation'          => kop_dm_designation_label($role + ['program_name' => '']),
                    'page_url'             => $fid ? kop_dm_facility_url($fid) : '',
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

        // ---- get_designation ----
        // What one facility record is now: its own program, a home of a
        // program (which), or a program with homes (which).
        if ($action === 'get_designation') {
            $fid = (int)($_GET['facility_id'] ?? 0);
            $names = kop_dm_v2_names($pdo, [$fid]);
            if (!isset($names[$fid])) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'There is no facility record #' . $fid . '.']);
                exit;
            }
            $role = kop_dm_home_role($pdo, $fid);
            echo json_encode(['success' => true, 'facility_id' => $fid, 'name' => $names[$fid],
                'homes_available' => kop_dm_homes_ready(),
                'young_adult_available' => function_exists('kop_ya_move_facility'),
                'schools_available' => function_exists('kop_ischools_move_facility'),
                'page_url' => kop_dm_facility_url($fid)] + $role);
            exit;
        }

        // ---- find_facility ----
        // Facility records by name, past/other name or id (the admin finder's
        // search), for picking a program record.
        if ($action === 'find_facility') {
            $results = function_exists('kop_facility_finder_search')
                ? kop_facility_finder_search($pdo, (string)($_GET['q'] ?? ''), 12) : [];
            foreach ($results as &$r) {
                $role = kop_dm_home_role($pdo, (int)$r['id']);
                $r['designation'] = kop_dm_designation_label($role);
                $r['home_role'] = $role['role'];
            }
            unset($r);
            echo json_encode(['success' => true, 'results' => $results]);
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

    // ---- People: person_save {id, name, aliases, notes}, person_merge {id,
    // into: "#id" or an exact name}, person_undo_merge {log}, person_separate
    // {id, facility_id, list, position}. The People screen's own functions;
    // they keep their own tables, so no v2 re-derive.
    if (in_array($action, ['person_save', 'person_merge', 'person_undo_merge', 'person_separate'], true)) {
        try {
            if (!kop_dm_people_ready()) throw new RuntimeException('People is not set up on this site.');
            $pid = (int)($input['id'] ?? 0);
            $result = ['success' => true];
            if ($action === 'person_save') {
                $err = kop_people_save_details($pid, (string)($input['name'] ?? ''), (string)($input['aliases'] ?? ''), (string)($input['notes'] ?? ''));
                if ($err !== '') throw new RuntimeException($err);
                $result['message'] = 'Saved.';
                $result['person'] = kop_dm_person_detail($pid);
            } elseif ($action === 'person_merge') {
                $into = kop_people_admin_find((string)($input['into'] ?? ''), $pid);
                if (is_string($into)) throw new RuntimeException($into);
                $result['message'] = kop_pmerge_do_merge($into, $pid, kop_dm_by());
                $log = kop_pmerge_log();
                $result['undo_log'] = (string)($log[0]['id'] ?? '');
                $result['into'] = (int)$into;
            } elseif ($action === 'person_undo_merge') {
                $result['message'] = kop_pmerge_do_undo((string)($input['log'] ?? ''));
            } else {
                $new = kop_people_separate((int)($input['facility_id'] ?? 0), (string)($input['list'] ?? ''), (int)($input['position'] ?? 0));
                if (!$new) throw new RuntimeException('That entry is not there any more. Refresh and try again.');
                kop_people_sync();
                $result['message'] = 'That entry is now its own person (#' . $new . '), apart from #' . $pid . '.';
                $result['new_id'] = $new;
                $result['person'] = kop_dm_person_detail($pid);
            }
            echo json_encode($result);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Merges, conversions, record links, filing news (data-manager-extra.php):
    // each through its own module, so no v2 re-derive either.
    try {
        $extra = kop_dm_extra_post($pdo, $action, $input);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    if ($extra !== null) {
        echo json_encode($extra);
        exit;
    }

    // Every other POST action edits the legacy tables; re-derive v2 after the request.
    if (function_exists('kop_facility_v2_request_sync')) {
        kop_facility_v2_request_sync();
    }

    // ---- set_designation ----
    // {facility_id, designation: home|not_home|young_adult|indigenous_school,
    //  program_id?, program_name?}. A home keeps its own record and is listed
    // under a program (Program Homes, with an Undo here); the other two move
    // the record out of the facility tables through their own modules
    // (kop_ya_move_facility(), kop_ischools_move_facility()).
    if ($action === 'set_designation') {
        $fid = (int)($input['facility_id'] ?? 0);
        $to = (string)($input['designation'] ?? '');
        $name = kop_dm_v2_names($pdo, [$fid])[$fid] ?? '';
        if ($name === '') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'There is no facility record #' . $fid . '.']);
            exit;
        }
        try {
            $role = kop_dm_home_role($pdo, $fid);
            if (in_array($to, ['home', 'young_adult', 'indigenous_school'], true) && $role['role'] === 'program') {
                throw new RuntimeException($name . ' is a program with ' . count($role['homes']) . ' home(s) listed under it. Take its homes off first (KOP Tools > Program Homes).');
            }
            $result = ['success' => true, 'facility_id' => $fid];

            if ($to === 'home') {
                if (!kop_dm_homes_ready()) throw new RuntimeException('Program Homes is not set up on this site.');
                kop_program_homes_install();
                $pid = (int)($input['program_id'] ?? 0);
                $pname = trim((string)($input['program_name'] ?? ''));
                if ($pid === $fid) throw new RuntimeException('Pick the program\'s own record, not this one.');
                if ($pid > 0) {
                    $pname = kop_dm_v2_names($pdo, [$pid])[$pid] ?? '';
                    if ($pname === '') throw new RuntimeException('Program record #' . $pid . ' is not on file.');
                    if (kop_program_homes_program_of($pid)) throw new RuntimeException($pname . ' is itself a home of a program. Pick that program instead.');
                } elseif ($pname === '') {
                    throw new RuntimeException('Pick the program\'s record, or type a name for a new one.');
                }
                // A new name can still find an existing record, so note every group first.
                global $wpdb;
                $groups = array_map('intval', (array)$wpdb->get_col('SELECT program_id FROM ' . kop_program_homes_table('groups')));
                $was = kop_program_homes_program_of($fid);
                $pid = (int)kop_program_homes_group([$fid => kop_dm_home_name($name, $pname)], $pid, $pname, kop_program_homes_opts());
                $pname = kop_dm_v2_names($pdo, [$pid])[$pid] ?? $pname;
                $result['message'] = $name . ' is now listed as a home of ' . $pname . ' (record #' . $pid . ').';
                $result['undo'] = ['facility_id' => $fid, 'program_id' => $pid,
                    'before' => $was ? (int)$was[0] : 0, 'before_name' => $was ? (string)$was[1] : '',
                    'new_group' => !in_array($pid, $groups, true)];
            } elseif ($to === 'not_home') {
                if ($role['role'] !== 'home') throw new RuntimeException($name . ' is not a home of any program.');
                $was = kop_program_homes_program_of($fid);
                kop_program_homes_remove_home($fid);
                $result['message'] = $name . ' is no longer listed as a home of ' . $role['program_name'] . '. It stays its own record.';
                $result['undo'] = ['facility_id' => $fid, 'program_id' => (int)$was[0], 'before' => (int)$was[0], 'before_name' => (string)$was[1]];
            } elseif ($to === 'young_adult') {
                if (!function_exists('kop_ya_move_facility')) throw new RuntimeException('Young Adult Programs is not set up on this site.');
                $ya = kop_ya_pdo();
                kop_ya_install($ya);
                $yid = kop_ya_move_facility($ya, $fid, kop_dm_by());
                $p = kop_ya_get($ya, $yid);
                $result['message'] = $name . ' is now the young adult program "' . ($p['name'] ?? $name) . '" and no longer a facility record. Its details are at KOP Tools > Young Adult Programs.';
                $result['moved'] = ['kind' => 'young_adult', 'id' => $yid];
            } elseif ($to === 'indigenous_school') {
                if (!function_exists('kop_ischools_move_facility')) throw new RuntimeException('Indigenous Schools is not set up on this site.');
                $is = kop_ischools_pdo();
                kop_ischools_install($is);
                $sid = kop_ischools_move_facility($is, $fid, kop_dm_by());
                $s = kop_ischools_get($is, $sid);
                $result['message'] = $name . ' is now the school "' . ($s['name'] ?? $name) . '" and no longer a facility record. Its details are at KOP Tools > Indigenous Schools.';
                $result['moved'] = ['kind' => 'indigenous_school', 'id' => $sid];
            } else {
                throw new RuntimeException('Unknown designation "' . $to . '".');
            }
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode($result);
        exit;
    }

    // ---- undo_home ----
    // Puts a home link back as it was before set_designation home/not_home:
    // under its earlier program, or on its own; a program record that change
    // made goes too when nothing else uses it (kop_program_homes_undo()).
    if ($action === 'undo_home') {
        $fid = (int)($input['facility_id'] ?? 0);
        $pid = (int)($input['program_id'] ?? 0);
        $before = (int)($input['before'] ?? 0);
        try {
            if ($fid <= 0 || $pid <= 0 || !kop_dm_homes_ready()) throw new RuntimeException('Nothing to undo.');
            $name = kop_dm_v2_names($pdo, [$fid])[$fid] ?? ('#' . $fid);
            $now = kop_program_homes_program_of($fid);
            if ($before > 0 && kop_dm_v2_names($pdo, [$before])) {
                kop_program_homes_group([$fid => (string)($input['before_name'] ?? '')], $before, '', kop_program_homes_opts());
            } elseif ($now && (int)$now[0] === $pid) {
                kop_program_homes_remove_home($fid);
            }
            $gone = '';
            if (!empty($input['new_group']) && !kop_program_homes_homes_of($pid)) {
                $gone = kop_program_homes_undo($pid) === 'removed' ? ' The program record it made is deleted.' : '';
            }
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true,
            'message' => 'Undone. ' . $name . ($before > 0 ? ' is back under its earlier program.' : ' is on its own again.') . $gone]);
        exit;
    }

    // ---- rename_record / set_record_doc_folder ----
    // For rows that are not operator projects: one facility record
    // (facilities_v2), a young adult program or an Indian boarding school.
    if ($action === 'rename_record' || $action === 'set_record_doc_folder') {
        $kind = (string)($input['kind'] ?? '');
        $id = (int)($input['id'] ?? 0);
        try {
            if ($id <= 0) throw new RuntimeException('Which record?');
            if ($action === 'set_record_doc_folder' && $kind !== 'facility') throw new RuntimeException('Only facility records have a document folder here.');
            $newName = trim((string)($input['new_name'] ?? ''));
            if ($action === 'rename_record' && $newName === '') throw new RuntimeException('Type the new name.');

            if ($kind === 'facility') {
                $prefix = kop_dm_v2_prefix($pdo);
                $folderRaw = $input['document_folder_id'] ?? null;
                kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $id, $action, $newName, $folderRaw) {
                    $stored = kop_facility_load($id, ['pdo' => $pdo, 'prefix' => $prefix]);
                    if (!$stored) throw new RuntimeException('There is no facility record #' . $id . '.');
                    $doc = $stored['doc'];
                    if ($action === 'rename_record') {
                        $doc['identification']['name'] = $newName;
                    } else {
                        $doc['documentFolderId'] = ($folderRaw !== null && $folderRaw !== '' && (int)$folderRaw > 0) ? (int)$folderRaw : null;
                    }
                    kop_facility_save($doc, ['pdo' => $pdo, 'prefix' => $prefix, 'skip_memberships' => true]);
                });
                if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
            } elseif ($kind === 'young_adult' || $kind === 'indigenous_school') {
                $ya = $kind === 'young_adult';
                $xpdo = $ya ? kop_ya_pdo() : kop_ischools_pdo();
                $row = $ya ? kop_ya_get($xpdo, $id) : kop_ischools_get($xpdo, $id);
                if (!$row) throw new RuntimeException('That record is gone.');
                // The save functions write every column, so start from what is stored.
                $row['name'] = $newName;
                $ya ? kop_ya_save($xpdo, $row, $id, kop_dm_by()) : kop_ischools_save($xpdo, $row, $id, kop_dm_by());
            } else {
                throw new RuntimeException('Unknown record kind "' . $kind . '".');
            }
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true,
            'message' => $action === 'rename_record' ? 'Renamed to "' . $newName . '".' : 'Document folder updated.']);
        exit;
    }

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
        if (kop_dm_v2_writes($pdo) && $targetTable === 'facilities_master') {
            $collides = kop_v2_name_taken($pdo, kop_dm_v2_prefix($pdo), $uniqueName);
        } else {
            $collide = $pdo->prepare("SELECT 1 FROM `$targetTable` WHERE unique_name = ? LIMIT 1");
            $collide->execute([$uniqueName]);
            $collides = (bool)$collide->fetchColumn();
        }
        if ($collides) {
            http_response_code(409);
            echo json_encode(['success' => false,
                'error' => "A record named '$uniqueName' already exists in the target category. Rename one first."]);
            exit;
        }

        // Transporter and provider tables are created on first use. Do it
        // before the transaction: CREATE TABLE commits implicitly.
        if (in_array($targetTable, ['transporters_master', 'providers_master'], true)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `$targetTable` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                unique_name VARCHAR(255) NOT NULL,
                json_data LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_name (unique_name),
                KEY updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Update the embedded category label, then move the row atomically.
        $project = kop_dm_decode($rec['json_data']);
        $project['category'] = $target;
        $newJson = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            if (kop_dm_v2_writes($pdo) && $targetTable === 'facilities_master') {
                kop_v2_save_legacy_row($pdo, kop_dm_v2_prefix($pdo), $uniqueName, $project);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO `$targetTable` (unique_name, json_data, created_at, updated_at)
                     VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                $ins->execute([$uniqueName, $newJson]);
            }

            if (kop_dm_v2_writes($pdo) && $sourceTable === 'facilities_master') {
                // The project leaves the facility index; its facilities stay.
                kop_v2_delete_form_project($pdo, kop_dm_v2_prefix($pdo), $uniqueName, false);
            } else {
                $del = $pdo->prepare("DELETE FROM `$sourceTable` WHERE id = ?");
                $del->execute([$rec['id']]);
            }

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
            kop_dm_save_project($pdo, $srcRec, $srcProject);
            kop_dm_save_project($pdo, $dstRec, $dstProject);
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
        if (kop_dm_v2_writes($pdo) && $table === 'facilities_master') {
            $taken = kop_v2_name_taken($pdo, kop_dm_v2_prefix($pdo), $newName);
        } else {
            $collide = $pdo->prepare("SELECT 1 FROM `$table` WHERE unique_name = ? LIMIT 1");
            $collide->execute([$newName]);
            $taken = (bool)$collide->fetchColumn();
        }
        if ($taken) {
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
            if (kop_dm_v2_writes($pdo) && $table === 'facilities_master') {
                // Renames the operator project and the facilities that name it
                // as their source project.
                $renamed = kop_v2_rename_operator($pdo, kop_dm_v2_prefix($pdo), $uniqueName, $newName);
                if (empty($renamed['renamed'])) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => $renamed['error'] ?? 'Rename failed']);
                    exit;
                }
            } else {
                $upd = $pdo->prepare("UPDATE `$table` SET unique_name = ?, json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$newName, $newJson, $rec['id']]);
            }

            // Repoint wiki links that referenced the old unique_name.
            foreach (['wiki_submissions', 'wiki_master'] as $wTable) {
                try {
                    $w = $pdo->prepare("UPDATE `$wTable` SET facility_unique_name = ? WHERE facility_unique_name = ?");
                    $w->execute([$newName, $uniqueName]);
                    $wikiUpdated += $w->rowCount();
                } catch (PDOException $e) { /* table/column missing */ }
            }

            // Best-effort: update sourceProject references inside location
            // aggregates. Under v2 the rename above already moved them.
            try {
                if (kop_dm_v2_writes($pdo)) throw new PDOException('handled by the v2 rename');
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
            if (kop_dm_v2_writes($pdo) && $table === 'facilities_master') {
                // The operator project goes; its facilities stay and keep
                // their state pages.
                $deleted = kop_v2_delete_form_project($pdo, kop_dm_v2_prefix($pdo), $uniqueName, false);
                if (empty($deleted['deleted'])) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => $deleted['error'] ?? 'Delete failed']);
                    exit;
                }
            } else {
                $del = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
                $del->execute([$rec['id']]);
            }

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
                kop_dm_save_project($pdo, $rec, $project);
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

        // Give the facility a stable id + unique_name if it has none. Under v2
        // saving the project assigns them; before that, promotion does.
        if (!$fid && kop_dm_v2_writes($pdo)) {
            $fid = kop_dm_assign_facility_id($pdo, $rec, $project, $path, $idx);
        } elseif (!$fid && function_exists('kop_promote_single_nested_facility')) {
            $fid = kop_promote_single_nested_facility($pdo, $facility);
            if ($fid) {
                $facs[$idx] = $facility; // promotion stamped facility_id
                kop_dm_set_facilities($project, $path, $facs);
                kop_dm_save_project($pdo, $rec, $project);
            }
        }
        if (!$fid) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'This facility has no name to create a linkable record from.']);
            exit;
        }

        $funique = kop_dm_facility_unique_name($pdo, (int)$fid);
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

    // ---- scrape ----
    // One-time bulk linker: walk every program (companies/referrers/transporters,
    // skipping location aggregates and hidden __facility_ref rows) in batches and
    //   • set a document folder where one is empty and a STRONG name match exists,
    //   • link every unlinked wiki entry whose organization equals the program's
    //     unique_name (as 'suggested', for review).
    // Never overwrites an existing folder; never steals an already-linked wiki
    // entry. Paged via offset/limit so it can't time out. Returns progress so the
    // client can loop until done.
    if ($action === 'scrape') {
        $offset    = max((int)($input['offset'] ?? 0), 0);
        $limit     = min(max((int)($input['limit'] ?? 50), 1), 200);
        $doFolders = !array_key_exists('do_folders', $input) || $input['do_folders'];
        $doWiki    = !array_key_exists('do_wiki', $input) || $input['do_wiki'];

        // Build the ordered work list (cheap: id + unique_name only).
        $work = [];
        foreach (['facilities_master', 'referrers_master', 'transporters_master'] as $table) {
            if ($table === 'facilities_master' && kop_dm_v2_writes($pdo)) {
                foreach (kop_dm_master_rows($pdo) as $r) {
                    $work[] = ['table' => $table, 'id' => (int)$r['id'], 'unique_name' => $r['unique_name'],
                               'json_data' => $r['json_data']];
                }
                continue;
            }
            try {
                $stmt = $pdo->query("SELECT id, unique_name FROM `$table` ORDER BY id ASC");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $work[] = ['table' => $table, 'id' => (int)$r['id'], 'unique_name' => $r['unique_name']];
                }
            } catch (PDOException $e) { /* table missing */ }
        }
        $total = count($work);
        $batch = array_slice($work, $offset, $limit);

        $foldersSet = 0;
        $wikiLinked = 0;
        $processed  = 0;

        foreach ($batch as $w) {
            $processed++;
            if (isset($w['json_data'])) {
                $json = $w['json_data'];
            } else {
                $sel = $pdo->prepare("SELECT json_data FROM `{$w['table']}` WHERE id = ?");
                $sel->execute([$w['id']]);
                $json = $sel->fetchColumn();
                if ($json === false) continue;
            }

            $project = kop_dm_decode($json);
            if (!empty($project['__facility_ref'])) continue; // hidden id rows

            $meta  = kop_dm_describe($project, $w['table']);
            $names = array_values(array_unique(array_filter([$meta['display_name'], $w['unique_name']])));

            // Folder (only when empty + strong match).
            if ($doFolders && empty($meta['document_folder_id']) && $names) {
                $folder = kop_dm_best_folder($names);
                if ($folder) {
                    if (!isset($project['data']) || !is_array($project['data'])) $project['data'] = [];
                    $project['documentFolderId'] = $folder['id'];
                    $project['data']['documentFolderId'] = $folder['id'];
                    kop_dm_save_project($pdo, $w, $project);
                    $foldersSet++;
                }
            }

            // Wiki: link all unlinked entries whose organization == this program.
            if ($doWiki) {
                try {
                    $u = $pdo->prepare(
                        "UPDATE wiki_submissions
                         SET facility_unique_name = ?, facility_link_status = 'suggested', updated_at = CURRENT_TIMESTAMP
                         WHERE LOWER(organization) = LOWER(?)
                           AND (facility_unique_name IS NULL OR facility_unique_name = '')
                           AND status NOT IN ('rejected','deleted')"
                    );
                    $u->execute([$w['unique_name'], $w['unique_name']]);
                    $wikiLinked += $u->rowCount();
                } catch (PDOException $e) { /* table/column missing */ }
                try {
                    $u = $pdo->prepare(
                        "UPDATE wiki_master
                         SET facility_unique_name = ?, facility_link_status = 'suggested', updated_at = CURRENT_TIMESTAMP
                         WHERE LOWER(organization) = LOWER(?)
                           AND (facility_unique_name IS NULL OR facility_unique_name = '')"
                    );
                    $u->execute([$w['unique_name'], $w['unique_name']]);
                    $wikiLinked += $u->rowCount();
                } catch (PDOException $e) { /* table/column missing */ }
            }
        }

        $nextOffset = $offset + $limit;
        echo json_encode([
            'success'     => true,
            'total'       => $total,
            'offset'      => $offset,
            'processed'   => $processed,
            'next_offset' => $nextOffset,
            'done'        => $nextOffset >= $total,
            'folders_set' => $foldersSet,
            'wiki_linked' => $wikiLinked,
        ]);
        exit;
    }

    // ---- auto_apply_facility ----
    // Strong-match auto-assign for ONE facility: a FileBird folder (set on the
    // facility) and a wiki link (to the facility's own record, promoting it if
    // needed). Never overwrites an existing folder or steals a linked wiki entry.
    if ($action === 'auto_apply_facility') {
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

        $name  = kop_dm_facility_name($facs[$idx]);
        $names = array_values(array_filter([$name]));
        $result = ['folder' => null, 'wiki' => null, 'notes' => []];
        $dirty = false;

        // Folder (only if empty).
        if (!empty($facs[$idx]['documentFolderId'])) {
            $result['notes'][] = 'Facility document folder already set — left as is.';
        } else {
            $folder = $names ? kop_dm_best_folder($names) : null;
            if ($folder) {
                $facs[$idx]['documentFolderId'] = $folder['id'];
                $result['folder'] = $folder;
                $dirty = true;
            } else {
                $result['notes'][] = 'No strong folder match found.';
            }
        }

        // Ensure the facility has a stable record. Under v2 it always has one
        // (saving the project assigns it); before that, promotion creates it.
        $fid = !empty($facs[$idx]['facility_id']) ? (int)$facs[$idx]['facility_id'] : null;
        if (!$fid && !kop_dm_v2_writes($pdo) && function_exists('kop_promote_single_nested_facility')) {
            $tmp = $facs[$idx];
            $fid = kop_promote_single_nested_facility($pdo, $tmp);
            if ($fid) { $facs[$idx] = $tmp; $dirty = true; }
        }

        if ($dirty) {
            kop_dm_set_facilities($project, $path, $facs);
            kop_dm_save_project($pdo, $rec, $project);
        }
        if (!$fid && kop_dm_v2_writes($pdo)) {
            $fid = kop_dm_assign_facility_id($pdo, $rec, $project, $path, $idx);
        }

        // Wiki (to the facility's own unique_name).
        if ($fid) {
            $funique = kop_dm_facility_unique_name($pdo, (int)$fid);
            $wiki = $names ? kop_dm_best_wiki($pdo, $names) : null;
            if (!$funique) {
                $result['notes'][] = 'Could not resolve the facility record for wiki linking.';
            } elseif (!$wiki) {
                $result['notes'][] = 'No strong wiki match found.';
            } elseif ($wiki['facility_unique_name'] === $funique) {
                $result['notes'][] = 'Best wiki entry is already linked to this facility.';
            } elseif (!empty($wiki['facility_unique_name'])) {
                $result['notes'][] = 'Best wiki entry ("' . $wiki['program_name'] . '") is already linked elsewhere — left as is.';
            } else {
                $wTable = $wiki['type'] === 'master' ? 'wiki_master' : 'wiki_submissions';
                try {
                    $w = $pdo->prepare("UPDATE `$wTable` SET facility_unique_name = ?, facility_link_status = 'suggested', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $w->execute([$funique, (int)$wiki['id']]);
                    $result['wiki'] = ['id' => (int)$wiki['id'], 'type' => $wiki['type'], 'program_name' => $wiki['program_name']];
                } catch (PDOException $e) {
                    $result['notes'][] = 'Wiki link failed: ' . $e->getMessage();
                }
            }
        } else {
            $result['notes'][] = 'Could not create a linkable facility record.';
        }

        $parts = [];
        if ($result['folder']) $parts[] = 'folder “' . $result['folder']['name'] . '” (#' . $result['folder']['id'] . ')';
        if ($result['wiki'])   $parts[] = 'wiki “' . $result['wiki']['program_name'] . '”';
        $msg = $parts ? ('Auto-linked ' . implode(' and ', $parts) . '.') : 'No new strong matches to apply.';

        echo json_encode([
            'success' => true,
            'folder'  => $result['folder'],
            'wiki'    => $result['wiki'],
            'notes'   => $result['notes'],
            'message' => $msg,
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
        kop_dm_save_project($pdo, $rec, $project);

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
