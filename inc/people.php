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
 *
 * Everyone else named in the records gets an id too, linked by name like the
 * operators (kop_people_named_sources(); their documents are not rewritten):
 * company owners on facility records, consultants and their agencies' people,
 * transport companies' people, providers' staff, the staff and plaintiffs of
 * published lawsuits, memorial victims, young adult program staff, the staff
 * and owner of each current r/troubledteens wiki entry, and journalists.
 * Free text ("Jane Doe 1", "Estate of Jason Britt", "Provo Canyon School")
 * gives an id only to what reads as a person's name (kop_people_is_person_name).
 *
 * Each person sits in a pool (kop_people_pools()): industry, victims and
 * plaintiffs, journalists. A name finds an id only in its own pool, so a
 * child on the memorial or a reporter is never joined to a staff member who
 * shares the name; Merge People pairs only within a pool. An admin can still
 * merge across pools by hand. The map and the facility pages read the
 * industry pool only.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PEOPLE_DB_VERSION', '3');
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
            pool VARCHAR(12) NOT NULL DEFAULT 'industry',
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
        // Version 2 tables had no pool (everyone was industry).
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$people} LIKE 'pool'")) {
            $wpdb->query("ALTER TABLE {$people} ADD COLUMN pool VARCHAR(12) NOT NULL DEFAULT 'industry'");
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

if (!function_exists('kop_people_pools')) {
    /** pool => label. A name finds an id only in its own pool. */
    function kop_people_pools() {
        return array('industry' => 'Industry', 'harmed' => 'Victims and plaintiffs', 'press' => 'Journalists');
    }
}

if (!function_exists('kop_people_pool_key')) {
    /** The by_key index key: the name key for the industry, "pool|key" for the others. */
    function kop_people_pool_key($pool, $key) {
        return ($pool === '' || $pool === 'industry') ? (string) $key : $pool . '|' . $key;
    }
}

if (!function_exists('kop_people_has_pool')) {
    /** Whether the people table has the pool column yet (version 3). */
    function kop_people_has_pool(array $opts = array()) {
        static $known = array();
        $t = kop_people_table('people', $opts);
        $k = $t . (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO ? '#' . spl_object_id($opts['pdo']) : '');
        if (isset($known[$k])) return $known[$k];
        if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO) {
            try {
                $opts['pdo']->query("SELECT pool FROM {$t} LIMIT 1");
                return $known[$k] = true;
            } catch (Throwable $e) {
                return $known[$k] = false;
            }
        }
        global $wpdb;
        return $known[$k] = isset($wpdb) && method_exists($wpdb, 'get_var') && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$t} LIKE 'pool'");
    }
}

