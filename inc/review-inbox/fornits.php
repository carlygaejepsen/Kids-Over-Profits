<?php
/**
 * Review inbox source: what the old Fornits survivor forum says about each
 * facility (inc/fornits.php): discussion links, staff, incidents, survivor
 * accounts (added unpublished) and leads. Add goes through kop_fornits_apply(),
 * a lead's other destinations are "Move to", Undo is kop_fornits_undo(). The
 * reading's thread categories are the item's tags (the categories column);
 * any other tag is kept in the shared tags table.
 *
 * Everything the old screen (KOP Tools > Fornits) does is here too: the tabs
 * with counts, the kind, category and importance filters, the most important
 * items ticked to start with, Add to another record (the Record box on Add,
 * also in bulk), the whole post, "Read a few more now" (kop_fornits_read_batch),
 * "Check AI keys" (kop_fornits_check_ai) and the reading's progress.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('fornits', function () {
    if (!function_exists('kop_fornits_apply')) return null;
    $views = array('pending' => 'Waiting');
    foreach (kop_fornits_kinds() as $k => $label) $views[$k] = 'Waiting: ' . strtolower($label);
    $views['applied'] = 'Added';
    $views['rejected'] = 'Skipped or already there';
    return array(
        'label'    => 'Fornits',
        'group'    => 'Imports to review',
        'help'     => 'What the old Fornits survivor forum says about each facility, read by the AI (Gemini and Groq in turn): discussion links, staff, incidents, survivor accounts and leads. '
            . 'Each quote was checked against its post; one "not found in the post" starts unticked. The most important items start ticked: "Select all" and Add does a page. '
            . 'Add puts it on the facility record citing the post (discussion links under "Survivor posts and discussion"; survivor accounts unpublished until you tick "OK to publish" on the record). '
            . 'Wrong facility? Pick another in the Record box beside Add. Undo takes it back.',
        'views'    => $views,
        'filters'  => array(
            array('name' => 'kind', 'label' => 'Kind', 'options' => kop_fornits_kinds()),
            array('name' => 'cat', 'label' => 'Category', 'options' => kop_fornits_categories()),
            array('name' => 'min', 'label' => 'Importance', 'options' => array('3' => 'Key evidence only', '2' => 'Useful or better', '1' => 'Hide chatter', 'read' => 'Read by the AI')),
        ),
        'view_counts' => 'kop_rinbox_fornits_view_counts',
        'tools'    => array(
            array('id' => 'read_now', 'label' => 'Read a few more now', 'style' => 'neutral',
                'help' => 'Loads new batches and has the AI read a few more topics (up to a minute). The hourly run does this on its own.'),
            array('id' => 'check_ai', 'label' => 'Check AI keys', 'style' => 'neutral',
                'help' => 'Sends one tiny request to each AI provider through the site\'s own code and says whether it works. Keys are never shown.'),
            array('id' => 'status', 'label' => 'How far the reading is', 'style' => 'neutral',
                'help' => 'Topics loaded, read by the AI, calls used today and the last hourly run.'),
        ),
        'tool'     => 'kop_rinbox_fornits_tool',
        'tool_url' => admin_url('admin.php?page=kop-fornits'),
        'count'    => function () {
            global $wpdb;
            kop_fornits_ensure_tables();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_fornits_items_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_fornits_list',
        'get'      => function ($key) {
            $rows = kop_rinbox_fornits_select('i.pkey = %s', array(preg_replace('/[^a-f0-9]/', '', (string) $key)), 'i.id', 1, 0);
            return $rows ? kop_rinbox_fornits_item($rows[0]) : null;
        },
        'act'      => 'kop_rinbox_fornits_act',
        'save'     => 'kop_rinbox_fornits_save',
        'tags_get' => 'kop_rinbox_fornits_tags_get',
        'tags_set' => 'kop_rinbox_fornits_tags_set',
    );
});

// The reading's categories are offered as tags everywhere.
add_filter('kop_review_inbox_known_tags', function ($tags) {
    if (function_exists('kop_fornits_categories')) {
        foreach (array_keys(kop_fornits_categories()) as $c) $tags[] = str_replace('_', ' ', $c);
    }
    return $tags;
});

/** Items with their thread, as the old screen reads them. */
function kop_rinbox_fornits_select($where, array $args, $order, $limit, $offset) {
    global $wpdb;
    $sql = 'SELECT i.*, t.title AS topic_title, t.headline AS topic_headline, t.url AS topic_url, t.board_name, t.summary AS topic_summary, t.read_status
        FROM ' . kop_fornits_items_table() . ' i LEFT JOIN ' . kop_fornits_topics_table() . " t ON t.topic_id = i.topic_id
        WHERE {$where} ORDER BY {$order} LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
    return (array) $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
}

