<?php
/**
 * Parent companies that are really one program of group homes.
 *
 * The migration made a company record (kop_operators) for some small
 * providers whose homes the state licenses one by one: "Dimondale Adolescent
 * Care Facility" is a company with five records all named "Dimondale
 * Adolescent Care Facility" (Carson, Gardena, ...), "NeuroRestorative" one with
 * fourteen "NeuroRestorative <X> House" records in Illinois. They are one
 * program, not a company running programs, so the /operator/ list and the
 * directory's parent company tab show a company that is only its own homes.
 *
 * Converting one (KOP Tools > Program Homes > Companies that are one program):
 *   - its homes are grouped under a program record (inc/program-homes.php):
 *     a new record named for the program, or the record already named just
 *     that in the state; a company with a single record keeps that record;
 *   - the company's written history (operator.history, draft or published)
 *     moves with it and prints on the program's /facility/ page, edited with
 *     the same pencil ("phistory:<company id>");
 *   - its other names join the program's other names, news filed under the
 *     company is filed under the program, a home whose "operator" field
 *     names the company is cleared;
 *   - the company record and its links go, and /operator/<its slug>/ 301s
 *     to the program's page.
 * Everything the conversion changed is kept in {prefix}kop_program_conversions
 * (the whole company row, its links, the groups and documents before), so
 * Undo puts the company back exactly; a document edited since is left as it
 * is now.
 *
 * Guarded with function_exists so scripts/test-program-homes-convert.php can
 * load it next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_PROGRAM_CONVERSIONS_DB_VERSION')) {
    define('KOP_PROGRAM_CONVERSIONS_DB_VERSION', '1');
}

if (!function_exists('kop_phc_table')) {
    function kop_phc_table() {
        global $wpdb;
        return $wpdb->prefix . 'kop_program_conversions';
    }
}

if (!function_exists('kop_phc_install')) {
    function kop_phc_install($force = false) {
        if (!$force && get_option('kop_program_conversions_db') === KOP_PROGRAM_CONVERSIONS_DB_VERSION) return;
        global $wpdb;
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_phc_table() . " (
            operator_id BIGINT UNSIGNED NOT NULL,
            program_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(200) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            operator_json LONGTEXT NOT NULL,
            undo_json LONGTEXT NOT NULL,
            converted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            converted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (operator_id),
            KEY program_id (program_id),
            KEY slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        update_option('kop_program_conversions_db', KOP_PROGRAM_CONVERSIONS_DB_VERSION, false);
    }
    add_action('admin_init', 'kop_phc_install');
}

if (!function_exists('kop_phc_ready')) {
    function kop_phc_ready() {
        static $ready = null;
        if ($ready === null) {
            $ready = function_exists('kop_facility_pages_table_exists') && kop_facility_pages_table_exists(kop_phc_table());
        }
        return $ready;
    }
}

if (!function_exists('kop_phc_rows')) {
    /** Every conversion: operator_id => row (operator_json decoded as 'op', undo_json as 'undo'). */
    function kop_phc_rows($refresh = false) {
        static $memo = null;
        if ($memo !== null && !$refresh) return $memo;
        $memo = array();
        if (!kop_phc_ready()) return $memo;
        global $wpdb;
        foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_phc_table() . ' ORDER BY converted_at DESC, operator_id', ARRAY_A) as $r) {
            $row = json_decode((string) $r['operator_json'], true);
            $r['row'] = is_array($row) ? $row : array();
            $json = json_decode((string) ($r['row']['json_data'] ?? ''), true);
            $r['op'] = is_array($json) && is_array($json['operator'] ?? null) ? $json['operator'] : array();
            $r['undo'] = json_decode((string) $r['undo_json'], true) ?: array();
            $memo[(int) $r['operator_id']] = $r;
        }
        return $memo;
    }
}

if (!function_exists('kop_phc_cache_key')) {
    /** Part of kop_facility_pages_fingerprint(): a conversion or a history edit changes a page. */
    function kop_phc_cache_key() {
        if (!kop_phc_ready()) return '-';
        global $wpdb;
        $row = $wpdb->get_row('SELECT COUNT(*), MAX(updated_at), SUM(program_id) FROM ' . kop_phc_table(), ARRAY_N);
        return is_array($row) ? implode('|', array_map('strval', $row)) : '?';
    }
}

// ---------------------------------------------------------------------------
// Which companies are one program
// ---------------------------------------------------------------------------

