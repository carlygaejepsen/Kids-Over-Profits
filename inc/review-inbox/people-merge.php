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
        'view_counts' => 'kop_rinbox_pmerge_view_counts',
        'list'     => 'kop_rinbox_pmerge_list',
        'get'      => 'kop_rinbox_pmerge_get',
        'act'      => 'kop_rinbox_pmerge_act',
        'tools'    => kop_rinbox_pmerge_tools(),
        'tool'     => 'kop_rinbox_pmerge_tool',
        'lookup'   => 'kop_rinbox_pmerge_lookup',
    );
});

/** "Merge any two people" (Merge People), "Sync now" (People) and the map rebuild (merged people are drawn as one from it). */
function kop_rinbox_pmerge_tools() {
    $last = get_option('kop_people_last_sync', array());
    $tools = array(
        array('id' => 'merge_any', 'label' => 'Merge these two people', 'style' => 'approve',
            'help' => 'Merge any two people, also ones not suggested here. Type a name or #id for each.',
            'confirm' => 'Make the second person the same as the first? Their entries move to the kept person. You can undo it from the Merged tab.',
            'params' => array(
                array('name' => 'keep', 'label' => 'Keep', 'type' => 'text', 'value' => '', 'lookup' => 'person', 'placeholder' => 'Name or #id'),
                array('name' => 'drop', 'label' => 'Fold in', 'type' => 'text', 'value' => '', 'lookup' => 'person', 'placeholder' => 'Name or #id'),
            )),
        array('id' => 'sync', 'label' => 'Give new names an id now', 'style' => 'neutral',
            'help' => 'New names on the staff lists get a person id within the hour; this does it now (as "Sync now" on KOP Tools > People).'
                . (is_array($last) && !empty($last['at']) ? ' Last done ' . gmdate('M j, H:i', (int) $last['at']) . ' UTC.' : '')),
    );
    if (function_exists('kop_rinbox_map_rebuild_tool') && ($t = kop_rinbox_map_rebuild_tool('A merged person is drawn as one person on the network map from its next build.'))) $tools[] = $t;
    return $tools;
}

function kop_rinbox_pmerge_view_counts() {
    $data = kop_pmerge_screen_data();
    $counts = array('likely' => 0, 'check' => 0, 'merged' => 0, 'dismissed' => count($data['dismissed']));
    foreach ($data['pairs'] as $p) if (isset($counts[$p['tab']])) $counts[$p['tab']]++;
    foreach ($data['merged'] as $m) if (empty($m['undone'])) $counts['merged']++;
    return $counts;
}

/** People whose name or other name has $q in it, for the tool's two boxes: [{value: "#id", label}]. */
function kop_rinbox_pmerge_lookup($name, $q) {
    if ($name !== 'person' || !function_exists('kop_people_load')) return array();
    $q = mb_strtolower(trim(ltrim(trim($q), '#')));
    $out = array();
    foreach (kop_people_load()['rows'] as $id => $r) {
        if ($r['merged_into']) continue;
        if ((string) $id !== $q && mb_strpos(mb_strtolower($r['name'] . "\n" . (string) $r['aliases']), $q) === false) continue;
        $out[] = array('value' => '#' . $id, 'label' => $r['name'] . ' (#' . $id . ')');
        if (count($out) >= 20) break;
    }
    return $out;
}

/** "#12", "12" or an exact name -> a person id, as KOP Tools > People reads it; an error message otherwise. */
function kop_rinbox_pmerge_find($text, $not) {
    if (!function_exists('kop_people_admin_find')) {
        $path = get_stylesheet_directory() . '/inc/people-admin.php';
        if (file_exists($path)) require_once $path;
    }
    if (!function_exists('kop_people_admin_find')) throw new RuntimeException('The People screen is not installed here.');
    return kop_people_admin_find((string) $text, (int) $not);
}

function kop_rinbox_pmerge_tool($id, array $params) {
    if (function_exists('kop_people_install')) kop_people_install();
    if ($id === 'map_rebuild') return kop_rinbox_map_rebuild_run();
    if ($id === 'sync') {
        $s = kop_people_sync();
        delete_transient('kop_pmerge_screen');
        return array('message' => sprintf('Done: %d new ids, %d entries given an id.', (int) $s['created'], (int) $s['stamped']));
    }
    if ($id !== 'merge_any') throw new RuntimeException('Unknown tool.');
    try {
        $keep = kop_rinbox_pmerge_find(sanitize_text_field((string) ($params['keep'] ?? '')), 0);
        if (is_string($keep)) throw new RuntimeException('Keep: ' . $keep);
        $drop = kop_rinbox_pmerge_find(sanitize_text_field((string) ($params['drop'] ?? '')), 0);
        if (is_string($drop)) throw new RuntimeException('Fold in: ' . $drop);
        if ((int) $keep === (int) $drop) throw new RuntimeException('That is the same person.');
        return array('message' => kop_pmerge_do_merge((int) $keep, (int) $drop, kop_rinbox_reviewer()) . ' Undo is on the Merged tab.');
    } finally {
        delete_transient('kop_pmerge_screen');
    }
}

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
            return mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['text'] . ' ' . ($it['search_text'] ?? '')), $needle) !== false;
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

