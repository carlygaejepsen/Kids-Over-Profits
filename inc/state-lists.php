<?php
/**
 * State Lists: facilities that state-published lists name but our records do not.
 *
 * Some states publish no inspection reports, only lists of who is licensed or
 * registered (Missouri's license-exempt registry, Kentucky, Alaska, Louisiana,
 * Indiana, the Kansas and Mississippi PRTF lists). scripts/state-lists-check.py
 * reads them, matches every row to facilities_v2 with the facility pages' name
 * key, and with --export writes the rows it could not match (none, or more than
 * one record) with the records each may be ("candidates", each with why), plus
 * the rows gone from a list since its previous snapshot. That file is copied to
 * ~/kop-import/state-lists/state-lists.json on the server; this screen reads it
 * whenever it changes (or on "Import now").
 *
 * KOP Tools > State Lists, one card per listed facility, filtered by state:
 *   - Link to this record: one click per candidate, or any record through the
 *     facility finder. The listed name goes on the record as an other name only
 *     when the box is ticked (a record's other names that match a network-map
 *     node become rename lines on the map, so never silently).
 *   - Create record: a new facilities_v2 record from the row, made by
 *     kop_facdisc_create() (the same creator Facilities from News uses), its
 *     notes and "Materials and links" citing the list.
 *   - Not a TTI facility / Later.
 *   - Left the list: rows gone since the previous snapshot, naming the record
 *     they matched; information only (Dismiss), never a status change.
 * Every decision has an exact Undo on the Done tab: a link puts back the
 * record as it was (only the added name comes off if someone edited the record
 * since), a created record is deleted only while nobody has edited or linked it.
 *
 * Rows live in {prefix}kop_state_list_rows (records DB, kop_seed_pdo()), keyed
 * by the export's stable key, so a new import updates the listing and keeps
 * every decision. Young adult programs and Indigenous schools are never
 * facilities: a listed name that is one of them says so and offers no Create.
 *
 * Tested by scripts/test-state-lists.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_STATE_LISTS_DB_VERSION = '1';
const KOP_STATE_LISTS_FORMAT = 'kop-state-lists/1';

function kop_sl_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_state_list_rows';
}

function kop_sl_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('The records database is not reachable.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function kop_sl_is_sqlite(PDO $pdo) {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function kop_sl_ensure_table(PDO $pdo) {
    $t = kop_sl_table();
    if (get_option('kop_state_lists_db') === KOP_STATE_LISTS_DB_VERSION && !kop_sl_is_sqlite($pdo)) {
        return;
    }
    if (kop_sl_is_sqlite($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT, row_key TEXT NOT NULL UNIQUE, list_state TEXT NOT NULL, state TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL, name_key TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', county TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '', capacity INTEGER NULL, license_id TEXT NOT NULL DEFAULT '',
            source_url TEXT NOT NULL DEFAULT '', list_date TEXT NOT NULL DEFAULT '', match_status TEXT NOT NULL DEFAULT 'none',
            candidates TEXT NULL, excluded TEXT NULL, on_list INTEGER NOT NULL DEFAULT 1, left_date TEXT NOT NULL DEFAULT '',
            left_record_id INTEGER NULL, left_seen INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'open',
            facility_id INTEGER NULL, undo TEXT NULL, decided_by TEXT NULL, decided_at TEXT NULL,
            imported_at TEXT NOT NULL, created_at TEXT NOT NULL)");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t}` (
        id INT NOT NULL AUTO_INCREMENT,
        row_key VARCHAR(191) NOT NULL,
        list_state CHAR(2) NOT NULL,
        state CHAR(2) NOT NULL DEFAULT '',
        name VARCHAR(255) NOT NULL,
        name_key VARCHAR(255) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        county VARCHAR(120) NOT NULL DEFAULT '',
        address VARCHAR(255) NOT NULL DEFAULT '',
        type VARCHAR(160) NOT NULL DEFAULT '',
        capacity INT NULL,
        license_id VARCHAR(40) NOT NULL DEFAULT '',
        source_url TEXT NOT NULL,
        list_date VARCHAR(40) NOT NULL DEFAULT '',
        match_status VARCHAR(12) NOT NULL DEFAULT 'none',
        candidates MEDIUMTEXT NULL,
        excluded TEXT NULL,
        on_list TINYINT NOT NULL DEFAULT 1,
        left_date VARCHAR(40) NOT NULL DEFAULT '',
        left_record_id INT NULL,
        left_seen TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        facility_id INT NULL,
        undo MEDIUMTEXT NULL,
        decided_by VARCHAR(60) NULL,
        decided_at DATETIME NULL,
        imported_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY row_key (row_key),
        KEY list_status (list_state, status),
        KEY facility (facility_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_state_lists_db', KOP_STATE_LISTS_DB_VERSION);
}

/** The decisions, as the screen names them. */
function kop_sl_statuses() {
    return array(
        'open'    => 'To review',
        'later'   => 'Later',
        'linked'  => 'Linked to a record',
        'created' => 'Record created',
        'not_tti' => 'Not a TTI facility',
    );
}

