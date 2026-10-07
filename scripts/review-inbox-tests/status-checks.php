<?php
/**
 * scripts/test-review-inbox.php checks for the 'status_checks' source
 * (inc/review-inbox/status-checks.php over inc/status-checks.php).
 * One made-up update for a real bill waits in the queue; edit, dismiss,
 * reopen, apply and Undo run on the scratch copy.
 */

require_once dirname(__DIR__, 2) . '/inc/status-checks.php';
$GLOBALS['kop_test_options']['kop_status_checks_db'] = KOP_STATUS_CHECKS_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS record_status_sources (kind TEXT NOT NULL, record_id INTEGER NOT NULL, source_url TEXT, source_ref TEXT,
    last_checked TEXT, last_outcome TEXT, last_detail TEXT, last_hash TEXT, PRIMARY KEY (kind, record_id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS record_status_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, record_id INTEGER NOT NULL,
    changes TEXT NOT NULL, change_hash TEXT NOT NULL, quote TEXT, quote_found INTEGER NOT NULL DEFAULT 0, source_url TEXT, source_label TEXT,
    status TEXT NOT NULL DEFAULT 'pending', previous TEXT, reviewed_by TEXT, reviewed_at TEXT, created_at TEXT NOT NULL, UNIQUE (kind, record_id, change_hash))");
$kop_sc_bill = $pdo->query("SELECT * FROM legislation WHERE publication_status = 'published' AND status NOT IN ('enacted','dead') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($kop_sc_bill) {
    kop_sc_propose($pdo, 'bill', (int) $kop_sc_bill['id'], array(
        'status' => array($kop_sc_bill['status'], 'enacted'),
        'last_action_date' => array((string) $kop_sc_bill['last_action_date'], '2026-10-05'),
        'last_action_text' => array((string) $kop_sc_bill['last_action_text'], 'Signed by the Governor (made up for the test).'),
    ), 'Signed by the Governor', false, 'https://example.test/bill', 'example.test');
}

function kop_rinbox_test_status_checks(array $src, array $item, callable $check) {
    $pdo = kop_sc_pdo();
    $p = kop_sc_get($pdo, (int) $item['key']);
    $found = json_decode($p['changes'], true);   // what the check found, before the reviewer edits it
    $before = kop_sc_record($pdo, $p['kind'], $p['record_id']);

    $it = call_user_func($src['get'], $item['key']);
    $check('status_checks: card compares the record now with the source', count($it['compare']['rows']) === 3 && $it['compare']['rows'][0]['values'][1] === 'enacted', json_encode($it['compare']['rows'][0]));
    $check('status_checks: a quote not found in the source is flagged', in_array('Check the quote', array_column($it['details'], 'label'), true));

    call_user_func($src['save'], $item['key'], array('status' => 'signed', 'last_action_date' => '2026-10-04'));
    $changes = json_decode(kop_sc_get($pdo, (int) $item['key'])['changes'], true);
    $check('status_checks: save edits the values Apply will write', $changes['status'][1] === 'signed' && $changes['last_action_date'][1] === '2026-10-04');
    foreach (array(array('status' => 'law'), array('last_action_date' => 'last week')) as $bad) {
        try {
            call_user_func($src['save'], $item['key'], $bad);
            $check('status_checks: refuses ' . json_encode($bad), false);
        } catch (RuntimeException $e) {
            $check('status_checks: refuses ' . json_encode($bad), true, $e->getMessage());
        }
    }

    call_user_func($src['act'], $item['key'], 'dismiss', array());
    $check('status_checks: dismiss', kop_sc_get($pdo, (int) $item['key'])['status'] === 'dismissed');
    // The next night's check finds the same change again (the source's values, not the reviewer's edits).
    $again = kop_sc_propose($pdo, $p['kind'], (int) $p['record_id'], $found, '', false, '', '');
    $check('status_checks: a dismissed update is never proposed again', $again === 0);
    call_user_func($src['act'], $item['key'], 'reopen', array());
    $check('status_checks: back to review', kop_sc_get($pdo, (int) $item['key'])['status'] === 'pending');

    call_user_func($src['act'], $item['key'], 'apply', array());
    $after = kop_sc_record($pdo, $p['kind'], $p['record_id']);
    $check('status_checks: apply writes the edited values', $after['status'] === 'signed' && $after['last_action_date'] === '2026-10-04'
        && $after['last_action_text'] === 'Signed by the Governor (made up for the test).', $after['status'] . ' ' . $after['last_action_date']);
    $check('status_checks: apply notes the source in the admin notes', strpos((string) $after['reviewer_notes'], '[status check ') !== false && strpos((string) $after['reviewer_notes'], 'example.test/bill') !== false);
    $applied = call_user_func($src['get'], $item['key']);
    $check('status_checks: an applied update offers Undo', ($applied['actions'][0]['style'] ?? '') === 'undo');

    // Someone edits one field by hand; Undo leaves that field alone and restores the rest exactly.
    $pdo->prepare('UPDATE legislation SET last_action_text = ? WHERE id = ?')->execute(array('Edited by hand after the update.', (int) $p['record_id']));
    $res = call_user_func($src['act'], $item['key'], 'undo', array());
    $undone = kop_sc_record($pdo, $p['kind'], $p['record_id']);
    $check('status_checks: Undo restores what nobody edited since', $undone['status'] === $before['status'] && (string) $undone['last_action_date'] === (string) $before['last_action_date']
        && (string) $undone['reviewer_notes'] === (string) $before['reviewer_notes'], $res['message']);
    $check('status_checks: Undo keeps a field edited by hand', $undone['last_action_text'] === 'Edited by hand after the update.' && strpos($res['message'], 'Last action') !== false, $res['message']);
    $check('status_checks: the update waits again after Undo', kop_sc_get($pdo, (int) $item['key'])['status'] === 'pending');
    $pdo->prepare('UPDATE legislation SET last_action_text = ? WHERE id = ?')->execute(array($before['last_action_text'], (int) $p['record_id']));

    $counts = call_user_func($src['view_counts'], array());
    $check('status_checks: every tab has a count', array_keys($counts) === array_keys($src['views']), json_encode($counts));
    $bills = call_user_func($src['list'], array('view' => 'all', 'search' => '', 'offset' => 0, 'limit' => 5, 'filters' => array('kind' => 'lawsuit')));
    $check('status_checks: the kind filter narrows the list', $bills['total'] === 0);
    $check('status_checks: the Check now tool is offered', ($src['tools'][0]['id'] ?? '') === 'check' && is_callable($src['tool']));
    try {
        call_user_func($src['tool'], 'check', array('kind' => 'both', 'ids' => '19'));
        $check('status_checks: record numbers need a kind', false);
    } catch (RuntimeException $e) {
        $check('status_checks: record numbers need a kind', true);
    }
}
