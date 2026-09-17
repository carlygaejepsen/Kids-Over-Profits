<?php
/**
 * Admin data form on the v2 facility model (docs/DATA-MODEL-MIGRATION.md,
 * phase 4 step 5).
 *
 * The form keeps its project shape: an operator project (operator block plus
 * a facilities[] list) or a state/country profile (facilities[] plus
 * referrerConsultants and field notes). This file translates between that
 * shape and the v2 tables:
 *
 *   load   kop_v2_form_projects() builds the projects get-master-data.php
 *          returns, from facilities_v2, kop_operators, the operator join and
 *          location memberships.
 *   save   kop_v2_save_form_project() splits a project into one
 *          kop_facility_save() per facility, the operator row and its join
 *          rows (operator projects) or the location memberships (profiles).
 *
 * Nothing here runs until the write switch is on: the 'writes' key in
 * kop_migration_state is 'v2' (api/migrate-facility-model.php?action=writes).
 * From then on the v2 tables are the source of truth, the legacy-to-v2 sync
 * stops, and facilities_master / locations_master are frozen as a backup.
 *
 * locations_master keeps one job: the state-level data that has no v2 home
 * (referrerConsultants, fieldNotes and the referrer/transporter blocks on some
 * profiles). A profile save writes those keys and leaves the row's frozen
 * facilities array alone.
 *
 * PDO only, no WordPress calls, so the offline test harness runs it as is.
 */

require_once __DIR__ . '/facility-migration.php';   // kop_migration_tables(), state get/set, fingerprint

if (!defined('KOP_V2_FIRST_NEW_OPERATOR_ID')) {
    // Operators created after the switch are numbered from here, far from any
    // facility id: news and lawsuit links are still attached by row id.
    define('KOP_V2_FIRST_NEW_OPERATOR_ID', 500000);
}

// ---------------------------------------------------------------------------
// The write switch
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_write_switch_blockers')) {
    /**
     * Code that still reads or writes the legacy facility tables and would go
     * stale or diverge once they are frozen. The switch page refuses to turn
     * writes on while this list is non-empty; remove an entry when its code
     * is ported.
     */
    function kop_v2_write_switch_blockers() {
        return array();
    }
}

if (!function_exists('kop_v2_writes_active')) {
    /** True when admin saves go to the v2 tables. */
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) {
        static $cache = array();
        if (!$refresh && isset($cache[$prefix])) return $cache[$prefix];
        try {
            $state = kop_migration_tables_exist($pdo, $prefix)
                ? kop_migration_state_get($pdo, $prefix, 'writes')
                : null;
        } catch (Throwable $e) {
            $state = null;
        }
        return $cache[$prefix] = (is_array($state) && ($state['mode'] ?? '') === 'v2');
    }
}

if (!function_exists('kop_v2_frozen_fingerprint')) {
    /**
     * Fingerprint of facilities_master, which nothing may write after the
     * switch. (locations_master still takes state-level referrer edits.)
     */
    function kop_v2_frozen_fingerprint(PDO $pdo) {
        $row = $pdo->query("SELECT COUNT(*) AS n, MAX(updated_at) AS u, SUM(CRC32(json_data)) AS c FROM facilities_master")->fetch(PDO::FETCH_ASSOC);
        return md5(json_encode(array((int)$row['n'], (string)$row['u'], (string)$row['c'])));
    }
}

if (!function_exists('kop_v2_detect_prefix')) {
    /** The WordPress table prefix, from WordPress, wp-config, or the migration state table. */
    function kop_v2_detect_prefix(PDO $pdo) {
        if (isset($GLOBALS['wpdb']->prefix) && is_string($GLOBALS['wpdb']->prefix)) return $GLOBALS['wpdb']->prefix;
        if (isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix'])) return $GLOBALS['table_prefix'];
        try {
            $table = $pdo->query("SHOW TABLES LIKE '%kop\\_migration\\_state'")->fetchColumn();
            if (is_string($table)) return substr($table, 0, -strlen('kop_migration_state'));
        } catch (Throwable $e) {
        }
        return '';
    }
}

if (!function_exists('kop_v2_exit_if_legacy_frozen')) {
    /**
     * For tools that write facilities_master or the facility arrays in
     * locations_master: stop once admin saves go to the v2 tables, where those
     * writes would be silently ignored by every page.
     */
    function kop_v2_exit_if_legacy_frozen($pdo, $tool) {
        if (!($pdo instanceof PDO)) return;
        if (!kop_v2_writes_active($pdo, kop_v2_detect_prefix($pdo))) return;
        $message = $tool . ' writes the old facility tables, which are frozen: admin saves go to the new facility tables (api/migrate-facility-model.php?action=writes).';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(409);
            header('Content-Type: application/json');
        }
        echo json_encode(array('success' => false, 'error' => $message));
        exit;
    }
}

if (!function_exists('kop_v2_apply_link_repoints')) {
    /**
     * Move news and lawsuit links recorded on a facility the migration split
     * to the facility they are about (kop_migration_state link_repoints).
     * Idempotent: a link already moved is not touched again.
     *
     * @return array {moved, already, skipped}
     */
    function kop_v2_apply_link_repoints(PDO $pdo, $prefix) {
        $repoints = kop_migration_state_get($pdo, $prefix, 'link_repoints', array());
        $out = array('moved' => 0, 'already' => 0, 'skipped' => 0);
        $tables = array(
            'news'    => array('news_facility_links', 'news_id'),
            'lawsuit' => array('lawsuit_facility_links', 'lawsuit_id'),
        );
        foreach ((array)$repoints as $r) {
            if (!isset($tables[$r['link_kind'] ?? ''])) { $out['skipped']++; continue; }
            list($table, $column) = $tables[$r['link_kind']];
            $stmt = $pdo->prepare("UPDATE IGNORE `{$table}` SET facility_id = ? WHERE {$column} = ? AND facility_id = ?");
            $stmt->execute(array((int)$r['to_facility_id'], (int)$r['link_id'], (int)$r['from_facility_id']));
            if ($stmt->rowCount() > 0) {
                $out['moved']++;
            } else {
                $out['already']++;
            }
        }
        return $out;
    }
}