if (!function_exists('kop_people_is_person_name')) {
    /**
     * Whether free text reads as one person's name: two to five words, each
     * a capitalised name, an initial or a particle ("de", "van"), with no
     * digits and none of the words companies, places and stand-ins use
     * ("Academy", "LLC", "State", "Doe", "FNU", "Anonymous", "minor").
     */
    function kop_people_is_person_name($name) {
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/\([^)]*\)?|["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]/u', ' ', (string) $name)));
        $name = trim(preg_replace('/^(dr|mr|mrs|ms|rev)\.?\s+/i', '', $name));
        if ($name === '' || preg_match('/\d|[@\/&:;!?]/', $name)) return false;
        static $not = null;
        if ($not === null) {
            $not = array_flip(explode(' ', 'inc llc lc ltd lp llp pllc pc corp corporation company co academy academies school schools ranch '
                . 'services service department dept group holdings partners capital center centre foundation trust associates association '
                . 'healthcare health family families youth program programs the of and for united states state america american county city '
                . 'estate doe roe fnu lnu unknown unnamed anonymous minor minors class students student survivors survivor parents parent '
                . 'mother father son sons daughter daughters resident residents staff others et al withheld behalf herself himself '
                . 'institute ministries ministry church hospital university college network systems solutions management consulting '
                . 'consultants enterprises charities transport transports transportation escort escorts agency behavioral treatment residential therapy '
                . 'wilderness camp home homes house village boy girl boys girls teen teens child children infant baby year old '
                . 'registered agent sole proprietor contact relator plaintiff plaintiffs defendant defendants none na n/a various'));
        }
        $particles = array('de', 'da', 'di', 'du', 'del', 'della', 'der', 'den', 'van', 'von', 'la', 'le', 'st', 'st.', 'y', 'bin', 'al-', "d'");
        $words = explode(' ', $name);
        if (count($words) < 2 || count($words) > 5) return false;
        $real = 0;
        foreach ($words as $w) {
            $bare = strtolower(trim($w, ".,'\x{2019}"));
            if (isset($not[$bare])) return false;
            if (in_array(strtolower($w), $particles, true)) continue;
            if (preg_match('/^\p{Lu}\.?$/u', $w) || preg_match('/^(\p{Lu}\.){2,3}$/u', $w)) continue; // "P." "J" "A.L."
            if (preg_match('/^(jr|sr|ii|iii|iv)\.?,?$/i', $w)) continue;
            // "Smith", "McDougall", "O'Brien", "DeWitt", "Gauld-Hurd": a capital, then at least one lower-case letter.
            if (!preg_match('/^\p{Lu}[\p{L}\'\x{2019}\-]*\p{Ll}[\p{L}\'\x{2019}\-]*,?$/u', $w)) return false;
            $real++;
        }
        return $real >= 2 && kop_people_key($name) !== '';
    }
}

if (!function_exists('kop_people_split_named')) {
    /**
     * "Bobby Tredinnick, LMSW-CASAC (CEO)" -> [name, role]: the name before
     * the first comma or bracket, the role from the brackets (else what
     * follows the comma).
     */
    function kop_people_split_named($raw) {
        $raw = trim((string) $raw);
        $role = '';
        if (preg_match('/\(([^)]*)\)?/u', $raw, $m)) $role = trim($m[1]);
        $name = trim(preg_replace('/\(.*$/us', '', $raw));
        if (strpos($name, ',') !== false) {
            list($name, $rest) = array_map('trim', explode(',', $name, 2));
            if ($role === '') $role = $rest;
        }
        return array($name, $role);
    }
}

