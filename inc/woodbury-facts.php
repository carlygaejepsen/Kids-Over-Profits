<?php
/**
 * Woodbury Reports facts: what the newsletter says about each program, added
 * to its facility record after review.
 *
 * scripts/woodbury-facts.py reads the facts pulled from every issue (staff and
 * their roles over the years, incidents, openings, closings, renames, owners,
 * moves, size, ages), checks each quote against the issue text, matches the
 * program to facilities_v2 and leaves out what the record already holds. Its
 * facts.json is copied to ~/kop-import/woodbury/ beside the mention
 * candidates (inc/woodbury-mentions.php).
 *
 * KOP Tools > Woodbury Facts shows one card per facility with every
 * proposed addition, the quote it rests on and a link to the issue page.
 * "Add checked to record" writes them into facilities_v2 at once (the
 * /facility/ page shows them, and the network map picks up new staff, past
 * operators and past names on its next build). Each addition cites the issue.
 * Undo takes back exactly what was added. Programs with no record can be
 * pointed at an existing record or created from the card.
 *
 * Proposals live in {prefix}kop_woodbury_facts, keyed by the build's key, so
 * a rebuild adds new ones and never undoes a decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_WOODBURY_FACTS_DB_VERSION', '2');

function kop_wbf_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_woodbury_facts';
}

function kop_wbf_ensure_table() {
    if (get_option('kop_woodbury_facts_db') === KOP_WOODBURY_FACTS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_wbf_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pkey VARCHAR(32) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        program VARCHAR(255) NOT NULL DEFAULT '',
        program_as_written VARCHAR(255) NOT NULL DEFAULT '',
        place VARCHAR(120) NOT NULL DEFAULT '',
        match_kind VARCHAR(12) NOT NULL DEFAULT '',
        match_note TEXT NULL,
        alternatives TEXT NULL,
        grp VARCHAR(12) NOT NULL DEFAULT '',
        op VARCHAR(16) NOT NULL DEFAULT '',
        path VARCHAR(64) NOT NULL DEFAULT '',
        value LONGTEXT NULL,
        label TEXT NULL,
        conflict TEXT NULL,
        current_val TEXT NULL,
        extra LONGTEXT NULL,
        evidence LONGTEXT NULL,
        found TINYINT(1) NOT NULL DEFAULT 0,
        preselect TINYINT(1) NOT NULL DEFAULT 0,
        auto TINYINT(1) NOT NULL DEFAULT 0,
        issue_date VARCHAR(7) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        applied LONGTEXT NULL,
        applied_fid BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pkey (pkey),
        KEY status_fac (status, facility_id),
        KEY program (program(80))
    ) {$charset};");
    update_option('kop_woodbury_facts_db', KOP_WOODBURY_FACTS_DB_VERSION);
}

function kop_wbf_path() {
    return (function_exists('kop_wb_pending_dir') ? kop_wb_pending_dir() : dirname(rtrim(ABSPATH, '/')) . '/kop-import/woodbury') . '/facts.json';
}

/**
 * Load a new facts.json. New proposals are added, waiting ones take the
 * build's latest match and text, decided ones are left alone, and waiting
 * ones the build no longer makes are marked 'gone'.
 */
function kop_wbf_sync($force = false) {
    $path = kop_wbf_path();
    if (!is_readable($path)) {
        return null;
    }
    $md5 = md5_file($path);
    if (!$force && get_option('kop_woodbury_facts_md5') === $md5) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['proposals']) || !is_array($data['proposals'])) {
        return null;
    }
    global $wpdb;
    $table = kop_wbf_table();
    $existing = array();
    foreach ((array) $wpdb->get_results("SELECT pkey, status FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['pkey']] = $r['status'];
    }
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    foreach ($data['proposals'] as $p) {
        $pkey = preg_replace('/[^a-f0-9]/', '', (string) ($p['key'] ?? ''));
        if ($pkey === '') {
            continue;
        }
        $seen[$pkey] = true;
        $extra = array();
        foreach (array('person', 'career', 'note_line') as $k) {
            if (isset($p[$k])) {
                $extra[$k] = $p[$k];
            }
        }
        $row = array(
            'facility_id'        => (int) ($p['facility_id'] ?? 0),
            'program'            => mb_substr((string) ($p['program'] ?? ''), 0, 255),
            'program_as_written' => mb_substr((string) ($p['program_as_written'] ?? ''), 0, 255),
            'place'              => mb_substr((string) ($p['place'] ?? ''), 0, 120),
            'match_kind'         => substr(sanitize_key($p['match'] ?? ''), 0, 12),
            'match_note'         => (string) ($p['match_note'] ?? ''),
            'alternatives'       => wp_json_encode(array_values((array) ($p['alternatives'] ?? array()))),
            'grp'                => substr(sanitize_key($p['group'] ?? ''), 0, 12),
            'op'                 => substr(sanitize_key($p['op'] ?? ''), 0, 16),
            'path'               => substr(preg_replace('/[^A-Za-z.]/', '', (string) ($p['path'] ?? '')), 0, 64),
            'value'              => wp_json_encode($p['value'] ?? null),
            'label'              => (string) ($p['label'] ?? ''),
            'conflict'           => (string) ($p['conflict'] ?? ''),
            'current_val'        => (string) ($p['current'] ?? ''),
            'extra'              => wp_json_encode($extra),
            'evidence'           => wp_json_encode(array_values((array) ($p['evidence'] ?? array()))),
            'found'              => !empty($p['found']) ? 1 : 0,
            'preselect'          => !empty($p['preselect']) ? 1 : 0,
            'auto'               => !empty($p['auto']) ? 1 : 0,
            'issue_date'         => substr((string) ($p['issue_date'] ?? ''), 0, 7),
        );
        if (!isset($existing[$pkey])) {
            $row['pkey'] = $pkey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            if ($wpdb->insert($table, $row) !== false) {
                $added++;
            }
        } elseif (in_array($existing[$pkey], array('pending', 'gone'), true)) {
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('pkey' => $pkey));
            $updated++;
        }
    }
    $gone = 0;
    foreach ($existing as $pkey => $status) {
        if ($status === 'pending' && !isset($seen[$pkey])) {
            $gone += (int) $wpdb->update($table, array('status' => 'gone'), array('pkey' => $pkey));
        }
    }
    update_option('kop_woodbury_facts_md5', $md5, false);
    return array('added' => $added, 'updated' => $updated, 'gone' => $gone);
}

/* ---- Changing a document ---------------------------------------------- */

/** "Dr. Jane M. Doe, PhD" -> "jane doe", as scripts/woodbury-facts.py keys people. */
function kop_wbf_person_key($name) {
    $s = strtolower(remove_accents((string) $name));
    $s = preg_replace('/\b(dr|mr|mrs|ms|rev|jr|sr|ii|iii|iv|phd|md|lcsw|lpc|ma|med|edd|psyd)\b\.?/', ' ', $s);
    $s = preg_replace('/[^a-z\s-]/', ' ', $s);
    $words = array_values(array_filter(preg_split('/[\s-]+/', $s), function ($w) { return strlen($w) > 1; }));
    return count($words) < 2 ? '' : $words[0] . ' ' . $words[count($words) - 1];
}

