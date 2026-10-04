<?php
/**
 * Review inbox source: problems readers reported with the site's bug widget
 * (the bug_reports table, written by api/save-bug-report.php). A status
 * change does what the Bug Reports screen (kop_render_bug_reports_page() in
 * inc/admin.php) does: it updates the row through $wpdb and then calls
 * kop_bug_report_notify_status_change(), which emails a reporter who asked
 * for updates.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('bug-reports', function () {
    if (!function_exists('kop_bug_report_notify_status_change')) return null;
    return array(
        'label'    => 'Bug reports',
        'group'    => 'Sent in by readers',
        'help'     => 'Problems readers reported on the site. Changing the status emails the reader when they asked for updates.',
        'views'    => kop_rinbox_bugs_statuses() + array('all' => 'All'),
        'view_counts' => function (array $q = array()) {
            global $wpdb;
            $out = array_fill_keys(array_keys(kop_rinbox_bugs_statuses()), 0);
            if (kop_rinbox_bugs_table_exists()) {
                foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n FROM bug_reports GROUP BY status') as $r) {
                    if (isset($out[$r->status])) $out[$r->status] = (int) $r->n;
                }
            }
            $out['all'] = array_sum($out);
            return $out;
        },
        'tool_url' => admin_url('admin.php?page=kop-bug-reports'),
        'count'    => function () {
            global $wpdb;
            if (!kop_rinbox_bugs_table_exists()) return 0;
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM bug_reports WHERE status = 'new'");
        },
        'list'     => 'kop_rinbox_bugs_list',
        'get'      => function ($key) {
            $r = kop_rinbox_bugs_row($key);
            return $r ? kop_rinbox_bugs_item($r) : null;
        },
        'act'      => 'kop_rinbox_bugs_act',
        'save'     => 'kop_rinbox_bugs_save',
    );
});

/** The table appears with the first report (api/save-bug-report.php creates it). */
function kop_rinbox_bugs_table_exists() {
    global $wpdb;
    return $wpdb->get_var("SHOW TABLES LIKE 'bug_reports'") === 'bug_reports';
}

