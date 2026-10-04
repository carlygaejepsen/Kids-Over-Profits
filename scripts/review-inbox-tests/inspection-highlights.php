<?php
/**
 * scripts/test-review-inbox.php checks for the 'inspection-highlights' source
 * (inc/review-inbox/inspection-highlights.php over inc/inspection-highlights.php,
 * which the harness loads). inspection_reports is too big to copy whole; the
 * source only reads its link columns, so those are copied for the reports
 * the findings came from (report text left empty).
 */

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER, report_id TEXT, report_date TEXT, report_url TEXT,
        raw_content TEXT, content_length INTEGER, is_structured INTEGER, summary TEXT, categories_json TEXT, created_at TEXT, updated_at TEXT,
        featured INTEGER, featured_note TEXT)');
    try {
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($GLOBALS['mirror']) . ' AS ihsrc');
        $pdo->exec('INSERT OR IGNORE INTO inspection_reports (id, facility_id, report_id, report_date, report_url)
                    SELECT id, facility_id, report_id, report_date, report_url FROM ihsrc.inspection_reports
                     WHERE id IN (SELECT report_id FROM inspection_highlights)');
        $pdo->exec('DETACH DATABASE ihsrc');
    } catch (PDOException $e) {
        // No reports table in this mirror: the links are simply empty.
    }
})();

