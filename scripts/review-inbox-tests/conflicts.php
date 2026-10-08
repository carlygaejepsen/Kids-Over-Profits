<?php
/**
 * scripts/test-review-inbox.php checks for the 'conflicts' source
 * (inc/review-inbox/conflicts.php): Woodbury Facts conflicts from the mirror's
 * own items, settled each way on the scratch copy, every one taken back by Undo.
 */

function kop_rinbox_test_conflicts(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    $all = kop_rinbox_conflicts_all(array());
    $wbf = kop_rinbox_conflict_keys('woodbury-facts');
    $check('conflicts: the section gathers the queues\' conflicts', count($all) >= count($wbf) && $wbf, count($all) . ' in all, ' . count($wbf) . ' from Woodbury Facts');
    // The first card may be a roll-up; the single-card checks take the first card standing alone.
    $units = kop_rinbox_conflicts_units(array());
    foreach ($units as $ukey => $members) {
        if (count($members) === 1) { $item = kop_rinbox_get_item('conflicts', $ukey); break; }
    }
    $check('conflicts: a card shows the conflict, both sides and the three choices, never ticked', $item['conflict'] !== '' && $item['selected'] === false
        && array_slice(array_column($item['actions'], 'id'), 0, empty(($all[$item['key']] ?? array())['editable']) ? 2 : 3) === (empty(($all[$item['key']] ?? array())['editable']) ? array('resolve_use', 'resolve_keep') : array('resolve_use', 'resolve_keep', 'resolve_edit')),
        json_encode(array('conflict' => $item['conflict'], 'actions' => array_column($item['actions'], 'id'), 'compare' => $item['compare'])));

    // They are not in Woodbury's own waiting list or count.
    $keys = array_keys($wbf);
    $page = call_user_func(kop_rinbox_source('woodbury-facts')['list'], array('view' => 'pending', 'search' => '', 'offset' => 0, 'limit' => 200, 'filters' => array()));
    $raw = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn();
    $count = (int) call_user_func(kop_rinbox_source('woodbury-facts')['count']);
    $check('conflicts: they leave Woodbury Facts\' waiting list and count', !array_intersect(array_column($page['items'], 'key'), $keys)
        && $count === $raw - count($keys) - count(kop_rinbox_on_file_keys('woodbury-facts')), "$count of $raw waiting");

    // A value on the record that differs: Use this value, Undo; Keep the record, Undo; Edit, Undo.
    $pick = null;
    foreach ($wbf as $k => $c) {
        $r = kop_wbf_rows(array($k))[0] ?? null;
        if ($r && $r['op'] === 'set_if_empty' && $c['record'] !== '' && kop_on_file_doc($r['facility_id'])) { $pick = $r; break; }
    }
    if (!$pick) { $check('conflicts: a value conflict to settle exists in the mirror', false); return; }
    $key = 'woodbury-facts|' . $pick['pkey'];
    $fid = (int) $pick['facility_id'];
    $opts = kop_wbf_opts();
    $slot = function () use ($fid, $opts, $pick) { return kop_wbf_get(kop_facility_load($fid, $opts)['doc'], $pick['path']); };
    $status = function () use ($pick) { return kop_wbf_rows(array($pick['pkey']))[0]['status']; };
    $before = $slot();

    $msg = kop_rinbox_conflicts_act($key, 'resolve_use', array())['message'];
    $check('conflicts: Use this value writes the item\'s value over the record\'s and files it as added', $slot() == kop_wbf_row_value($pick) && $status() === 'applied', $msg);
    $after = kop_rinbox_get_item('conflicts', $key);
    $check('conflicts: a settled card offers only the queue\'s Undo, no conflict mark', array_column($after['actions'], 'id') === array('undo') && $after['conflict'] === '',
        json_encode(array('actions' => array_column($after['actions'], 'id'), 'conflict' => $after['conflict'], 'status' => $after['status'])));
    kop_rinbox_conflicts_act($key, 'undo', array());
    $check('conflicts: Undo puts the record\'s old value back and the item waits again', $slot() == $before && $status() === 'pending', json_encode($slot()));

    kop_rinbox_conflicts_act($key, 'resolve_keep', array());
    $check('conflicts: Keep the record leaves the record and files the item as rejected', $slot() == $before && $status() === 'rejected');
    kop_rinbox_conflicts_act($key, 'undo', array());
    $check('conflicts: ...and Undo brings it back', $status() === 'pending');

    $typed = is_array(kop_wbf_row_value($pick)) ? '7 to 19' : (is_int(kop_wbf_row_value($pick)) ? '77' : 'Typed In Test');
    kop_rinbox_conflicts_act($key, 'resolve_edit', array('value' => $typed));
    $want = kop_wbf_parse_value($pick, $typed);
    $check('conflicts: Edit writes the typed value in the record\'s shape', $slot() == $want && $status() === 'applied', json_encode($slot()));
    kop_rinbox_conflicts_act($key, 'undo', array());
    $check('conflicts: ...and Undo puts the old value back', $slot() == $before && $status() === 'pending');

    $refused = '';
    try { kop_wbf_parse_value(array('op' => 'set_closed', 'value' => '{}'), 'soon'); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    $check('conflicts: a typed closure needs a year', $refused !== '', $refused);
    $check('conflicts: ranges and numbers are read from typing', kop_wbf_parse_value(array('op' => 'set_if_empty', 'value' => '{"min":1,"max":2}'), '12 to 18') === array('min' => 12, 'max' => 18)
        && kop_wbf_parse_value(array('op' => 'set_if_empty', 'value' => '40'), '45') === 45);

    kop_rinbox_test_conflicts_closed($check);
    kop_rinbox_test_conflicts_rollup($check);
    kop_rinbox_test_conflicts_elsewhere($check);

    // The kind filter.
    $staff = kop_rinbox_conflicts_all(array('filters' => array('what' => 'staff')));
    $check('conflicts: the kind filter keeps only that kind', !array_filter($staff, function ($c) { return $c['what'] !== 'staff'; }), count($staff) . ' staff roles');
}

/** Conflicts about one program and kind are one card; one choice settles every ticked row, one Undo takes them all back. */
function kop_rinbox_test_conflicts_rollup(callable $check) {
    $all = kop_rinbox_conflicts_all(array());
    $units = kop_rinbox_conflicts_units(array());
    $groups = array_filter($units, function ($m) { return count($m) > 1; });
    $check('conflicts: conflicts about one program and kind roll up into one card', $groups && count($units) < count($all)
        && array_sum(array_map('count', $units)) === count($all), count($all) . ' conflicts on ' . count($units) . ' cards, ' . count($groups) . ' roll-ups');
    $named = true;
    foreach ($units as $ukey => $members) {
        $first = reset($members);
        foreach ($members as $c) {
            if ((int) ($c['facility_id'] ?? 0) !== (int) ($first['facility_id'] ?? 0) || ($c['what'] ?? '') !== ($first['what'] ?? '')) $named = false;
        }
    }
    $check('conflicts: a roll-up holds one program and one kind only', $named);

    // A roll-up on a real record, all rows ticked: Keep the record, then Undo.
    $gkey = null;
    foreach ($groups as $ukey => $members) {
        if (strpos($ukey, 'woodbury-facts|@') === 0 && (int) reset($members)['facility_id'] > 0) { $gkey = $ukey; break; }
    }
    if (!$gkey) { $check('conflicts: a Woodbury roll-up on a record exists in the mirror', false); return; }
    $card = kop_rinbox_get_item('conflicts', $gkey);
    $keys = array_merge(...array_column($card['checklist'], 'keys'));
    $check('conflicts: a roll-up card names the program and ticks every row', count($keys) === count($groups[$gkey]) && strpos($card['title'], ':') !== false
        && !array_filter($card['checklist'], function ($e) { return empty($e['checked']); }) && $card['checklist_noun'] === 'items',
        $card['title'] . ' | ' . count($keys) . ' rows | ' . $card['conflict']);
    $status = function (array $ks) {
        return array_count_values(array_column(kop_wbf_rows($ks), 'status'));
    };
    $wkeys = array_map(function ($k) { return (string) $k; }, $keys);

    $res = kop_rinbox_conflicts_act($gkey, 'resolve_keep', array('picked' => $keys));
    $check('conflicts: Keep the record on a roll-up files every ticked row as rejected', $status($wkeys) === array('rejected' => count($keys)), $res['message']);
    $check('conflicts: ...and the card leaves the list', !isset(kop_rinbox_conflicts_units(array())[$gkey]));
    $undo = kop_rinbox_conflicts_act($gkey, $res['undo']['action'], $res['undo']['params']);
    $check('conflicts: ...and one Undo brings every row back', $status($wkeys) === array('pending' => count($keys)) && isset(kop_rinbox_conflicts_units(array())[$gkey]), $undo['message']);

    // Unticked rows stay waiting.
    $one = array($keys[0]);
    $res = kop_rinbox_conflicts_act($gkey, 'resolve_keep', array('picked' => $one));
    $left = kop_rinbox_conflicts_group_members('woodbury-facts', explode('|', $gkey, 2)[1]);
    $check('conflicts: only the ticked rows are settled', !isset($left[$keys[0]]) && count($left) === count($keys) - 1);
    kop_rinbox_conflicts_act($gkey, $res['undo']['action'], $res['undo']['params']);
    $refused = '';
    try { kop_rinbox_conflicts_act($gkey, 'resolve_keep', array('picked' => array())); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    $check('conflicts: nothing ticked is refused', $refused !== '', $refused);

    // A one-value kind whose rows say different things: Use is refused, Edit writes one value and keeps the record for the rest.
    $single = null;
    foreach ($groups as $ukey => $members) {
        $vals = array_unique(array_column($members, 'item'));
        if (strpos($ukey, 'woodbury-facts|@') === 0 && (int) reset($members)['facility_id'] > 0 && count($vals) > 1
            && in_array(reset($members)['what'], kop_rinbox_conflicts_single_kinds(), true)) { $single = $ukey; break; }
    }
    if (!$single) { echo "  (no one-value roll-up with different values in the mirror; Use/Edit roll-up checks skipped)\n"; return; }
    $skeys = array_map('strval', array_keys($groups[$single]));
    $refused = '';
    try { kop_rinbox_conflicts_act($single, 'resolve_use', array('picked' => $skeys)); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    $check('conflicts: Use on rows giving different values for a one-value field is refused', $refused !== '' && $status($skeys) === array('pending' => count($skeys)), $refused);
    $card = kop_rinbox_get_item('conflicts', $single);
    if (in_array('resolve_edit', array_column($card['actions'], 'id'), true)) {
        $res = kop_rinbox_conflicts_act($single, 'resolve_edit', array('picked' => $skeys, 'value' => '1999'));
        $st = $status($skeys);
        $check('conflicts: Edit on a roll-up writes once and files the rest as rejected', ($st['applied'] ?? 0) === 1 && ($st['rejected'] ?? 0) === count($skeys) - 1, $res['message']);
        kop_rinbox_conflicts_act($single, $res['undo']['action'], $res['undo']['params']);
        $check('conflicts: ...and Undo puts them all back', $status($skeys) === array('pending' => count($skeys)));
    }
}

/** Create a record, or add to another one, from a conflict card and from a roll-up, with Undo. */
function kop_rinbox_test_conflicts_elsewhere(callable $check) {
    $units = kop_rinbox_conflicts_units(array());
    $single = $group = null;
    foreach ($units as $ukey => $members) {
        if (strpos($ukey, 'woodbury-facts|') !== 0) continue;
        if (count($members) === 1 && !$single) $single = $ukey;
        if (count($members) > 1 && !$group && (int) reset($members)['facility_id'] > 0) $group = $ukey;
    }
    $ids = function ($it) { return array_column($it['actions'], 'id'); };
    $card = kop_rinbox_get_item('conflicts', $single);
    $check('conflicts: a card keeps the queue\'s Create the record and Add to another record', in_array('create', $ids($card), true) && in_array('apply', $ids($card), true),
        implode(', ', $ids($card)));
    $kinds = array_column(array_column($card['actions'], 'params', 'id')['create'] ?? array(), 'options', 'name')['kind'] ?? array();
    $check('conflicts: Create offers a parent company and a transporter too', isset($kinds['company'], $kinds['transporter']), implode(', ', array_keys((array) $kinds)));
    $gcard = kop_rinbox_get_item('conflicts', $group);
    $check('conflicts: a roll-up offers Create a record for ticked and Add ticked to another record',
        in_array('create_ticked', $ids($gcard), true) && in_array('apply_ticked', $ids($gcard), true), implode(', ', $ids($gcard)));

    // Add the ticked rows to another record, then Undo.
    $gid = explode('|', $group, 2)[1];
    $keys = array_map('strval', array_keys(kop_rinbox_conflicts_group_members('woodbury-facts', $gid)));
    $other = (int) $GLOBALS['pdo']->query('SELECT id FROM facilities_v2 WHERE id <> ' . (int) reset($units[$group])['facility_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
    $status = function () use ($keys) { return array_count_values(array_column(kop_wbf_rows($keys), 'status')); };
    $where = function () use ($keys) { return array_unique(array_map('intval', array_column(kop_wbf_rows($keys), 'facility_id'))); };
    try {
        $res = kop_rinbox_conflicts_act($group, 'apply_ticked', array('picked' => $keys, 'facility' => $other));
        $moved = array_diff_key($status(), array('pending' => 1));
        $check('conflicts: Add ticked to another record adds them there (or marks them in conflict there)', $moved || in_array($other, $where(), true), $res['message']);
        if (!empty($res['undo'])) kop_rinbox_conflicts_act($group, $res['undo']['action'], $res['undo']['params']);
        $check('conflicts: ...and Undo puts every row back waiting, on the record it named', $status() === array('pending' => count($keys)) && $where() === array((int) reset($units[$group])['facility_id']),
            json_encode(array($status(), $where())));
    } catch (Throwable $e) {
        $check('conflicts: Add ticked to another record', false, get_class($e) . ': ' . $e->getMessage());
    }

    // Create a record for the ticked rows. Both creates write with MySQL-only SQL (ON DUPLICATE KEY, INSERT IGNORE), so
    // only the record is made up here, as scripts/test-woodbury-facts.php does: the queue's create_many files every
    // ticked row on it (kop_wbf_file_items with kop_wbc_create swapped for a stand-in) and Undo puts them back.
    $made = array();
    $builders = &$GLOBALS['kop_rinbox_builders'];
    $real = $builders['woodbury-facts'];
    $builders['woodbury-facts'] = function () use ($real, &$made) {
        $src = call_user_func($real);
        $src['create_many'] = function (array $ks, array $params) use (&$made) {
            global $wpdb;
            $rows = array_values(array_filter(kop_wbf_rows($ks), function ($r) { return $r['status'] === 'pending'; }));
            $made[] = $params;
            foreach ($rows as $r) {
                $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied_fid' => 0, 'reviewed_by' => 'inbox-test', 'reviewed_at' => gmdate('Y-m-d H:i:s'),
                    'applied' => json_encode(array('filed' => 'company', 'who' => 'firm', 'id' => 990001, 'name' => $params['name']))), array('pkey' => $r['pkey']));
            }
            return array('message' => 'Created.', 'done' => array_column($rows, 'pkey'));
        };
        return $src;
    };
    kop_rinbox_sources(true);
    try {
        $res = kop_rinbox_conflicts_act($group, 'create_ticked', array('picked' => $keys, 'kind' => 'company', 'name' => 'Inbox Test Holdings', 'city' => '', 'state' => 'UT', 'country' => ''));
        $check('conflicts: Create a record for ticked hands every ticked row and the form to the queue, once', count($made) === 1 && $made[0]['kind'] === 'company'
            && $status() === array('applied' => count($keys)), $res['message']);
        kop_rinbox_conflicts_act($group, $res['undo']['action'], $res['undo']['params']);
        $check('conflicts: ...and Undo puts the rows back waiting (the record stays)', $status() === array('pending' => count($keys)), json_encode($status()));
    } catch (Throwable $e) {
        $check('conflicts: Create a record for ticked', false, get_class($e) . ': ' . $e->getMessage());
    }
    $builders['woodbury-facts'] = $real;
    kop_rinbox_sources(true);
}

/** A closure on a record already marked closed is no conflict: a year the record lacks is filled in, no year is on file. */
function kop_rinbox_test_conflicts_closed(callable $check) {
    $bad = array();
    foreach (kop_rinbox_conflict_keys('woodbury-facts') as $k => $c) {
        $r = kop_wbf_rows(array($k))[0] ?? null;
        if (!$r || $r['op'] !== 'set_closed' || (int) $r['facility_id'] <= 0) continue;
        $doc = kop_on_file_doc((int) $r['facility_id']);
        $status = strtolower((string) ($doc['doc']['operatingPeriod']['status'] ?? ($doc['operatingPeriod']['status'] ?? '')));
        if ($doc && in_array($status, array('closed', 'unknown', ''), true) && !kop_wbf_conflict_parts($doc['doc'] ?? $doc, $r)) $bad[] = $k;
    }
    $check('conflicts: no closure on a record already marked closed is a conflict', !$bad, count($bad) . ' left' . ($bad ? ': ' . implode(', ', array_slice($bad, 0, 5)) : ''));

    // One with a year, on a closed record with no end year: Add fills the year in.
    global $wpdb;
    $opts = kop_wbf_opts();
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_wbf_table() . " WHERE status = 'pending' AND op = 'set_closed' AND facility_id > 0", ARRAY_A) as $r) {
        $v = kop_wbf_row_value($r);
        if (empty($v['endYear'])) continue;
        $stored = kop_facility_load((int) $r['facility_id'], $opts);
        $op = $stored['doc']['operatingPeriod'] ?? array();
        if (!$stored || strcasecmp((string) ($op['status'] ?? ''), 'Closed') !== 0 || !empty($op['endYear'])) continue;
        $res = kop_wbf_apply(array($r), (int) $r['facility_id'], 'inbox-test')[$r['pkey']];
        $after = kop_facility_load((int) $r['facility_id'], $opts)['doc']['operatingPeriod'];
        $check('conflicts: a closing year for a closed record with none is added', !empty($res['ok']) && (int) $after['endYear'] === (int) $v['endYear'] && $after['status'] === 'Closed',
            $r['label'] . ' -> ' . json_encode(array('status' => $after['status'], 'endYear' => $after['endYear'])));
        kop_wbf_undo(kop_wbf_rows(array($r['pkey'])), 'inbox-test');
        $back = kop_facility_load((int) $r['facility_id'], $opts)['doc']['operatingPeriod'];
        $check('conflicts: ...and Undo takes the year off again', empty($back['endYear']) && kop_wbf_rows(array($r['pkey']))[0]['status'] === 'pending');
        return;
    }
    echo "  (no closed record without an end year has a waiting closing year in the mirror)\n";
}