/* ---- Import ---------------------------------------------------------------- */

function kop_sl_import_path() {
    $dir = defined('KOP_STATE_LISTS_IMPORT_DIR') ? rtrim(KOP_STATE_LISTS_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/state-lists';
    return $dir . '/state-lists.json';
}

/** The export, read and checked, or null when there is no file. */
function kop_sl_read_file($path = null) {
    $path = $path ?: kop_sl_import_path();
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data['format'] ?? '') !== KOP_STATE_LISTS_FORMAT) {
        throw new RuntimeException('The file is not a state-lists export (' . KOP_STATE_LISTS_FORMAT . '): ' . $path);
    }
    return $data;
}

/**
 * Put an export into the table. A key already there keeps its decision; its
 * listing (name, town, candidates, date) is refreshed. Rows in 'gone' are
 * marked as having left the list (the record they matched is kept), a row
 * back on a list is marked listed again. Returns counts.
 */
function kop_sl_import(PDO $pdo, array $data) {
    kop_sl_ensure_table($pdo);
    $t = kop_sl_table();
    $now = gmdate('Y-m-d H:i:s');
    $find = $pdo->prepare("SELECT id FROM `{$t}` WHERE row_key = ?");
    $ins = $pdo->prepare("INSERT INTO `{$t}` (row_key, list_state, state, name, name_key, city, county, address, type, capacity, license_id,
        source_url, list_date, match_status, candidates, excluded, on_list, left_date, left_record_id, left_seen, status, imported_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'open', ?, ?)");
    $upd = $pdo->prepare("UPDATE `{$t}` SET list_state = ?, state = ?, name = ?, name_key = ?, city = ?, county = ?, address = ?, type = ?,
        capacity = ?, license_id = ?, source_url = ?, list_date = ?, match_status = ?, candidates = ?, excluded = ?, on_list = ?,
        left_date = ?, left_record_id = ?, imported_at = ? WHERE id = ?");
    $counts = array('added' => 0, 'updated' => 0, 'left' => 0);
    foreach (array('rows' => 1, 'gone' => 0) as $part => $on_list) {
        foreach ((array) ($data[$part] ?? array()) as $r) {
            $key = mb_substr(trim((string) ($r['key'] ?? '')), 0, 191);
            $name = trim((string) ($r['name'] ?? ''));
            $list = strtoupper(substr((string) ($r['list'] ?? ''), 0, 2));
            if ($key === '' || $name === '' || !preg_match('/^[A-Z]{2}$/', $list)) {
                continue;
            }
            $cands = array();
            foreach ((array) ($r['candidates'] ?? array()) as $c) {
                if ((int) ($c['id'] ?? 0) > 0) {
                    $cands[] = array('id' => (int) $c['id'], 'why' => mb_substr((string) ($c['why'] ?? ''), 0, 200));
                }
            }
            $vals = array(
                $list, strtoupper(substr((string) ($r['state'] ?? $list), 0, 2)), mb_substr($name, 0, 255), mb_substr((string) ($r['name_key'] ?? ''), 0, 255),
                mb_substr((string) ($r['city'] ?? ''), 0, 120), mb_substr((string) ($r['county'] ?? ''), 0, 120), mb_substr((string) ($r['address'] ?? ''), 0, 255),
                mb_substr((string) ($r['type'] ?? ''), 0, 160), isset($r['capacity']) && is_numeric($r['capacity']) ? (int) $r['capacity'] : null,
                mb_substr((string) ($r['license_id'] ?? ''), 0, 40), (string) ($r['source_url'] ?? ''), mb_substr((string) ($r['list_date'] ?? ''), 0, 40),
                $on_list ? (($r['status'] ?? '') === 'ambiguous' ? 'ambiguous' : 'none') : 'gone',
                wp_json_encode($cands), !empty($r['excluded']) ? wp_json_encode($r['excluded']) : null, $on_list,
                $on_list ? '' : mb_substr((string) ($r['left_by'] ?? ''), 0, 40),
                !$on_list && !empty($r['record']['id']) ? (int) $r['record']['id'] : null,
            );
            $find->execute(array($key));
            $id = (int) $find->fetchColumn();
            $find->closeCursor();
            if ($id) {
                if (!$on_list) {
                    // A row that left keeps the candidates it had while listed.
                    $old = $pdo->prepare("SELECT candidates, match_status FROM `{$t}` WHERE id = ?");
                    $old->execute(array($id));
                    $was = $old->fetch(PDO::FETCH_ASSOC);
                    $vals[12] = $was['match_status'];
                    $vals[13] = $was['candidates'];
                }
                $upd->execute(array_merge($vals, array($now, $id)));
                $counts['updated']++;
            } else {
                $ins->execute(array_merge(array($key), $vals, array($now, $now)));
                $counts['added']++;
            }
            if (!$on_list) {
                $counts['left']++;
            }
        }
    }
    $lists = array();
    foreach ((array) ($data['lists'] ?? array()) as $st => $l) {
        $lists[strtoupper(substr((string) $st, 0, 2))] = array(
            'label' => (string) ($l['label'] ?? $st), 'covers' => (string) ($l['covers'] ?? ''), 'urls' => array_values(array_map('strval', (array) ($l['urls'] ?? array()))),
            'list_date' => (string) ($l['list_date'] ?? ''), 'fetched_on' => (string) ($l['fetched_on'] ?? ''), 'rows' => (int) ($l['rows'] ?? 0),
            'counts' => (array) ($l['counts'] ?? array()),
        );
    }
    update_option('kop_state_lists_meta', array('lists' => $lists, 'generated' => (string) ($data['generated'] ?? ''), 'imported_at' => $now), false);
    return $counts;
}

/** Import the server file when it changed since the last import. Returns counts, or null when nothing was read. */
function kop_sl_import_if_changed(PDO $pdo, $force = false) {
    $path = kop_sl_import_path();
    if (!is_readable($path)) {
        return null;
    }
    $hash = md5_file($path);
    if (!$force && get_option('kop_state_lists_file_hash') === $hash) {
        return null;
    }
    $counts = kop_sl_import($pdo, kop_sl_read_file($path));
    update_option('kop_state_lists_file_hash', $hash, false);
    return $counts;
}

/* ---- Reading rows ---------------------------------------------------------- */

function kop_sl_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM `' . kop_sl_table() . '` WHERE id = ?');
    $st->execute(array((int) $id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** {id: {id, name, city, state, status}} for the ids given that still exist. */
function kop_sl_records(PDO $pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = array();
    foreach (array_chunk($ids, 400) as $chunk) {
        $st = $pdo->query('SELECT id, name, unique_name, city, state, status FROM facilities_v2 WHERE id IN (' . implode(',', $chunk) . ')');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[(int) $f['id']] = array('id' => (int) $f['id'], 'name' => (string) ($f['name'] ?: $f['unique_name']),
                'city' => (string) $f['city'], 'state' => (string) $f['state'], 'status' => (string) ($f['status'] ?: 'Unknown'),
                'url' => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $f['id']) : '');
        }
    }
    return $out;
}

/** "Christian Acres Youth Center" for an all-capitals listed name; other names as listed. */
function kop_sl_suggested_name($name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if (preg_match('/[a-z]/', $name)) {
        return $name;
    }
    $small = array('of', 'the', 'and', 'for', 'at', 'in', 'on', 'a', 'an', 'to');
    $words = explode(' ', mb_strtolower($name));
    foreach ($words as $i => $w) {
        if (preg_match('/^(i{1,3}|iv|vi{0,3}|ix|x)$/', $w)) {
            $words[$i] = strtoupper($w);
        } elseif ($i > 0 && in_array($w, $small, true)) {
            $words[$i] = $w;
        } else {
            $words[$i] = preg_replace_callback('/(^|[\-\/(])([a-z])/', function ($m) { return $m[1] . strtoupper($m[2]); }, $w);
        }
    }
    return implode(' ', $words);
}

/** The data form's type for a listed type, or ''. */
function kop_sl_type_guess($type) {
    $type = strtolower((string) $type);
    if (strpos($type, 'prtf') !== false || strpos($type, 'psychiatric residential') !== false) return 'Psychiatric Residential Treatment Facility';
    if (strpos($type, 'group home') !== false && strpos($type, ',') === false) return 'Group Home';
    return '';
}

/** One card's data. $records: kop_sl_records() for every id the cards name. */
function kop_sl_item(array $r, array $records, array $lists) {
    $cands = array();
    foreach ((array) json_decode((string) $r['candidates'], true) as $c) {
        $f = $records[(int) ($c['id'] ?? 0)] ?? null;
        if ($f) {
            $cands[] = $f + array('why' => (string) ($c['why'] ?? ''));
        }
    }
    $undo = json_decode((string) $r['undo'], true) ?: array();
    $list = $lists[$r['list_state']] ?? array();
    $place = $r['city'] !== '' ? $r['city'] : ($r['county'] !== '' ? $r['county'] . ' County' : '');
    return array(
        'id'          => (int) $r['id'],
        'key'         => (string) $r['row_key'],
        'list'        => (string) $r['list_state'],
        'list_label'  => (string) ($list['label'] ?? ($r['list_state'] . ' list')),
        'state'       => (string) $r['state'],
        'name'        => (string) $r['name'],
        'suggested_name' => kop_sl_suggested_name($r['name']),
        'place'       => trim($place . ($r['state'] !== '' ? ', ' . $r['state'] : ''), ', '),
        'city'        => (string) $r['city'],
        'address'     => (string) $r['address'],
        'type'        => (string) $r['type'],
        'type_guess'  => kop_sl_type_guess($r['type']),
        'capacity'    => $r['capacity'] !== null ? (int) $r['capacity'] : null,
        'license_id'  => (string) $r['license_id'],
        'source_url'  => (string) $r['source_url'],
        'source_text' => (string) ($list['label'] ?? (function_exists('kop_url_label') ? kop_url_label($r['source_url']) : 'The state list')),
        'list_date'   => (string) $r['list_date'],
        'ambiguous'   => $r['match_status'] === 'ambiguous',
        'candidates'  => $cands,
        'excluded'    => $r['excluded'] ? json_decode((string) $r['excluded'], true) : null,
        'on_list'     => (int) $r['on_list'] === 1,
        'left_date'   => (string) $r['left_date'],
        'left_seen'   => (int) $r['left_seen'] === 1,
        'left_record' => $r['left_record_id'] ? ($records[(int) $r['left_record_id']] ?? array('id' => (int) $r['left_record_id'], 'name' => 'record #' . (int) $r['left_record_id'], 'url' => '')) : null,
        'status'      => (string) $r['status'],
        'facility'    => $r['facility_id'] ? ($records[(int) $r['facility_id']] ?? array('id' => (int) $r['facility_id'], 'name' => 'record #' . (int) $r['facility_id'] . ' (gone)', 'url' => '')) : null,
        'added_name'  => !empty($undo['added_name']),
        'decided_by'  => (string) $r['decided_by'],
        'decided_at'  => (string) $r['decided_at'],
    );
}

/** Everything the screen draws. */
function kop_sl_screen_data(PDO $pdo) {
    kop_sl_ensure_table($pdo);
    $rows = $pdo->query('SELECT * FROM `' . kop_sl_table() . '` ORDER BY list_state, name, id')->fetchAll(PDO::FETCH_ASSOC);
    $ids = array();
    foreach ($rows as $r) {
        foreach ((array) json_decode((string) $r['candidates'], true) as $c) $ids[] = (int) ($c['id'] ?? 0);
        $ids[] = (int) $r['facility_id'];
        $ids[] = (int) $r['left_record_id'];
    }
    $on_file = kop_sl_on_file($pdo, $rows);
    foreach ($on_file as $fid) $ids[] = $fid;
    $records = $ids ? kop_sl_records($pdo, $ids) : array();
    $meta = get_option('kop_state_lists_meta', array());
    $lists = is_array($meta['lists'] ?? null) ? $meta['lists'] : array();
    $path = kop_sl_import_path();
    return array(
        'items'    => array_map(function ($r) use ($records, $lists, $on_file) {
            $it = kop_sl_item($r, $records, $lists);
            $fid = $on_file[(int) $r['id']] ?? 0;
            $it['on_file'] = $fid && isset($records[$fid]) ? $records[$fid] : null;
            return $it;
        }, $rows),
        'lists'    => $lists,
        'statuses' => kop_sl_statuses(),
        'types'    => function_exists('kop_facdisc_types') ? kop_facdisc_types() : array(),
        'file'     => array('path' => $path, 'exists' => is_readable($path), 'modified' => is_readable($path) ? gmdate('Y-m-d H:i', filemtime($path)) . ' UTC' : '',
                            'generated' => (string) ($meta['generated'] ?? ''), 'imported_at' => (string) ($meta['imported_at'] ?? '')),
    );
}

/**
 * Rows still to decide (To review, Later) whose listed name is now exactly a
 * name of one record in the same state: a record made or renamed since the
 * export, or the name added when another row was linked. [row id => facility id];
 * two or more such records stay undecided, as at export.
 */
function kop_sl_on_file(PDO $pdo, array $rows) {
    $want = array();
    foreach ($rows as $r) {
        if (!in_array($r['status'], array('open', 'later'), true) || (int) $r['on_list'] !== 1 || !empty($r['excluded'])) continue;
        $key = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($r['name']) : (string) $r['name_key'];
        if ($key !== '' && $r['state'] !== '') $want[$r['state']][$key][] = (int) $r['id'];
    }
    if (!$want) return array();
    $states = array_keys($want);
    $st = $pdo->prepare('SELECT id, state, json_data FROM facilities_v2 WHERE state IN (' . implode(',', array_fill(0, count($states), '?')) . ')');
    $st->execute($states);
    $hits = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $doc = json_decode((string) $f['json_data'], true);
        if (!is_array($doc)) continue;
        foreach (array_keys(kop_sl_doc_name_keys($doc)) as $k) {
            foreach ($want[$f['state']][$k] ?? array() as $rid) $hits[$rid][(int) $f['id']] = true;
        }
    }
    $out = array();
    foreach ($hits as $rid => $fids) {
        if (count($fids) === 1) $out[$rid] = (int) key($fids);
    }
    return $out;
}