if (!function_exists('kop_phc_program_name')) {
    /** "New Hope Of Arizona, Inc" -> "New Hope Of Arizona"; "Universal Health Services (UHS)" -> "Universal Health Services". */
    function kop_phc_program_name($company) {
        $n = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', (string) $company));
        $n = trim(preg_replace('/,?\s+(inc|llc|ltd|corp|incorporated)\.?$/iu', '', $n));
        return $n !== '' ? $n : trim((string) $company);
    }
}

if (!function_exists('kop_phc_home_name')) {
    /**
     * What the program calls one home: what follows the company's name
     * ("NeuroRestorative Bears House" -> "Bears House"), else the house the
     * place field names ("Lamar House – Sunnyvale" -> "Lamar House"), else the town.
     */
    function kop_phc_home_name($facility_name, $company, $city) {
        $facility_name = trim((string) $facility_name);
        $base = kop_phc_program_name($company);
        if (kop_program_homes_key($facility_name) !== kop_program_homes_key($base)) {
            $rest = preg_replace('/^' . preg_quote($base, '/') . '(?:,?\s+(?:inc|llc)\.?)?/iu', '', $facility_name);
            $rest = trim((string) $rest, " \t,:-\u{2013}\u{2014}");
            if ($rest !== '' && $rest !== $facility_name) return $rest;
        }
        list($house) = kop_program_homes_split_name($city);
        return $house !== '' ? $house : trim((string) $city);
    }
}

