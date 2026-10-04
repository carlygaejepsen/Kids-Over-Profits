<?php
/**
 * Review inbox source: reader notes from the glossary's "My facility used
 * this too" and "Suggest a correction" buttons (inc/glossary-feedback.php).
 * The change itself is made in the Glossary Editor; here a note is marked
 * added or dismissed, as on the old screen, and can be reopened.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('glossary-feedback', function () {
    if (!function_exists('kop_glossary_feedback_statuses')) return null;
    return array(
        'label'    => 'Glossary feedback',
        'group'    => 'Sent in by readers',
        'help'     => 'Notes readers left on glossary entries. Make the change with the Edit entry link (the Glossary Editor), then mark the note added; dismiss notes that need no change.',
        'views'    => kop_glossary_feedback_statuses() + array('all' => 'All'),
        'view_counts' => function (array $q = array()) {
            global $wpdb;
            kop_glossary_feedback_ensure_table();
            $out = array_fill_keys(array_keys(kop_glossary_feedback_statuses()), 0);
            foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . kop_glossary_feedback_table() . ' GROUP BY status') as $r) {
                if (isset($out[$r->status])) $out[$r->status] = (int) $r->n;
            }
            $out['all'] = array_sum($out);
            return $out;
        },
        'tool_url' => admin_url('admin.php?page=kop-glossary-feedback'),
        'count'    => function () {
            global $wpdb;
            kop_glossary_feedback_ensure_table();
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . kop_glossary_feedback_table() . " WHERE status = 'new'");
        },
        'list'     => 'kop_rinbox_gfeedback_list',
        'get'      => function ($key) {
            $r = kop_rinbox_gfeedback_row($key);
            return $r ? kop_rinbox_gfeedback_item($r) : null;
        },
        'act'      => 'kop_rinbox_gfeedback_act',
        'save'     => 'kop_rinbox_gfeedback_save',
    );
});

function kop_rinbox_gfeedback_row($id) {
    global $wpdb;
    kop_glossary_feedback_ensure_table();
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_glossary_feedback_table() . ' WHERE id = %d', (int) $id)) ?: null;
}

function kop_rinbox_gfeedback_list(array $q) {
    global $wpdb;
    kop_glossary_feedback_ensure_table();
    $table = kop_glossary_feedback_table();
    $status = isset(kop_glossary_feedback_statuses()[$q['view']]) || $q['view'] === 'all' ? $q['view'] : 'new';
    $where = $status === 'all' ? '1=1' : $wpdb->prepare('status = %s', $status);
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (term LIKE %s OR program LIKE %s OR details LIKE %s)', $like, $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");
    $rows = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    return array('items' => array_map('kop_rinbox_gfeedback_item', (array) $rows), 'total' => $total);
}

function kop_rinbox_gfeedback_item($r) {
    $kinds = kop_glossary_feedback_kinds();
    $statuses = kop_glossary_feedback_statuses();
    $entry_id = kop_glossary_feedback_entry_id($r);
    $slug = defined('KOP_GLOSSARY_SLUG') ? KOP_GLOSSARY_SLUG : 'glossary';
    // What the old screen shows: program, the note, the source and the reader's contact.
    $text = trim(implode("\n", array_filter(array(
        $r->program ? 'Program: ' . $r->program : '',
        $r->details ? (string) $r->details : '',
        $r->source ? 'Source: ' . $r->source : '',
        $r->contact ? 'Contact: ' . $r->contact : '',
    ))));
    // Every other status, as the old screen's "Mark as" buttons.
    $buttons = array(
        'added'     => array('label' => 'Mark added to glossary', 'style' => 'approve'),
        'dismissed' => array('label' => 'Dismiss', 'style' => 'reject'),
        'new'       => array('label' => 'Back to new', 'style' => 'undo'),
    );
    $actions = array();
    foreach ($buttons as $s => $b) {
        if ($s !== $r->status) $actions[] = array('id' => $s, 'label' => $b['label'], 'style' => $b['style']);
    }
    $editor = add_query_arg($entry_id !== ''
        ? array('view' => 'edit', 'entry' => $entry_id, 'feedback' => (int) $r->id)
        : array('view' => 'new', 'feedback' => (int) $r->id), admin_url('admin.php?page=kop-glossary-editor'));
    return array(
        'key'          => (string) $r->id,
        'title'        => (string) $r->term,
        'subtitle'     => $kinds[$r->kind] ?? (string) $r->kind,
        'url'          => home_url('/' . $slug . '/' . ($entry_id !== '' ? '#' . $entry_id : '')),
        'text'         => $text,
        'created'      => (string) $r->created_at,
        'status'       => (string) $r->status,
        'status_label' => $statuses[$r->status] ?? (string) $r->status,
        'fields'       => array(
            array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => $kinds, 'value' => (string) $r->kind, 'category' => true),
            array('name' => 'program', 'label' => 'Program', 'type' => 'text', 'value' => (string) $r->program),
            array('name' => 'details', 'label' => 'Note', 'type' => 'textarea', 'value' => (string) $r->details),
            array('name' => 'source', 'label' => 'Source', 'type' => 'text', 'value' => (string) $r->source),
        ),
        'actions'      => $actions,
        'links'        => array(array('label' => $entry_id !== '' ? 'Edit entry' : 'Entry gone: add it', 'url' => $editor)),
        'details'      => array_values(array_filter(array(
            array('label' => 'Note', 'value' => '#' . (int) $r->id),
            $r->contact ? array('label' => 'Reader\'s email', 'value' => (string) $r->contact, 'url' => 'mailto:' . $r->contact) : null,
        ))),
    );
}

function kop_rinbox_gfeedback_act($key, $action, array $params) {
    global $wpdb;
    $statuses = kop_glossary_feedback_statuses();
    if (!isset($statuses[$action])) throw new RuntimeException('Unknown action.');
    $r = kop_rinbox_gfeedback_row($key);
    if (!$r) throw new RuntimeException('That note is gone.');
    // The old screen's status change.
    $wpdb->update(kop_glossary_feedback_table(), array('status' => $action), array('id' => (int) $key));
    kop_rinbox_flush_counts();
    $messages = array(
        'added'     => 'Marked added to the glossary. Back to new is on the Added tab.',
        'dismissed' => 'Dismissed. The glossary is unchanged; Back to new is on the Dismissed tab.',
        'new'       => 'Back with the new notes.',
    );
    return array('message' => $messages[$action]);
}

function kop_rinbox_gfeedback_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_gfeedback_row($key);
    if (!$r) throw new RuntimeException('That note is gone.');
    $data = array();
    if (array_key_exists('kind', $fields)) {
        if (!isset(kop_glossary_feedback_kinds()[$fields['kind']])) throw new RuntimeException('Kind: pick one from the list.');
        $data['kind'] = (string) $fields['kind'];
    }
    // The same plain-text caps the public form applies.
    $caps = array('program' => 200, 'details' => 4000, 'source' => 500);
    foreach ($caps as $col => $max) {
        if (array_key_exists($col, $fields)) {
            $v = kop_glossary_feedback_text($fields[$col], $max);
            $data[$col] = $v !== '' ? $v : null;
        }
    }
    if (!$data) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_glossary_feedback_table(), $data, array('id' => (int) $key));
    return array('message' => 'Saved.');
}