function kop_rinbox_fornits_list(array $q) {
    global $wpdb;
    kop_fornits_ensure_tables();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_fornits_synced')) {
        // New batch files are loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_fornits_synced', 1, MINUTE_IN_SECONDS);
        kop_fornits_sync(15);
    }
    $where = kop_rinbox_fornits_where($q['view'], $q);
    $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_fornits_items_table() . " i WHERE {$where}");
    $order = in_array($q['view'], array('applied', 'rejected'), true)
        ? 'i.reviewed_at DESC, i.id DESC'
        : 'i.importance DESC, i.preselect DESC, i.facility_id, i.post_date, i.id';
    $rows = kop_rinbox_fornits_select($where, array(), $order, $q['limit'], $q['offset']);
    return array('items' => array_map('kop_rinbox_fornits_item', $rows), 'total' => $total);
}

/** A view's items (as i) under the kind, category, importance and search filters. */
function kop_rinbox_fornits_where($view, array $q) {
    global $wpdb;
    $kinds = kop_fornits_kinds();
    if (isset($kinds[$view])) {
        $where = $wpdb->prepare("i.status = 'pending' AND i.kind = %s", $view);
    } elseif (in_array($view, array('applied', 'rejected'), true)) {
        $where = $wpdb->prepare('i.status = %s', $view);
    } else {
        $where = "i.status = 'pending'";
    }
    $f = (array) ($q['filters'] ?? array());
    if (!empty($f['kind'])) $where .= $wpdb->prepare(' AND i.kind = %s', $f['kind']);
    if (!empty($f['cat'])) {
        // FIND_IN_SET, written so SQLite reads it too. The value is one of the category keys (letters and "_").
        $c = $f['cat'];
        $where .= $wpdb->prepare(' AND (i.categories = %s OR i.categories LIKE %s OR i.categories LIKE %s OR i.categories LIKE %s)',
            $f['cat'], $c . ',%', '%,' . $c, '%,' . $c . ',%');
    }
    if (($f['min'] ?? '') === 'read') {
        $where .= " AND i.categories <> ''";
    } elseif (!empty($f['min'])) {
        $where .= $wpdb->prepare(' AND i.importance >= %d', (int) $f['min']);
    }
    if (($q['search'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (i.label LIKE %s OR i.quote LIKE %s OR i.author LIKE %s OR i.summary LIKE %s)', $like, $like, $like, $like);
    }
    return $where;
}

function kop_rinbox_fornits_view_counts(array $q) {
    global $wpdb;
    $out = array();
    foreach (array_merge(array('pending'), array_keys(kop_fornits_kinds()), array('applied', 'rejected')) as $v) {
        $out[$v] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_fornits_items_table() . ' i WHERE ' . kop_rinbox_fornits_where($v, $q));
    }
    return $out;
}

/** The old screen's progress line: topics loaded and read, calls today, the last hourly run. */
function kop_rinbox_fornits_progress() {
    global $wpdb;
    $t = $wpdb->get_row('SELECT COUNT(*) AS n, SUM(read_status = \'read\') AS done, SUM(read_status = \'error\') AS err FROM ' . kop_fornits_topics_table(), ARRAY_A);
    $last = get_option('kop_fornits_last_run', array());
    $calls = array();
    foreach (kop_fornits_calls_today() as $p => $n) $calls[] = ucfirst($p) . ' ' . $n . ' of ' . kop_fornits_caps()[$p];
    return 'Topics loaded: ' . (int) ($t['n'] ?? 0) . '; read by the AI: ' . (int) ($t['done'] ?? 0)
        . ((int) ($t['err'] ?? 0) ? '; could not read: ' . (int) $t['err'] : '')
        . '; AI calls today: ' . implode(', ', $calls)
        . (!empty($last['at']) ? '; last hourly run ' . get_date_from_gmt($last['at'], 'M j, g:i a') . ', read ' . (int) ($last['read'] ?? 0)
            . (!empty($last['limit']) ? ' (stopped at the daily limit)' : '') : '')
        . ((int) ($t['n'] ?? 0) ? '.' : '. No topics yet: scripts/fornits-process.py uploads batches to ' . kop_fornits_dir() . ' every hour while the crawl runs.');
}

function kop_rinbox_fornits_tool($id, array $params) {
    if ($id === 'read_now') {
        kop_fornits_sync(20);
        $c = kop_fornits_read_batch(6, 60);
        return array('message' => 'Read ' . $c['read'] . ' topics, ' . $c['items'] . ' new proposals'
            . ($c['limit'] ? '; the daily limit is reached, the hourly run carries on' : '') . ($c['error'] ? ', ' . $c['error'] . ' errors' : '') . '.');
    }
    if ($id === 'check_ai') {
        $lines = array();
        foreach (kop_fornits_check_ai() as $p => list($ok, $msg)) {
            $lines[] = ucfirst($p) . ': ' . ($ok ? '' : 'NOT WORKING. ') . $msg;
        }
        return array('message' => implode('  |  ', $lines));
    }
    if ($id === 'status') {
        return array('message' => kop_rinbox_fornits_progress());
    }
    throw new RuntimeException('Unknown tool.');
}

/** The kind of a single item, as a word. */
function kop_rinbox_fornits_kind_word($kind) {
    $w = array('link' => 'Discussion', 'staff' => 'Staff', 'incident' => 'Incident', 'testimony' => 'Survivor account', 'lead' => 'Lead');
    return $w[$kind] ?? ucfirst((string) $kind);
}

function kop_rinbox_fornits_incident_cats() {
    return kop_rinbox_options(array('death', 'abuse', 'sexual_abuse', 'restraint', 'seclusion', 'neglect', 'runaway', 'injury', 'lawsuit', 'investigation', 'other'));
}

function kop_rinbox_fornits_item(array $r) {
    $v = json_decode((string) $r['value'], true) ?: array();
    $also = json_decode((string) $r['also'], true) ?: array();
    $done = json_decode((string) $r['applied'], true) ?: array();
    $pending = $r['status'] === 'pending';
    $kind = (string) $r['kind'];
    $imp = kop_fornits_importance_labels();
    $post_url = kop_fornits_post_url($r['topic_id'], $r['post_n']);
    $headline = kop_fornits_has_headline($r) ? (string) $r['topic_headline'] : (string) $r['topic_title'];

    $lines = array();
    if ($kind === 'link') {
        if (trim((string) $r['summary']) !== '') $lines[] = trim((string) $r['summary']);
        if (trim((string) $r['topic_summary']) !== '' && $r['topic_summary'] !== $r['summary']) $lines[] = 'The thread: ' . trim((string) $r['topic_summary']);
        if ((string) $r['categories'] === '') $lines[] = 'Not read by the AI yet.';
    } elseif (trim((string) $r['topic_summary']) !== '') {
        $lines[] = 'The thread: ' . trim((string) $r['topic_summary']);
    }
    if ((string) $r['quote'] !== '') {
        $lines[] = ($kind === 'link' ? 'Opening words: ' : '') . '"' . kop_rinbox_excerpt($r['quote'], $kind === 'testimony' ? 1200 : 600) . '"';
    }
    if ($kind !== 'link' && !(int) $r['quote_found']) {
        $lines[] = 'Quote not found in the post: read the post before adding.';
    }
    if ($also) {
        $lines[] = 'The same name is on other records: ' . implode(', ', array_map(function ($a) {
            return ($a['name'] ?? '') . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . ' #' . (int) ($a['id'] ?? 0);
        }, array_slice($also, 0, 5))) . '. Check it is this one.';
    }
    if (!empty($done['reason'])) $lines[] = $done['reason'];

    $links = array();
    if ((string) $r['topic_url'] !== '') $links[] = array('label' => 'Thread: ' . kop_rinbox_excerpt($headline, 80), 'url' => (string) $r['topic_url']);
    if ($kind !== 'link') $links[] = array('label' => 'Post ' . ((int) $r['post_n'] + 1), 'url' => $post_url);
    if ($kind === 'lead' && !empty($v['url'])) $links[] = array('label' => 'Link in the post', 'url' => (string) $v['url']);

    $actions = array();
    $moves = array();
    if ($pending) {
        $label = 'Add to the record';
        if ($kind === 'lead') {
            $targets = kop_fornits_lead_targets();
            $default = kop_fornits_lead_target($v + array('url' => '', 'type' => 'other'));
            $label = 'Add: ' . $targets[$default];
            foreach ($targets as $id => $l) {
                if ($id === $default) continue;
                $m = array('id' => $id, 'label' => $l);
                if (!in_array($id, array('news', 'lawsuit'), true)) {
                    $m['params'] = array(array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => (int) $r['facility_id']));
                }
                $moves[] = $m;
            }
        } elseif ($kind === 'testimony') {
            $label = 'Add to the record (unpublished)';
        }
        $fac = kop_rinbox_facility($r['facility_id']);
        $fname = $fac ? $fac['name'] : 'the record you pick';
        if ($kind === 'lead') {
            $help = array(
                'news'    => 'Sends the lead to the news queue, where it waits for its own review; nothing is published yet and no email is sent.',
                'lawsuit' => 'Sends the lead to the lawsuits queue, where it waits for its own review; nothing is published yet and no email is sent.',
                'closed'  => 'Sets ' . $fname . ' to Closed on its facility page and the network map, citing the post.',
                'note'    => 'Adds this as a note on the record of ' . $fname . ', citing the post.',
            )[$default] ?? '';
        } else {
            $help = array(
                'link'      => 'Lists this thread on the facility page of ' . $fname . ' under "Survivor posts and discussion".',
                'staff'     => 'Adds ' . ((string) ($v['person'] ?? '') !== '' ? $v['person'] : 'this person') . ' to the staff list of ' . $fname . ', citing the post.',
                'incident'  => 'Adds this to the critical incidents of ' . $fname . ', citing the post.',
                'testimony' => 'Adds this account to the survivor testimony of ' . $fname . ', unpublished: it shows only after "OK to publish" is ticked on the record.',
            )[$kind] ?? '';
        }
        // The Record box is the old screen's "Add checked to that record".
        $actions[] = array('id' => 'apply', 'label' => $label, 'style' => 'approve', 'help' => $help, 'params' => array(
            array('name' => 'facility', 'label' => $kind === 'lead' ? 'Record (not needed for a queue)' : 'Record', 'type' => 'facility',
                'value' => (int) $r['facility_id'], 'optional' => true),
        ));
        $actions[] = array('id' => 'reject', 'label' => 'Reject: skip this', 'style' => 'reject',
            'help' => 'Nothing is added anywhere; the item moves to the Skipped tab.');
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Takes it off the record (or out of its queue) and puts it back in the waiting list.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'neutral',
            'help' => 'Nothing on the site changes; the item waits for review again.');
    }

    $ro = !$pending;
    $fields = array();
    if ($kind === 'link') {
        $fields[] = array('name' => 'label', 'label' => 'Link label', 'type' => 'text', 'value' => (string) $r['label'], 'readonly' => $ro);
        $fields[] = array('name' => 'summary', 'label' => 'What it says about this facility', 'type' => 'textarea', 'value' => (string) $r['summary'], 'readonly' => $ro);
    } elseif ($kind === 'staff') {
        $fields[] = array('name' => 'person', 'label' => 'Name', 'type' => 'text', 'value' => (string) ($v['person'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'role', 'label' => 'Role', 'type' => 'text', 'value' => (string) ($v['role'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'years', 'label' => 'Years', 'type' => 'text', 'value' => (string) ($v['years'] ?? ''), 'readonly' => $ro);
    } elseif ($kind === 'incident') {
        $fields[] = array('name' => 'incident', 'label' => 'What happened', 'type' => 'select', 'options' => kop_rinbox_fornits_incident_cats(), 'value' => (string) ($v['category'] ?? 'other'), 'readonly' => $ro);
        $fields[] = array('name' => 'year', 'label' => 'Year', 'type' => 'text', 'value' => (string) ($v['year'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'value' => (string) ($v['summary'] ?? ''), 'readonly' => $ro);
    } elseif ($kind === 'testimony') {
        $fields[] = array('name' => 'quote', 'label' => 'The account (goes on the record)', 'type' => 'textarea', 'value' => (string) $r['quote'], 'readonly' => $ro);
        $fields[] = array('name' => 'who', 'label' => 'Written by', 'type' => 'select', 'options' => array('survivor' => 'Survivor', 'parent' => 'Parent', 'staff' => 'Former staff'), 'value' => (string) ($v['who'] ?? 'survivor'), 'readonly' => $ro);
        $fields[] = array('name' => 'years', 'label' => 'Years there', 'type' => 'text', 'value' => (string) ($v['years'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'value' => (string) ($v['summary'] ?? ''), 'readonly' => $ro);
    } else {
        $fields[] = array('name' => 'lead_type', 'label' => 'Lead about', 'type' => 'select', 'options' => kop_rinbox_options(array('closure', 'lawsuit', 'news', 'investigation', 'other')), 'value' => (string) ($v['type'] ?? 'other'), 'readonly' => $ro);
        $fields[] = array('name' => 'year', 'label' => 'Year', 'type' => 'text', 'value' => (string) ($v['year'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'value' => (string) ($v['summary'] ?? ''), 'readonly' => $ro);
        $fields[] = array('name' => 'url', 'label' => 'Link', 'type' => 'text', 'value' => (string) ($v['url'] ?? ''), 'readonly' => $ro);
    }
    $fields[] = array('name' => 'importance', 'label' => 'How much it matters', 'type' => 'select', 'category' => true, 'readonly' => $ro,
        'options' => array_combine(array_map('strval', array_keys($imp)), array_values($imp)), 'value' => (string) (int) $r['importance']);
    $fields[] = array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => (int) $r['facility_id'], 'readonly' => $ro);

    $statuses = array('pending' => 'Waiting', 'applied' => 'Added', 'rejected' => 'Skipped');
    $fid = $r['status'] === 'applied' ? (int) $r['applied_fid'] : (int) $r['facility_id'];
    $when = kop_fornits_month($r['post_date']);

    $details = array();
    if ($kind === 'lead' && $pending) {
        $details[] = array('label' => 'Goes to', 'value' => kop_fornits_lead_targets()[kop_fornits_lead_target($v + array('url' => '', 'type' => 'other'))] . ' (change it with "Move to")');
    }
    if (kop_fornits_has_headline($r)) $details[] = array('label' => 'Thread title on the forum', 'value' => (string) $r['topic_title']);
    if ((string) $r['board_name'] !== '') $details[] = array('label' => 'Board', 'value' => (string) $r['board_name']);
    if ((string) $r['how'] !== '') $details[] = array('label' => 'Matched by', 'value' => (string) $r['how']);
    if ($kind === 'link' && (string) $r['quote'] !== '' && trim((string) $r['summary']) !== '') {
        $details[] = array('label' => 'Opening words', 'value' => (string) $r['quote']);
    }
    if ($kind !== 'link' && trim((string) $r['context']) !== '') {
        $details[] = array('label' => 'Whole post', 'value' => trim((string) $r['context']));
    }
    return array(
        'key'          => (string) $r['pkey'],
        'title'        => (string) $r['label'],
        'subtitle'     => implode(' · ', array_filter(array(
            kop_rinbox_fornits_kind_word($kind),
            (string) $r['categories'] !== '' ? ($imp[(int) $r['importance']] ?? '') : '',
            ($kind === 'link' ? 'Opening post' : 'Post ' . ((int) $r['post_n'] + 1)) . ' by ' . ($r['author'] !== '' ? $r['author'] : 'a member') . ($when ? ', ' . $when : ''),
            $r['board_name'] ? 'board "' . $r['board_name'] . '"' : '',
        ))),
        'url'          => $kind === 'link' ? (string) ($v['url'] ?? $r['topic_url']) : $post_url,
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => kop_rinbox_facility($fid),
        'fields'       => $fields,
        'actions'      => $actions,
        'moves'        => $moves,
        'links'        => $links,
        'details'      => $details,
        // The reading's most important items, with a quote found in the post, start ticked.
        'selected'     => $pending && (int) $r['preselect'] === 1,
    );
}

function kop_rinbox_fornits_row($key) {
    $rows = kop_fornits_rows(array($key));
    if (!$rows) throw new RuntimeException('That Fornits item is gone.');
    return $rows[0];
}

function kop_rinbox_fornits_act($key, $action, array $params) {
    global $wpdb;
    $r = kop_rinbox_fornits_row($key);
    $user = kop_rinbox_reviewer();
    switch ($action) {
        case 'apply':
        case 'move':
            if ($r['status'] !== 'pending') throw new RuntimeException('This item was already handled (' . $r['status'] . ').');
            $v = json_decode((string) $r['value'], true) ?: array();
            $target = '';
            if ($r['kind'] === 'lead') {
                $valid = kop_fornits_lead_targets();
                $target = $action === 'move' ? (string) ($params['to'] ?? '') : kop_fornits_lead_target($v + array('url' => '', 'type' => 'other'));
                if (!isset($valid[$target])) throw new RuntimeException('Pick where the lead goes.');
            } elseif ($action === 'move') {
                throw new RuntimeException('Only a lead can go somewhere else.');
            }
            $fid = (int) $r['facility_id'];
            $picked = (int) ($params['facility'] ?? 0);
            // Another record than the reading's match: kop_fornits_apply() puts it there, as "Add checked to that record" did.
            $other = $picked > 0 && $picked !== $fid ? $picked : 0;
            if ($other) $fid = $other;
            if ($fid <= 0 && !in_array($target, array('news', 'lawsuit'), true)) {
                throw new RuntimeException('Pick the facility first: choose it in the Record box beside Add, then add it.');
            }
            $res = kop_fornits_apply(array($r), array($r['pkey'] => $target), $other, $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing was done.');
            if (empty($res['ok'])) {
                if (!empty($res['keep'])) throw new RuntimeException($res['error']);
                return array('message' => 'Not added: ' . $res['error'] . ' It is now under "Skipped or already there".');
            }
            $name = $fid > 0 ? kop_rinbox_facility($fid)['name'] : '';
            $said = array(
                'link'      => 'Added to the page of ' . $name . ' under "Survivor posts and discussion".',
                'staff'     => 'Added ' . ($v['person'] ?? 'them') . ' to the staff of ' . $name . ', citing the post.',
                'incident'  => 'Added to the critical incidents of ' . $name . ', citing the post.',
                'testimony' => 'Added to the survivor testimony of ' . $name . ', unpublished: tick "OK to publish" on the record to show it.',
            )[$r['kind']] ?? '';
            if ($r['kind'] === 'lead') {
                $said = array(
                    'news'    => 'Sent to the news queue, waiting for review there (no emails sent).',
                    'lawsuit' => 'Sent to the lawsuits queue, waiting for review there (no emails sent).',
                    'closed'  => $name . ' is marked closed, citing the post.',
                    'note'    => 'Added as a note on ' . $name . ', citing the post.',
                )[$target];
            }
            return array('message' => $said . ' Undo is on the Added tab.');
        case 'reject':
            if ($r['status'] !== 'pending') throw new RuntimeException('Only a waiting item can be skipped.');
            $wpdb->update(kop_fornits_items_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            return array('message' => 'Skipped. Nothing was added; "Back to review" on the Skipped tab brings it back.');
        case 'undo':
            $was = $r['status'];
            $res = kop_fornits_undo(array($r), $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing to undo.');
            if (empty($res['ok'])) throw new RuntimeException($res['error']);
            return array('message' => $was === 'applied' ? 'Undone: it came off the record (or out of its queue). It is waiting for review again.' : 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_fornits_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_fornits_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo it (or put it back to review) before editing.');
    $v = json_decode((string) $r['value'], true) ?: array();
    $t = function ($k, $max = 400) use ($fields) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $fields[$k])), 0, $max); };
    $has = function ($k) use ($fields) { return array_key_exists($k, $fields); };
    $year = function ($k) use ($t) {
        $y = $t($k, 10);
        if ($y !== '' && !preg_match('/^(19|20)\d\d$/', $y)) throw new RuntimeException('"' . $y . '" is not a year.');
        return $y;
    };
    $set = array();
    $kind = $r['kind'];
    $label = null;
    if ($kind === 'link') {
        if ($has('label')) {
            if ($t('label', 1000) === '') throw new RuntimeException('The link needs a label.');
            $set['label'] = $t('label', 1000);
        }
        if ($has('summary')) $set['summary'] = $t('summary', 1000);
    } elseif ($kind === 'staff') {
        foreach (array('person' => 120, 'role' => 120, 'years' => 40) as $k => $max) if ($has($k)) $v[$k] = $t($k, $max);
        if (count(preg_split('/\s+/', trim((string) ($v['person'] ?? '')))) < 2) throw new RuntimeException('Give a first and last name.');
        $label = $v['person'] . ($v['role'] !== '' ? ', ' . $v['role'] : '') . ($v['years'] !== '' ? ' (' . $v['years'] . ')' : '');
    } elseif ($kind === 'incident') {
        if ($has('incident') && $fields['incident'] !== '') {
            if (!isset(kop_rinbox_fornits_incident_cats()[$fields['incident']])) throw new RuntimeException('Pick what happened from the list.');
            $v['category'] = (string) $fields['incident'];
        }
        if ($has('year')) $v['year'] = $year('year');
        if ($has('summary')) $v['summary'] = $t('summary');
        if (trim((string) ($v['summary'] ?? '')) === '') throw new RuntimeException('The incident needs a summary.');
        $label = ucfirst(str_replace('_', ' ', $v['category'])) . ': ' . $v['summary'];
    } elseif ($kind === 'testimony') {
        if ($has('quote')) {
            $quote = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $fields['quote']), " \"'"), 0, 1200);
            if ($quote === '') throw new RuntimeException('The account is empty. Skip it instead.');
            $set['quote'] = $quote;
            $set['quote_found'] = kop_fornits_quote_found($quote, (string) $r['context']) ? 1 : 0;
        }
        if ($has('who') && in_array($fields['who'], array('survivor', 'parent', 'staff'), true)) $v['who'] = $fields['who'];
        if ($has('years')) $v['years'] = $t('years', 40);
        if ($has('summary')) $v['summary'] = $t('summary');
        $label = ucfirst($v['who'] ?? 'survivor') . ' account' . (($v['years'] ?? '') !== '' ? ' (' . $v['years'] . ')' : '') . ': ' . ($v['summary'] ?? '');
    } else {
        if ($has('lead_type') && $fields['lead_type'] !== '') {
            if (!in_array($fields['lead_type'], array('closure', 'lawsuit', 'news', 'investigation', 'other'), true)) throw new RuntimeException('Pick what the lead is about from the list.');
            $v['type'] = (string) $fields['lead_type'];
        }
        if ($has('year')) $v['year'] = $year('year');
        if ($has('summary')) $v['summary'] = $t('summary');
        if ($has('url')) {
            $url = $t('url', 500);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new RuntimeException('The link must start with http:// or https://.');
            $v['url'] = $url;
        }
        $label = ucfirst($v['type'] ?? 'other') . ': ' . ($v['summary'] ?? '');
    }
    if ($kind !== 'link') {
        $set['value'] = wp_json_encode($v);
        $set['label'] = $label;
    }
    if ($has('importance') && $fields['importance'] !== '') {
        $set['importance'] = max(0, min(3, (int) $fields['importance']));
    }
    if ($has('facility_id') && (int) $fields['facility_id'] !== (int) $r['facility_id']) {
        $fid = max(0, (int) $fields['facility_id']);
        if ($fid > 0) {
            $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
        }
        $set['facility_id'] = $fid;
        $set['also'] = '[]';
        $set['how'] = $fid > 0 ? 'picked by ' . mb_substr(kop_rinbox_reviewer(), 0, 100) : '';
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_fornits_items_table(), $set, array('pkey' => $r['pkey']));
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}

/* ---- Tags: the reading's categories, plus any other tag ------------------ */

function kop_rinbox_fornits_tags_get($key) {
    global $wpdb;
    $tags = array();
    $cats = (string) $wpdb->get_var($wpdb->prepare('SELECT categories FROM ' . kop_fornits_items_table() . ' WHERE pkey = %s', (string) $key));
    foreach (array_filter(explode(',', $cats)) as $c) $tags[] = str_replace('_', ' ', trim($c));
    kop_rinbox_ensure_tables();
    foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT tag FROM ' . kop_rinbox_tags_table() . " WHERE source = 'fornits' AND item_key = %s ORDER BY tag", (string) $key)) as $t) {
        $tags[] = $t;
    }
    return $tags;
}

function kop_rinbox_fornits_tags_set($key, array $tags) {
    global $wpdb;
    $known = kop_fornits_categories();
    $cats = array();
    $free = array();
    foreach ($tags as $t) {
        $c = str_replace(' ', '_', mb_strtolower(trim((string) $t)));
        if (isset($known[$c])) $cats[] = $c;
        else $free[] = $t;
    }
    $wpdb->update(kop_fornits_items_table(), array('categories' => mb_substr(implode(',', $cats), 0, 255)), array('pkey' => (string) $key));
    kop_rinbox_ensure_tables();
    $table = kop_rinbox_tags_table();
    $wpdb->delete($table, array('source' => 'fornits', 'item_key' => (string) $key));
    foreach ($free as $t) {
        $wpdb->insert($table, array('source' => 'fornits', 'item_key' => (string) $key, 'tag' => $t,
            'created_by' => kop_rinbox_reviewer(), 'created_at' => current_time('mysql', true)));
    }
}
