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
    $waiting = kop_rinbox_closure_waiting();
    return array(
        'label'    => 'Closure reports',
        'group'    => 'Found by the news scans',
        'help'     => 'Articles that say a facility closed, found by the hourly scan of saved news. Nothing changes until you confirm: confirming sets the facility\'s status, fills its end year from the closure date if it has none, cites the article in its notes and links the article to it. The facility page and the map follow at once; Undo puts everything back. Check the quote against the article first.'
            . ($waiting !== null ? ' ' . $waiting . ' saved articles are not scanned yet.' : ''),
        'views'    => kop_rinbox_closure_views(),
        'view_counts' => 'kop_rinbox_closure_view_counts',
        'tool_url' => admin_url('admin.php?page=kop-closure-reports'),
        'tools'    => array(array(
            'id' => 'scan', 'label' => 'Scan the next 10 articles now', 'style' => 'neutral',
            'help' => 'Reads saved articles the hourly scan has not reached yet. Give article numbers to scan only those (again).',
            'params' => array(array('name' => 'news', 'label' => 'or only these article numbers', 'type' => 'text', 'value' => '', 'optional' => true)),
        )),
        'tool'     => 'kop_rinbox_closure_tool',
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

function kop_rinbox_closure_views() {
    return array('pending' => 'To review', 'applied' => 'Confirmed', 'dismissed' => 'Dismissed', 'other' => 'Already recorded / duplicate', 'all' => 'All');
}

/** The SQL condition for one view. */
function kop_rinbox_closure_view_where($view) {
    return array(
        'pending'   => "r.status IN ('pending','unmatched')",
        'applied'   => "r.status = 'applied'",
        'dismissed' => "r.status = 'dismissed'",
        'other'     => "r.status IN ('already','duplicate')",
        'all'       => '1=1',
    )[$view] ?? "r.status IN ('pending','unmatched')";
}

/** Saved articles the scan has not read yet, as the old screen counts them (cached a minute), or null. */
function kop_rinbox_closure_waiting() {
    $n = get_transient('kop_rinbox_closure_waiting');
    if ($n !== false) return (int) $n;
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) return null;
        kop_closure_ensure_tables($pdo);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_closure_scans s ON s.news_id = n.id
                                 WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    set_transient('kop_rinbox_closure_waiting', $n, MINUTE_IN_SECONDS);
    return $n;
}

function kop_rinbox_closure_view_counts(array $q = array()) {
    $pdo = kop_closure_pdo();
    $by = array();
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM facility_closure_reports GROUP BY status') as $row) $by[$row['status']] = (int) $row['n'];
    return array(
        'pending'   => ($by['pending'] ?? 0) + ($by['unmatched'] ?? 0),
        'applied'   => $by['applied'] ?? 0,
        'dismissed' => $by['dismissed'] ?? 0,
        'other'     => ($by['already'] ?? 0) + ($by['duplicate'] ?? 0),
        'all'       => array_sum($by),
    );
}

/** "Scan the next 10 articles now", or only the article numbers given (the old screen's scan form). */
function kop_rinbox_closure_tool($id, array $params) {
    if ($id !== 'scan') throw new RuntimeException('Unknown tool.');
    $pdo = kop_closure_pdo();
    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,#]+/', (string) ($params['news'] ?? '')))));
    $result = kop_closure_scan_batch($pdo, $ids ? count($ids) : 10, 90, true, $ids);
    delete_transient('kop_rinbox_closure_waiting');
    $c = $result['counts'];
    return array('message' => sprintf('Scanned %d articles: %d with a closure, %d without, %d not about closures, %d failed.',
        $c['scanned'], $c['found'], $c['none'], $c['skipped'], $c['error']));
}

