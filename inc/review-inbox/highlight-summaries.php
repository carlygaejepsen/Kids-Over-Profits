<?php
/**
 * Review inbox source: plain-language summaries of hard-to-read serious
 * findings (inc/highlight-summaries.php). The AI drafts each one; nothing is
 * on the site until a person approves it. The card shows the draft (editable)
 * above the state's own wording it was written from, so the reviewer can
 * check every claim against the text. A draft whose finding changed since
 * (a rescan or a same-day merge) is marked out of date and can only be
 * written again.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('highlight-summaries', function () {
    if (!function_exists('kop_hs_candidates')) return null;
    return array(
        'label'    => 'Plain summaries',
        'group'    => 'Found in state inspection reports',
        'help'     => 'Short plain-language summaries of serious violations whose state wording is hard to follow (codes, numbered people, fragments, very long sentences). The AI drafts each one from the state\'s text only. Read it against the state\'s wording under it: approve only if every claim is in that text. An approved summary shows above the state\'s wording on the facility page, the home page cards and the severe reports page; the state\'s wording always stays.',
        'views'    => array('pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected or not written'),
        'view_counts' => function (array $q = array()) {
            $pdo = kop_rinbox_pdo();
            kop_hs_ensure_table($pdo);
            $out = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
            foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM inspection_highlight_summaries GROUP BY status') as $r) {
                if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['n'];
            }
            return $out;
        },
        'count'    => function () {
            $pdo = kop_rinbox_pdo();
            kop_hs_ensure_table($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM inspection_highlight_summaries WHERE status = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_hs_list',
        'get'      => function ($key) {
            $r = kop_rinbox_hs_rows('s.highlight_id = ?', array((int) $key), 1, 0);
            return $r ? kop_rinbox_hs_item($r[0]) : null;
        },
        'act'      => 'kop_rinbox_hs_act',
        'save'     => 'kop_rinbox_hs_save',
        'tools'    => array(array(
            'id'    => 'write',
            'label' => 'Write more drafts now',
            'help'  => 'Asks the AI to draft summaries for up to 5 more hard-to-read approved findings. This also happens by itself every hour.',
            'style' => 'neutral',
            'params' => array(),
        )),
        'tool'     => 'kop_rinbox_hs_tool',
    );
});

function kop_rinbox_hs_rows($where, array $params, $limit, $offset) {
    $pdo = kop_rinbox_pdo();
    kop_hs_ensure_table($pdo);
    $st = $pdo->prepare("SELECT s.highlight_id, s.excerpt_hash, s.summary, s.status, s.reasons, s.provider, s.reviewed_by, s.reviewed_at, s.created_at,
            h.excerpt, h.state, h.score, h.finding_date, h.state_label, h.facility_id, h.report_id, f.facility_name,
            r.report_date, r.report_url, r.report_id AS source_report_id
        FROM inspection_highlight_summaries s
        JOIN inspection_highlights h ON h.id = s.highlight_id
        JOIN inspection_facilities f ON f.id = h.facility_id
        LEFT JOIN inspection_reports r ON r.id = h.report_id
        WHERE $where ORDER BY (h.score >= " . (int) kop_ih_severe_score() . ') DESC, s.created_at DESC, s.highlight_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_rinbox_hs_list(array $q) {
    $status = in_array($q['view'], array('pending', 'approved', 'rejected'), true) ? $q['view'] : 'pending';
    $where = 's.status = ?';
    $params = array($status);
    if ($q['search'] !== '') {
        $where .= ' AND (f.facility_name LIKE ? OR s.summary LIKE ?)';
        array_push($params, '%' . $q['search'] . '%', '%' . $q['search'] . '%');
    }
    $pdo = kop_rinbox_pdo();
    kop_hs_ensure_table($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM inspection_highlight_summaries s JOIN inspection_highlights h ON h.id = s.highlight_id
        JOIN inspection_facilities f ON f.id = h.facility_id WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    return array('items' => array_map('kop_rinbox_hs_item', kop_rinbox_hs_rows($where, $params, $q['limit'], $q['offset'])), 'total' => $total);
}

function kop_rinbox_hs_item(array $r) {
    $stale = $r['excerpt_hash'] !== kop_hs_hash($r['excerpt']);
    $none = strncmp((string) $r['summary'], 'No summary:', 11) === 0;
    $when = $r['finding_date'] ?: (string) ($r['report_date'] ?? '');
    $sub = array($r['state']);
    if ($when !== '') $sub[] = 'Inspected ' . $when;
    if ($r['reasons']) $sub[] = 'Hard to read: ' . $r['reasons'];
    if ($stale) $sub[] = 'Out of date: the state\'s text changed after this was written';
    $status_label = $stale ? 'Out of date' : ($none ? 'Not written' : ucfirst($r['status']));

    $actions = array();
    if (!$stale && !$none && $r['status'] !== 'approved') $actions[] = array('id' => 'approved', 'label' => 'Approve: show this summary', 'style' => 'approve',
        'help' => 'Shows the summary above the state\'s wording on the facility page of ' . $r['facility_name'] . ', and wherever this finding is listed.');
    if ($r['status'] !== 'rejected') $actions[] = array('id' => 'rejected', 'label' => 'Reject: never show it', 'style' => 'reject',
        'help' => 'The state\'s wording is shown alone, as now' . ($r['status'] === 'approved' ? ' (the summary comes off the pages it is on)' : '') . '. It is not written again unless you ask.');
    if ($r['status'] !== 'approved') $actions[] = array('id' => 'rewrite', 'label' => 'Write it again', 'style' => 'neutral',
        'help' => 'Asks the AI for a new draft from the state\'s text. Anything you typed in the box is replaced.');
    if ($r['status'] !== 'pending' && !$stale && !$none) $actions[] = array('id' => 'pending', 'label' => 'Back to pending', 'style' => 'undo',
        'help' => 'Takes the summary off the site and puts it back in the waiting list.');

    // The draft goes in the card's text, not only in the (closed) editor: the
    // reviewer and volunteer copies (which carry no fields) must read it.
    $draft = $none ? (string) $r['summary'] : "Plain-language summary" . ($r['status'] === 'approved' ? ' (on the site)' : ' (draft)') . ":\n" . (string) $r['summary'];
    $text = ($draft !== '' ? $draft . "\n\n" : '') . "The state's wording, which readers see under the summary:\n" . kop_rinbox_excerpt(kop_ih_reader_labels($r['excerpt']), 1600)
        . ($r['status'] !== 'pending' && $r['reviewed_by'] ? "\n" . ucfirst($r['status']) . ' by ' . $r['reviewed_by'] . ', ' . $r['reviewed_at'] . ' UTC' : '');
    return array(
        'key'          => (string) $r['highlight_id'],
        'title'        => (string) $r['facility_name'],
        'subtitle'     => implode(' · ', $sub),
        'url'          => kop_ih_source_url($r),
        'text'         => $text,
        'created'      => (string) $r['created_at'],
        'status'       => $r['status'],
        'status_label' => $status_label,
        'fields'       => array(
            array('name' => 'summary', 'label' => 'Plain-language summary (edit it if it is not right)', 'type' => 'textarea', 'value' => $none ? '' : (string) $r['summary']),
        ),
        'actions'      => $actions,
        'details'      => array(
            array('label' => 'Finding number', 'value' => '#' . (int) $r['highlight_id']),
            array('label' => 'Drafted by', 'value' => $r['provider'] !== '' && $r['provider'] !== null ? ucfirst((string) $r['provider']) : 'a reviewer'),
        ),
        'preview'      => (int) $r['report_id'] > 0 ? array('label' => 'Full report',
            'url' => get_stylesheet_directory_uri() . '/api/review-inspection-highlights.php?' . http_build_query(array('report' => (int) $r['report_id'], 'format' => 'text'))) : null,
    );
}

function kop_rinbox_hs_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT s.*, h.excerpt, h.status AS finding_status FROM inspection_highlight_summaries s
        JOIN inspection_highlights h ON h.id = s.highlight_id WHERE s.highlight_id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_rinbox_hs_act($key, $action, array $params) {
    $pdo = kop_rinbox_pdo();
    kop_hs_ensure_table($pdo);
    $id = (int) $key;
    $r = kop_rinbox_hs_row($pdo, $id);
    if (!$r) throw new RuntimeException('That finding is gone (a later scan may have dropped or merged it).');
    $now = gmdate('Y-m-d H:i:s');
    if ($action === 'rewrite') {
        $row = array('id' => $id, 'excerpt' => $r['excerpt']);
        try {
            $summary = kop_hs_write($pdo, $row);
        } catch (RuntimeException $e) {
            throw new RuntimeException('No new draft: ' . $e->getMessage());
        }
        kop_rinbox_flush_counts();
        return array('message' => 'A new draft is ready below, waiting for your review.');
    }
    if (!in_array($action, array('pending', 'approved', 'rejected'), true)) throw new RuntimeException('Unknown action.');
    if ($action === 'approved') {
        if ($r['excerpt_hash'] !== kop_hs_hash($r['excerpt'])) throw new RuntimeException('The state\'s text changed after this was written. Use "Write it again".');
        // What goes on the site passes the same checks as an AI draft (no codes, brackets or links), whoever last edited it.
        kop_hs_clean_summary($r['summary']);
        if ($r['finding_status'] !== 'approved') throw new RuntimeException('The finding itself is not approved, so it is not on the site.');
    }
    $pending = $action === 'pending';
    $pdo->prepare('UPDATE inspection_highlight_summaries SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE highlight_id = ?')
        ->execute(array($action, $pending ? null : kop_rinbox_reviewer(), $pending ? null : $now, $id));
    kop_rinbox_flush_counts();
    if ($action === 'approved') return array('message' => 'Approved. It shows above the state\'s wording. Back to pending is on the Approved tab.');
    return array('message' => $action === 'rejected' ? 'Rejected. Only the state\'s wording shows.' : 'Back to pending: it is off the site and waits for review.');
}

function kop_rinbox_hs_save($key, array $fields) {
    $pdo = kop_rinbox_pdo();
    kop_hs_ensure_table($pdo);
    $r = kop_rinbox_hs_row($pdo, (int) $key);
    if (!$r) throw new RuntimeException('That finding is gone.');
    if (!array_key_exists('summary', $fields)) return array('message' => 'Nothing to save.');
    $text = kop_hs_clean_summary($fields['summary']);
    if ($text === (string) $r['summary']) return array('message' => 'Nothing changed.');
    $pdo->prepare('UPDATE inspection_highlight_summaries SET summary = ? WHERE highlight_id = ?')->execute(array($text, (int) $key));
    return array('message' => 'Saved.' . ($r['status'] === 'approved' ? ' The change is on the site now.' : ''));
}

function kop_rinbox_hs_tool($id, array $params) {
    if ($id !== 'write') throw new RuntimeException('Unknown tool.');
    $pdo = kop_rinbox_pdo();
    $c = kop_hs_run_batch($pdo, 5);
    kop_rinbox_flush_counts();
    $bits = array($c['written'] . ' draft' . ($c['written'] === 1 ? '' : 's') . ' written');
    if ($c['unclear']) $bits[] = $c['unclear'] . ' could not be summarised (see Rejected or not written)';
    if ($c['errors']) $bits[] = $c['errors'] . ' stopped on an AI error: ' . implode(' | ', array_slice(array_unique(array_column(array_filter($c['items'], function ($i) { return isset($i['error']); }), 'error')), 0, 2));
    if ($c['left']) $bits[] = $c['left'] . ' more are still waiting to be written';
    return array('message' => implode('; ', $bits) . '.');
}
