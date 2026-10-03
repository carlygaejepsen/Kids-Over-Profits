<?php
/**
 * Review inbox source: person ids that look like one person under two names
 * (KOP Tools > Merge People, inc/people-merge.php). Merge runs
 * kop_pmerge_do_merge(), Undo kop_pmerge_do_undo(), "Not the same person"
 * kop_pmerge_set_dismissed(). Pairs come from kop_pmerge_screen_data(),
 * cached an hour by the queue; every action here clears it.
 *
 * Keys: "a:b" for a pair (also on the Not the same tab), "merge:<log id>"
 * for a merge on the Merged tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('people-merge', function () {
    if (!function_exists('kop_pmerge_screen_data')) return null;
    return array(
        'label'    => 'Duplicate people',
        'group'    => 'Suggestions to check',
        'help'     => 'Two names on the staff lists and the map that look like one person (a short first name, a married name, a spelling). Merge makes them one person on every facility page, company page and the map; Undo on the Merged tab splits them again.',
        'views'    => array('likely' => 'Likely the same', 'check' => 'Worth a look', 'merged' => 'Merged', 'dismissed' => 'Not the same'),
        'tool_url' => admin_url('admin.php?page=kop-merge-people'),
        'count'    => function () {
            return count(kop_pmerge_screen_data()['pairs']);
        },
        'list'     => 'kop_rinbox_pmerge_list',
        'get'      => 'kop_rinbox_pmerge_get',
        'act'      => 'kop_rinbox_pmerge_act',
    );
});

function kop_rinbox_pmerge_list(array $q) {
    $data = kop_pmerge_screen_data();
    $items = array();
    if ($q['view'] === 'merged') {
        foreach ($data['merged'] as $m) $items[] = kop_rinbox_pmerge_merged_item($m);
    } elseif ($q['view'] === 'dismissed') {
        foreach ($data['dismissed'] as $d) $items[] = kop_rinbox_pmerge_pair_item($d, 'dismissed');
    } else {
        foreach ($data['pairs'] as $p) if ($p['tab'] === $q['view']) $items[] = kop_rinbox_pmerge_pair_item($p, $p['tab']);
    }
    if ($q['search'] !== '') {
        $needle = mb_strtolower($q['search']);
        $items = array_values(array_filter($items, function ($it) use ($needle) {
            return mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['text']), $needle) !== false;
        }));
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_pmerge_get($key) {
    $data = kop_pmerge_screen_data();
    if (strpos($key, 'merge:') === 0) {
        $id = substr($key, 6);
        foreach ($data['merged'] as $m) if ((string) $m['id'] === $id) return kop_rinbox_pmerge_merged_item($m);
        return null;
    }
    foreach ($data['pairs'] as $p) if ($p['key'] === $key) return kop_rinbox_pmerge_pair_item($p, $p['tab']);
    foreach ($data['dismissed'] as $d) if ($d['key'] === $key) return kop_rinbox_pmerge_pair_item($d, 'dismissed');
    return null;
}

/** One person of a pair in plain lines: names and where each is named. */
function kop_rinbox_pmerge_side_text(array $s, $label) {
    $lines = array($label . ': ' . $s['name'] . ' (person #' . $s['id'] . ')');
    if (!empty($s['aliases'])) $lines[] = '  Also written: ' . implode('; ', $s['aliases']);
    $recs = array();
    foreach ((array) ($s['records'] ?? array()) as $r) $recs[] = $r['name'] . ($r['role'] !== '' ? ' (' . $r['role'] . ')' : '');
    $n = (int) ($s['n'] ?? count($recs));
    $lines[] = '  Named at: ' . ($recs ? implode('; ', $recs) . ($n > count($recs) ? '; and ' . ($n - count($recs)) . ' more' : '') : 'nowhere on file');
    return implode("\n", $lines);
}

