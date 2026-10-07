<?php
/**
 * scripts/test-review-inbox.php --source=ya-moved: Woodbury Facts and Fornits
 * items still naming a facility record that moved to the young adult programs
 * (kop_ya_moved_from_map()) are filed on that program's profile, with Undo.
 * Runs on the scratch copy's real moved records.
 */

function kop_rinbox_test_ya_moved(callable $check) {
    $pdo = $GLOBALS['pdo'];
    $ya = kop_ya_pdo();
    $map = kop_ya_moved_from_map($ya);
    $check('ya-moved: the moved records are known by their old ids', count($map) >= 1, json_encode(array_map(function ($p) { return $p['name']; }, $map)));
    $ids = implode(',', array_map('intval', array_keys($map)));
    $t = 'wpdl_kop_woodbury_facts';
    $before = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending' AND facility_id IN ($ids)")->fetchColumn();

    // Woodbury Facts: onto the young adult tab with the program picked, and no "Facility #N does not exist" conflict.
    $moved = kop_wbf_moved_to_ya();
    $left = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending' AND facility_id IN ($ids)")->fetchColumn();
    $gone = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending' AND conflict LIKE '%does not exist%' AND ya_why LIKE 'Its record moved%'")->fetchColumn();
    $check('ya-moved: Woodbury items of moved records go to the young adult tab, without the missing-record conflict', $before > 0 && $left === 0 && $gone === 0 && $moved >= $before,
        "$before waiting on moved records, $moved re-pointed");
    $check('ya-moved: running it again changes nothing', kop_wbf_moved_to_ya() === 0);

    $fid = (int) array_keys($map)[1 % count($map)];
    $want = $map[$fid];
    $pkey = (string) $pdo->query("SELECT pkey FROM $t WHERE status = 'pending' AND ya = 1 AND ya_why LIKE " . $pdo->quote('%#' . $want['id'] . ')%') . " AND grp <> 'consultant' ORDER BY id LIMIT 1")->fetchColumn();
    if ($pkey === '') { $check('ya-moved: a Woodbury item of ' . $want['name'] . ' to file', false); return; }
    $card = kop_rinbox_get_item('woodbury-facts', $pkey);
    $file = array_values(array_filter($card['actions'], function ($a) { return $a['id'] === 'ya_apply'; }))[0] ?? null;
    $check('ya-moved: its card offers Add to that young adult program, the program picked', $file && (string) $file['params'][0]['value'] === (string) $want['id'],
        json_encode($file ? $file['params'][0]['value'] : null) . ' for ' . $want['name']);
    $has = function ($key) use ($ya, $want) {
        foreach (kop_ya_facts(kop_ya_get($ya, $want['id'])) as $f) if (($f['key'] ?? '') === $key) return true;
        return false;
    };
    call_user_func(kop_rinbox_source('woodbury-facts')['act'], $pkey, 'ya_apply', array('ya' => $want['id']));
    $check('ya-moved: Add puts it on the program\'s profile', $has($pkey) && kop_wbf_rows(array($pkey))[0]['status'] === 'applied');
    call_user_func(kop_rinbox_source('woodbury-facts')['act'], $pkey, 'undo', array());
    $check('ya-moved: Undo takes it off again', !$has($pkey) && kop_wbf_rows(array($pkey))[0]['status'] === 'pending');

    // Fornits: "Add to <program>" files a fact citing the post.
    $row = $pdo->query("SELECT * FROM wpdl_kop_fornits_items WHERE status = 'pending' AND kind <> 'testimony' AND facility_id IN ($ids) ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "  (no Fornits item on a moved record in the mirror)\n"; return; }
    $target = $map[(int) $row['facility_id']];
    $fcard = kop_rinbox_get_item('fornits', $row['pkey']);
    $check('ya-moved: a Fornits card of a moved record offers Add to its young adult program first', ($fcard['actions'][0]['id'] ?? '') === 'ya_file'
        && strpos($fcard['actions'][0]['label'], $target['name']) !== false, json_encode(array_column($fcard['actions'], 'label')));
    $msg = call_user_func(kop_rinbox_source('fornits')['act'], $row['pkey'], 'ya_file', array())['message'];
    $fact = null;
    foreach (kop_ya_facts(kop_ya_get($ya, $target['id'])) as $f) if (($f['key'] ?? '') === 'fornits-' . $row['pkey']) $fact = $f;
    $check('ya-moved: it is on the profile, citing the Fornits post', $fact && strpos((string) $fact['cites'][0]['url'], 'fornits.com') !== false
        && strpos((string) $fact['cites'][0]['cite'], 'Fornits forum') === 0, $msg . ' ' . json_encode($fact));
    call_user_func(kop_rinbox_source('fornits')['act'], $row['pkey'], 'undo', array());
    $still = false;
    foreach (kop_ya_facts(kop_ya_get($ya, $target['id'])) as $f) if (($f['key'] ?? '') === 'fornits-' . $row['pkey']) $still = true;
    $status = $pdo->query('SELECT status FROM wpdl_kop_fornits_items WHERE pkey = ' . $pdo->quote($row['pkey']))->fetchColumn();
    $check('ya-moved: Undo takes the Fornits fact off and the item waits again', !$still && $status === 'pending');
}
