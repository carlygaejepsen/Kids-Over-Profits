<?php
/**
 * Review inbox source: bill and lawsuit status changes the nightly check found
 * (inc/status-checks.php). Apply writes them into the record through
 * kop_sc_apply(), Undo puts the record back through kop_sc_undo().
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('status_checks', function () {
    if (!function_exists('kop_sc_apply')) return null;
    return array(
        'label'    => 'Bill and lawsuit updates',
        'group'    => 'Found by the news scans',
        'help'     => 'Each night every published bill and lawsuit that is not finished is checked against its source: Congress\'s own data for federal bills, '
            . 'the legislature\'s bill page for state bills, the court docket (CourtListener) for lawsuits. A change waits here; nothing on the site changes '
            . 'until you apply it, and Undo puts the record back. Check the quote against the source first. ' . kop_rinbox_sc_last_run(),
        'views'    => array('pending' => 'To review', 'applied' => 'Applied', 'dismissed' => 'Dismissed', 'stale' => 'Replaced by a newer check', 'all' => 'All'),
        'view_counts' => 'kop_rinbox_sc_view_counts',
        'filters'  => array(array('name' => 'kind', 'label' => 'Kind', 'options' => array('bill' => 'Bills', 'lawsuit' => 'Lawsuits'))),
        'tools'    => array(array(
            'id' => 'check', 'label' => 'Check now', 'style' => 'neutral',
            'help' => 'Checks up to 10 bills and lawsuits the nightly run has not reached today. Give record numbers to check only those, again.',
            'params' => array(
                array('name' => 'kind', 'label' => 'Which', 'type' => 'select', 'options' => array('both' => 'Bills and lawsuits', 'bill' => 'Bills', 'lawsuit' => 'Lawsuits'), 'value' => 'both'),
                array('name' => 'ids', 'label' => 'or only these record numbers', 'type' => 'text', 'value' => '', 'optional' => true),
            ),
        )),
        'tool'     => 'kop_rinbox_sc_tool',
        'count'    => function () {
            $pdo = kop_sc_pdo();
            kop_sc_ensure_tables($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM record_status_proposals WHERE status = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_sc_list',
        'get'      => function ($key) {
            $p = kop_sc_get(kop_sc_pdo(), (int) $key);
            return $p ? kop_rinbox_sc_item($p) : null;
        },
        'act'      => 'kop_rinbox_sc_act',
        'save'     => 'kop_rinbox_sc_save',
    );
});

/** "Last night: 41 checked, 2 changes, 12 with no source to check." */
function kop_rinbox_sc_last_run() {
    $l = get_option('kop_status_checks_last_run');
    if (!is_array($l)) return 'The first check runs tonight.';
    $s = 'Last run (' . $l['night'] . '): ' . (int) $l['checked'] . ' checked, ' . (int) $l['changed'] . ' with a change, '
        . (int) $l['no_source'] . ' with no source to check, ' . (int) $l['error'] . ' could not be read.';
    return $s . (!empty($l['remaining']) ? ' ' . (int) $l['remaining'] . ' still to check.' : '');
}

function kop_rinbox_sc_view_counts(array $q = array()) {
    $pdo = kop_sc_pdo();
    kop_sc_ensure_tables($pdo);
    $by = array();
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM record_status_proposals GROUP BY status') as $r) $by[$r['status']] = (int) $r['n'];
    return array('pending' => $by['pending'] ?? 0, 'applied' => $by['applied'] ?? 0, 'dismissed' => $by['dismissed'] ?? 0,
                 'stale' => $by['stale'] ?? 0, 'all' => array_sum($by));
}

