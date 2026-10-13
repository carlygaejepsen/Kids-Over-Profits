<?php
/**
 * Review inbox source: document types (inc/doc-types.php). The AI's guess
 * at what kind of document a file is waits here until approved; nothing is
 * typed by the AI alone. "Typed by rule" lists what the folder and title
 * rules typed by themselves, to spot-check: "Right" takes it off the list,
 * "Wrong" takes the type off and lets the AI read it. Every action returns
 * the exact state before it, so Undo (here or in Recently done) puts the
 * types and the suggestion back as they were.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('doc-types', function () {
    if (!function_exists('kop_dt_types')) return null;
    return array(
        'label'    => 'Document types',
        'group'    => 'Documents',
        'help'     => 'What kind of document each file is (student handbook, enrollment document, inspection report, news clipping, legal filing...), so documents of one kind can be compared side by side on the public Documents by type pages. The AI reads each document the folder and title rules could not type and guesses; approve the guess, pick another type, or leave it untyped. "Typed by rule" is what the rules typed by themselves, to spot-check.',
        'views'    => array('pending' => 'AI guesses', 'unclear' => 'AI could not tell', 'rule' => 'Typed by rule', 'approved' => 'Approved', 'rejected' => 'Left untyped'),
        'view_counts' => 'kop_rinbox_dt_view_counts',
        'count'    => function () {
            $c = kop_rinbox_dt_view_counts();
            return (int) $c['pending'];
        },
        'list'     => 'kop_rinbox_dt_list',
        'get'      => function ($key) { return kop_rinbox_dt_item((int) $key, null); },
        'act'      => 'kop_rinbox_dt_act',
        'tools'    => array(array(
            'id'    => 'ask',
            'label' => 'Ask the AI about 10 more now',
            'help'  => 'Reads up to 10 more documents the rules could not type and adds the AI\'s guesses here. This also happens by itself every hour.',
            'style' => 'neutral',
            'params' => array(),
        )),
        'tool'     => function ($id, array $params) {
            if ($id !== 'ask') throw new RuntimeException('Unknown tool.');
            $r = kop_dt_ai_batch(10);
            $msg = 'The AI looked at ' . $r['asked'] . ': ' . $r['pending'] . ' new guesses, ' . $r['unclear'] . ' it could not tell; ' . $r['left'] . ' still waiting.';
            if ($r['errors']) $msg .= ' It failed on ' . $r['errors'] . ': ' . $r['error'];
            return array('message' => $msg);
        },
    );
});

function kop_rinbox_dt_view_counts(array $q = array()) {
    global $wpdb;
    $s = kop_dt_sugg_table();
    $t = kop_dt_table();
    $out = array('pending' => 0, 'unclear' => 0, 'rule' => 0, 'approved' => 0, 'rejected' => 0);
    foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) AS n FROM $s GROUP BY status", ARRAY_A) as $r) {
        if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['n'];
    }
    $out['rule'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT attachment_id) FROM $t WHERE source = 'rule'");
    return $out;
}

function kop_rinbox_dt_list(array $q) {
    global $wpdb;
    $view = isset(kop_rinbox_dt_view_counts()[$q['view']]) ? $q['view'] : 'pending';
    $p = $wpdb->posts;
    $args = array();
    if ($view === 'rule') {
        $from = "FROM (SELECT DISTINCT attachment_id FROM " . kop_dt_table() . " WHERE source = 'rule') x JOIN $p p ON p.ID = x.attachment_id";
        $where = '1 = 1';
    } else {
        $from = 'FROM ' . kop_dt_sugg_table() . " x JOIN $p p ON p.ID = x.attachment_id";
        $where = 'x.status = %s';
        $args[] = $view;
    }
    if ($q['search'] !== '') {
        $where .= ' AND p.post_title LIKE %s';
        $args[] = '%' . $wpdb->esc_like($q['search']) . '%';
    }
    $count_sql = "SELECT COUNT(*) $from WHERE $where";
    $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);
    $sql = "SELECT p.ID $from WHERE $where ORDER BY p.ID DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'];
    $ids = array_map('intval', $wpdb->get_col($args ? $wpdb->prepare($sql, $args) : $sql));
    $docs = kop_dt_load_docs($ids);
    kop_dt_types_of($ids);
    $items = array();
    foreach ($ids as $id) {
        $it = kop_rinbox_dt_item($id, $docs[$id] ?? null, $view === 'rule');
        if ($it) $items[] = $it;
    }
    return array('items' => $items, 'total' => $total);
}

/** The select of every type, as an action's question. */
function kop_rinbox_dt_type_param($value = '') {
    $opts = array();
    foreach (kop_dt_types() as $slug => $t) $opts[$slug] = $t['label'];
    return array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $opts, 'value' => $value);
}