function &kop_wbf_ref(array &$doc, $path) {
    $ref = &$doc;
    foreach (explode('.', $path) as $part) {
        if (!isset($ref[$part]) || !is_array($ref)) {
            $ref[$part] = null;
        }
        $ref = &$ref[$part];
    }
    return $ref;
}

/** The value at a dotted path, or null, without creating anything. */
function kop_wbf_get(array $doc, $path) {
    foreach (explode('.', $path) as $part) {
        if (!is_array($doc) || !array_key_exists($part, $doc)) {
            return null;
        }
        $doc = $doc[$part];
    }
    return $doc;
}

function kop_wbf_row_value(array $r) {
    return json_decode((string) $r['value'], true);
}

function kop_wbf_evidence(array $r) {
    $e = json_decode((string) $r['evidence'], true);
    return is_array($e) ? $e : array();
}

/** "Woodbury Reports, May 2007 (#153), p. 20: <url>", the first source. */
function kop_wbf_cite(array $r) {
    $e = kop_wbf_evidence($r);
    if (!$e) {
        return 'Woodbury Reports';
    }
    $first = $e[0];
    return 'Woodbury Reports, ' . $first['label'] . (!empty($first['number']) ? ' (' . $first['number'] . ')' : '')
        . ', p. ' . (int) $first['page'] . ': ' . $first['url'];
}

/** The line a structured change leaves in the notes, so the record says where it came from. */
function kop_wbf_source_line(array $r) {
    return $r['label'] . ' (' . kop_wbf_cite($r) . ')';
}

function kop_wbf_list_has(array $list, $value) {
    foreach ($list as $item) {
        if (is_string($value) && is_string($item) && strcasecmp(trim($item), trim($value)) === 0) {
            return true;
        }
        if (is_array($value) && is_array($item) && isset($value['raw'], $item['raw']) && strcasecmp($item['raw'], $value['raw']) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Apply one proposal to a document. Returns what was done, for Undo.
 * Throws when the record already holds a different value.
 */
function kop_wbf_doc_apply(array &$doc, array $r) {
    $value = kop_wbf_row_value($r);
    $done = array('op' => $r['op'], 'path' => $r['path'], 'notes' => array());
    $add_note = function ($path, $line) use (&$doc, &$done) {
        $list = &kop_wbf_ref($doc, $path);
        $list = is_array($list) ? $list : array();
        if (!in_array($line, $list, true)) {
            $list[] = $line;
            $done['notes'][] = array('path' => $path, 'line' => $line);
        }
    };

    if ($r['op'] === 'add_staff') {
        $key = kop_wbf_person_key($value['name'] ?? '');
        foreach (array('staff.administrator', 'staff.notableStaff') as $path) {
            $list = &kop_wbf_ref($doc, $path);
            $list = is_array($list) ? $list : array();
            foreach ($list as $i => $s) {
                $s = is_array($s) ? $s : array('name' => (string) $s, 'role' => '', 'pastJobs' => '');
                if ($key !== '' && kop_wbf_person_key($s['name'] ?? '') === $key) {
                    $before = array('role' => (string) ($s['role'] ?? ''), 'pastJobs' => (string) ($s['pastJobs'] ?? ''));
                    if ($before['role'] !== '' && $before['pastJobs'] !== '') {
                        throw new RuntimeException('Already on the record\'s staff list.');
                    }
                    if ($before['role'] === '') {
                        $s['role'] = $value['role'];
                    }
                    if ($before['pastJobs'] === '') {
                        $s['pastJobs'] = $value['pastJobs'];
                    }
                    $list[$i] = $s;
                    $done += array('mode' => 'merged', 'at' => $path, 'name' => $s['name'], 'before' => $before);
                    return $done;
                }
            }
            unset($list);
        }
        $list = &kop_wbf_ref($doc, $r['path']);
        $list = is_array($list) ? $list : array();
        $list[] = array('name' => (string) $value['name'], 'role' => (string) $value['role'], 'pastJobs' => (string) $value['pastJobs']);
        $done += array('mode' => 'added', 'at' => $r['path'], 'name' => (string) $value['name']);
        return $done;
    }

    if ($r['op'] === 'add_list') {
        $done['created'] = kop_wbf_get($doc, $r['path']) === null;
        $list = &kop_wbf_ref($doc, $r['path']);
        $list = is_array($list) ? $list : array();
        if (kop_wbf_list_has($list, $value)) {
            throw new RuntimeException('Already on the record.');
        }
        $list[] = $value;
        unset($list);
        $done['value'] = $value;
        if (in_array($r['path'], array('identification.pastNames', 'identification.pastOperators', 'location.formerLocations'), true)) {
            $extra = json_decode((string) $r['extra'], true);
            $add_note('notes', !empty($extra['note_line']) ? $extra['note_line'] . ' ' . kop_wbf_evidence($r)[0]['url'] : kop_wbf_source_line($r));
        }
        return $done;
    }

    if ($r['op'] === 'set_if_empty') {
        // A type is only ever one of the agreed types, the list the discovery form and "Create a record" use.
        if ($r['path'] === 'facilityDetails.type' && function_exists('kop_facdisc_types') && !in_array($value, kop_facdisc_types(), true)) {
            throw new RuntimeException('"' . $value . '" is not one of the agreed facility types.');
        }
        $slot = &kop_wbf_ref($doc, $r['path']);
        $empty = $slot === null || $slot === '' || (is_array($slot) && ($slot['min'] ?? null) === null && ($slot['max'] ?? null) === null);
        if (!$empty) {
            throw new RuntimeException('The record already has ' . (is_array($slot) ? wp_json_encode($slot) : $slot) . ' there.');
        }
        $done['before'] = $slot;
        $slot = $value;
        unset($slot);
        $done['value'] = $value;
        $add_note('operatingPeriod.notes', kop_wbf_source_line($r));
        return $done;
    }

    if ($r['op'] === 'set_closed') {
        $done['before_status'] = $doc['operatingPeriod']['status'] ?? 'Unknown';
        $done['before_end'] = $doc['operatingPeriod']['endYear'] ?? null;
        $doc['operatingPeriod']['status'] = 'Closed';
        if (!empty($value['endYear']) && $done['before_end'] === null) {
            $doc['operatingPeriod']['endYear'] = (int) $value['endYear'];
        }
        $add_note('operatingPeriod.notes', kop_wbf_source_line($r));
        return $done;
    }
    throw new RuntimeException('Unknown change.');
}

/** Take back what kop_wbf_doc_apply() did, leaving anything edited since. */
function kop_wbf_doc_undo(array &$doc, array $done) {
    foreach ((array) ($done['notes'] ?? array()) as $n) {
        $list = &kop_wbf_ref($doc, $n['path']);
        $list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($n) { return $x !== $n['line']; }));
        unset($list);
    }
    if ($done['op'] === 'add_staff') {
        $list = &kop_wbf_ref($doc, $done['at']);
        $list = is_array($list) ? $list : array();
        $key = kop_wbf_person_key($done['name']);
        foreach ($list as $i => $s) {
            if (!is_array($s) || kop_wbf_person_key($s['name'] ?? '') !== $key) {
                continue;
            }
            if ($done['mode'] === 'added') {
                array_splice($list, $i, 1);
            } else {
                $list[$i]['role'] = $done['before']['role'];
                $list[$i]['pastJobs'] = $done['before']['pastJobs'];
            }
            break;
        }
    } elseif ($done['op'] === 'add_list') {
        $list = &kop_wbf_ref($doc, $done['path']);
        // Matched as kop_wbf_list_has() does: the save fills in a location's city and country, so by its raw text.
        $list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($done) {
            return !kop_wbf_list_has(array($x), $done['value']);
        }));
        $empty = !$list;
        unset($list);
        if ($empty && !empty($done['created'])) {
            // The list was made for this addition ("customIncidents" on an empty map): take it away again.
            $parts = explode('.', $done['path']);
            $leaf = array_pop($parts);
            $parent = &kop_wbf_ref($doc, implode('.', $parts));
            if (is_array($parent)) {
                unset($parent[$leaf]);
            }
            unset($parent);
        }
    } elseif ($done['op'] === 'set_if_empty') {
        $slot = &kop_wbf_ref($doc, $done['path']);
        if ($slot === $done['value']) {
            $slot = $done['before'];
        }
    } elseif ($done['op'] === 'set_closed') {
        if (($doc['operatingPeriod']['status'] ?? '') === 'Closed') {
            $doc['operatingPeriod']['status'] = $done['before_status'] ?: 'Unknown';
        }
        if ($done['before_end'] === null) {
            $doc['operatingPeriod']['endYear'] = null;
        }
    }
}

