<?php
/**
 * People: one id per person named in the records.
 *
 * A staff entry used to be only a name, matched across records by name at
 * read time. Each person now has a row with a stable id, and every staff
 * entry carries it:
 *
 *   {prefix}kop_people        one row per person: id (never reused), name,
 *                             name_key (kop_facility_pages_person_key),
 *                             aliases (other spellings, one per line),
 *                             merged_into (set when the row turned out to
 *                             be someone who already had an id), notes.
 *   {prefix}kop_person_roles  DERIVED: where each person is named
 *                             (facility staff.administrator/notableStaff,
 *                             operator keyStaff founders/keyExecutives/ceo,
 *                             network map person nodes, ref = node id),
 *                             rebuilt from the records by every sync.
 *
 * facilities_v2 staff entries keep their id as `personId`
 * ({name, role, pastJobs, personId}; kop_facility_person_list() and the
 * form's v2PersonList() keep it). The hourly sync (kop_people_sync) gives
 * every entry without one the id of the person with the same name key, or a
 * new id, and stamps it into the document. An entry whose name no longer
 * matches its person (the name was changed to someone else) is matched again
 * by name. Operator records and map nodes are linked by name only: their
 * documents are not rewritten here. The map build
 * (scripts/build-network-graph.js, readPeople) reads this table from the
 * mirror, stamps personId on each person node, and draws everyone one person
 * id covers as one node.
 *
 * Two people with one name: "Separate" on KOP Tools > People gives one entry
 * a new id with the same name key; the sync keeps it. A new entry with that
 * name goes to the lowest id. One person under two names: KOP Tools > Merge
 * People (inc/people-merge.php) or "Same person as" merges the row into
 * another (merged_into), its names become aliases, and the sync moves the
 * entries; every merge can be undone.
 *
 * Pages group a person's records by kop_people_group_key(): the person's id
 * when known, so a merge joins two names and a separation splits one.
 *
 * Names with fewer than two words ("Jamie", "Admissions") get no id.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PEOPLE_DB_VERSION', '2');
define('KOP_PEOPLE_LISTS', 'administrator,notableStaff');

if (!function_exists('kop_people_table')) {
    function kop_people_table($base, array $opts = array()) {
        $prefix = isset($opts['prefix']) ? (string) $opts['prefix'] : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        return $prefix . ($base === 'roles' ? 'kop_person_roles' : 'kop_people');
    }
}

if (!function_exists('kop_people_install')) {
    /** Creates the two tables (MySQL) once per KOP_PEOPLE_DB_VERSION. */
    function kop_people_install($force = false) {
        if (!$force && get_option('kop_people_db') === KOP_PEOPLE_DB_VERSION) return;
        global $wpdb;
        $people = kop_people_table('people');
        $roles = kop_people_table('roles');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$people} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            name_key VARCHAR(191) NOT NULL,
            aliases TEXT NULL,
            merged_into BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name_key (name_key),
            KEY merged_into (merged_into)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$roles} (
            record_kind VARCHAR(10) NOT NULL,
            record_id BIGINT UNSIGNED NOT NULL,
            list VARCHAR(20) NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            role TEXT NULL,
            ref VARCHAR(191) NOT NULL DEFAULT '',
            PRIMARY KEY (record_kind, record_id, list, position),
            KEY person_id (person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Version 1 tables had no ref column (the map node id).
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$roles} LIKE 'ref'")) {
            $wpdb->query("ALTER TABLE {$roles} ADD COLUMN ref VARCHAR(191) NOT NULL DEFAULT ''");
        }
        update_option('kop_people_db', KOP_PEOPLE_DB_VERSION, false);
    }
    add_action('admin_init', 'kop_people_install');
}

if (!function_exists('kop_people_key')) {
    /** The match key for a name, '' when it cannot be matched (one word). */
    function kop_people_key($name) {
        return function_exists('kop_facility_pages_person_key') ? kop_facility_pages_person_key((string) $name) : '';
    }
}