function kop_rinbox_sc_tool($id, array $params) {
    if ($id !== 'check') throw new RuntimeException('Unknown tool.');
    $pdo = kop_sc_pdo();
    $kind = in_array($params['kind'] ?? '', array('bill', 'lawsuit'), true) ? array($params['kind']) : array('bill', 'lawsuit');
    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,#]+/', (string) ($params['ids'] ?? '')))));
    if ($ids && count($kind) > 1) throw new RuntimeException('With record numbers, pick Bills or Lawsuits.');
    $r = kop_sc_run($pdo, $ids ? count($ids) : 10, 90, true, $kind, $ids);
    kop_rinbox_flush_counts();
    $c = $r['counts'];
    return array('message' => sprintf('Checked %d: %d with a change, %d unchanged, %d with no source to check, %d could not be read.',
        $c['checked'], $c['changed'], $c['same'], $c['no_source'], $c['error']));
}

function kop_rinbox_sc_list(array $q) {
    $pdo = kop_sc_pdo();
    kop_sc_ensure_tables($pdo);
    $views = array('pending' => "p.status = 'pending'", 'applied' => "p.status = 'applied'", 'dismissed' => "p.status = 'dismissed'",
                   'stale' => "p.status = 'stale'", 'all' => '1=1');
    $where = $views[$q['view']] ?? $views['pending'];
    $args = array();
    if (!empty($q['filters']['kind'])) {
        $where .= ' AND p.kind = ?';
        $args[] = $q['filters']['kind'];
    }
    if (($q['search'] ?? '') !== '') {
        $where .= ' AND (l.bill_number LIKE ? OR l.bill_title LIKE ? OR l.jurisdiction LIKE ? OR s.case_name LIKE ?)';
        $like = '%' . $q['search'] . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $from = "FROM record_status_proposals p
             LEFT JOIN legislation l ON p.kind = 'bill' AND l.id = p.record_id
             LEFT JOIN lawsuits s ON p.kind = 'lawsuit' AND s.id = p.record_id WHERE $where";
    $st = $pdo->prepare("SELECT COUNT(*) $from");
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT p.* $from ORDER BY p.created_at DESC, p.id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($args);
    return array('items' => array_map('kop_rinbox_sc_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

function kop_rinbox_sc_field_label($f) {
    return array('status' => 'Status', 'last_action_date' => 'Last action date', 'last_action_text' => 'Last action',
                 'outcome' => 'Outcome', 'settlement_amount' => 'Settlement amount')[$f] ?? $f;
}

function kop_rinbox_sc_item(array $p) {
    $pdo = kop_sc_pdo();
    $kinds = kop_sc_kinds();
    $row = kop_sc_record($pdo, $p['kind'], $p['record_id']);
    $changes = (array) json_decode((string) $p['changes'], true);
    $title = $row ? kop_sc_record_title($p['kind'], $row) : $kinds[$p['kind']]['label'] . ' #' . (int) $p['record_id'] . ' (gone)';
    $rows = array();
    $fields = array();
    foreach ($changes as $f => $c) {
        $now = $row ? (string) $row[$f] : '';
        $rows[] = array('label' => kop_rinbox_sc_field_label($f), 'values' => array($now !== '' ? $now : '(none)', (string) $c[1]));
        if ($p['status'] === 'pending') {
            if ($f === 'status') {
                $opts = $p['kind'] === 'bill' ? kop_sc_bill_statuses() : kop_sc_lawsuit_statuses();
                $fields[] = array('name' => $f, 'label' => 'New status', 'type' => 'select', 'options' => kop_rinbox_options($opts), 'value' => (string) $c[1]);
            } else {
                $fields[] = array('name' => $f, 'label' => 'New ' . strtolower(kop_rinbox_sc_field_label($f)),
                    'type' => in_array($f, array('outcome', 'last_action_text'), true) ? 'textarea' : 'text', 'value' => (string) $c[1]);
            }
        }
    }
    $actions = array();
    if ($p['status'] === 'pending') {
        $actions[] = array('id' => 'apply', 'label' => 'Apply the update', 'style' => 'approve',
            'help' => 'Writes the new values into the ' . strtolower($kinds[$p['kind']]['label']) . ' record, so the legislation page'
                . ($p['kind'] === 'lawsuit' ? ', the lawsuits list and facility pages show' : ' shows') . ' them at once, and notes the source in its admin notes.');
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss: not right', 'style' => 'reject',
            'help' => 'Nothing changes; this exact update is never proposed again.');
    } elseif ($p['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo: put the old values back', 'style' => 'undo',
            'help' => 'Restores the record as it was before, except fields someone has edited since, and the update waits for review again.');
    } else {
        $actions[] = array('id' => 'reopen', 'label' => 'Back to review', 'style' => 'neutral', 'help' => 'Nothing on the site changes; the update waits for review again.');
    }
    $details = array(array('label' => 'Source', 'value' => (string) $p['source_label']));
    if ($p['quote'] !== '' && !$p['quote_found']) {
        $details[] = array('label' => 'Check the quote', 'value' => 'The quote was not found word for word in the source. Read the source before applying.');
    }
    if ($row && $p['kind'] === 'bill') {
        $details[] = array('label' => 'Bill', 'value' => (string) $row['bill_title']);
    } elseif ($row) {
        $details[] = array('label' => 'Court', 'value' => trim($row['court'] . ($row['case_number'] ? ' · ' . $row['case_number'] : ''), ' ·'));
    }
    if (!empty($p['reviewed_by'])) {
        $details[] = array('label' => 'Reviewed by', 'value' => $p['reviewed_by'] . ', ' . $p['reviewed_at'] . ' UTC');
    }
    $statusLabels = array('pending' => 'To review', 'applied' => 'Applied', 'dismissed' => 'Dismissed', 'stale' => 'Replaced by a newer check');
    return array(
        'key'          => (string) $p['id'],
        'title'        => $title,
        'subtitle'     => $kinds[$p['kind']]['label'] . ' · ' . kop_sc_changes_line($changes),
        'url'          => (string) $p['source_url'],
        'text'         => $p['quote'] !== '' ? '"' . $p['quote'] . '"' : '',
        'details'      => $details,
        'compare'      => array('heads' => array('On the site now', 'The source says'), 'rows' => $rows),
        'created'      => (string) $p['created_at'],
        'status'       => $p['status'],
        'status_label' => $statusLabels[$p['status']] ?? $p['status'],
        'fields'       => $fields,
        'actions'      => $actions,
    );
}

function kop_rinbox_sc_act($key, $action, array $params) {
    $pdo = kop_sc_pdo();
    $user = kop_rinbox_reviewer();
    switch ($action) {
        case 'apply':
            kop_sc_apply($pdo, (int) $key, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Applied. The record shows the new values now. Undo is on the Applied tab.');
        case 'undo':
            $kept = kop_sc_undo($pdo, (int) $key, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Undone; the record is back as it was' . ($kept ? ', except ' . implode(', ', array_map('kop_rinbox_sc_field_label', $kept)) . ', which someone edited since' : '') . '.');
        case 'dismiss':
            kop_sc_set_status($pdo, (int) $key, 'dismissed', $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Dismissed. Nothing changed.');
        case 'reopen':
            kop_sc_set_status($pdo, (int) $key, 'pending', $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

/** Edit what the update will write (the proposal's new values), before applying it. */
function kop_rinbox_sc_save($key, array $fields) {
    $pdo = kop_sc_pdo();
    $p = kop_sc_get($pdo, (int) $key);
    if (!$p) throw new RuntimeException('That update is gone.');
    if ($p['status'] !== 'pending') throw new RuntimeException('Only a waiting update can be edited.');
    $changes = (array) json_decode((string) $p['changes'], true);
    foreach ($fields as $f => $v) {
        if (!isset($changes[$f])) continue;
        $v = trim((string) $v);
        if ($f === 'status' && !in_array($v, $p['kind'] === 'bill' ? kop_sc_bill_statuses() : kop_sc_lawsuit_statuses(), true)) {
            throw new RuntimeException('Pick a status from the list.');
        }
        if ($f === 'last_action_date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new RuntimeException('Last action date: write it as YYYY-MM-DD.');
        }
        $changes[$f][1] = $v;
    }
    $pdo->prepare('UPDATE record_status_proposals SET changes = ? WHERE id = ?')
        ->execute(array(json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $key));
    return array('message' => 'Saved. Apply writes these values.');
}