/* ---- Facility records ------------------------------------------------------ */

function kop_sl_opts(PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix, 'skip_memberships' => kop_sl_is_sqlite($pdo));
}

/** Save a changed document through the legacy shape (kop_gdl_save()), so it comes out current. */
function kop_sl_save_doc(array $doc, array $opts) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    kop_facility_save($out, $opts);
}

/** The record's names (name, current, other, past) as name keys. */
function kop_sl_doc_name_keys(array $doc) {
    $ident = (array) ($doc['identification'] ?? array());
    $names = array($ident['name'] ?? '', $ident['currentName'] ?? '');
    foreach (array('otherNames', 'pastNames') as $k) {
        foreach ((array) ($ident[$k] ?? array()) as $n) $names[] = is_array($n) ? ($n['name'] ?? '') : $n;
    }
    $keys = array();
    foreach ($names as $n) {
        $k = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key((string) $n) : strtolower(trim((string) $n));
        if ($k !== '') $keys[$k] = true;
    }
    return $keys;
}

function kop_sl_list_citation(array $r) {
    $meta = get_option('kop_state_lists_meta', array());
    $label = (string) ($meta['lists'][$r['list_state']]['label'] ?? ($r['list_state'] . ' state facility list'));
    return array($label, 'Added ' . gmdate('Y-m-d') . ' from the ' . $label . ($r['list_date'] !== '' ? ' (list dated ' . $r['list_date'] . ')' : '')
        . ($r['license_id'] !== '' ? ', licence ' . $r['license_id'] : '') . ': ' . $r['source_url']);
}

