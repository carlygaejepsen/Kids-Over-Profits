<?php
/**
 * Review inbox source: pages of the Woodbury Reports newsletter that write
 * about a program (inc/woodbury-mentions.php). Filing puts those pages, as
 * their own small PDF, in the program's "Woodbury Reports Mentions" folder
 * through kop_wb_file_many() (a facility id, or c<id> for a company); Skip
 * and Put back are kop_wb_set_status(); Undo is kop_wb_undo(). Creating a
 * consultant, provider or company record for the pages stays on the old
 * screen (tool_url).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('woodbury-reports', function () {
    if (!function_exists('kop_wb_file_many')) return null;
    $views = array('pending' => 'Waiting');
    foreach (kop_wb_tabs() as $k => $t) $views[$k] = $t['label'];
    return array(
        'label'    => 'Woodbury Reports',
        'group'    => 'Imports to review',
        'help'     => 'Pages of the Woodbury Reports newsletter that write about a program. Filing puts those pages, as their own small PDF, '
            . 'in a "Woodbury Reports Mentions" folder on the facility\'s page; Undo takes the copy back out. '
            . 'To file under a consultant or provider, or create a record, use the full screen.',
        'views'    => $views,
        'tool_url' => admin_url('admin.php?page=kop-woodbury-reports'),
        'count'    => function () {
            global $wpdb;
            kop_wb_ensure_table();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wb_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_wb_list',
        'get'      => function ($key) {
            $r = kop_wb_get(preg_replace('/[^a-f0-9]/', '', (string) $key));
            return $r ? kop_rinbox_wb_item($r) : null;
        },
        'act'      => 'kop_rinbox_wb_act',
        'save'     => 'kop_rinbox_wb_save',
    );
});

function kop_rinbox_wb_kinds() {
    return array('section' => 'Article', 'fuzzy' => 'Article, close name', 'news' => 'News item', 'mention' => 'Mentioned', 'unmatched' => 'Article, no record');
}

function kop_rinbox_wb_list(array $q) {
    global $wpdb;
    kop_wb_ensure_table();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_wb_synced')) {
        // A new scan is loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_wb_synced', 1, MINUTE_IN_SECONDS);
        kop_wb_sync();
    }
    $tabs = kop_wb_tabs();
    $where = isset($tabs[$q['view']]) ? kop_wb_tab_where($q['view']) : "status = 'pending'";
    if ($q['search'] !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (facility_name LIKE %s OR header LIKE %s OR matched_name LIKE %s)', $like, $like, $like);
    }
    $table = kop_wb_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('filed', 'skipped'), true)
        ? 'reviewed_at DESC, id DESC'
        : "CASE kind WHEN 'section' THEN 0 WHEN 'fuzzy' THEN 1 WHEN 'news' THEN 2 WHEN 'mention' THEN 3 ELSE 4 END, facility_name = '', facility_name, header, issue_date, id";
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_wb_item', $rows), 'total' => $total);
}

function kop_rinbox_wb_item(array $r) {
    $kinds = kop_rinbox_wb_kinds();
    $pending = $r['status'] === 'pending';
    $filed = $r['status'] === 'filed';
    $alts = json_decode((string) $r['alternatives'], true);
    $alts = is_array($alts) ? $alts : array();
    $said = $r['header'] !== '' ? $r['header'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') : $r['matched_name'];
    $issue = 'Woodbury Reports, ' . $r['issue_label'] . ($r['issue_number'] !== '' ? ' (' . $r['issue_number'] . ')' : '') . ', ' . kop_wb_page_label($r['pages']);

    $lines = array();
    if ($said !== '') $lines[] = 'The pages say "' . $said . '".';
    if (trim((string) $r['snippet']) !== '') $lines[] = '"' . kop_rinbox_excerpt($r['snippet'], 600) . '"';
    if (trim((string) $r['note']) !== '') $lines[] = trim((string) $r['note']);
    $others = array();
    foreach (array_slice($alts, 0, 5) as $a) {
        if ((int) ($a['id'] ?? 0) !== (int) $r['facility_id']) {
            $others[] = ($a['name'] ?? '') . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . ' #' . (int) ($a['id'] ?? 0);
        }
    }
    if ($pending && $others) $lines[] = 'Other possible matches: ' . implode(', ', $others) . '.';
    if ($pending && !(int) $r['facility_id']) $lines[] = 'No facility matched this name: pick one under "File under".';
    if ($filed) {
        $where = array();
        foreach (kop_wb_places($r) as $p) $where[] = $p['name'] . ($p['kind'] === 'company' ? ' (company)' : '') . ($p['where'] !== '' ? ', folder ' . $p['where'] : '');
        if ($where) $lines[] = 'Filed under: ' . implode('; ', $where) . '.';
    }

    $links = array();
    if ($filed && (int) $r['attachment_id']) {
        $u = wp_get_attachment_url((int) $r['attachment_id']);
        if ($u) $links[] = array('label' => 'Filed PDF', 'url' => (string) $u);
    } elseif ((string) $r['file'] !== '') {
        // The cut pages wait outside the web root; the old screen's viewer sends them to a signed-in admin.
        $links[] = array('label' => 'View pages', 'url' => add_query_arg(array('action' => 'kop_wb_view', 'key' => $r['ckey'],
            'nonce' => wp_create_nonce('kop_woodbury')), admin_url('admin-ajax.php')));
    }
    $issue_url = (int) $r['issue_id'] ? wp_get_attachment_url((int) $r['issue_id']) : '';
    if ($issue_url) $links[] = array('label' => 'Full issue', 'url' => $issue_url . '#page=' . (int) strtok((string) $r['pages'], ','));

    $actions = array();
    if ($pending) {
        $actions[] = array('id' => 'file', 'label' => 'File under this facility', 'style' => 'approve', 'params' => array(
            array('name' => 'facility', 'label' => 'File under', 'type' => 'facility', 'value' => (int) $r['facility_id']),
            array('name' => 'also', 'label' => 'Also under (more ids; c12 for company 12)', 'type' => 'text', 'value' => ''),
        ));
        $actions[] = array('id' => 'skip', 'label' => 'Skip', 'style' => 'reject');
    } elseif ($filed) {
        $actions[] = array('id' => 'undo', 'label' => 'Undo filing', 'style' => 'undo',
            'confirm' => 'Delete the filed copy of these pages and put them back in the queue?');
    } else {
        $actions[] = array('id' => 'reopen', 'label' => 'Put back', 'style' => 'neutral');
    }

    $statuses = array('pending' => 'Waiting', 'filed' => 'Filed', 'skipped' => 'Skipped', 'gone' => 'No longer found');
    $title = $r['facility_name'] !== '' ? $r['facility_name'] : ($said !== '' ? $said : $issue);
    return array(
        'key'          => (string) $r['ckey'],
        'title'        => (string) $title,
        'subtitle'     => implode(' · ', array_filter(array($kinds[$r['kind']] ?? $r['kind'], $issue))),
        'url'          => '',
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => $r['target_kind'] === 'facility' || $r['target_kind'] === '' ? kop_rinbox_facility((int) $r['facility_id']) : null,
        'fields'       => array(
            array('name' => 'kind', 'label' => 'What it is', 'type' => 'select', 'options' => $kinds, 'value' => (string) $r['kind'], 'category' => true, 'readonly' => !$pending),
            array('name' => 'facility_id', 'label' => 'Best match', 'type' => 'facility', 'value' => (int) $r['facility_id'], 'readonly' => !$pending),
        ),
        'actions'      => $actions,
        'links'        => $links,
    );
}

function kop_rinbox_wb_row($key) {
    $r = kop_wb_get(preg_replace('/[^a-f0-9]/', '', (string) $key));
    if (!$r) throw new RuntimeException('Those Woodbury pages are gone from the list.');
    return $r;
}

function kop_rinbox_wb_act($key, $action, array $params) {
    $r = kop_rinbox_wb_row($key);
    $user = kop_rinbox_reviewer();
    switch ($action) {
        case 'file':
            if ($r['status'] !== 'pending') throw new RuntimeException('These pages were already handled (' . $r['status'] . ').');
            $tokens = array();
            $first = trim((string) ($params['facility'] ?? ''));
            if ($first === '' && (int) $r['facility_id'] > 0) $first = (string) (int) $r['facility_id'];
            foreach (array_merge(array($first), preg_split('/[\s,;]+/', (string) ($params['also'] ?? ''))) as $t) {
                $t = kop_wb_clean_token($t);
                if ($t !== '') $tokens[] = $t;
            }
            if (!$tokens) throw new RuntimeException('Pick the facility to file the pages under.');
            $res = kop_wb_file_many($r, $tokens, $user);
            $names = array();
            foreach ((array) ($res['places'] ?? array()) as $p) $names[] = $p['name'] . ($p['kind'] === 'company' ? ' (company)' : '');
            return array('message' => 'Filed' . ($names ? ' under ' . implode(', ', $names) : '') . ': the pages show in the "Woodbury Reports Mentions" folder on the page. Undo is on the Filed tab.');
        case 'skip':
            if ($r['status'] !== 'pending') throw new RuntimeException('Only waiting pages can be skipped.');
            kop_wb_set_status($r, 'skipped', $user);
            return array('message' => 'Skipped. Nothing was filed; "Put back" on the Skipped tab brings it back.');
        case 'reopen':
            if (!in_array($r['status'], array('skipped', 'gone'), true)) throw new RuntimeException('Only skipped pages can be put back.');
            kop_wb_set_status($r, 'pending', $user);
            return array('message' => 'Waiting for review again.');
        case 'undo':
            if ($r['status'] !== 'filed') throw new RuntimeException('Only filed pages can be undone.');
            kop_wb_undo($r, $user);
            return array('message' => 'Undone: the filed copy is deleted and the pages are waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_wb_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_wb_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo the filing (or put it back) before editing.');
    $set = array();
    if (isset($fields['kind']) && $fields['kind'] !== '') {
        if (!isset(kop_rinbox_wb_kinds()[$fields['kind']])) throw new RuntimeException('Pick what the pages are from the list.');
        $set['kind'] = (string) $fields['kind'];
    }
    if (array_key_exists('facility_id', $fields) && (int) $fields['facility_id'] !== (int) $r['facility_id']) {
        $fid = max(0, (int) $fields['facility_id']);
        $name = '';
        $state = '';
        if ($fid > 0) {
            $st = kop_rinbox_pdo()->prepare('SELECT name, state FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
            $name = (string) $f['name'];
            $state = (string) $f['state'];
        }
        $set['facility_id'] = $fid;
        $set['facility_name'] = mb_substr($name, 0, 255);
        $set['facility_state'] = mb_substr($state, 0, 8);
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_wb_table(), $set, array('ckey' => $r['ckey']));
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}