function kop_wbf_opts() {
    global $wpdb;
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/** Save a changed document the way the closure confirm does: through the legacy shape, so it comes out current. */
function kop_wbf_save(array $doc, array $opts) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    kop_facility_save($out, $opts);
}

function kop_wbf_rows(array $pkeys) {
    global $wpdb;
    $pkeys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, $pkeys)));
    if (!$pkeys) {
        return array();
    }
    $in = implode(',', array_fill(0, count($pkeys), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_wbf_table() . " WHERE pkey IN ({$in})", $pkeys), ARRAY_A);
}

/**
 * Add proposals to one facility's record in one save. $fid overrides the
 * build's match. Returns [pkey => result].
 */
function kop_wbf_apply(array $rows, $fid, $reviewer) {
    global $wpdb;
    $fid = (int) $fid;
    if ($fid <= 0) {
        throw new RuntimeException('Pick the facility first.');
    }
    $opts = kop_wbf_opts();
    $results = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($rows, $fid, $opts, $reviewer, &$results, $wpdb) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            throw new RuntimeException("Facility #{$fid} does not exist.");
        }
        $doc = $stored['doc'];
        $applied = array();
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') {
                $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
                continue;
            }
            try {
                $trial = $doc;
                $done = kop_wbf_doc_apply($trial, $r);
                $doc = $trial;
                $applied[$r['pkey']] = $done;
                $results[$r['pkey']] = array('ok' => true);
            } catch (RuntimeException $e) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage(), 'already' => true);
            }
        }
        if ($applied) {
            kop_wbf_save($doc, $opts);
        }
        $now = current_time('mysql', true);
        foreach ($rows as $r) {
            if (isset($applied[$r['pkey']])) {
                $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($applied[$r['pkey']]),
                    'applied_fid' => $fid, 'facility_id' => $fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            } elseif (!empty($results[$r['pkey']]['already'])) {
                $wpdb->update(kop_wbf_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => $results[$r['pkey']]['error'])),
                    'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            }
        }
    });
    foreach ($rows as $r) {
        if (($r['op'] ?? '') === 'set_closed' && !empty($results[$r['pkey']]['ok'])) {
            do_action('kop_facility_status_changed', $fid);
            break;
        }
    }
    return $results;
}

function kop_wbf_undo(array $rows, $reviewer) {
    global $wpdb;
    $opts = kop_wbf_opts();
    $by = array();
    foreach ($rows as $r) {
        // Something taken back by hand is never added automatically again.
        $wpdb->update(kop_wbf_table(), array('auto' => 0), array('pkey' => $r['pkey']));
        if ($r['status'] === 'applied' && $r['grp'] === 'consultant') {
            kop_wbf_consultant_undo($r);
            $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
        } elseif ($r['status'] === 'applied') {
            $by[(int) $r['applied_fid']][] = $r;
        } elseif ($r['status'] === 'rejected') {
            $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
        }
    }
    foreach ($by as $fid => $list) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, $reviewer, $wpdb) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                throw new RuntimeException("Facility #{$fid} does not exist.");
            }
            $doc = $stored['doc'];
            foreach (array_reverse($list) as $r) {
                $done = json_decode((string) $r['applied'], true);
                if (is_array($done) && isset($done['op'])) {
                    kop_wbf_doc_undo($doc, $done);
                }
            }
            kop_wbf_save($doc, $opts);
            foreach ($list as $r) {
                $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'applied_fid' => 0,
                    'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            }
        });
        do_action('kop_facility_status_changed', $fid);
    }
}

/* ---- Automatic additions --------------------------------------------------- */

/**
 * Add the items the build marked as plainly stated (auto = 1: verified quote,
 * sure match, the quote saying exactly what is added; never closures,
 * incidents, consultant flags or programs with no record), one facility at a
 * time until $seconds run out. They land on the "Added automatically" tab,
 * each with Undo. Runs hourly and for a moment whenever the screen opens.
 */
function kop_wbf_auto_run($seconds = 40) {
    global $wpdb;
    if (get_transient('kop_wbf_auto_lock')) {
        return 0;
    }
    set_transient('kop_wbf_auto_lock', 1, 5 * MINUTE_IN_SECONDS);
    $table = kop_wbf_table();
    $start = microtime(true);
    $added = 0;
    try {
        $fids = $wpdb->get_col("SELECT DISTINCT facility_id FROM {$table} WHERE status = 'pending' AND auto = 1 AND facility_id > 0 LIMIT 200");
        foreach ($fids as $fid) {
            if (microtime(true) - $start > $seconds) {
                break;
            }
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = 'pending' AND auto = 1 AND facility_id = %d", (int) $fid), ARRAY_A);
            try {
                foreach (kop_wbf_apply($rows, (int) $fid, 'auto') as $res) {
                    $added += !empty($res['ok']) ? 1 : 0;
                }
            } catch (Throwable $e) {
                // Leave them for a person, with the reason.
                error_log('kop woodbury facts auto #' . (int) $fid . ': ' . $e->getMessage());
                foreach ($rows as $r) {
                    $wpdb->update($table, array('auto' => 0, 'conflict' => 'Could not be added automatically: ' . $e->getMessage()), array('pkey' => $r['pkey']));
                }
            }
        }
    } finally {
        delete_transient('kop_wbf_auto_lock');
    }
    return $added;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_wbf_auto_hourly')) {
        wp_schedule_event(time() + 300, 'hourly', 'kop_wbf_auto_hourly');
    }
});
add_action('kop_wbf_auto_hourly', function () {
    kop_wbf_ensure_table();
    kop_wbf_sync();
    kop_wbf_auto_run(120);
});