if (!function_exists('kop_people_split_list')) {
    /**
     * A party list written as text ("Oliver Whitcomb and Amy Clifford, on
     * behalf of an anonymous survivor", "The Estate of Jason Britt") -> the
     * names in it. Only what reads as a person's name is kept.
     */
    function kop_people_split_list($raw) {
        $out = array();
        $raw = preg_replace('/\([^)]*\)?/u', ' ', (string) $raw);
        foreach (preg_split('/\s*[,;]\s*|\s+(?:and|&)\s+/u', $raw) as $part) {
            $part = trim(preg_replace('/^(the\s+)?(estate|parents|family|mother|father|guardians?)\s+of\s+(the\s+late\s+)?/i', '', trim($part)));
            if ($part !== '' && kop_people_is_person_name($part)) $out[] = $part;
        }
        return $out;
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
     * {rows: {id: row}, by_key: {pool key: [ids not merged, lowest first]},
     * keys: {id: {key: true}}} (a row's name key plus its aliases' keys).
     */
    function kop_people_load(array $opts = array()) {
        $rows = array();
        $cols = 'id, name, name_key, aliases, merged_into, notes' . (kop_people_has_pool($opts) ? ', pool' : '');
        foreach (kop_facility_db_rows('SELECT ' . $cols . ' FROM ' . kop_people_table('people', $opts) . ' ORDER BY id', array(), $opts) as $r) {
            $r['id'] = (int) $r['id'];
            $r['pool'] = isset($r['pool']) && $r['pool'] !== '' ? (string) $r['pool'] : 'industry';
            $r['merged_into'] = $r['merged_into'] !== null && (int) $r['merged_into'] > 0 ? (int) $r['merged_into'] : 0;
            $rows[$r['id']] = $r;
        }
        $state = array('rows' => $rows, 'by_key' => array(), 'keys' => array(), 'initials' => array());
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
        $initials = kop_people_middle_initials($r['name']);
        foreach (kop_people_alias_list($r['aliases']) as $a) $initials += kop_people_middle_initials($a);
        $state['initials'][$id] = $initials;
        if ($r['merged_into']) return;
        $pool = $r['pool'] ?? 'industry';
        foreach (array_keys($keys) as $k) {
            $k = kop_people_pool_key($pool, $k);
            if (!isset($state['by_key'][$k])) $state['by_key'][$k] = array();
            if (!in_array($id, $state['by_key'][$k], true)) {
                $state['by_key'][$k][] = $id;
                sort($state['by_key'][$k]);
            }
        }
    }
}

if (!function_exists('kop_people_middle_initials')) {
    /**
     * The middle initials a name is written with: "Robert W. Lichfield" ->
     * {w}, "Laurie Gauld Hurd" -> {g}, "Robert Lichfield" -> {}.
     */
    function kop_people_middle_initials($name) {
        $name = preg_replace('/^[A-Za-z][A-Za-z &\/-]{2,40}:\s*/', '', (string) $name);
        $name = preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]|\([^)]*\)/u', ' ', $name);
        $name = preg_replace('/,.*$/', '', $name);
        $name = function_exists('remove_accents') ? remove_accents($name) : $name;
        $tokens = preg_split('/[^a-z\']+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $drop = array('dr', 'mr', 'mrs', 'ms', 'rev', 'jr', 'sr', 'ii', 'iii', 'iv', 'phd', 'md', 'psyd', 'lcsw', 'lpc', 'lmft', 'rn', 'ma', 'msw', 'edd');
        $tokens = array_values(array_filter($tokens, static function ($t) use ($drop) { return trim($t, "'") !== '' && !in_array($t, $drop, true); }));
        $out = array();
        for ($i = 1; $i < count($tokens) - 1; $i++) $out[$tokens[$i][0]] = true;
        return $out;
    }
}

if (!function_exists('kop_people_initials_conflict')) {
    /**
     * Whether a name cannot be person $id: both carry middle initials and
     * none is shared. "Robert W. Lichfield" and "Robert B. Lichfield" are
     * father and son with one name key.
     */
    function kop_people_initials_conflict(array $state, $id, array $initials) {
        $theirs = $state['initials'][$id] ?? array();
        return $initials && $theirs && !array_intersect_key($initials, $theirs);
    }
}