if (!function_exists('kop_v2_set_writes')) {
    /**
     * Turn the write switch on ('v2') or off ('legacy').
     *
     * On: brings v2 up to date with the legacy tables one last time, applies
     * the link repoints and records the legacy fingerprint, so switching back
     * can tell whether the old tables were edited in between.
     *
     * @return array summary for the switch page
     */
    function kop_v2_set_writes(PDO $pdo, $prefix, $to) {
        if (!kop_migration_tables_exist($pdo, $prefix) || !kop_migration_state_get($pdo, $prefix, 'applied', false)) {
            throw new RuntimeException('Apply the migration first.');
        }
        if ($to === 'v2') {
            if (kop_v2_writes_active($pdo, $prefix, true)) return array('mode' => 'v2', 'unchanged' => true);
            $sync = kop_migration_sync($pdo, $prefix, false);
            if (!empty($sync['skipped']) && ($sync['reason'] ?? '') === 'another sync is running') {
                throw new RuntimeException('A sync is running; try again in a minute.');
            }
            $repoints = kop_v2_apply_link_repoints($pdo, $prefix);
            $state = array(
                'mode'               => 'v2',
                'at'                 => gmdate('c'),
                'frozen_fingerprint' => kop_v2_frozen_fingerprint($pdo),
                'final_sync'         => $sync,
                'link_repoints'      => $repoints,
            );
            kop_migration_state_set($pdo, $prefix, 'writes', $state);
            kop_v2_writes_active($pdo, $prefix, true);
            return $state;
        }
        $previous = kop_migration_state_get($pdo, $prefix, 'writes');
        $state = array('mode' => 'legacy', 'at' => gmdate('c'), 'previous' => $previous);
        kop_migration_state_set($pdo, $prefix, 'writes', $state);
        kop_v2_writes_active($pdo, $prefix, true);
        return $state;
    }
}