/* ---- Educational consultants with an industry past ----------------------- */

function kop_wbf_referrer_table(PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $table = kop_resolve_table_name($pdo, 'referrers_master', $wpdb->prefix);
    kop_ensure_master_table($pdo, $table);
    return $table;
}

/** The person entries of a consultant record that are this person: referrerIndividual and referrerConsultants[]. */
function kop_wbf_consultant_entries(array &$data, $key) {
    $refs = array();
    if (isset($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $c = $data['referrerIndividual'];
        $name = $c['fullName'] ?? trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
        if (kop_wbf_person_key($name) === $key) {
            $refs[] = &$data['referrerIndividual'];
        }
    }
    if (isset($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $i => $c) {
            $name = is_array($c) ? ($c['fullName'] ?? trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''))) : '';
            if (kop_wbf_person_key($name) === $key) {
                $refs[] = &$data['referrerConsultants'][$i];
            }
        }
    }
    return $refs;
}

/**
 * Flag a consultant as former industry staff: their programs go into
 * pastTTIJobs (the directory's "Career History"), formerIndustryStaff is set
 * and the issue is cited in their notes. A consultant with no record gets one
 * (kop_wbc_create_consultant, which also files it under Educational
 * Consultants). Returns what Undo needs.
 */
function kop_wbf_consultant_apply(array $r) {
    $v = kop_wbf_row_value($r);
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    $table = kop_wbf_referrer_table($pdo);
    $key = kop_wbf_person_key($v['name'] ?? '');
    if ($key === '') {
        throw new RuntimeException('No name to flag.');
    }
    $rid = (int) ($v['referrer_id'] ?? 0);
    $created = false;
    if ($rid <= 0) {
        // A record made since the build may name them already.
        $find = $pdo->prepare("SELECT id FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) OR json_data LIKE ? LIMIT 1");
        $find->execute(array($v['name'], '%"' . str_replace(array('%', '_'), array('\%', '\_'), $v['name']) . '"%'));
        $rid = (int) $find->fetchColumn();
    }
    if ($rid <= 0) {
        if (!function_exists('kop_wbc_create_consultant')) {
            throw new RuntimeException('Record creation is not available.');
        }
        $e = kop_wbf_evidence($r);
        $first = $e ? $e[0] : array('issue_id' => 0, 'label' => '', 'number' => '', 'page' => 0);
        $state = ($v['state'] ?? '') !== '' ? (string) kop_facility_state_code($v['state']) : '';
        $made = kop_wbc_create_consultant(
            array('issue_id' => (int) $first['issue_id'], 'issue_label' => (string) $first['label'],
                'issue_number' => (string) $first['number'], 'pages' => (string) (int) $first['page']),
            array('name' => $v['name'], 'who' => 'person', 'city' => (string) ($v['city'] ?? ''), 'state' => $state, 'country' => ''),
            $pdo
        );
        $rid = (int) $made['id'];
        $created = true;
    }
    $row = $pdo->prepare("SELECT unique_name, json_data FROM `{$table}` WHERE id = ?");
    $row->execute(array($rid));
    $rec = $row->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        throw new RuntimeException("Consultant record #{$rid} does not exist.");
    }
    $before = (string) $rec['json_data'];
    $payload = json_decode($before, true);
    if (!is_array($payload)) {
        throw new RuntimeException('The consultant record could not be read.');
    }
    if (!isset($payload['data']) || !is_array($payload['data'])) {
        $payload['data'] = array();
    }
    $data = &$payload['data'];
    $entries = kop_wbf_consultant_entries($data, $key);
    if (!$entries) {
        // A firm's record without this person on it: add them to its consultants.
        $bits = explode(' ', trim($v['name']));
        $last = count($bits) > 1 ? array_pop($bits) : '';
        $data['referrerConsultants'] = isset($data['referrerConsultants']) && is_array($data['referrerConsultants']) ? $data['referrerConsultants'] : array();
        $data['referrerConsultants'][] = array('firstName' => implode(' ', $bits), 'lastName' => $last, 'fullName' => trim($v['name']),
            'role' => 'Educational Consultant', 'credentials' => (string) ($v['credentials'] ?? ''), 'pastTTIJobs' => array(), 'notes' => '');
        $entries = kop_wbf_consultant_entries($data, $key);
    }
    $cite = kop_wbf_cite($r);
    $added = 0;
    foreach ($entries as &$entry) {
        $jobs = isset($entry['pastTTIJobs']) && is_array($entry['pastTTIJobs']) ? $entry['pastTTIJobs'] : array();
        $have = array();
        foreach ($jobs as $j) {
            $have[] = strtolower(trim(is_array($j) ? ($j['organization'] ?? $j['employer'] ?? '') : (string) $j));
        }
        foreach ((array) ($v['jobs'] ?? array()) as $j) {
            $org = trim((string) ($j['organization'] ?? ''));
            if ($org === '' || in_array(strtolower($org), $have, true)) {
                continue;
            }
            $role = trim((string) ($j['role'] ?? '')) . (($j['when'] ?? '') !== '' ? ' (' . $j['when'] . ')' : '');
            $jobs[] = array('role' => $role, 'organization' => $org, 'employer' => $org, 'source' => 'Woodbury Reports');
            $have[] = strtolower($org);
            $added++;
        }
        $entry['pastTTIJobs'] = $jobs;
        $entry['formerIndustryStaff'] = true;
        $line = 'Worked in the troubled teen industry before or while consulting, per ' . $cite;
        $notes = (string) ($entry['notes'] ?? '');
        if (strpos($notes, $line) === false) {
            $entry['notes'] = trim($notes . ($notes !== '' ? "\n" : '') . $line);
        }
    }
    unset($entry);
    unset($data);
    $after = wp_json_encode($payload);
    $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array($after, $rid));
    kop_wbf_consultant_mirror($pdo, $rec['unique_name'], $payload, $key);
    return array('op' => 'consultant_jobs', 'referrer_id' => $rid, 'created' => $created, 'added' => $added,
        'before' => $before, 'after_md5' => md5($after), 'unique_name' => $rec['unique_name']);
}