/* ---- Decisions ------------------------------------------------------------- */

function kop_sl_set(PDO $pdo, $id, $status, $fid, $undo, $user) {
    $pdo->prepare('UPDATE `' . kop_sl_table() . '` SET status = ?, facility_id = ?, undo = ?, decided_by = ?, decided_at = ? WHERE id = ?')
        ->execute(array($status, $fid ? (int) $fid : null, $undo !== null ? wp_json_encode($undo) : null, $user, gmdate('Y-m-d H:i:s'), (int) $id));
}

function kop_sl_after_change($fid = 0) {
    if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
    do_action('kop_facility_status_changed', (int) $fid);
}

/**
 * Do one thing to one row. Actions: link (facility_id, add_name), create
 * (name, city, state, type), not_tti, later, undo, dismiss_left, undo_left.
 * Returns the message for the screen; throws when it cannot.
 */
function kop_sl_act(PDO $pdo, $id, $action, array $p, $user) {
    $r = kop_sl_row($pdo, $id);
    if (!$r) throw new RuntimeException('That row is gone; import the list again.');
    $open = in_array($r['status'], array('open', 'later'), true);
    switch ($action) {
        case 'link':
            if (!$open) throw new RuntimeException('This row is decided already; Undo it on the Done tab first.');
            $fid = (int) ($p['facility_id'] ?? 0);
            $opts = kop_sl_opts($pdo);
            $loaded = $fid > 0 ? kop_facility_load($fid, $opts) : null;
            if (!$loaded) throw new RuntimeException('Pick the facility record to link first.');
            $undo = array('action' => 'link', 'prev' => $r['status']);
            $msg = 'Linked "' . $r['name'] . '" to ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . '.';
            if (!empty($p['add_name']) && $p['add_name'] !== 'false') {
                $doc = $loaded['doc'];
                $key = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($r['name']) : '';
                if ($key !== '' && isset(kop_sl_doc_name_keys($doc)[$key])) {
                    $msg .= ' The record already has that name, so nothing was added.';
                } else {
                    $doc['identification']['otherNames'] = array_values(array_merge((array) ($doc['identification']['otherNames'] ?? array()), array($r['name'])));
                    kop_sl_save_doc($doc, $opts);
                    $after = kop_facility_load($fid, $opts);
                    $undo += array('added_name' => $r['name'], 'doc_before' => $loaded['doc'], 'doc_after' => $after ? $after['doc'] : null);
                    $msg .= ' "' . $r['name'] . '" is now one of its other names.';
                }
            }
            kop_sl_set($pdo, $r['id'], 'linked', $fid, $undo, $user);
            kop_sl_after_change($fid);
            return $msg . ' Undo is on the Done tab.';

        case 'create':
            if (!$open) throw new RuntimeException('This row is decided already; Undo it on the Done tab first.');
            if (!empty($r['excluded'])) throw new RuntimeException('This name is not a TTI facility (see the note on the card); no facility record is made for it.');
            if (!function_exists('kop_facdisc_create')) throw new RuntimeException('The record creator (inc/facility-discovery.php) is not loaded.');
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($p['name'] ?? '') ?: kop_sl_suggested_name($r['name'])));
            $state = strtoupper(trim((string) ($p['state'] ?? '') ?: $r['state']));
            $type = (string) ($p['type'] ?? '');
            if ($name === '' || !preg_match('/^[A-Z]{2}$/', $state)) throw new RuntimeException('A name and a two-letter state are needed.');
            if ($type !== '' && !in_array($type, kop_facdisc_types(), true)) throw new RuntimeException('Type: pick one from the list.');
            kop_sl_opts($pdo);   // the write switch
            list($label, $note) = kop_sl_list_citation($r);
            $entry = array(
                'name' => $r['name'], 'officialName' => $name, 'otherNames' => array(), 'city' => trim((string) ($p['city'] ?? $r['city'])),
                'state' => $state, 'country' => 'United States', 'type' => $type, 'status' => 'Unknown', 'startYear' => null, 'endYear' => null,
                'operator' => '', 'gender' => '',
            );
            // Not the listed spelling as an other name when only the capitals differ.
            if (function_exists('kop_facility_pages_name_key') && kop_facility_pages_name_key($r['name']) === kop_facility_pages_name_key($name)) {
                $entry['name'] = $name;
            }
            $source = array('id' => 0, 'source_note' => $note, 'provenance_source' => 'state-list', 'provenance_category' => 'state-list',
                'resource_links' => array(array('url' => $r['source_url'], 'label' => $label, 'kind' => 'licensing', 'source' => $label)));
            list($decision, $fid, $doc) = kop_facdisc_create($pdo, $entry, $source, array('skip_memberships' => kop_sl_is_sqlite($pdo)));
            if ($decision === 'created') {
                kop_sl_set($pdo, $r['id'], 'created', $fid, array('action' => 'create', 'prev' => $r['status'], 'doc' => $doc), $user);
                kop_sl_after_change($fid);
                return 'Created ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ', citing the ' . $label . '. Undo on the Done tab deletes it while nobody has edited it.';
            }
            kop_sl_set($pdo, $r['id'], 'linked', $fid, array('action' => 'link', 'prev' => $r['status']), $user);
            return 'That name and place already has a record, ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ', so the row is linked to it. No new record was made.';

        case 'not_tti':
        case 'later':
            if (!$open || ($action === 'later' && $r['status'] === 'later')) throw new RuntimeException('This row is decided already.');
            kop_sl_set($pdo, $r['id'], $action, null, array('action' => $action, 'prev' => $r['status']), $user);
            return $action === 'later' ? 'Moved to Later.' : 'Marked as not a TTI facility. Nothing on the site changed; Undo is on the Done tab.';

        case 'undo':
            return kop_sl_undo($pdo, $r, $user);

        case 'dismiss_left':
        case 'undo_left':
            if ((int) $r['on_list'] === 1) throw new RuntimeException('This row is still on its list.');
            $pdo->prepare('UPDATE `' . kop_sl_table() . '` SET left_seen = ? WHERE id = ?')->execute(array($action === 'dismiss_left' ? 1 : 0, (int) $r['id']));
            return $action === 'dismiss_left' ? 'Dismissed. No record was changed.' : 'Back on the Left the list tab.';
    }
    throw new RuntimeException('Unknown action.');
}

