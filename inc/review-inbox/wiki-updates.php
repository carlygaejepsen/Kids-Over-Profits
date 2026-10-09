<?php
/**
 * Review inbox source: r/troubledteens wiki entries brought up to date from
 * KOP (docs/PLAN.md 3.12 step 5, inc/wiki-update-drafts.php).
 *
 * One card per drafted entry: each added line with what the checker noted,
 * the lines put in the past tense side by side, anything in the record that
 * contradicts the entry, and "Show the whole entry" (the text with the changes
 * marked and a Copy button). "Edit details" changes or empties any added line
 * and turns the past tense off. Tabs: To review (Approve writes the update
 * into KOP's copy of the entry, Set aside), Ready for Reddit (copy, paste on
 * Reddit, then "Pasted on Reddit"), On Reddit, Set aside; Undo on each.
 *
 * Keys: the wiki_submissions id of the entry's newest row.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/wiki-update-drafts.php';

kop_rinbox_register('wiki-updates', function () {
    return array(
        'label'    => 'Wiki updates',
        'group'    => 'Suggestions to check',
        'help'     => 'r/troubledteens wiki entries with what KOP has learned since they were written (closures, news, lawsuits, deaths, findings) added, each line with its source. Approve writes the update into KOP\'s copy of the entry; then copy it onto the Reddit page. Undo on every tab.',
        'views'    => array('review' => 'To review', 'ready' => 'Ready for Reddit', 'posted' => 'On Reddit', 'skip' => 'Set aside'),
        'count'    => function () { return kop_rinbox_wupd_view_counts()['review']; },
        'view_counts' => 'kop_rinbox_wupd_view_counts',
        'list'     => 'kop_rinbox_wupd_list',
        'get'      => function ($key) {
            $rows = kop_rinbox_wupd_rows();
            return isset($rows[(int) $key]) ? kop_rinbox_wupd_item($rows[(int) $key]) : null;
        },
        'act'      => 'kop_rinbox_wupd_act',
        'save'     => 'kop_rinbox_wupd_save',
        'ai_fill'  => function () {
            throw new RuntimeException('Wiki updates are written from the record and checked against their sources; edit a line by hand instead.');
        },
    );
});

/** Every drafted entry that is still there: id => draft + row + view. */
function kop_rinbox_wupd_rows($reset = false) {
    static $rows = null;
    if ($reset) {
        $rows = null;
        return array();
    }
    if ($rows !== null) return $rows;
    $rows = array();
    $drafts = kop_wiki_drafts_all();
    if (!$drafts) return $rows;
    $pdo = kop_wiki_upd_pdo();
    $state = kop_wiki_drafts_state();
    foreach ($drafts as $id => $d) {
        try {
            $row = kop_wiki_drafts_row($pdo, $id);
        } catch (RuntimeException $e) {
            continue;
        }
        $row['page'] = kop_wiki_upd_page_of($row);
        $rows[$id] = array('id' => $id, 'draft' => $d, 'row' => $row, 'state' => $state[$id] ?? null,
            'view' => $state[$id]['view'] ?? 'review');
    }
    return $rows;
}

function kop_rinbox_wupd_view_counts() {
    $counts = array('review' => 0, 'ready' => 0, 'posted' => 0, 'skip' => 0);
    foreach (kop_rinbox_wupd_rows() as $r) $counts[$r['view']] = ($counts[$r['view']] ?? 0) + 1;
    return $counts;
}

function kop_rinbox_wupd_list(array $q) {
    $rows = array_filter(kop_rinbox_wupd_rows(), function ($r) use ($q) {
        if ($r['view'] !== $q['view']) return false;
        if ($q['search'] === '') return true;
        $hay = mb_strtolower($r['row']['program_name'] . ' ' . $r['row']['city_state'] . ' ' . ($r['draft']['record']['name'] ?? ''));
        return mb_strpos($hay, mb_strtolower($q['search'])) !== false;
    });
    uasort($rows, function ($a, $b) { return strcasecmp($a['row']['program_name'], $b['row']['program_name']); });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $r) $items[] = kop_rinbox_wupd_item($r);
    return array('items' => $items, 'total' => count($rows));
}

