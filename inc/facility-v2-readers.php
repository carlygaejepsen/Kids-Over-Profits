<?php
/**
 * Readers for the v2 facility model (docs/DATA-MODEL-MIGRATION.md, phase 4).
 *
 * Each public reader switches to v2 on its own, so a problem in one area can
 * be rolled back without touching the others:
 *
 *   location_pages     state and country hubs (kop/v1/state, kop/v1/country)
 *   program_index      the public directory feed (kop/v1/facilities)
 *   facility_profiles  templates/single-facility-profile.php
 *   search             search.php, the header quick search, Ajax Search Lite
 *   homepage_stats     "facilities tracked" counts and the recent-facilities widget
 *
 * An area reads v2 when it is listed in the kop_data_model_areas option, when
 * kop_data_model is 'v2' (everything), or when the request carries ?model=v2
 * (preview; used by scripts/snapshot-location-pages.js --model v2). Nothing
 * reads v2 until the tables exist. Areas are switched from
 * api/migrate-facility-model.php?action=cutover.
 *
 * The admin data form (get-master-data.php, save-master.php) edits the legacy
 * tables, and v2 is re-derived from them, until the write switch sends its
 * saves to v2 (inc/facility-v2-writer.php).
 */

if (!function_exists('kop_v2_areas')) {
    /** Area key => human label. */
    function kop_v2_areas() {
        return array(
            'location_pages'    => 'State and country pages',
            'program_index'     => 'Program index (directory)',
            'facility_profiles' => 'Facility profile pages',
            'search'            => 'Site search',
            'homepage_stats'    => 'Homepage counts and recent facilities',
        );
    }
}

if (!function_exists('kop_v2_tables_ready')) {
    function kop_v2_tables_ready() {
        static $ready = null;
        if ($ready !== null) return $ready;
        global $wpdb;
        if (!isset($wpdb)) return $ready = false;
        $ready = ($wpdb->get_var("SHOW TABLES LIKE 'facilities_v2'") === 'facilities_v2')
            && ((int)$wpdb->get_var("SELECT COUNT(*) FROM facilities_v2") > 0);
        return $ready;
    }
}

if (!function_exists('kop_v2_active')) {
    /** Should this area read the v2 model on this request? */
    function kop_v2_active($area) {
        $preview = isset($_GET['model']) && $_GET['model'] === 'v2';
        $all = function_exists('get_option') && get_option('kop_data_model') === 'v2';
        $areas = function_exists('get_option') ? (array)get_option('kop_data_model_areas', array()) : array();
        if (!$preview && !$all && !in_array($area, $areas, true)) return false;
        return kop_v2_tables_ready();
    }
}

if (function_exists('add_filter')) {
    // Preview: on a page opened with ?model=v2, the data requests its scripts
    // make (state/country hubs, program index) carry ?model=v2 too, so the
    // whole page previews the v2 model.
    add_filter('rest_url', function ($url, $path) {
        if (!isset($_GET['model']) || $_GET['model'] !== 'v2') return $url;
        if (!preg_match('#^/?kop/v1/(state|country|facilities)\b#', (string)$path)) return $url;
        return add_query_arg('model', 'v2', $url);
    }, 10, 2);
}

if (!function_exists('kop_v2_model_requested')) {
    /** Phase 3 name for the location pages switch. */
    function kop_v2_model_requested() {
        return kop_v2_active('location_pages');
    }
}

if (!function_exists('kop_v2_writes_on')) {
    /**
     * True once admin saves write the v2 tables (the write switch in
     * inc/facility-v2-writer.php). From then on facilities_master is frozen,
     * so the readers that still scan it for names, ids and values - the data
     * form's search and autocomplete, the pickers, the news and lawsuit
     * linkers, the state page's inspection placement - read v2 instead.
     *
     * This is the $wpdb-side check; code with a PDO handle calls
     * kop_v2_writes_active().
     */
    function kop_v2_writes_on() {
        static $on = null;
        if ($on !== null) return $on;
        global $wpdb;
        if (!isset($wpdb)) return $on = false;
        $table = $wpdb->prefix . 'kop_migration_state';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return $on = false;
        $value = $wpdb->get_var($wpdb->prepare("SELECT state_value FROM {$table} WHERE state_key = %s", 'writes'));
        $state = json_decode((string)$value, true);
        return $on = (is_array($state) && ($state['mode'] ?? '') === 'v2');
    }
}

