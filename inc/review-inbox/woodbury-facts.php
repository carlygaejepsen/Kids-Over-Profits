<?php
/**
 * Review inbox source: what the Woodbury Reports newsletter (and HEAL's and
 * the wiki's pages) say about each program, not yet on its record
 * (inc/woodbury-facts.php). Add goes through kop_wbf_apply(); a program with
 * no record can be pointed at one or created; young adult programs are filed
 * with kop_wbf_ya_file(); consultants are flagged with
 * kop_wbf_consultant_apply(); corrections use kop_wbf_edit() and
 * kop_wbf_edit_reset(); Undo is kop_wbf_undo().
 *
 * Everything the old screen (KOP Tools > Woodbury Facts) does is here too:
 * the tabs with counts and the "kind" filter, items with a checked quote and
 * a sure match ticked to start with, the build's other close names as
 * one-click "Add to" buttons, a new program record with its type and "a
 * different place" box, "Another person in these words"
 * (kop_wbf_add_person()), every quote and the whole career, loading the build
 * again and adding the plainly stated items now (kop_wbf_auto_run()).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('woodbury-facts', function () {
    if (!function_exists('kop_wbf_apply')) return null;
    $views = array('pending' => 'Waiting');
    foreach (kop_wbf_tabs() as $k => $t) $views[$k] = $t['label'];
    return array(
        'label'    => 'Woodbury Facts',
        'group'    => 'Imports to review',
        'help'     => 'What the Woodbury Reports newsletter says about a program that its record does not have yet: staff, incidents, openings, closings, names, owners, size and ages. '
            . 'Each item shows the words it comes from and links to the issue page. Add puts it on the record citing the page; Undo takes back exactly that. '
            . 'Read wrong? "Edit details" fixes the name, role, year or text before you add it. The words name someone the item missed? Use "Add this person". '
            . 'Wrong program? Pick another record beside Add, or create one. Items with a checked quote and a sure match start ticked. '
            . 'Plainly stated items (the quote names the person and role, or states the year, size, ages, owner or past name word for word, at a sure match) are added for you every hour: '
            . 'check or undo them on "Added automatically"; anything you undo stays manual. Closures, incidents, consultant flags and programs with no record always wait for you.'
            . (is_readable(kop_wbf_path()) ? '' : ' No facts uploaded yet: run python scripts/woodbury-facts.py and copy facts.json to ' . dirname(kop_wbf_path()) . '.'),
        'views'    => $views,
        'filters'  => array(array('name' => 'grp', 'label' => 'Kind', 'options' => kop_wbf_groups())),
        'view_counts' => 'kop_rinbox_wbf_view_counts',
        'tools'    => array(
            array('id' => 'auto', 'label' => 'Add the plainly stated items now', 'style' => 'neutral',
                'help' => 'Adds the items the build marked as plainly stated, for up to half a minute (the hourly run does the rest). They land on "Added automatically", each with Undo.'),
            array('id' => 'resync', 'label' => 'Load the facts file again', 'style' => 'neutral',
                'help' => 'Reads the uploaded facts.json now, even if it looks unchanged: new items are added, waiting ones take the latest reading.'),
        ),
        'tool'     => 'kop_rinbox_wbf_tool',
        'tool_url' => admin_url('admin.php?page=kop-woodbury-facts'),
        'count'    => function () {
            global $wpdb;
            kop_wbf_ensure_table();
            // Waiting, less what is on file or in conflict (kop_rinbox_wbf_where()).
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . ' WHERE ' . kop_rinbox_wbf_where('pending', array()));
        },
        'list'     => 'kop_rinbox_wbf_list',
        'on_file'  => 'kop_rinbox_wbf_on_file',
        'conflicts' => 'kop_rinbox_wbf_conflicts',
        'resolve'  => function ($key, $how, $typed) {
            return kop_wbf_resolve(kop_rinbox_wbf_row($key), $how, $typed, kop_rinbox_reviewer());
        },
        // One new record for several waiting items (the Conflicts section's roll-up cards).
        'create_many' => function (array $keys, array $params) {
            $rows = array_values(array_filter(kop_wbf_rows($keys), function ($r) { return $r['status'] === 'pending'; }));
            if (!$rows) throw new RuntimeException('Those items were already handled. Reload the list.');
            return kop_rinbox_wbf_create_for($rows, $params);
        },
        'on_file_in_list' => true,
        'get'      => function ($key) {
            $rows = kop_wbf_rows(array($key));
            return $rows ? kop_rinbox_wbf_item($rows[0]) : null;
        },
        'act'      => 'kop_rinbox_wbf_act',
        'save'     => 'kop_rinbox_wbf_save',
    );
});

function kop_rinbox_wbf_list(array $q) {
    global $wpdb;
    kop_wbf_ensure_table();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_wbf_synced')) {
        // A new facts.json is loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_wbf_synced', 1, MINUTE_IN_SECONDS);
        kop_wbf_sync();
        // Items of a record moved to the young adult programs go to its young adult tab (the load writes the old ids back).
        kop_wbf_moved_to_ya();
    }
    $where = kop_rinbox_wbf_where($q['view'], $q);
    $table = kop_wbf_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('applied', 'auto', 'rejected'), true)
        ? 'reviewed_at DESC, id DESC'
        : "facility_id = 0, program, facility_id, CASE grp WHEN 'staff' THEN 0 WHEN 'incident' THEN 1 WHEN 'history' THEN 2 WHEN 'details' THEN 3 ELSE 4 END, issue_date, label, id";
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_wbf_item', $rows), 'total' => $total);
}

/** A view's rows under the kind filter and the search (program or person). */
function kop_rinbox_wbf_where($view, array $q) {
    global $wpdb;
    $tabs = kop_wbf_tabs();
    $where = $tabs[$view]['where'] ?? "status = 'pending'";
    // What the record holds already waits under "Already on file" (kop_rinbox_wbf_on_file()), conflicts in the Conflicts section.
    if (strpos($where, "status = 'pending'") === 0) {
        $where .= kop_on_file_not_in('pkey', array_merge(array_keys(kop_rinbox_on_file_keys('woodbury-facts')), array_keys(kop_rinbox_conflict_keys('woodbury-facts'))));
    }
    if (!empty($q['filters']['grp'])) $where .= $wpdb->prepare(' AND grp = %s', $q['filters']['grp']);
    if (($q['search'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (program LIKE %s OR program_as_written LIKE %s OR label LIKE %s)', $like, $like, $like);
    }
    return $where;
}

function kop_rinbox_wbf_view_counts(array $q) {
    global $wpdb;
    $out = array();
    foreach (array_merge(array('pending'), array_keys(kop_wbf_tabs())) as $v) {
        $out[$v] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . ' WHERE ' . kop_rinbox_wbf_where($v, $q));
    }
    return $out;
}

function kop_rinbox_wbf_tool($id, array $params) {
    global $wpdb;
    if ($id === 'auto') {
        $n = kop_wbf_auto_run(25);
        $table = kop_wbf_table();
        $done = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'applied' AND reviewed_by = 'auto'");
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND auto = 1 AND facility_id > 0");
        return array('message' => 'Added ' . $n . ' just now; ' . number_format($done) . ' added automatically so far'
            . ($left ? ', ' . number_format($left) . ' more to go (the hourly run carries on, or click again)' : '') . '.');
    }
    if ($id === 'resync') {
        $sync = kop_wbf_sync(true);
        delete_transient('kop_rinbox_wbf_synced');
        if (!$sync) return array('message' => is_readable(kop_wbf_path()) ? 'The facts file could not be read.' : 'No facts file is uploaded yet.');
        return array('message' => 'Loaded the facts file: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated'] . ' updated, ' . (int) $sync['gone'] . ' no longer proposed.');
    }
    throw new RuntimeException('Unknown tool.');
}