function kop_rinbox_dt_item($id, $doc = null, $rule_view = null) {
    global $wpdb;
    if ($doc === null) {
        $docs = kop_dt_load_docs(array($id));
        $doc = $docs[$id] ?? null;
    }
    if (!$doc) return null;
    $types = kop_dt_types_for($id);
    $sugg = kop_dt_sugg_get($id);
    $rule_note = '';
    $from_rule = false;
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT source, note FROM ' . kop_dt_table() . ' WHERE attachment_id = %d', $id), ARRAY_A) as $r) {
        if ($r['source'] === 'rule') { $from_rule = true; $rule_note = (string) $r['note']; }
    }
    if ($rule_view === null) $rule_view = $from_rule && (!$sugg || !in_array($sugg['status'], array('pending', 'unclear'), true));
    $guess = $sugg && $sugg['types'] !== '' ? kop_dt_clean_types(explode(',', $sugg['types'])) : array();
    $labels = function ($list) { return implode(', ', array_map('kop_dt_label', $list)); };
    $path = kop_dt_folder_path($doc);
    $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';

    $sub = array();
    if ($path !== '') $sub[] = 'Filed in ' . $path;
    if ($doc['file'] !== '') $sub[] = $doc['file'];
    $text = array();
    $actions = array();
    $status = $rule_view ? 'rule' : ($sugg ? $sugg['status'] : 'none');

    if ($rule_view) {
        $text[] = 'Typed by rule as: ' . ($types ? $labels($types) : '(nothing now)') . ($rule_note !== '' ? "\nWhy: " . $rule_note . '.' : '');
        if ($from_rule) {
            $actions[] = array('id' => 'ok', 'label' => 'Right: keep it', 'style' => 'approve',
                'help' => 'Keeps the type and takes the document off this list.');
            $actions[] = array('id' => 'wrong', 'label' => 'Wrong: let the AI read it', 'style' => 'reject',
                'help' => 'Takes the type off; the AI reads the document within the hour and its guess comes to "AI guesses".');
        }
    } else {
        if ($guess) $text[] = 'The AI says: ' . $labels($guess) . '.';
        if ($sugg && trim((string) $sugg['why']) !== '') $text[] = ($guess ? 'Why: ' : 'The AI could not tell: ') . trim((string) $sugg['why']);
        $text[] = 'Its type now: ' . ($types ? $labels($types) : 'none');
        if ($sugg && in_array($sugg['status'], array('approved', 'rejected'), true) && $sugg['reviewed_by'] !== '') {
            $text[] = ucfirst($sugg['status'] === 'rejected' ? 'left untyped' : 'approved') . ' by ' . $sugg['reviewed_by'] . ', ' . $sugg['reviewed_at'] . ' UTC';
        }
        if ($status === 'pending' && $guess) {
            $actions[] = array('id' => 'approve', 'label' => 'Approve: ' . $labels($guess), 'style' => 'approve',
                'help' => 'Types it as ' . $labels($guess) . ': it shows on that type\'s page and its tile says so.');
        }
        if ($status === 'pending' || $status === 'unclear') {
            $actions[] = array('id' => 'reject', 'label' => 'Leave it untyped', 'style' => 'reject',
                'help' => 'No type. The AI is not asked about it again; you can still set one with the pencil or at KOP Tools > Document Types.');
            $actions[] = array('id' => 'reask', 'label' => 'Ask the AI again', 'style' => 'neutral',
                'help' => 'Reads the document again and replaces this guess.');
        }
        if ($status === 'approved' || $status === 'rejected') {
            $actions[] = array('id' => 'reopen', 'label' => 'Back to waiting', 'style' => 'undo',
                'help' => 'Puts back the type it had before the decision and returns the guess to "AI guesses".');
        }
    }
    $actions[] = array('id' => 'other', 'label' => 'Set this type instead', 'style' => 'neutral',
        'help' => 'Gives it the type you pick (and only that one), whatever the AI or the rules said.',
        'params' => array(kop_rinbox_dt_type_param($guess ? $guess[0] : ($types ? $types[0] : ''))));

    return array(
        'key'          => (string) $id,
        'title'        => function_exists('kop_doc_archive_title') ? kop_doc_archive_title($doc['title']) : $doc['title'],
        'subtitle'     => implode(' · ', $sub),
        'url'          => $url,
        'text'         => implode("\n", $text),
        'created'      => $sugg ? (string) $sugg['created_at'] : '',
        'status'       => $status,
        'status_label' => $rule_view ? 'Typed by rule' : ($status === 'pending' ? 'AI guess' : ($status === 'unclear' ? 'Could not tell' : ucfirst($status))),
        'actions'      => $actions,
        'details'      => array_values(array_filter(array(
            array('label' => 'Document number', 'value' => '#' . $id),
            $sugg && $sugg['provider'] !== '' ? array('label' => 'Read by', 'value' => ucfirst((string) $sugg['provider'])) : null,
        ))),
        'preview'      => $url !== '' ? array('label' => 'Open the document', 'url' => $url) : null,
    );
}