if (!function_exists('kop_v2_legacy_shaped_rows')) {
    /**
     * Every operator project and every facility as the row shape the legacy
     * scanners expect, with `payload` already decoded:
     *
     *   operator  {name, category, data: {operator, facilities[]}}
     *   facility  {__facility_ref, name, displayName, city, state, data: {facility}}
     *
     * Built once per request. This is what lets the search, autocomplete and
     * value collectors keep their matching logic unchanged after the switch.
     *
     * @return array list of {unique_name, payload}
     */
    function kop_v2_legacy_shaped_rows() {
        static $rows = null;
        if ($rows !== null) return $rows;
        global $wpdb;
        $rows = array();
        if (!kop_v2_tables_ready()) return $rows;

        $facilities = array();
        foreach ((array)$wpdb->get_results("SELECT id, unique_name, json_data FROM facilities_v2 ORDER BY id", ARRAY_A) as $row) {
            $doc = kop_v2_decode($row['json_data']);
            if ($doc === null) continue;
            $facilities[(int)$row['id']] = array('unique_name' => (string)$row['unique_name'], 'doc' => $doc);
        }

        $by_operator = array();
        foreach ((array)$wpdb->get_results("SELECT operator_id, facility_id FROM {$wpdb->prefix}kop_operator_facilities ORDER BY operator_id, sort_order, facility_id", ARRAY_A) as $r) {
            $fid = (int)$r['facility_id'];
            if (isset($facilities[$fid])) $by_operator[(int)$r['operator_id']][] = kop_facility_to_legacy($facilities[$fid]['doc']);
        }
        foreach ((array)$wpdb->get_results("SELECT id, unique_name, name, json_data FROM {$wpdb->prefix}kop_operators ORDER BY id", ARRAY_A) as $op) {
            $stored = json_decode((string)$op['json_data'], true);
            $blocks = (is_array($stored) && isset($stored['legacy_blocks']) && is_array($stored['legacy_blocks'])) ? $stored['legacy_blocks'] : array();
            $data = array();
            foreach ($blocks as $k => $v) {
                if (!in_array($k, array('name', 'category', 'timestamp', 'currentFacilityIndex', '__facility_ref'), true)) $data[$k] = $v;
            }
            $data['operator'] = (is_array($stored) && isset($stored['operator']) && is_array($stored['operator'])) ? $stored['operator'] : array();
            $data['facilities'] = $by_operator[(int)$op['id']] ?? array();
            $rows[] = array(
                'unique_name' => (string)$op['unique_name'],
                'payload' => array('name' => (string)$op['unique_name'], 'category' => 'companies', 'data' => $data),
            );
        }

        // The legacy rows carried the state as its full name ("UTAH"), and a
        // search for a state name matched on it; the document stores the code.
        $state_names = kop_state_abbrev_to_name();
        foreach ($facilities as $entry) {
            $doc = $entry['doc'];
            $code = (string)($doc['location']['state'] ?? '');
            $place = $code !== '' && isset($state_names[$code])
                ? mb_strtoupper($state_names[$code])
                : mb_strtoupper((string)($doc['location']['country'] ?? ''));
            $facility = kop_facility_to_legacy($doc);
            $data = array('facility' => $facility);
            // Curated matchAliases sat beside the facility on a legacy row, and
            // that is where the name resolvers look for them.
            if (!empty($facility['matchAliases'])) $data['matchAliases'] = $facility['matchAliases'];
            $rows[] = array(
                'unique_name' => $entry['unique_name'],
                'payload' => array(
                    '__facility_ref' => true,
                    'name' => $entry['unique_name'],
                    'displayName' => (string)$doc['identification']['name'],
                    'city' => (string)$doc['location']['city'],
                    'state' => $place,
                    'data' => $data,
                ),
            );
        }

        return $rows;
    }
}