/** State pages list consultants from their state's locations_master row: keep that copy in step. */
function kop_wbf_consultant_mirror(PDO $pdo, $project, array $payload, $key) {
    global $wpdb;
    if (!function_exists('kop_wbc_mirror_consultant')) {
        return;
    }
    $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
    foreach (kop_wbf_consultant_entries($data, $key) as $entry) {
        $state = (string) ($entry['state'] ?? '');
        $code = $state !== '' ? (string) kop_facility_state_code($state) : '';
        if ($code !== '') {
            kop_wbc_mirror_consultant($pdo, $wpdb->prefix, $project, $code, $entry);
        }
        break;
    }
}

function kop_wbf_consultant_undo(array $r) {
    $done = json_decode((string) $r['applied'], true);
    if (!is_array($done) || empty($done['referrer_id'])) {
        return;
    }
    $pdo = kop_closure_pdo();
    $table = kop_wbf_referrer_table($pdo);
    $row = $pdo->prepare("SELECT json_data FROM `{$table}` WHERE id = ?");
    $row->execute(array((int) $done['referrer_id']));
    $now = (string) $row->fetchColumn();
    if ($now !== '' && md5($now) !== $done['after_md5']) {
        throw new RuntimeException('The consultant record was edited since; change it in the data form instead.');
    }
    $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array($done['before'], (int) $done['referrer_id']));
    $payload = json_decode($done['before'], true);
    if (is_array($payload)) {
        kop_wbf_consultant_mirror($pdo, $done['unique_name'], $payload, kop_wbf_person_key(kop_wbf_row_value($r)['name'] ?? ''));
    }
}

/* ---- AJAX --------------------------------------------------------------- */

