<?php
/**
 * Programs that share a campus: separate programs, each with its own record,
 * run side by side on one site (two specialised residential programs of one
 * hospital, a girls' and a boys' school on one ranch). They are not homes of
 * one program (inc/program-homes.php: one program record, many licensed
 * homes), and not merely two programs of one company (the "Same operator"
 * list): no record stands over the others, and they share grounds, staff
 * and often their reports.
 *
 *   {prefix}kop_campuses          id, name (optional, "Provo Canyon campus"), created_at, created_by
 *   {prefix}kop_campus_programs   facility_id (PK), campus_id, created_at
 *
 * A record is on at most one campus. Each page names the other programs on
 * its campus (they leave its "Same operator" list). Tied at KOP Tools >
 * Program Homes > Same campus (suggested from one street address under one
 * company, or by hand), from a Program Homes suggestion that turns out to be
 * separate programs, or from a Merge Duplicates pair. Every change is logged
 * with the campuses as they were before (kop_campus_log), so Undo is exact.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_CAMPUS_DB_VERSION')) {
    define('KOP_CAMPUS_DB_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

if (!function_exists('kop_campus_table')) {
    function kop_campus_table($which = 'programs') {
        global $wpdb;
        return $wpdb->prefix . ($which === 'campuses' ? 'kop_campuses' : 'kop_campus_programs');
    }
}

if (!function_exists('kop_campus_install')) {
    function kop_campus_install($force = false) {
        if (!$force && get_option('kop_campus_db') === KOP_CAMPUS_DB_VERSION) return;
        global $wpdb;
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_campus_table('campuses') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_campus_table('programs') . ' (
            facility_id BIGINT UNSIGNED NOT NULL,
            campus_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (facility_id),
            KEY campus_id (campus_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        update_option('kop_campus_db', KOP_CAMPUS_DB_VERSION, false);
    }
    add_action('admin_init', 'kop_campus_install');
}

if (!function_exists('kop_campus_ready')) {
    function kop_campus_ready() {
        static $ready = null;
        if ($ready === null) {
            $ready = function_exists('kop_facility_pages_table_exists')
                && kop_facility_pages_table_exists(kop_campus_table('programs'));
        }
        return $ready;
    }
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

if (!function_exists('kop_campus_map')) {
    /**
     * {facilities: facility_id => campus_id, campuses: campus_id => {name, ids}}.
     * Records that are gone (merged away) drop out; a campus left with one
     * program is no campus.
     */
    function kop_campus_map($refresh = false) {
        static $memo = null;
        if ($memo !== null && !$refresh) return $memo;
        $memo = array('facilities' => array(), 'campuses' => array());
        if (!kop_campus_ready()) return $memo;
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT m.facility_id, m.campus_id, c.name FROM ' . kop_campus_table('programs') . ' m
               JOIN facilities_v2 f ON f.id = m.facility_id
               LEFT JOIN ' . kop_campus_table('campuses') . ' c ON c.id = m.campus_id
              ORDER BY m.campus_id, m.facility_id',
            ARRAY_A
        );
        $by = array();
        foreach ((array) $rows as $r) {
            $cid = (int) $r['campus_id'];
            if (!isset($by[$cid])) $by[$cid] = array('name' => trim((string) $r['name']), 'ids' => array());
            $by[$cid]['ids'][] = (int) $r['facility_id'];
        }
        foreach ($by as $cid => $c) {
            if (count($c['ids']) < 2) continue;
            $memo['campuses'][$cid] = $c;
            foreach ($c['ids'] as $id) $memo['facilities'][$id] = $cid;
        }
        return $memo;
    }
}

if (!function_exists('kop_campus_of')) {
    /** Campus id of a record, or 0. */
    function kop_campus_of($facility_id) {
        $map = kop_campus_map();
        return (int) ($map['facilities'][(int) $facility_id] ?? 0);
    }
}

if (!function_exists('kop_campus_others')) {
    /** The other records on a record's campus. */
    function kop_campus_others($facility_id) {
        $cid = kop_campus_of($facility_id);
        if (!$cid) return array();
        $map = kop_campus_map();
        return array_values(array_diff($map['campuses'][$cid]['ids'], array((int) $facility_id)));
    }
}

