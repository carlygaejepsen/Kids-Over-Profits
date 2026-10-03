<?php
/**
 * Review inbox source: links from the owner's Google Docs, HEAL's archived
 * site, the r/troubledteens wiki and SCIAD NET (inc/drive-docs.php). Add puts
 * a link where its kind belongs through kop_gdl_apply() (resource link or
 * website on the facility record, or the news, lawsuits or legislation
 * queue); "Move to" sends it somewhere else; Undo is kop_gdl_undo().
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('drive-docs', function () {
    if (!function_exists('kop_gdl_apply')) return null;
    return array(
        'label'    => 'Drive Docs',
        'group'    => 'Imports to review',
        'help'     => 'Links found in your Google Docs, HEAL\'s old site, the r/troubledteens wiki and SCIAD NET that the site does not have yet. '
            . 'Add sends each one where its kind belongs (news to the news queue, court records to lawsuits, the rest to the facility page); '
            . '"Move to" sends it somewhere else. Undo takes it back.',
        'views'    => array(
            'pending'  => 'Waiting',
            'facility' => 'Waiting, for a facility',
            'company'  => 'Waiting, company only',
            'none'     => 'Waiting, no facility',
            'applied'  => 'Added',
            'rejected' => 'Skipped or already there',
        ),
        'tool_url' => admin_url('admin.php?page=kop-drive-docs'),
        'count'    => function () {
            global $wpdb;
            kop_gdl_ensure_table();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_gdl_list',
        'get'      => function ($key) {
            $rows = kop_gdl_rows(array($key));
            return $rows ? kop_rinbox_gdl_item($rows[0]) : null;
        },
        'act'      => 'kop_rinbox_gdl_act',
        'save'     => 'kop_rinbox_gdl_save',
    );
});

function kop_rinbox_gdl_list(array $q) {
    global $wpdb;
    kop_gdl_ensure_table();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_gdl_synced')) {
        // New links files are loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_gdl_synced', 1, MINUTE_IN_SECONDS);
        kop_gdl_sync();
    }
    $tabs = kop_gdl_tabs();
    $where = $q['view'] === 'pending' ? "status = 'pending'" : ($tabs[$q['view']]['where'] ?? "status = 'pending'");
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (label LIKE %s OR url LIKE %s OR source_doc LIKE %s OR operator_name LIKE %s)', $like, $like, $like, $like);
    }
    $table = kop_gdl_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('applied', 'rejected'), true)
        ? 'reviewed_at DESC, id DESC'
        : 'facility_id = 0, facility_id, ' . kop_gdl_kind_order_sql() . ', label, id';
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_gdl_item', $rows), 'total' => $total);
}

function kop_rinbox_gdl_item(array $r) {
    $kinds = kop_gdl_kinds();
    $targets = kop_gdl_targets();
    $sources = kop_gdl_sources();
    $src = $sources[$r['source'] ?? ''] ?? array();
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    $done = json_decode((string) $r['applied'], true) ?: array();
    $pending = $r['status'] === 'pending';

    $lines = array();
    if (!empty($first['text'])) {
        $lines[] = '"' . kop_rinbox_excerpt(preg_replace('#https?://\S+#', '[link]', (string) $first['text']), 420) . '"';
    }
    $where = trim(($first['doc'] ?? '') . (!empty($first['tab']) ? ' > ' . $first['tab'] : '') . (!empty($first['heading']) ? ' > ' . $first['heading'] : ''));
    if ($where !== '') {
        $lines[] = 'From ' . $where . (count($seen) > 1 ? ' (and ' . (count($seen) - 1) . ' more place' . (count($seen) > 2 ? 's' : '') . ')' : '') . '.';
    }
    if ($pending && (int) $r['facility_id'] > 0 && !kop_gdl_sure_match($r)) {
        $lines[] = 'Matched by a close name (' . $r['facility_how'] . '): check it is this facility.';
    }
    if ($pending && (int) $r['facility_id'] === 0 && $r['operator_name'] !== '') {
        $lines[] = 'Company: ' . $r['operator_name'] . '. Pick the facility before adding it to a facility page.';
    }
    $also = json_decode((string) $r['also_named'], true) ?: array();
    if ($also) {
        $lines[] = (($r['source'] ?? '') === 'sciad' && (int) $r['facility_id'] === 0 ? 'Same name as these records, pick one: ' : 'Also names: ')
            . implode(', ', array_map(function ($a) {
                return ($a['name'] ?? '') . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . (!empty($a['id']) ? ' #' . (int) $a['id'] : '');
            }, array_slice($also, 0, 5)));
    }
    if (!empty($done['reason'])) {
        $lines[] = $done['reason'];
    } elseif (!empty($done['target'])) {
        $lines[] = 'Went to: ' . ($targets[$done['target']] ?? $done['target']) . (!empty($done['id']) ? ' #' . (int) $done['id'] : '') . '.';
    }

    $links = array();
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        $links[] = array('label' => 'Archived copy', 'url' => (string) $first['archive']);
    }
    if (!empty($src['credit_url'])) {
        $links[] = array('label' => 'Credit: ' . $src['label'], 'url' => $src['credit_url']);
    }

    $default = kop_gdl_default_target($r['kind']);
    $actions = array();
    $moves = array();
    if ($pending) {
        $actions[] = array('id' => 'apply', 'label' => 'Add: ' . $targets[$default], 'style' => 'approve');
        $actions[] = array('id' => 'reject', 'label' => 'Skip', 'style' => 'reject');
        foreach ($targets as $id => $label) {
            if ($id !== $default) $moves[] = array('id' => $id, 'label' => $label);
        }
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'neutral');
    }
    $statuses = array('pending' => 'Waiting', 'applied' => 'Added', 'rejected' => 'Skipped', 'gone' => 'No longer offered');
    $fid = $r['status'] === 'applied' ? (int) $r['applied_fid'] : (int) $r['facility_id'];

    return array(
        'key'          => (string) $r['pkey'],
        'title'        => $r['label'] !== '' ? (string) $r['label'] : (string) $r['url'],
        'subtitle'     => implode(' · ', array_filter(array($kinds[$r['kind']] ?? $r['kind'], $r['domain'], $src['label'] ?? ''))),
        'url'          => (string) $r['url'],
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => kop_rinbox_facility($fid),
        'fields'       => array(
            array('name' => 'label', 'label' => 'Label', 'type' => 'text', 'value' => (string) $r['label'], 'readonly' => !$pending),
            array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => $kinds, 'value' => (string) $r['kind'], 'category' => true, 'readonly' => !$pending),
            array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => (int) $r['facility_id'], 'readonly' => !$pending),
        ),
        'actions'      => $actions,
        'moves'        => $moves,
        'links'        => $links,
    );
}

/** The row, or a helpful error. */
function kop_rinbox_gdl_row($key) {
    $rows = kop_gdl_rows(array($key));
    if (!$rows) throw new RuntimeException('That link is gone from the Drive Docs list.');
    return $rows[0];
}