if (!function_exists('kop_people_display_name')) {
    /** 'Admissions: Glenda "Glen" Roach (Current)' -> "Glenda Roach": the name a new person row gets. */
    function kop_people_display_name($raw) {
        $name = preg_replace('/^[A-Za-z][A-Za-z &\/-]{2,40}:\s*/', '', (string) $raw);
        $name = preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]|\([^)]*\)/u', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name !== '' ? $name : trim((string) $raw);
    }
}

if (!function_exists('kop_people_version_bump')) {
    /** Pages cache people by this (kop_facility_pages_people_index). */
    function kop_people_version_bump() {
        if (function_exists('update_option')) update_option('kop_people_version', (string) microtime(true), false);
        $GLOBALS['kop_people_group_state'] = null;
    }
}

if (!function_exists('kop_people_alias_list')) {
    function kop_people_alias_list($text) {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $a) {
            $a = trim($a);
            if ($a !== '' && !in_array($a, $out, true)) $out[] = $a;
        }
        return $out;
    }
}

if (!function_exists('kop_people_load')) {
    /**
     * Every person row, with the lookups the sync needs:
     * {rows: {id: row}, by_key: {key: [ids not merged, lowest first]},
     * keys: {id: {key: true}}} (a row's name key plus its aliases' keys).
     */
    function kop_people_load(array $opts = array()) {
        $rows = array();
        foreach (kop_facility_db_rows('SELECT id, name, name_key, aliases, merged_into, notes FROM ' . kop_people_table('people', $opts) . ' ORDER BY id', array(), $opts) as $r) {
            $r['id'] = (int) $r['id'];
            $r['merged_into'] = $r['merged_into'] !== null && (int) $r['merged_into'] > 0 ? (int) $r['merged_into'] : 0;
            $rows[$r['id']] = $r;
        }
        $state = array('rows' => $rows, 'by_key' => array(), 'keys' => array());
        foreach ($rows as $id => $r) kop_people_index_row($state, $id);
        return $state;
    }
}

if (!function_exists('kop_people_index_row')) {
    function kop_people_index_row(array &$state, $id) {
        $r = $state['rows'][$id];
        $keys = array();
        if ($r['name_key'] !== '') $keys[$r['name_key']] = true;
        foreach (kop_people_alias_list($r['aliases']) as $a) {
            $k = kop_people_key($a);
            if ($k !== '') $keys[$k] = true;
        }
        $state['keys'][$id] = $keys;
        if ($r['merged_into']) return;
        foreach (array_keys($keys) as $k) {
            if (!isset($state['by_key'][$k])) $state['by_key'][$k] = array();
            if (!in_array($id, $state['by_key'][$k], true)) {
                $state['by_key'][$k][] = $id;
                sort($state['by_key'][$k]);
            }
        }
    }
}

if (!function_exists('kop_people_resolve')) {
    /** The id a person row now lives under (follows merged_into), 0 if unknown. */
    function kop_people_resolve(array $state, $id) {
        $id = (int) $id;
        $seen = array();
        while ($id > 0 && isset($state['rows'][$id]) && $state['rows'][$id]['merged_into'] && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $state['rows'][$id]['merged_into'];
        }
        return isset($state['rows'][$id]) ? $id : 0;
    }
}

if (!function_exists('kop_people_create')) {
    function kop_people_create(array &$state, $name, $key, array $opts = array()) {
        kop_facility_db_exec('INSERT INTO ' . kop_people_table('people', $opts) . ' (name, name_key, aliases, notes) VALUES (?, ?, ?, ?)',
            array((string) $name, (string) $key, '', ''), $opts);
        $id = kop_facility_db_insert_id($opts);
        $state['rows'][$id] = array('id' => $id, 'name' => (string) $name, 'name_key' => (string) $key, 'aliases' => '', 'merged_into' => 0, 'notes' => '');
        kop_people_index_row($state, $id);
        return $id;
    }
}