add_action('wp_ajax_kop_wbf_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury_facts', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? array_slice($_POST['keys'], 0, 400) : array();
    $rows = kop_wbf_rows($keys);
    $user = wp_get_current_user()->user_login;
    try {
        if (!$rows) {
            throw new RuntimeException('Nothing selected.');
        }
        if ($act === 'apply' || $act === 'create') {
            $fid = (int) ($_POST['fid'] ?? 0);
            if ($act === 'create') {
                if (!function_exists('kop_wbc_create_facility')) {
                    throw new RuntimeException('Record creation is not available.');
                }
                $e = kop_wbf_evidence($rows[0]);
                $first = $e ? $e[0] : array('issue_id' => 0, 'label' => '', 'number' => '', 'page' => 0);
                $source = array('issue_id' => (int) $first['issue_id'], 'issue_label' => (string) $first['label'],
                    'issue_number' => (string) $first['number'], 'pages' => (string) (int) $first['page']);
                $f = array(
                    'name'  => trim(preg_replace('/\s+/', ' ', sanitize_text_field(wp_unslash($_POST['name'] ?? '')))),
                    'city'  => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                    'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
                    'force' => !empty($_POST['force']),
                );
                $raw_state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
                $f['state'] = $raw_state !== '' ? (string) kop_facility_state_code($raw_state) : '';
                if ($raw_state !== '' && $f['state'] === '') {
                    throw new RuntimeException('"' . $raw_state . '" is not a US state. Leave it empty and give the country instead.');
                }
                if ($f['name'] === '') {
                    throw new RuntimeException('A name is needed.');
                }
                $target = kop_wbc_create_facility($source, $f, kop_closure_pdo());
                $fid = (int) $target['id'];
            }
            $results = kop_wbf_apply($rows, $fid, $user);
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
            wp_send_json_success(array('results' => $results, 'fid' => $fid, 'url' => $url,
                'label' => wp_strip_all_tags(kop_facility_finder_label(kop_closure_pdo(), $fid))));
        } elseif ($act === 'consultant') {
            $results = array();
            foreach ($rows as $r) {
                if ($r['status'] !== 'pending' || $r['grp'] !== 'consultant') {
                    $results[$r['pkey']] = array('ok' => false, 'error' => 'Not a waiting consultant.');
                    continue;
                }
                $done = kop_wbf_consultant_apply($r);
                $GLOBALS['wpdb']->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($done),
                    'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
                $results[$r['pkey']] = array('ok' => true, 'created' => $done['created'], 'added' => $done['added']);
            }
            wp_send_json_success(array('results' => $results, 'url' => home_url('/referrers-educational-consultants/')));
        } elseif ($act === 'reject') {
            foreach ($rows as $r) {
                if ($r['status'] === 'pending') {
                    $GLOBALS['wpdb']->update(kop_wbf_table(), array('status' => 'rejected', 'reviewed_by' => $user,
                        'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
                }
            }
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        } elseif ($act === 'undo') {
            kop_wbf_undo($rows, $user);
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Review screen ------------------------------------------------------ */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Woodbury Facts', 'Woodbury Facts', 'manage_options',
        'kop-woodbury-facts', 'kop_render_woodbury_facts_page');
}, 21);

function kop_wbf_tabs() {
    return array(
        'records'  => array('label' => 'For existing records', 'where' => "status = 'pending' AND facility_id > 0"),
        'norecord' => array('label' => 'Programs with no record', 'where' => "status = 'pending' AND facility_id = 0 AND grp <> 'consultant'"),
        'consultants' => array('label' => 'Ed cons who worked in the industry', 'where' => "status = 'pending' AND grp = 'consultant'"),
        'applied'  => array('label' => 'Added', 'where' => "status = 'applied'"),
        'auto'     => array('label' => 'Added automatically', 'where' => "status = 'applied' AND reviewed_by = 'auto'"),
        'rejected' => array('label' => 'Rejected', 'where' => "status = 'rejected'"),
    );
}

function kop_wbf_groups() {
    return array(
        'staff'    => 'Staff and careers',
        'incident' => 'Incidents',
        'history'  => 'History (openings, closings, names, owners, moves)',
        'details'  => 'Details (size, ages, type, memberships, other)',
        'consultant' => 'Educational consultant who worked in the industry',
    );
}

function kop_wbf_where_it_goes(array $r) {
    $map = array(
        'staff.administrator'               => 'Staff: administrators',
        'staff.notableStaff'                => 'Staff: notable staff',
        'criticalIncidents.customIncidents' => 'Critical incidents',
        'notes'                             => 'Notes',
        'identification.pastNames'          => 'Past names',
        'identification.pastOperators'      => 'Past operators',
        'location.formerLocations'          => 'Former locations',
        'operatingPeriod.startYear'         => 'Start year',
        'operatingPeriod'                   => 'Status and end year',
        'facilityDetails.capacity'          => 'Capacity',
        'facilityDetails.ageRange'          => 'Age range',
        'facilityDetails.gender'            => 'Gender',
        'facilityDetails.type'              => 'Type',
        'referrer'                          => 'Consultant record: Career History, flagged former industry staff',
    );
    return $map[$r['path']] ?? $r['path'];
}

function kop_render_woodbury_facts_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_wbf_ensure_table();
    $sync = kop_wbf_sync(isset($_GET['wbf_resync']));
    $auto_now = kop_wbf_auto_run(15);
    $auto_left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . " WHERE status = 'pending' AND auto = 1 AND facility_id > 0");
    $table = kop_wbf_table();
    $tabs = kop_wbf_tabs();
    $groups = kop_wbf_groups();
    $tab = isset($_GET['wbf_tab'], $tabs[$_GET['wbf_tab']]) ? $_GET['wbf_tab'] : 'records';
    $grp = isset($_GET['wbf_grp'], $groups[$_GET['wbf_grp']]) ? $_GET['wbf_grp'] : '';
    $q = isset($_GET['wbf_q']) ? trim(sanitize_text_field(wp_unslash($_GET['wbf_q']))) : '';
    $paged = max(1, (int) ($_GET['wbf_page'] ?? 1));
    $per = 20;
    $base = admin_url('admin.php?page=kop-woodbury-facts');

    echo '<div class="wrap kop-wbf"><h1>Woodbury Facts</h1>';
    if ($sync) {
        echo '<div class="notice notice-info"><p>Loaded a new build: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated']
            . ' updated, ' . (int) $sync['gone'] . ' no longer proposed.</p></div>';
    }
    if (!is_readable(kop_wbf_path())) {
        echo '<div class="notice notice-warning"><p>No facts uploaded yet. Run <code>python scripts/woodbury-facts.py</code> and copy '
            . '<code>facts.json</code> to <code>' . esc_html(dirname(kop_wbf_path())) . '</code>.</p></div>';
    }
    echo '<p>Everything the Woodbury Reports newsletter (2006-2014) says about a program that its record does not have yet: '
        . 'the people who worked there and where else they worked, incidents, when it opened or closed, past names, owners, moves, size and ages. '
        . 'Each item shows the words it comes from and links to the page of the issue.</p>'
        . '<ol class="kop-wbf-how"><li><strong>Read down a card.</strong> Items with a checked quote and a sure match start ticked. '
        . 'Untick anything wrong.</li>'
        . '<li><strong>Click <em>Add checked to record</em></strong> on the card, or <em>Add everything ticked on this page</em> at the top. '
        . 'The facility page shows the additions at once, each citing the issue; new staff, past operators and past names reach the network map on its next build.</li>'
        . '<li><strong>Changed your mind?</strong> The <em>Added</em> tab has Undo, which takes back exactly what was added.</li></ol>';
    $auto_done = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . " WHERE status = 'applied' AND reviewed_by = 'auto'");
    echo '<div class="notice notice-info inline"><p><strong>Plainly stated items are added for you.</strong> '
        . 'When the quote itself names the person and their role at the program, or states the year, size, ages, owner or past name word for word, '
        . 'and the program is a sure match, the item goes into the record without a click. Closures, incidents, consultant flags and programs with no record always wait for you. '
        . number_format($auto_done) . ' added automatically so far' . ($auto_now ? ' (' . (int) $auto_now . ' just now)' : '')
        . ($auto_left ? ', ' . number_format($auto_left) . ' more being added in the background' : '')
        . '. Check or undo them on the <em>Added automatically</em> tab; anything you undo stays manual.</p></div>';

    $where = $tabs[$tab]['where'];
    if ($grp !== '') {
        $where .= $wpdb->prepare(' AND grp = %s', $grp);
    }
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= $wpdb->prepare(' AND (program LIKE %s OR program_as_written LIKE %s OR label LIKE %s)', $like, $like, $like);
    }

    echo '<ul class="subsubsub">';
    $i = 0;
    foreach ($tabs as $k => $t) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . $t['where']);
        echo '<li><a href="' . esc_url(add_query_arg(array('wbf_tab' => $k), $base)) . '"' . ($tab === $k ? ' class="current"' : '') . '>'
            . esc_html($t['label']) . ' <span class="count">(' . $n . ')</span></a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="get" class="kop-wbf-filter"><input type="hidden" name="page" value="kop-woodbury-facts">'
        . '<input type="hidden" name="wbf_tab" value="' . esc_attr($tab) . '">'
        . '<input type="search" name="wbf_q" value="' . esc_attr($q) . '" placeholder="Program or person" style="width:240px"> '
        . '<select name="wbf_grp"><option value="">Every kind</option>';
    foreach ($groups as $k => $label) {
        echo '<option value="' . esc_attr($k) . '"' . selected($grp, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <button class="button">Show</button>'
        . ($q !== '' || $grp !== '' ? ' <a href="' . esc_url(add_query_arg('wbf_tab', $tab, $base)) . '">Clear</a>' : '') . '</form>';

    $card_col = in_array($tab, array('norecord', 'consultants'), true) ? 'program' : (in_array($tab, array('applied', 'auto'), true) ? 'applied_fid' : 'facility_id');
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$card_col}) FROM {$table} WHERE {$where}");
    $order = $tab === 'norecord' ? 'COUNT(*) DESC, program' : 'MIN(program)';
    $cards = $wpdb->get_col("SELECT {$card_col} FROM {$table} WHERE {$where} GROUP BY {$card_col} ORDER BY {$order} LIMIT "
        . (($paged - 1) * $per) . ", {$per}");
    if (!$cards) {
        echo '<p>Nothing here.</p></div>';
        return;
    }
    $in = implode(',', array_fill(0, count($cards), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} AND {$card_col} IN ({$in}) "
        . "ORDER BY FIELD(grp,'staff','incident','history','details'), issue_date, label", $cards), ARRAY_A);
    $by = array();
    foreach ($rows as $r) {
        $by[(string) $r[$card_col]][] = $r;
    }

    $pending = in_array($tab, array('records', 'norecord', 'consultants'), true);
    echo '<div class="kop-wbf-bar">';
    if ($tab === 'records') {
        echo '<button type="button" class="button button-primary kop-wbf-all">Add everything ticked on this page</button> ';
    }
    echo '<span class="kop-wbf-progress" aria-live="polite"></span></div>';

    foreach ($cards as $c) {
        if (!empty($by[(string) $c])) {
            kop_wbf_render_card($by[(string) $c], $tab);
        }
    }

    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p class="kop-wbf-pager">Page ' . $paged . ' of ' . $pages . ' (' . $total . ' ' . ($tab === 'norecord' ? 'programs' : 'facilities') . ') &middot; ';
        for ($n = 1; $n <= $pages; $n++) {
            $url = add_query_arg(array('wbf_tab' => $tab, 'wbf_page' => $n, 'wbf_q' => $q !== '' ? $q : null, 'wbf_grp' => $grp !== '' ? $grp : null), $base);
            echo $n === $paged ? '<strong>' . $n . '</strong> ' : '<a href="' . esc_url($url) . '">' . $n . '</a> ';
        }
        echo '</p>';
    }
    kop_wbf_render_assets();
    echo '</div>';
}