if (!function_exists('kop_phc_suggest')) {
    /**
     * Companies whose every record carries the company's own name ("X", or
     * "X <home>") and stands in one state. $companies: [{id, name, op (the
     * decoded operator object), folder}]; $records: company id => [{id, name,
     * city, state, status, current_operator}]; $news: company id => count of
     * articles filed under it; $grouped: home id => [program id, home name]
     * (kop_program_homes_map()['homes']); $dismissed: key => time.
     *
     * Each: {key, operator_id, name, program_name, state, kind ('homes' or
     * 'single'), homes: [{id, name, home_name, city, status, in_program}],
     * existing: {id, name} or null, history: '', 'draft' or 'published',
     * news, warnings: [text]}. Most homes first.
     */
    function kop_phc_suggest(array $companies, array $records, array $news = array(), array $grouped = array(), array $dismissed = array()) {
        $out = array();
        foreach ($companies as $c) {
            $oid = (int) $c['id'];
            $key = 'company:' . $oid;
            if (isset($dismissed[$key])) continue;
            $recs = $records[$oid] ?? array();
            if (!$recs) continue;
            $base = kop_phc_program_name($c['name']);
            $ck = kop_program_homes_key($base);
            if (mb_strlen($ck) < 4) continue;
            $states = array();
            $own_name = true;
            foreach ($recs as $r) {
                $states[strtoupper(trim((string) $r['state']))] = true;
                $rk = kop_program_homes_key($r['name']);
                if ($rk !== $ck && strpos($rk, $ck . ' ') !== 0) { $own_name = false; break; }
            }
            if (!$own_name || count($states) !== 1 || isset($states[''])) continue;
            $state = (string) array_key_first($states);

            // A record named just the program, with no town of its own, is the program already.
            $existing = null;
            $homes = array();
            foreach ($recs as $r) {
                if (!$existing && count($recs) > 1 && kop_program_homes_key($r['name']) === $ck && trim((string) $r['city']) === '') {
                    $existing = array('id' => (int) $r['id'], 'name' => (string) $r['name']);
                    continue;
                }
                $homes[] = array(
                    'id'         => (int) $r['id'],
                    'name'       => (string) $r['name'],
                    'home_name'  => kop_phc_home_name($r['name'], $c['name'], $r['city']),
                    'city'       => trim((string) $r['city']),
                    'status'     => trim((string) $r['status']),
                    'in_program' => isset($grouped[(int) $r['id']]) ? (int) $grouped[(int) $r['id']][0] : 0,
                );
            }
            usort($homes, static function ($a, $b) { return strcasecmp($a['home_name'], $b['home_name']) ?: $a['id'] - $b['id']; });
            $kind = (count($homes) === 1 && !$existing) ? 'single' : 'homes';

            $op = (array) ($c['op'] ?? array());
            $warnings = array();
            foreach ($homes as $h) {
                if ($h['in_program'] && (!$existing || $h['in_program'] !== $existing['id'])) {
                    $warnings[] = $h['name'] . ' (#' . $h['id'] . ') is already a home of program #' . $h['in_program'] . '; converting moves it.';
                }
            }
            // A company based elsewhere may run programs the records do not hold yet.
            $state_name = function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($state) : $state;
            foreach (array('headquarters', 'location') as $f) {
                $where = trim((string) ($op[$f] ?? ''));
                if ($where === '') continue;
                $here = preg_match('/\b' . preg_quote($state, '/') . '\b/', $where) || ($state_name !== '' && stripos($where, $state_name) !== false);
                if (!$here) {
                    $warnings[] = 'The company record puts it in ' . $where . ', outside ' . ($state_name !== '' ? $state_name : $state) . ': it may be a company with programs the records do not hold yet.';
                    break;
                }
            }
            $left = array();
            foreach (array('websites' => 'websites', 'notes' => 'research notes', 'owners' => 'owners', 'investors' => 'investors', 'fieldNotes' => 'field notes') as $f => $label) {
                if (!empty(array_filter((array) ($op[$f] ?? array())))) $left[] = $label;
            }
            if (array_filter((array) ($op['keyStaff'] ?? array()))) $left[] = 'key staff';
            if (!empty($c['folder'])) $left[] = 'a document folder';
            if ($left) {
                $warnings[] = 'The company record also holds ' . implode(', ', $left) . '. They stay in the saved copy of the company (Undo brings them back); copy what the program needs by hand.';
            }
            $history = '';
            if (array_filter(array_map('trim', array_map('strval', (array) ($op['history'] ?? array()))))) {
                $history = strtolower(trim((string) ($op['historyStatus'] ?? ''))) === 'published' ? 'published' : 'draft';
            }
            $out[] = array(
                'key'          => $key,
                'operator_id'  => $oid,
                'name'         => (string) $c['name'],
                'program_name' => $existing ? $existing['name'] : ($kind === 'single' ? $homes[0]['name'] : $base),
                'state'        => $state,
                'kind'         => $kind,
                'homes'        => $homes,
                'existing'     => $existing,
                'history'      => $history,
                'news'         => (int) ($news[$oid] ?? 0),
                'warnings'     => $warnings,
            );
        }
        usort($out, static function ($a, $b) {
            return count($b['homes']) - count($a['homes']) ?: strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }
}

if (!function_exists('kop_phc_inputs')) {
    /** What kop_phc_suggest() reads, from the database. Duplicate company records (one name twice) are left out. */
    function kop_phc_inputs() {
        global $wpdb;
        $p = $wpdb->prefix;
        $companies = $records = $news = array();
        if (!kop_facility_pages_table_exists($p . 'kop_operators')) return array($companies, $records, $news);
        $names = array();
        foreach ((array) $wpdb->get_results("SELECT id, name, json_data, document_folder_id FROM `{$p}kop_operators` ORDER BY id", ARRAY_A) as $r) {
            $json = json_decode((string) $r['json_data'], true);
            $nk = kop_program_homes_key(kop_phc_program_name($r['name']));
            $names[$nk] = ($names[$nk] ?? 0) + 1;
            $companies[] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'folder' => (int) $r['document_folder_id'],
                'op' => is_array($json) && is_array($json['operator'] ?? null) ? $json['operator'] : array());
        }
        $companies = array_values(array_filter($companies, static function ($c) use ($names) {
            return ($names[kop_program_homes_key(kop_phc_program_name($c['name']))] ?? 0) === 1;
        }));
        foreach ((array) $wpdb->get_results("SELECT ofc.operator_id, f.id, f.name, f.city, f.state, f.status
                FROM `{$p}kop_operator_facilities` ofc JOIN facilities_v2 f ON f.id = ofc.facility_id ORDER BY f.id", ARRAY_A) as $r) {
            $records[(int) $r['operator_id']][] = $r;
        }
        if (kop_facility_pages_table_exists($p . 'kop_operator_links')) {
            foreach ((array) $wpdb->get_results("SELECT operator_id, COUNT(*) AS n FROM `{$p}kop_operator_links` WHERE link_kind = 'news' GROUP BY operator_id", ARRAY_A) as $r) {
                $news[(int) $r['operator_id']] = (int) $r['n'];
            }
        }
        return array($companies, $records, $news);
    }
}

if (!function_exists('kop_phc_suggestions')) {
    function kop_phc_suggestions() {
        list($companies, $records, $news) = kop_phc_inputs();
        $map = kop_program_homes_map(true);
        return kop_phc_suggest($companies, $records, $news, $map['homes'], kop_program_homes_dismissed());
    }
}

// ---------------------------------------------------------------------------
// Convert and Undo
// ---------------------------------------------------------------------------

if (!function_exists('kop_phc_save_doc')) {
    /** Write a changed document; returns the stored document after the save. */
    function kop_phc_save_doc(array $doc, array $opts) {
        kop_facility_save($doc, $opts + array('force' => true));
        $now = kop_facility_load((int) $doc['facility_id'], $opts);
        return $now ? $now['doc'] : $doc;
    }
}

if (!function_exists('kop_phc_convert')) {
    /**
     * Turn company $operator_id into a program. $program_name: the program
     * record's name (a new record only); $home_names: home id => what the
     * program calls it (missing ids keep the suggested name). Returns the
     * program's facility id. $opts: kop_facility_save()'s (pdo, prefix, ...).
     */
    function kop_phc_convert($operator_id, $program_name, array $home_names, array $opts) {
        global $wpdb;
        $p = $wpdb->prefix;
        $operator_id = (int) $operator_id;
        $s = null;
        foreach (kop_phc_suggest(...array_merge(kop_phc_inputs(), array(kop_program_homes_map(true)['homes']))) as $c) {
            if ($c['operator_id'] === $operator_id) { $s = $c; break; }
        }
        if (!$s) throw new RuntimeException('Company #' . $operator_id . ' is not one program any more (its records have changed); nothing done.');
        if (kop_phc_ready() && $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_phc_table() . ' WHERE operator_id = %d', $operator_id))) {
            throw new RuntimeException('Company #' . $operator_id . ' has been converted already.');
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$p}kop_operators` WHERE id = %d", $operator_id), ARRAY_A);
        if (!$row) throw new RuntimeException('That company record is gone.');
        $op_json = json_decode((string) $row['json_data'], true);
        $op = is_array($op_json) && is_array($op_json['operator'] ?? null) ? $op_json['operator'] : array();
        $slug = '';
        if (function_exists('kop_operator_pages_index')) {
            $index = kop_operator_pages_index(true);
            $slug = (string) ($index['ids'][$operator_id]['slug'] ?? '');
        }

        // What Undo needs, read before anything changes.
        $undo = array(
            'facilities' => (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$p}kop_operator_facilities` WHERE operator_id = %d", $operator_id), ARRAY_A),
            'links'      => kop_facility_pages_table_exists($p . 'kop_operator_links')
                ? (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$p}kop_operator_links` WHERE operator_id = %d", $operator_id), ARRAY_A) : array(),
            'kind'       => $s['kind'],
            'homes'      => array(),
            'homes_before' => array(),
            'group_before' => null,
            'program_created' => false,
            'news_added' => array(),
            'docs'       => array(),
        );
        $ht = kop_program_homes_table('homes');
        $gt = kop_program_homes_table('groups');
        $home_ids = array_map(static function ($h) { return (int) $h['id']; }, $s['homes']);
        if ($home_ids) {
            $undo['homes_before'] = (array) $wpdb->get_results("SELECT home_id, program_id, home_name FROM {$ht} WHERE home_id IN (" . implode(',', $home_ids) . ')', ARRAY_A);
        }

        // The program record and its homes.
        if ($s['kind'] === 'single') {
            $program_id = (int) $s['homes'][0]['id'];
        } else {
            $homes = array();
            foreach ($s['homes'] as $h) {
                $n = trim((string) ($home_names[$h['id']] ?? ''));
                $homes[(int) $h['id']] = $n !== '' ? $n : $h['home_name'];
            }
            $existing = $s['existing'] ? (int) $s['existing']['id'] : 0;
            $name = trim((string) $program_name) !== '' ? trim((string) $program_name) : $s['program_name'];
            if ($existing) {
                $g = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$gt} WHERE program_id = %d", $existing), ARRAY_A);
                $undo['group_before'] = $g ?: null;
                $undo['homes_before'] = array_merge($undo['homes_before'], (array) $wpdb->get_results($wpdb->prepare("SELECT home_id, program_id, home_name FROM {$ht} WHERE program_id = %d", $existing), ARRAY_A));
            }
            $groups_before = array_map('intval', (array) $wpdb->get_col("SELECT program_id FROM {$gt}"));
            $program_id = (int) kop_program_homes_group($homes, $existing, $name, $opts);
            if (!$existing && in_array($program_id, $groups_before, true)) {
                // The record the program name found was already a program.
                $undo['group_before'] = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$gt} WHERE program_id = %d", $program_id), ARRAY_A) ?: null;
            }
            $created = $wpdb->get_var($wpdb->prepare("SELECT created_record FROM {$gt} WHERE program_id = %d", $program_id));
            $undo['program_created'] = !$existing && !in_array($program_id, $groups_before, true) && (int) $created === 1;
            $undo['homes'] = array_keys($homes);
        }

        // The program's document: the company's other names (and its own name when the program reads differently).
        $docs = array();
        $prog = kop_facility_load($program_id, $opts);
        if ($prog) {
            $doc = $prog['doc'];
            $names = (array) ($doc['identification']['otherNames'] ?? array());
            $have = array(kop_program_homes_key($doc['identification']['name'] ?? '') => true);
            foreach (array_merge($names, (array) ($doc['identification']['pastNames'] ?? array())) as $n) {
                $have[kop_program_homes_key(is_array($n) ? ($n['name'] ?? '') : $n)] = true;
            }
            $add = array_merge(array($row['name'], kop_phc_program_name($row['name'])), (array) ($op['otherNames'] ?? array()));
            if (!empty($op['currentName'])) $add[] = $op['currentName'];
            foreach ($add as $n) {
                $n = trim((string) (is_array($n) ? ($n['name'] ?? '') : $n));
                $k = kop_program_homes_key($n);
                if ($n === '' || $k === '' || isset($have[$k])) continue;
                $have[$k] = true;
                $names[] = $n;
            }
            if ($names !== (array) ($doc['identification']['otherNames'] ?? array())) {
                $doc['identification']['otherNames'] = $names;
                $docs[$program_id] = $prog['doc'];
            }
            if (isset($docs[$program_id])) $undo['docs'][$program_id] = array('before' => $docs[$program_id], 'after' => kop_phc_save_doc($doc, $opts));
        }
        // A home (or the program) whose operator field names this company: the company is going.
        $ck = kop_program_homes_key(kop_phc_program_name($row['name']));
        foreach (array_unique(array_merge($home_ids, array($program_id))) as $fid) {
            $rec = kop_facility_load((int) $fid, $opts);
            if (!$rec) continue;
            $cur = trim((string) ($rec['doc']['identification']['currentOperator'] ?? ''));
            if ($cur === '' || kop_program_homes_key(kop_phc_program_name($cur)) !== $ck) continue;
            $doc = $rec['doc'];
            $doc['identification']['currentOperator'] = '';
            $before = isset($undo['docs'][$fid]) ? $undo['docs'][$fid]['before'] : $rec['doc'];
            $undo['docs'][$fid] = array('before' => $before, 'after' => kop_phc_save_doc($doc, $opts));
        }

        // News filed under the company is filed under the program.
        if (kop_facility_pages_table_exists('news_facility_links')) {
            foreach ($undo['links'] as $l) {
                if ($l['link_kind'] !== 'news') continue;
                if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM news_facility_links WHERE news_id = %d AND facility_id = %d', (int) $l['link_id'], $program_id))) continue;
                $wpdb->insert('news_facility_links', array('news_id' => (int) $l['link_id'], 'facility_id' => $program_id, 'link_type' => 'mentioned',
                    'created_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 'program-conversion'));
                $undo['news_added'][] = (int) $l['link_id'];
            }
        }

        // The company goes.
        $wpdb->query($wpdb->prepare("DELETE FROM `{$p}kop_operator_facilities` WHERE operator_id = %d", $operator_id));
        if (kop_facility_pages_table_exists($p . 'kop_operator_links')) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$p}kop_operator_links` WHERE operator_id = %d", $operator_id));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM `{$p}kop_operators` WHERE id = %d", $operator_id));
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(kop_phc_table(), array(
            'operator_id'   => $operator_id,
            'program_id'    => $program_id,
            'slug'          => $slug,
            'name'          => mb_substr((string) $row['name'], 0, 250),
            'operator_json' => wp_json_encode($row),
            'undo_json'     => wp_json_encode($undo),
            'converted_at'  => $now,
            'converted_by'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'updated_at'    => $now,
        ));
        kop_phc_after_change();
        return $program_id;
    }
}