function kop_rinbox_gdl_act($key, $action, array $params) {
    global $wpdb;
    $r = kop_rinbox_gdl_row($key);
    $user = kop_rinbox_reviewer();
    $targets = kop_gdl_targets();
    switch ($action) {
        case 'apply':
        case 'move':
            $to = $action === 'move' ? (string) ($params['to'] ?? '') : kop_gdl_default_target($r['kind']);
            if (!isset($targets[$to])) throw new RuntimeException('Pick where the link goes.');
            if ($r['status'] !== 'pending') throw new RuntimeException('This link was already handled (' . $r['status'] . ').');
            $fid = (int) $r['facility_id'];
            if (kop_gdl_needs_facility($to) && $fid <= 0) {
                throw new RuntimeException('Pick the facility first: open "Edit details", choose it under Facility and save, then add it.');
            }
            $res = kop_gdl_apply(array($r), array($r['pkey'] => $to), $fid, $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing was done.');
            if (empty($res['ok'])) {
                if (!empty($res['keep'])) throw new RuntimeException($res['error']);
                return array('message' => 'Not added: ' . $res['error'] . ' It is now under "Skipped or already there".');
            }
            $where = kop_gdl_needs_facility($to)
                ? ($to === 'website' ? 'the website links of ' : 'the "Materials and links" list of ') . kop_rinbox_facility($fid)['name']
                : 'the ' . strtolower($targets[$to]) . ', waiting for review there (no emails sent)';
            return array('message' => 'Added to ' . $where . '. Undo is on the Added tab.');
        case 'reject':
            if ($r['status'] !== 'pending') throw new RuntimeException('Only a waiting link can be skipped.');
            $wpdb->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            return array('message' => 'Skipped. Nothing was added; "Back to review" on the Skipped tab brings it back.');
        case 'undo':
            $was = $r['status'];
            $res = kop_gdl_undo(array($r), $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing to undo.');
            if (empty($res['ok'])) throw new RuntimeException($res['error']);
            return array('message' => $was === 'applied' ? 'Undone: the link came off where it went. It is waiting for review again.' : 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_gdl_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_gdl_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo it (or put it back to review) before editing.');
    $set = array();
    if (array_key_exists('label', $fields)) {
        $set['label'] = trim(preg_replace('/\s+/u', ' ', (string) $fields['label']));
    }
    if (isset($fields['kind']) && $fields['kind'] !== '') {
        if (!isset(kop_gdl_kinds()[$fields['kind']])) throw new RuntimeException('Pick one of the kinds in the list.');
        $set['kind'] = (string) $fields['kind'];
    }
    if (array_key_exists('facility_id', $fields)) {
        $fid = (int) $fields['facility_id'];
        if ($fid !== (int) $r['facility_id']) {
            if ($fid > 0) {
                $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
                $st->execute(array($fid));
                if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
            }
            $set['facility_id'] = max(0, $fid);
            // Picked by a person: a sure match (kop_gdl_sure_match), so the old screen ticks it too.
            $set['facility_how'] = $fid > 0 ? 'picked by ' . mb_substr(kop_rinbox_reviewer(), 0, 28) : '';
        }
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_gdl_table(), $set, array('pkey' => $r['pkey']));
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}