function kop_rinbox_pmerge_pair_item(array $p, $status) {
    $a = $p['a'];
    $b = $p['b'];
    $labels = array('likely' => 'Likely the same', 'check' => 'Worth a look', 'dismissed' => 'Marked not the same');
    $keep = (int) ($p['keep'] ?? $a['id']);
    $links = array();
    $facility = null;
    foreach (array($a, $b) as $s) {
        if (!empty($s['url'])) $links[] = array('label' => $s['name'] . ' (person #' . $s['id'] . ')', 'url' => $s['url']);
        foreach ((array) ($s['records'] ?? array()) as $r) {
            if (!$facility && $r['kind'] === 'facility') $facility = kop_rinbox_facility($r['id']);
        }
    }
    if ($status === 'dismissed') {
        $why = 'Marked not the same' . (!empty($p['by']) ? ' by ' . $p['by'] : '') . (!empty($p['at']) ? ' on ' . substr($p['at'], 0, 10) : '');
        $actions = array(array('id' => 'undismiss', 'label' => 'Put back on the list', 'style' => 'neutral'));
    } else {
        $why = (string) $p['reason'];
        $actions = array(
            array('id' => 'merge', 'label' => 'Same person: merge', 'style' => 'approve',
                'params' => array(array('name' => 'keep', 'label' => 'Name to keep', 'type' => 'select', 'value' => (string) $keep,
                    'options' => array(
                        (string) $a['id'] => 'Keep ' . $a['name'] . ' (#' . $a['id'] . ')',
                        (string) $b['id'] => 'Keep ' . $b['name'] . ' (#' . $b['id'] . ')',
                    )))),
            array('id' => 'dismiss', 'label' => 'Not the same person', 'style' => 'reject'),
        );
    }
    return array(
        'key'          => (string) $p['key'],
        'title'        => $a['name'] . '  /  ' . $b['name'],
        'subtitle'     => $why,
        'text'         => kop_rinbox_pmerge_side_text($a, 'First') . "\n\n" . kop_rinbox_pmerge_side_text($b, 'Second'),
        'status'       => $status,
        'status_label' => $labels[$status] ?? $status,
        'facility'     => $facility,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_pmerge_merged_item(array $m) {
    $actions = array();
    if (!empty($m['canUndo'])) {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'confirm' => 'Make "' . $m['drop']['name'] . '" their own person again, with the entries they had?');
    }
    $moved = (int) ($m['moved'] ?? 0);
    return array(
        'key'          => 'merge:' . $m['id'],
        'title'        => '"' . $m['drop']['name'] . '" merged into "' . $m['keep']['name'] . '"',
        'subtitle'     => 'Person #' . $m['drop']['id'] . ' into #' . $m['keep']['id'] . (!empty($m['by']) ? ' · by ' . $m['by'] : ''),
        'text'         => $moved ? $moved . ($moved === 1 ? ' staff entry moved.' : ' staff entries moved.') : 'No staff entries had to move.',
        'created'      => (string) ($m['at'] ?? ''),
        'status'       => !empty($m['undone']) ? 'undone' : 'merged',
        'status_label' => !empty($m['undone']) ? 'Undone' : 'Merged',
        'url'          => function_exists('kop_people_admin_url') ? (string) kop_people_admin_url(array('person' => (int) $m['keep']['id'])) : '',
        'actions'      => $actions,
    );
}

function kop_rinbox_pmerge_act($key, $action, array $params) {
    if (function_exists('kop_people_install')) kop_people_install();
    $login = kop_rinbox_reviewer();
    try {
        if ($action === 'undo') {
            if (strpos($key, 'merge:') !== 0) throw new RuntimeException('Only a merge can be undone.');
            return array('message' => kop_pmerge_do_undo(substr($key, 6)));
        }
        if (!preg_match('/^(\d+):(\d+)$/', $key, $m)) throw new RuntimeException('That is not a pair of people.');
        $a = (int) $m[1];
        $b = (int) $m[2];
        switch ($action) {
            case 'merge':
                $keep = (int) ($params['keep'] ?? 0);
                if ($keep !== $a && $keep !== $b) throw new RuntimeException('Pick which of the two names to keep.');
                $drop = $keep === $a ? $b : $a;
                $message = kop_pmerge_do_merge($keep, $drop, $login);
                $log = kop_pmerge_log();
                $res = array('message' => $message . ' Undo is on the Merged tab.');
                if (!empty($log[0]['id']) && (int) ($log[0]['drop']['id'] ?? 0) === $drop) $res['key'] = 'merge:' . $log[0]['id'];
                return $res;
            case 'dismiss':
                return array('message' => kop_pmerge_set_dismissed($a, $b, true, $login));
            case 'undismiss':
                return array('message' => kop_pmerge_set_dismissed($a, $b, false, $login));
        }
        throw new RuntimeException('Unknown action.');
    } finally {
        delete_transient('kop_pmerge_screen');
    }
}