/** City and state from Woodbury's "Kalispell, MT", as the old screen splits it. */
function kop_rinbox_wbf_place($place) {
    if (preg_match('/^(.*?),\s*([A-Za-z .]+)$/', (string) $place, $m)) return array(trim($m[1]), trim($m[2]));
    return array('', (string) $place);
}

/** The edit form's fields (kop_wbf_edit_fields) in the inbox's shape. */
function kop_rinbox_wbf_fields(array $r) {
    $out = array();
    $types = array('num' => 'number', 'area' => 'textarea', 'text' => 'text', 'select' => 'select');
    foreach (kop_wbf_edit_fields($r) as $fd) {
        $f = array('name' => $fd[0], 'label' => $fd[1], 'type' => $types[$fd[2]] ?? 'text', 'value' => (string) $fd[3]);
        if ($fd[2] === 'select') {
            $opts = array();
            foreach ((array) ($fd[4] ?? array()) as $k => $v) $opts[is_int($k) ? (string) $v : (string) $k] = (string) $v;
            if ($f['value'] !== '' && !isset($opts[$f['value']])) $opts = array($f['value'] => $f['value']) + $opts;
            $f['options'] = $opts ?: array($f['value'] => $f['value']);
            // Administrators or notable staff: the one choice that sorts a staff item.
            if ($fd[0] === 'where') $f['category'] = true;
        }
        if ($r['status'] !== 'pending') $f['readonly'] = true;
        $out[] = $f;
    }
    return $out;
}