if (!function_exists('kop_campus_cache_key')) {
    /** Part of kop_facility_pages_fingerprint(). */
    function kop_campus_cache_key() {
        if (!kop_campus_ready()) return '-';
        global $wpdb;
        $row = $wpdb->get_row('SELECT COUNT(*), MAX(facility_id), SUM(campus_id) FROM ' . kop_campus_table('programs'), ARRAY_N);
        // A campus name prints on the pages, so a renamed one changes them too.
        $names = $wpdb->get_col('SELECT name FROM ' . kop_campus_table('campuses') . ' ORDER BY id');
        return (is_array($row) ? implode('|', array_map('strval', $row)) : '?') . '|' . md5(implode("\n", (array) $names));
    }
}

if (!function_exists('kop_campus_for_page')) {
    /**
     * What a facility page prints: {id, name, others: [{id, name, place,
     * status, years, url}]} or null. Open programs first.
     */
    function kop_campus_for_page($facility_id) {
        $others = kop_campus_others($facility_id);
        if (!$others || !function_exists('kop_program_homes_home_rows')) return null;
        $cid = kop_campus_of($facility_id);
        $map = kop_campus_map();
        $rows = kop_program_homes_home_rows($others);
        foreach ($rows as &$r) {
            unset($r['home_name']);
        }
        unset($r);
        usort($rows, static function ($a, $b) {
            $ao = strcasecmp($a['status'], 'Open') === 0 ? 0 : 1;
            $bo = strcasecmp($b['status'], 'Open') === 0 ? 0 : 1;
            return $ao - $bo ?: strcasecmp($a['name'], $b['name']);
        });
        return array('id' => $cid, 'name' => (string) ($map['campuses'][$cid]['name'] ?? ''), 'others' => $rows);
    }
}

// ---------------------------------------------------------------------------
// Writing (every change logged with the campuses as they were, for Undo)
// ---------------------------------------------------------------------------