if (!function_exists('kop_people_assign')) {
    /**
     * The person id for one entry: its own personId while that person still
     * answers to the name, else the lowest id with the name's key, else a new
     * one. 0 when the name has no key.
     */
    function kop_people_assign(array &$state, $name, $current, array $opts = array(), &$created = 0) {
        $key = kop_people_key($name);
        if ($key === '') return 0;
        $pid = kop_people_resolve($state, $current);
        if ($pid > 0 && isset($state['keys'][$pid][$key])) return $pid;
        // A merged-away row's keys count for the row it went into.
        if ($pid > 0 && (int) $current !== $pid && isset($state['keys'][(int) $current][$key])) return $pid;
        if (!empty($state['by_key'][$key])) return $state['by_key'][$key][0];
        $created++;
        return kop_people_create($state, kop_people_display_name($name), $key, $opts);
    }
}

if (!function_exists('kop_people_map_graph')) {
    /** The network map graph (nodes, edges), or null. */
    function kop_people_map_graph(array $opts = array()) {
        if (array_key_exists('graph', $opts)) return $opts['graph'];
        if (function_exists('kop_network_map_graph')) {
            $g = kop_network_map_graph();
            if (is_array($g)) return $g;
        }
        $file = dirname(__DIR__) . '/js/data/network/graph.json';
        $g = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($g) ? $g : null;
    }
}