/** What an op does, in words: "Adds to Related Media", "New section: Closure", "Header years". */
function kop_rinbox_wupd_op_label(array $op) {
    switch ($op['op'] ?? '') {
        case 'set_header_years': return 'Header years';
        case 'set_alternate_names': return 'Previous & alternate names (above the type line)';
        case 'add_section': return 'New section: ' . trim(preg_replace('/[#*]+/', '', (string) ($op['heading'] ?? '')));
        case 'move_to_section': return 'Moves from ' . ($op['from_section'] ?? '') . ' to ' . ($op['section'] ?? '');
        default: return 'Adds to ' . trim(preg_replace('/[#*]+/', '', (string) ($op['section'] ?? '')));
    }
}

function kop_rinbox_wupd_item(array $r) {
    $id = (int) $r['id'];
    $d = $r['draft'];
    $row = $r['row'];
    $view = $r['view'];
    $name = trim((string) $row['program_name']);
    $edits = kop_wiki_drafts_edits($id);
    $details = array();
    $fields = array();
    $compare = null;
    $text = '';

    $rec = (array) ($d['record'] ?? array());
    if (!empty($rec['name'])) {
        $url = !empty($rec['id']) && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $rec['id']) : '';
        $details[] = array('label' => 'Drawn from the record', 'value' => $rec['name'] . (!empty($rec['status']) ? ' (' . $rec['status'] . ')' : ''), 'url' => $url);
    }

    if ($view === 'review') {
        $b = kop_wiki_drafts_build($id, $row['markdown'], $edits);
        foreach ((array) ($d['ops'] ?? array()) as $op) {
            $label = kop_rinbox_wupd_op_label($op);
            $value = ($op['op'] ?? '') === 'set_header_years' ? (string) $op['years'] : (string) ($op['text'] ?? '');
            if (isset($edits[$op['id']])) $value = (string) $edits[$op['id']];
            $out = trim($value) === '';
            $details[] = array('label' => $out ? $label . ' (left out)' : $label, 'value' => $out ? '' : $value);
            if (!$out && trim((string) ($op['note'] ?? '')) !== '') $details[] = array('label' => 'Checked', 'value' => (string) $op['note']);
            $fields[] = array('name' => (string) $op['id'], 'label' => $label . ' (empty it to leave this out)',
                'type' => in_array($op['op'] ?? '', array('set_header_years', 'set_alternate_names'), true) ? 'text' : 'textarea', 'value' => $value);
        }
        $tense = (array) ($d['tense'] ?? array());
        if ($tense) {
            $on = (string) ($edits['_tense'] ?? '1') === '1';
            $fields[] = array('name' => '_tense', 'label' => 'Put the lines below in the past tense (the program has closed or changed its name)', 'type' => 'checkbox', 'value' => $on ? '1' : '');
            if ($on) {
                $compare = array('heads' => array('The entry says', 'In the past tense'), 'rows' => array());
                foreach ($tense as $p) $compare['rows'][] = array('label' => '', 'values' => array($p['old'], kop_wiki_drafts_format_line($p['new'])));
            } else {
                $details[] = array('label' => 'Past tense', 'value' => 'Left out: the entry keeps its present tense.');
            }
        }
        foreach ((array) ($d['fixes'] ?? array()) as $f) {
            $details[] = array('label' => 'Corrects a line', 'value' => kop_wiki_drafts_format_line($f['new']) . (($f['note'] ?? '') !== '' ? ' (' . $f['note'] . ')' : ''));
        }
        foreach ((array) ($d['conflicts'] ?? array()) as $c) {
            $details[] = array('label' => 'KOP\'s record says otherwise', 'value' => $c['text'] . ($c['source_label'] !== '' ? ' (' . $c['source_label'] . ')' : ''),
                'url' => (string) ($c['source_url'] ?? ''));
        }
        foreach ($b['errors'] as $err) $details[] = array('label' => 'Could not place', 'value' => $err);
        $n = $b['count'];
        $text = $n . ' line' . ($n === 1 ? '' : 's') . ' added' . ($b['tensed'] ? ', ' . count($b['tensed']) . ' put in the past tense' : '')
            . '. Every added line names its source; check each, change or empty any line in "Edit details", then approve.'
            . ($b['stale'] ? ' The entry was edited after this draft was made: the additions are placed by section heading, so check where they sit in "Show the whole entry".' : '');
    } elseif ($view === 'ready') {
        $text = 'Approved: KOP\'s copy of the entry has the update. Copy the whole entry, paste it over the page on Reddit (Edit it on Reddit), then click Pasted on Reddit.';
    } elseif ($view === 'posted') {
        $text = 'Pasted on Reddit.';
    } else {
        $text = 'Set aside: the entry stays as it is.';
    }
    if (!empty($r['state']['by'])) $details[] = array('label' => $view === 'skip' ? 'Set aside by' : 'Approved by', 'value' => $r['state']['by'] . ', ' . substr((string) $r['state']['at'], 0, 10));

    $reddit = kop_wiki_drafts_reddit_url($row);
    $links = array();
    if ($reddit !== '') $links[] = array('label' => 'The entry on the Reddit wiki', 'url' => $reddit);
    $links[] = array('label' => 'The whole entry with the changes, and Copy', 'url' => kop_wiki_drafts_view_url($id));
    $edit = $view === 'ready' ? kop_wiki_drafts_reddit_edit_url($reddit) : '';

    if ($view === 'review') {
        $actions = array(
            array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve',
                'help' => 'Writes the update into KOP\'s copy of ' . $name . ' (the lines above, as edited) and moves it to Ready for Reddit. Undo puts back the text it had.'),
            array('id' => 'skip', 'label' => 'Set aside', 'style' => 'reject',
                'help' => 'Leaves ' . $name . ' as it is; it moves to Set aside.'),
        );
    } elseif ($view === 'ready') {
        $actions = array(
            array('id' => 'posted', 'label' => 'Pasted on Reddit', 'style' => 'approve',
                'help' => 'Marks the update as pasted onto the Reddit page; it moves to On Reddit.'),
            array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
                'help' => 'Puts back the text ' . $name . ' had before it was approved; the draft waits on To review again.'),
        );
    } else {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'posted' ? 'Moves it back to Ready for Reddit.' : $name . ' waits on To review again.'));
    }

    $item = array(
        'key'          => (string) $id,
        'title'        => $name,
        'subtitle'     => implode(' · ', array_filter(array(trim((string) $row['city_state'], ' -'), (string) $row['years_active']))),
        'text'         => $text,
        'details'      => $details,
        'status'       => $view,
        'status_label' => array('review' => '', 'ready' => 'Ready for Reddit', 'posted' => 'On Reddit', 'skip' => 'Set aside')[$view] ?? '',
        'created'      => (string) ($r['state']['at'] ?? $row['updated_at']),
        'facility'     => !empty($rec['id']) ? kop_rinbox_facility((int) $rec['id']) : null,
        'preview'      => array('label' => 'Show the whole entry', 'url' => kop_wiki_drafts_view_url($id)),
        'links'        => $links,
        'actions'      => $actions,
    );
    if ($fields) $item['fields'] = $fields;
    if ($compare) $item['compare'] = $compare;
    if ($view === 'ready') {
        // Copy, Edit it on Reddit and Pasted on Reddit side by side on the card (js/review-inbox.js),
        // so pasting needs no trip to the whole-entry page. KOP's copy is the approved text.
        $item['copy'] = array('label' => 'Copy the whole entry', 'text' => (string) $row['markdown']);
        if ($edit !== '') $item['go'] = array('label' => 'Edit it on Reddit', 'url' => $edit);
    }
    return $item;
}