function kop_rinbox_test_inspection_highlights(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $id = (int) $item['key'];
    $row = function () use ($pdo, $id) {
        $st = $pdo->prepare('SELECT * FROM inspection_highlights WHERE id = ?');
        $st->execute(array($id));
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $before = $row();
    $check('inspection-highlights: the waiting item is pending', $before['status'] === 'pending');
    $first = $pdo->query("SELECT h.id FROM inspection_highlights h JOIN inspection_facilities f ON f.id = h.facility_id WHERE h.status = 'pending'
        ORDER BY (h.score >= " . (int) kop_ih_severe_score() . ') DESC, (h.finding_date IS NULL) ASC, h.finding_date DESC, h.score DESC, h.id DESC LIMIT 1')->fetchColumn();
    $check('inspection-highlights: recent severe findings lead, as on the old screen', (int) $first === $id);
    $check('inspection-highlights: the card links the state source', preg_match('#^https?://#', $item['url']) === 1, $item['url']);

    $other = array_values(array_diff(array_keys(kop_ih_categories()), array($before['category'])))[0];
    call_user_func($src['save'], $item['key'], array('category' => $other, 'review_note' => 'checked in test'));
    $after = $row();
    $check('inspection-highlights: save changes the worst harm and leads the list with it',
        $after['category'] === $other && explode(',', $after['categories'])[0] === $other && $after['review_note'] === 'checked in test', $after['categories']);
    try {
        call_user_func($src['save'], $item['key'], array('category' => 'gossip'));
        $check('inspection-highlights: an unknown category is refused', false);
    } catch (RuntimeException $e) {
        $check('inspection-highlights: an unknown category is refused', true, $e->getMessage());
    }
    $pdo->prepare('UPDATE inspection_highlights SET category = ?, categories = ?, review_note = ? WHERE id = ?')
        ->execute(array($before['category'], $before['categories'], $before['review_note'], $id));

    $res = call_user_func($src['act'], $item['key'], 'rejected', array());
    $r = $row();
    $check('inspection-highlights: reject records who and when', $r['status'] === 'rejected' && $r['reviewed_by'] === 'inbox-test' && $r['reviewed_at'] !== null, $res['message']);
    $back = kop_rinbox_get_item('inspection-highlights', $item['key']);
    $check('inspection-highlights: a rejected finding offers approve and back to pending', array_column($back['actions'], 'id') === array('approved', 'pending'));
    call_user_func($src['act'], $item['key'], 'pending', array());
    $r = $row();
    $check('inspection-highlights: back to pending clears the review', $r['status'] === 'pending' && $r['reviewed_by'] === null && $r['reviewed_at'] === null);

    $res = call_user_func($src['act'], $item['key'], 'approved', array());
    $r = $row();
    $kept = isset($res['key']) ? $res['key'] : $item['key'];
    $st = $pdo->prepare('SELECT status FROM inspection_highlights WHERE id = ?');
    $st->execute(array((int) $kept));
    $check('inspection-highlights: approve (through the same-day merge)', $st->fetchColumn() === 'approved', $res['message']);
    if ($r) {
        call_user_func($src['act'], $item['key'], 'pending', array());
        $check('inspection-highlights: back to pending undoes the approval', $row()['status'] === 'pending');
    }
    // The old screen's filters, finding numbers, note with the decision and the report viewer.
    $list = function (array $filters, $search = '', $view = 'pending') use ($src) {
        return call_user_func($src['list'], array('view' => $view, 'search' => $search, 'offset' => 0, 'limit' => 100, 'filters' => $filters));
    };
    $all = $list(array());
    $state = $before['state'];
    $by_state = $list(array('state' => $state));
    $check('inspection-highlights: the state filter keeps that state only', $by_state['total'] > 0 && $by_state['total'] <= $all['total']
        && !array_filter($by_state['items'], function ($it) use ($state) { return strpos($it['subtitle'], ' · ' . $state) === false && strpos($it['subtitle'], $state) === false; }));
    $cat = explode(',', $before['categories'])[0];
    $n_cat = (int) $pdo->query('SELECT COUNT(*) FROM inspection_highlights WHERE status = \'pending\' AND (\',\' || categories || \',\') LIKE ' . $pdo->quote('%,' . $cat . ',%'))->fetchColumn();
    $check('inspection-highlights: the category filter matches any of a finding\'s categories', $list(array('category' => $cat))['total'] === $n_cat, $cat . ' ' . $n_cat);
    $min = $list(array('min' => '90'));
    $check('inspection-highlights: the score filter', !array_filter($min['items'], function ($it) { return !preg_match('/^Score (9\d|100)\b/', $it['subtitle']); }));
    $worst = $list(array('sort' => 'worst'));
    $scores = array_map(function ($it) { return (int) substr($it['subtitle'], 6); }, $worst['items']);
    $sorted = $scores;
    rsort($sorted);
    $check('inspection-highlights: Worst first sorts by score', $scores === $sorted);
    $picked = $list(array(), '#' . $id);
    $check('inspection-highlights: finding numbers in the search pick those findings', $picked['total'] === 1 && $picked['items'][0]['key'] === (string) $id);
    $counts = call_user_func($src['view_counts'], array());
    $check('inspection-highlights: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['pending'] === $all['total'], json_encode($counts));
    $fresh = kop_rinbox_get_item('inspection-highlights', (string) $id);
    $appr = array_values(array_filter($fresh['actions'], function ($a) { return $a['id'] === 'approved'; }))[0] ?? null;
    $check('inspection-highlights: the decision carries the optional note', $appr && $appr['params'][0]['name'] === 'note' && !empty($appr['params'][0]['optional']));
    $check('inspection-highlights: the full report opens in the card', $fresh['preview'] && strpos($fresh['preview']['url'], 'format=text') !== false);
    call_user_func($src['act'], (string) $id, 'rejected', array('note' => 'not the facility'));
    $check('inspection-highlights: the note is written with the decision', $row()['review_note'] === 'not the facility');
    call_user_func($src['act'], (string) $id, 'pending', array('note' => ''));
    $pdo->prepare('UPDATE inspection_highlights SET review_note = ? WHERE id = ?')->execute(array($before['review_note'], $id));

    try {
        call_user_func($src['act'], $item['key'], 'publish', array());
        $check('inspection-highlights: an unknown action is refused', false);
    } catch (RuntimeException $e) {
        $check('inspection-highlights: an unknown action is refused', true, $e->getMessage());
    }
}