/** Take back the row's decision exactly; the row returns to where it was. */
function kop_sl_undo(PDO $pdo, array $r, $user) {
    $undo = json_decode((string) $r['undo'], true) ?: array();
    if ($r['status'] === 'later' && empty($undo['action'])) {
        $undo = array('action' => 'later', 'prev' => 'open');   // back in Later after an earlier Undo
    }
    if ($r['status'] === 'open' || empty($undo['action'])) {
        throw new RuntimeException('Nothing to undo on this row.');
    }
    $prev = in_array($undo['prev'] ?? '', array('open', 'later'), true) ? $undo['prev'] : 'open';
    $fid = (int) $r['facility_id'];
    $msg = 'Undone: back in ' . kop_sl_statuses()[$prev] . '.';
    if ($r['status'] === 'linked' && !empty($undo['added_name'])) {
        $opts = kop_sl_opts($pdo);
        $now = kop_facility_load($fid, $opts);
        if ($now) {
            if (!empty($undo['doc_after']) && kop_facility_same_document($undo['doc_after'], $now['doc'])) {
                kop_facility_save($undo['doc_before'], $opts + array('force' => true));
                $msg .= ' The record is as it was before the link.';
            } else {
                // Edited since: take off only the name the link added.
                $doc = $now['doc'];
                $doc['identification']['otherNames'] = array_values(array_filter((array) ($doc['identification']['otherNames'] ?? array()),
                    function ($n) use ($undo) { return (is_array($n) ? ($n['name'] ?? '') : $n) !== $undo['added_name']; }));
                kop_sl_save_doc($doc, $opts);
                $msg .= ' "' . $undo['added_name'] . '" came off the record\'s other names; its other edits stay.';
            }
        }
    } elseif ($r['status'] === 'created') {
        kop_sl_delete_created($pdo, $fid, (array) ($undo['doc'] ?? array()));
        $msg .= ' The record it created is deleted.';
    }
    kop_sl_set($pdo, $r['id'], $prev, null, null, $user);
    if ($fid) kop_sl_after_change($fid);
    return $msg;
}

