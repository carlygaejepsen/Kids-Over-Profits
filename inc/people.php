<?php
/**
 * People: one id per person named in the facility records.
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
 *                             operator keyStaff founders/keyExecutives/ceo),
 *                             rebuilt from the records by every sync.
 *
 * facilities_v2 staff entries keep their id as `personId`
 * ({name, role, pastJobs, personId}; kop_facility_person_list() and the
 * form's v2PersonList() keep it). The hourly sync (kop_people_sync) gives
 * every entry without one the id of the person with the same name key, or a
 * new id, and stamps it into the document. An entry whose name no longer
 * matches its person (the name was changed to someone else) is matched again
 * by name. Operator records are linked by name only: their documents are not
 * rewritten.
 *
 * Two people with one name: "Separate" on KOP Tools > People gives one entry
 * a new id with the same name key; the sync keeps it. A new entry with that
 * name goes to the lowest id. One person under two names: "Same person as"
 * merges the row into another (merged_into), its names become aliases, and
 * the next sync moves the entries.
 *
 * Names with fewer than two words ("Jamie", "Admissions") get no id.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PEOPLE_DB_VERSION', '1');
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
            PRIMARY KEY (record_kind, record_id, list, position),
            KEY person_id (person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    /** "Admissions: Jane Doe (Current)" -> "Jane Doe": the name a new person row gets. */
    function kop_people_display_name($raw) {
        $name = preg_replace('/^[A-Za-z][A-Za-z &\/-]{2,40}:\s*/', '', (string) $raw);
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', ' ', $name)));
        return $name !== '' ? $name : trim((string) $raw);
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

if (!function_exists('kop_people_sync')) {
    /**
     * Gives every staff entry a person id, stamps it into facilities_v2,
     * and rebuilds kop_person_roles.
     *
     * @param array $opts pdo, prefix (as kop_facility_db_rows), dry_run.
     * @return array {facilities, entries, stamped, docs_written, created, roles, unkeyed}
     */
    function kop_people_sync(array $opts = array()) {
        $state = kop_people_load($opts);
        $stats = array('facilities' => 0, 'entries' => 0, 'stamped' => 0, 'docs_written' => 0, 'created' => 0, 'roles' => 0, 'unkeyed' => 0);
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
                    $roles[] = array('facility', $fid, $list, (int) $pos, $pid, $name, (string) ($entry['role'] ?? ''));
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
                if ($pid > 0) $roles[] = array('operator', (int) $row['id'], $p[0], $p[1], $pid, $p[2], $p[3]);
            }
        }

        $stats['roles'] = count($roles);
        if (!$dry) {
            $t = kop_people_table('roles', $opts);
            kop_facility_db_exec("DELETE FROM {$t}", array(), $opts);
            foreach (array_chunk($roles, 200) as $chunk) {
                $sql = "INSERT INTO {$t} (record_kind, record_id, list, position, person_id, name, role) VALUES "
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)'));
                $params = array();
                foreach ($chunk as $r) foreach ($r as $v) $params[] = $v;
                kop_facility_db_exec($sql, $params, $opts);
            }
            if (function_exists('update_option')) update_option('kop_people_last_sync', array('at' => time()) + $stats, false);
        }
        return $stats;
    }
}

if (!function_exists('kop_people_merge')) {
    /**
     * Person $from is person $into: $from's name and aliases join $into's
     * aliases, $from points at $into, and the sync moves the entries.
     */
    function kop_people_merge($from, $into, array $opts = array()) {
        $state = kop_people_load($opts);
        $from = (int) $from;
        $into = kop_people_resolve($state, $into);
        if (!isset($state['rows'][$from]) || $into === 0 || kop_people_resolve($state, $from) === $into) return false;
        $target = $state['rows'][$into];
        $aliases = kop_people_alias_list($target['aliases']);
        $source = $state['rows'][$from];
        foreach (array_merge(array($source['name']), kop_people_alias_list($source['aliases'])) as $a) {
            if ($a !== '' && strcasecmp($a, $target['name']) !== 0 && !in_array($a, $aliases, true)) $aliases[] = $a;
        }
        $t = kop_people_table('people', $opts);
        kop_facility_db_exec("UPDATE {$t} SET aliases = ? WHERE id = ?", array(implode("\n", $aliases), $into), $opts);
        kop_facility_db_exec("UPDATE {$t} SET merged_into = ? WHERE id = ?", array($into, $from), $opts);
        // Rows merged into $from now go straight to $into.
        kop_facility_db_exec("UPDATE {$t} SET merged_into = ? WHERE merged_into = ?", array($into, $from), $opts);
        return true;
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
        $loaded['doc']['staff'][$list][(int) $position]['personId'] = $pid;
        kop_facility_db_exec('UPDATE ' . kop_facility_table('facilities', $opts) . ' SET json_data = ? WHERE id = ?',
            array(kop_facility_json_encode($loaded['doc']), (int) $facility_id), $opts);
        return $pid;
    }
}

if (!function_exists('kop_people_roles_of')) {
    /** kop_person_roles rows for one person id, facilities first. */
    function kop_people_roles_of($person_id, array $opts = array()) {
        return kop_facility_db_rows('SELECT record_kind, record_id, list, position, name, role FROM ' . kop_people_table('roles', $opts)
            . ' WHERE person_id = ? ORDER BY record_kind DESC, record_id, list, position', array((int) $person_id), $opts);
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
