<?php
/**
 * scripts/test-review-inbox.php checks for the 'highlight-summaries' source
 * (inc/review-inbox/highlight-summaries.php over inc/highlight-summaries.php),
 * on the mirror's approved findings, with a made-up AI. Two hard-to-read
 * findings get a draft each, so the queue has something waiting.
 */

require_once dirname(__DIR__, 2) . '/inc/inspection-highlights.php';
require_once dirname(__DIR__, 2) . '/inc/highlight-summaries.php';

(function () {
    $pdo = $GLOBALS['pdo'];
    // The table is made by the code under test, on SQLite here.
    kop_hs_ensure_table($pdo);
    foreach (kop_hs_candidates($pdo, 2) as $r) {
        kop_hs_store($pdo, (int) $r['id'], kop_hs_hash($r['excerpt']), 'A staff member hurt a child in the program, and the state found the report to be true.', $r['reasons'], 'groq');
    }
})();

function kop_rinbox_test_highlight_summaries(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $id = (int) $item['key'];
    $row = function () use ($pdo, $id) {
        $st = $pdo->prepare('SELECT * FROM inspection_highlight_summaries WHERE highlight_id = ?');
        $st->execute(array($id));
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $finding = function () use ($pdo, $id) {
        $st = $pdo->prepare('SELECT excerpt FROM inspection_highlights WHERE id = ?');
        $st->execute(array($id));
        return (string) $st->fetchColumn();
    };
    $public = function () use ($id, $finding) {
        return kop_hs_public_summaries(array(array('id' => $id, 'excerpt' => $finding())));
    };
    $check('highlight-summaries: the waiting item is a pending draft', $row()['status'] === 'pending' && $item['fields'][0]['name'] === 'summary' && $item['fields'][0]['type'] === 'textarea');
    $check('highlight-summaries: a pending draft is not public', $public() === array());
    $check('highlight-summaries: the card shows why it was picked and the state\'s wording', strpos($item['subtitle'], 'Hard to read') !== false && strpos($item['text'], "state's wording") !== false, $item['subtitle']);

    // Editing: the same checks as an AI draft.
    foreach (array('A [redacted] hit a child and ran away from the facility.', 'S1 hit C1 and ran away from the facility.', 'Too short.', 'See https://example.com for what the report says about it.') as $bad) {
        try {
            call_user_func($src['save'], $item['key'], array('summary' => $bad));
            $check('highlight-summaries: an edit with codes, brackets, a link or too few words is refused: ' . $bad, false);
        } catch (RuntimeException $e) {
            $check('highlight-summaries: an edit with codes, brackets, a link or too few words is refused: ' . $bad, true, $e->getMessage());
        }
    }
    $edited = 'A staff member hit a child in the program. The state found this to be true.';
    call_user_func($src['save'], $item['key'], array('summary' => $edited));
    $check('highlight-summaries: a good edit is saved', $row()['summary'] === $edited);

    // Approve shows it, to the exact text it was written from.
    $res = call_user_func($src['act'], $item['key'], 'approved', array());
    $r = $row();
    $check('highlight-summaries: approve records who and when', $r['status'] === 'approved' && $r['reviewed_by'] === 'inbox-test' && $r['reviewed_at'] !== null, $res['message']);
    $check('highlight-summaries: an approved summary is public', ($public()[$id] ?? '') === $edited);
    $original = $finding();
    $pdo->prepare('UPDATE inspection_highlights SET excerpt = ? WHERE id = ?')->execute(array($original . ' One more sentence.', $id));
    $check('highlight-summaries: it stops showing when the state\'s text changes', $public() === array());
    $stale = kop_rinbox_get_item('highlight-summaries', $item['key']);
    $check('highlight-summaries: a changed finding is marked out of date and cannot be approved', $stale['status_label'] === 'Out of date' && !in_array('approved', array_column($stale['actions'], 'id'), true));
    $pdo->prepare('UPDATE inspection_highlight_summaries SET status = ? WHERE highlight_id = ?')->execute(array('pending', $id));
    try {
        call_user_func($src['act'], $item['key'], 'approved', array());
        $check('highlight-summaries: approving an out-of-date draft is refused', false);
    } catch (RuntimeException $e) {
        $check('highlight-summaries: approving an out-of-date draft is refused', true, $e->getMessage());
    }
    $pdo->prepare('UPDATE inspection_highlights SET excerpt = ? WHERE id = ?')->execute(array($original, $id));

    // Back to pending takes it off the site; reject keeps it off.
    call_user_func($src['act'], $item['key'], 'approved', array());
    call_user_func($src['act'], $item['key'], 'pending', array());
    $check('highlight-summaries: back to pending takes it off the site', $row()['status'] === 'pending' && $row()['reviewed_by'] === null && $public() === array());
    call_user_func($src['act'], $item['key'], 'rejected', array());
    $back = kop_rinbox_get_item('highlight-summaries', $item['key']);
    $check('highlight-summaries: a rejected draft can still be approved, written again or put back', array_column($back['actions'], 'id') === array('approved', 'rewrite', 'pending'), implode(',', array_column($back['actions'], 'id')));

    // Write it again: a made-up AI.
    $prompts = array();
    $GLOBALS['kop_hs_ai'] = function ($prompt) use (&$prompts) { $prompts[] = $prompt; return 'UNCLEAR'; };
    try {
        call_user_func($src['act'], $item['key'], 'rewrite', array());
        $check('highlight-summaries: an AI that cannot tell what happened gives no draft', false);
    } catch (RuntimeException $e) {
        $check('highlight-summaries: an AI that cannot tell what happened gives no draft', true, $e->getMessage());
    }
    $check('highlight-summaries: the AI is told to use only the text, no names, no codes', $prompts && strpos($prompts[0], 'Never use a person\'s name') !== false && strpos($prompts[0], 'Use only what the text says') !== false);
    $check('highlight-summaries: the AI reads the labels spelled out, not the state\'s codes', $prompts && !preg_match('/(?<![\w#\[])[SECYR]\d{1,2}\b/', explode('Text:', $prompts[0])[1]));
    $GLOBALS['kop_hs_ai'] = function ($prompt) { return "Summary: **A staff member pushed a child in the program.** The state said the report was true."; };
    call_user_func($src['act'], $item['key'], 'rewrite', array());
    $r = $row();
    $check('highlight-summaries: write again stores a clean pending draft', $r['status'] === 'pending' && $r['summary'] === 'A staff member pushed a child in the program. The state said the report was true.', $r['summary']);

    // The "write more drafts now" button and the hourly job: only hard-to-read, approved findings with no summary.
    $GLOBALS['kop_hs_ai'] = function ($prompt) { return 'A child in the program reported being hurt by staff, and the state looked into it.'; };
    $before = (int) $pdo->query('SELECT COUNT(*) FROM inspection_highlight_summaries')->fetchColumn();
    $res = call_user_func($src['tool'], 'write', array());
    $after = (int) $pdo->query('SELECT COUNT(*) FROM inspection_highlight_summaries')->fetchColumn();
    $check('highlight-summaries: the write button drafts up to 5 more', $after - $before === 5 && strpos($res['message'], '5 drafts written') === 0, $res['message']);
    $bad = 0;
    foreach ($pdo->query("SELECT s.highlight_id, h.excerpt, h.status FROM inspection_highlight_summaries s JOIN inspection_highlights h ON h.id = s.highlight_id") as $x) {
        if ($x['status'] !== 'approved') $bad++;
    }
    $check('highlight-summaries: only approved findings are written about', $bad === 0);
    $GLOBALS['kop_hs_ai'] = function ($prompt) { throw new Exception('rate limit'); };
    $res = kop_hs_run_batch($pdo, 5);
    $check('highlight-summaries: provider errors stop the batch after three and write nothing', $res['written'] === 0 && $res['errors'] === 3);
    $GLOBALS['kop_hs_ai'] = function ($prompt) { return 'UNCLEAR'; };
    $res = kop_hs_run_batch($pdo, 1);
    $parked = $pdo->query("SELECT status, reviewed_by FROM inspection_highlight_summaries WHERE summary LIKE 'No summary:%'")->fetch(PDO::FETCH_ASSOC);
    $check('highlight-summaries: a finding the AI cannot summarise is parked, not retried every hour', $res['unclear'] === 1 && $parked && $parked['status'] === 'rejected' && $parked['reviewed_by'] === 'system');
    $none = $pdo->query("SELECT highlight_id FROM inspection_highlight_summaries WHERE summary LIKE 'No summary:%'")->fetchColumn();
    $card = kop_rinbox_get_item('highlight-summaries', (string) $none);
    $check('highlight-summaries: a parked one says "Not written" and can be written again', $card['status_label'] === 'Not written' && array_column($card['actions'], 'id') === array('rewrite'));
    unset($GLOBALS['kop_hs_ai']);
}