if (!function_exists('kop_v2_name_id_map')) {
    /**
     * Facility and operator names to row ids, for the tables that link by id
     * (news_facility_links, lawsuit_facility_links). Keyed by the lowercase
     * name and by kop_normalize_facility_name(), like the legacy map built
     * from facilities_master.unique_name, and additionally by each facility's
     * own name so a renamed facility still resolves.
     *
     * @return array<string,int>
     */
    function kop_v2_name_id_map() {
        static $map = null;
        if ($map !== null) return $map;
        global $wpdb;
        $map = array();
        if (!kop_v2_tables_ready()) return $map;

        $add = function ($name, $id, $normalized) use (&$map) {
            $name = trim((string)$name);
            if ($name === '' || $id <= 0) return;
            $key = $normalized ? kop_normalize_facility_name($name) : strtolower($name);
            if ($key !== '' && !isset($map[$key])) $map[$key] = $id;
        };

        // The legacy map took whichever row the name index happened to return
        // first, so its tie-breaks were incidental. The rule here is stated:
        // an exact name beats a normalized one ("Three Points Center" is the
        // facility of that name, not "Three Points Center, LLC"), a row name
        // beats a display name, and a facility beats an operator of the same
        // name. Within a pass the lowest id wins.
        $by_id = function ($a, $b) { return (int)$a['id'] <=> (int)$b['id']; };
        $facilities = (array)$wpdb->get_results("SELECT id, unique_name, name FROM facilities_v2 ORDER BY id", ARRAY_A);
        $operators = (array)$wpdb->get_results("SELECT id, unique_name, name FROM {$wpdb->prefix}kop_operators ORDER BY id", ARRAY_A);
        usort($facilities, $by_id);
        usort($operators, $by_id);
        foreach (array('unique_name', 'name') as $field) {
            foreach (array(false, true) as $normalized) {
                foreach (array($facilities, $operators) as $set) {
                    foreach ($set as $row) {
                        $add($row[$field], (int)$row['id'], $normalized);
                    }
                }
            }
        }
        return $map;
    }
}

if (!function_exists('kop_v2_facility_state_map')) {
    /**
     * Normalized facility name => the state codes facilities of that name are
     * in. Replaces the scan of every nested facility in facilities_master that
     * decides which state page an inspection row belongs on.
     *
     * Only each facility's own state counts, as in the legacy map: a former or
     * additional location is not where the facility is inspected, and counting
     * it would make the name ambiguous and stop the row being placed at all.
     *
     * @return array<string,string[]>
     */
    function kop_v2_facility_state_map() {
        static $map = null;
        if ($map !== null) return $map;
        global $wpdb;
        $map = array();
        if (!kop_v2_tables_ready()) return $map;

        $sets = array();
        $rows = $wpdb->get_results("SELECT name, state FROM facilities_v2 WHERE state IS NOT NULL AND state <> ''", ARRAY_A);
        foreach ((array)$rows as $row) {
            $key = kop_normalize_facility_name($row['name'] ?? '');
            if ($key === '') continue;
            $sets[$key][strtoupper($row['state'])] = true;
        }
        foreach ($sets as $key => $set) $map[$key] = array_keys($set);
        return $map;
    }
}

if (!function_exists('kop_v2_decode')) {
    /** A stored v2 document, or null. */
    function kop_v2_decode($json) {
        $doc = json_decode((string)$json, true);
        return (is_array($doc) && isset($doc['location'], $doc['identification'])) ? $doc : null;
    }
}

// ---------------------------------------------------------------------------
// location_pages
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_collect_programs')) {
    /**
     * Program records for one state or country page from the v2 tables.
     * One record per facility id: no name-based merging.
     *
     * @param string $page_name 'Utah' or 'Mexico'
     * @param string $kind      'state' or 'country'
     */
    function kop_v2_collect_programs($page_name, $kind) {
        global $wpdb;
        $locations = $wpdb->prefix . 'kop_facility_locations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id, f.unique_name, f.json_data, f.updated_at
               FROM facilities_v2 f
              WHERE f.id IN (SELECT l.facility_id FROM {$locations} l WHERE l.location_key = %s)
              ORDER BY f.id",
            mb_strtoupper($page_name)
        ), ARRAY_A);
        if (!is_array($rows) || !$rows) return array();

        $operators = kop_v2_operators_for_facilities(array_map('intval', wp_list_pluck($rows, 'id')));

        $programs = array();
        foreach ($rows as $row) {
            $doc = kop_v2_decode($row['json_data']);
            if ($doc === null) continue;
            $legacy = kop_facility_to_legacy($doc);
            $data = array('facilities' => array($legacy));
            $fid = (int)$row['id'];
            if (isset($operators[$fid])) $data['operator'] = $operators[$fid]['operator'];

            $meta = array('master_id' => $fid, 'facility_count' => 1, 'updated_at' => (string)$row['updated_at']);
            if ($kind === 'country') {
                $meta['default_country'] = $page_name;
                $built = kop_state_build_program_record($row['unique_name'], $legacy, $data, '', $meta);
            } else {
                $built = kop_state_build_program_record($row['unique_name'], $legacy, $data, $page_name, $meta);
            }
            if ($built !== null) $programs[] = $built['program'];
        }

        usort($programs, static function ($a, $b) {
            return strnatcasecmp($a['facility_name'] ?: $a['project_name'], $b['facility_name'] ?: $b['project_name']);
        });
        return $programs;
    }
}

