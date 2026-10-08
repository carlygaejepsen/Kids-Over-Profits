<?php
/**
 * Homes and cottages of one program: a program record (an ordinary
 * facilities_v2 record, e.g. "Newport Academy" in CA) and the licensed homes
 * that belong to it ("Newport Academy – Acre", "– Aracena", ...).
 *
 * The state licenses each home on its own, so each keeps its own record, its
 * inspection reports and its page. The link lives in its own table, not in
 * the facility document, so a save through the data form (whose normalizer
 * rebuilds the identification block from known fields) can never drop it:
 *
 *   {prefix}kop_program_homes    home_id (PK), program_id, home_name
 *   {prefix}kop_program_groups   program_id (PK), created_record, created_at, created_by
 *
 * A program page lists its homes and adds their news, lawsuits and approved
 * serious findings to its own; a home page says which program it belongs to
 * and lists the other homes. Groups are proposed from the names ("Program –
 * Home" in one state, kop_program_homes_suggest()) and confirmed by an admin
 * at KOP Tools > Program Homes, with Undo. Merged records keep their links
 * (kop_facility_merge_ref_tables filter below).
 *
 * Guarded with function_exists so scripts/test-program-homes.php can load it
 * next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_PROGRAM_HOMES_DB_VERSION')) {
    define('KOP_PROGRAM_HOMES_DB_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_table')) {
    function kop_program_homes_table($which = 'homes') {
        global $wpdb;
        return $wpdb->prefix . ($which === 'groups' ? 'kop_program_groups' : 'kop_program_homes');
    }
}

if (!function_exists('kop_program_homes_install')) {
    function kop_program_homes_install($force = false) {
        if (!$force && get_option('kop_program_homes_db') === KOP_PROGRAM_HOMES_DB_VERSION) return;
        global $wpdb;
        $homes = kop_program_homes_table('homes');
        $groups = kop_program_homes_table('groups');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$homes} (
            home_id BIGINT UNSIGNED NOT NULL,
            program_id BIGINT UNSIGNED NOT NULL,
            home_name VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (home_id),
            KEY program_id (program_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$groups} (
            program_id BIGINT UNSIGNED NOT NULL,
            created_record TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (program_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        update_option('kop_program_homes_db', KOP_PROGRAM_HOMES_DB_VERSION, false);
    }
    add_action('admin_init', 'kop_program_homes_install');
}

if (!function_exists('kop_program_homes_ready')) {
    function kop_program_homes_ready() {
        static $ready = null;
        if ($ready === null) {
            $ready = function_exists('kop_facility_pages_table_exists')
                && kop_facility_pages_table_exists(kop_program_homes_table('homes'));
        }
        return $ready;
    }
}

// ---------------------------------------------------------------------------
// Reading the links
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_map')) {
    /**
     * {homes: home_id => [program_id, home_name], programs: program_id => [home ids]}.
     * Homes whose record is gone (merged away) are left out.
     */
    function kop_program_homes_map($refresh = false) {
        static $memo = null;
        if ($memo !== null && !$refresh) return $memo;
        $memo = array('homes' => array(), 'programs' => array());
        if (!kop_program_homes_ready()) return $memo;
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT h.home_id, h.program_id, h.home_name FROM ' . kop_program_homes_table('homes') . ' h
               JOIN facilities_v2 f ON f.id = h.home_id
               JOIN facilities_v2 p ON p.id = h.program_id
              ORDER BY h.home_name, h.home_id',
            ARRAY_A
        );
        foreach ((array) $rows as $r) {
            $home = (int) $r['home_id'];
            $program = (int) $r['program_id'];
            if ($home === $program) continue;
            $memo['homes'][$home] = array($program, (string) $r['home_name']);
            $memo['programs'][$program][] = $home;
        }
        return $memo;
    }
}

if (!function_exists('kop_program_homes_program_of')) {
    /** [program_id, home_name] for a home, or null. */
    function kop_program_homes_program_of($facility_id) {
        $map = kop_program_homes_map();
        return $map['homes'][(int) $facility_id] ?? null;
    }
}

if (!function_exists('kop_program_homes_homes_of')) {
    /** Home ids of a program record (empty when it is not one). */
    function kop_program_homes_homes_of($facility_id) {
        $map = kop_program_homes_map();
        return $map['programs'][(int) $facility_id] ?? array();
    }
}

if (!function_exists('kop_program_homes_cache_key')) {
    /** Part of kop_facility_pages_fingerprint(): a grouping changes pages. */
    function kop_program_homes_cache_key() {
        if (!kop_program_homes_ready()) return '-';
        global $wpdb;
        $row = $wpdb->get_row('SELECT COUNT(*), MAX(home_id), SUM(program_id) FROM ' . kop_program_homes_table('homes'), ARRAY_N);
        return is_array($row) ? implode('|', array_map('strval', $row)) : '?';
    }
}

// ---------------------------------------------------------------------------
// Suggestions
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_split_name')) {
    /**
     * "Newport Academy – Acre" => ['Newport Academy', 'Acre']; also "Base - X",
     * "Base- X" and "Base: X". Only the first separator splits, so
     * "Paradigm – Malibu – Birdview" keeps "Malibu – Birdview" as the home.
     * ['', ''] when the name has no separator.
     */
    function kop_program_homes_split_name($name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if (preg_match('/^(.{3,}?)(?:\s*[\x{2013}\x{2014}]\s*|\s+-\s+|(?<=[\p{L}\p{N}.)])-\s+|:\s+)(.{2,})$/u', $name, $m)) {
            return array(trim($m[1]), trim($m[2]));
        }
        return array('', '');
    }
}

