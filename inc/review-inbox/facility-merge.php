<?php
/**
 * Review inbox source: facility records that look like one place under two
 * entries (KOP Tools > Merge Duplicates, inc/facility-merge.php). Merge runs
 * kop_fmerge_do_merge(), Undo kop_fmerge_do_undo(), "Not the same place"
 * kop_fmerge_set_dismissed(). Pairs come from kop_fmerge_screen_data(),
 * cached an hour by the queue; every action here clears it.
 *
 * Keys: "a:b" for a pair (also on the Not the same tab), "merge:<log id>"
 * for a merge on the Merged tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('facility-merge', function () {
    if (!function_exists('kop_fmerge_screen_data')) return null;
    return array(
        'label'    => 'Duplicate facilities',
        'group'    => 'Suggestions to check',
        'help'     => 'Two records that look like the same place. Pick the one to keep and merge: everything the other one had (facts, staff, articles, lawsuits, documents) moves onto it and its page forwards there. Every merge can be undone from the Merged tab.',
        'views'    => array('likely' => 'Likely the same', 'check' => 'Worth a look', 'address' => 'Same street address', 'merged' => 'Merged', 'dismissed' => 'Not the same'),
        'tool_url' => admin_url('admin.php?page=kop-merge-duplicates'),
        'count'    => function () {
            return count(kop_fmerge_screen_data()['pairs']);
        },
        'list'     => 'kop_rinbox_fmerge_list',
        'get'      => 'kop_rinbox_fmerge_get',
        'act'      => 'kop_rinbox_fmerge_act',
    );
});

function kop_rinbox_fmerge_list(array $q) {
    $data = kop_fmerge_screen_data();
    $view = $q['view'];
    $items = array();
    if ($view === 'merged') {
        foreach ($data['merged'] as $m) $items[] = kop_rinbox_fmerge_merged_item($m);
    } elseif ($view === 'dismissed') {
        foreach ($data['dismissed'] as $d) $items[] = kop_rinbox_fmerge_pair_item($d, 'dismissed');
    } else {
        foreach ($data['pairs'] as $p) if ($p['tab'] === $view) $items[] = kop_rinbox_fmerge_pair_item($p, $p['tab']);
    }
    if ($q['search'] !== '') {
        $needle = mb_strtolower($q['search']);
        $items = array_values(array_filter($items, function ($it) use ($needle) {
            return mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['text']), $needle) !== false;
        }));
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_fmerge_get($key) {
    $data = kop_fmerge_screen_data();
    if (strpos($key, 'merge:') === 0) {
        $id = substr($key, 6);
        foreach ($data['merged'] as $m) if ((string) $m['id'] === $id) return kop_rinbox_fmerge_merged_item($m);
        return null;
    }
    foreach ($data['pairs'] as $p) if ($p['key'] === $key) return kop_rinbox_fmerge_pair_item($p, $p['tab']);
    foreach ($data['dismissed'] as $d) if ($d['key'] === $key) return kop_rinbox_fmerge_pair_item($d, 'dismissed');
    return null;
}

/** One record of a pair in plain lines. */
function kop_rinbox_fmerge_side_text(array $s, $label) {
    $lines = array($label . ': ' . $s['name'] . ' (record #' . $s['id'] . ')');
    $where = array_filter(array($s['place'] ?? '', $s['status'] ?? '', $s['years'] ?? ''));
    $lines[] = '  ' . ($where ? implode(' · ', $where) : 'No place on file');
    if (!empty($s['address'])) $lines[] = '  Address: ' . $s['address'];
    $lines[] = '  Company: ' . (!empty($s['companies']) ? implode('; ', $s['companies']) : 'none recorded');
    if (!empty($s['type'])) $lines[] = '  Type: ' . $s['type'];
    if (isset($s['news'])) {
        $n = function ($k, $one, $many) use ($s) { $v = (int) ($s[$k] ?? 0); return $v . ' ' . ($v === 1 ? $one : $many); };
        $lines[] = '  ' . implode(', ', array($n('news', 'article', 'articles'), $n('lawsuits', 'lawsuit', 'lawsuits'),
            $n('docs', 'document', 'documents'), $n('staff', 'staff entry', 'staff entries')));
    }
    return implode("\n", $lines);
}