function kop_wbf_render_card(array $rows, $tab) {
    $first = $rows[0];
    $pending = in_array($tab, array('records', 'norecord', 'consultants'), true);
    $fid = in_array($tab, array('applied', 'auto'), true) ? (int) $first['applied_fid'] : (int) $first['facility_id'];
    echo '<div class="kop-wbf-card" data-fid="' . $fid . '">';
    echo '<div class="kop-wbf-head">';
    if ($fid) {
        $label = function_exists('kop_facility_finder_label') && function_exists('kop_closure_pdo') && kop_closure_pdo()
            ? kop_facility_finder_label(kop_closure_pdo(), $fid) : esc_html($first['program']);
        echo '<h2>' . $label . '</h2>';
        if ($first['match_kind'] !== 'exact' && $pending) {
            echo '<div class="kop-wbf-warn">Woodbury calls it &ldquo;' . esc_html($first['program_as_written'])
                . ($first['place'] !== '' ? ', ' . esc_html($first['place']) : '') . '&rdquo;. '
                . esc_html($first['match_note'] ?: 'Close name: check it is the same program.') . ' Items start unticked.</div>';
        }
    } else {
        echo '<h2>' . esc_html($first['program']) . ($first['place'] !== '' ? ' <span class="kop-wbf-muted">' . esc_html($first['place']) . '</span>' : '') . '</h2>';
    }
    echo '</div>';

    echo '<table class="widefat kop-wbf-table"><tbody>';
    $groups = kop_wbf_groups();
    $last = '';
    foreach ($rows as $r) {
        if ($r['grp'] !== $last) {
            echo '<tr class="kop-wbf-grp"><th colspan="3">' . esc_html($groups[$r['grp']] ?? $r['grp']) . '</th></tr>';
            $last = $r['grp'];
        }
        kop_wbf_render_row($r, $tab);
    }
    echo '</tbody></table>';

    echo '<div class="kop-wbf-actions">';
    if ($tab === 'records') {
        echo '<button type="button" class="button button-primary" data-act="apply">Add checked to record</button> '
            . '<button type="button" class="button" data-act="reject">Reject checked</button>';
    } elseif ($tab === 'consultants') {
        $v = kop_wbf_row_value($first);
        echo '<button type="button" class="button button-primary" data-act="consultant">Flag as former industry staff</button> '
            . '<button type="button" class="button" data-act="reject">Reject</button>'
            . '<div class="kop-wbf-muted">' . (!empty($v['referrer_id'])
                ? 'Adds these jobs to the Career History on the consultant record ' . esc_html($v['referrer_name']) . '.'
                : 'No consultant record yet: flagging creates one (filed under Educational Consultants) with this Career History.') . '</div>';
    } elseif ($tab === 'norecord') {
        $alts = json_decode((string) $first['alternatives'], true) ?: array();
        echo '<div class="kop-wbf-pick"><strong>Which record is it?</strong> ';
        foreach (array_slice($alts, 0, 3) as $a) {
            echo '<button type="button" class="button button-small kop-wbf-alt" data-fid="' . (int) $a['id'] . '">' . esc_html($a['name'] . ($a['state'] ? ' (' . $a['state'] . ')' : '')) . '</button> ';
        }
        echo kop_facility_finder_field('', '', ' class="kop-wbf-fid"') . ' <button type="button" class="button button-primary" data-act="apply">Add checked to that record</button></div>';
        $state = '';
        $city = '';
        if (preg_match('/^(.*?),\s*([A-Za-z .]+)$/', $first['place'], $m)) {
            $city = trim($m[1]);
            $state = trim($m[2]);
        } elseif ($first['place'] !== '') {
            $state = $first['place'];
        }
        echo '<details class="kop-wbf-create"><summary>No record yet? Create one</summary>'
            . '<label>Name <input type="text" class="kop-wbf-cname" value="' . esc_attr($first['program']) . '" style="width:260px"></label> '
            . '<label>City <input type="text" class="kop-wbf-ccity" value="' . esc_attr($city) . '" style="width:140px"></label> '
            . '<label>State <input type="text" class="kop-wbf-cstate" value="' . esc_attr($state) . '" style="width:90px"></label> '
            . '<label>or country <input type="text" class="kop-wbf-ccountry" style="width:110px"></label> '
            . '<label><input type="checkbox" class="kop-wbf-cforce"> It is a different place from a close match</label> '
            . '<button type="button" class="button" data-act="create">Create the record and add checked</button></details>'
            . ' <button type="button" class="button" data-act="reject">Reject checked</button>';
    } elseif (in_array($tab, array('applied', 'auto'), true)) {
        echo '<button type="button" class="button" data-act="undo">Undo checked</button>';
    } else {
        echo '<button type="button" class="button" data-act="undo">Put checked back to review</button>';
    }
    echo ' <span class="kop-wbf-result" aria-live="polite"></span></div>';
    echo '</div>';
}