/**
 * Delete a record this screen created, only while nobody has changed it and
 * nothing points at it (the rule of kop_facdisc_remove() and Program Homes).
 */
function kop_sl_delete_created(PDO $pdo, $fid, array $made) {
    $opts = kop_sl_opts($pdo);
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) return;
    if (!$made || !kop_facility_same_document($made, $stored['doc'])) {
        throw new RuntimeException('Someone has edited this record since it was created here; change or delete it in the data form instead.');
    }
    foreach (array('news_facility_links' => 'an article', 'lawsuit_facility_links' => 'a lawsuit') as $t => $what) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE facility_id = ?");
            $q->execute(array($fid));
            if ((int) $q->fetchColumn() > 0) throw new RuntimeException('The record has ' . $what . ' linked to it now; unlink it first, or keep the record.');
        } catch (PDOException $e) {
            // That table is not on this install.
        }
    }
    $other = $pdo->prepare('SELECT COUNT(*) FROM `' . kop_sl_table() . '` WHERE facility_id = ? AND status IN (\'linked\', \'created\') AND facility_id IS NOT NULL');
    $other->execute(array($fid));
    if ((int) $other->fetchColumn() > 1) throw new RuntimeException('Another listed row is linked to this record now; undo that one first.');
    $t = kop_migration_tables($opts['prefix']);
    kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $fid) {
        foreach (array($t['facility_locations'], $t['operator_facilities'], $t['identity']) as $table) {
            try {
                $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?")->execute(array($fid));
            } catch (PDOException $e) {
                // Not on this install (a test copy).
            }
        }
        $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array($fid));
    });
}