if (!function_exists('kop_v2_edits_since_switch')) {
    /** Facilities saved in v2 since the write switch was turned on. */
    function kop_v2_edits_since_switch(PDO $pdo, $prefix) {
        $state = kop_migration_state_get($pdo, $prefix, 'writes');
        if (!is_array($state) || ($state['mode'] ?? '') !== 'v2' || empty($state['at'])) return 0;
        $t = kop_migration_tables($prefix);
        $since = gmdate('Y-m-d H:i:s', strtotime($state['at']));
        // updated_at is server time; compare in UTC.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t['facilities']}` WHERE CONVERT_TZ(updated_at, @@session.time_zone, '+00:00') >= ?");
        try {
            $stmt->execute(array($since));
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Time zone tables missing: fall back to local comparison.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$t['facilities']}` WHERE updated_at >= ?");
            $stmt->execute(array($since));
            return (int)$stmt->fetchColumn();
        }
    }
}

// ---------------------------------------------------------------------------
// Legacy-shaped reads for the endpoints that scan or join facilities_master
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_pdo_master_rows')) {
    /**
     * What `SELECT id, unique_name, json_data FROM facilities_master` returned:
     * one row per operator project and one per facility, json_data encoded in
     * the legacy shapes. Lets the endpoints that scan that table keep their
     * parsing after the write switch freezes it.
     *
     * Operator rows keep their legacy id, so links made by row id still
     * resolve. Built once per request.
     *
     * @return array list of {id, unique_name, json_data}
     */
    function kop_v2_pdo_master_rows(PDO $pdo, $prefix) {
        static $cache = array();
        if (isset($cache[$prefix])) return $cache[$prefix];
        $t = kop_migration_tables($prefix);
        $rows = array();

        $facilities = array();
        $stmt = $pdo->query("SELECT id, unique_name, json_data FROM `{$t['facilities']}` ORDER BY id");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $doc = json_decode((string)$row['json_data'], true);
            if (!is_array($doc) || !isset($doc['identification'], $doc['location'])) continue;
            $facilities[(int)$row['id']] = array('unique_name' => (string)$row['unique_name'], 'doc' => $doc);
        }

        $by_operator = array();
        foreach ($pdo->query("SELECT operator_id, facility_id FROM `{$t['operator_facilities']}` ORDER BY operator_id, sort_order, facility_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fid = (int)$r['facility_id'];
            if (isset($facilities[$fid])) $by_operator[(int)$r['operator_id']][] = kop_facility_to_legacy($facilities[$fid]['doc']);
        }
        foreach ($pdo->query("SELECT id, unique_name, name, json_data, document_folder_id, updated_at FROM `{$t['operators']}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $op) {
            $project = kop_v2_operator_project($op, $by_operator[(int)$op['id']] ?? array());
            $rows[] = array(
                'id' => (int)$op['id'],
                'unique_name' => (string)$op['unique_name'],
                'json_data' => json_encode(array(
                    'name' => $project['name'],
                    'category' => $project['category'],
                    'currentFacilityIndex' => $project['currentFacilityIndex'],
                    'timestamp' => $project['timestamp'],
                    'data' => $project['data'],
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        foreach ($facilities as $id => $entry) {
            $doc = $entry['doc'];
            $facility = kop_facility_to_legacy($doc);
            $data = array('facility' => $facility);
            // Curated matchAliases sat beside the facility on a legacy row, and
            // that is where the name resolvers look for them.
            if (!empty($facility['matchAliases'])) $data['matchAliases'] = $facility['matchAliases'];
            $rows[] = array(
                'id' => $id,
                'unique_name' => $entry['unique_name'],
                'json_data' => json_encode(array(
                    '__facility_ref' => true,
                    'name' => $entry['unique_name'],
                    'displayName' => (string)$doc['identification']['name'],
                    'city' => (string)$doc['location']['city'],
                    'state' => (string)($doc['location']['state'] ?? ''),
                    'data' => $data,
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        return $cache[$prefix] = $rows;
    }
}

if (!function_exists('kop_v2_pdo_master_row_by_name')) {
    /**
     * One legacy-shaped row by unique_name, for the lookups that fetched a
     * facilities_master row that way.
     *
     * @return array|null {id, unique_name, json_data}
     */
    function kop_v2_pdo_master_row_by_name(PDO $pdo, $prefix, $unique_name) {
        $unique_name = trim((string)$unique_name);
        if ($unique_name === '') return null;
        foreach (kop_v2_pdo_master_rows($pdo, $prefix) as $row) {
            if (strcasecmp($row['unique_name'], $unique_name) === 0) return $row;
        }
        return null;
    }
}

if (!function_exists('kop_v2_pdo_names_by_id')) {
    /**
     * Row id => unique_name, for the link tables that join facilities_master
     * to show what a news item or lawsuit is about. Operator ids resolve too:
     * an article can be about an operator rather than one facility.
     *
     * @param int[] $ids empty for every row
     * @return array<int,string>
     */
    function kop_v2_pdo_names_by_id(PDO $pdo, $prefix, array $ids = array()) {
        $t = kop_migration_tables($prefix);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $where = $ids ? ' WHERE id IN (' . implode(',', $ids) . ')' : '';
        $out = array();
        foreach (array($t['facilities'], $t['operators']) as $table) {
            foreach ($pdo->query("SELECT id, unique_name FROM `{$table}`{$where}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['id']] = (string)$row['unique_name'];
            }
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Load
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_operator_project')) {
    /**
     * The form/program-index project for one kop_operators row.
     *
     * @param array $op         row: id, unique_name, name, json_data, document_folder_id, updated_at
     * @param array $facilities legacy projections, in join order
     * @return array {id, name, data, category, timestamp, currentFacilityIndex}
     */
    function kop_v2_operator_project(array $op, array $facilities) {
        $stored = is_array($op['json_data']) ? $op['json_data'] : json_decode((string)$op['json_data'], true);
        $blocks = (is_array($stored) && isset($stored['legacy_blocks']) && is_array($stored['legacy_blocks'])) ? $stored['legacy_blocks'] : array();
        $wrapper_keys = array('name', 'category', 'timestamp', 'currentFacilityIndex', '__facility_ref');
        $data = array();
        foreach ($blocks as $k => $v) {
            if (!in_array($k, $wrapper_keys, true)) $data[$k] = $v;
        }
        $data['operator'] = (is_array($stored) && isset($stored['operator']) && is_array($stored['operator'])) ? $stored['operator'] : array();
        if ($op['document_folder_id'] !== null) $data['documentFolderId'] = (int)$op['document_folder_id'];
        $data['facilities'] = array_values($facilities);

        return array(
            'id'                   => (int)$op['id'],
            'name'                 => isset($blocks['name']) && is_string($blocks['name']) ? $blocks['name'] : $op['unique_name'],
            'data'                 => $data,
            'category'             => isset($blocks['category']) && is_string($blocks['category']) ? $blocks['category'] : 'companies',
            'timestamp'            => isset($blocks['timestamp']) && is_string($blocks['timestamp']) ? $blocks['timestamp'] : (string)($op['updated_at'] ?? ''),
            'currentFacilityIndex' => isset($blocks['currentFacilityIndex']) ? (int)$blocks['currentFacilityIndex'] : 0,
        );
    }
}

if (!function_exists('kop_v2_location_profile_rows')) {
    /** locations_master rows keyed by uppercase name: {id, project} with the wrapper unwrapped. */
    function kop_v2_location_profile_rows(PDO $pdo) {
        $out = array();
        foreach ($pdo->query("SELECT id, unique_name, json_data FROM locations_master ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $json = json_decode((string)$row['json_data'], true);
            if (!is_array($json)) continue;
            $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
            $key = mb_strtoupper(trim((string)$row['unique_name']));
            if (isset($out[$key])) continue;
            $out[$key] = array('id' => (int)$row['id'], 'unique_name' => $row['unique_name'], 'json' => $json, 'data' => $data);
        }
        return $out;
    }
}

if (!function_exists('kop_v2_form_projects')) {
    /**
     * Operator projects and location profiles in get-master-data.php's shape:
     * "facilities_<unique_name>" / "locations_<KEY>" => {name, data, category,
     * currentFacilityIndex, timestamp, _sourceTable, _dbId, _uniqueName}.
     * Each facility carries its facility_id, so a save updates that row.
     */
    function kop_v2_form_projects(PDO $pdo, $prefix) {
        $t = kop_migration_tables($prefix);
        $facilities = array();
        $stmt = $pdo->query("SELECT id, json_data FROM `{$t['facilities']}` ORDER BY id");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $doc = json_decode((string)$row['json_data'], true);
            if (!is_array($doc) || !isset($doc['identification'], $doc['location'])) continue;
            $facilities[(int)$row['id']] = kop_facility_to_legacy($doc);
        }

        $projects = array();

        $by_operator = array();
        foreach ($pdo->query("SELECT operator_id, facility_id FROM `{$t['operator_facilities']}` ORDER BY operator_id, sort_order, facility_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($facilities[(int)$r['facility_id']])) $by_operator[(int)$r['operator_id']][] = $facilities[(int)$r['facility_id']];
        }
        foreach ($pdo->query("SELECT id, unique_name, name, json_data, document_folder_id, updated_at FROM `{$t['operators']}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $op) {
            $p = kop_v2_operator_project($op, $by_operator[(int)$op['id']] ?? array());
            $projects['facilities_' . $op['unique_name']] = array(
                'name'                 => $p['name'],
                'data'                 => $p['data'],
                'category'             => $p['category'],
                'currentFacilityIndex' => $p['currentFacilityIndex'],
                'timestamp'            => $p['timestamp'],
                '_sourceTable'         => 'facilities',
                '_dbId'                => $p['id'],
                '_uniqueName'          => $op['unique_name'],
            );
        }

        $by_location = array();
        foreach ($pdo->query("SELECT location_key, facility_id FROM `{$t['facility_locations']}` WHERE role <> 'unknown' ORDER BY location_key, facility_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($facilities[(int)$r['facility_id']])) $by_location[$r['location_key']][(int)$r['facility_id']] = $facilities[(int)$r['facility_id']];
        }
        $profiles = kop_v2_location_profile_rows($pdo);
        $keys = array_unique(array_merge(array_keys($profiles), array_keys($by_location)));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $profile = $profiles[$key] ?? null;
            $data = $profile ? $profile['data'] : array();
            unset($data['data']);
            $data['facilities'] = array_values($by_location[$key] ?? array());
            $projects['locations_' . $key] = array(
                'name'                 => $key,
                'data'                 => $data,
                'category'             => 'locations',
                'currentFacilityIndex' => $profile ? (int)($profile['json']['currentFacilityIndex'] ?? 0) : 0,
                'timestamp'            => $profile ? (string)($profile['json']['timestamp'] ?? '') : '',
                '_sourceTable'         => 'locations',
                '_dbId'                => $profile ? $profile['id'] : null,
                '_uniqueName'          => $profile ? $profile['unique_name'] : $key,
            );
        }

        return $projects;
    }
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_save_facility_entries')) {
    /**
     * Save each facility entry of a form project.
     *
     * An entry with a facility_id updates that facility. One without is
     * matched by name and place (kop_facility_resolve_identity) before a new
     * facility is created. Provenance of an existing facility is never
     * rewritten by the form. Entries with no name are skipped: the form's
     * blank template is not a facility.
     *
     * @param array $ctx source_project, source_category, source_project_id, source_operator
     * @return array {ids: index => id, created, updated, unchanged, skipped: index[]}
     */
    function kop_v2_save_facility_entries(PDO $pdo, $prefix, array $entries, array $ctx) {
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $out = array('ids' => array(), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => array());

        foreach (array_values($entries) as $index => $entry) {
            if (!is_array($entry)) { $out['skipped'][] = $index; continue; }
            $existing = null;
            $claimed = isset($entry['facility_id']) ? (int)$entry['facility_id'] : 0;
            if ($claimed > 0) $existing = kop_facility_load($claimed, $opts);

            $doc = kop_facility_normalize($entry, array(
                'facility_id' => $existing ? $existing['id'] : null,
                'unique_name' => $existing ? $existing['unique_name'] : '',
            ));
            if ($doc['identification']['name'] === '') { $out['skipped'][] = $index; continue; }
            if (!$existing) {
                $doc['facility_id'] = null;
                // Identity may still find it; provenance is replaced below if so.
                $resolved = (int)kop_facility_resolve_identity($doc['identification']['name'], $doc['location']['state'], $doc['location']['city'], $opts);
                if ($resolved > 0) {
                    $existing = kop_facility_load($resolved, $opts);
                    if ($existing) $doc['facility_id'] = $existing['id'];
                }
            }

            if ($existing) {
                $doc['provenance'] = $existing['doc']['provenance'];
            } else {
                $doc['provenance']['sourceProject'] = (string)($ctx['source_project'] ?? '');
                $doc['provenance']['sourceCategory'] = (string)($ctx['source_category'] ?? '');
                $doc['provenance']['sourceProjectId'] = isset($ctx['source_project_id']) ? (int)$ctx['source_project_id'] : null;
                $doc['provenance']['sourceOperator'] = isset($ctx['source_operator']) && is_array($ctx['source_operator']) && $ctx['source_operator'] ? $ctx['source_operator'] : null;
                $doc['provenance']['source'] = 'admin-form';
                $doc['provenance']['migratedAt'] = '';
                $doc['provenance']['legacyIds'] = array();
            }

            try {
                $status = null;
                $id = kop_facility_save($doc, $opts, $status);
            } catch (RuntimeException $e) {
                throw new RuntimeException('"' . $doc['identification']['name'] . '": ' . preg_replace('/^kop_facility_save: /', '', $e->getMessage()), 0, $e);
            }
            $out['ids'][$index] = $id;
            $out[$status]++;
        }
        return $out;
    }
}

if (!function_exists('kop_v2_save_operator_project')) {
    function kop_v2_save_operator_project(PDO $pdo, $prefix, $project_name, array $data, array $meta) {
        $t = kop_migration_tables($prefix);
        $stmt = $pdo->prepare("SELECT id, unique_name, name, json_data, document_folder_id FROM `{$t['operators']}` WHERE unique_name = ?");
        $stmt->execute(array($project_name));
        $op = $stmt->fetch(PDO::FETCH_ASSOC);

        $stored = $op ? json_decode((string)$op['json_data'], true) : array();
        // No operator block in the payload (a suggestion about one facility)
        // keeps the stored one.
        $operator = isset($data['operator']) && is_array($data['operator'])
            ? $data['operator']
            : ((is_array($stored) && isset($stored['operator']) && is_array($stored['operator'])) ? $stored['operator'] : array());
        $name = kop_facility_str($operator['name'] ?? '');
        if ($name === '' && $op) $name = (string)$op['name'];
        if ($name === '') $name = $project_name;

        $blocks = (is_array($stored) && isset($stored['legacy_blocks']) && is_array($stored['legacy_blocks'])) ? $stored['legacy_blocks'] : array();
        foreach ($data as $k => $v) {
            if (in_array($k, array('operator', 'facilities', 'data', 'facility', 'documentFolderId'), true)) continue;
            $blocks[$k] = $v;
        }
        $blocks['name'] = $project_name;
        $blocks['category'] = 'companies';
        if (isset($meta['timestamp'])) $blocks['timestamp'] = (string)$meta['timestamp'];
        if (isset($meta['currentFacilityIndex'])) $blocks['currentFacilityIndex'] = (int)$meta['currentFacilityIndex'];

        $folder = array_key_exists('documentFolderId', $data)
            ? kop_facility_int($data['documentFolderId'])
            : ($op ? ($op['document_folder_id'] !== null ? (int)$op['document_folder_id'] : null) : null);
        $json = json_encode(array('operator' => $operator, 'legacy_blocks' => $blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($op) {
            $operator_id = (int)$op['id'];
            $pdo->prepare("UPDATE `{$t['operators']}` SET name = ?, json_data = ?, document_folder_id = ? WHERE id = ?")
                ->execute(array($name, $json, $folder, $operator_id));
        } else {
            $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$t['operators']}`")->fetchColumn();
            $operator_id = max(KOP_V2_FIRST_NEW_OPERATOR_ID, $max + 1);
            $pdo->prepare("INSERT INTO `{$t['operators']}` (id, unique_name, name, json_data, document_folder_id) VALUES (?, ?, ?, ?, ?)")
                ->execute(array($operator_id, $project_name, $name, $json, $folder));
        }

        $saved = kop_v2_save_facility_entries($pdo, $prefix, isset($data['facilities']) && is_array($data['facilities']) ? $data['facilities'] : array(), array(
            'source_project'    => $project_name,
            'source_category'   => 'companies',
            'source_project_id' => $operator_id,
            'source_operator'   => $operator,
        ));

        // Join rows follow the form's order; a facility dropped from the list
        // leaves this operator but stays a facility.
        $existing = array();
        $q = $pdo->prepare("SELECT facility_id, relationship FROM `{$t['operator_facilities']}` WHERE operator_id = ? ORDER BY sort_order, facility_id");
        $q->execute(array($operator_id));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $existing[(int)$r['facility_id']] = $r['relationship'];

        $keep = array();
        foreach ($saved['ids'] as $index => $fid) {
            if (!isset($keep[$fid])) $keep[$fid] = $index;
        }
        if (!empty($meta['partial'])) {
            // A suggestion lists only the facilities it changes: add the new
            // ones at the end, never reorder or drop.
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM `{$t['operator_facilities']}` WHERE operator_id = " . (int)$operator_id)->fetchColumn();
            $insert = $pdo->prepare("INSERT IGNORE INTO `{$t['operator_facilities']}` (operator_id, facility_id, relationship, sort_order) VALUES (?, ?, 'current', ?)");
            foreach ($keep as $fid => $unused) {
                if (isset($existing[$fid])) continue;
                $insert->execute(array($operator_id, $fid, $next++));
            }
        } elseif (array_keys($keep) !== array_keys($existing)) {
            // Same facilities in the same order: rows (and their migrated
            // sort_order gaps) are left as they are.
            $upsert = $pdo->prepare("INSERT INTO `{$t['operator_facilities']}` (operator_id, facility_id, relationship, sort_order) VALUES (?, ?, 'current', ?)
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)");
            foreach ($keep as $fid => $index) {
                $upsert->execute(array($operator_id, $fid, $index));
            }
        }
        $unlinked = 0;
        $delete = $pdo->prepare("DELETE FROM `{$t['operator_facilities']}` WHERE operator_id = ? AND facility_id = ?");
        foreach ($existing as $fid => $unused) {
            if (isset($keep[$fid]) || !empty($meta['partial'])) continue;
            $delete->execute(array($operator_id, $fid));
            $unlinked++;
        }

        return $saved + array('operator_id' => $operator_id, 'operator_created' => !$op, 'unlinked' => $unlinked);
    }
}

if (!function_exists('kop_v2_save_location_project')) {
    function kop_v2_save_location_project(PDO $pdo, $prefix, $project_name, array $data, array $meta) {
        $t = kop_migration_tables($prefix);
        $key = mb_strtoupper(trim($project_name));

        $saved = kop_v2_save_facility_entries($pdo, $prefix, isset($data['facilities']) && is_array($data['facilities']) ? $data['facilities'] : array(), array(
            'source_project'  => $key,
            'source_category' => 'locations',
        ));

        // Everything listed on the profile is on the page.
        $has = $pdo->prepare("SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE facility_id = ? AND location_key = ? AND role <> 'unknown'");
        $add = $pdo->prepare("INSERT INTO `{$t['facility_locations']}` (facility_id, location_key, role, source, needs_review, review_reason) VALUES (?, ?, 'current', 'manual', 0, NULL)");
        $drop_unknown = $pdo->prepare("DELETE FROM `{$t['facility_locations']}` WHERE facility_id = ? AND location_key = 'UNKNOWN'");
        $listed = array();
        $placed = 0;
        foreach ($saved['ids'] as $fid) {
            $listed[$fid] = true;
            $has->execute(array($fid, $key));
            if ((int)$has->fetchColumn() === 0) {
                $add->execute(array($fid, $key));
                $drop_unknown->execute(array($fid));
                $placed++;
            }
        }

        // Taken off the profile: remove the placements that exist only because
        // of a list (manual, legacy_membership). A facility whose own address
        // or location puts it here stays, and is reported.
        $q = $pdo->prepare("SELECT DISTINCT facility_id FROM `{$t['facility_locations']}` WHERE location_key = ? AND role <> 'unknown'");
        $q->execute(array($key));
        $removed = 0;
        $stays = array();
        $delete = $pdo->prepare("DELETE FROM `{$t['facility_locations']}` WHERE facility_id = ? AND location_key = ? AND source IN ('manual', 'legacy_membership')");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            $fid = (int)$fid;
            if (isset($listed[$fid]) || !empty($meta['partial'])) continue;
            $delete->execute(array($fid, $key));
            $has->execute(array($fid, $key));
            if ((int)$has->fetchColumn() > 0) {
                $doc = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $prefix));
                $stays[] = $doc ? $doc['doc']['identification']['name'] : (string)$fid;
            } else {
                $removed++;
                kop_v2_ensure_some_membership($pdo, $prefix, $fid);
            }
        }

        // State-level data with no v2 home stays in locations_master.
        $extra = array();
        foreach ($data as $k => $v) {
            if ($k === 'facilities' || $k === 'data') continue;
            $extra[$k] = $v;
        }
        $profiles = kop_v2_location_profile_rows($pdo);
        if (isset($profiles[$key])) {
            $json = $profiles[$key]['json'];
            $stored_json = $json;
            $wrapped = isset($json['data']) && is_array($json['data']);
            $target = $wrapped ? $json['data'] : $json;
            foreach ($extra as $k => $v) $target[$k] = $v;
            if ($wrapped) {
                $json['data'] = $target;
            } else {
                $json = $target;
            }
            // Only a real change to the state-level data is written.
            if (!kop_facility_same_document($stored_json, $json)) {
                if (isset($meta['currentFacilityIndex'])) $json['currentFacilityIndex'] = (int)$meta['currentFacilityIndex'];
                if (isset($meta['timestamp'])) $json['timestamp'] = (string)$meta['timestamp'];
                $pdo->prepare("UPDATE locations_master SET json_data = ?, updated_at = NOW() WHERE id = ?")
                    ->execute(array(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $profiles[$key]['id']));
            }
        } elseif (array_filter($extra, function ($v) { return !empty($v); })) {
            $json = array(
                'name' => $key, 'category' => 'locations',
                'currentFacilityIndex' => (int)($meta['currentFacilityIndex'] ?? 0),
                'timestamp' => (string)($meta['timestamp'] ?? gmdate('c')),
                'data' => array_merge($extra, array('facilities' => array())),
            );
            $pdo->prepare("INSERT INTO locations_master (unique_name, json_data, created_at, updated_at) VALUES (?, ?, NOW(), NOW())")
                ->execute(array($key, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return $saved + array('location_key' => $key, 'placed' => $placed, 'removed_from_page' => $removed, 'still_on_page' => $stays);
    }
}

if (!function_exists('kop_v2_ensure_some_membership')) {
    /** A facility left on no page goes to the Unknown location group. */
    function kop_v2_ensure_some_membership(PDO $pdo, $prefix, $facility_id) {
        $t = kop_migration_tables($prefix);
        $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE facility_id = ?");
        $q->execute(array((int)$facility_id));
        if ((int)$q->fetchColumn() > 0) return;
        $pdo->prepare("INSERT INTO `{$t['facility_locations']}` (facility_id, location_key, role, source, needs_review, review_reason) VALUES (?, 'UNKNOWN', 'unknown', 'manual', 1, 'removed from its last page in the data form')")
            ->execute(array((int)$facility_id));
    }
}

if (!function_exists('kop_v2_with_write_lock')) {
    /**
     * Run $fn inside a transaction, serialized with other v2 writes. Inside a
     * caller's open transaction (suggestion approval) it joins that one and
     * the caller commits.
     */
    function kop_v2_with_write_lock(PDO $pdo, callable $fn) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $locked = (int)$pdo->query("SELECT GET_LOCK('kop_facility_v2_write', 15)")->fetchColumn();
        if ($locked !== 1) throw new RuntimeException('Another save is in progress; try again.');
        try {
            if ($pdo->inTransaction()) {
                return $fn();
            }
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('kop_facility_v2_write')");
        }
    }
}

if (!function_exists('kop_v2_save_form_project')) {
    /**
     * Save one form project to the v2 tables, all or nothing.
     *
     * @param string $category 'locations' for a state/country profile, anything else is an operator project
     * @param array  $meta     timestamp, currentFacilityIndex
     * @return array {ids: index => facility id, created, updated, unchanged, skipped, ...}
     */
    function kop_v2_save_form_project(PDO $pdo, $prefix, $project_name, array $data, $category, array $meta = array()) {
        $project_name = trim((string)$project_name);
        if ($project_name === '') throw new InvalidArgumentException('Project name is required');
        return kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $project_name, $data, $category, $meta) {
            return $category === 'locations'
                ? kop_v2_save_location_project($pdo, $prefix, $project_name, $data, $meta)
                : kop_v2_save_operator_project($pdo, $prefix, $project_name, $data, $meta);
        });
    }
}

if (!function_exists('kop_v2_save_legacy_row')) {
    /**
     * Save a row in the legacy shape back to the v2 tables, for the tools that
     * read a facilities_master row, edit part of it and write the whole row
     * back (the wiki merge, the Data Manager).
     *
     * An operator project saves as a project; a per-facility row saves as that
     * one facility.
     *
     * @param array $project the decoded row, as kop_v2_pdo_master_rows() serves it
     * @return array the save summary
     */
    function kop_v2_save_legacy_row(PDO $pdo, $prefix, $unique_name, array $project, array $meta = array()) {
        if (!empty($project['__facility_ref'])) {
            $facility = isset($project['data']['facility']) && is_array($project['data']['facility'])
                ? $project['data']['facility']
                : array();
            if (!$facility) throw new RuntimeException('No facility data in "' . $unique_name . '"');
            return kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $facility) {
                return kop_v2_save_facility_entries($pdo, $prefix, array($facility), array());
            });
        }
        $data = isset($project['data']) && is_array($project['data']) ? $project['data'] : $project;
        $category = (isset($project['category']) && $project['category'] === 'locations') ? 'locations' : 'companies';
        return kop_v2_save_form_project($pdo, $prefix, $unique_name, $data, $category, $meta);
    }
}

if (!function_exists('kop_v2_rename_operator')) {
    /**
     * Rename an operator project. Facilities that name it as their source
     * project follow.
     *
     * @return array {renamed: bool, error?, facilities_updated}
     */
    function kop_v2_rename_operator(PDO $pdo, $prefix, $old, $new) {
        $t = kop_migration_tables($prefix);
        return kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $old, $new) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t['operators']}` WHERE unique_name = ?");
            $q->execute(array($new));
            if ((int)$q->fetchColumn() > 0) return array('renamed' => false, 'error' => "Project '$new' already exists.");
            $u = $pdo->prepare("UPDATE `{$t['operators']}` SET unique_name = ?,
                json_data = JSON_SET(json_data, '$.legacy_blocks.name', ?) WHERE unique_name = ?");
            $u->execute(array($new, $new, $old));
            if ($u->rowCount() === 0) return array('renamed' => false, 'error' => "Project '$old' not found.");
            $f = $pdo->prepare("UPDATE `{$t['facilities']}` SET json_data = JSON_SET(json_data, '$.provenance.sourceProject', ?)
                WHERE JSON_VALUE(json_data, '$.provenance.sourceProject') = ?");
            $f->execute(array($new, $old));
            return array('renamed' => true, 'facilities_updated' => $f->rowCount());
        });
    }
}

if (!function_exists('kop_v2_delete_form_project')) {
    /**
     * Delete an operator project (its facilities stay) or an empty location
     * profile.
     *
     * @return array {deleted: bool, error?, kind, facilities_kept}
     */
    function kop_v2_delete_form_project(PDO $pdo, $prefix, $project_name, $is_location) {
        $t = kop_migration_tables($prefix);
        return kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $t, $project_name, $is_location) {
            if ($is_location) {
                $key = mb_strtoupper(trim($project_name));
                $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE location_key = ? AND role <> 'unknown'");
                $q->execute(array($key));
                $facilities = (int)$q->fetchColumn();
                $profiles = kop_v2_location_profile_rows($pdo);
                $referrers = isset($profiles[$key]) ? count((array)($profiles[$key]['data']['referrerConsultants'] ?? array())) : 0;
                if ($facilities > 0 || $referrers > 0) {
                    return array('deleted' => false, 'kind' => 'location', 'error' => "Cannot delete location project '$key'. It contains $facilities facilities and $referrers referrers.");
                }
                if (!isset($profiles[$key])) return array('deleted' => false, 'kind' => 'location', 'error' => 'Project not found');
                $pdo->prepare("DELETE FROM locations_master WHERE id = ?")->execute(array($profiles[$key]['id']));
                return array('deleted' => true, 'kind' => 'location', 'facilities_kept' => 0);
            }
            $q = $pdo->prepare("SELECT id FROM `{$t['operators']}` WHERE unique_name = ?");
            $q->execute(array($project_name));
            $id = $q->fetchColumn();
            if ($id === false) return array('deleted' => false, 'kind' => 'operator', 'error' => 'Project not found');
            $c = $pdo->prepare("SELECT COUNT(*) FROM `{$t['operator_facilities']}` WHERE operator_id = ?");
            $c->execute(array((int)$id));
            $kept = (int)$c->fetchColumn();
            $pdo->prepare("DELETE FROM `{$t['operator_facilities']}` WHERE operator_id = ?")->execute(array((int)$id));
            $pdo->prepare("DELETE FROM `{$t['operators']}` WHERE id = ?")->execute(array((int)$id));
            return array('deleted' => true, 'kind' => 'operator', 'facilities_kept' => $kept);
        });
    }
}

// ---------------------------------------------------------------------------
// Deploy seeds (inc/admin.php) once writes are on
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_apply_facility_record_seed')) {
    /**
     * One seeds/facility-records.json entry: merge its facility fields into
     * the facility's v2 document, once per entry version. The facility keeps
     * its id through the migration, and there is one copy, so apply_to and
     * the wrapper 'top' keys have nothing left to do.
     *
     * @return bool true when the document was written
     */
    function kop_v2_apply_facility_record_seed(PDO $pdo, $prefix, array $entry) {
        if (empty($entry['id']) || empty($entry['facility']) || !is_array($entry['facility'])) return false;
        $opts = array('pdo' => $pdo, 'prefix' => $prefix);
        $stored = kop_facility_load((int)$entry['id'], $opts);
        if (!$stored || (!empty($entry['unique_name']) && $stored['unique_name'] !== $entry['unique_name'])) {
            return false;   // wrong row: never write into a record we did not mean
        }
        $version = (int)($entry['version'] ?? 1);
        if ((int)($stored['doc']['provenance']['kopProfileVersion'] ?? 0) >= $version) return false;

        return (bool)kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $stored, $entry, $version) {
            $merged = array_replace(kop_facility_to_legacy($stored['doc']), $entry['facility']);
            $doc = kop_facility_normalize($merged, array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
            $doc['provenance'] = $stored['doc']['provenance'];
            $doc['provenance']['kopProfileVersion'] = $version;
            kop_facility_save($doc, $opts);
            return true;
        });
    }
}

if (!function_exists('kop_v2_add_operator_other_names')) {
    /**
     * One seeds/operator-aliases.json entry: add names to the operator's
     * otherNames. Names already present (any case) are skipped.
     *
     * @return int names added
     */
    function kop_v2_add_operator_other_names(PDO $pdo, $prefix, $id, $unique_name, array $names) {
        $t = kop_migration_tables($prefix);
        return (int)kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $id, $unique_name, $names) {
            $q = $pdo->prepare("SELECT unique_name, json_data FROM `{$t['operators']}` WHERE id = ?");
            $q->execute(array((int)$id));
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['unique_name'] !== $unique_name) return 0;
            $json = json_decode((string)$row['json_data'], true);
            if (!is_array($json)) return 0;
            $operator = isset($json['operator']) && is_array($json['operator']) ? $json['operator'] : array();
            $list = is_array($operator['otherNames'] ?? null) ? $operator['otherNames'] : array();
            $have = array_map('strtolower', array_map('strval', $list));
            $have[] = strtolower((string)($operator['name'] ?? ''));
            $added = 0;
            foreach ($names as $name) {
                $name = trim((string)$name);
                if ($name === '' || in_array(strtolower($name), $have, true)) continue;
                $list[] = $name;
                $have[] = strtolower($name);
                $added++;
            }
            if ($added) {
                $operator['otherNames'] = $list;
                $json['operator'] = $operator;
                $pdo->prepare("UPDATE `{$t['operators']}` SET json_data = ? WHERE id = ?")
                    ->execute(array(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$id));
            }
            return $added;
        });
    }
}

// ---------------------------------------------------------------------------
// Document folders and name checks (the facility picker, the wiki editor)
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_name_taken')) {
    /** Is this unique_name already an operator project or a facility? */
    function kop_v2_name_taken(PDO $pdo, $prefix, $unique_name) {
        $t = kop_migration_tables($prefix);
        foreach (array($t['facilities'], $t['operators']) as $table) {
            $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) LIMIT 1");
            $stmt->execute(array((string)$unique_name));
            if ($stmt->fetchColumn()) return true;
        }
        return false;
    }
}

if (!function_exists('kop_v2_set_document_folder')) {
    /**
     * Point a program at its FileBird document folder: the operator row's
     * column, or the facility document's documentFolderId.
     *
     * @param mixed $folder_id_raw a positive id, or null/0/'' to clear it
     * @return int|null the stored folder id
     * @throws RuntimeException when no program of that name exists
     */
    function kop_v2_set_document_folder(PDO $pdo, $prefix, $unique_name, $folder_id_raw) {
        $t = kop_migration_tables($prefix);
        $folder_id = ($folder_id_raw !== null && $folder_id_raw !== '' && (int)$folder_id_raw > 0) ? (int)$folder_id_raw : null;

        return kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix, $t, $unique_name, $folder_id) {
            $stmt = $pdo->prepare("SELECT id FROM `{$t['operators']}` WHERE unique_name = ? LIMIT 1");
            $stmt->execute(array((string)$unique_name));
            $operator_id = $stmt->fetchColumn();
            if ($operator_id !== false) {
                $pdo->prepare("UPDATE `{$t['operators']}` SET document_folder_id = ? WHERE id = ?")
                    ->execute(array($folder_id, (int)$operator_id));
                return $folder_id;
            }

            $stmt = $pdo->prepare("SELECT id FROM `{$t['facilities']}` WHERE unique_name = ? LIMIT 1");
            $stmt->execute(array((string)$unique_name));
            $facility_id = $stmt->fetchColumn();
            if ($facility_id === false) {
                throw new RuntimeException("Program '{$unique_name}' not found");
            }
            $stored = kop_facility_load((int)$facility_id, array('pdo' => $pdo, 'prefix' => $prefix));
            $doc = $stored['doc'];
            $doc['documentFolderId'] = $folder_id;
            kop_facility_save($doc, array('pdo' => $pdo, 'prefix' => $prefix, 'skip_memberships' => true));
            return $folder_id;
        });
    }
}

if (!function_exists('kop_v2_save_summary')) {
    /** One line for the form's status message. */
    function kop_v2_save_summary($project_name, array $r) {
        $parts = array();
        if ($r['created']) $parts[] = $r['created'] . ' new';
        if ($r['updated']) $parts[] = $r['updated'] . ' updated';
        if ($r['unchanged']) $parts[] = $r['unchanged'] . ' unchanged';
        $msg = "Project '$project_name' saved";
        if ($parts) $msg .= ': ' . implode(', ', $parts) . ' ' . (($r['created'] + $r['updated'] + $r['unchanged']) === 1 ? 'facility' : 'facilities');
        if (!empty($r['unlinked'])) $msg .= '. ' . $r['unlinked'] . ' removed from this operator (the facilities themselves are kept)';
        if (!empty($r['removed_from_page'])) $msg .= '. ' . $r['removed_from_page'] . ' taken off this page';
        if (!empty($r['still_on_page'])) $msg .= '. Still on this page because their address is here: ' . implode(', ', $r['still_on_page']);
        return $msg . '.';
    }
}