/** The state an Undo puts back: {types, source, sugg}. */
function kop_rinbox_dt_snapshot($id) {
    global $wpdb;
    $source = (string) $wpdb->get_var($wpdb->prepare('SELECT source FROM ' . kop_dt_table() . ' WHERE attachment_id = %d LIMIT 1', (int) $id));
    $note = (string) $wpdb->get_var($wpdb->prepare('SELECT note FROM ' . kop_dt_table() . ' WHERE attachment_id = %d LIMIT 1', (int) $id));
    return array('types' => kop_dt_types_for($id), 'source' => $source, 'note' => $note, 'sugg' => kop_dt_sugg_get($id));
}

function kop_rinbox_dt_act($key, $action, array $params) {
    $id = (int) $key;
    $docs = kop_dt_load_docs(array($id));
    if (!$docs) throw new RuntimeException('That document is gone from the media library.');
    $before = kop_rinbox_dt_snapshot($id);
    $undo = array('action' => 'restore', 'params' => array('state' => wp_json_encode($before)));
    $who = kop_rinbox_reviewer();
    $sugg = $before['sugg'];
    $guess = $sugg && $sugg['types'] !== '' ? kop_dt_clean_types(explode(',', $sugg['types'])) : array();
    switch ($action) {
        case 'approve':
            if (!$guess) throw new RuntimeException('There is no guess to approve. Pick a type with "Set this type instead".');
            kop_dt_change(array($id => $guess), 'ai', 'Approved the AI\'s guess', $who, (string) ($sugg['why'] ?? ''));
            kop_dt_sugg_status($id, 'approved', $who);
            return array('message' => 'Typed as ' . implode(', ', array_map('kop_dt_label', $guess)) . '.', 'undo' => $undo);
        case 'other':
            $type = sanitize_key((string) ($params['type'] ?? ''));
            if (!isset(kop_dt_types()[$type])) throw new RuntimeException('Pick a type first.');
            kop_dt_change(array($id => array($type)), 'person', 'Set in the Review inbox', $who);
            if ($sugg) kop_dt_sugg_status($id, 'approved', $who);
            return array('message' => 'Typed as ' . kop_dt_label($type) . '.', 'undo' => $undo);
        case 'reject':
            kop_dt_sugg_status($id, 'rejected', $who);
            return array('message' => 'Left untyped. The AI will not be asked about it again.', 'undo' => $undo);
        case 'reask':
            $row = kop_dt_ai_suggest($id);
            return array('message' => $row['status'] === 'pending' ? 'New guess: ' . implode(', ', array_map('kop_dt_label', explode(',', $row['types']))) . '.' : 'The AI still could not tell.', 'undo' => $undo);
        case 'ok':
            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . kop_dt_table() . " SET source = 'checked', created_by = %s WHERE attachment_id = %d AND source = 'rule'", $who, $id));
            kop_dt_changed();
            return array('message' => 'Kept. It is off the "Typed by rule" list.', 'undo' => $undo);
        case 'wrong':
            kop_dt_change(array($id => array()), 'rule', 'Rule type found wrong in the Review inbox', $who);
            kop_dt_sugg_put($id, array('status' => 'norule', 'why' => 'The rule\'s type was wrong', 'rules_version' => KOP_DOC_TYPES_RULES_VERSION));
            return array('message' => 'Type taken off. The AI will read it within the hour.', 'undo' => $undo);
        case 'reopen':
            $prev = kop_rinbox_dt_last_before($id);
            kop_dt_change(array($id => $prev), 'person', 'Back to waiting in the Review inbox', $who);
            kop_dt_sugg_status($id, $guess ? 'pending' : 'unclear', null);
            return array('message' => 'Back to waiting.', 'undo' => $undo);
        case 'restore':
            $state = json_decode((string) ($params['state'] ?? ''), true);
            if (!is_array($state)) throw new RuntimeException('Nothing to put back.');
            kop_dt_write_one($id, (array) $state['types'], $state['source'] !== '' ? $state['source'] : 'person', (string) ($state['note'] ?? ''), $who);
            kop_dt_sugg_put($id, is_array($state['sugg']) ? $state['sugg'] : null);
            kop_dt_types_of(null);
            kop_dt_changed();
            return array('message' => 'Put back the way it was.');
    }
    throw new RuntimeException('Unknown action.');
}

/** The types a document had before its last decision, from the change log ([] when not found). */
function kop_rinbox_dt_last_before($id) {
    foreach (kop_dt_log() as $e) {
        if (!empty($e['undone'])) continue;
        foreach ($e['changes'] as $c) {
            if ((int) $c['id'] === (int) $id) return kop_dt_clean_types($c['before']);
        }
    }
    return array();
}