function kop_rinbox_bugs_statuses() {
    return array('new' => 'New', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed');
}

/** The screen's category names. */
function kop_rinbox_bugs_categories() {
    return array(
        'save-failed' => 'Saving/submitting failed',
        'load-failed' => 'Something didn’t load',
        'broken-ui'   => 'Broken UI',
        'wrong-data'  => 'Wrong/missing data',
        'other'       => 'Other',
    );
}

function kop_rinbox_bugs_row($id) {
    global $wpdb;
    if (!kop_rinbox_bugs_table_exists()) return null;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', (int) $id)) ?: null;
}

function kop_rinbox_bugs_list(array $q) {
    global $wpdb;
    if (!kop_rinbox_bugs_table_exists()) return array('items' => array(), 'total' => 0);
    $status = isset(kop_rinbox_bugs_statuses()[$q['view']]) || $q['view'] === 'all' ? $q['view'] : 'new';
    $where = $status === 'all' ? '1=1' : $wpdb->prepare('status = %s', $status);
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (description LIKE %s OR feature LIKE %s OR page_url LIKE %s)', $like, $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM bug_reports WHERE $where");
    $rows = $wpdb->get_results("SELECT * FROM bug_reports WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    return array('items' => array_map('kop_rinbox_bugs_item', (array) $rows), 'total' => $total);
}

function kop_rinbox_bugs_item($r) {
    $cats = kop_rinbox_bugs_categories();
    $statuses = kop_rinbox_bugs_statuses();
    $sub = array();
    if (!empty($r->feature)) $sub[] = $r->feature;
    $sub[] = $cats[$r->category] ?? (string) $r->category;
    // What the old screen shows, the technical details shortened.
    $lines = array(trim((string) $r->description));
    if (!empty($r->steps)) $lines[] = 'Steps: ' . trim((string) $r->steps);
    if (!empty($r->contact)) $lines[] = 'Contact: ' . $r->contact . (!empty($r->notify_updates) ? ' (wants status updates)' : '');
    // The old screen's "Technical details": the browser and every error the page logged.
    $details = array();
    if (!empty($r->user_agent)) $details[] = array('label' => 'Browser', 'value' => $r->user_agent . ($r->viewport ? ' — ' . $r->viewport : ''));
    $errors = json_decode($r->console_errors ? $r->console_errors : 'null', true);
    if (is_array($errors) && $errors) {
        $msgs = array();
        foreach ($errors as $err) {
            if (is_array($err)) $msgs[] = '[' . ($err['type'] ?? '?') . '] ' . ($err['message'] ?? '');
        }
        if ($msgs) {
            $details[] = array('label' => 'Errors (' . count($msgs) . ')', 'value' => $msgs);
            $lines[] = 'Errors: ' . kop_rinbox_excerpt(implode('; ', array_slice($msgs, 0, 3)), 300) . (count($msgs) > 3 ? ' (and ' . (count($msgs) - 3) . ' more below)' : '');
        }
    }
    $buttons = array(
        'in_progress' => array('label' => 'Start working on it', 'style' => 'neutral'),
        'resolved'    => array('label' => 'Resolved', 'style' => 'approve'),
        'dismissed'   => array('label' => 'Dismiss', 'style' => 'reject'),
        'new'         => array('label' => 'Back to new', 'style' => 'undo'),
    );
    $actions = array();
    foreach ($buttons as $s => $b) {
        if ($s !== $r->status) $actions[] = array('id' => $s, 'label' => $b['label'], 'style' => $b['style']);
    }
    $title = trim(preg_replace('/\s+/u', ' ', (string) $r->description));
    return array(
        'key'          => (string) $r->id,
        'title'        => mb_strlen($title) > 90 ? mb_substr($title, 0, 89) . '…' : ($title !== '' ? $title : 'Report #' . (int) $r->id),
        'subtitle'     => implode(' · ', $sub),
        'url'          => (string) $r->page_url,
        'text'         => implode("\n", $lines),
        'created'      => (string) $r->created_at,
        'status'       => (string) $r->status,
        'status_label' => $statuses[$r->status] ?? ucwords(str_replace('_', ' ', (string) $r->status)),
        'fields'       => array(
            array('name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => $cats, 'value' => (string) $r->category, 'category' => true),
            array('name' => 'feature', 'label' => 'Feature', 'type' => 'text', 'value' => (string) $r->feature),
        ),
        'actions'      => $actions,
        'details'      => $details,
    );
}

function kop_rinbox_bugs_act($key, $action, array $params) {
    global $wpdb;
    $statuses = kop_rinbox_bugs_statuses();
    if (!isset($statuses[$action])) throw new RuntimeException('Unknown action.');
    // As the old screen: older tables get the reporter opt-in column before a status change.
    if (function_exists('kop_bug_ensure_notify_column')) {
        try {
            kop_bug_ensure_notify_column();
        } catch (Throwable $e) {
            // The column is there already, or this database cannot say.
        }
    }
    $existing = kop_rinbox_bugs_row($key);
    if (!$existing) throw new RuntimeException('That report is gone.');
    $wpdb->update('bug_reports', array('status' => $action), array('id' => (int) $key));
    // Emails the reporter when they asked for updates, as the Bug Reports screen does.
    $notified = kop_bug_report_notify_status_change($existing, $action, (string) $existing->status);
    kop_rinbox_flush_counts();
    return array('message' => 'Report #' . (int) $key . ' marked as ' . strtolower($statuses[$action]) . '.'
        . ($notified ? ' The reporter was emailed about the change.' : '')
        . ($action !== 'new' ? ' Back to new is on the ' . $statuses[$action] . ' tab.' : ''));
}

function kop_rinbox_bugs_save($key, array $fields) {
    global $wpdb;
    if (!kop_rinbox_bugs_row($key)) throw new RuntimeException('That report is gone.');
    $data = array();
    if (array_key_exists('category', $fields)) {
        if (!isset(kop_rinbox_bugs_categories()[$fields['category']])) throw new RuntimeException('Category: pick one from the list.');
        $data['category'] = (string) $fields['category'];
    }
    if (array_key_exists('feature', $fields)) {
        $v = trim(wp_strip_all_tags((string) $fields['feature']));
        $data['feature'] = $v !== '' ? mb_substr($v, 0, 120) : null;
    }
    if (!$data) return array('message' => 'Nothing to save.');
    $wpdb->update('bug_reports', $data, array('id' => (int) $key));
    return array('message' => 'Saved.');
}
