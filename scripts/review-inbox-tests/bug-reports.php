<?php
/**
 * scripts/test-review-inbox.php checks for the 'bug-reports' source
 * (inc/review-inbox/bug-reports.php over inc/bug-report-notify.php). Mail is
 * caught, never sent, so the reporter email the old screen sends is checked.
 */

require_once dirname(__DIR__, 2) . '/inc/bug-report-notify.php';
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $body) { $GLOBALS['kop_test_mail'][] = array($to, $subject, $body); return true; }
}
if (!function_exists('is_email')) {
    function is_email($s) { return filter_var((string) $s, FILTER_VALIDATE_EMAIL) ? $s : false; }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS bug_reports (id INTEGER PRIMARY KEY, feature TEXT, category TEXT, description TEXT, steps TEXT, contact TEXT,
        notify_updates INTEGER, page_url TEXT, page_title TEXT, user_agent TEXT, viewport TEXT, console_errors TEXT, context_json TEXT, ip_hash TEXT,
        status TEXT, admin_note TEXT, created_at TEXT, updated_at TEXT)');
    $now = gmdate('Y-m-d H:i:s', time() + 60);
    $pdo->prepare("INSERT INTO bug_reports (feature, category, description, steps, contact, notify_updates, page_url, user_agent, viewport, console_errors, status, created_at, updated_at)
                   VALUES ('Inbox test', 'load-failed', 'The inbox test page did not load.', 'Open it.', 'reader@example.test', 1, 'https://example.test/page/',
                   'TestBrowser', '390x800', ?, 'new', ?, ?)")
        ->execute(array(json_encode(array(array('type' => 'error', 'message' => 'x is undefined'))), $now, $now));
})();

function kop_rinbox_test_bug_reports(array $src, array $item, callable $check) {
    global $wpdb;
    $row = function () use ($wpdb, $item) {
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', (int) $item['key']));
    };
    $check('bug-reports: the waiting item is the new test report', $item['title'] === 'The inbox test page did not load.' && strpos($item['text'], 'x is undefined') !== false);

    call_user_func($src['save'], $item['key'], array('category' => 'broken-ui', 'feature' => 'Inbox'));
    $r = $row();
    $check('bug-reports: save changes category and feature', $r->category === 'broken-ui' && $r->feature === 'Inbox');
    try {
        call_user_func($src['save'], $item['key'], array('category' => 'typo'));
        $check('bug-reports: an unknown category is refused', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: an unknown category is refused', true, $e->getMessage());
    }

    $GLOBALS['kop_test_mail'] = array();
    $res = call_user_func($src['act'], $item['key'], 'in_progress', array());
    $mail = $GLOBALS['kop_test_mail'];
    $check('bug-reports: start working sets the status', $row()->status === 'in_progress', $res['message']);
    $check('bug-reports: the reporter who asked for updates is emailed, without their own text',
        count($mail) === 1 && $mail[0][0] === 'reader@example.test' && strpos($mail[0][2], 'did not load') === false && strpos($res['message'], 'emailed') !== false);
    $res = call_user_func($src['act'], $item['key'], 'resolved', array());
    $check('bug-reports: resolved', $row()->status === 'resolved', $res['message']);
    call_user_func($src['act'], $item['key'], 'new', array());
    $check('bug-reports: back to new', $row()->status === 'new');
    $again = kop_rinbox_get_item('bug-reports', $item['key']);
    $check('bug-reports: a new report offers the three other statuses', array_column($again['actions'], 'id') === array('in_progress', 'resolved', 'dismissed'));
    try {
        call_user_func($src['act'], $item['key'], 'delete', array());
        $check('bug-reports: an unknown action is refused', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: an unknown action is refused', true, $e->getMessage());
    }
}