function kop_rinbox_wupd_save($key, array $fields) {
    $rows = kop_rinbox_wupd_rows();
    $r = $rows[(int) $key] ?? null;
    if (!$r) throw new RuntimeException('That wiki update is not here any more.');
    if ($r['view'] !== 'review') throw new RuntimeException('Undo it first: only a draft on To review can be changed.');
    $edits = kop_wiki_drafts_edits($r['id']);
    foreach ((array) ($r['draft']['ops'] ?? array()) as $op) {
        if (!array_key_exists($op['id'], $fields)) continue;
        $orig = ($op['op'] ?? '') === 'set_header_years' ? (string) $op['years'] : (string) ($op['text'] ?? '');
        $v = str_replace("\r\n", "\n", (string) $fields[$op['id']]);
        if (trim($v) === trim($orig)) unset($edits[$op['id']]);
        else $edits[$op['id']] = $v;
    }
    if (array_key_exists('_tense', $fields)) {
        if ((string) $fields['_tense'] === '1') unset($edits['_tense']);
        else $edits['_tense'] = '';
    }
    $b = kop_wiki_drafts_build($r['id'], $r['row']['markdown'], $edits);
    if ($b['problems']) throw new RuntimeException('Not saved: ' . implode('; ', $b['problems']) . '.');
    kop_wiki_drafts_save_edits($r['id'], $edits);
    kop_rinbox_wupd_rows(true);
    return array('message' => 'Saved.');
}

