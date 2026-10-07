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
    $check('conflicts: a card shows the conflict, both sides and the three choices, never ticked', $item['conflict'] !== '' && $item['selected'] === false
        && array_column($item['actions'], 'id') === (empty(($all[$item['key']] ?? array())['editable']) ? array('resolve_use', 'resolve_keep') : array('resolve_use', 'resolve_keep', 'resolve_edit')),
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

    // The kind filter.
    $staff = kop_rinbox_conflicts_all(array('filters' => array('what' => 'staff')));
    $check('conflicts: the kind filter keeps only that kind', !array_filter($staff, function ($c) { return $c['what'] !== 'staff'; }), count($staff) . ' staff roles');
}