function kop_rinbox_wbf_item(array $r) {
    $groups = kop_wbf_groups();
    $extra = json_decode((string) $r['extra'], true) ?: array();
    $ev = kop_wbf_evidence($r);
    $pending = $r['status'] === 'pending';
    $fid = $r['status'] === 'applied' ? (int) $r['applied_fid'] : (int) $r['facility_id'];
    $ya = !empty($r['ya']);

    $lines = array();
    if ($pending && (int) $r['facility_id'] > 0 && $r['match_kind'] !== 'exact') {
        $lines[] = 'Woodbury calls it "' . $r['program_as_written'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') . '". '
            . ($r['match_note'] ?: 'Close name: check it is the same program.');
    }
    if ($ya && $pending && !empty($r['ya_why'])) $lines[] = 'Young adult program: ' . $r['ya_why'];
    foreach ($ev as $e) {
        $line = kop_wbf_ev_label($e) . (!empty($e['quote']) ? ': "' . kop_rinbox_excerpt($e['quote'], 700) . '"' : '');
        if (empty($e['found'])) $line .= ' (these words were not found in the ' . (!empty($e['pub']) ? 'archived page' : 'issue text') . ': check the page before adding)';
        $lines[] = $line;
    }
    // Conflicts (the build's, and the record as it is now) are the card's Conflict mark, not a line here.
    $conflict = $pending ? kop_rinbox_wbf_conflict($r) : '';
    if (!empty($extra['career']) && count($extra['career']) > 1) {
        $lines[] = 'Where Woodbury places ' . ($extra['person'] ?? 'them') . ': ' . implode('; ', (array) $extra['career']);
    }
    if ($pending && $r['grp'] === 'consultant') {
        $cv = kop_wbf_row_value($r);
        $lines[] = !empty($cv['referrer_id'])
            ? 'Flagging adds these jobs to the Career History on the consultant record ' . ($cv['referrer_name'] ?? '') . '.'
            : 'No consultant record yet: flagging creates one (filed under Educational Consultants) with this Career History.';
    }
    $alts = array();
    foreach (array_slice(json_decode((string) $r['alternatives'], true) ?: array(), 0, 3) as $a) {
        if ((int) ($a['id'] ?? 0) > 0 && (int) $a['id'] !== (int) $r['facility_id']) $alts[(int) $a['id']] = $a;
    }
    if (isset($extra['original'])) $lines[] = 'Corrected by ' . ($extra['edited_by'] ?? 'a reviewer') . '.';
    if (!empty($extra['manual'])) $lines[] = 'Added by hand by ' . ($extra['edited_by'] ?? '') . '.';
    if ($r['status'] === 'applied' && kop_wbf_filed_on($r) !== '') {
        $done = json_decode((string) $r['applied'], true);
        $lines[] = (($done['filed'] ?? '') === 'young_adult' ? 'Added to ' : 'Filed in the notes of ') . kop_wbf_filed_on($r) . '.';
    }
    if ($r['status'] === 'rejected') {
        $why = json_decode((string) $r['applied'], true);
        if (!empty($why['reason'])) $lines[] = 'Skipped: ' . $why['reason'];
    }

    $links = array();
    foreach ($ev as $e) {
        if (!empty($e['url'])) $links[] = array('label' => kop_wbf_ev_label($e), 'url' => (string) $e['url']);
    }
    if ($ya) $links[] = array('label' => 'Young Adult Programs list', 'url' => admin_url('admin.php?page=kop-young-adult-programs'));

    list($city, $state) = kop_rinbox_wbf_place($r['place']);
    $actions = array();
    $goes = kop_wbf_where_it_goes($r);
    if ($pending && $r['grp'] === 'consultant') {
        $v = kop_wbf_row_value($r);
        $actions[] = array('id' => 'consultant', 'label' => !empty($v['referrer_id']) ? 'Flag as former industry staff' : 'Flag (creates their consultant record)', 'style' => 'approve',
            'help' => (!empty($v['referrer_id'])
                ? 'Flags the consultant record of ' . ($v['referrer_name'] ?? ($v['name'] ?? 'this person')) . ' as former industry staff'
                : 'Creates a consultant record for ' . ($v['name'] ?? 'this person') . ' under Educational Consultants, flagged as former industry staff,')
                . ' and adds these jobs to its Career History, citing Woodbury.');
    } elseif ($pending && $ya && (int) $r['facility_id'] === 0) {
        $programs = array();
        $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
        foreach ($pdo && function_exists('kop_ya_all') ? kop_ya_all($pdo, null) : array() as $p) {
            $programs[(string) $p['id']] = $p['name'] . ($p['state'] !== '' ? ' (' . $p['state'] . ')' : '');
        }
        if ($programs) {
            // A moved record names its program (kop_wbf_moved_to_ya()); else the program by name.
            $match = preg_match('/young adult program #(\d+)/', (string) ($r['ya_why'] ?? ''), $mm) && $pdo ? kop_ya_get($pdo, (int) $mm[1]) : null;
            $match = $match ?: ($pdo && function_exists('kop_ya_find_by_name') ? (kop_ya_find_by_name($pdo, $r['program']) ?: kop_ya_find_by_name($pdo, $r['program_as_written'])) : null);
            $actions[] = array('id' => 'ya_apply', 'label' => 'Add to that young adult program', 'style' => 'approve',
                'help' => 'Adds this item to the young adult program you pick, on the Young Adult Programs page, citing the Woodbury page.',
                'params' => array(
                array('name' => 'ya', 'label' => 'Young adult program', 'type' => 'select', 'options' => $programs, 'value' => $match ? (string) $match['id'] : ''),
            ));
        }
        $why = (string) ($r['ya_why'] ?? '');
        $actions[] = array('id' => 'ya_create', 'label' => 'Create the young adult program and add this', 'style' => $programs ? 'neutral' : 'approve',
            'help' => 'Makes a new young adult program named ' . $r['program'] . ' (listed on the Young Adult Programs page at once) and adds this item to it, citing the Woodbury page.',
            'params' => array(
            array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $r['program']),
            array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $city),
            array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => $state),
            array('name' => 'country', 'label' => 'or country', 'type' => 'text', 'value' => ''),
            array('name' => 'ages', 'label' => 'Ages', 'type' => 'text', 'value' => preg_match('/^Ages:\s*(.*)$/', $why, $m) ? $m[1] : ''),
            array('name' => 'program_type', 'label' => 'Described as', 'type' => 'text', 'optional' => true,
                'value' => $why !== '' && !preg_match('/^(Ages|Name):/', $why) ? mb_substr($why, 0, 255) : ''),
        ));
        $actions[] = array('id' => 'ya_off', 'label' => 'Not a young adult program', 'style' => 'neutral',
            'help' => 'Nothing on the site changes; every waiting item of ' . $r['program'] . ' moves to "Programs with no record".');
    } elseif ($pending) {
        $actions[] = array('id' => 'apply', 'label' => (int) $r['facility_id'] > 0 ? 'Add to the record' : 'Add to this record', 'style' => 'approve',
            'help' => 'Adds this to ' . ((int) $r['facility_id'] > 0 ? kop_rinbox_wbf_name($r['facility_id']) : 'the record you pick') . ' under ' . $goes . ', citing the Woodbury page.',
            'params' => array(
            array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => (int) $r['facility_id']),
        ));
        // The build's other close names, one click each (the old screen's buttons under "Another record").
        foreach ($alts as $aid => $a) {
            $actions[] = array('id' => 'apply_to_' . $aid, 'label' => 'Add to ' . ($a['name'] ?? '#' . $aid) . (!empty($a['state']) ? ' (' . $a['state'] . ')' : ''), 'style' => 'neutral',
                'help' => 'Adds this to ' . ($a['name'] ?? 'record #' . $aid) . ' instead, under ' . $goes . ', citing the Woodbury page.');
        }
        // Any waiting item can go on a new record (the old screen's "Wrong program?").
        $kinds = kop_wbf_create_kinds();
        $types = array('' => 'Not sure');
        foreach (function_exists('kop_facdisc_types') ? kop_facdisc_types() : array() as $t) $types[$t] = $t;
        $name = (int) $r['facility_id'] === 0 || $r['program_as_written'] === '' ? $r['program'] : $r['program_as_written'];
        $actions[] = array('id' => 'create', 'label' => 'Create the record and add this', 'style' => 'neutral',
            'help' => 'Makes a new record with the name and place below (a facility gets its own facility page) and adds this item to it.',
            'confirm' => 'Create a new record with this name and add the item to it?', 'params' => array(
            array('name' => 'kind', 'label' => 'What is it?', 'type' => 'select', 'options' => $kinds, 'value' => 'facility'),
            array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $name),
            array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $city),
            array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => $state),
            array('name' => 'country', 'label' => 'or country', 'type' => 'text', 'value' => ''),
            array('name' => 'type', 'label' => 'Program type', 'type' => 'select', 'options' => $types, 'value' => '', 'optional' => true),
            array('name' => 'force', 'label' => 'It is a different place from a close match', 'type' => 'checkbox', 'value' => '', 'optional' => true),
        ));
        if ((int) $r['facility_id'] === 0) {
            $actions[] = array('id' => 'ya_on', 'label' => 'It is a young adult program (18+)', 'style' => 'neutral',
                'help' => 'Nothing on the site changes; every waiting item of ' . $r['program'] . ' moves to the young adult tab.');
        }
    }
    if ($pending) {
        if ($r['grp'] !== 'consultant' && $ev) {
            // "Another person in these words": a new waiting staff item citing the same page.
            $actions[] = array('id' => 'add_person', 'label' => 'Add this person', 'style' => 'neutral',
                'help' => 'Makes a new waiting item for the person you name, citing the same page; nothing is added to a record until that item is approved.',
                'params' => array(
                array('name' => 'name', 'label' => 'Another person in these words: name', 'type' => 'text', 'value' => ''),
                array('name' => 'role', 'label' => 'Role at this program', 'type' => 'text', 'value' => '', 'optional' => true),
                array('name' => 'pastJobs', 'label' => 'Past jobs', 'type' => 'text', 'value' => '', 'optional' => true),
                array('name' => 'where', 'label' => 'List', 'type' => 'select', 'value' => 'staff.notableStaff',
                    'options' => array('staff.notableStaff' => 'Notable staff', 'staff.administrator' => 'Administrators')),
            ));
        }
        if (isset($extra['original'])) $actions[] = array('id' => 'reset', 'label' => 'Back to what Woodbury said', 'style' => 'neutral',
            'help' => 'Drops the corrections made here; the item reads as Woodbury had it.');
        $actions[] = array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject',
            'help' => 'Nothing is added anywhere; the item moves to the Rejected tab.');
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Takes what this item added off the record and puts it back in the waiting list; a record it created stays.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'neutral',
            'help' => 'Nothing on the site changes; the item waits for review again.');
    }

    $statuses = array('pending' => 'Waiting', 'applied' => $r['reviewed_by'] === 'auto' ? 'Added automatically' : 'Added', 'rejected' => 'Rejected', 'gone' => 'No longer proposed');
    return array(
        'key'          => (string) $r['pkey'],
        'title'        => (string) $r['label'],
        'subtitle'     => implode(' · ', array_filter(array(
            $groups[$r['grp']] ?? $r['grp'],
            'Goes to: ' . kop_wbf_where_it_goes($r),
            $fid > 0 ? '' : $r['program'] . ($r['place'] !== '' ? ' (' . $r['place'] . ')' : ''),
            (string) $r['issue_date'],
        ))),
        'url'          => (string) ($ev[0]['url'] ?? ''),
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => kop_rinbox_facility($fid),
        'fields'       => kop_rinbox_wbf_fields($r),
        'actions'      => $actions,
        'links'        => $links,
        'conflict'     => $conflict,
        // As the old screen: a checked quote and a sure match start ticked (records and consultants).
        'selected'     => $pending && !empty($r['preselect']) && ((int) $r['facility_id'] > 0 || $r['grp'] === 'consultant'),
    );
}

