<?php
/**
 * scripts/test-review-inbox.php checks for the 'facility-merge' source
 * (inc/review-inbox/facility-merge.php over inc/facility-merge.php): a pair
 * opens with both records, "Not the same" and back, and a real merge of the
 * first pair with Undo putting facilities_v2 back exactly.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/facility-merge.php';

if (kop_rinbox_test_wants('facility-merge')) {
    // Every table a merge reads or moves rows in (as scripts/test-facility-merge.php copies).
    $kop_rinbox_fm_tables = array('lawsuit_facility_links', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_fbv',
        'wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links', 'wpdl_kop_migration_state');
    foreach (kop_fmerge_ref_tables('wpdl_') as $r) $kop_rinbox_fm_tables[] = $r['t'];
    foreach (kop_fmerge_json_tables('wpdl_') as $r) $kop_rinbox_fm_tables[] = $r['t'];
    kop_rinbox_test_copy_tables($kop_rinbox_fm_tables);
    unset($kop_rinbox_fm_tables);
}

// The mirror is a copy of production, where facility saves go to facilities_v2
// (the real check reads MySQL's SHOW TABLES, which SQLite has not got).
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}

function kop_rinbox_test_facility_merge(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    list($a, $b) = array_map('intval', explode(':', $item['key']));
    $check('facility-merge: both records are named', strpos($item['text'], '#' . $a) !== false && strpos($item['text'], '#' . $b) !== false);
    $merge = null;
    foreach ($item['actions'] as $x) if ($x['id'] === 'merge') $merge = $x;
    $opts = $merge ? array_keys($merge['params'][0]['options']) : array();
    $check('facility-merge: merge asks which record to keep', $merge && $opts == array((string) $a, (string) $b), implode(',', $opts));

    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('facility-merge: dismiss, merge and undo', 'update_option does not store in this harness');
        return;
    }
    $res = call_user_func($src['act'], $item['key'], 'dismiss', array());
    $got = kop_rinbox_get_item('facility-merge', $item['key']);
    $check('facility-merge: not the same', $got['status'] === 'dismissed' && isset(kop_fmerge_dismissed()[$item['key']]), $res['message']);
    call_user_func($src['act'], $item['key'], 'undismiss', array());
    $got = kop_rinbox_get_item('facility-merge', $item['key']);
    $check('facility-merge: back on the list', $got['status'] !== 'dismissed' && !isset(kop_fmerge_dismissed()[$item['key']]));

    $snap = function () use ($pdo) {
        return md5(json_encode($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
    };
    $before = $snap();
    $keep = (int) $merge['params'][0]['value'];
    $drop = $keep === $a ? $b : $a;
    $res = call_user_func($src['act'], $item['key'], 'merge', array('keep' => (string) $keep));
    $gone = !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . $drop)->fetchColumn();
    $check('facility-merge: merge folds one record into the other', $gone && !empty($res['key']), $res['message']);
    if (empty($res['key'])) return;
    $merged = kop_rinbox_get_item('facility-merge', $res['key']);
    $undo = array_filter($merged['actions'], function ($x) { return $x['id'] === 'undo'; });
    $check('facility-merge: the merge is on the Merged tab with Undo', $merged['status'] === 'merged' && $undo);
    $res = call_user_func($src['act'], $res['key'], 'undo', array());
    $check('facility-merge: Undo puts facilities_v2 back exactly', $snap() === $before, $res['message']);
}