if (!function_exists('kop_program_homes_key')) {
    function kop_program_homes_key($name) {
        $s = mb_strtolower(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
        $s = str_replace(array("\u{2019}", "'"), '', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = preg_replace('/\b(inc|llc|the)\b/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}

if (!function_exists('kop_program_homes_words')) {
    /**
     * A name's words as kop_program_homes_key() reads them, each with where it
     * ends in the name: [[key word, byte offset after it], ...]. "The" and
     * "Inc"/"LLC" are not words here, as in the key.
     */
    function kop_program_homes_words($name) {
        $name = html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8');
        $out = array();
        if (!preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\'][\p{L}\p{N}]+)*/u', $name, $m, PREG_OFFSET_CAPTURE)) return $out;
        foreach ($m[0] as $t) {
            $k = kop_program_homes_key($t[0]);
            if ($k === '') continue;
            foreach (explode(' ', $k) as $w) $out[] = array($w, $t[1] + strlen($t[0]));
        }
        return $out;
    }
}

if (!function_exists('kop_program_homes_cut')) {
    /** "Boys Republic Graves Cottage STRTP" cut after 2 words: ['Boys Republic', 'Graves Cottage STRTP']. */
    function kop_program_homes_cut($name, $n) {
        $name = html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8');
        $words = kop_program_homes_words($name);
        if ($n < 1 || count($words) < $n) return array(trim($name), '');
        $end = $words[$n - 1][1];
        $base = rtrim(substr($name, 0, $end), " ,-:\u{2013}\u{2014}");
        $rest = trim(substr($name, $end), " ,-:#'\u{2013}\u{2014}\u{2019}");
        // "Three Springs of Marion" -> "Marion"; "Academy at Candor" -> "Candor".
        $rest = trim(preg_replace('/^(?:(?:inc|llc|ltd|of|at|in)\.?[\s,]+)+/i', '', $rest . ' '), " ,-:#\u{2013}\u{2014}");
        return array($base, $rest);
    }
}

if (!function_exists('kop_program_homes_tidy_name')) {
    /** "Three Springs of" -> "Three Springs"; "Speck Homes Inc, Program" -> "Speck Homes"; "Lydia's Home, LLC Phase" -> "Lydia's Home". */
    function kop_program_homes_tidy_name($name) {
        $name = trim((string) $name);
        do {
            $before = $name;
            $name = trim(preg_replace('/[\s,.\-:]+(?:at|of|de|del|la|el|and|for|in|the|inc|llc|ltd|phase|level|program|no|number)\.?$/iu', '', $name), " ,-:");
        } while ($name !== $before && $name !== '');
        return $name !== '' ? $name : trim((string) $before);
    }
}

if (!function_exists('kop_program_homes_prefix_groups')) {
    /**
     * Groups the "Program – Home" names miss, from rows not yet in one (rows as
     * kop_program_homes_suggest() takes them). Records in one state that share
     * the start of their name, longest start first:
     *   numbered     "Echelon 1", "Echelon 3"; "Adelphoi Middle Creek I", "... II"
     *   same name    the same name in several towns (the homes told apart by town)
     *   house names  "ROP ATCS Baker House", "ROP ATCS Joann House"
     *   shared start three or more records sharing two or more words ("Little Sand
     *                East / Lakeside / West"): may be one company's separate programs
     * A one-word start is never a common word, and takes three records unless the homes are numbered or same-named.
     * Returns [key => {program_name, state, reason, homes: [{id, name, home_name, city,
     * status, operator}], existing: {id, name} or null}].
     */
    function kop_program_homes_prefix_groups(array $rows) {
        static $common = null;
        if ($common === null) {
            $common = array_flip(explode(' ', 'new st saint north south east west united first grace hope life youth boys girls family childrens children '
                . 'community mountain valley river lake crossroads pathways bridges cedar oak pine sunrise victory faith heritage harbor haven '
                . 'cornerstone lighthouse mercy spring springs canyon ranch academy center house home camp american national christian southern '
                . 'northern sierra rocky blue green red golden silver eagle ridge meadow meadows forest island bay pacific atlantic santa san los '
                . 'james john baptist methodist catholic county city state department juvenile adolescent residential treatment behavioral '
                . 'alabama alaska arizona arkansas california colorado connecticut delaware florida georgia hawaii idaho illinois indiana iowa '
                . 'kansas kentucky louisiana maine maryland massachusetts michigan minnesota mississippi missouri montana nebraska nevada '
                . 'hampshire jersey mexico york carolina dakota ohio oklahoma oregon pennsylvania rhode tennessee texas utah vermont virginia '
                . 'washington wisconsin wyoming'));
        }
        $generic = array_flip(array('childrens home', 'group home', 'youth services', 'new hope', 'boys ranch', 'girls ranch', 'youth home',
            'youth homes', 'family services', 'childrens center', 'child and', 'home for', 'house of', 'center for', 'residential treatment',
            'treatment center', 'youth academy', 'boys home', 'girls home', 'new life', 'new beginnings', 'second chance', 'hope house',
            'christian academy', 'juvenile detention', 'detention center', 'juvenile justice', 'county juvenile', 'methodist childrens',
            'baptist childrens', 'united methodist', 'department of', 'youth development', 'youth center'));
        $numbered = '/^(?:(?:home|house|phase|no|number|unit|program)\s+)?(?:\d+|i{1,3}|iv|v|vi{1,3}|ix|x)$/';
        $homey = '/\b(?:house|home|homes|cottage|cottages|residence|group|campus|lodge|cabin|apartments?|manor|unit|ihf|strtp)\b/';
        // A start never ends on a joining word or a single letter ("Camp E-Kel-Etu", "Three Springs of").
        $joining = array_flip(array('at', 'of', 'de', 'del', 'la', 'el', 'and', 'for', 'in', 'on', 'a', 'an', 'to'));

        $by_state = array();
        foreach ($rows as $r) {
            $state = strtoupper(trim((string) ($r['state'] ?? '')));
            if ($state === '') continue;
            $words = array_map(static function ($w) { return $w[0]; }, kop_program_homes_words($r['name']));
            if (!$words) continue;
            $by_state[$state][] = array($r, $words);
        }
        $out = array();
        foreach ($by_state as $state => $list) {
            $taken = array();
            for ($n = 6; $n >= 1; $n--) {
                $pref = array();
                foreach ($list as $i => $e) {
                    if (isset($taken[$i]) || count($e[1]) < $n) continue;
                    $pref[implode(' ', array_slice($e[1], 0, $n))][] = $i;
                }
                foreach ($pref as $base => $members) {
                    if (count($members) < 2 || isset($generic[$base])) continue;
                    $last = substr(strrchr(' ' . $base, ' '), 1);
                    if (isset($joining[$last]) || strlen($last) < 2) continue;
                    if ($n === 1 && (isset($common[$base]) || mb_strlen($base) < 5 || ctype_digit($base))) continue;
                    $rests = array();
                    $is_numbered = false;
                    $house = 0;
                    $empty = 0;
                    foreach ($members as $i) {
                        $rest = implode(' ', array_slice($list[$i][1], $n));
                        $rests[$i] = $rest;
                        if ($rest === '') $empty++;
                        elseif (preg_match($numbered, $rest)) $is_numbered = true;
                        elseif (preg_match($homey, $rest)) $house++;
                    }
                    if ($is_numbered) $reason = 'numbered';
                    elseif ($empty === count($members)) $reason = 'same name';
                    elseif ($house >= 2) $reason = 'house names';
                    // Three that share a start, or a record named just the start and two more.
                    elseif ($n >= 2 && (count($members) - $empty >= 3 || ($empty === 1 && count($members) >= 3))) $reason = 'shared start';
                    else continue;
                    // One shared word is enough for numbered or same-named homes, else it takes three records.
                    if ($n === 1 && count($members) < 3 && $reason !== 'numbered' && $reason !== 'same name') continue;
                    // One record named just the start is the program, unless every record is.
                    $existing = null;
                    $homes = array();
                    $names = array();
                    foreach ($members as $i) {
                        $r = $list[$i][0];
                        $taken[$i] = true;
                        list($b, $home) = kop_program_homes_cut($r['name'], $n);
                        $names[$b] = ($names[$b] ?? 0) + 1;
                        if ($rests[$i] === '' && $reason !== 'same name' && !$existing && $empty === 1) {
                            $existing = array('id' => (int) $r['id'], 'name' => (string) $r['name']);
                            continue;
                        }
                        $city = trim((string) ($r['city'] ?? ''));
                        if ($home === '') {
                            list($house_name) = kop_program_homes_split_name($city);
                            $home = $house_name !== '' ? $house_name : $city;
                        }
                        $homes[] = array(
                            'id'        => (int) $r['id'],
                            'name'      => (string) $r['name'],
                            'home_name' => $home,
                            'city'      => $city,
                            'status'    => trim((string) ($r['status'] ?? '')),
                            'operator'  => trim((string) ($r['operator'] ?? '')),
                        );
                    }
                    if (count($homes) < ($existing ? 1 : 2)) continue;
                    arsort($names);
                    $out[$base . '|' . $state] = array(
                        'program_name' => $existing ? $existing['name'] : kop_program_homes_tidy_name((string) array_key_first($names)),
                        'state'        => $state,
                        'reason'       => $reason,
                        'homes'        => $homes,
                        'existing'     => $existing,
                    );
                }
            }
        }
        return $out;
    }
}

if (!function_exists('kop_program_homes_reasons')) {
    /** Why a group was suggested => how the screen names it. */
    function kop_program_homes_reasons() {
        return array(
            'dash'         => "Named \"Program \u{2013} Home\"",
            'numbered'     => 'Numbered homes',
            'same name'    => 'Same name in several towns',
            'house names'  => 'Program name, then a house',
            'shared start' => 'Names start the same',
        );
    }
}

if (!function_exists('kop_program_homes_suggest')) {
    /**
     * Proposed groups from facility rows [{id, name, state, city, status,
     * operator}] (operator = company name or ''), none already grouped: records
     * named "Program – Home" in one state, two or more of them, then the ones
     * kop_program_homes_prefix_groups() finds among the rest. Each:
     * {key, program_name, state, reason (kop_program_homes_reasons()), homes:
     * [{id, name, home_name, city, status}], existing: {id, name} or null (a
     * record named just "Program" there), operator, warnings: [text]}. Largest first.
     */
    function kop_program_homes_suggest(array $rows, array $grouped = array(), array $dismissed = array()) {
        $by_key = array();
        $plain = array();
        $company_keys = array();
        foreach ($rows as $r) {
            $op = trim((string) ($r['operator'] ?? ''));
            if ($op !== '') $company_keys[kop_program_homes_key(preg_replace('/\s*\([^)]*\)\s*$/', '', $op))] = $op;
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $state = strtoupper(trim((string) ($r['state'] ?? '')));
            $plain[kop_program_homes_key($r['name']) . '|' . $state][] = $r;
            if (isset($grouped[$id])) continue;
            list($base, $home) = kop_program_homes_split_name($r['name']);
            if ($base === '') continue;
            $bk = kop_program_homes_key($base);
            if (mb_strlen($bk) < 4) continue;
            $by_key[$bk . '|' . $state]['base'][$base] = ($by_key[$bk . '|' . $state]['base'][$base] ?? 0) + 1;
            $by_key[$bk . '|' . $state]['homes'][] = array(
                'id'        => $id,
                'name'      => (string) $r['name'],
                'home_name' => $home,
                'city'      => trim((string) ($r['city'] ?? '')),
                'status'    => trim((string) ($r['status'] ?? '')),
                'operator'  => trim((string) ($r['operator'] ?? '')),
            );
        }
        $cands = array();
        $used = array();
        foreach ($by_key as $key => $g) {
            if (count($g['homes']) < 2) continue;
            // A dismissed group's records are not offered again under another start.
            foreach ($g['homes'] as $h) $used[$h['id']] = true;
            foreach ($plain[$key] ?? array() as $p) $used[(int) $p['id']] = true;
            if (isset($dismissed[$key])) continue;
            arsort($g['base']);
            // A record named just "Program" in that state is the program already.
            $existing = null;
            foreach ($plain[$key] ?? array() as $p) {
                if (!isset($grouped[(int) $p['id']])) { $existing = array('id' => (int) $p['id'], 'name' => (string) $p['name']); break; }
            }
            $cands[$key] = array('program_name' => (string) array_key_first($g['base']), 'state' => explode('|', $key, 2)[1],
                'reason' => 'dash', 'homes' => $g['homes'], 'existing' => $existing);
        }
        $rest = array();
        foreach ($rows as $r) {
            if (!isset($grouped[(int) $r['id']]) && !isset($used[(int) $r['id']])) $rest[] = $r;
        }
        foreach (kop_program_homes_prefix_groups($rest) as $key => $g) {
            if (!isset($dismissed[$key]) && !isset($cands[$key])) $cands[$key] = $g;
        }

        $out = array();
        foreach ($cands as $key => $g) {
            $bk = explode('|', $key, 2)[0];
            $ops = array();
            foreach ($g['homes'] as $h) if ($h['operator'] !== '') $ops[$h['operator']] = ($ops[$h['operator']] ?? 0) + 1;
            arsort($ops);
            $operator = $ops ? (string) array_key_first($ops) : '';
            $warnings = array();
            if (count($ops) > 1) $warnings[] = 'Its homes are filed under ' . count($ops) . ' different companies.';
            // "CERTS – Moonridge Academy": the first part names a company
            // (CERTS Group), and what follows are its separate programs.
            $company = isset($company_keys[$bk]) ? $company_keys[$bk] : '';
            if ($company === '') {
                foreach ($company_keys as $ck => $cname) {
                    if (strpos($ck . ' ', $bk . ' ') === 0) { $company = $cname; break; }
                }
            }
            if ($company !== '') {
                $warnings[] = 'The first part is the name of a company (' . $company . '), so these may be its separate programs rather than homes of one.';
            } elseif ($g['reason'] === 'shared start') {
                $warnings[] = 'These only share the start of their names: they may be one company\'s separate programs rather than homes of one program.';
            }
            usort($g['homes'], static function ($a, $b) { return strnatcasecmp($a['home_name'], $b['home_name']); });
            $out[] = array(
                'key'          => $key,
                'program_name' => $g['program_name'],
                'state'        => $g['state'],
                'reason'       => $g['reason'],
                'homes'        => $g['homes'],
                'existing'     => $g['existing'],
                'operator'     => $operator,
                'warnings'     => $warnings,
            );
        }
        usort($out, static function ($a, $b) {
            return count($b['homes']) - count($a['homes']) ?: strcasecmp($a['program_name'], $b['program_name']);
        });
        return $out;
    }
}

if (!function_exists('kop_program_homes_rows')) {
    /** Every facility record as the suggester reads it, with its company's name. */
    function kop_program_homes_rows() {
        global $wpdb;
        $ops = array();
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        $otab = $wpdb->prefix . 'kop_operators';
        if (kop_facility_pages_table_exists($ofc) && kop_facility_pages_table_exists($otab)) {
            foreach ((array) $wpdb->get_results("SELECT ofc.facility_id, o.name FROM `{$ofc}` ofc JOIN `{$otab}` o ON o.id = ofc.operator_id", ARRAY_A) as $r) {
                if (!isset($ops[(int) $r['facility_id']])) $ops[(int) $r['facility_id']] = (string) $r['name'];
            }
        }
        $rows = array();
        foreach ((array) $wpdb->get_results('SELECT id, name, state, city, status FROM facilities_v2', ARRAY_A) as $r) {
            $r['operator'] = $ops[(int) $r['id']] ?? '';
            $rows[] = $r;
        }
        return $rows;
    }
}

if (!function_exists('kop_program_homes_grouped_ids')) {
    /** Every id already in a group, as a home or as a program => true. */
    function kop_program_homes_grouped_ids() {
        $map = kop_program_homes_map(true);
        $ids = array();
        foreach ($map['homes'] as $home => $p) {
            $ids[$home] = true;
            $ids[$p[0]] = true;
        }
        return $ids;
    }
}

// ---------------------------------------------------------------------------
// Grouping and Undo
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_create_program')) {
    /**
     * A new facilities_v2 record for the program, built from its homes: the
     * shared state, the city when they share one, the company most of them
     * are filed under, the earliest opening, open when any home is open.
     * $opts: pdo, prefix (kop_facility_save's), skip_memberships for tests.
     */
    function kop_program_homes_create_program($name, array $home_ids, array $opts) {
        global $wpdb;
        $name = trim((string) $name);
        if ($name === '') throw new RuntimeException('The program needs a name.');
        $in = implode(',', array_map('intval', $home_ids));
        $homes = $wpdb->get_results("SELECT id, json_data, state, city, status, start_year, end_year, facility_type FROM facilities_v2 WHERE id IN ({$in})", ARRAY_A);
        if (!$homes) throw new RuntimeException('Those home records are gone.');
        $states = $cities = $types = $operators = array();
        $start = 0;
        $end = 0;
        $any_open = false;
        $all_closed = true;
        foreach ($homes as $h) {
            $doc = json_decode((string) $h['json_data'], true) ?: array();
            if ($h['state'] !== '' && $h['state'] !== null) $states[strtoupper($h['state'])] = true;
            $cities[trim((string) $h['city'])] = true;
            if (trim((string) $h['facility_type']) !== '') $types[trim((string) $h['facility_type'])] = ($types[trim((string) $h['facility_type'])] ?? 0) + 1;
            $op = trim((string) ($doc['identification']['currentOperator'] ?? ''));
            if ($op !== '') $operators[$op] = ($operators[$op] ?? 0) + 1;
            if ((int) $h['start_year'] > 0 && (!$start || (int) $h['start_year'] < $start)) $start = (int) $h['start_year'];
            if ((int) $h['end_year'] > $end) $end = (int) $h['end_year'];
            $st = strtolower(trim((string) $h['status']));
            if ($st === 'open') $any_open = true;
            if ($st !== 'closed') $all_closed = false;
        }
        if (count($states) > 1) throw new RuntimeException('These homes are in more than one state; group them one state at a time.');
        arsort($types);
        arsort($operators);
        $state = $states ? (string) array_key_first($states) : '';
        $city = count($cities) === 1 ? (string) array_key_first($cities) : '';
        $status = $any_open ? 'Open' : ($all_closed ? 'Closed' : 'Unknown');

        $found = kop_facility_resolve_identity($name, $state ?: null, $city ?: null, $opts);
        if ($found) return array((int) $found, false);

        $legacy = array(
            'identification'  => array('name' => $name, 'currentOperator' => $operators ? (string) array_key_first($operators) : '', 'otherNames' => array()),
            'locationDetails' => array('city' => $city, 'state' => $state, 'country' => 'United States'),
            'location'        => trim(implode(', ', array_filter(array($city, $state)))),
            'operatingPeriod' => array('startYear' => $start ?: null, 'endYear' => ($all_closed && $end) ? $end : null, 'status' => $status, 'notes' => array()),
            'facilityDetails' => array('type' => $types ? (string) array_key_first($types) : '', 'gender' => ''),
            'notes'           => array(),
        );
        $doc = kop_facility_normalize($legacy, array('facility_id' => null, 'unique_name' => ''));
        $doc['facility_id'] = null;
        $doc['provenance']['source'] = 'program-homes';
        $doc['provenance']['sourceCategory'] = 'program-homes';
        $doc['provenance']['sourceProject'] = '';
        $doc['provenance']['sourceProjectId'] = null;
        $doc['provenance']['sourceOperator'] = null;
        $doc['provenance']['migratedAt'] = '';
        $doc['provenance']['legacyIds'] = array();
        $status_out = null;
        $id = (int) kop_facility_save($doc, $opts, $status_out);

        // The company most of its homes are filed under runs the program too.
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        if ($id > 0 && kop_facility_pages_table_exists($ofc)) {
            $counts = array();
            foreach ((array) $wpdb->get_col("SELECT operator_id FROM `{$ofc}` WHERE facility_id IN ({$in})") as $oid) {
                $counts[(int) $oid] = ($counts[(int) $oid] ?? 0) + 1;
            }
            arsort($counts);
            if ($counts) {
                $oid = (int) array_key_first($counts);
                if (!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$ofc}` WHERE operator_id = %d AND facility_id = %d", $oid, $id))) {
                    $wpdb->insert($ofc, array('operator_id' => $oid, 'facility_id' => $id, 'relationship' => 'current', 'sort_order' => 0));
                }
            }
        }
        return array($id, true);
    }
}

if (!function_exists('kop_program_homes_group')) {
    /**
     * Ties $homes (home_id => home name) to a program: $program_id when given
     * (an existing record), else a new record named $name. Returns the
     * program id. A home already in another group moves to this one.
     */
    function kop_program_homes_group(array $homes, $program_id, $name, array $opts) {
        global $wpdb;
        $homes = array_filter($homes, static function ($v, $k) { return (int) $k > 0; }, ARRAY_FILTER_USE_BOTH);
        $program_id = (int) $program_id;
        unset($homes[$program_id]);
        if (count($homes) < 1) throw new RuntimeException('Tick at least one home.');
        $created = false;
        if ($program_id <= 0) {
            list($program_id, $created) = kop_program_homes_create_program($name, array_keys($homes), $opts);
        } elseif (!$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = %d', $program_id))) {
            throw new RuntimeException('Program record #' . $program_id . ' is gone.');
        }
        if ($program_id <= 0) throw new RuntimeException('The program record could not be made.');
        unset($homes[$program_id]);
        $ht = kop_program_homes_table('homes');
        $gt = kop_program_homes_table('groups');
        foreach ($homes as $hid => $home_name) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$ht} WHERE home_id = %d", (int) $hid));
            $wpdb->insert($ht, array('home_id' => (int) $hid, 'program_id' => $program_id, 'home_name' => mb_substr(trim((string) $home_name), 0, 250)));
        }
        // A program cannot also be someone's home.
        $wpdb->query($wpdb->prepare("DELETE FROM {$ht} WHERE home_id = %d", $program_id));
        if (!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$gt} WHERE program_id = %d", $program_id))) {
            $wpdb->insert($gt, array(
                'program_id'     => $program_id,
                'created_record' => $created ? 1 : 0,
                'created_by'     => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ));
        }
        kop_program_homes_after_change();
        return $program_id;
    }
}

if (!function_exists('kop_program_homes_undo')) {
    /**
     * Unties every home of a program. When this screen made the program record
     * and nothing has been linked to it or changed on it since, the record goes
     * too. Returns 'removed' (record deleted) or 'kept'.
     */
    function kop_program_homes_undo($program_id) {
        global $wpdb;
        $program_id = (int) $program_id;
        $ht = kop_program_homes_table('homes');
        $gt = kop_program_homes_table('groups');
        $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$gt} WHERE program_id = %d", $program_id), ARRAY_A);
        $wpdb->query($wpdb->prepare("DELETE FROM {$ht} WHERE program_id = %d", $program_id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$gt} WHERE program_id = %d", $program_id));
        $result = 'kept';
        if ($group && (int) $group['created_record'] === 1 && kop_program_homes_record_untouched($program_id, (string) $group['created_at'])) {
            $wpdb->query($wpdb->prepare('DELETE FROM facilities_v2 WHERE id = %d', $program_id));
            foreach (array($wpdb->prefix . 'kop_operator_facilities', $wpdb->prefix . 'kop_facility_locations') as $t) {
                if (kop_facility_pages_table_exists($t)) $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE facility_id = %d", $program_id));
            }
            $result = 'removed';
        }
        kop_program_homes_after_change();
        return $result;
    }
}

if (!function_exists('kop_program_homes_record_untouched')) {
    /** No news, lawsuit or inspection link and no edit since the group was made. */
    function kop_program_homes_record_untouched($id, $created_at) {
        global $wpdb;
        foreach (array('news_facility_links', 'lawsuit_facility_links') as $t) {
            if (kop_facility_pages_table_exists($t) && $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE facility_id = %d", (int) $id))) return false;
        }
        if (function_exists('kop_inspection_links_for') && kop_inspection_links_for((int) $id)) return false;
        $updated = (string) $wpdb->get_var($wpdb->prepare('SELECT updated_at FROM facilities_v2 WHERE id = %d', (int) $id));
        $made = strtotime($created_at);
        return $updated === '' || !$made || strtotime($updated) <= $made + 120;
    }
}

if (!function_exists('kop_program_homes_after_change')) {
    function kop_program_homes_after_change() {
        kop_program_homes_map(true);
        if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
        if (function_exists('kop_operator_pages_flush_index')) kop_operator_pages_flush_index();
        if (function_exists('delete_transient')) {
            delete_transient('kop_program_homes_suggestions');
            delete_transient('kop_fmerge_screen'); // Merge Duplicates leaves out pairs in one program
        }
    }
}

// Merging two records keeps their places in a group (inc/facility-merge.php).
if (function_exists('add_filter')) {
    add_filter('kop_facility_merge_ref_tables', static function ($tables, $prefix = '') {
        global $wpdb;
        $p = $prefix !== '' ? $prefix : $wpdb->prefix;
        $tables[] = array('t' => $p . 'kop_program_homes', 'c' => 'home_id', 'k' => 'id');
        $tables[] = array('t' => $p . 'kop_program_homes', 'c' => 'program_id', 'k' => 'id');
        $tables[] = array('t' => $p . 'kop_program_groups', 'c' => 'program_id', 'k' => 'id');
        $tables[] = array('t' => $p . 'kop_program_conversions', 'c' => 'program_id', 'k' => 'id');
        return $tables;
    }, 10, 2);
}

// ---------------------------------------------------------------------------
// What the facility pages print
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_place')) {
    function kop_program_homes_place($city, $state, $country) {
        $state_name = ($state && function_exists('kop_state_canonical_name')) ? (string) kop_state_canonical_name($state) : (string) $state;
        return trim(($city ? $city . ', ' : '') . ($state_name !== '' ? $state_name : ($country && $country !== 'United States' ? $country : '')), ', ');
    }
}

if (!function_exists('kop_program_homes_home_rows')) {
    /** [{id, name, home_name, place, status, years, url}] for home ids, open first. */
    function kop_program_homes_home_rows(array $ids, array $names = array()) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return array();
        $rows = $wpdb->get_results('SELECT id, name, city, state, country, status, start_year, end_year FROM facilities_v2 WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A);
        $map = kop_program_homes_map();
        $out = array();
        foreach ((array) $rows as $r) {
            $id = (int) $r['id'];
            $status = trim((string) $r['status']);
            $years = '';
            if ($r['start_year'] || $r['end_year']) {
                $years = ($r['start_year'] ?: '?') . ($r['end_year'] ? '-' . $r['end_year'] : (strcasecmp($status, 'Open') === 0 ? '-present' : ''));
            }
            $home_name = $names[$id] ?? ($map['homes'][$id][1] ?? '');
            $out[] = array(
                'id'        => $id,
                'name'      => (string) $r['name'],
                'home_name' => $home_name !== '' ? $home_name : (string) $r['name'],
                'place'     => kop_program_homes_place($r['city'], $r['state'], $r['country']),
                'status'    => $status,
                'years'     => $years,
                'url'       => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($id) : '',
            );
        }
        usort($out, static function ($a, $b) {
            $ao = strcasecmp($a['status'], 'Open') === 0 ? 0 : 1;
            $bo = strcasecmp($b['status'], 'Open') === 0 ? 0 : 1;
            return $ao - $bo ?: strcasecmp($a['home_name'], $b['home_name']);
        });
        return $out;
    }
}

if (!function_exists('kop_program_homes_rollup')) {
    /**
     * A program's homes and what they add to its page: {homes, news,
     * lawsuits, violations, reports}. News, lawsuits and findings carry
     * 'home' (its name) and 'home_url'; ids already on the program's own
     * page ($own: news ids, lawsuit ids, finding ids) are left out.
     */
    function kop_program_homes_rollup($program_id, $state_code, array $own = array()) {
        global $wpdb;
        $home_ids = kop_program_homes_homes_of($program_id);
        $out = array('homes' => array(), 'news' => array(), 'lawsuits' => array(), 'violations' => array(), 'reports' => 0);
        if (!$home_ids) return $out;
        $out['homes'] = kop_program_homes_home_rows($home_ids);
        $label = array();
        foreach ($out['homes'] as $h) $label[$h['id']] = array($h['home_name'], $h['url']);

        $seen_news = array_flip(array_map('intval', $own['news'] ?? array()));
        foreach ($home_ids as $hid) {
            foreach (kop_facility_pages_news($hid) as $n) {
                if (isset($seen_news[$n['id']])) continue;
                $seen_news[$n['id']] = true;
                $n['home'] = $label[$hid][0] ?? '';
                $n['home_url'] = $label[$hid][1] ?? '';
                $out['news'][] = $n;
            }
        }
        usort($out['news'], static function ($a, $b) { return strcmp($b['date'], $a['date']) ?: $b['id'] - $a['id']; });

        // Lawsuits linked to a home by id (the name match runs on its own page).
        $seen_cases = array_flip(array_map('intval', $own['lawsuits'] ?? array()));
        if (kop_facility_pages_table_exists('lawsuit_facility_links') && kop_facility_pages_table_exists('lawsuits')) {
            $links = $wpdb->get_results('SELECT lawsuit_id, facility_id, link_type FROM lawsuit_facility_links WHERE facility_id IN (' . implode(',', array_map('intval', $home_ids)) . ')', ARRAY_A);
            $by_case = array();
            foreach ((array) $links as $l) if (!isset($seen_cases[(int) $l['lawsuit_id']])) $by_case[(int) $l['lawsuit_id']] = $l;
            if ($by_case) {
                $rows = $wpdb->get_results(
                    'SELECT id, case_name, case_number, court, filing_date, status, outcome, summary FROM lawsuits
                      WHERE id IN (' . implode(',', array_keys($by_case)) . ") AND publication_status IN ('approved','published')
                      ORDER BY filing_date DESC, id DESC",
                    ARRAY_A
                );
                foreach ((array) $rows as $r) {
                    $hid = (int) $by_case[(int) $r['id']]['facility_id'];
                    $out['lawsuits'][] = array(
                        'id'          => (int) $r['id'],
                        'case_name'   => trim((string) $r['case_name']),
                        'case_number' => trim((string) $r['case_number']),
                        'court'       => trim((string) $r['court']),
                        'year'        => $r['filing_date'] ? substr((string) $r['filing_date'], 0, 4) : '',
                        'status'      => ucfirst(str_replace('_', ' ', trim((string) $r['status']))),
                        'outcome'     => trim((string) $r['outcome']),
                        'summary'     => trim((string) $r['summary']),
                        'link_type'   => (string) $by_case[(int) $r['id']]['link_type'],
                        'home'        => $label[$hid][0] ?? '',
                        'home_url'    => $label[$hid][1] ?? '',
                    );
                }
            }
        }

        // Each home's licensed rows, matched in one pass over the state's
        // licensing list (the per-home lookup scans it once per call).
        if (kop_facility_pages_table_exists('inspection_facilities')) {
            $keys = array();
            $docs = $wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2 WHERE id IN (' . implode(',', array_map('intval', $home_ids)) . ')', ARRAY_A);
            foreach ((array) $docs as $d) {
                $doc = json_decode((string) $d['json_data'], true) ?: array();
                $keys[(int) $d['id']] = kop_facility_pages_doc_name_keys($doc, (string) $d['unique_name']);
            }
            $matched = array();
            foreach ($home_ids as $hid) {
                if (function_exists('kop_inspection_links_for')) {
                    foreach (kop_inspection_links_for($hid) as $iid) $matched[$hid][(int) $iid] = true;
                }
            }
            $sql = 'SELECT id, facility_name FROM inspection_facilities' . ($state_code !== '' ? ' WHERE state = %s' : '');
            $rows = $state_code !== '' ? $wpdb->get_results($wpdb->prepare($sql, $state_code), ARRAY_A) : array();
            foreach ((array) $rows as $r) {
                $rk = kop_facility_pages_name_key((string) $r['facility_name']);
                if ($rk === '') continue;
                foreach ($keys as $hid => $hk) {
                    foreach ($hk as $fk) {
                        if ($fk === $rk || kop_facility_pages_key_matches($fk, $rk)) { $matched[$hid][(int) $r['id']] = true; continue 3; }
                    }
                }
            }
            $seen_findings = array_flip(array_map('intval', $own['violations'] ?? array()));
            $all_ids = array();
            foreach ($matched as $hid => $iids) {
                $all_ids = array_merge($all_ids, array_keys($iids));
                foreach (kop_facility_pages_violations(array_keys($iids)) as $v) {
                    if (isset($seen_findings[$v['id']])) continue;
                    $seen_findings[$v['id']] = true;
                    $v['home'] = $label[$hid][0] ?? '';
                    $v['home_url'] = $label[$hid][1] ?? '';
                    $out['violations'][] = $v;
                }
            }
            usort($out['violations'], static function ($a, $b) {
                return ($b['severe'] <=> $a['severe']) ?: ($b['weight'] <=> $a['weight']) ?: strcmp($b['date'], $a['date']);
            });
            if ($all_ids && kop_facility_pages_table_exists('inspection_reports')) {
                $out['reports'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM inspection_reports WHERE facility_id IN (' . implode(',', array_unique(array_map('intval', $all_ids))) . ')');
            }
        }
        return $out;
    }
}

if (!function_exists('kop_program_homes_for_home')) {
    /** {program: {id, name, url}, home_name, others: home rows} for a home, or null. */
    function kop_program_homes_for_home($facility_id) {
        global $wpdb;
        $p = kop_program_homes_program_of($facility_id);
        if (!$p) return null;
        list($program_id, $home_name) = $p;
        $name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $program_id));
        $others = array_values(array_filter(kop_program_homes_homes_of($program_id), static function ($id) use ($facility_id) { return $id !== (int) $facility_id; }));
        return array(
            'program'   => array('id' => $program_id, 'name' => $name, 'url' => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($program_id) : ''),
            'home_name' => $home_name,
            'count'     => count($others) + 1,
            'others'    => kop_program_homes_home_rows($others),
        );
    }
}

// ---------------------------------------------------------------------------
// KOP Tools > Program Homes
// ---------------------------------------------------------------------------

if (!function_exists('kop_program_homes_admin_menu')) {
    function kop_program_homes_admin_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Program Homes', 'Program Homes', 'manage_options', 'kop-program-homes', 'kop_program_homes_page');
    }
    add_action('admin_menu', 'kop_program_homes_admin_menu', 30);
}

if (!function_exists('kop_program_homes_dismissed')) {
    function kop_program_homes_dismissed() {
        $v = get_option('kop_program_homes_dismissed', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_program_homes_suggestions')) {
    /** The suggestion list, cached ten minutes (it reads every record). */
    function kop_program_homes_suggestions($refresh = false) {
        $cached = $refresh ? false : get_transient('kop_program_homes_suggestions');
        if (is_array($cached)) return $cached;
        $list = kop_program_homes_suggest(kop_program_homes_rows(), kop_program_homes_grouped_ids(), kop_program_homes_dismissed());
        set_transient('kop_program_homes_suggestions', $list, 10 * MINUTE_IN_SECONDS);
        return $list;
    }
}

if (!function_exists('kop_program_homes_opts')) {
    function kop_program_homes_opts() {
        global $wpdb;
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) throw new RuntimeException('No database connection for facility saves.');
        require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
        if (!kop_v2_writes_active($pdo, $wpdb->prefix)) throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
        return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
    }
}

if (!function_exists('kop_program_homes_set_dismissed')) {
    /** Mark a suggestion (its key) "not one program" ($dismiss true), or put it back in the suggestions. */
    function kop_program_homes_set_dismissed($key, $dismiss) {
        $d = kop_program_homes_dismissed();
        if ($dismiss) $d[(string) $key] = time();
        else unset($d[(string) $key]);
        update_option('kop_program_homes_dismissed', $d, false);
        delete_transient('kop_program_homes_suggestions');
    }
}

if (!function_exists('kop_program_homes_remove_home')) {
    /** Take one home out of its program. */
    function kop_program_homes_remove_home($home_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_program_homes_table('homes') . ' WHERE home_id = %d', (int) $home_id));
        kop_program_homes_after_change();
    }
}

if (!function_exists('kop_program_homes_handle_post')) {
    function kop_program_homes_handle_post() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('kop_program_homes');
        $do = sanitize_key($_POST['do'] ?? '');
        $back = admin_url('admin.php?page=kop-program-homes' . (!empty($_POST['tab']) ? '&tab=' . sanitize_key($_POST['tab']) : '') . (!empty($_POST['q']) ? '&q=' . rawurlencode(wp_unslash($_POST['q'])) : '') . (!empty($_POST['why']) ? '&why=' . rawurlencode(wp_unslash($_POST['why'])) : ''));
        $msg = '';
        try {
            if ($do === 'group') {
                $homes = array();
                foreach ((array) ($_POST['home'] ?? array()) as $id => $on) {
                    if ($on) $homes[(int) $id] = sanitize_text_field(wp_unslash($_POST['home_name'][$id] ?? ''));
                }
                $program = (string) ($_POST['program'] ?? 'new');
                $name = sanitize_text_field(wp_unslash($_POST['program_name'] ?? ''));
                $pid = kop_program_homes_group($homes, ctype_digit($program) ? (int) $program : 0, $name, kop_program_homes_opts());
                $msg = 'Grouped ' . count($homes) . ' homes under #' . $pid . ' ' . $name . '.';
            } elseif ($do === 'dismiss' || $do === 'undismiss') {
                kop_program_homes_set_dismissed(sanitize_text_field(wp_unslash($_POST['key'] ?? '')), $do === 'dismiss');
                $msg = $do === 'dismiss' ? 'Marked as not one program.' : 'Back in the suggestions.';
            } elseif ($do === 'undo') {
                $r = kop_program_homes_undo((int) ($_POST['program_id'] ?? 0));
                $msg = $r === 'removed' ? 'Undone; the program record this screen made is deleted.' : 'Undone; the program record stays (it was there before, or has been linked or edited since).';
            } elseif ($do === 'remove_home') {
                kop_program_homes_remove_home((int) ($_POST['home_id'] ?? 0));
                $msg = 'Home taken out of the program.';
            } elseif (($do === 'convert' || $do === 'convert_undo') && function_exists('kop_phc_handle')) {
                $msg = kop_phc_handle($do);
            }
        } catch (Throwable $e) {
            $msg = 'Not done: ' . $e->getMessage();
        }
        set_transient('kop_program_homes_msg_' . get_current_user_id(), $msg, 60);
        wp_safe_redirect($back);
        exit;
    }
    add_action('admin_post_kop_program_homes', 'kop_program_homes_handle_post');
}

if (!function_exists('kop_program_homes_page')) {
    function kop_program_homes_page() {
        if (!current_user_can('manage_options')) return;
        kop_program_homes_install();
        $tab = sanitize_key($_GET['tab'] ?? 'suggested');
        $q = trim(sanitize_text_field(wp_unslash($_GET['q'] ?? '')));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $msg = get_transient('kop_program_homes_msg_' . get_current_user_id());
        if ($msg) delete_transient('kop_program_homes_msg_' . get_current_user_id());
        $base = admin_url('admin.php?page=kop-program-homes');
        $hidden = static function ($extra = array()) use ($tab, $q) {
            echo '<input type="hidden" name="action" value="kop_program_homes">';
            wp_nonce_field('kop_program_homes');
            echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="q" value="' . esc_attr($q) . '">';
            if (!empty($_GET['why'])) echo '<input type="hidden" name="why" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET['why']))) . '">';
            foreach ($extra as $k => $v) echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        };
        $suggestions = kop_program_homes_suggestions(isset($_GET['refresh']));
        $map = kop_program_homes_map(true);
        $dismissed = kop_program_homes_dismissed();
        // Companies that are one program (inc/program-homes-convert.php).
        $conv = function_exists('kop_phc_suggestions');
        if ($conv) kop_phc_install();
        $companies = $conv ? kop_phc_suggestions() : array();
        $converted = $conv ? kop_phc_rows(true) : array();
        ?>
        <div class="wrap kop-ph">
            <h1>Program homes</h1>
            <p class="kop-ph-lede">Some programs are licensed home by home or cottage by cottage, so they show up as many records ("Newport Academy &ndash; Acre", "&ndash; Aracena", ...). Group them under one program record: the program's page then lists every home, with their news, lawsuits and serious findings, and each home's page names its program. Each home keeps its own record, licence and inspection reports.</p>
            <?php if ($msg) : ?><div class="notice notice-info"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
            <nav class="nav-tab-wrapper">
                <a class="nav-tab<?php echo $tab === 'suggested' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base); ?>">Suggested (<?php echo count($suggestions); ?>)</a>
                <a class="nav-tab<?php echo $tab === 'grouped' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&tab=grouped'); ?>">Grouped (<?php echo count($map['programs']); ?>)</a>
                <?php if ($conv) : ?>
                <a class="nav-tab<?php echo $tab === 'companies' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&tab=companies'); ?>">Companies that are one program (<?php echo count($companies); ?>)</a>
                <a class="nav-tab<?php echo $tab === 'converted' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&tab=converted'); ?>">Converted (<?php echo count($converted); ?>)</a>
                <?php endif; ?>
                <a class="nav-tab<?php echo $tab === 'dismissed' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&tab=dismissed'); ?>">Not one program (<?php echo count($dismissed); ?>)</a>
            </nav>
            <form method="get" class="kop-ph-search">
                <input type="hidden" name="page" value="kop-program-homes"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                <label>Find <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Program or home name"></label>
                <button type="submit" class="button">Search</button>
            </form>
            <?php
            if ($tab === 'grouped') {
                kop_program_homes_page_grouped($map, $q, $hidden);
            } elseif ($tab === 'companies' && $conv) {
                kop_phc_page_suggested($companies, $q, $hidden);
            } elseif ($tab === 'converted' && $conv) {
                kop_phc_page_converted($q, $hidden);
            } elseif ($tab === 'dismissed') {
                global $wpdb;
                foreach ($dismissed as $key => $when) {
                    // "company:<id>" is a company kept as a company (the Companies that are one program tab).
                    $label = ucwords(str_replace('|', ' in ', $key));
                    if (strpos($key, 'company:') === 0) {
                        $cname = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}kop_operators WHERE id = %d", (int) substr($key, 8)));
                        $label = 'Company: ' . ($cname !== '' ? $cname : '#' . substr($key, 8));
                    }
                    if ($q !== '' && stripos($label, $q) === false) continue;
                    echo '<div class="kop-ph-card"><p><strong>' . esc_html($label) . '</strong></p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    $hidden(array('do' => 'undismiss', 'key' => $key));
                    echo '<button class="button">Suggest it again</button></form></div>';
                }
            } else {
                // A home is found by its past or other names too.
                $aka = ($q !== '' && function_exists('kop_v2_alias_match_ids')) ? kop_v2_alias_match_ids('facilities', $q) : array();
                $list = array_values(array_filter($suggestions, static function ($s) use ($q, $aka) {
                    if ($q === '') return true;
                    if (stripos($s['program_name'], $q) !== false) return true;
                    foreach ($s['homes'] as $h) if (stripos($h['name'], $q) !== false || isset($aka[(int) $h['id']])) return true;
                    return false;
                }));
                // Why each group was suggested: one link per reason, with how many.
                $why = sanitize_text_field(wp_unslash($_GET['why'] ?? ''));
                $reasons = kop_program_homes_reasons();
                $by_why = array();
                foreach ($list as $s) $by_why[$s['reason'] ?? 'dash'] = ($by_why[$s['reason'] ?? 'dash'] ?? 0) + 1;
                $why_url = static function ($w) use ($base, $q) {
                    return $base . ($w !== '' ? '&why=' . rawurlencode($w) : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
                };
                echo '<p class="kop-ph-why">' . ($why === '' ? '<strong>All (' . count($list) . ')</strong>' : '<a href="' . esc_url($why_url('')) . '">All (' . count($list) . ')</a>');
                foreach ($reasons as $w => $label) {
                    if (empty($by_why[$w])) continue;
                    $text = esc_html($label) . ' (' . (int) $by_why[$w] . ')';
                    echo ' &middot; ' . ($why === $w ? '<strong>' . $text . '</strong>' : '<a href="' . esc_url($why_url($w)) . '">' . $text . '</a>');
                }
                echo '</p>';
                if ($why !== '') $list = array_values(array_filter($list, static function ($s) use ($why) { return ($s['reason'] ?? 'dash') === $why; }));
                $per = 20;
                $pages = max(1, (int) ceil(count($list) / $per));
                $paged = min($paged, $pages);
                echo '<p class="kop-ph-count">' . count($list) . ' suggested groups, biggest first. Untick any record that is not one of its homes; change the program name if it should read differently. "Names start the same" groups are the least sure.</p>';
                foreach (array_slice($list, ($paged - 1) * $per, $per) as $s) {
                    kop_program_homes_page_card($s, $hidden);
                }
                if ($pages > 1) {
                    echo '<p class="kop-ph-pages">';
                    for ($i = 1; $i <= $pages; $i++) {
                        $u = $base . '&paged=' . $i . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($why !== '' ? '&why=' . rawurlencode($why) : '');
                        echo $i === $paged ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url($u) . '">' . $i . '</a> ';
                    }
                    echo '</p>';
                }
            }
            ?>
        </div>
        <style>
            .kop-ph-lede { max-width: 60rem; font-size: 14px; }
            .kop-ph-search { margin: 12px 0; }
            .kop-ph-card { max-width: 60rem; margin: 0 0 14px; padding: 12px 16px; border: 1px solid #c3c4c7; border-left: 4px solid #33A7B5; background: #fff; color: #1d2327; }
            .kop-ph-card h2 { margin: 0 0 6px; font-size: 16px; color: #1d2327; }
            .kop-ph-card .kop-ph-meta { margin: 0 0 8px; color: #4A5568; }
            .kop-ph-card .kop-ph-warn { margin: 0 0 8px; padding: 6px 10px; background: #FFF5CB; border-left: 3px solid #EF9034; color: #1d2327; }
            .kop-ph-card table { border-collapse: collapse; width: 100%; margin: 0 0 10px; }
            .kop-ph-card td { padding: 3px 6px; border-top: 1px solid #f0f0f1; vertical-align: middle; }
            .kop-ph-card td input[type=text] { width: 100%; }
            .kop-ph-card .kop-ph-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
            .kop-ph-card .kop-ph-actions label { margin-right: 8px; }
            .kop-ph-count, .kop-ph-pages { color: #4A5568; }
            .kop-ph-why { margin: 8px 0; }
        </style>
        <?php
    }
}

if (!function_exists('kop_program_homes_page_card')) {
    function kop_program_homes_page_card(array $s, callable $hidden) {
        $fid = 'ph-' . md5($s['key']);
        $state_name = function_exists('kop_state_canonical_name') && $s['state'] !== '' ? kop_state_canonical_name($s['state']) : $s['state'];
        ?>
        <div class="kop-ph-card">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php $hidden(array('do' => 'group')); ?>
                <h2><?php echo esc_html($s['program_name']); ?><?php echo $state_name !== '' ? ', ' . esc_html($state_name) : ''; ?> <span class="kop-ph-meta">(<?php echo count($s['homes']); ?> records)</span></h2>
                <p class="kop-ph-meta">Why: <?php echo esc_html(kop_program_homes_reasons()[$s['reason'] ?? 'dash'] ?? ''); ?><?php if ($s['operator'] !== '') : ?>. Filed under <?php echo esc_html($s['operator']); ?><?php endif; ?></p>
                <?php foreach ($s['warnings'] as $w) : ?><p class="kop-ph-warn"><?php echo esc_html($w); ?></p><?php endforeach; ?>
                <table>
                    <tbody>
                    <?php foreach ($s['homes'] as $h) :
                        $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($h['id']) : '';
                        ?>
                        <tr>
                            <td style="width:24px"><input type="checkbox" name="home[<?php echo (int) $h['id']; ?>]" value="1" checked aria-label="<?php echo esc_attr('Include ' . $h['name']); ?>"></td>
                            <td style="width:40%"><input type="text" name="home_name[<?php echo (int) $h['id']; ?>]" value="<?php echo esc_attr($h['home_name']); ?>" aria-label="Home name"></td>
                            <td><?php echo $url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($h['name']) . '</a>' : esc_html($h['name']); ?>
                                <span class="kop-ph-meta"><?php echo esc_html(trim('#' . $h['id'] . ' ' . $h['city'] . ($h['status'] !== '' ? ' | ' . $h['status'] : ''))); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="kop-ph-actions">
                    <label for="<?php echo esc_attr($fid); ?>">Program name</label>
                    <input type="text" id="<?php echo esc_attr($fid); ?>" name="program_name" value="<?php echo esc_attr($s['program_name']); ?>" size="40">
                    <?php if ($s['existing']) : ?>
                        <label><input type="radio" name="program" value="<?php echo (int) $s['existing']['id']; ?>" checked> Use the existing record #<?php echo (int) $s['existing']['id']; ?> <?php echo esc_html($s['existing']['name']); ?></label>
                        <label><input type="radio" name="program" value="new"> Make a new program record</label>
                    <?php else : ?>
                        <input type="hidden" name="program" value="new">
                    <?php endif; ?>
                    <button type="submit" class="button button-primary">Group these homes</button>
                </div>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <?php $hidden(array('do' => 'dismiss', 'key' => $s['key'])); ?>
                <button type="submit" class="button-link">Not one program: stop suggesting this</button>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('kop_program_homes_page_grouped')) {
    function kop_program_homes_page_grouped(array $map, $q, callable $hidden) {
        global $wpdb;
        if (!$map['programs']) {
            echo '<p>No programs grouped yet.</p>';
            return;
        }
        $names = array();
        $ids = array_merge(array_keys($map['programs']), array_keys($map['homes']));
        foreach ((array) $wpdb->get_results('SELECT id, name FROM facilities_v2 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', ARRAY_A) as $r) {
            $names[(int) $r['id']] = (string) $r['name'];
        }
        uasort($map['programs'], static function ($a, $b) { return count($b) - count($a); });
        // Past and other names count too.
        $aka = ($q !== '' && function_exists('kop_v2_alias_match_ids')) ? kop_v2_alias_match_ids('facilities', $q) : array();
        foreach ($map['programs'] as $pid => $homes) {
            $hay = ($names[$pid] ?? '') . ' ' . implode(' ', array_map(static function ($h) use ($names) { return $names[$h] ?? ''; }, $homes));
            $by_alias = isset($aka[$pid]) || array_filter($homes, static function ($h) use ($aka) { return isset($aka[(int) $h]); });
            if ($q !== '' && stripos($hay, $q) === false && !$by_alias) continue;
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($pid) : '';
            echo '<div class="kop-ph-card"><h2>' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($names[$pid] ?? '#' . $pid) . '</a>' : esc_html($names[$pid] ?? '#' . $pid))
                . ' <span class="kop-ph-meta">#' . (int) $pid . ', ' . count($homes) . ' homes</span></h2><table><tbody>';
            foreach ($homes as $hid) {
                echo '<tr><td>' . esc_html($map['homes'][$hid][1] !== '' ? $map['homes'][$hid][1] : ($names[$hid] ?? '')) . '</td><td class="kop-ph-meta">#' . (int) $hid . ' ' . esc_html($names[$hid] ?? '') . '</td><td style="width:1%"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                $hidden(array('do' => 'remove_home', 'home_id' => $hid));
                echo '<button class="button-link">Take out</button></form></td></tr>';
            }
            echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $hidden(array('do' => 'undo', 'program_id' => $pid));
            echo '<button class="button">Undo this group</button></form></div>';
        }
    }
}

// A program record with homes always gets a page.
if (function_exists('add_filter')) {
    add_filter('kop_facility_page_signals', static function ($signals, $doc = array(), $id = 0) {
        if ($id && function_exists('kop_program_homes_homes_of') && kop_program_homes_homes_of((int) $id)) $signals[] = 'programHomes';
        return $signals;
    }, 10, 3);
}