if (!function_exists('kop_campus_log')) {
    function kop_campus_log() {
        $v = get_option('kop_campus_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_campus_snapshot')) {
    /** The rows of every campus touching $ids: {campuses: [id => name], programs: [facility_id => campus_id]}. */
    function kop_campus_snapshot(array $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $snap = array('campuses' => array(), 'programs' => array(), 'ids' => $ids);
        if (!$ids) return $snap;
        $pt = kop_campus_table('programs');
        $cids = array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT campus_id FROM {$pt} WHERE facility_id IN (" . implode(',', $ids) . ')'));
        if ($cids) {
            foreach ((array) $wpdb->get_results("SELECT facility_id, campus_id FROM {$pt} WHERE campus_id IN (" . implode(',', $cids) . ')', ARRAY_A) as $r) {
                $snap['programs'][(int) $r['facility_id']] = (int) $r['campus_id'];
            }
            foreach ((array) $wpdb->get_results('SELECT id, name FROM ' . kop_campus_table('campuses') . ' WHERE id IN (' . implode(',', $cids) . ')', ARRAY_A) as $r) {
                $snap['campuses'][(int) $r['id']] = (string) $r['name'];
            }
        }
        return $snap;
    }
}

if (!function_exists('kop_campus_write_log')) {
    function kop_campus_write_log($op, array $ids, array $before, $campus_id, $note = '') {
        $log = kop_campus_log();
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $id = substr(md5(uniqid('', true)), 0, 12);
        array_unshift($log, array(
            'id' => $id, 'op' => $op, 'ids' => array_values(array_map('intval', $ids)), 'campus' => (int) $campus_id,
            'before' => $before, 'note' => (string) $note,
            'by' => $user && !empty($user->user_login) ? (string) $user->user_login : '', 'at' => gmdate('Y-m-d H:i:s'),
        ));
        update_option('kop_campus_log', array_slice($log, 0, 300), false);
        return $id;
    }
}

if (!function_exists('kop_campus_tie')) {
    /**
     * Puts $ids (two or more records) on one campus. A record already on a
     * campus brings it along: the campuses join, and the name given (else the
     * first named one) is kept. Records that are homes of one program are
     * refused (they are that program's, inc/program-homes.php). Returns
     * {campus, log}.
     */
    function kop_campus_tie(array $ids, $name = '', $note = '') {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) < 2 && !array_filter($ids, 'kop_campus_of')) throw new RuntimeException('Pick at least two records.');
        $found = array_map('intval', (array) $wpdb->get_col('SELECT id FROM facilities_v2 WHERE id IN (' . implode(',', $ids ?: array(0)) . ')'));
        if ($missing = array_diff($ids, $found)) throw new RuntimeException('Record #' . implode(', #', $missing) . ' is gone.');
        if (function_exists('kop_program_homes_program_of')) {
            $prog = array();
            foreach ($ids as $id) {
                $in = kop_program_homes_program_of($id);
                $p = $in ? (int) $in[0] : (kop_program_homes_homes_of($id) ? $id : 0);
                if ($p && isset($prog[$p])) throw new RuntimeException('#' . $prog[$p] . ' and #' . $id . ' are homes of one program already (Program Homes), not separate programs.');
                if ($p) $prog[$p] = $id;
            }
        }
        $before = kop_campus_snapshot($ids);
        $pt = kop_campus_table('programs');
        $ct = kop_campus_table('campuses');
        $cids = array_values(array_unique(array_values(array_intersect_key($before['programs'], array_flip($ids)))));
        sort($cids);
        $name = trim((string) $name);
        if ($cids) {
            $cid = $cids[0];
            if ($name === '') {
                foreach ($cids as $c) if (($before['campuses'][$c] ?? '') !== '') { $name = $before['campuses'][$c]; break; }
            }
            $wpdb->query($wpdb->prepare("UPDATE {$ct} SET name = %s WHERE id = %d", $name, $cid));
            foreach (array_slice($cids, 1) as $c) {
                $wpdb->query($wpdb->prepare("UPDATE {$pt} SET campus_id = %d WHERE campus_id = %d", $cid, $c));
                $wpdb->query($wpdb->prepare("DELETE FROM {$ct} WHERE id = %d", $c));
            }
        } else {
            $wpdb->insert($ct, array('name' => $name, 'created_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0));
            $cid = (int) $wpdb->insert_id;
            if ($cid <= 0) $cid = (int) $wpdb->get_var("SELECT MAX(id) FROM {$ct}");
        }
        foreach ($ids as $id) {
            if (($before['programs'][$id] ?? 0) === $cid) continue;
            $wpdb->query($wpdb->prepare("DELETE FROM {$pt} WHERE facility_id = %d", $id));
            $wpdb->insert($pt, array('facility_id' => $id, 'campus_id' => $cid));
        }
        $log = kop_campus_write_log('tie', $ids, $before, $cid, $note);
        kop_campus_after_change();
        return array('campus' => $cid, 'log' => $log);
    }
}

if (!function_exists('kop_campus_remove')) {
    /** Takes one record off its campus; a campus left with one program goes too. Returns the log id. */
    function kop_campus_remove($facility_id) {
        global $wpdb;
        $facility_id = (int) $facility_id;
        $before = kop_campus_snapshot(array($facility_id));
        $cid = (int) ($before['programs'][$facility_id] ?? 0);
        if (!$cid) throw new RuntimeException('That record is not on a campus.');
        $pt = kop_campus_table('programs');
        $wpdb->query($wpdb->prepare("DELETE FROM {$pt} WHERE facility_id = %d", $facility_id));
        $left = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT facility_id FROM {$pt} WHERE campus_id = %d", $cid)));
        if (count($left) < 2) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$pt} WHERE campus_id = %d", $cid));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_campus_table('campuses') . ' WHERE id = %d', $cid));
        }
        $log = kop_campus_write_log('remove', array($facility_id), $before, $cid);
        kop_campus_after_change();
        return $log;
    }
}