if (!function_exists('kop_phc_undo')) {
    /**
     * Put company $operator_id back as it was before its conversion, with any
     * history edits made on the program page since. Returns a sentence for the screen.
     */
    function kop_phc_undo($operator_id, array $opts) {
        global $wpdb;
        $p = $wpdb->prefix;
        $operator_id = (int) $operator_id;
        $c = kop_phc_rows(true)[$operator_id] ?? null;
        if (!$c) throw new RuntimeException('That conversion is not on record.');
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$p}kop_operators` WHERE id = %d", $operator_id))) {
            throw new RuntimeException('A company record #' . $operator_id . ' exists again; nothing done.');
        }
        $undo = $c['undo'];
        $program_id = (int) $c['program_id'];
        $notes = array();

        // The company, with the history as it reads now (a pencil edit rewrites the saved row's json_data).
        $row = $c['row'];
        $row['id'] = $operator_id;
        $wpdb->insert($p . 'kop_operators', $row);
        foreach ((array) ($undo['facilities'] ?? array()) as $f) {
            if ((int) $f['facility_id'] === $program_id && !empty($undo['program_created'])) continue;
            if (!$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = %d', (int) $f['facility_id']))) continue;
            $wpdb->query($wpdb->prepare("DELETE FROM `{$p}kop_operator_facilities` WHERE operator_id = %d AND facility_id = %d", $operator_id, (int) $f['facility_id']));
            $wpdb->insert($p . 'kop_operator_facilities', $f);
        }
        foreach ((array) ($undo['links'] ?? array()) as $l) $wpdb->insert($p . 'kop_operator_links', $l);
        foreach ((array) ($undo['news_added'] ?? array()) as $nid) {
            $wpdb->query($wpdb->prepare("DELETE FROM news_facility_links WHERE news_id = %d AND facility_id = %d AND created_by = 'program-conversion'", (int) $nid, $program_id));
        }

        // Documents the conversion changed, unless edited since.
        foreach ((array) ($undo['docs'] ?? array()) as $fid => $d) {
            $now = kop_facility_load((int) $fid, $opts);
            if (!$now) continue;
            if (kop_facility_same_document($d['after'], $now['doc'])) {
                kop_facility_save($d['before'], $opts + array('force' => true));
            } else {
                $notes[] = 'record #' . (int) $fid . ' has been edited since, so it stays as it is now';
            }
        }

        // The homes, as they were grouped before.
        if (($undo['kind'] ?? '') === 'homes') {
            $ht = kop_program_homes_table('homes');
            $gt = kop_program_homes_table('groups');
            if (!empty($undo['program_created'])) {
                if (kop_program_homes_undo($program_id) === 'kept') $notes[] = 'the program record #' . $program_id . ' has news, lawsuits, inspection links or edits of its own, so it stays (without its homes)';
            } else {
                foreach ((array) ($undo['homes'] ?? array()) as $hid) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$ht} WHERE home_id = %d AND program_id = %d", (int) $hid, $program_id));
                }
                if (empty($undo['group_before'])) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$gt} WHERE program_id = %d", $program_id));
                }
            }
            foreach ((array) ($undo['homes_before'] ?? array()) as $h) {
                if (!$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = %d', (int) $h['program_id']))) continue;
                $wpdb->query($wpdb->prepare("DELETE FROM {$ht} WHERE home_id = %d", (int) $h['home_id']));
                $wpdb->insert($ht, array('home_id' => (int) $h['home_id'], 'program_id' => (int) $h['program_id'], 'home_name' => (string) $h['home_name']));
            }
            if (!empty($undo['group_before']) && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$gt} WHERE program_id = %d", (int) $undo['group_before']['program_id']))) {
                $wpdb->insert($gt, $undo['group_before']);
            }
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_phc_table() . ' WHERE operator_id = %d', $operator_id));
        kop_phc_after_change();
        return 'Undone: ' . $c['name'] . ' is a company again' . ($notes ? '; ' . implode('; ', $notes) : '') . '.';
    }
}

if (!function_exists('kop_phc_after_change')) {
    function kop_phc_after_change() {
        kop_phc_rows(true);
        kop_program_homes_after_change();
        if (function_exists('kop_operator_pages_index')) kop_operator_pages_index(true);
    }
}

// ---------------------------------------------------------------------------
// The program page and the old company address
// ---------------------------------------------------------------------------

if (!function_exists('kop_phc_for_program')) {
    /** The conversion whose program is $facility_id (the newest), or null. */
    function kop_phc_for_program($facility_id) {
        foreach (kop_phc_rows() as $c) {
            if ((int) $c['program_id'] === (int) $facility_id) return $c;
        }
        return null;
    }
}

if (!function_exists('kop_phc_program_history')) {
    /**
     * {operator_id, company, history (kop_operator_history_written()'s shape
     * or null)} for a program made from a company, else null.
     */
    function kop_phc_program_history($facility_id) {
        $c = kop_phc_for_program($facility_id);
        if (!$c || !function_exists('kop_operator_history_written')) return null;
        return array('operator_id' => (int) $c['operator_id'], 'company' => (string) $c['name'], 'history' => kop_operator_history_written($c['op']));
    }
}

if (!function_exists('kop_phc_redirect_url')) {
    /** Where /operator/<slug>/ of a converted company goes now ('' when it was not converted). */
    function kop_phc_redirect_url($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || !kop_phc_ready()) return '';
        foreach (kop_phc_rows() as $oid => $c) {
            if ($c['slug'] === $slug || (ctype_digit($slug) && (int) $slug === (int) $oid)) {
                return function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $c['program_id']) : '';
            }
        }
        return '';
    }
}

// A program made from a company always gets a page: the company's address sends readers there.
if (!function_exists('kop_phc_page_signals')) {
    function kop_phc_page_signals($signals, $doc = array(), $id = 0) {
        if ($id && kop_phc_for_program((int) $id)) $signals[] = 'convertedCompany';
        return $signals;
    }
    if (function_exists('add_filter')) add_filter('kop_facility_page_signals', 'kop_phc_page_signals', 10, 3);
}

// The history keeps its pencil on the program page ("phistory:<company id>").
if (function_exists('add_filter')) {
    add_filter('kop_inline_edit_sources', static function ($sources) {
        $sources['phistory'] = array('load' => 'kop_phc_ie_load', 'save' => 'kop_phc_ie_save');
        return $sources;
    });
}

if (!function_exists('kop_phc_ie_fields')) {
    function kop_phc_ie_fields(array $op) {
        return array_values(array_filter(kop_ie_operator_form($op), static function ($f) {
            return $f['section'] === 'History';
        }));
    }
}

if (!function_exists('kop_phc_ie_load')) {
    function kop_phc_ie_load(array $parts) {
        $c = kop_phc_rows(true)[(int) ($parts[0] ?? 0)] ?? null;
        if (!$c) throw new RuntimeException('That history is gone (the conversion was undone?).');
        return array(
            'title'  => kop_phc_program_name($c['name']) . ': history',
            'help'   => 'A draft is seen by admins only. Check every sentence against its sources before setting it to Published.',
            'fields' => kop_phc_ie_fields($c['op']),
        );
    }
}

if (!function_exists('kop_phc_ie_save')) {
    function kop_phc_ie_save(array $parts, array $values) {
        global $wpdb;
        $oid = (int) ($parts[0] ?? 0);
        $c = kop_phc_rows(true)[$oid] ?? null;
        if (!$c) throw new RuntimeException('That history is gone (the conversion was undone?).');
        $op = $c['op'];
        foreach (kop_phc_ie_fields($op) as $field) {
            if (array_key_exists($field['name'], $values)) kop_ie_facility_apply($op, $field, $values[$field['name']]);
        }
        $row = $c['row'];
        $json = json_decode((string) ($row['json_data'] ?? ''), true);
        if (!is_array($json)) $json = array();
        $json['operator'] = $op;
        $row['json_data'] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $wpdb->update(kop_phc_table(), array('operator_json' => wp_json_encode($row), 'updated_at' => gmdate('Y-m-d H:i:s')), array('operator_id' => $oid));
        kop_phc_after_change();
        if (function_exists('kop_ie_purge_url') && function_exists('kop_facility_page_url')) kop_ie_purge_url((string) kop_facility_page_url((int) $c['program_id']));
        return array('message' => 'Saved.');
    }
}

// ---------------------------------------------------------------------------
// KOP Tools > Program Homes: the two tabs
// ---------------------------------------------------------------------------

if (!function_exists('kop_phc_handle')) {
    /** The screen's 'convert' and 'convert_undo' actions; returns the message. */
    function kop_phc_handle($do) {
        kop_phc_install();
        if ($do === 'convert') {
            $names = array();
            foreach ((array) ($_POST['home_name'] ?? array()) as $id => $n) $names[(int) $id] = sanitize_text_field(wp_unslash($n));
            $oid = (int) ($_POST['operator_id'] ?? 0);
            $name = sanitize_text_field(wp_unslash($_POST['program_name'] ?? ''));
            $pid = kop_phc_convert($oid, $name, $names, kop_program_homes_opts());
            $c = kop_phc_rows(true)[$oid] ?? array('name' => '#' . $oid);
            return $c['name'] . ' is now the program record #' . $pid . '; its company page sends readers there.';
        }
        if ($do === 'convert_undo') {
            return kop_phc_undo((int) ($_POST['operator_id'] ?? 0), kop_program_homes_opts());
        }
        return '';
    }
}

if (!function_exists('kop_phc_page_suggested')) {
    function kop_phc_page_suggested(array $list, $q, callable $hidden) {
        if ($q !== '') {
            $list = array_values(array_filter($list, static function ($s) use ($q) {
                if (stripos($s['name'], $q) !== false) return true;
                foreach ($s['homes'] as $h) {
                    if (stripos($h['name'] . ' ' . $h['home_name'] . ' ' . $h['city'], $q) !== false) return true;
                }
                return false;
            }));
        }
        echo '<p class="kop-ph-count">' . count($list) . ' companies whose every record carries the company\'s own name in one state: a program of licensed homes rather than a company running programs. Converting one groups its homes under a program record, moves its written history to that program\'s page, and sends its company page there. Undo puts the company back.</p>';
        foreach ($list as $s) kop_phc_page_card($s, $hidden);
    }
}

if (!function_exists('kop_phc_page_card')) {
    function kop_phc_page_card(array $s, callable $hidden) {
        $state_name = function_exists('kop_state_canonical_name') ? kop_state_canonical_name($s['state']) : $s['state'];
        $op_url = function_exists('kop_operator_page_url') ? kop_operator_page_url($s['operator_id']) : '';
        $fid = 'phc-' . (int) $s['operator_id'];
        $single = $s['kind'] === 'single';
        ?>
        <div class="kop-ph-card">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php $hidden(array('do' => 'convert', 'operator_id' => $s['operator_id'])); ?>
                <h2><?php echo $op_url !== '' ? '<a href="' . esc_url($op_url) . '" target="_blank" rel="noopener">' . esc_html($s['name']) . '</a>' : esc_html($s['name']); ?>, <?php echo esc_html($state_name); ?>
                    <span class="kop-ph-meta">(company #<?php echo (int) $s['operator_id']; ?>, <?php echo $single ? 'one record' : count($s['homes']) . ' homes'; ?>)</span></h2>
                <p class="kop-ph-meta"><?php
                    $bits = array();
                    $bits[] = $s['history'] === '' ? 'No written history' : 'Written history (' . $s['history'] . ') moves to the program page';
                    if ($s['news']) $bits[] = $s['news'] . ' news ' . ($s['news'] === 1 ? 'article moves' : 'articles move') . ' to the program';
                    echo esc_html(implode('. ', $bits) . '.');
                ?></p>
                <?php foreach ($s['warnings'] as $w) : ?><p class="kop-ph-warn"><?php echo esc_html($w); ?></p><?php endforeach; ?>
                <table>
                    <tbody>
                    <?php foreach ($s['homes'] as $h) :
                        $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($h['id']) : '';
                        ?>
                        <tr>
                            <?php if (!$single) : ?>
                            <td style="width:40%"><input type="text" name="home_name[<?php echo (int) $h['id']; ?>]" value="<?php echo esc_attr($h['home_name']); ?>" aria-label="<?php echo esc_attr('What the program calls ' . $h['name'] . ' in ' . $h['city']); ?>"></td>
                            <?php endif; ?>
                            <td><?php echo $url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($h['name']) . '</a>' : esc_html($h['name']); ?>
                                <span class="kop-ph-meta"><?php echo esc_html(trim('#' . $h['id'] . ' ' . $h['city'] . ($h['status'] !== '' ? ' | ' . $h['status'] : ''))); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="kop-ph-actions">
                    <?php if ($single) : ?>
                        <span>The company page goes; this record is the program.</span>
                    <?php elseif ($s['existing']) : ?>
                        <span>Program record: the existing #<?php echo (int) $s['existing']['id']; ?> <?php echo esc_html($s['existing']['name']); ?></span>
                    <?php else : ?>
                        <label for="<?php echo esc_attr($fid); ?>">New program record named</label>
                        <input type="text" id="<?php echo esc_attr($fid); ?>" name="program_name" value="<?php echo esc_attr($s['program_name']); ?>" size="40">
                    <?php endif; ?>
                    <button type="submit" class="button button-primary"><?php echo $single ? 'Fold the company into this record' : 'Make it one program'; ?></button>
                </div>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <?php $hidden(array('do' => 'dismiss', 'key' => $s['key'])); ?>
                <button type="submit" class="button-link">It is a real company: stop suggesting this</button>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('kop_phc_page_converted')) {
    function kop_phc_page_converted($q, callable $hidden) {
        $rows = kop_phc_rows(true);
        if (!$rows) {
            echo '<p>No companies converted yet.</p>';
            return;
        }
        foreach ($rows as $oid => $c) {
            if ($q !== '' && stripos($c['name'], $q) === false) continue;
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $c['program_id']) : '';
            $homes = function_exists('kop_program_homes_homes_of') ? count(kop_program_homes_homes_of((int) $c['program_id'])) : 0;
            echo '<div class="kop-ph-card"><h2>' . esc_html($c['name']) . ' <span class="kop-ph-meta">(company #' . (int) $oid . ', converted ' . esc_html(substr((string) $c['converted_at'], 0, 10)) . ')</span></h2>'
                . '<p>Now the program record ' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">#' . (int) $c['program_id'] . '</a>' : '#' . (int) $c['program_id'])
                . ($homes ? ', with ' . $homes . ' homes' : '') . ($c['slug'] !== '' ? '. /operator/' . esc_html($c['slug']) . '/ sends readers there.' : '.') . '</p>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $hidden(array('do' => 'convert_undo', 'operator_id' => $oid));
            echo '<button class="button">Undo: make it a company again</button></form></div>';
        }
    }
}
