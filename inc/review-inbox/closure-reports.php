<?php
/**
 * Review inbox source: facility closures the hourly news scan found
 * (inc/closure-reports.php). Confirm sets the facility's status through
 * kop_closure_apply(), Undo through kop_closure_undo().
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('closure', function () {
    if (!function_exists('kop_closure_apply')) return null;
    return array(
        'label'    => 'Closure reports',
        'group'    => 'Found by the news scans',
        'help'     => 'Articles that say a facility closed. Confirm sets the facility\'s status on its page and the map; Undo puts it back.',
        'views'    => array('pending' => 'To review', 'applied' => 'Confirmed', 'dismissed' => 'Dismissed', 'other' => 'Already recorded / duplicate'),
        'tool_url' => admin_url('admin.php?page=kop-closure-reports'),
        'count'    => function () {
            $pdo = kop_closure_pdo();
            kop_closure_ensure_tables($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM facility_closure_reports WHERE status IN ('pending','unmatched')")->fetchColumn();
        },
        'list'     => 'kop_rinbox_closure_list',
        'get'      => function ($key) {
            $r = kop_closure_get_report(kop_closure_pdo(), (int) $key);
            return $r ? kop_rinbox_closure_item($r) : null;
        },
        'act'      => 'kop_rinbox_closure_act',
        'save'     => 'kop_rinbox_closure_save',
    );
});

function kop_rinbox_closure_list(array $q) {
    $pdo = kop_closure_pdo();
    kop_closure_ensure_tables($pdo);
    $where = array(
        'pending'   => "r.status IN ('pending','unmatched')",
        'applied'   => "r.status = 'applied'",
        'dismissed' => "r.status = 'dismissed'",
        'other'     => "r.status IN ('already','duplicate')",
    )[$q['view']] ?? "r.status IN ('pending','unmatched')";
    $params = array();
    if ($q['search'] !== '') {
        $where .= ' AND (r.program_name LIKE ? OR r.location LIKE ? OR n.article_title LIKE ?)';
        $like = '%' . $q['search'] . '%';
        $params = array($like, $like, $like);
    }
    $from = "FROM facility_closure_reports r LEFT JOIN news_submissions n ON n.id = r.news_id WHERE $where";
    $st = $pdo->prepare("SELECT COUNT(*) $from");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT r.*, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status AS news_status
                          $from ORDER BY r.created_at DESC, r.id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($params);
    return array('items' => array_map('kop_rinbox_closure_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

function kop_rinbox_closure_item(array $r) {
    $stages = array();
    foreach (kop_closure_report_stages() as $k => $s) $stages[$k] = $s['label'];
    $statuses = kop_closure_report_statuses();
    $open = in_array($r['status'], array('pending', 'unmatched'), true);
    $actions = array();
    if ($open) {
        $actions[] = array('id' => 'apply', 'label' => 'Confirm closure', 'style' => 'approve',
            'params' => $r['target_status'] === 'Closed'
                ? array(array('name' => 'end_year', 'label' => 'End year', 'type' => 'number',
                    'value' => $r['closure_date'] ? substr($r['closure_date'], 0, 4) : ''))
                : array());
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss', 'style' => 'reject');
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo');
    } else {
        $actions[] = array('id' => 'reopen', 'label' => 'Back to review', 'style' => 'neutral');
    }
    $quote = (string) $r['quote'];
    return array(
        'key'          => (string) $r['id'],
        'title'        => $r['program_name'],
        'subtitle'     => trim(($stages[$r['stage']] ?? $r['stage']) . ($r['closure_date'] ? ', ' . $r['closure_date'] : '') . ($r['location'] ? ' · ' . $r['location'] : '')),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => ($quote !== '' ? '"' . $quote . '"' . ($r['quote_found'] ? '' : ' (quote not found in the article text)') : '')
            . ($r['article_title'] ? "\nFrom: " . $r['article_title'] . ($r['publication_name'] ? ' (' . $r['publication_name'] . ')' : '') : ''),
        'created'      => (string) $r['created_at'],
        'status'       => $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => kop_rinbox_facility($r['facility_id']),
        'fields'       => array(
            array('name' => 'program_name', 'label' => 'Program name', 'type' => 'text', 'value' => $r['program_name']),
            array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => (int) $r['facility_id']),
            array('name' => 'stage', 'label' => 'What happened', 'type' => 'select', 'options' => $stages, 'value' => $r['stage'], 'category' => true),
            array('name' => 'closure_date', 'label' => 'Closure date (YYYY-MM-DD)', 'type' => 'text', 'value' => (string) $r['closure_date']),
            array('name' => 'location', 'label' => 'Location', 'type' => 'text', 'value' => (string) $r['location']),
        ),
        'actions'      => $actions,
    );
}

function kop_rinbox_closure_act($key, $action, array $params) {
    $pdo = kop_closure_pdo();
    $id = (int) $key;
    $user = kop_rinbox_reviewer();
    switch ($action) {
        case 'apply':
            $end = isset($params['end_year']) && $params['end_year'] !== '' ? (int) $params['end_year'] : null;
            $fid = kop_closure_apply($pdo, $id, $user, 0, $end);
            return array('message' => 'Confirmed. ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ' is marked closed on its page and the map. Undo is on the Confirmed tab.');
        case 'undo':
            $fid = kop_closure_undo($pdo, $id, $user);
            return array('message' => 'Undone. ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ' has its old status again.');
        case 'dismiss':
            kop_closure_set_status($pdo, $id, 'dismissed', $user);
            return array('message' => 'Dismissed. No facility was changed.');
        case 'reopen':
            kop_closure_set_status($pdo, $id, 'pending', $user);
            return array('message' => 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_closure_save($key, array $fields) {
    $pdo = kop_closure_pdo();
    $r = kop_closure_get_report($pdo, (int) $key);
    if (!$r) throw new RuntimeException('That report is gone.');
    if ($r['status'] === 'applied') throw new RuntimeException('Undo the confirmation before editing the report.');
    $stages = kop_closure_report_stages();
    $set = array();
    $vals = array();
    foreach (array('program_name', 'location', 'closure_date') as $col) {
        if (array_key_exists($col, $fields)) {
            $set[] = "$col = ?";
            $vals[] = trim((string) $fields[$col]);
        }
    }
    if (isset($fields['closure_date']) && trim((string) $fields['closure_date']) !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', trim($fields['closure_date']))) {
        throw new RuntimeException('Closure date: write it as YYYY, YYYY-MM or YYYY-MM-DD.');
    }
    if (isset($fields['stage']) && isset($stages[$fields['stage']])) {
        $set[] = 'stage = ?';
        $vals[] = $fields['stage'];
        $set[] = 'target_status = ?';
        $vals[] = $stages[$fields['stage']]['status'];
    }
    if (array_key_exists('facility_id', $fields)) {
        $fid = (int) $fields['facility_id'];
        $set[] = 'facility_id = ?';
        $vals[] = $fid > 0 ? $fid : null;
        // A report with a facility is reviewable; one without stays unmatched.
        $set[] = 'status = ?';
        $vals[] = $fid > 0 ? ($r['status'] === 'unmatched' ? 'pending' : $r['status']) : 'unmatched';
    }
    if (!$set) return array('message' => 'Nothing to save.');
    if (array_key_exists('program_name', $fields) && trim((string) $fields['program_name']) === '') {
        throw new RuntimeException('The program name cannot be empty.');
    }
    $vals[] = (int) $key;
    $pdo->prepare('UPDATE facility_closure_reports SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}