function kop_rinbox_wupd_act($key, $action, array $params) {
    $rows = kop_rinbox_wupd_rows();
    $r = $rows[(int) $key] ?? null;
    if (!$r) throw new RuntimeException('That wiki update is not here any more.');
    $id = (int) $r['id'];
    $name = trim((string) $r['row']['program_name']);
    $by = kop_rinbox_reviewer();
    $pdo = kop_wiki_upd_pdo();
    if ($action === 'undo') {
        if ($r['view'] === 'posted') {
            $s = $r['state'];
            $s['view'] = 'ready';
            kop_wiki_drafts_set_state($id, $s);
            $msg = 'Undone. ' . $name . ' is back on Ready for Reddit.';
        } elseif ($r['view'] === 'ready') {
            kop_wiki_drafts_undo($pdo, $id);
            $msg = 'Undone. ' . $name . ' has its old text back and waits on To review.';
        } else {
            kop_wiki_drafts_set_state($id, null);
            $msg = 'Undone. ' . $name . ' waits on To review.';
        }
        kop_rinbox_wupd_rows(true);
        return array('message' => $msg);
    }
    if ($action === 'posted') {
        if ($r['view'] !== 'ready') throw new RuntimeException('Approve it first.');
        $s = $r['state'];
        $s['view'] = 'posted';
        $s['posted_at'] = gmdate('c');
        kop_wiki_drafts_set_state($id, $s);
        kop_rinbox_wupd_rows(true);
        return array('message' => 'Marked as pasted on Reddit. ' . $name . ' is on the On Reddit tab.');
    }
    if ($r['view'] !== 'review') throw new RuntimeException('Undo this entry first.');
    if ($action === 'skip') {
        kop_wiki_drafts_set_state($id, array('view' => 'skip', 'by' => $by, 'at' => gmdate('c')));
        kop_rinbox_wupd_rows(true);
        return array('message' => 'Set aside. ' . $name . ' stays as it is; Undo is on the Set aside tab.');
    }
    if ($action === 'approve') {
        $b = kop_wiki_drafts_approve($pdo, $id, $by);
        kop_rinbox_wupd_rows(true);
        return array('message' => 'Approved. ' . $name . ' has ' . $b['count'] . ' new line' . ($b['count'] === 1 ? '' : 's')
            . ($b['tensed'] ? ' and ' . count($b['tensed']) . ' in the past tense' : '') . '; copy it onto Reddit from Ready for Reddit.');
    }
    throw new RuntimeException('Unknown action.');
}
