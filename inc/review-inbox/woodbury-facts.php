<?php
/**
 * Review inbox source: what the Woodbury Reports newsletter (and HEAL's and
 * the wiki's pages) say about each program, not yet on its record
 * (inc/woodbury-facts.php). Add goes through kop_wbf_apply(); a program with
 * no record can be pointed at one or created; young adult programs are filed
 * with kop_wbf_ya_file(); consultants are flagged with
 * kop_wbf_consultant_apply(); corrections use kop_wbf_edit() and
 * kop_wbf_edit_reset(); Undo is kop_wbf_undo(). Adding a person the build
 * missed stays on the old screen (tool_url).
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
            . 'To add a person the item missed, use the full screen.',
        'views'    => $views,
        'tool_url' => admin_url('admin.php?page=kop-woodbury-facts'),
        'count'    => function () {
            global $wpdb;
            kop_wbf_ensure_table();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_wbf_list',
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
    }
    $tabs = kop_wbf_tabs();
    $where = $tabs[$q['view']]['where'] ?? "status = 'pending'";
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (program LIKE %s OR program_as_written LIKE %s OR label LIKE %s)', $like, $like, $like);
    }
    $table = kop_wbf_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('applied', 'auto', 'rejected'), true)
        ? 'reviewed_at DESC, id DESC'
        : "facility_id = 0, program, facility_id, CASE grp WHEN 'staff' THEN 0 WHEN 'incident' THEN 1 WHEN 'history' THEN 2 WHEN 'details' THEN 3 ELSE 4 END, issue_date, label, id";
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_wbf_item', $rows), 'total' => $total);
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
    foreach (array_slice($ev, 0, 2) as $e) {
        $line = kop_wbf_ev_label($e) . (!empty($e['quote']) ? ': "' . kop_rinbox_excerpt($e['quote'], 500) . '"' : '');
        if (empty($e['found'])) $line .= ' (these words were not found in the ' . (!empty($e['pub']) ? 'archived page' : 'issue text') . ': check the page before adding)';
        $lines[] = $line;
    }
    if (count($ev) > 2) $lines[] = (count($ev) - 2) . ' more source' . (count($ev) > 3 ? 's' : '') . ' on the full screen.';
    if ((string) $r['conflict'] !== '') $lines[] = $r['conflict'];
    if ((string) $r['current_val'] !== '') $lines[] = 'On the record now: ' . $r['current_val'];
    if (!empty($extra['career']) && count($extra['career']) > 1) {
        $lines[] = 'Where Woodbury places ' . ($extra['person'] ?? 'them') . ': ' . implode('; ', array_slice((array) $extra['career'], 0, 8));
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
    foreach (array_slice($ev, 0, 3) as $e) {
        if (!empty($e['url'])) $links[] = array('label' => kop_wbf_ev_label($e), 'url' => (string) $e['url']);
    }

    list($city, $state) = kop_rinbox_wbf_place($r['place']);
    $actions = array();
    if ($pending && $r['grp'] === 'consultant') {
        $v = kop_wbf_row_value($r);
        $actions[] = array('id' => 'consultant', 'label' => !empty($v['referrer_id']) ? 'Flag as former industry staff' : 'Flag (creates their consultant record)', 'style' => 'approve');
    } elseif ($pending && $ya && (int) $r['facility_id'] === 0) {
        $programs = array();
        $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
        foreach ($pdo && function_exists('kop_ya_all') ? kop_ya_all($pdo, null) : array() as $p) {
            $programs[(string) $p['id']] = $p['name'] . ($p['state'] !== '' ? ' (' . $p['state'] . ')' : '');
        }
        if ($programs) {
            $match = $pdo && function_exists('kop_ya_find_by_name') ? (kop_ya_find_by_name($pdo, $r['program']) ?: kop_ya_find_by_name($pdo, $r['program_as_written'])) : null;
            $actions[] = array('id' => 'ya_apply', 'label' => 'Add to that young adult program', 'style' => 'approve', 'params' => array(
                array('name' => 'ya', 'label' => 'Young adult program', 'type' => 'select', 'options' => $programs, 'value' => $match ? (string) $match['id'] : ''),
            ));
        }
        $why = (string) ($r['ya_why'] ?? '');
        $actions[] = array('id' => 'ya_create', 'label' => 'Create the young adult program and add this', 'style' => 'neutral', 'params' => array(
            array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $r['program']),
            array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $city),
            array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => $state),
            array('name' => 'country', 'label' => 'or country', 'type' => 'text', 'value' => ''),
            array('name' => 'ages', 'label' => 'Ages', 'type' => 'text', 'value' => preg_match('/^Ages:\s*(.*)$/', $why, $m) ? $m[1] : ''),
        ));
        $actions[] = array('id' => 'ya_off', 'label' => 'Not a young adult program', 'style' => 'neutral');
    } elseif ($pending) {
        $actions[] = array('id' => 'apply', 'label' => (int) $r['facility_id'] > 0 ? 'Add to the record' : 'Add to this record', 'style' => 'approve', 'params' => array(
            array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => (int) $r['facility_id']),
        ));
        if ((int) $r['facility_id'] === 0) {
            $kinds = kop_wbf_create_kinds();
            $actions[] = array('id' => 'create', 'label' => 'Create the record and add this', 'style' => 'neutral', 'params' => array(
                array('name' => 'kind', 'label' => 'What is it?', 'type' => 'select', 'options' => $kinds, 'value' => 'facility'),
                array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $r['program']),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $city),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => $state),
                array('name' => 'country', 'label' => 'or country', 'type' => 'text', 'value' => ''),
            ));
            $actions[] = array('id' => 'ya_on', 'label' => 'It is a young adult program (18+)', 'style' => 'neutral');
        }
    }
    if ($pending) {
        if (isset($extra['original'])) $actions[] = array('id' => 'reset', 'label' => 'Back to what Woodbury said', 'style' => 'neutral');
        $actions[] = array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject');
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'neutral');
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
    switch ($action) {
        case 'apply':
            $waiting();
            $fid = (int) ($params['facility'] ?? 0) ?: (int) $r['facility_id'];
            if ($fid <= 0) throw new RuntimeException('Pick the record first (the Record box beside the button).');
            return $result(kop_wbf_apply(array($r), $fid, $user),
                'Added to ' . kop_rinbox_wbf_name($fid) . ' (' . kop_wbf_where_it_goes($r) . '), citing ' . (kop_wbf_ev_label(kop_wbf_evidence($r)[0] ?? array('label' => 'Woodbury Reports', 'page' => 0))) . '. Undo is on the Added tab.');
        case 'create':
            $waiting();
            $kind = sanitize_key((string) ($params['kind'] ?? 'facility')) ?: 'facility';
            $kinds = kop_wbf_create_kinds();
            if (!isset($kinds[$kind])) throw new RuntimeException('Choose what kind of record to create.');
            $f = array('name' => $p('name'), 'city' => $p('city'), 'country' => $p('country'));
            if ($f['name'] === '') throw new RuntimeException('A name is needed.');
            if ($kind !== 'facility') {
                $f['state'] = $p('state');
                $filed = kop_wbf_file_items(array($r), $kind, $f, $user);
                $t = $filed['target'];
                return array('message' => 'Created the ' . strtolower($kinds[$kind]) . ' record "' . $t['name'] . '" (#' . (int) $t['id'] . ') with this item in its notes. Undo puts the item back; the record stays.');
            }
            if (!function_exists('kop_wbc_create_facility')) throw new RuntimeException('Record creation is not available.');
            $raw_state = $p('state');
            $f['state'] = $raw_state !== '' ? (string) kop_facility_state_code($raw_state) : '';
            if ($raw_state !== '' && $f['state'] === '') {
                throw new RuntimeException('"' . $raw_state . '" is not a US state. Leave it empty and give the country instead.');
            }
            $f['type'] = '';
            $f['force'] = false;
            $target = kop_wbc_create_facility(kop_wbf_create_source(kop_wbf_evidence($r)), $f, kop_closure_pdo());
            $fid = (int) $target['id'];
            return $result(kop_wbf_apply(array($r), $fid, $user), 'Created ' . kop_rinbox_wbf_name($fid) . ' and added this item to it. Undo is on the Added tab.');
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
