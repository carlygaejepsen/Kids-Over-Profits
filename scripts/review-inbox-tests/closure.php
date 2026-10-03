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
}