/* ---- Screen ---------------------------------------------------------------- */

add_action('wp_ajax_kop_state_lists', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Only an administrator can do this.'), 403);
    check_ajax_referer('kop_state_lists', 'nonce');
    try {
        $pdo = kop_sl_pdo();
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        if ($op === 'import') {
            $c = kop_sl_import_if_changed($pdo, true);
            $msg = $c === null ? 'No file at ' . kop_sl_import_path() . '.'
                : sprintf('Imported: %d new rows, %d updated (decisions kept), %d gone from their list.', $c['added'], $c['updated'], $c['left']);
        } else {
            $params = array_map(function ($v) { return is_string($v) ? wp_unslash($v) : $v; }, (array) ($_POST['params'] ?? array()));
            $msg = kop_sl_act($pdo, (int) ($_POST['id'] ?? 0), $op, $params, wp_get_current_user()->user_login);
        }
        wp_send_json_success(array('message' => $msg, 'data' => kop_sl_screen_data($pdo)));
    } catch (Throwable $e) {
        $data = null;
        try { $data = kop_sl_screen_data(kop_sl_pdo()); } catch (Throwable $e2) {}
        wp_send_json_error(array('message' => $e->getMessage(), 'data' => $data), 400);
    }
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'State Lists', 'State Lists', 'manage_options', 'kop-state-lists', 'kop_sl_page');
}, 22);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'kop-state-lists') === false) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (file_exists($dir . '/css/colors.css')) {
        wp_enqueue_style('kop-colors', $uri . '/css/colors.css', array(), filemtime($dir . '/css/colors.css'));
    }
    wp_enqueue_style('kop-state-lists', $uri . '/css/state-lists.css', array('kop-colors'), filemtime($dir . '/css/state-lists.css'));
    wp_enqueue_script('kop-state-lists', $uri . '/js/state-lists.js', array(), filemtime($dir . '/js/state-lists.js'), true);
});