if (!function_exists('kop_v2_operators_for_facilities')) {
    /**
     * First operator of each facility: facility id => {id, name, operator}.
     *
     * @param int[] $ids
     */
    function kop_v2_operators_for_facilities(array $ids) {
        global $wpdb;
        $out = array();
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return $out;
        $rows = $wpdb->get_results(
            "SELECT ofc.facility_id, o.id, o.name, o.json_data
               FROM {$wpdb->prefix}kop_operator_facilities ofc
               JOIN {$wpdb->prefix}kop_operators o ON o.id = ofc.operator_id
              WHERE ofc.facility_id IN (" . implode(',', $ids) . ")
              ORDER BY ofc.sort_order, o.id",
            ARRAY_A
        );
        foreach ((array)$rows as $r) {
            $fid = (int)$r['facility_id'];
            if (isset($out[$fid])) continue;
            $decoded = json_decode($r['json_data'], true);
            $out[$fid] = array(
                'id'       => (int)$r['id'],
                'name'     => (string)$r['name'],
                'operator' => (is_array($decoded) && isset($decoded['operator']) && is_array($decoded['operator'])) ? $decoded['operator'] : array(),
            );
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// program_index
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_get_facilities_projects')) {
    /**
     * The kop/v1/facilities payload built from v2, in the legacy shape the
     * directory scripts read: {source, projects: {unique_name: project}}.
     *
     * - One project per operator (kop_operators), its facilities from
     *   kop_operator_facilities, each the legacy projection with facility_id.
     * - One project per location key (the state/country aggregates), from
     *   kop_facility_locations.
     * - Referrers and transporters are out of scope and still come from their
     *   legacy tables.
     * News, lawsuits, memorials and inspection stats are attached by the same
     * functions as before; ids are unchanged by the migration.
     */
    function kop_v2_get_facilities_projects() {
        global $wpdb;

        $legacy = kop_get_facilities_projects_from_database(array('referrers_master', 'transporters_master'), false);
        $projects = (is_array($legacy) && isset($legacy['projects'])) ? $legacy['projects'] : array();

        // Every facility once, projected to the legacy nested shape.
        $facilities = array();
        foreach ((array)$wpdb->get_results("SELECT id, json_data FROM facilities_v2", ARRAY_A) as $row) {
            $doc = kop_v2_decode($row['json_data']);
            if ($doc === null) continue;
            $facilities[(int)$row['id']] = kop_facility_to_legacy($doc);
        }

        $by_operator = array();
        foreach ((array)$wpdb->get_results("SELECT operator_id, facility_id FROM {$wpdb->prefix}kop_operator_facilities ORDER BY operator_id, sort_order, facility_id", ARRAY_A) as $r) {
            if (isset($facilities[(int)$r['facility_id']])) {
                $by_operator[(int)$r['operator_id']][] = $facilities[(int)$r['facility_id']];
            }
        }

        $wrapper_keys = array('name', 'category', 'timestamp', 'currentFacilityIndex', '__facility_ref');
        foreach ((array)$wpdb->get_results("SELECT id, unique_name, name, json_data, document_folder_id, updated_at FROM {$wpdb->prefix}kop_operators ORDER BY id", ARRAY_A) as $op) {
            $stored = json_decode($op['json_data'], true);
            $blocks = (is_array($stored) && isset($stored['legacy_blocks']) && is_array($stored['legacy_blocks'])) ? $stored['legacy_blocks'] : array();
            $data = array();
            foreach ($blocks as $k => $v) {
                if (!in_array($k, $wrapper_keys, true)) $data[$k] = $v;
            }
            $data['operator'] = (is_array($stored) && isset($stored['operator']) && is_array($stored['operator'])) ? $stored['operator'] : array();
            if ($op['document_folder_id'] !== null) $data['documentFolderId'] = (int)$op['document_folder_id'];
            $data['facilities'] = $by_operator[(int)$op['id']] ?? array();

            $projects[$op['unique_name']] = array(
                'id'                   => (int)$op['id'],
                'source_table'         => 'facilities_master',
                'name'                 => isset($blocks['name']) && is_string($blocks['name']) ? $blocks['name'] : $op['unique_name'],
                'label'                => $op['unique_name'],
                'data'                 => $data,
                'category'             => isset($blocks['category']) && is_string($blocks['category']) ? $blocks['category'] : 'companies',
                'timestamp'            => isset($blocks['timestamp']) && is_string($blocks['timestamp']) ? $blocks['timestamp'] : (string)$op['updated_at'],
                'currentFacilityIndex' => isset($blocks['currentFacilityIndex']) ? (int)$blocks['currentFacilityIndex'] : 0,
            );
        }

        $by_location = array();
        foreach ((array)$wpdb->get_results("SELECT location_key, facility_id FROM {$wpdb->prefix}kop_facility_locations WHERE role <> 'unknown' ORDER BY location_key, facility_id", ARRAY_A) as $r) {
            if (isset($facilities[(int)$r['facility_id']])) {
                $by_location[$r['location_key']][(int)$r['facility_id']] = $facilities[(int)$r['facility_id']];
            }
        }
        foreach ($by_location as $key => $list) {
            $projects[$key] = array(
                'id'                   => null,
                'source_table'         => 'locations_master',
                'name'                 => $key,
                'label'                => $key,
                'data'                 => array('facilities' => array_values($list)),
                'category'             => 'locations',
                'timestamp'            => current_time('mysql'),
                'currentFacilityIndex' => 0,
            );
        }

        kop_attach_linked_news_to_projects($wpdb, $projects);
        kop_attach_linked_lawsuits_to_projects($wpdb, $projects);
        kop_attach_memorials_to_projects($wpdb, $projects);
        kop_attach_inspection_stats_to_projects($wpdb, $projects);

        return array('source' => 'database-v2', 'projects' => $projects);
    }
}

// ---------------------------------------------------------------------------
// facility_profiles
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_profile_record')) {
    /**
     * The record a facility profile page shows, by unique_name.
     *
     * @return array|null {id, facility (legacy projection)}
     */
    function kop_v2_profile_record(PDO $pdo, $unique_name) {
        $stmt = $pdo->prepare('SELECT id, json_data FROM facilities_v2 WHERE unique_name = ? LIMIT 1');
        $stmt->execute(array($unique_name));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $doc = kop_v2_decode($row['json_data']);
        if ($doc === null) return null;
        return array('id' => (int)$row['id'], 'facility' => kop_facility_to_legacy($doc));
    }
}

// ---------------------------------------------------------------------------
// search
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_place_page_url')) {
    /** Permalink of the published state or country hub page for a place, or ''. */
    function kop_v2_place_page_url($state_code, $country) {
        static $cache = array();
        $slug = '';
        if ($state_code && function_exists('kop_state_slug') && function_exists('kop_state_canonical_name')) {
            $slug = kop_state_slug(kop_state_canonical_name($state_code));
        } elseif ($country && $country !== 'United States' && function_exists('kop_country_slug')) {
            $slug = kop_country_slug($country);
        }
        if ($slug === '') return '';
        if (!array_key_exists($slug, $cache)) {
            $page = get_page_by_path($slug);
            $cache[$slug] = ($page && $page->post_status === 'publish') ? get_permalink($page) : '';
        }
        return $cache[$slug];
    }
}

