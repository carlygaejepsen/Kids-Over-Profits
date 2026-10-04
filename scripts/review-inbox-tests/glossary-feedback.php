<?php
/**
 * scripts/test-review-inbox.php checks for the 'glossary-feedback' source
 * (inc/review-inbox/glossary-feedback.php over inc/glossary-feedback.php).
 * The mirror has no new note, so one is added to the scratch copy.
 */

require_once dirname(__DIR__, 2) . '/inc/glossary-feedback.php';
$GLOBALS['kop_test_options']['kop_glossary_feedback_db'] = KOP_GLOSSARY_FEEDBACK_DB_VERSION;

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_glossary_feedback (id INTEGER PRIMARY KEY, created_at TEXT, kind TEXT, term_id TEXT, term TEXT,
        program TEXT, details TEXT, source TEXT, contact TEXT, status TEXT, ip_hash TEXT)');
    $pdo->prepare("INSERT INTO wpdl_kop_glossary_feedback (created_at, kind, term_id, term, program, details, source, contact, status, ip_hash)
                   VALUES (?, 'used_at', 'inbox-test-term', 'Inbox Test Term', 'Inbox Test Ranch', 'They called it this at our program.', 'I was there', NULL, 'new', NULL)")
        ->execute(array(gmdate('Y-m-d H:i:s', time() + 60)));
})();

function kop_rinbox_test_glossary_feedback(array $src, array $item, callable $check) {
    global $wpdb;
    $row = function () use ($wpdb, $item) {
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM wpdl_kop_glossary_feedback WHERE id = %d', (int) $item['key']));
    };
    $check('glossary-feedback: the waiting item is the new test note', $item['title'] === 'Inbox Test Term' && strpos($item['text'], 'Inbox Test Ranch') !== false);
    $check('glossary-feedback: it links to the Glossary Editor', strpos($item['links'][0]['url'], 'page=kop-glossary-editor') !== false);

    call_user_func($src['save'], $item['key'], array('kind' => 'correction', 'program' => '<b>Inbox Ranch</b>', 'details' => 'Fixed spelling.'));
    $r = $row();
    $check('glossary-feedback: save changes kind and text (tags stripped)', $r->kind === 'correction' && $r->program === 'Inbox Ranch' && $r->details === 'Fixed spelling.');
    try {
        call_user_func($src['save'], $item['key'], array('kind' => 'praise'));
        $check('glossary-feedback: an unknown kind is refused', false);
    } catch (RuntimeException $e) {
        $check('glossary-feedback: an unknown kind is refused', true, $e->getMessage());
    }

    $res = call_user_func($src['act'], $item['key'], 'added', array());
    $check('glossary-feedback: mark added', $row()->status === 'added', $res['message']);
    $again = kop_rinbox_get_item('glossary-feedback', $item['key']);
    $check('glossary-feedback: an added note can be dismissed or go back to new, as on the old screen', array_column($again['actions'], 'id') === array('dismissed', 'new'));
    $counts = call_user_func($src['view_counts'], array());
    $check('glossary-feedback: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['added'] >= 1, json_encode($counts));
    $check('glossary-feedback: All lists every note', call_user_func($src['list'], array('view' => 'all', 'search' => '', 'offset' => 0, 'limit' => 1))['total'] === $counts['all']);
    call_user_func($src['act'], $item['key'], 'new', array());
    $check('glossary-feedback: back to new', $row()->status === 'new');
    call_user_func($src['act'], $item['key'], 'dismissed', array());
    $check('glossary-feedback: dismiss', $row()->status === 'dismissed');
    try {
        call_user_func($src['act'], $item['key'], 'delete', array());
        $check('glossary-feedback: an unknown action is refused', false);
    } catch (RuntimeException $e) {
        $check('glossary-feedback: an unknown action is refused', true, $e->getMessage());
    }
}