function kop_wbf_render_row(array $r, $tab) {
    $pending = in_array($tab, array('records', 'norecord', 'consultants'), true);
    $checked = $pending ? ($r['preselect'] && in_array($tab, array('records', 'consultants'), true)) : false;
    $extra = json_decode((string) $r['extra'], true) ?: array();
    echo '<tr data-key="' . esc_attr($r['pkey']) . '">';
    echo '<td class="kop-wbf-check"><input type="checkbox" class="kop-wbf-pick"' . ($checked ? ' checked' : '') . ' aria-label="Select"></td>';
    echo '<td class="kop-wbf-what"><strong>' . esc_html($r['label']) . '</strong>'
        . '<div class="kop-wbf-muted">Goes to: ' . esc_html(kop_wbf_where_it_goes($r)) . '</div>';
    if ($r['conflict'] !== '') {
        echo '<div class="kop-wbf-warn">' . esc_html($r['conflict']) . '</div>';
    }
    if ($r['current_val'] !== '') {
        echo '<div class="kop-wbf-muted">On the record now: ' . esc_html($r['current_val']) . '</div>';
    }
    if (!empty($extra['career']) && count($extra['career']) > 1) {
        echo '<div class="kop-wbf-career"><span class="kop-wbf-muted">Where Woodbury places ' . esc_html($extra['person'] ?? 'them') . ':</span><ul>';
        foreach ($extra['career'] as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul></div>';
    }
    if ($r['status'] === 'rejected') {
        $why = json_decode((string) $r['applied'], true);
        if (!empty($why['reason'])) {
            echo '<div class="kop-wbf-muted">Skipped: ' . esc_html($why['reason']) . '</div>';
        }
    }
    echo '</td><td class="kop-wbf-ev">';
    $ev = kop_wbf_evidence($r);
    foreach (array_slice($ev, 0, 3) as $e) {
        echo '<div class="kop-wbf-src"><a href="' . esc_url($e['url']) . '" target="_blank" rel="noopener">' . esc_html($e['label'] . ', p. ' . (int) $e['page']) . '</a>';
        if ($e['quote'] !== '') {
            echo '<blockquote class="' . ($e['found'] ? '' : 'kop-wbf-unfound') . '">' . esc_html($e['quote']) . '</blockquote>';
        }
        if (!$e['found']) {
            echo '<div class="kop-wbf-warn">These words were not found in the issue text: check the page before adding.</div>';
        }
        echo '</div>';
    }
    if (count($ev) > 3) {
        echo '<details><summary>' . (count($ev) - 3) . ' more</summary>';
        foreach (array_slice($ev, 3) as $e) {
            echo '<div class="kop-wbf-src"><a href="' . esc_url($e['url']) . '" target="_blank" rel="noopener">' . esc_html($e['label'] . ', p. ' . (int) $e['page']) . '</a>'
                . ($e['quote'] !== '' ? '<blockquote>' . esc_html($e['quote']) . '</blockquote>' : '') . '</div>';
        }
        echo '</details>';
    }
    echo '</td></tr>';
}

function kop_wbf_render_assets() {
    $nonce = wp_create_nonce('kop_woodbury_facts');
    ?>
    <style>
        .kop-wbf-how { margin-left: 20px; max-width: 900px; }
        .kop-wbf-filter { margin: 8px 0 12px; }
        .kop-wbf-bar { position: sticky; top: 32px; z-index: 5; background: #f0f0f1; padding: 8px 0; }
        .kop-wbf-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #33A7B5; margin: 0 0 18px; padding: 10px 14px; max-width: 1400px; }
        .kop-wbf-card.kop-wbf-done { border-left-color: #B2E102; opacity: .75; }
        .kop-wbf-head h2 { margin: 4px 0 6px; font-size: 1.25em; }
        .kop-wbf-table td { vertical-align: top; }
        .kop-wbf-grp th { background: #F2EEDF; font-weight: 600; padding: 4px 8px; }
        .kop-wbf-check { width: 28px; }
        .kop-wbf-what { width: 40%; }
        .kop-wbf-ev blockquote { margin: 2px 0 6px; padding-left: 8px; border-left: 3px solid #33A7B5; color: #1d2327; }
        .kop-wbf-ev blockquote.kop-wbf-unfound { border-left-color: #d63638; }
        .kop-wbf-muted { color: #646970; font-size: 12px; }
        .kop-wbf-warn { color: #9a4a00; font-size: 12px; margin-top: 2px; }
        .kop-wbf-career ul { margin: 2px 0 0 18px; list-style: disc; font-size: 12px; }
        .kop-wbf-actions { margin-top: 8px; }
        .kop-wbf-pick, .kop-wbf-create { margin-bottom: 6px; }
        .kop-wbf-create label { margin-right: 6px; }
        .kop-wbf-result.ok { color: #007017; }
        .kop-wbf-result.err { color: #d63638; }
        tr.kop-wbf-gone td { opacity: .45; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        function post(data) {
            var body = new URLSearchParams();
            body.append('action', 'kop_wbf_act');
            body.append('nonce', nonce);
            Object.keys(data).forEach(function (k) {
                if (Array.isArray(data[k])) data[k].forEach(function (v) { body.append(k + '[]', v); });
                else if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]);
            });
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
        }

        function run(card, act) {
            var keys = Array.prototype.map.call(card.querySelectorAll('tr[data-key]'), function (tr) {
                return tr.querySelector('.kop-wbf-pick').checked && !tr.classList.contains('kop-wbf-gone') ? tr.dataset.key : null;
            }).filter(Boolean);
            var out = card.querySelector('.kop-wbf-result');
            if (!keys.length) { out.className = 'kop-wbf-result err'; out.textContent = 'Tick at least one item.'; return Promise.resolve(); }
            var data = { act: act, keys: keys, fid: card.dataset.fid || '' };
            var box = card.querySelector('.kop-wbf-fid');
            if (act === 'apply' && box && box.value) data.fid = box.value;
            if (act === 'apply' && !(+data.fid)) { out.className = 'kop-wbf-result err'; out.textContent = 'Pick the record first.'; return Promise.resolve(); }
            if (act === 'create') {
                data.name = card.querySelector('.kop-wbf-cname').value;
                data.city = card.querySelector('.kop-wbf-ccity').value;
                data.state = card.querySelector('.kop-wbf-cstate').value;
                data.country = card.querySelector('.kop-wbf-ccountry').value;
                data.force = card.querySelector('.kop-wbf-cforce').checked ? 1 : '';
            }
            out.className = 'kop-wbf-result'; out.textContent = 'Working...';
            return post(data).then(function (res) {
                if (!res.success) { out.className = 'kop-wbf-result err'; out.textContent = res.data || 'Failed.'; return; }
                var ok = 0, skipped = [];
                Object.keys(res.data.results || {}).forEach(function (k) {
                    var r = res.data.results[k];
                    var tr = card.querySelector('tr[data-key="' + k + '"]');
                    if (r.ok) { ok++; if (tr) tr.classList.add('kop-wbf-gone'); }
                    else { skipped.push(r.error); if (tr) tr.classList.add('kop-wbf-gone'); }
                });
                var words = { apply: 'Added ' + ok + ' to ', create: 'Created the record and added ' + ok + ' to ', reject: 'Rejected ' + ok + '.', undo: 'Undone: ' + ok + '.',
                    consultant: 'Flagged as former industry staff; the jobs are in their Career History on the consultants directory.' }[act];
                if (act === 'consultant') res.data.label = '';
                out.className = 'kop-wbf-result ok';
                out.textContent = words + (res.data.label ? res.data.label + '.' : '') + (skipped.length ? ' ' + skipped.length + ' already on the record, skipped.' : '');
                if (res.data.url) {
                    var a = document.createElement('a'); a.href = res.data.url; a.target = '_blank'; a.textContent = ' Open its page';
                    out.appendChild(a);
                }
                if (!card.querySelector('tr[data-key]:not(.kop-wbf-gone)')) card.classList.add('kop-wbf-done');
            }).catch(function () { out.className = 'kop-wbf-result err'; out.textContent = 'Network error; try again.'; });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.kop-wbf-card [data-act]');
            if (btn) { run(btn.closest('.kop-wbf-card'), btn.dataset.act); return; }
            var alt = e.target.closest('.kop-wbf-alt');
            if (alt) {
                var card = alt.closest('.kop-wbf-card');
                var box = card.querySelector('.kop-wbf-fid');
                if (box) { box.value = alt.dataset.fid; box.dispatchEvent(new Event('change', { bubbles: true })); }
                card.querySelectorAll('.kop-wbf-alt').forEach(function (b) { b.classList.toggle('button-primary', b === alt); });
                return;
            }
            if (e.target.closest('.kop-wbf-all')) {
                var cards = Array.prototype.slice.call(document.querySelectorAll('.kop-wbf-card:not(.kop-wbf-done)'));
                var prog = document.querySelector('.kop-wbf-progress');
                var i = 0;
                (function next() {
                    if (i >= cards.length) { prog.textContent = 'Done: ' + cards.length + ' cards.'; return; }
                    prog.textContent = 'Adding ' + (i + 1) + ' of ' + cards.length + '...';
                    var c = cards[i++];
                    var any = c.querySelector('tr[data-key]:not(.kop-wbf-gone) .kop-wbf-pick:checked');
                    (any ? run(c, 'apply') : Promise.resolve()).then(next);
                })();
            }
        });
    })();
    </script>
    <?php
}