function kop_rinbox_fmerge_pair_item(array $p, $status) {
    $a = $p['a'];
    $b = $p['b'];
    $labels = array('likely' => 'Likely the same', 'check' => 'Worth a look', 'address' => 'Same street address', 'dismissed' => 'Marked not the same');
    $keep = (int) ($p['keep'] ?? $a['id']);
    $links = array();
    foreach (array($a, $b) as $s) {
        if (!empty($s['page'])) $links[] = array('label' => $s['name'] . ' (#' . $s['id'] . ')', 'url' => $s['page']);
    }
    if ($status === 'dismissed') {
        $why = 'Marked not the same' . (!empty($p['by']) ? ' by ' . $p['by'] : '') . (!empty($p['at']) ? ' on ' . substr($p['at'], 0, 10) : '');
        $actions = array(array('id' => 'undismiss', 'label' => 'Put back on the list', 'style' => 'neutral'));
    } else {
        $why = (string) $p['reason'];
        $actions = array(
            array('id' => 'merge', 'label' => 'Merge into one', 'style' => 'approve',
                'confirm' => 'Merge these two records? The one you do not keep becomes another name of the kept one. You can undo it from the Merged tab.',
                'params' => array(array('name' => 'keep', 'label' => 'Record to keep', 'type' => 'select', 'value' => (string) $keep,
                    'options' => array(
                        (string) $a['id'] => 'Keep ' . $a['name'] . ' (#' . $a['id'] . (!empty($a['place']) ? ', ' . $a['place'] : '') . ')',
                        (string) $b['id'] => 'Keep ' . $b['name'] . ' (#' . $b['id'] . (!empty($b['place']) ? ', ' . $b['place'] : '') . ')',
                    )))),
            array('id' => 'dismiss', 'label' => 'Not the same place', 'style' => 'reject'),
        );
    }
    return array(
        'key'          => (string) $p['key'],
        'title'        => $a['name'] . '  /  ' . $b['name'],
        'subtitle'     => $why . (!empty($a['place']) ? ' · ' . $a['place'] : ''),
        'text'         => ($status !== 'dismissed' ? 'Suggested to keep: record #' . $keep . ".\n\n" : '')
            . kop_rinbox_fmerge_side_text($a, 'First record') . "\n\n" . kop_rinbox_fmerge_side_text($b, 'Second record'),
        'status'       => $status,
        'status_label' => $labels[$status] ?? $status,
        'facility'     => kop_rinbox_facility($keep),
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_fmerge_merged_item(array $m) {
    $r = (array) ($m['report'] ?? array());
    $moved = array();
    foreach ((array) ($r['rows'] ?? array()) as $t => $n) $moved[] = $n . ' in ' . str_replace('_', ' ', preg_replace('/^wpdl_(kop_)?/', '', (string) $t));
    if (!empty($r['files'])) $moved[] = $r['files'] . ' document' . ($r['files'] == 1 ? '' : 's') . ' moved into the library';
    if (!empty($r['subfolders'])) $moved[] = $r['subfolders'] . ' subfolder' . ($r['subfolders'] == 1 ? '' : 's') . ' moved';
    $actions = array();
    if (!empty($m['canUndo'])) {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'confirm' => 'Split "' . $m['drop']['name'] . '" back out into its own record, with everything it had?');
    }
    return array(
        'key'          => 'merge:' . $m['id'],
        'title'        => '"' . $m['drop']['name'] . '" merged into "' . $m['keep']['name'] . '"',
        'subtitle'     => 'Record #' . $m['drop']['id'] . ' into #' . $m['keep']['id'] . (!empty($m['by']) ? ' · by ' . $m['by'] : ''),
        'text'         => $moved ? 'Moved: ' . implode(', ', $moved) . '.' : 'Nothing else pointed at the folded-in record.',
        'created'      => (string) ($m['at'] ?? ''),
        'status'       => !empty($m['undone']) ? 'undone' : 'merged',
        'status_label' => !empty($m['undone']) ? 'Undone' : 'Merged',
        'facility'     => kop_rinbox_facility((int) $m['keep']['id']),
        'url'          => (string) ($m['page'] ?? ''),
        'actions'      => $actions,
    );
}

function kop_rinbox_fmerge_act($key, $action, array $params) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $pdo = kop_rinbox_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $prefix = $wpdb->prefix;
    $login = kop_rinbox_reviewer();
    try {
        if ($action === 'undo') {
            if (strpos($key, 'merge:') !== 0) throw new RuntimeException('Only a merge can be undone.');
            return array('message' => kop_fmerge_do_undo($pdo, $prefix, substr($key, 6)));
        }
        if (!preg_match('/^(\d+):(\d+)$/', $key, $m)) throw new RuntimeException('That is not a pair of records.');
        $a = (int) $m[1];
        $b = (int) $m[2];
        switch ($action) {
            case 'merge':
                $keep = (int) ($params['keep'] ?? 0);
                if ($keep !== $a && $keep !== $b) throw new RuntimeException('Pick which of the two records to keep.');
                $drop = $keep === $a ? $b : $a;
                $message = kop_fmerge_do_merge($pdo, $prefix, $keep, $drop, $login);
                $log = kop_fmerge_log();
                $res = array('message' => $message . ' Undo is on the Merged tab.');
                if (!empty($log[0]['id']) && (int) ($log[0]['drop']['id'] ?? 0) === $drop) $res['key'] = 'merge:' . $log[0]['id'];
                return $res;
            case 'dismiss':
                return array('message' => kop_fmerge_set_dismissed($a, $b, true, $login));
            case 'undismiss':
                return array('message' => kop_fmerge_set_dismissed($a, $b, false, $login));
        }
        throw new RuntimeException('Unknown action.');
    } finally {
        kop_fmerge_flush();
    }
}
