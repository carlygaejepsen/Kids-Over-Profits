<?php
/**
 * Review inbox source: serious findings read out of the state inspection
 * reports (inc/inspection-highlights.php). Nothing is on the site until a
 * person approves it; the decision is written exactly as the old screen
 * (api/review-inspection-highlights.php) writes it, and an approval folds
 * the facility's same-day findings into one through kop_ih_merge_same_day().
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('inspection-highlights', function () {
    if (!function_exists('kop_ih_merge_same_day')) return null;
    return array(
        'label'    => 'Serious violations',
        'group'    => 'Found in state inspection reports',
        'help'     => 'Serious violations read out of state inspection reports. Open the state source or the full report before approving: the reader cannot tell who did what to whom. Approved serious violations scoring ' . (int) kop_ih_severe_score() . ' or more show on the home page and the inspection reports hub.',
        'views'    => array('pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected'),
        'view_counts' => function (array $q = array()) {
            $out = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
            foreach (kop_rinbox_pdo()->query('SELECT status, COUNT(*) AS n FROM inspection_highlights GROUP BY status') as $r) {
                if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['n'];
            }
            return $out;
        },
        // The old screen's filters; the search also takes a facility name, a state, or finding numbers ("#12, 40").
        'filters'  => array(
            array('name' => 'state', 'label' => 'State', 'options' => array('' => 'All states') + array_combine(kop_ih_supported_states(), kop_ih_supported_states())),
            array('name' => 'category', 'label' => 'Category', 'options' => array('' => 'All categories') + kop_rinbox_ih_category_options()),
            array('name' => 'min', 'label' => 'Score at least', 'options' => array('' => 'Any score', '50' => '50', '70' => '70', '90' => '90')),
            array('name' => 'sort', 'label' => 'Order', 'options' => array('' => 'Most recent severe first', 'worst' => 'Worst first')),
        ),
        'tool_url' => get_stylesheet_directory_uri() . '/api/review-inspection-highlights.php',
        'count'    => function () {
            return (int) kop_rinbox_pdo()->query("SELECT COUNT(*) FROM inspection_highlights WHERE status = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_ih_list',
        'get'      => function ($key) {
            $r = kop_rinbox_ih_rows('h.id = ?', array((int) $key), 'h.id', 1, 0);
            return $r ? kop_rinbox_ih_item($r[0]) : null;
        },
        'act'      => 'kop_rinbox_ih_act',
        'save'     => 'kop_rinbox_ih_save',
    );
});

function kop_rinbox_ih_rows($where, array $params, $order, $limit, $offset) {
    $st = kop_rinbox_pdo()->prepare("SELECT h.*, f.facility_name, r.report_date, r.report_url, r.report_id AS source_report_id
        FROM inspection_highlights h
        JOIN inspection_facilities f ON f.id = h.facility_id
        LEFT JOIN inspection_reports r ON r.id = h.report_id
        WHERE $where ORDER BY $order LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kop_rinbox_ih_list(array $q) {
    $status = in_array($q['view'], array('pending', 'approved', 'rejected'), true) ? $q['view'] : 'pending';
    $where = 'h.status = ?';
    $params = array($status);
    $f = (array) ($q['filters'] ?? array());
    if (!empty($f['state'])) {
        $where .= ' AND h.state = ?';
        $params[] = (string) $f['state'];
    }
    if (!empty($f['category'])) {
        // FIND_IN_SET, written so SQLite reads it too.
        $where .= ' AND (h.categories = ? OR h.categories LIKE ? OR h.categories LIKE ? OR h.categories LIKE ?)';
        array_push($params, $f['category'], $f['category'] . ',%', '%,' . $f['category'], '%,' . $f['category'] . ',%');
    }
    if (!empty($f['min'])) {
        $where .= ' AND h.score >= ?';
        $params[] = (int) $f['min'];
    }
    if ($q['search'] !== '' && preg_match('/^[#\d,\s]+$/', $q['search']) && preg_match('/\d/', $q['search'])) {
        // A hand-picked list of finding numbers, as the old screen's ?ids=12,40.
        $ids = array_values(array_filter(array_map('intval', preg_split('/[#,\s]+/', $q['search']))));
        $where .= ' AND h.id IN (' . implode(',', $ids) . ')';
    } elseif ($q['search'] !== '') {
        // A facility name, or a two-letter state.
        if (preg_match('/^[A-Za-z]{2}$/', $q['search'])) {
            $where .= ' AND (f.facility_name LIKE ? OR h.state = ?)';
            array_push($params, '%' . $q['search'] . '%', strtoupper($q['search']));
        } else {
            $where .= ' AND f.facility_name LIKE ?';
            $params[] = '%' . $q['search'] . '%';
        }
    }
    $st = kop_rinbox_pdo()->prepare("SELECT COUNT(*) FROM inspection_highlights h JOIN inspection_facilities f ON f.id = h.facility_id WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    // Recent severe findings lead, as on the old screen: they are what the site shows.
    $order = ($f['sort'] ?? '') === 'worst'
        ? 'h.score DESC, h.finding_date DESC, h.id DESC'
        : '(h.score >= ' . (int) kop_ih_severe_score() . ') DESC, (h.finding_date IS NULL) ASC, h.finding_date DESC, h.score DESC, h.id DESC';
    return array('items' => array_map('kop_rinbox_ih_item', kop_rinbox_ih_rows($where, $params, $order, $q['limit'], $q['offset'])), 'total' => $total);
}

function kop_rinbox_ih_category_options() {
    $out = array();
    foreach (kop_ih_categories() as $k => $c) $out[$k] = $c['label'];
    return $out;
}

function kop_rinbox_ih_item(array $r) {
    $cats = kop_rinbox_ih_category_options();
    $when = $r['finding_date'] ?: (string) ($r['report_date'] ?? '');
    $severe = (int) $r['score'] >= kop_ih_severe_score();
    $sub = array('Score ' . (int) $r['score'], $r['state']);
    if ($when !== '') $sub[] = $when;
    if ($r['state_label']) $sub[] = $r['state_label'];
    if ($severe) $sub[] = $r['status'] === 'approved' ? 'Severe: shown on the site' : 'Severe: shown on the site once approved';
    $also = array();
    foreach (array_filter(array_map('trim', explode(',', (string) $r['categories']))) as $k) {
        if ($k !== $r['category'] && isset($cats[$k])) $also[] = $cats[$k];
    }
    $text = kop_rinbox_excerpt($r['excerpt'], 900)
        . ($r['standard'] ? "\nCited: " . $r['standard'] : '')
        . ($also ? "\nAlso matched: " . implode(', ', $also) : '')
        . ($r['corrected_on_site'] ? "\nCorrected at the inspection." : '')
        . ($r['status'] !== 'pending' ? "\n" . ucfirst($r['status']) . ' by ' . $r['reviewed_by'] . ', ' . $r['reviewed_at'] . ' UTC' . ($r['review_note'] ? ': ' . $r['review_note'] : '') : '');
    // The old screen's note box goes with the decision (optional; a bulk decision keeps each card's note).
    $note = array(array('name' => 'note', 'label' => 'Note', 'type' => 'text', 'value' => (string) $r['review_note'], 'optional' => true));
    $actions = array();
    if ($r['status'] !== 'approved') $actions[] = array('id' => 'approved', 'label' => 'Approve', 'style' => 'approve', 'params' => $note);
    if ($r['status'] !== 'rejected') $actions[] = array('id' => 'rejected', 'label' => 'Reject', 'style' => 'reject', 'params' => $note);
    if ($r['status'] !== 'pending') $actions[] = array('id' => 'pending', 'label' => 'Back to pending', 'style' => 'undo');
    $links = array(array(
        'label' => 'Full report (old screen)',
        'url'   => get_stylesheet_directory_uri() . '/api/review-inspection-highlights.php?' . http_build_query(array('status' => $r['status'], 'ids' => (int) $r['id'])),
    ));
    return array(
        'key'          => (string) $r['id'],
        'title'        => (string) $r['facility_name'],
        'subtitle'     => implode(' · ', $sub),
        'url'          => kop_ih_source_url($r),
        'text'         => $text,
        'created'      => (string) ($r['finding_date'] ?: $r['created_at']),
        'status'       => $r['status'],
        'status_label' => ucfirst($r['status']),
        'fields'       => array(
            array('name' => 'category', 'label' => 'Worst harm', 'type' => 'select', 'options' => $cats, 'value' => (string) $r['category'], 'category' => true),
            array('name' => 'review_note', 'label' => 'Note', 'type' => 'text', 'value' => (string) $r['review_note']),
        ),
        'actions'      => $actions,
        'links'        => $links,
        'details'      => array(
            array('label' => 'Violation number', 'value' => '#' . (int) $r['id']),
            array('label' => 'Categories', 'value' => implode(', ', array_map(function ($k) use ($cats) { return $cats[$k] ?? $k; },
                array_values(array_filter(array_map('trim', explode(',', (string) $r['categories']))))))),
        ),
        // The old screen's "Full report": the scraped report text, read when opened.
        'preview'      => (int) $r['report_id'] > 0 ? array('label' => 'Full report',
            'url' => get_stylesheet_directory_uri() . '/api/review-inspection-highlights.php?' . http_build_query(array('report' => (int) $r['report_id'], 'format' => 'text'))) : null,
    );
}

function kop_rinbox_ih_get_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM inspection_highlights WHERE id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A decision, written as api/review-inspection-highlights.php writes it (the note stays as saved). */
function kop_rinbox_ih_act($key, $action, array $params) {
    $pdo = kop_rinbox_pdo();
    $id = (int) $key;
    if (!in_array($action, array('pending', 'approved', 'rejected'), true)) throw new RuntimeException('Unknown action.');
    $r = kop_rinbox_ih_get_row($pdo, $id);
    if (!$r) throw new RuntimeException('That finding is gone (a later scan may have dropped or merged it).');
    $note = isset($params['note']) ? mb_substr(trim((string) $params['note']), 0, 500) : trim((string) $r['review_note']);
    $pending = $action === 'pending';
    $pdo->prepare('UPDATE inspection_highlights SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
        ->execute(array($action, $note !== '' ? $note : null, $pending ? null : kop_rinbox_reviewer(), $pending ? null : gmdate('Y-m-d H:i:s'), $id));
    $merged = 0;
    $into = 0;
    if ($action === 'approved') {
        // The site shows one entry per facility per day: an approval folds
        // this finding and any others of its day into one.
        foreach (kop_ih_merge_same_day($pdo, true, (int) $r['facility_id']) as $m) {
            $merged += count($m['merged']) + count($m['identical']);
            if (in_array($id, array_merge($m['merged'], $m['identical']), true)) $into = (int) $m['keep'];
        }
    }
    kop_rinbox_flush_counts();
    if ($action === 'approved') {
        $shown = (int) $r['score'] >= kop_ih_severe_score() ? ' It shows on the home page and the inspection reports hub.' : ' It scores below ' . (int) kop_ih_severe_score() . ', so the site does not highlight it.';
        if ($into) {
            // This finding was folded into an approved one of the same day: show that one.
            return array('key' => (string) $into, 'message' => 'Approved and folded into finding #' . $into . ', which is this facility\'s entry for that day.' . $shown);
        }
        return array('message' => 'Approved.' . $shown . ($merged ? ' ' . $merged . ' other finding' . ($merged === 1 ? ' of the same day was' : 's of the same day were') . ' folded into this one.' : '') . ' Back to pending is on the Approved tab.');
    }
    return array('message' => $action === 'rejected' ? 'Rejected. It will not appear on the site.' : 'Back to pending: it waits for review and is off the site.');
}

function kop_rinbox_ih_save($key, array $fields) {
    $pdo = kop_rinbox_pdo();
    $r = kop_rinbox_ih_get_row($pdo, (int) $key);
    if (!$r) throw new RuntimeException('That finding is gone.');
    $set = array();
    $vals = array();
    if (array_key_exists('category', $fields) && (string) $fields['category'] !== $r['category']) {
        $cat = (string) $fields['category'];
        if (!isset(kop_ih_categories()[$cat])) throw new RuntimeException('Worst harm: pick one from the list.');
        // The worst category leads the list the site counts by.
        $list = array_values(array_diff(array_filter(array_map('trim', explode(',', (string) $r['categories']))), array($cat)));
        array_unshift($list, $cat);
        $set[] = 'category = ?';
        $vals[] = $cat;
        $set[] = 'categories = ?';
        $vals[] = implode(',', $list);
    }
    if (array_key_exists('review_note', $fields)) {
        $note = mb_substr(trim((string) $fields['review_note']), 0, 500);
        $set[] = 'review_note = ?';
        $vals[] = $note !== '' ? $note : null;
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $vals[] = (int) $key;
    $pdo->prepare('UPDATE inspection_highlights SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    return array('message' => 'Saved.' . ($r['status'] === 'pending' && isset($cat) ? ' A later scan of this report may set the category again while it is pending.' : ''));
}