function kop_rinbox_wbf_row($key) {
    $rows = kop_wbf_rows(array($key));
    if (!$rows) throw new RuntimeException('That Woodbury item is gone.');
    return $rows[0];
}

/** "Name (City, ST)" of a facility, for messages. */
function kop_rinbox_wbf_name($fid) {
    $f = kop_rinbox_facility($fid);
    return $f ? $f['name'] : 'facility #' . (int) $fid;
}

function kop_rinbox_wbf_act($key, $action, array $params) {
    global $wpdb;
    $r = kop_rinbox_wbf_row($key);
    $user = kop_rinbox_reviewer();
    $p = function ($k) use ($params) { return trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) ($params[$k] ?? '')))); };
    $waiting = function () use ($r) {
        if ($r['status'] !== 'pending') throw new RuntimeException('This item was already handled (' . $r['status'] . '). Undo it first.');
    };
    $result = function (array $results, $done_msg) use ($r) {
        $res = $results[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing was done.');
        if (!empty($res['ok'])) return array('message' => $done_msg);
        if (!empty($res['already'])) return array('message' => 'Not added: ' . $res['error'] . ' It is now under Rejected.');
        throw new RuntimeException($res['error']);
    };
    if (preg_match('/^apply_to_(\d+)$/', $action, $m)) {
        // One of the build's other close names.
        $params = array('facility' => (int) $m[1]);
        $action = 'apply';
    }
    switch ($action) {
        case 'apply':
            $waiting();
            $fid = (int) ($params['facility'] ?? 0) ?: (int) $r['facility_id'];
            if ($fid > 0 && $fid !== (int) $r['facility_id']) {
                $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
                $st->execute(array($fid));
                if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
            }
            if ($fid <= 0) throw new RuntimeException('Pick the record first (the Record box beside the button).');
            return $result(kop_wbf_apply(array($r), $fid, $user),
                'Added to ' . kop_rinbox_wbf_name($fid) . ' (' . kop_wbf_where_it_goes($r) . '), citing ' . (kop_wbf_ev_label(kop_wbf_evidence($r)[0] ?? array('label' => 'Woodbury Reports', 'page' => 0))) . '. Undo is on the Added tab.');
        case 'create':
            $waiting();
            $made = kop_rinbox_wbf_create_for(array($r), $params);
            return $result($made['results'], $made['message']);
        case 'consultant':
            $waiting();
            if ($r['grp'] !== 'consultant') throw new RuntimeException('Not an educational consultant item.');
            $done = kop_wbf_consultant_apply($r);
            $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($done),
                'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            $v = kop_wbf_row_value($r);
            return array('message' => ($done['created'] ? 'Created a consultant record for ' : 'Flagged ') . ($v['name'] ?? 'them')
                . ' as former industry staff, with ' . (int) $done['added'] . ' job' . ((int) $done['added'] === 1 ? '' : 's') . ' added to their Career History. Undo is on the Added tab.');
        case 'ya_apply':
        case 'ya_create':
            $waiting();
            $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
            if (!$pdo) throw new RuntimeException('The records database is not reachable.');
            kop_ya_install($pdo);
            if ($action === 'ya_create') {
                $e = kop_wbf_evidence($r);
                $yid = kop_ya_save($pdo, array(
                    'name' => $p('name'), 'city' => $p('city'), 'state' => $p('state'), 'country' => $p('country'), 'ages' => $p('ages'),
                    'program_type' => $p('program_type'),
                    'source' => !empty($e[0]['cite']) ? (string) $e[0]['cite'] : 'Woodbury Reports' . ($e ? ', ' . $e[0]['label'] . ', p. ' . (int) $e[0]['page'] : ''),
                    'review' => 'approved',
                ), 0, $user);
            } else {
                $yid = (int) ($params['ya'] ?? 0);
            }
            $results = kop_wbf_ya_file($pdo, array($r), $yid, $user);
            $prog = kop_ya_get($pdo, $yid);
            return $result($results, 'Added to the young adult program "' . ($prog['name'] ?? '#' . $yid) . '", citing its page. Undo is on the Added tab.');
        case 'ya_on':
        case 'ya_off':
            $waiting();
            kop_wbf_ya_override($r['program'], $action === 'ya_on' ? 1 : 0);
            return array('message' => $action === 'ya_on'
                ? 'Every waiting item of "' . $r['program'] . '" is now on the young adult tab, and stays there after the next scan.'
                : 'Every waiting item of "' . $r['program'] . '" is back on "Programs with no record".');
        case 'add_person':
            $waiting();
            $f = array();
            foreach (array('name', 'role', 'pastJobs', 'where') as $k) $f[$k] = sanitize_textarea_field((string) ($params[$k] ?? ''));
            $new = kop_wbf_add_person($r, $f, $user);
            return array('message' => 'Added ' . $new['label'] . ' as a new waiting item citing the same page (search for the name to find it). Add it like any other.');
        case 'reset':
            kop_wbf_edit_reset($r);
            return array('message' => 'Back to what Woodbury said.');
        case 'reject':
            $waiting();
            $wpdb->update(kop_wbf_table(), array('status' => 'rejected', 'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            return array('message' => 'Rejected. Nothing was added; "Back to review" on the Rejected tab brings it back.');
        case 'undo':
            if (!in_array($r['status'], array('applied', 'rejected'), true)) throw new RuntimeException('Nothing to undo.');
            $was = $r['status'];
            kop_wbf_undo(array($r), $user);
            $now = kop_wbf_rows(array($r['pkey']))[0] ?? null;
            if (!$now || $now['status'] !== 'pending') throw new RuntimeException('Undo did not go through. Try again, or use the full screen.');
            return array('message' => $was === 'applied' ? 'Undone: what this item added is off the record again. It is waiting for review.' : 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_wbf_save($key, array $fields) {
    $r = kop_rinbox_wbf_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Only waiting items can be edited. Undo it first.');
    $form = kop_wbf_edit_fields($r);
    if (!$form) throw new RuntimeException('This item cannot be edited here.');
    // kop_wbf_edit() reads every field of the form: start from what the item says now.
    $f = array();
    foreach ($form as $fd) {
        $f[$fd[0]] = array_key_exists($fd[0], $fields) ? (is_array($fields[$fd[0]]) ? implode("\n", $fields[$fd[0]]) : (string) $fields[$fd[0]]) : (string) $fd[3];
    }
    $after = kop_wbf_edit($r, $f, kop_rinbox_reviewer());
    kop_rinbox_flush_counts();
    return array('message' => 'Saved. It now reads: ' . $after['label']);
}

/**
 * Waiting items whose record holds them already (someone added it by hand, or
 * from another source, since the build): what Add would refuse as "already on
 * the record", or would change nothing. A record holding a different value is
 * a conflict, not on file: it stays waiting. [pkey => {label, url}]
 */
function kop_rinbox_wbf_on_file() {
    kop_wbf_ensure_table();
    return kop_on_file_cached('wbf', kop_wbf_table(), function (PDO $pdo) {
        global $wpdb;
        $rows = (array) $wpdb->get_results('SELECT pkey, facility_id, op, path, value FROM ' . kop_wbf_table()
            . " WHERE status = 'pending' AND facility_id > 0 AND op IN ('add_staff', 'add_list', 'set_if_empty', 'set_closed')", ARRAY_A);
        $docs = kop_on_file_docs($pdo, array_column($rows, 'facility_id'));
        $out = array();
        foreach ($rows as $r) {
            $f = $docs[(int) $r['facility_id']] ?? null;
            if (!$f) continue;
            $why = kop_wbf_on_record($f['doc'], $r);
            // A different value is a conflict (the Conflicts section), never "on file".
            if ($why === '' || kop_wbf_conflict_parts($f['doc'], $r)) continue;
            $out[(string) $r['pkey']] = array('label' => $f['name'] . ': ' . $why,
                'url' => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $r['facility_id']) : '');
        }
        return $out;
    });
}

/** Why the record already holds this item ('' when it does not), by kop_wbf_doc_apply()'s rules, read only. */
function kop_wbf_on_record(array $doc, array $r) {
    $value = kop_wbf_row_value($r);
    $same = function ($a, $b) {
        if (is_array($a) || is_array($b)) {
            $a = (array) $a;
            $b = (array) $b;
            if (isset($a['raw'], $b['raw'])) return strcasecmp(trim($a['raw']), trim($b['raw'])) === 0;
            return ($a['min'] ?? null) == ($b['min'] ?? null) && ($a['max'] ?? null) == ($b['max'] ?? null)
                && (isset($a['min']) || isset($a['max']) || $a == $b);
        }
        return strcasecmp(trim((string) $a), trim((string) $b)) === 0;
    };
    switch ($r['op']) {
        case 'add_staff':
            $key = kop_wbf_person_key($value['name'] ?? '');
            if ($key === '') return '';
            foreach (array('staff.administrator', 'staff.notableStaff') as $path) {
                foreach ((array) kop_wbf_get($doc, $path) as $s) {
                    $s = is_array($s) ? $s : array('name' => (string) $s);
                    if (kop_wbf_person_key($s['name'] ?? '') !== $key) continue;
                    // Add fills only empty role and past jobs: on file when it would fill nothing.
                    foreach (array('role', 'pastJobs') as $k) {
                        if (trim((string) ($value[$k] ?? '')) !== '' && trim((string) ($s[$k] ?? '')) === '') return '';
                    }
                    return ($s['name'] ?? $value['name']) . ' is on its staff list' . (!empty($s['role']) ? ' (' . $s['role'] . ')' : '');
                }
            }
            return '';
        case 'add_list':
            $list = kop_wbf_get($doc, $r['path']);
            return is_array($list) && kop_wbf_list_has($list, $value)
                ? 'already listed (' . (is_array($value) ? (string) ($value['raw'] ?? wp_json_encode($value)) : mb_substr((string) $value, 0, 120)) . ')' : '';
        case 'set_if_empty':
            $slot = kop_wbf_get($doc, $r['path']);
            if ($slot === null || $slot === '' || (is_array($slot) && ($slot['min'] ?? null) === null && ($slot['max'] ?? null) === null)) return '';
            return $same($slot, $value) ? 'already says ' . (is_array($slot) ? wp_json_encode($slot) : $slot) : '';
        case 'set_closed':
            $status = (string) ($doc['operatingPeriod']['status'] ?? '');
            $end = $doc['operatingPeriod']['endYear'] ?? null;
            $want = $value['endYear'] ?? null;
            if (strcasecmp($status, 'Closed') !== 0) return '';
            if ($want && (int) $end !== (int) $want) return '';
            return 'already marked closed' . ($end ? ', ended ' . (int) $end : '');
    }
    return '';
}

/** Every conflict for a waiting item: the build's own note and what the record says now. */
function kop_rinbox_wbf_conflict(array $r) {
    $f = (int) $r['facility_id'] > 0 ? kop_on_file_doc($r['facility_id']) : null;
    return kop_rinbox_wbf_conflict_text($r, $f ? kop_wbf_conflict_parts($f['doc'], $r) : null);
}

/** The build's notes and the live conflict ($live: kop_wbf_conflict_parts()) as one line. */
function kop_rinbox_wbf_conflict_text(array $r, $live) {
    $out = array();
    if ((string) $r['conflict'] !== '') {
        // The build writes ranges as JSON ("Another issue gives {"min": 10, "max": 14}"): shown as words.
        $out[] = rtrim(preg_replace_callback('/\{[^{}]*\}/', function ($m) {
            $v = json_decode($m[0], true);
            return is_array($v) ? kop_wbf_show_value($v) : $m[0];
        }, (string) $r['conflict']), '.') . '.';
    }
    if ((string) $r['current_val'] !== '') $out[] = 'On the record now: ' . $r['current_val'] . '.';
    if ($live && !in_array($live['text'], $out, true)) $out[] = $live['text'];
    return implode(' ', $out);
}

/**
 * Waiting items in conflict, for the Conflicts section: the build's own notes
 * (another issue gives another value, the record's value at build time) and
 * the record as it is now (kop_wbf_conflict_parts()). Items the record already
 * holds are on file, not conflicts. [pkey => {text, record, item, what, title, facility_id}]
 */
function kop_rinbox_wbf_conflicts() {
    kop_wbf_ensure_table();
    return kop_on_file_cached('wbf_conflicts_v2', kop_wbf_table(), function (PDO $pdo) {
        global $wpdb;
        $rows = (array) $wpdb->get_results('SELECT * FROM ' . kop_wbf_table() . " WHERE status = 'pending' AND ((conflict IS NOT NULL AND conflict <> '')
            OR (current_val IS NOT NULL AND current_val <> '') OR (facility_id > 0 AND op IN ('set_if_empty', 'set_closed', 'add_staff')))", ARRAY_A);
        $docs = kop_on_file_docs($pdo, array_column($rows, 'facility_id'));
        $out = array();
        foreach ($rows as $r) {
            $doc = $docs[(int) $r['facility_id']]['doc'] ?? null;
            $live = $doc ? kop_wbf_conflict_parts($doc, $r) : null;
            $built = (string) $r['conflict'] !== '' || (string) $r['current_val'] !== '';
            if (!$live && !$built) continue;
            if (!$live && $doc && kop_wbf_on_record($doc, $r) !== '') continue;
            $text = kop_rinbox_wbf_conflict_text($r, $live);
            $out[(string) $r['pkey']] = array(
                'text' => $text,
                'record' => $live ? $live['record'] : (string) $r['current_val'],
                'item' => $live ? $live['item'] : kop_wbf_show_value(kop_wbf_row_value($r)),
                'what' => $live ? $live['what'] : kop_wbf_conflict_what($r),
                'title' => (string) $r['label'],
                'facility_id' => (int) $r['facility_id'],
                // No record yet: the program as Woodbury names it, so the Conflicts section can say which.
                'program' => (int) $r['facility_id'] > 0 ? '' : trim((string) $r['program'] . ((string) $r['place'] !== '' ? ' (' . $r['place'] . ')' : '')),
                'editable' => (int) $r['facility_id'] > 0 && in_array($r['op'], array('set_if_empty', 'set_closed', 'add_staff'), true),
            );
        }
        return $out;
    });
}

/**
 * "Create the record and add this" for one or more waiting items: the record
 * from the name and place in $params (kind facility, company, consultant firm
 * or person, provider, transporter), then every item added to it. A program
 * takes the items as record data (one that disagrees with another just added
 * keeps waiting, marked); the other kinds take them as notes.
 * Returns {message, results: [pkey => {ok, error?}], done: [pkeys added]}.
 */
function kop_rinbox_wbf_create_for(array $rows, array $params) {
    $user = kop_rinbox_reviewer();
    $p = function ($k) use ($params) { return trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) ($params[$k] ?? '')))); };
    $kind = sanitize_key((string) ($params['kind'] ?? 'facility')) ?: 'facility';
    $kinds = kop_wbf_create_kinds();
    if (!isset($kinds[$kind])) throw new RuntimeException('Choose what kind of record to create.');
    $f = array('name' => $p('name'), 'city' => $p('city'), 'country' => $p('country'));
    if ($f['name'] === '') throw new RuntimeException('A name is needed.');
    $n = count($rows);
    $these = $n === 1 ? 'this item' : 'the ' . $n . ' items';
    if ($kind !== 'facility') {
        $f['state'] = $p('state');
        $filed = kop_wbf_file_items($rows, $kind, $f, $user);
        $t = $filed['target'];
        return array('results' => $filed['results'], 'done' => array_keys($filed['results']),
            'message' => 'Created the ' . strtolower($kinds[$kind]) . ' record "' . $t['name'] . '" (#' . (int) $t['id'] . ') with ' . $these . ' in its notes. Undo puts '
                . ($n === 1 ? 'the item' : 'them') . ' back; the record stays.');
    }
    if (!function_exists('kop_wbc_create_facility')) throw new RuntimeException('Record creation is not available.');
    $raw_state = $p('state');
    $f['state'] = $raw_state !== '' ? (string) kop_facility_state_code($raw_state) : '';
    if ($raw_state !== '' && $f['state'] === '') {
        throw new RuntimeException('"' . $raw_state . '" is not a US state. Leave it empty and give the country instead.');
    }
    $f['type'] = $p('type');
    $f['force'] = !empty($params['force']) && $params['force'] !== '0';
    $target = kop_wbc_create_facility(kop_wbf_create_source(kop_wbf_evidence($rows[0])), $f, kop_closure_pdo());
    $fid = (int) $target['id'];
    $results = kop_wbf_apply($rows, $fid, $user);
    $done = array_keys(array_filter($results, function ($x) { return !empty($x['ok']); }));
    $left = array_filter($results, function ($x) { return !empty($x['conflict']); });
    $already = array_filter($results, function ($x) { return !empty($x['already']); });
    $msg = 'Created ' . kop_rinbox_wbf_name($fid) . ' and added ' . ($n === 1 ? 'this item' : count($done) . ' of the ' . $n . ' items') . ' to it'
        . ($left ? '; ' . count($left) . ' disagree' . (count($left) === 1 ? 's' : '') . ' with what was just added and still wait' . (count($left) === 1 ? 's' : '') . ' under Conflicts' : '')
        . ($already && $n > 1 ? '; ' . count($already) . ' said the same thing and went to Rejected' : '') . '. Undo is on the Added tab.';
    return array('results' => $results, 'done' => array_map('strval', $done), 'message' => $msg);
}