if (!function_exists('kop_people_sync')) {
    /**
     * Gives every staff entry a person id, stamps it into facilities_v2,
     * gives every network map person an id, and rebuilds kop_person_roles.
     *
     * @param array $opts pdo, prefix (as kop_facility_db_rows), dry_run,
     *                    graph (the map graph; default the deployed one).
     * @return array {facilities, entries, stamped, docs_written, created, map, roles, unkeyed}
     */
    function kop_people_sync(array $opts = array()) {
        $state = kop_people_load($opts);
        $stats = array('facilities' => 0, 'entries' => 0, 'stamped' => 0, 'docs_written' => 0, 'created' => 0, 'map' => 0, 'roles' => 0, 'unkeyed' => 0);
        $roles = array();
        $lists = explode(',', KOP_PEOPLE_LISTS);
        $facilities = kop_facility_table('facilities', $opts);
        $dry = !empty($opts['dry_run']);

        foreach (kop_facility_db_rows("SELECT id, json_data FROM {$facilities} ORDER BY id", array(), $opts) as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            if (!is_array($doc) || empty($doc['staff']) || !is_array($doc['staff'])) continue;
            $fid = (int) $row['id'];
            $changed = false;
            $had = false;
            foreach ($lists as $list) {
                if (empty($doc['staff'][$list]) || !is_array($doc['staff'][$list])) continue;
                foreach ($doc['staff'][$list] as $pos => $entry) {
                    if (!is_array($entry)) continue;
                    $had = true;
                    $stats['entries']++;
                    $name = (string) ($entry['name'] ?? '');
                    $current = (int) ($entry['personId'] ?? 0);
                    $pid = kop_people_assign($state, $name, $current, $opts, $stats['created']);
                    if ($pid === 0) {
                        $stats['unkeyed']++;
                        if (isset($entry['personId'])) {
                            unset($doc['staff'][$list][$pos]['personId']);
                            $changed = true;
                        }
                        continue;
                    }
                    if ($current !== $pid) {
                        $doc['staff'][$list][$pos]['personId'] = $pid;
                        $changed = true;
                        $stats['stamped']++;
                    }
                    $roles[] = array('facility', $fid, $list, (int) $pos, $pid, $name, (string) ($entry['role'] ?? ''), '');
                }
            }
            if ($had) $stats['facilities']++;
            if ($changed) {
                $stats['docs_written']++;
                if (!$dry) {
                    kop_facility_db_exec("UPDATE {$facilities} SET json_data = ? WHERE id = ?", array(kop_facility_json_encode($doc), $fid), $opts);
                }
            }
        }

        // Operators: linked by name, their documents are not rewritten.
        $operators = kop_facility_table('operators', $opts);
        foreach (kop_facility_db_rows("SELECT id, json_data FROM {$operators} ORDER BY id", array(), $opts) as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            $staff = is_array($doc) && isset($doc['operator']['keyStaff']) && is_array($doc['operator']['keyStaff']) ? $doc['operator']['keyStaff'] : array();
            $people = array();
            foreach (array('founders', 'keyExecutives') as $list) {
                foreach ((isset($staff[$list]) && is_array($staff[$list]) ? $staff[$list] : array()) as $pos => $p) {
                    $people[] = array($list, (int) $pos, is_array($p) ? (string) ($p['name'] ?? '') : (string) $p, is_array($p) ? (string) ($p['role'] ?? '') : '');
                }
            }
            if (!empty($staff['ceo']) && is_string($staff['ceo'])) $people[] = array('ceo', 0, $staff['ceo'], 'CEO');
            foreach ($people as $p) {
                $pid = kop_people_assign($state, $p[2], 0, $opts, $stats['created']);
                if ($pid > 0) $roles[] = array('operator', (int) $row['id'], $p[0], $p[1], $pid, $p[2], $p[3], '');
            }
        }

        // Network map people: the id the node had at the last sync (or the build stamped on it) while it
        // still fits, else by name. Two nodes are two people until merged: the board drew them apart
        // ("Robert W. Lichfield", "Robert B. Lichfield"), so a second node with a taken key gets its own id.
        $graph = kop_people_map_graph($opts);
        $before = array();
        foreach (kop_facility_db_rows('SELECT ref, person_id FROM ' . kop_people_table('roles', $opts) . " WHERE record_kind = 'map'", array(), $opts) as $r) {
            $before[(string) $r['ref']] = (int) $r['person_id'];
        }
        $map_taken = array();
        $pos = 0;
        foreach ((array) ($graph['nodes'] ?? array()) as $node) {
            if (($node['kind'] ?? '') !== 'person') continue;
            $ref = (string) ($node['id'] ?? '');
            $names = array_merge(array((string) ($node['name'] ?? '')), array_map('strval', (array) ($node['aliases'] ?? array())));
            $current = (int) ($node['personId'] ?? 0) ?: ($before[$ref] ?? 0);
            $pid = 0;
            foreach ($names as $n) {
                $key = kop_people_key($n);
                if ($key === '') continue;
                $pid = kop_people_assign($state, $n, $current, $opts, $stats['created']);
                if ($pid && isset($map_taken[$pid]) && $map_taken[$pid] !== $ref && kop_people_resolve($state, $current) !== $pid) {
                    $stats['created']++;
                    $pid = kop_people_create($state, kop_people_display_name($n), $key, $opts);
                }
                if ($pid) break;
            }
            if (!$pid) continue;
            if (!isset($map_taken[$pid])) $map_taken[$pid] = $ref;
            $stats['map']++;
            $roles[] = array('map', 0, 'map', $pos++, $pid, (string) $node['name'], '', (string) ($node['id'] ?? ''));
        }

        $stats['roles'] = count($roles);
        if (!$dry) {
            $t = kop_people_table('roles', $opts);
            kop_facility_db_exec("DELETE FROM {$t}", array(), $opts);
            foreach (array_chunk($roles, 200) as $chunk) {
                $sql = "INSERT INTO {$t} (record_kind, record_id, list, position, person_id, name, role, ref) VALUES "
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $params = array();
                foreach ($chunk as $r) foreach ($r as $v) $params[] = $v;
                kop_facility_db_exec($sql, $params, $opts);
            }
            if (function_exists('update_option')) update_option('kop_people_last_sync', array('at' => time()) + $stats, false);
            if ($stats['created'] || $stats['stamped'] || $stats['docs_written']) kop_people_version_bump();
        }
        return $stats;
    }
}

