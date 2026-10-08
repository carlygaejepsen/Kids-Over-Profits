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

/** Waiting items of merged-away records follow the merge (kop_fmerge_follow_waiting()), after any file load. */
function kop_rinbox_test_merge_follow(callable $check) {
    $pdo = $GLOBALS['pdo'];
    // The harness's get_option() does not read the copied options table: the real merge map, from it.
    $raw = $pdo->query("SELECT option_value FROM wpdl_options WHERE option_name = 'kop_facility_merged_into'")->fetchColumn();
    if ($raw !== false) $GLOBALS['kop_test_options']['kop_facility_merged_into'] = unserialize($raw);
    $map = kop_facility_merged_into();
    $drops = array_map('intval', array_keys($map['ids']));
    if (!$drops) { echo "  (no merges in the mirror)\n"; return; }
    $in = implode(',', $drops);
    $count = function ($t, $where) use ($pdo, $in) { return (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE facility_id IN ($in) AND $where")->fetchColumn(); };
    $w0 = $count('wpdl_kop_woodbury_facts', "status = 'pending'");
    $f0 = $count('wpdl_kop_fornits_items', "status = 'pending'");
    $sample = $pdo->query("SELECT pkey, facility_id FROM wpdl_kop_woodbury_facts WHERE facility_id IN ($in) AND status = 'pending' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $moved = kop_fmerge_follow_waiting();
    $w1 = $count('wpdl_kop_woodbury_facts', "status = 'pending'");
    $f1 = $count('wpdl_kop_fornits_items', "status = 'pending'");
    $check('merge-follow: waiting Woodbury and Fornits items of merged-away records go to the kept record', ($w0 + $f0) > 0 && $w1 === 0 && $f1 === 0 && $moved >= $w0 + $f0,
        "Woodbury $w0 -> $w1, Fornits $f0 -> $f1, $moved rows");
    if ($sample) {
        $now = (int) $pdo->query('SELECT facility_id FROM wpdl_kop_woodbury_facts WHERE pkey = ' . $pdo->quote($sample['pkey']))->fetchColumn();
        $check('merge-follow: to the record it was merged into', $now === kop_facility_merge_resolve((int) $sample['facility_id']) && $now !== (int) $sample['facility_id'], $sample['facility_id'] . ' -> ' . $now);
    }
    $check('merge-follow: running it again changes nothing', kop_fmerge_follow_waiting() === 0);
    $decided = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE facility_id IN ($in) AND status = 'applied'")->fetchColumn()
        + (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE applied_fid IN ($in)")->fetchColumn();
    echo "  ($decided decided items still name a merged-away id: left alone, their Undo knows where they went)\n";
}
