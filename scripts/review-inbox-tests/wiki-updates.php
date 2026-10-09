<?php
/**
 * scripts/test-review-inbox.php checks for the 'wiki-updates' source
 * (inc/review-inbox/wiki-updates.php over inc/wiki-update-drafts.php): the
 * site's assembler gives exactly scripts/wiki-drafts.py's draft.md for every
 * draft in tmp/wiki-updates/drafts/; a card shows each added line, the past
 * tense side by side and its fields; edits and the past-tense switch change
 * the draft; Approve writes only the entry's own column, Pasted on Reddit and
 * its Undo, Undo puts back the exact text and updated_at; Set aside and Undo;
 * an entry edited since drafting still takes the additions.
 */

require_once __DIR__ . '/_shared.php';

if (!function_exists('delete_option')) {
    function delete_option($name) { unset($GLOBALS['kop_test_options'][$name]); return true; }
}

function kop_rinbox_test_wiki_updates(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];

    // The PHP assembler == the script's, on every local draft.
    $dir = dirname(__DIR__, 2) . '/tmp/wiki-updates/drafts';
    $same = $diff = array();
    foreach (kop_wiki_drafts_all() as $id => $d) {
        if (!is_file("$dir/$id/entry.md") || !is_file("$dir/$id/draft.md")) continue;
        // The names line comes from the record on the site only (kop_wiki_drafts_add_names()): compared without it.
        $b = kop_wiki_drafts_build($id, file_get_contents("$dir/$id/entry.md"), array('names' => ''));
        $want = str_replace("\r\n", "\n", file_get_contents("$dir/$id/draft.md"));
        if ($b['md'] === $want && !$b['errors'] && !$b['stale']) $same[] = $id;
        else {
            // The first line that differs, so a mismatch can be found without rerunning both sides.
            $g = explode("\n", $b['md']);
            $w = explode("\n", $want);
            for ($k = 0; $k < max(count($g), count($w)) && ($g[$k] ?? null) === ($w[$k] ?? null); $k++);
            $diff[] = $id . ($b['errors'] ? ' (' . $b['errors'][0] . ')'
                : ' (line ' . ($k + 1) . ': site ' . json_encode(mb_substr($g[$k] ?? '', 0, 60)) . ', script ' . json_encode(mb_substr($w[$k] ?? '', 0, 60)) . ')');
        }
    }
    $check('wiki-updates: the site builds exactly the script\'s draft for every local draft', $same && !$diff, count($same) . ' same; differ: ' . implode(', ', $diff));

    // Previous & alternate names: one bold line right under the header, above the type line, kept as it is for
    // Reddit, never the entry's own name, years after the names that have them; building again from that text
    // changes nothing, and an old bold-italic line is rewritten in bold.
    $named = $bad = $dated = array();
    foreach (kop_wiki_drafts_all() as $id => $d) {
        if (($d['ops'][0]['op'] ?? '') !== 'set_alternate_names' || !is_file("$dir/$id/entry.md")) continue;
        $entry = file_get_contents("$dir/$id/entry.md");
        $lines = kop_wiki_drafts_lines(kop_wiki_drafts_build($id, $entry, array())['md']);
        $own = preg_match('/^#+\s*\*\*(.+?)\*\*/u', $lines[0], $h) ? kop_wiki_upd_key($h[1]) : '';
        $ok = preg_match(KOP_WIKI_DRAFTS_NAMES_RE, $lines[1], $m) && strpos($lines[1], '***') === false
            && !in_array($own, array_map('kop_wiki_upd_key', kop_wiki_drafts_split_names($m[1])), true);
        $again = kop_wiki_drafts_build($id, implode("\n", $lines), array());
        $again_lines = kop_wiki_drafts_lines($again['md']);
        $ok = $ok && !$again['problems'] && count(preg_grep(KOP_WIKI_DRAFTS_NAMES_RE, $again_lines)) === 1 && $again_lines[1] === $lines[1];
        $old = $lines;
        $old[1] = '***' . trim($lines[1], '*') . '***';
        $ok = $ok && kop_wiki_drafts_lines(kop_wiki_drafts_build($id, implode("\n", $old), array())['md'])[1] === $lines[1];
        if ($ok) $named[] = $id;
        else $bad[] = $id;
        if (preg_match('/\(\D*\d{4}/', $lines[1])) $dated[$id] = $lines[1];
    }
    $check('wiki-updates: drafts with other names get one bold names line under the header', $named && !$bad, count($named) . ' named; wrong: ' . implode(', ', $bad));
    $check('wiki-updates: names carry the years they were used where known', (bool) $dated, count($dated) . ' with years, e.g. ' . implode(' | ', array_slice($dated, 0, 3)));

    // An entry whose text is still the one drafted from (an entry already approved and pasted holds the additions
    // itself, so leaving a line out could not take it off): the first such card stands in for the harness's pick.
    $key = (int) $item['key'];
    foreach (array_merge(array($key), array_keys(kop_rinbox_wupd_rows())) as $k) {
        $rr = kop_rinbox_wupd_rows()[$k] ?? null;
        if ($rr && $rr['view'] === 'review' && count($rr['draft']['ops'] ?? array()) > 2
            && !kop_wiki_drafts_build((int) $k, $rr['row']['markdown'], array())['stale']) {
            $key = (int) $k;
            break;
        }
    }
    $item = kop_rinbox_get_item('wiki-updates', (string) $key) ?: $item;
    $d = kop_wiki_drafts_all()[$key];
    $ops = array_values(array_filter($d['ops'], function ($o) { return ($o['op'] ?? '') !== 'set_header_years'; }));
    $names = array_column($item['fields'], 'name');
    $check('wiki-updates: a card has one field per added line and Approve / Set aside', $names && array_diff(array_column($d['ops'], 'id'), $names) === array()
        && array_column($item['actions'], 'id') === array('approve', 'skip'), implode(',', $names));
    $check('wiki-updates: a card shows the whole entry and links it on Reddit', !empty($item['preview']['url']) && strpos($item['preview']['url'], 'kop_wiki_draft_view') !== false);

    $tensed = null;
    foreach (kop_rinbox_wupd_rows() as $r) if (!empty($r['draft']['tense']) && $r['view'] === 'review') { $tensed = $r; break; }
    if ($tensed) {
        $card = kop_rinbox_get_item('wiki-updates', (string) $tensed['id']);
        $check('wiki-updates: the past tense is shown side by side, with a switch', count($card['compare']['rows'] ?? array()) === count($tensed['draft']['tense'])
            && in_array('_tense', array_column($card['fields'], 'name'), true));
    }

    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('wiki-updates: edit, approve and undo', 'update_option does not store in this harness');
        return;
    }
    $row = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT original_markdown, generated_markdown, updated_at FROM wiki_submissions WHERE id = ?');
        $st->execute(array($id));
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $before = $row($key);
    $r = kop_rinbox_wupd_rows()[$key];
    $col = $r['row']['column'];
    $other = $col === 'original_markdown' ? 'generated_markdown' : 'original_markdown';

    // Edits: change the first line, leave out the second.
    $first = $ops[0] ?? null;
    if ($first) {
        $fields = array($first['id'] => $first['text'] . ' EDITED');
        if (isset($ops[1])) $fields[$ops[1]['id']] = '';
        call_user_func($src['save'], (string) $key, $fields);
        $b = kop_wiki_drafts_build($key, $r['row']['markdown']);
        $check('wiki-updates: an edited line goes in as edited (in Reddit form)', strpos($b['md'], rtrim(kop_wiki_drafts_reddit_format($first['text'] . ' EDITED'), "\n")) !== false);
        if (isset($ops[1])) $check('wiki-updates: an emptied line is left out', strpos($b['md'], trim(explode("\n", $ops[1]['text'])[0])) === false);
        $card = kop_rinbox_get_item('wiki-updates', (string) $key);
        $check('wiki-updates: the card shows the edit', in_array($first['text'] . ' EDITED', array_column($card['details'], 'value'), true));
        call_user_func($src['save'], (string) $key, array($first['id'] => $first['text']) + (isset($ops[1]) ? array($ops[1]['id'] => $ops[1]['text']) : array()));
        $check('wiki-updates: saving the original text again clears the edits', kop_wiki_drafts_edits($key) === array());
    }

    $want = kop_wiki_drafts_build($key, $r['row']['markdown'])['md'];
    $res = call_user_func($src['act'], (string) $key, 'approve', array());
    $after = $row($key);
    $check('wiki-updates: Approve writes the update into the entry\'s own column only', $after[$col] === $want && $after[$other] === $before[$other], $res['message']);
    $check('wiki-updates: the entry is the newest row again', $after['updated_at'] !== $before['updated_at']);
    $got = kop_rinbox_get_item('wiki-updates', (string) $key);
    $check('wiki-updates: it moves to Ready for Reddit with Pasted on Reddit and Undo', $got['status'] === 'ready' && array_column($got['actions'], 'id') === array('posted', 'undo'));
    call_user_func($src['act'], (string) $key, 'posted', array());
    $check('wiki-updates: Pasted on Reddit moves it to On Reddit', kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'posted');
    call_user_func($src['act'], (string) $key, 'undo', array());
    $check('wiki-updates: its Undo moves it back to Ready for Reddit, the text unchanged', kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'ready' && $row($key) == $after);
    call_user_func($src['act'], (string) $key, 'undo', array());
    $check('wiki-updates: Undo puts back the exact text and updated_at', $row($key) == $before && kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'review');

    // Undo refuses to lose an edit made after approving.
    call_user_func($src['act'], (string) $key, 'approve', array());
    $pdo->prepare("UPDATE wiki_submissions SET $col = $col || 'x' WHERE id = ?")->execute(array($key));
    $refused = false;
    try { call_user_func($src['act'], (string) $key, 'undo', array()); } catch (RuntimeException $e) { $refused = true; }
    $check('wiki-updates: Undo refuses when the entry was edited after approving', $refused);
    $skipped = kop_wiki_drafts_refresh_approved($pdo);
    $check('wiki-updates: a draft fix leaves an entry edited after approving as it is', $row($key)[$col] === $want . 'x'
        && in_array($key, array_column($skipped, 0), true));

    // An entry approved before a later fix to its draft (stood in for by an older approved text) is built again,
    // pasted ones go back to Ready for Reddit, and Undo still puts back the text from before approving.
    $older = str_replace("\n", "\n\n", $want);
    $pdo->prepare("UPDATE wiki_submissions SET $col = ? WHERE id = ?")->execute(array($older, $key));
    $s = kop_wiki_drafts_state()[$key];
    $s['sha1'] = sha1($older);
    $s['view'] = 'posted';
    kop_wiki_drafts_set_state($key, $s);
    $done = kop_wiki_drafts_refresh_approved($pdo);
    kop_rinbox_wupd_rows(true);
    $check('wiki-updates: a draft fix reaches an approved entry, pasted ones go back to Ready for Reddit', $row($key)[$col] === $want
        && kop_wiki_drafts_state()[$key]['view'] === 'ready' && in_array($key, array_column($done, 0), true), json_encode($done));
    $check('wiki-updates: a second run changes nothing', !in_array($key, array_column(kop_wiki_drafts_refresh_approved($pdo), 0), true));
    $reopened = kop_wiki_drafts_reopen_all($pdo);
    kop_rinbox_wupd_rows(true);
    $check('wiki-updates: reopening every approved entry undoes each, back on To review with the text from before approving',
        $row($key)[$col] === $before[$col] && kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'review'
        && in_array(array($key, 'back on To review'), $reopened, true), json_encode($reopened));
    $pdo->prepare("UPDATE wiki_submissions SET original_markdown = ?, generated_markdown = ?, updated_at = ? WHERE id = ?")
        ->execute(array($before['original_markdown'], $before['generated_markdown'], $before['updated_at'], $key));
    kop_wiki_drafts_set_state($key, null);
    kop_rinbox_wupd_rows(true);

    call_user_func($src['act'], (string) $key, 'skip', array());
    $check('wiki-updates: Set aside leaves the entry as it is', kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'skip' && $row($key) == $before);
    call_user_func($src['act'], (string) $key, 'undo', array());
    $check('wiki-updates: and Undo brings it back', kop_rinbox_get_item('wiki-updates', (string) $key)['status'] === 'review');

    // The Reddit form undoes the markdown_output conversion: bold opened with a space, a lost bullet, the header's space, the footer.
    $fmt = kop_wiki_drafts_reddit_format("# **X**(1990-present) Town, UT

## ** History**

***Level One:** rules (see [** Y**](https://example.test))

**Name** was there.

---

Last revised by [u](/user/u/)
## Page title
");
    $check('wiki-updates: the text is put in Reddit form', $fmt === "# **X** (1990-present) Town, UT

## **History**

* **Level One:** rules (see [**Y**](https://example.test))

**Name** was there.
", json_encode($fmt));

    // An entry edited since drafting still takes the additions, placed by heading.
    $md = $r['row']['markdown'];
    $lines = kop_wiki_drafts_lines($md);
    array_splice($lines, 1, 0, array('', 'A line someone added later.'));
    $b = kop_wiki_drafts_build($key, implode("\n", $lines));
    $check('wiki-updates: an entry edited since drafting is marked and still takes every addition', $b['stale'] && !$b['problems']
        && strpos($b['md'], 'A line someone added later.') !== false && count($b['added']) === count(kop_wiki_drafts_build($key, $md)['added']), implode('; ', $b['errors']));
}