if (!function_exists('kop_people_merge')) {
    /**
     * Person $from is person $into: $from's name and aliases join $into's
     * aliases, $from points at $into, and the sync moves the entries.
     * Returns what kop_people_unmerge() needs to put it back, or false.
     */
    function kop_people_merge($from, $into, array $opts = array()) {
        $state = kop_people_load($opts);
        $from = (int) $from;
        $into = kop_people_resolve($state, $into);
        if (!isset($state['rows'][$from]) || $state['rows'][$from]['merged_into'] || $into === 0 || $into === $from) return false;
        $target = $state['rows'][$into];
        $aliases = kop_people_alias_list($target['aliases']);
        $source = $state['rows'][$from];
        foreach (array_merge(array($source['name']), kop_people_alias_list($source['aliases'])) as $a) {
            if ($a !== '' && strcasecmp($a, $target['name']) !== 0 && !in_array($a, $aliases, true)) $aliases[] = $a;
        }
        $t = kop_people_table('people', $opts);
        $children = array();
        foreach ($state['rows'] as $r) if ($r['merged_into'] === $from) $children[] = $r['id'];
        // The entries stamped with $from, so Undo can give them back.
        $entries = array();
        foreach (kop_facility_db_rows('SELECT record_id, list, position, name FROM ' . kop_people_table('roles', $opts)
            . " WHERE person_id = ? AND record_kind = 'facility'", array($from), $opts) as $r) {
            $entries[] = array((int) $r['record_id'], (string) $r['list'], (int) $r['position'], (string) $r['name']);
        }
        kop_facility_db_exec("UPDATE {$t} SET aliases = ? WHERE id = ?", array(implode("\n", $aliases), $into), $opts);
        kop_facility_db_exec("UPDATE {$t} SET merged_into = ? WHERE id = ?", array($into, $from), $opts);
        // Rows merged into $from now go straight to $into.
        kop_facility_db_exec("UPDATE {$t} SET merged_into = ? WHERE merged_into = ?", array($into, $from), $opts);
        kop_people_version_bump();
        return array('from' => $from, 'into' => $into, 'into_aliases' => (string) $target['aliases'], 'children' => $children, 'entries' => $entries);
    }
}

if (!function_exists('kop_people_unmerge')) {
    /**
     * Puts a merge back (the array kop_people_merge returned): $from is its
     * own person again, $into's other names are as they were, and the
     * entries that were $from's (found by facility, list and name) are
     * $from's again. Run the sync afterwards.
     */
    function kop_people_unmerge(array $undo, array $opts = array()) {
        $from = (int) $undo['from'];
        $into = (int) $undo['into'];
        $t = kop_people_table('people', $opts);
        kop_facility_db_exec("UPDATE {$t} SET aliases = ? WHERE id = ?", array((string) $undo['into_aliases'], $into), $opts);
        kop_facility_db_exec("UPDATE {$t} SET merged_into = NULL WHERE id = ?", array($from), $opts);
        foreach ((array) $undo['children'] as $c) {
            kop_facility_db_exec("UPDATE {$t} SET merged_into = ? WHERE id = ?", array($from, (int) $c), $opts);
        }
        $by_fid = array();
        foreach ((array) $undo['entries'] as $e) $by_fid[(int) $e[0]][] = $e;
        $moved = 0;
        foreach ($by_fid as $fid => $list) {
            $loaded = kop_facility_load($fid, $opts);
            if (!$loaded) continue;
            $doc = $loaded['doc'];
            $changed = false;
            foreach ($list as $e) {
                list(, $l, $pos, $name) = $e;
                $entries = isset($doc['staff'][$l]) && is_array($doc['staff'][$l]) ? $doc['staff'][$l] : array();
                // Where it was, else wherever that name now stands under $into.
                $at = null;
                if (isset($entries[$pos]) && (string) ($entries[$pos]['name'] ?? '') === $name) {
                    $at = $pos;
                } else {
                    foreach ($entries as $i => $x) {
                        if (is_array($x) && (string) ($x['name'] ?? '') === $name && (int) ($x['personId'] ?? 0) === $into) { $at = $i; break; }
                    }
                }
                if ($at === null) continue;
                $doc['staff'][$l][$at]['personId'] = $from;
                $changed = true;
                $moved++;
            }
            if ($changed) {
                kop_facility_db_exec('UPDATE ' . kop_facility_table('facilities', $opts) . ' SET json_data = ? WHERE id = ?',
                    array(kop_facility_json_encode($doc), $fid), $opts);
            }
        }
        kop_people_version_bump();
        return $moved;
    }
}