/** "Closed, ended 2019" for a facility as it is now, or '' when it is gone. */
function kop_rinbox_closure_facility_now(PDO $pdo, $fid) {
    if ((int) $fid <= 0) return '';
    $st = $pdo->prepare('SELECT status, end_year FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) $fid));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return '';
    return ($f['status'] ?: 'Unknown') . ($f['end_year'] ? ', ended ' . (int) $f['end_year'] : '');
}

function kop_rinbox_closure_list(array $q) {
    $pdo = kop_closure_pdo();
    kop_closure_ensure_tables($pdo);
    $where = kop_rinbox_closure_view_where($q['view']);
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
        // The old screen's confirm form: which facility closed (the scan's match filled in), and the end year.
        $params = array(array('name' => 'facility_id', 'label' => 'Which facility closed?', 'type' => 'facility', 'value' => (int) $r['facility_id']));
        if ($r['target_status'] === 'Closed') {
            $params[] = array('name' => 'end_year', 'label' => 'End year', 'type' => 'number', 'optional' => true,
                'value' => $r['closure_date'] ? substr($r['closure_date'], 0, 4) : '');
        }
        $actions[] = array('id' => 'apply', 'label' => 'Confirm: mark ' . $r['target_status'], 'style' => 'approve', 'params' => $params);
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss: not a closure', 'style' => 'reject');
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo: restore the old status', 'style' => 'undo');
    } else {
        $actions[] = array('id' => 'reopen', 'label' => 'Back to review', 'style' => 'neutral');
    }
    $quote = (string) $r['quote'];
    $pdo = kop_closure_pdo();
    $details = array();
    if ((int) $r['facility_id'] > 0) {
        $now = kop_rinbox_closure_facility_now($pdo, (int) $r['facility_id']);
        $details[] = array('label' => 'Facility now', 'value' => $now !== '' ? $now : 'gone (no such record)');
    } else {
        $details[] = array('label' => 'Facility', 'value' => 'No match: pick it in "Which facility closed?"');
    }
    $details[] = array('label' => 'Article names', 'value' => $r['program_name'] . ($r['location'] ? ' (' . $r['location'] . ')' : ''));
    $details[] = array('label' => 'Sets', 'value' => (string) $r['target_status']);
    if ($quote !== '' && !$r['quote_found']) {
        $details[] = array('label' => 'Check the quote', 'value' => 'Quote not found in the article text. Read the article before confirming.');
    }
    $details[] = array('label' => 'Article', 'value' => trim('#' . (int) $r['news_id'] . ' · ' . trim(($r['publication_name'] ?? '') . ' ' . ($r['publication_date'] ?? '')) . ' · ' . ($r['news_status'] ?: 'missing'), ' ·'));
    if (!empty($r['news_status']) && !in_array($r['news_status'], array('approved', 'published'), true)) {
        $details[] = array('label' => 'Not on the facility page yet', 'value' => 'This article is not approved yet, so it will not show on the facility page until it is.');
    }
    if (!empty($r['reviewed_by'])) {
        $details[] = array('label' => 'Reviewed by', 'value' => $r['reviewed_by'] . (!empty($r['reviewed_at']) ? ', ' . $r['reviewed_at'] . ' UTC' : ''));
    }
    return array(
        'key'          => (string) $r['id'],
        'title'        => $r['program_name'],
        'subtitle'     => trim(($stages[$r['stage']] ?? $r['stage']) . ($r['closure_date'] ? ', ' . $r['closure_date'] : '') . ($r['location'] ? ' · ' . $r['location'] : '')),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => ($quote !== '' ? '"' . $quote . '"' . ($r['quote_found'] ? '' : ' (quote not found in the article text)') : '')
            . ($r['article_title'] ? "\nFrom: " . $r['article_title'] . ($r['publication_name'] ? ' (' . $r['publication_name'] . ')' : '') : ''),
        'details'      => $details,
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
            // As the old screen: a cleared end year box is 0 (set no end year);
            // no box at all (a Suspended report) leaves it to the closure date.
            $end = array_key_exists('end_year', $params) ? (int) $params['end_year'] : null;
            $fid = kop_closure_apply($pdo, $id, $user, (int) ($params['facility_id'] ?? 0), $end);
            return array('message' => 'Confirmed. ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ' is now marked '
                . kop_rinbox_closure_facility_now($pdo, $fid) . '. Its facility page and the network map already show this. Undo is on the Confirmed tab.');
        case 'undo':
            $fid = kop_closure_undo($pdo, $id, $user);
            return array('message' => 'Undone. ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . ' is back to '
                . kop_rinbox_closure_facility_now($pdo, $fid) . ', and the report is waiting again.');
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