if (!function_exists('kop_campus_rename')) {
    function kop_campus_rename($campus_id, $name) {
        global $wpdb;
        $campus_id = (int) $campus_id;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT facility_id FROM ' . kop_campus_table('programs') . ' WHERE campus_id = %d', $campus_id)));
        if (!$ids) throw new RuntimeException('That campus is gone.');
        $before = kop_campus_snapshot($ids);
        $wpdb->query($wpdb->prepare('UPDATE ' . kop_campus_table('campuses') . ' SET name = %s WHERE id = %d', trim((string) $name), $campus_id));
        $log = kop_campus_write_log('rename', $ids, $before, $campus_id);
        kop_campus_after_change();
        return $log;
    }
}

if (!function_exists('kop_campus_undo')) {
    /**
     * Puts the campuses a logged change touched back as they were: every
     * record it named and every campus it saw return to the snapshot.
     */
    function kop_campus_undo($log_id) {
        global $wpdb;
        $log = kop_campus_log();
        foreach ($log as $i => $e) {
            if ($e['id'] !== (string) $log_id) continue;
            if (!empty($e['undone'])) throw new RuntimeException('That change is undone already.');
            $b = (array) $e['before'];
            $pt = kop_campus_table('programs');
            $ct = kop_campus_table('campuses');
            $ids = array_unique(array_merge(array_map('intval', (array) $e['ids']), array_map('intval', array_keys((array) ($b['programs'] ?? array())))));
            $cids = array_unique(array_merge(array_map('intval', array_keys((array) ($b['campuses'] ?? array()))), array((int) $e['campus'])));
            $cids = array_filter($cids);
            // Everything on the campuses it touched, now, joins what goes back.
            if ($cids) $ids = array_unique(array_merge($ids, array_map('intval', (array) $wpdb->get_col("SELECT facility_id FROM {$pt} WHERE campus_id IN (" . implode(',', $cids) . ')'))));
            if ($ids) $wpdb->query("DELETE FROM {$pt} WHERE facility_id IN (" . implode(',', array_map('intval', $ids)) . ')');
            if ($cids) $wpdb->query("DELETE FROM {$ct} WHERE id IN (" . implode(',', array_map('intval', $cids)) . ')');
            foreach ((array) ($b['campuses'] ?? array()) as $cid => $name) {
                $wpdb->insert($ct, array('id' => (int) $cid, 'name' => (string) $name));
            }
            foreach ((array) ($b['programs'] ?? array()) as $fid => $cid) {
                $wpdb->insert($pt, array('facility_id' => (int) $fid, 'campus_id' => (int) $cid));
            }
            $log[$i]['undone'] = gmdate('Y-m-d H:i:s');
            update_option('kop_campus_log', $log, false);
            kop_campus_after_change();
            return 'Undone: the campus is back as it was.';
        }
        throw new RuntimeException('That change is not in the log.');
    }
}

if (!function_exists('kop_campus_after_change')) {
    function kop_campus_after_change() {
        kop_campus_map(true);
        if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
        if (function_exists('delete_transient')) {
            delete_transient('kop_campus_suggestions');
            delete_transient('kop_fmerge_screen'); // Merge Duplicates leaves out pairs on one campus
        }
    }
}

// Merging two records keeps the kept one's campus (UPDATE IGNORE: if both were there, the dropped row stays and drops out of the map).
if (function_exists('add_filter')) {
    add_filter('kop_facility_merge_ref_tables', static function ($tables, $prefix = '') {
        global $wpdb;
        $p = $prefix !== '' ? $prefix : $wpdb->prefix;
        $tables[] = array('t' => $p . 'kop_campus_programs', 'c' => 'facility_id', 'k' => 'id');
        return $tables;
    }, 10, 2);
}

// ---------------------------------------------------------------------------
// Suggestions: one street address, different programs, one company
// ---------------------------------------------------------------------------