if (!function_exists('kop_v2_search')) {
    /**
     * Operators, facilities and places matching a phrase, from v2.
     * Each item: {kind, display, operator, location, fac_count, url}. `url` is
     * the facility's state or country page when one exists, '' otherwise (the
     * caller then links to the program index search).
     */
    function kop_v2_search($phrase, $facility_limit = 10, $operator_limit = 5, $place_limit = 3) {
        global $wpdb;
        $phrase = trim((string)$phrase);
        $out = array('operators' => array(), 'facilities' => array(), 'places' => array());
        if ($phrase === '') return $out;
        $like = '%' . $wpdb->esc_like($phrase) . '%';
        $key_like = '%' . $wpdb->esc_like(kop_facility_name_key($phrase)) . '%';

        $ops = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id, o.name, o.json_data,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}kop_operator_facilities ofc WHERE ofc.operator_id = o.id) AS n
               FROM {$wpdb->prefix}kop_operators o
              WHERE o.name LIKE %s OR o.unique_name LIKE %s
              ORDER BY n DESC, o.name
              LIMIT %d",
            $like, $like, $operator_limit
        ), ARRAY_A);
        foreach ((array)$ops as $o) {
            $stored = json_decode($o['json_data'], true);
            $op = (is_array($stored) && isset($stored['operator'])) ? $stored['operator'] : array();
            $hq = isset($op['headquarters']) && is_string($op['headquarters']) ? $op['headquarters'] : '';
            $out['operators'][] = array(
                'kind' => 'operator', 'display' => $o['name'], 'operator' => $o['name'],
                'location' => $hq, 'fac_count' => (int)$o['n'], 'url' => '',
            );
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, state, city, country, status
               FROM facilities_v2
              WHERE name LIKE %s OR name_key LIKE %s OR unique_name LIKE %s
              ORDER BY (name LIKE %s) DESC, name
              LIMIT %d",
            $like, $key_like, $like, $wpdb->esc_like($phrase) . '%', $facility_limit
        ), ARRAY_A);
        $operators = kop_v2_operators_for_facilities(array_map('intval', wp_list_pluck((array)$rows, 'id')));
        foreach ((array)$rows as $r) {
            $place = $r['state'] ? trim($r['city'] . ', ' . $r['state'], ', ') : trim($r['city'] . ', ' . $r['country'], ', ');
            $op_name = isset($operators[(int)$r['id']]) ? $operators[(int)$r['id']]['name'] : '';
            $out['facilities'][] = array(
                'kind' => 'facility', 'display' => $r['name'], 'operator' => $op_name,
                'location' => $place . ($r['status'] && $r['status'] !== 'Unknown' ? ' (' . $r['status'] . ')' : ''),
                'fac_count' => 0, 'url' => kop_v2_place_page_url($r['state'], $r['country']),
            );
        }

        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT location_key FROM {$wpdb->prefix}kop_facility_locations
              WHERE location_key LIKE %s AND location_key <> 'UNKNOWN'
              GROUP BY location_key LIMIT %d",
            $like, $place_limit
        ));
        foreach ((array)$keys as $key) {
            $code = kop_facility_state_code($key);
            $name = function_exists('kop_state_canonical_name') && $code ? kop_state_canonical_name($code) : ucwords(strtolower($key));
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT facility_id) FROM {$wpdb->prefix}kop_facility_locations WHERE location_key = %s", $key
            ));
            $out['places'][] = array(
                'kind' => 'place', 'display' => $name, 'operator' => '', 'location' => '',
                'fac_count' => $count, 'url' => kop_v2_place_page_url($code, $code ? null : $name),
            );
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// homepage_stats
// ---------------------------------------------------------------------------

if (!function_exists('kop_v2_facility_count')) {
    /** Distinct facilities (operators and duplicate copies are not facilities). */
    function kop_v2_facility_count() {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM facilities_v2");
    }
}

if (!function_exists('kop_v2_recent_facilities')) {
    /** Most recently updated facilities: list of {name, meta}. */
    function kop_v2_recent_facilities($limit) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT name, city, state, country FROM facilities_v2 ORDER BY updated_at DESC, id DESC LIMIT %d",
            $limit
        ), ARRAY_A);
        $out = array();
        foreach ((array)$rows as $r) {
            $out[] = array(
                'name' => $r['name'],
                'meta' => $r['state'] ? trim($r['city'] . ', ' . $r['state'], ', ') : trim($r['city'] . ', ' . $r['country'], ', '),
            );
        }
        return $out;
    }
}