if (!function_exists('kop_people_separate')) {
    /**
     * Gives one facility staff entry a new person id (two people, one name).
     * Returns the new id, or 0 when the entry is not there.
     */
    function kop_people_separate($facility_id, $list, $position, array $opts = array()) {
        if (!in_array($list, explode(',', KOP_PEOPLE_LISTS), true)) return 0;
        $loaded = kop_facility_load((int) $facility_id, $opts);
        $entry = $loaded['doc']['staff'][$list][(int) $position] ?? null;
        if (!is_array($entry)) return 0;
        $key = kop_people_key($entry['name'] ?? '');
        if ($key === '') return 0;
        $state = kop_people_load($opts);
        $pid = kop_people_create($state, kop_people_display_name($entry['name']), $key, $opts);
        $was = kop_people_resolve($state, (int) ($entry['personId'] ?? 0));
        $loaded['doc']['staff'][$list][(int) $position]['personId'] = $pid;
        kop_facility_db_exec('UPDATE ' . kop_facility_table('facilities', $opts) . ' SET json_data = ? WHERE id = ?',
            array(kop_facility_json_encode($loaded['doc']), (int) $facility_id), $opts);
        // Two people on purpose: Merge People does not offer the pair again.
        if ($was && function_exists('get_option')) {
            $dis = get_option('kop_people_merge_dismissed', array());
            $dis = is_array($dis) ? $dis : array();
            $dis[min($was, $pid) . ':' . max($was, $pid)] = array('by' => 'Separate', 'at' => gmdate('Y-m-d H:i:s'));
            update_option('kop_people_merge_dismissed', $dis, false);
        }
        kop_people_version_bump();
        return $pid;
    }
}

if (!function_exists('kop_people_roles_of')) {
    /** kop_person_roles rows for one person id: facilities, then companies, then the map. */
    function kop_people_roles_of($person_id, array $opts = array()) {
        return kop_facility_db_rows('SELECT record_kind, record_id, list, position, name, role, ref FROM ' . kop_people_table('roles', $opts)
            . " WHERE person_id = ? ORDER BY CASE record_kind WHEN 'facility' THEN 0 WHEN 'operator' THEN 1 ELSE 2 END, record_id, list, position",
            array((int) $person_id), $opts);
    }
}

if (!function_exists('kop_people_group_key')) {
    /**
     * What pages group one person's records by: "p<id>" for a known person
     * (their own personId, followed through merges, else the lowest id with
     * the name's key), else the name key. '' when the name has no key.
     */
    function kop_people_group_key($name, $person_id = 0) {
        $state = &$GLOBALS['kop_people_group_state'];
        if ($state === null) {
            $state = array('rows' => array(), 'by_key' => array(), 'keys' => array());
            if (function_exists('get_option') && get_option('kop_people_db') && isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_results')) {
                try {
                    $state = kop_people_load();
                } catch (Throwable $e) {
                }
            }
        }
        $key = kop_people_key($name);
        $pid = $person_id ? kop_people_resolve($state, $person_id) : 0;
        if (!$pid && $key !== '' && !empty($state['by_key'][$key])) $pid = $state['by_key'][$key][0];
        return $pid ? 'p' . $pid : $key;
    }
}

/* ---- Hourly sync -------------------------------------------------------- */

if (function_exists('add_action')) {
    add_action('init', function () {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('kop_people_sync_hourly')) {
            wp_schedule_event(time() + 900, 'hourly', 'kop_people_sync_hourly');
        }
    });
    add_action('kop_people_sync_hourly', 'kop_people_sync_cron');
}

if (!function_exists('kop_people_sync_cron')) {
    function kop_people_sync_cron() {
        if (get_transient('kop_people_sync_lock')) return;
        set_transient('kop_people_sync_lock', 1, 10 * MINUTE_IN_SECONDS);
        try {
            kop_people_install();
            kop_people_sync();
        } catch (Throwable $e) {
            error_log('kop_people_sync: ' . $e->getMessage());
        }
        delete_transient('kop_people_sync_lock');
    }
}
