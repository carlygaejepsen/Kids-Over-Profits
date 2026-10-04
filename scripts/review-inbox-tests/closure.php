<?php
/**
 * scripts/test-review-inbox.php checks for the 'closure' source
 * (inc/review-inbox/closure-reports.php over inc/closure-reports.php).
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;

function kop_rinbox_test_closure(array $src, array $item, callable $check) {
    $pdo = kop_closure_pdo();
    $before = kop_closure_get_report($pdo, (int) $item['key']);
    call_user_func($src['save'], $item['key'], array('program_name' => 'Renamed In Test', 'stage' => 'suspended'));
    $after = kop_closure_get_report($pdo, (int) $item['key']);
    $check('closure: save renames and recategorises', $after['program_name'] === 'Renamed In Test' && $after['stage'] === 'suspended' && $after['target_status'] === 'Suspended');
    try {
        call_user_func($src['save'], $item['key'], array('closure_date' => 'last May'));
        $check('closure: a date that is not a date is refused', false);
    } catch (RuntimeException $e) {
        $check('closure: a date that is not a date is refused', true, $e->getMessage());
    }
    call_user_func($src['act'], $item['key'], 'dismiss', array());
    $check('closure: dismiss', kop_closure_get_report($pdo, (int) $item['key'])['status'] === 'dismissed');
    call_user_func($src['act'], $item['key'], 'reopen', array());
    $check('closure: back to review', kop_closure_get_report($pdo, (int) $item['key'])['status'] === 'pending');
    $pdo->prepare('UPDATE facility_closure_reports SET program_name = ?, stage = ?, target_status = ?, status = ? WHERE id = ?')
        ->execute(array($before['program_name'], $before['stage'], $before['target_status'], $before['status'], (int) $item['key']));

    // The old screen's parts: per-tab counts, the facility as it is now, the confirm form's facility box.
    $counts = call_user_func($src['view_counts'], array());
    $check('closure: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['all'] >= $counts['pending'], json_encode($counts));
    $check('closure: All lists every report', call_user_func($src['list'], array('view' => 'all', 'search' => '', 'offset' => 0, 'limit' => 1))['total'] === $counts['all']);
    $it = call_user_func($src['get'], $item['key']);
    $labels = array_column($it['details'], 'label');
    $check('closure: details name the facility as it is now and what the article says', (in_array('Facility now', $labels, true) || in_array('Facility', $labels, true)) && in_array('Article names', $labels, true) && in_array('Sets', $labels, true), implode(', ', $labels));
    $apply = null;
    foreach ($it['actions'] as $a) if ($a['id'] === 'apply') $apply = $a;
    $check('closure: Confirm asks which facility closed, with the match filled in', $apply && $apply['params'][0]['name'] === 'facility_id' && (int) $apply['params'][0]['value'] === (int) $before['facility_id']);
    $notfound = $pdo->query("SELECT id FROM facility_closure_reports WHERE quote_found = 0 AND quote IS NOT NULL AND quote <> '' LIMIT 1")->fetchColumn();
    if ($notfound) {
        $nf = call_user_func($src['get'], (string) $notfound);
        $check('closure: a quote not found in the article is flagged', in_array('Check the quote', array_column($nf['details'], 'label'), true));
    }
    $check('closure: the scan tool is offered', ($src['tools'][0]['id'] ?? '') === 'scan' && is_callable($src['tool']));
    try {
        call_user_func($src['tool'], 'nope', array());
        $check('closure: an unknown tool is refused', false);
    } catch (RuntimeException $e) {
        $check('closure: an unknown tool is refused', true);
    }
}