if (!function_exists('kop_people_find')) {
    /**
     * The id a name goes to in a pool, 0 if none: the lowest id with its key,
     * passing over ids written with another middle initial and preferring
     * one written with the same.
     */
    function kop_people_find(array $state, $name, $pool = 'industry') {
        $key = kop_people_key($name);
        if ($key === '') return 0;
        $initials = kop_people_middle_initials($name);
        $fallback = 0;
        foreach ($state['by_key'][kop_people_pool_key($pool, $key)] ?? array() as $id) {
            if (kop_people_initials_conflict($state, $id, $initials)) continue;
            if (!$initials || array_intersect_key($initials, $state['initials'][$id] ?? array())) return $id;
            if (!$fallback) $fallback = $id;
        }
        return $fallback;
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
    function kop_people_create(array &$state, $name, $key, array $opts = array(), $pool = 'industry') {
        $pool = isset(kop_people_pools()[$pool]) ? $pool : 'industry';
        if (kop_people_has_pool($opts)) {
            kop_facility_db_exec('INSERT INTO ' . kop_people_table('people', $opts) . ' (name, name_key, aliases, notes, pool) VALUES (?, ?, ?, ?, ?)',
                array((string) $name, (string) $key, '', '', $pool), $opts);
        } else {
            kop_facility_db_exec('INSERT INTO ' . kop_people_table('people', $opts) . ' (name, name_key, aliases, notes) VALUES (?, ?, ?, ?)',
                array((string) $name, (string) $key, '', ''), $opts);
        }
        $id = kop_facility_db_insert_id($opts);
        $state['rows'][$id] = array('id' => $id, 'name' => (string) $name, 'name_key' => (string) $key, 'aliases' => '', 'merged_into' => 0, 'notes' => '', 'pool' => $pool);
        kop_people_index_row($state, $id);
        return $id;
    }
}

if (!function_exists('kop_people_assign')) {
    /**
     * The person id for one entry: its own personId while that person still
     * answers to the name, else the lowest id with the name's key in the
     * pool, else a new one in the pool. 0 when the name has no key.
     * A different middle initial is a different person (Robert W. and
     * Robert B. Lichfield, father and son): such an id is passed over, and
     * the one written with the same initial preferred.
     */
    function kop_people_assign(array &$state, $name, $current, array $opts = array(), &$created = 0, $pool = 'industry') {
        $key = kop_people_key($name);
        if ($key === '') return 0;
        $initials = kop_people_middle_initials($name);
        $pid = kop_people_resolve($state, $current);
        if ($pid > 0 && !kop_people_initials_conflict($state, $pid, $initials)) {
            if (isset($state['keys'][$pid][$key])) return $pid;
            // A merged-away row's keys count for the row it went into.
            if ((int) $current !== $pid && isset($state['keys'][(int) $current][$key])) return $pid;
        }
        $found = kop_people_find($state, $name, $pool);
        if ($found) return $found;
        $created++;
        return kop_people_create($state, kop_people_display_name($name), $key, $opts, $pool);
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

if (!function_exists('kop_people_source_rows')) {
    /** Rows of one source table; [] when the table is not there (an old mirror, a site without it). */
    function kop_people_source_rows($sql, array $opts = array()) {
        try {
            return kop_facility_db_rows($sql, array(), $opts);
        } catch (Throwable $e) {
            return array();
        }
    }
}

if (!function_exists('kop_people_kinds')) {
    /** record_kind => what the People screens call the record. */
    function kop_people_kinds() {
        return array(
            'facility' => 'facility', 'operator' => 'company', 'map' => 'map node', 'consultant' => 'consultant record',
            'transport' => 'transport company', 'provider' => 'provider', 'lawsuit' => 'lawsuit', 'memorial' => 'memorial entry',
            'youngadult' => 'young adult program', 'wiki' => 'wiki entry', 'journalist' => 'journalist',
        );
    }
}

if (!function_exists('kop_people_list_labels')) {
    /** list => label, for every list kop_person_roles holds. */
    function kop_people_list_labels() {
        return array(
            'administrator' => 'Administrator', 'notableStaff' => 'Staff', 'founders' => 'Founder', 'keyExecutives' => 'Executive',
            'ceo' => 'CEO', 'map' => 'Person on the map', 'owners' => 'Owner', 'consultant' => 'Consultant', 'personnel' => 'Personnel',
            'staff' => 'Staff', 'plaintiff' => 'Plaintiff', 'victim' => 'Died in a program', 'byline' => 'Journalist', 'owner' => 'Owner',
        );
    }
}

if (!function_exists('kop_people_record_label')) {
    /**
     * {name, url} of the record a kop_person_roles row points at, for the
     * People screens (map rows: the map, the node opened by the caller).
     */
    function kop_people_record_label($kind, $id, array $opts = array()) {
        static $names = array();
        $id = (int) $id;
        $prefix = isset($opts['prefix']) ? (string) $opts['prefix'] : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        $tables = array(
            'facility'   => 'SELECT id, name, state AS extra FROM ' . kop_facility_table('facilities', $opts),
            'operator'   => 'SELECT id, name FROM ' . kop_facility_table('operators', $opts),
            'consultant' => 'SELECT id, unique_name AS name FROM referrers_master',
            'transport'  => "SELECT id, unique_name AS name FROM {$prefix}transporters_master",
            'provider'   => 'SELECT id, unique_name AS name FROM providers_master',
            'lawsuit'    => 'SELECT id, case_name AS name FROM lawsuits',
            'memorial'   => 'SELECT id, name, program AS extra FROM memorial_victims',
            'youngadult' => 'SELECT id, name, state AS extra FROM young_adult_programs',
            'wiki'       => 'SELECT id, program_name AS name FROM wiki_submissions',
            'journalist' => 'SELECT id, name, outlet AS extra FROM journalists',
        );
        $page = function ($template, $fallback) {
            return function_exists('kop_facility_pages_page_url_by_template') ? (string) kop_facility_pages_page_url_by_template($template, $fallback) : '';
        };
        if ($kind === 'map') return array('name' => 'Network map', 'url' => $page('page-network-map.php', '/network-map/'));
        if (!isset($tables[$kind])) return array('name' => ucfirst((string) $kind) . ' #' . $id, 'url' => '');
        if (!isset($names[$kind])) {
            $names[$kind] = array();
            foreach (kop_people_source_rows($tables[$kind], $opts) as $r) {
                $names[$kind][(int) $r['id']] = trim((string) $r['name']) . (!empty($r['extra']) ? ($kind === 'memorial' ? ' (' . $r['extra'] . ')' : ', ' . $r['extra']) : '');
            }
        }
        $label = kop_people_kinds()[$kind];
        $name = $names[$kind][$id] ?? (ucfirst($label) . ' #' . $id . ' (gone)');
        if ($kind === 'memorial') $name = 'Memorial: ' . $name;
        if ($kind === 'journalist') $name = 'Journalist: ' . $name;
        if ($kind === 'wiki') $name = 'Wiki: ' . $name;
        $url = '';
        if ($kind === 'facility' && function_exists('kop_facility_page_url')) $url = (string) kop_facility_page_url($id);
        if ($kind === 'operator' && function_exists('kop_operator_page_url')) $url = (string) kop_operator_page_url($id);
        if ($kind === 'youngadult') $url = $page('page-young-adult-programs.php', '/young-adult-programs/');
        return array('name' => $name, 'url' => $url);
    }
}

if (!function_exists('kop_people_wiki_rows')) {
    /** The current wiki entries: approved/published rows, the newest per Reddit page (as inc/wiki-updates.php). */
    function kop_people_wiki_rows(array $opts = array()) {
        $rows = kop_people_source_rows("SELECT id, program_name, json_data, submitted_by, submission_notes FROM wiki_submissions
            WHERE status IN ('approved','published') ORDER BY updated_at DESC, id DESC", $opts);
        if (!function_exists('kop_wiki_upd_page_of') && is_file(__DIR__ . '/wiki-updates.php')) require_once __DIR__ . '/wiki-updates.php';
        $out = array();
        $seen = array();
        foreach ($rows as $r) {
            $page = function_exists('kop_wiki_upd_page_of') ? (string) kop_wiki_upd_page_of($r) : '';
            $key = $page !== '' ? $page : 'row:' . $r['id'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('kop_people_named_sources')) {
    /**
     * Everyone named outside the facility staff lists, the operators and the
     * map: [{kind, id, list, name, role, pool}], linked by name only.
     *   structured names (a wiki staff entry, a consultant's full name, a
     *   journalist) count when they have a name key, as staff lists do;
     *   names inside free text (owners, personnel lines, lawsuit parties,
     *   the memorial) only when they read as a person's name. Owners and
     *   plaintiffs are as often companies ("Twin Oaks", "Golden Ark
     *   Enterprises") or places ("Western Maine"): those also need a first
     *   word someone in the records is called (a staff member, consultant,
     *   journalist, the memorial, or one of $known: the staff lists' and the
     *   map's names).
     */
    function kop_people_named_sources(array $opts = array(), array $known = array()) {
        $out = array();
        $prefix = isset($opts['prefix']) ? (string) $opts['prefix'] : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        $given = array();
        $first = function ($name) {
            $t = preg_split('/[^a-z]+/', strtolower(function_exists('remove_accents') ? remove_accents((string) $name) : (string) $name), -1, PREG_SPLIT_NO_EMPTY);
            $t = array_values(array_filter($t, static function ($w) { return strlen($w) > 1 && !in_array($w, array('dr', 'mr', 'mrs', 'ms', 'rev'), true); }));
            return $t ? $t[0] : '';
        };
        $add = function ($kind, $id, $list, $name, $role = '', $pool = 'industry', $free = false) use (&$out, &$given, $first) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
            if ($name === '' || kop_people_key($name) === '') return;
            if ($free && !kop_people_is_person_name($name)) return;
            $check = $free && in_array($list, array('owners', 'owner', 'plaintiff'), true);
            if (!$check) $given[$first($name)] = true;
            $out[] = array('kind' => $kind, 'id' => (int) $id, 'list' => $list, 'name' => $name, 'role' => trim((string) $role), 'pool' => $pool, 'check' => $check);
        };
        $text = function ($v) { return is_array($v) ? (string) ($v['name'] ?? '') : (is_scalar($v) ? (string) $v : ''); };
        // A personnel line: "Sonny Faaootoa (founder)", "Bobby Tredinnick, LMSW-CASAC (CEO)".
        $line = function ($kind, $id, $list, $raw) use ($add) {
            list($name, $role) = kop_people_split_named(is_array($raw) ? ($raw['name'] ?? '') : (string) $raw);
            if (is_array($raw) && !empty($raw['role'])) $role = (string) $raw['role'];
            $add($kind, $id, $list, $name, $role, 'industry', true);
        };
        // Staff lists shaped like a facility's or an operator's, inside another record.
        $staff = function ($kind, $id, $doc) use ($add, $line) {
            foreach (array('administrator', 'notableStaff') as $l) {
                foreach ((array) ($doc['staff'][$l] ?? array()) as $e) {
                    if (is_array($e)) $add($kind, $id, $l, $e['name'] ?? '', $e['role'] ?? '');
                }
            }
            $ks = (array) ($doc['operator']['keyStaff'] ?? array());
            foreach (array('founders', 'keyExecutives') as $l) {
                foreach ((array) ($ks[$l] ?? array()) as $e) {
                    if (is_array($e)) $add($kind, $id, $l, $e['name'] ?? '', $e['role'] ?? '');
                    else $line($kind, $id, $l, $e);
                }
            }
            if (!empty($ks['ceo']) && is_string($ks['ceo'])) $line($kind, $id, 'ceo', $ks['ceo']);
            foreach (array('currentOwners', 'pastOwners') as $l) {
                foreach ((array) ($doc['identification'][$l] ?? array()) as $o) $line($kind, $id, 'owners', $o);
            }
        };

        // Company owners named on facility records (most are companies: only names kept).
        foreach (kop_people_source_rows("SELECT id, json_data FROM " . kop_facility_table('facilities', $opts)
            . " WHERE json_data LIKE '%currentOwners%' ORDER BY id", $opts) as $r) {
            $doc = json_decode((string) $r['json_data'], true);
            foreach ((array) ($doc['identification']['currentOwners'] ?? array()) as $o) $line('facility', $r['id'], 'owners', $text($o));
        }
        // Consultants: the person a record is about, the consultants it lists, its agency's people.
        foreach (kop_people_source_rows('SELECT id, json_data FROM referrers_master ORDER BY id', $opts) as $r) {
            $d = json_decode((string) $r['json_data'], true);
            $d = is_array($d['data'] ?? null) ? $d['data'] : array();
            $ind = (array) ($d['referrerIndividual'] ?? array());
            $full = trim((string) ($ind['fullName'] ?? '')) ?: trim(($ind['firstName'] ?? '') . ' ' . ($ind['lastName'] ?? ''));
            if ($full !== '') $add('consultant', $r['id'], 'consultant', $full, (string) ($ind['role'] ?? ''));
            foreach ((array) ($d['referrerConsultants'] ?? array()) as $c) {
                if (!is_array($c)) continue;
                $full = trim((string) ($c['fullName'] ?? '')) ?: trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
                $add('consultant', $r['id'], 'consultant', $full, (string) ($c['role'] ?? ''));
            }
            foreach (array('referrerAgency', 'referrerGroup') as $g) {
                foreach ((array) ($d[$g]['keyPersonnel'] ?? array()) as $p) $line('consultant', $r['id'], 'personnel', $p);
            }
            foreach ((array) ($d['facilities'] ?? array()) as $f) if (is_array($f)) $staff('consultant', $r['id'], $f);
            if (!empty($d['operator'])) $staff('consultant', $r['id'], array('operator' => $d['operator']));
        }
        // Transport companies' people.
        foreach (kop_people_source_rows("SELECT id, json_data FROM {$prefix}transporters_master ORDER BY id", $opts) as $r) {
            $d = json_decode((string) $r['json_data'], true);
            $d = is_array($d['data'] ?? null) ? $d['data'] : array();
            foreach (array('transporterCompany', 'transporterIndividual') as $g) {
                foreach ((array) ($d[$g]['keyPersonnel'] ?? array()) as $p) $line('transport', $r['id'], 'personnel', $p);
            }
            $ind = (array) ($d['transporterIndividual'] ?? array());
            $full = trim((string) ($ind['fullName'] ?? '')) ?: trim(($ind['firstName'] ?? '') . ' ' . ($ind['lastName'] ?? ''));
            if ($full !== '') $add('transport', $r['id'], 'personnel', $full, (string) ($ind['role'] ?? ''));
        }
        // Providers outside the TTI: their staff, owners and founders.
        foreach (kop_people_source_rows('SELECT id, json_data FROM providers_master ORDER BY id', $opts) as $r) {
            $d = json_decode((string) $r['json_data'], true);
            $d = is_array($d['data'] ?? null) ? $d['data'] : array();
            foreach ((array) ($d['facilities'] ?? array()) as $f) if (is_array($f)) $staff('provider', $r['id'], $f);
            if (!empty($d['operator'])) $staff('provider', $r['id'], array('operator' => $d['operator']));
        }
        // Published lawsuits: the staff they name (industry) and the plaintiffs.
        foreach (kop_people_source_rows("SELECT id, plaintiffs, staff_mentioned FROM lawsuits WHERE publication_status = 'published' ORDER BY id", $opts) as $r) {
            foreach ((array) json_decode((string) $r['staff_mentioned'], true) as $p) if (is_string($p)) $line('lawsuit', $r['id'], 'staff', $p);
            foreach ((array) json_decode((string) $r['plaintiffs'], true) as $p) {
                if (!is_string($p)) continue;
                foreach (kop_people_split_list($p) as $n) $add('lawsuit', $r['id'], 'plaintiff', $n, 'Plaintiff', 'harmed', true);
            }
        }
        // The memorial.
        foreach (kop_people_source_rows("SELECT id, name, program FROM memorial_victims WHERE publication_status = 'published' ORDER BY id", $opts) as $r) {
            $add('memorial', $r['id'], 'victim', (string) $r['name'], (string) $r['program'], 'harmed', true);
        }
        // Young adult programs: the staff their Woodbury items name ("Karen Armstrong, Owner (2009)").
        foreach (kop_people_source_rows("SELECT id, facts FROM young_adult_programs WHERE review = 'approved' ORDER BY id", $opts) as $r) {
            foreach ((array) json_decode((string) $r['facts'], true) as $f) {
                if (is_array($f) && ($f['group'] ?? '') === 'staff') $line('youngadult', $r['id'], 'staff', (string) ($f['label'] ?? ''));
            }
        }
        // The r/troubledteens wiki: each current entry's staff and owner.
        foreach (kop_people_wiki_rows($opts) as $r) {
            $d = json_decode((string) $r['json_data'], true);
            if (!is_array($d)) continue;
            foreach ((array) ($d['staffMembers'] ?? array()) as $s) {
                if (is_array($s)) $add('wiki', $r['id'], 'staff', (string) ($s['name'] ?? ''), (string) ($s['role'] ?? ''));
            }
            if (!empty($d['ownerName']) && is_string($d['ownerName'])) $line('wiki', $r['id'], 'owner', $d['ownerName']);
        }
        // Journalists (internal only).
        foreach (kop_people_source_rows('SELECT id, name, outlet FROM journalists ORDER BY id', $opts) as $r) {
            $add('journalist', $r['id'], 'byline', (string) $r['name'], (string) $r['outlet'], 'press');
        }
        foreach ($known as $n) $given[$first($n)] = true;
        unset($given['']);
        $kept = array();
        foreach ($out as $n) {
            if ($n['check'] && !isset($given[$first($n['name'])])) continue;
            unset($n['check']);
            $kept[] = $n;
        }
        return $kept;
    }
}

if (!function_exists('kop_people_sync')) {
    /**
     * Gives every staff entry a person id, stamps it into facilities_v2,
     * gives every network map person an id, and rebuilds kop_person_roles.
     *
     * @param array $opts pdo, prefix (as kop_facility_db_rows), dry_run,
     *                    graph (the map graph; default the deployed one).
     * Then gives everyone kop_people_named_sources() lists an id, by name
     * within their pool.
     *
     * @return array {facilities, entries, stamped, docs_written, created, map, named, roles, unkeyed}
     */
    function kop_people_sync(array $opts = array()) {
        $state = kop_people_load($opts);
        $stats = array('facilities' => 0, 'entries' => 0, 'stamped' => 0, 'docs_written' => 0, 'created' => 0, 'map' => 0, 'named' => 0, 'roles' => 0, 'unkeyed' => 0);
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

        // Everyone else the records name (kop_people_named_sources), by name within their pool; one row
        // per person per list of a record ("Frederic Yeomans IV" and "Frederic H. Yeomans IV" are one plaintiff).
        $at = array();
        $known = array();
        foreach ($roles as $r) $known[] = $r[5];
        foreach (kop_people_named_sources($opts, $known) as $n) {
            $pid = kop_people_assign($state, $n['name'], 0, $opts, $stats['created'], $n['pool']);
            if (!$pid) continue;
            $slot = $n['kind'] . ':' . $n['id'] . ':' . $n['list'];
            if (isset($at[$slot][$pid])) continue;
            $at[$slot][$pid] = true;
            $roles[] = array($n['kind'], $n['id'], $n['list'], count($at[$slot]) - 1, $pid, $n['name'], $n['role'], '');
            $stats['named']++;
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
    /** kop_person_roles rows for one person id: facilities, then companies, the other records, the map last. */
    function kop_people_roles_of($person_id, array $opts = array()) {
        return kop_facility_db_rows('SELECT record_kind, record_id, list, position, name, role, ref FROM ' . kop_people_table('roles', $opts)
            . " WHERE person_id = ? ORDER BY CASE record_kind WHEN 'facility' THEN 0 WHEN 'operator' THEN 1 WHEN 'map' THEN 3 ELSE 2 END, record_kind, record_id, list, position",
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
            $state = array('rows' => array(), 'by_key' => array(), 'keys' => array(), 'initials' => array());
            if (function_exists('get_option') && get_option('kop_people_db') && isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_results')) {
                try {
                    $state = kop_people_load();
                } catch (Throwable $e) {
                }
            }
        }
        $key = kop_people_key($name);
        $pid = $person_id ? kop_people_resolve($state, $person_id) : 0;
        if (!$pid && $key !== '') $pid = kop_people_find($state, $name);
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