if (!function_exists('kop_campus_suggest')) {
    /**
     * Records at one street address (kop_fmerge_street_key) in one state, with
     * different names and a current company in common, not renames of each
     * other and not homes of one program. $rows: kop_fmerge_prepare() rows
     * with 'ops' = current company ids. $company_names: id => name.
     * $in_program: id => program id. $on_campus: id => campus id.
     * Returns [{key, ids, names: id => name, company, campus (id already
     * holding some of them, else 0)}], largest first.
     */
    function kop_campus_suggest(array $rows, array $company_names = array(), array $in_program = array(), array $on_campus = array(), array $dismissed = array()) {
        $by_street = array();
        foreach ($rows as $r) {
            if (!$r || $r['street'] === '') continue;
            $by_street[$r['street'] . '|' . $r['state']][] = $r;
        }
        $out = array();
        foreach ($by_street as $key => $list) {
            if (count($list) < 2 || isset($dismissed[$key])) continue;
            // Pairs: different names, a shared company, not renamed, not one program.
            $parent = array();
            $find = static function ($x) use (&$parent) {
                while ($parent[$x] !== $x) $x = $parent[$x] = $parent[$parent[$x]];
                return $x;
            };
            $company = array();
            foreach ($list as $r) $parent[$r['id']] = $r['id'];
            $n = count($list);
            $edges = 0;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    $ka = implode(' ', $a['words']);
                    $kb = implode(' ', $b['words']);
                    if ($ka === $kb || $ka === '' || $kb === '') continue;
                    if (in_array($kb, $a['past'], true) || in_array($ka, $b['past'], true)
                        || in_array($kb, $a['alts'], true) || in_array($ka, $b['alts'], true)) continue;
                    $pa = $in_program[$a['id']] ?? 0;
                    if ($pa && $pa === ($in_program[$b['id']] ?? -1)) continue;
                    $shared = array_intersect($a['ops'], $b['ops']);
                    if (!$shared) continue;
                    $ca = $on_campus[$a['id']] ?? 0;
                    if ($ca && $ca === ($on_campus[$b['id']] ?? -1)) continue;
                    $parent[$find($a['id'])] = $find($b['id']);
                    $company[(int) reset($shared)] = true;
                    $edges++;
                }
            }
            if (!$edges) continue;
            $groups = array();
            foreach ($list as $r) $groups[$find($r['id'])][] = $r;
            foreach ($groups as $g) {
                if (count($g) < 2) continue;
                $ids = array_map(static function ($r) { return (int) $r['id']; }, $g);
                sort($ids);
                $names = array();
                foreach ($g as $r) $names[(int) $r['id']] = $r['name'];
                $campus = 0;
                foreach ($ids as $id) if (!empty($on_campus[$id])) { $campus = (int) $on_campus[$id]; break; }
                $cname = array();
                foreach (array_keys($company) as $cid) if (isset($company_names[$cid])) $cname[] = $company_names[$cid];
                $out[] = array('key' => $key, 'ids' => $ids, 'names' => $names, 'company' => implode('; ', $cname), 'campus' => $campus);
            }
        }
        usort($out, static function ($a, $b) {
            return count($b['ids']) - count($a['ids']) ?: strcasecmp(reset($a['names']), reset($b['names']));
        });
        return $out;
    }
}

if (!function_exists('kop_campus_suggestions')) {
    /** The live suggestion list, cached ten minutes. */
    function kop_campus_suggestions($refresh = false) {
        $cached = $refresh ? false : get_transient('kop_campus_suggestions');
        if (is_array($cached)) return $cached;
        if (!function_exists('kop_fmerge_prepare')) {
            $f = __DIR__ . '/facility-merge-match.php';
            if (file_exists($f)) require_once $f;
        }
        if (!function_exists('kop_fmerge_prepare')) return array();
        global $wpdb;
        $ops = array();
        $names = array();
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        if (kop_facility_pages_table_exists($ofc)) {
            foreach ((array) $wpdb->get_results("SELECT facility_id, operator_id FROM `{$ofc}` WHERE relationship = 'current' OR relationship = '' OR relationship IS NULL", ARRAY_A) as $o) {
                $ops[(int) $o['facility_id']][] = (int) $o['operator_id'];
            }
            foreach ((array) $wpdb->get_results("SELECT id, name FROM `{$wpdb->prefix}kop_operators`", ARRAY_A) as $o) $names[(int) $o['id']] = (string) $o['name'];
        }
        $rows = array();
        foreach ((array) $wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2', ARRAY_A) as $r) {
            $r['ops'] = $ops[(int) $r['id']] ?? array();
            $p = kop_fmerge_prepare($r);
            if ($p) $rows[] = $p;
        }
        $in_program = array();
        if (function_exists('kop_program_homes_map')) {
            $pm = kop_program_homes_map();
            foreach ($pm['homes'] as $hid => $x) $in_program[$hid] = (int) $x[0];
            foreach ($pm['programs'] as $pid => $_) $in_program[$pid] = (int) $pid;
        }
        $map = kop_campus_map(true);
        $list = kop_campus_suggest($rows, $names, $in_program, $map['facilities'], kop_campus_dismissed());
        set_transient('kop_campus_suggestions', $list, 10 * MINUTE_IN_SECONDS);
        return $list;
    }
}