/** Where a person is named, one line per record. */
function kop_rinbox_pmerge_named_at(array $s) {
    $recs = array();
    foreach ((array) ($s['records'] ?? array()) as $r) $recs[] = $r['name'] . ($r['role'] !== '' ? ' (' . $r['role'] . ')' : '');
    $n = (int) ($s['n'] ?? count($recs));
    if ($n > count($recs)) $recs[] = 'and ' . ($n - count($recs)) . ' more';
    return $recs ? implode('; ', $recs) : 'nowhere on file';
}

/** The two people side by side, differences marked. */
function kop_rinbox_pmerge_compare(array $a, array $b, $keep = 0) {
    $head = function ($s) use ($keep) {
        return $s['name'] . ' (#' . $s['id'] . ')' . ($keep && (int) $s['id'] === (int) $keep ? ', suggested to keep' : '');
    };
    $kinds = function ($s) {
        $k = array();
        foreach ((array) ($s['records'] ?? array()) as $r) $k[$r['kind'] === 'map' ? 'the network map' : ($r['kind'] === 'operator' ? 'company staff' : 'facility staff')] = true;
        return implode(', ', array_keys($k));
    };
    return kop_rinbox_compare_rows(array($head($a), $head($b)), array(
        'Name'         => array($a['name'], $b['name']),
        'Also written' => array((array) ($a['aliases'] ?? array()), (array) ($b['aliases'] ?? array())),
        'Named on'     => array($kinds($a), $kinds($b)),
        'Named at'     => array(kop_rinbox_pmerge_named_at($a), kop_rinbox_pmerge_named_at($b)),
        'Entries'      => array((string) (int) ($a['n'] ?? 0), (string) (int) ($b['n'] ?? 0)),
    ));
}

/** One person in plain words, for search. */
function kop_rinbox_pmerge_side_text(array $s) {
    return $s['name'] . ' #' . $s['id'] . ' ' . implode(' ', (array) ($s['aliases'] ?? array())) . ' ' . kop_rinbox_pmerge_named_at($s);
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
    // Where each is named, as links (a few per person).
    foreach (array($a, $b) as $s) {
        $shown = 0;
        foreach ((array) ($s['records'] ?? array()) as $r) {
            if ($shown >= 4 || empty($r['url'])) continue;
            $links[] = array('label' => $r['name'] . ' (' . $s['name'] . ')', 'url' => $r['url']);
            $shown++;
        }
    }
    if ($status === 'dismissed') {
        $why = 'Marked not the same' . (!empty($p['by']) ? ' by ' . $p['by'] : '') . (!empty($p['at']) ? ' on ' . substr($p['at'], 0, 10) : '');
        $actions = array(array('id' => 'undismiss', 'label' => 'Put back on the list', 'style' => 'undo',
            'help' => 'Nothing on the site changes; the pair is suggested again.'));
    } else {
        $why = (string) $p['reason'];
        $actions = array(
            array('id' => 'merge', 'label' => 'Same person: merge', 'style' => 'approve',
                'help' => 'Makes ' . $a['name'] . ' and ' . $b['name'] . ' one person under the name picked below: the other name becomes another name of that person, their staff entries are listed together on facility and company pages, and the network map draws one person from its next build.',
                'params' => array(array('name' => 'keep', 'label' => 'Name to keep', 'type' => 'select', 'value' => (string) $keep,
                    'options' => array(
                        (string) $a['id'] => 'Keep ' . $a['name'] . ' (#' . $a['id'] . ')',
                        (string) $b['id'] => 'Keep ' . $b['name'] . ' (#' . $b['id'] . ')',
                    )))),
            array('id' => 'dismiss', 'label' => 'Reject: not the same person', 'style' => 'reject',
                'help' => 'Both people stay separate; this pair is not suggested again.'),
        );
    }
    return array(
        'key'          => (string) $p['key'],
        'title'        => $a['name'] . '  /  ' . $b['name'],
        'subtitle'     => $why,
        'text'         => $status === 'dismissed'
            ? 'These two were marked as different people, so they are not suggested.'
            : 'Suggested to keep: person #' . $keep . ' (named in more places). The other id forwards to it and its names become other names of the kept person. Two people who only share a name are split on KOP Tools > People (Separate).',
        'compare'      => kop_rinbox_pmerge_compare($a, $b, $status === 'dismissed' ? 0 : $keep),
        'search_text'  => kop_rinbox_pmerge_side_text($a) . ' ' . kop_rinbox_pmerge_side_text($b),
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
            'help' => 'Makes ' . $m['drop']['name'] . ' their own person again, with the staff entries they had.',
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