function kop_sl_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $notice = '';
    try {
        $pdo = kop_sl_pdo();
        kop_sl_ensure_table($pdo);
        $c = kop_sl_import_if_changed($pdo);
        if ($c) $notice = sprintf('Read the new list file: %d new rows, %d updated (decisions kept), %d gone from their list.', $c['added'], $c['updated'], $c['left']);
        $data = kop_sl_screen_data($pdo);
    } catch (Throwable $e) {
        echo '<div class="wrap"><h1>State Lists</h1><div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div></div>';
        return;
    }
    kop_sl_render_page($data, $notice, admin_url('admin-ajax.php'), wp_create_nonce('kop_state_lists'));
}

/** The page's HTML around the cards js/state-lists.js draws (also used by the offline test). */
function kop_sl_render_page(array $data, $notice, $ajax, $nonce) {
    $config = array('ajaxUrl' => $ajax, 'nonce' => $nonce, 'data' => $data);
    $icon = function ($n) { return function_exists('kop_icon') ? kop_icon($n) : ''; };
    ?>
    <div class="wrap kop-sl">
        <h1>State Lists</h1>
        <p class="kop-sl__intro">
            Facilities that a state's own published list names but our records do not have under that name.
            For each one: link it to the record it is (one click on a suggestion, or find any record by name), create a record from the list row,
            or mark it as not a TTI facility. Every decision can be undone from the <strong>Done</strong> tab.
            <strong>Left the list</strong> shows facilities gone from a list since it was last checked; that tab changes nothing by itself.
        </p>
        <?php if ($notice !== '') : ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <div class="kop-sl__file">
            <?php if ($data['file']['exists']) : ?>
                List file from <?php echo esc_html($data['file']['generated'] ?: $data['file']['modified']); ?>,
                read <?php echo esc_html($data['file']['imported_at'] ?: 'never'); ?> UTC.
                <button type="button" class="kop-sl__btn kop-sl__btn--plain kop-sl__import"><?php echo $icon('refresh'); ?>Import now</button>
            <?php else : ?>
                No list file yet. Put the export from <code>scripts/state-lists-check.py --export</code> at <code><?php echo esc_html($data['file']['path']); ?></code>.
            <?php endif; ?>
        </div>
        <div class="kop-sl__bar">
            <div class="kop-sl__tabs" role="tablist">
                <button type="button" class="kop-sl__tab" data-tab="open" aria-selected="true">To review <span></span></button>
                <button type="button" class="kop-sl__tab" data-tab="onfile" aria-selected="false" title="The listed name is now exactly a name of one record in the same state: link it to that record">Already on file <span></span></button>
                <button type="button" class="kop-sl__tab" data-tab="later" aria-selected="false">Later <span></span></button>
                <button type="button" class="kop-sl__tab" data-tab="done" aria-selected="false">Done <span></span></button>
                <button type="button" class="kop-sl__tab" data-tab="left" aria-selected="false">Left the list <span></span></button>
            </div>
            <label class="kop-sl__field">State <select class="kop-sl__state"><option value="">All states</option></select></label>
            <label class="kop-sl__field">Find <input type="search" class="kop-sl__search" placeholder="Name or town"></label>
            <span class="kop-sl__status" role="status" aria-live="polite"></span>
        </div>
        <p class="kop-sl__covers" hidden></p>
        <div class="kop-sl__list"></div>
        <div hidden><?php echo function_exists('kop_facility_finder_field') ? kop_facility_finder_field('', '', ' class="kop-sl__finder-proto"') : ''; ?></div>
        <script type="application/json" id="kop-sl-config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    </div>
    <?php
}