if (!function_exists('kop_campus_dismissed')) {
    function kop_campus_dismissed() {
        $v = get_option('kop_campus_dismissed', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_campus_set_dismissed')) {
    function kop_campus_set_dismissed($key, $dismiss) {
        $d = kop_campus_dismissed();
        if ($dismiss) $d[(string) $key] = time();
        else unset($d[(string) $key]);
        update_option('kop_campus_dismissed', $d, false);
        delete_transient('kop_campus_suggestions');
    }
}

// ---------------------------------------------------------------------------
// KOP Tools > Program Homes: the "Same campus" and "Campus suggestions" tabs
// ---------------------------------------------------------------------------

if (!function_exists('kop_campus_handle')) {
    /** The Program Homes form's campus_* actions. Returns the message. */
    function kop_campus_handle($do) {
        kop_campus_install();
        if ($do === 'campus_tie') {
            // From a Program Homes suggestion card (home[id]=1), a campus suggestion (ids[]) or by hand (a, b).
            $ids = array();
            foreach ((array) ($_POST['home'] ?? array()) as $id => $on) if ($on) $ids[] = (int) $id;
            foreach ((array) ($_POST['ids'] ?? array()) as $id) $ids[] = (int) $id;
            foreach (array('a', 'b') as $k) if (!empty($_POST[$k])) $ids[] = (int) $_POST[$k];
            $name = sanitize_text_field(wp_unslash($_POST['campus_name'] ?? ''));
            kop_campus_tie($ids, $name);
            // A Program Homes suggestion that is separate programs leaves the homes suggestions.
            if (!empty($_POST['key']) && function_exists('kop_program_homes_set_dismissed') && !empty($_POST['home'])) {
                kop_program_homes_set_dismissed(sanitize_text_field(wp_unslash($_POST['key'])), true);
            }
            return 'Tied ' . count(array_unique($ids)) . ' programs as sharing a campus. Undo is on the Same campus tab.';
        }
        if ($do === 'campus_remove') {
            kop_campus_remove((int) ($_POST['facility_id'] ?? 0));
            return 'Taken off the campus. Undo is under Recent changes.';
        }
        if ($do === 'campus_rename') {
            kop_campus_rename((int) ($_POST['campus_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['campus_name'] ?? '')));
            return 'Campus name saved.';
        }
        if ($do === 'campus_undo') {
            return kop_campus_undo(sanitize_key($_POST['log'] ?? ''));
        }
        if ($do === 'campus_dismiss' || $do === 'campus_undismiss') {
            kop_campus_set_dismissed(sanitize_text_field(wp_unslash($_POST['key'] ?? '')), $do === 'campus_dismiss');
            return $do === 'campus_dismiss' ? 'Not one campus: no longer suggested.' : 'Back in the campus suggestions.';
        }
        throw new RuntimeException('Unknown action.');
    }
}

if (!function_exists('kop_campus_page_tab')) {
    /** The Same campus tab ($which 'campus') or Campus suggestions tab ('campus_suggested'). */
    function kop_campus_page_tab($which, $q, callable $hidden) {
        global $wpdb;
        kop_campus_install();
        $post = esc_url(admin_url('admin-post.php'));
        $page_url = static function ($id) { return function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($id) : ''; };
        $link = static function ($id, $name) use ($page_url) {
            $u = $page_url($id);
            return ($u !== '' ? '<a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>' : esc_html($name))
                . ' <span class="kop-ph-meta">#' . (int) $id . '</span>';
        };
        $details = static function (array $ids) use ($wpdb) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            $out = array();
            if (!$ids) return $out;
            foreach ((array) $wpdb->get_results('SELECT id, name, city, state, status FROM facilities_v2 WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r) {
                $out[(int) $r['id']] = $r;
            }
            return $out;
        };
        echo '<p class="kop-ph-lede">Separate programs that share one campus: two specialised residential programs of one hospital, a boys\' and a girls\' school on one ranch. They are not homes of one program (no record stands over the others), and they are closer than two programs of one company: each page names the others on its campus, and they leave its "Same operator" list.</p>';

        if ($which === 'campus_suggested') {
            $list = kop_campus_suggestions(isset($_GET['refresh']));
            if ($q !== '') {
                $aka = function_exists('kop_v2_alias_match_ids') ? kop_v2_alias_match_ids('facilities', $q) : array();
                $list = array_values(array_filter($list, static function ($s) use ($q, $aka) {
                    foreach ($s['names'] as $id => $n) if (stripos($n, $q) !== false || isset($aka[$id])) return true;
                    return stripos($s['company'], $q) !== false;
                }));
            }
            echo '<p class="kop-ph-count">' . count($list) . ' places where records with different names share one street address and one company. Untick any that is the same program under another name (that is a rename or a duplicate: Merge Duplicates), or a home of one program (Program Homes).</p>';
            foreach (array_slice($list, 0, 60) as $s) {
                $d = $details($s['ids']);
                echo '<div class="kop-ph-card"><form method="post" action="' . $post . '">';
                $hidden(array('do' => 'campus_tie'));
                $first = reset($d);
                $where = $first ? trim($first['city'] . ', ' . $first['state'], ', ') : '';
                echo '<h2>' . esc_html($where !== '' ? $where : 'One address') . ' <span class="kop-ph-meta">(' . count($s['ids']) . ' records)</span></h2>';
                echo '<p class="kop-ph-meta">Why: same street address' . ($s['company'] !== '' ? ', filed under ' . esc_html($s['company']) : '') . ($s['campus'] ? '. Some are on a campus already; ticked ones join it.' : '') . '</p><table><tbody>';
                foreach ($s['ids'] as $id) {
                    $r = $d[$id] ?? array('name' => $s['names'][$id] ?? '#' . $id, 'status' => '');
                    echo '<tr><td style="width:24px"><input type="checkbox" name="ids[]" value="' . (int) $id . '" checked aria-label="' . esc_attr('Include ' . $r['name']) . '"></td><td>' . $link($id, $r['name'])
                        . ($r['status'] !== '' ? ' <span class="kop-ph-meta">' . esc_html($r['status']) . '</span>' : '') . '</td></tr>';
                }
                echo '</tbody></table><div class="kop-ph-actions"><label>Campus name (optional) <input type="text" name="campus_name" size="36" placeholder="e.g. Provo Canyon campus"></label>'
                    . '<button type="submit" class="button button-primary">These share a campus</button></div></form>';
                echo '<form method="post" action="' . $post . '" style="margin-top:8px">';
                $hidden(array('do' => 'campus_dismiss', 'key' => $s['key']));
                echo '<button type="submit" class="button-link">Not one campus: stop suggesting this</button></form></div>';
            }
            $dis = kop_campus_dismissed();
            if ($dis) {
                echo '<h2>Not one campus (' . count($dis) . ')</h2>';
                foreach ($dis as $key => $when) {
                    echo '<form method="post" action="' . $post . '" style="margin:0 0 4px">';
                    $hidden(array('do' => 'campus_undismiss', 'key' => $key));
                    echo '<span class="kop-ph-meta">' . esc_html(strtoupper(substr(strrchr($key, '|'), 1)) . ' ' . substr($key, 0, strrpos($key, '|'))) . '</span> <button class="button-link">Suggest it again</button></form>';
                }
            }
            return;
        }

        // Tie by hand.
        echo '<div class="kop-ph-card"><form method="post" action="' . $post . '">';
        $hidden(array('do' => 'campus_tie'));
        echo '<h2>Tie two programs by hand</h2><p class="kop-ph-meta">If either is on a campus already, the other joins it.</p><div class="kop-ph-actions">';
        echo '<label>Program ' . (function_exists('kop_facility_finder_field') ? kop_facility_finder_field('a') : '<input type="number" name="a">') . '</label>';
        echo '<label>and ' . (function_exists('kop_facility_finder_field') ? kop_facility_finder_field('b') : '<input type="number" name="b">') . '</label>';
        echo '<label>Campus name (optional) <input type="text" name="campus_name" size="30"></label><button type="submit" class="button button-primary">They share a campus</button></div></form></div>';

        $map = kop_campus_map(true);
        $all = array();
        foreach ($map['campuses'] as $c) $all = array_merge($all, $c['ids']);
        $d = $details($all);
        $aka = ($q !== '' && function_exists('kop_v2_alias_match_ids')) ? kop_v2_alias_match_ids('facilities', $q) : array();
        if (!$map['campuses']) echo '<p>No campuses yet.</p>';
        foreach ($map['campuses'] as $cid => $c) {
            if ($q !== '') {
                $hay = $c['name'];
                foreach ($c['ids'] as $id) $hay .= ' ' . ($d[$id]['name'] ?? '');
                if (stripos($hay, $q) === false && !array_intersect_key($aka, array_flip($c['ids']))) continue;
            }
            $first = $d[$c['ids'][0]] ?? null;
            $where = $first ? trim($first['city'] . ', ' . $first['state'], ', ') : '';
            echo '<div class="kop-ph-card"><h2>' . esc_html($c['name'] !== '' ? $c['name'] : 'Campus') . ($where !== '' ? ' <span class="kop-ph-meta">' . esc_html($where) . '</span>' : '') . '</h2><table><tbody>';
            foreach ($c['ids'] as $id) {
                echo '<tr><td>' . $link($id, $d[$id]['name'] ?? '#' . $id) . (($d[$id]['status'] ?? '') !== '' ? ' <span class="kop-ph-meta">' . esc_html($d[$id]['status']) . '</span>' : '') . '</td><td style="width:1%"><form method="post" action="' . $post . '">';
                $hidden(array('do' => 'campus_remove', 'facility_id' => $id));
                echo '<button class="button-link">Take off</button></form></td></tr>';
            }
            echo '</tbody></table><form method="post" action="' . $post . '" class="kop-ph-actions">';
            $hidden(array('do' => 'campus_rename', 'campus_id' => $cid));
            echo '<label>Campus name <input type="text" name="campus_name" size="36" value="' . esc_attr($c['name']) . '"></label><button class="button">Save name</button></form></div>';
        }

        // Recent changes, each with Undo.
        $log = array_slice(kop_campus_log(), 0, 30);
        if ($log) {
            $names = $details(array_merge(...array_map(static function ($e) { return array_map('intval', (array) $e['ids']); }, $log)));
            echo '<h2>Recent changes</h2><table class="widefat striped" style="max-width:60rem"><tbody>';
            $verbs = array('tie' => 'Tied', 'remove' => 'Took off', 'rename' => 'Renamed the campus of');
            foreach ($log as $e) {
                $who = implode(', ', array_map(static function ($id) use ($names) { return $names[$id]['name'] ?? '#' . $id; }, (array) $e['ids']));
                echo '<tr><td>' . esc_html(($verbs[$e['op']] ?? $e['op']) . ' ' . $who) . '</td><td class="kop-ph-meta">' . esc_html(trim($e['at'] . ' ' . $e['by'])) . '</td><td style="width:1%">';
                if (!empty($e['undone'])) {
                    echo '<span class="kop-ph-meta">Undone</span>';
                } else {
                    echo '<form method="post" action="' . $post . '">';
                    $hidden(array('do' => 'campus_undo', 'log' => $e['id']));
                    echo '<button class="button-link">Undo</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }
}
