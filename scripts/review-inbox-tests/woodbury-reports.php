<?php
/**
 * scripts/test-review-inbox.php checks for the 'woodbury-reports' source
 * (inc/review-inbox/woodbury-mentions.php over inc/woodbury-mentions.php), on
 * the mirror's real candidates. Filing (media import, FileBird folders, MySQL
 * locks) and Undo (deletes the imported attachment) are not run offline.
 */

require_once dirname(__DIR__, 2) . '/inc/woodbury-mentions.php';
$GLOBALS['kop_test_options']['kop_woodbury_mentions_db'] = KOP_WOODBURY_DB_VERSION;

if (!function_exists('esc_sql')) {
    function esc_sql($s) { return str_replace("'", "''", (string) $s); }
}

function kop_rinbox_test_woodbury_reports(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_mentions';
    $pick = function ($sql) use ($pdo) { return (string) $pdo->query($sql)->fetchColumn(); };

    $key = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND facility_id > 0 AND file <> '' ORDER BY id LIMIT 1");
    $before = kop_wb_get($key);
    $it = kop_rinbox_get_item('woodbury-reports', $key);
    $file = $it['actions'][0];
    $check('woodbury-reports: filing defaults to the best match', $file['id'] === 'file' && (int) $file['params'][0]['value'] === (int) $before['facility_id']);
    $view = array_values(array_filter($it['links'], function ($l) { return $l['label'] === 'View pages'; }));
    $check('woodbury-reports: the cut pages open in the old viewer', $view && strpos($view[0]['url'], 'action=kop_wb_view') !== false && strpos($view[0]['url'], 'nonce=') !== false);

    // Edit: what it is, and the best match.
    call_user_func($src['save'], $key, array('kind' => 'mention'));
    $check('woodbury-reports: save recategorises', kop_wb_get($key)['kind'] === 'mention');
    $other = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE id <> ' . (int) $before['facility_id'] . " AND name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    call_user_func($src['save'], $key, array('facility_id' => (string) $other));
    $r = kop_wb_get($key);
    $check('woodbury-reports: save changes the best match and its name', (int) $r['facility_id'] === $other && $r['facility_name'] !== '' && $r['facility_name'] !== $before['facility_name'], $r['facility_name']);
    try {
        call_user_func($src['save'], $key, array('kind' => 'poem'));
        $check('woodbury-reports: an unknown kind is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-reports: an unknown kind is refused', true, $e->getMessage());
    }

    // Skip and back.
    $m = call_user_func($src['act'], $key, 'skip', array());
    $check('woodbury-reports: skip', kop_wb_get($key)['status'] === 'skipped', $m['message']);
    $it = kop_rinbox_get_item('woodbury-reports', $key);
    $check('woodbury-reports: skipped pages offer Put back', array_column($it['actions'], 'id') === array('reopen'));
    $m = call_user_func($src['act'], $key, 'reopen', array());
    $check('woodbury-reports: put back', kop_wb_get($key)['status'] === 'pending', $m['message']);
    try {
        call_user_func($src['act'], $key, 'undo', array());
        $check('woodbury-reports: undo on waiting pages is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-reports: undo on waiting pages is refused', true, $e->getMessage());
    }
    $pdo->prepare("UPDATE $t SET kind = ?, facility_id = ?, facility_name = ?, facility_state = ?, reviewed_by = ?, reviewed_at = ? WHERE ckey = ?")
        ->execute(array($before['kind'], $before['facility_id'], $before['facility_name'], $before['facility_state'], $before['reviewed_by'], $before['reviewed_at'], $key));

    $none = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND facility_id = 0 ORDER BY id LIMIT 1");
    if ($none !== '') {
        try {
            call_user_func($src['act'], $none, 'file', array('facility' => '', 'also' => ''));
            $check('woodbury-reports: filing with no facility is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-reports: filing with no facility is refused', true, $e->getMessage());
        }
    }
    $filed = $pick("SELECT ckey FROM $t WHERE status = 'filed' ORDER BY id LIMIT 1");
    if ($filed !== '') {
        $it = kop_rinbox_get_item('woodbury-reports', $filed);
        $check('woodbury-reports: filed pages offer Undo (with a warning) and no edits', $it['actions'][0]['id'] === 'undo' && !empty($it['actions'][0]['confirm'])
            && !empty($it['fields'][0]['readonly']) && strpos($it['text'], 'Filed under') !== false);
    }
}
