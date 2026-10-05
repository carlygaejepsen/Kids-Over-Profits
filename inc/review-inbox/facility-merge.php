<?php
/**
 * Review inbox source: facility records that look like one place under two
 * entries (KOP Tools > Merge Duplicates, inc/facility-merge.php). Merge runs
 * kop_fmerge_do_merge(), Undo kop_fmerge_do_undo(), "Not the same place"
 * kop_fmerge_set_dismissed(). Pairs come from kop_fmerge_screen_data(),
 * cached an hour by the queue; every action here clears it.
 *
 * "Homes of one program" (two homes or cottages of one program, not one
 * place) runs kop_fmerge_make_homes(): both are tied to a program record
 * through Program Homes and the pair is marked not the same; its Undo
 * (kop_fmerge_undo_homes()) is on the Not the same tab. Pairs named
 * "Program – Home A" / "Program – Home B" are on their own tab.
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
        'help'     => 'Two records that look like the same place. Pick the one to keep and merge: everything the other one had (facts, staff, articles, lawsuits, documents) moves onto it and its page forwards there. Every merge can be undone from the Merged tab. Two homes or cottages of one program are not duplicates: "Homes of one program" lists both on the program\'s page instead.',
        'views'    => array('likely' => 'Likely the same', 'check' => 'Worth a look', 'address' => 'Same street address', 'homes' => 'Looks like homes of one program', 'merged' => 'Merged', 'dismissed' => 'Not the same'),
        'tool_url' => admin_url('admin.php?page=kop-merge-duplicates'),
        'count'    => function () {
            return count(kop_fmerge_screen_data()['pairs']);
        },
        'view_counts' => 'kop_rinbox_fmerge_view_counts',
        'list'     => 'kop_rinbox_fmerge_list',
        'get'      => 'kop_rinbox_fmerge_get',
        'act'      => 'kop_rinbox_fmerge_act',
        // "Merge any two records" of the Merge Duplicates screen.
        'tools'    => array(array('id' => 'merge_any', 'label' => 'Merge these two records', 'style' => 'approve',
            'help' => 'Merge any two records, also ones not suggested here: find the one to keep and the one to fold into it.',
            'confirm' => 'Fold the second record into the first? Everything it has moves onto the kept record. You can undo it from the Merged tab.',
            'params' => array(
                array('name' => 'keep', 'label' => 'Keep', 'type' => 'facility', 'value' => ''),
                array('name' => 'drop', 'label' => 'Fold in', 'type' => 'facility', 'value' => ''),
            ))),
        'tool'     => 'kop_rinbox_fmerge_tool',
    );
});

/** How many are on each tab, as the Merge Duplicates screen counts them. */
function kop_rinbox_fmerge_view_counts() {
    $data = kop_fmerge_screen_data();
    $counts = array('likely' => 0, 'check' => 0, 'address' => 0, 'homes' => 0, 'merged' => 0, 'dismissed' => count($data['dismissed']));
    foreach ($data['pairs'] as $p) if (isset($counts[$p['tab']])) $counts[$p['tab']]++;
    foreach ($data['merged'] as $m) if (empty($m['undone'])) $counts['merged']++;
    return $counts;
}

function kop_rinbox_fmerge_tool($id, array $params) {
    if ($id !== 'merge_any') throw new RuntimeException('Unknown tool.');
    $keep = (int) ($params['keep'] ?? 0);
    $drop = (int) ($params['drop'] ?? 0);
    if ($keep <= 0 || $drop <= 0 || $keep === $drop) throw new RuntimeException('Find the two records first (two different ones).');
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $pdo = kop_rinbox_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        return array('message' => kop_fmerge_do_merge($pdo, $wpdb->prefix, $keep, $drop, kop_rinbox_reviewer()) . ' Undo is on the Merged tab.');
    } finally {
        kop_fmerge_flush();
    }
}

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
            return mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['text'] . ' ' . ($it['search_text'] ?? '')), $needle) !== false;
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

/** The two records side by side: what each has on file, differences marked. */
function kop_rinbox_fmerge_compare(array $a, array $b, $keep = 0) {
    $head = function ($s) use ($keep) {
        return $s['name'] . ' (#' . $s['id'] . ')' . ($keep && (int) $s['id'] === (int) $keep ? ', suggested to keep' : '');
    };
    $n = function ($s, $k) { return isset($s[$k]) ? (string) (int) $s[$k] : ''; };
    $rows = array(
        'Name'           => array($a['name'], $b['name']),
        'Place'          => array($a['place'] ?? '', $b['place'] ?? ''),
        'Street address' => array($a['address'] ?? '', $b['address'] ?? ''),
        'Status'         => array($a['status'] ?? '', $b['status'] ?? ''),
        'Years'          => array($a['years'] ?? '', $b['years'] ?? ''),
        'Type'           => array($a['type'] ?? '', $b['type'] ?? ''),
        'Company'        => array(!empty($a['companies']) ? $a['companies'] : 'none recorded', !empty($b['companies']) ? $b['companies'] : 'none recorded'),
        'Articles'       => array($n($a, 'news'), $n($b, 'news')),
        'Lawsuits'       => array($n($a, 'lawsuits'), $n($b, 'lawsuits')),
        'Documents'      => array($n($a, 'docs'), $n($b, 'docs')),
        'Staff entries'  => array($n($a, 'staff'), $n($b, 'staff')),
        'Has a page'     => array(!empty($a['page']) ? 'yes' : 'no', !empty($b['page']) ? 'yes' : 'no'),
    );
    return kop_rinbox_compare_rows(array($head($a), $head($b)), $rows);
}

/** One record of a pair in plain words, for search. */
function kop_rinbox_fmerge_side_text(array $s) {
    return trim($s['name'] . ' #' . $s['id'] . ' ' . ($s['place'] ?? '') . ' ' . ($s['address'] ?? '') . ' ' . implode(' ', (array) ($s['companies'] ?? array())));
}

function kop_rinbox_fmerge_pair_item(array $p, $status) {
    $a = $p['a'];
    $b = $p['b'];
    $labels = array('likely' => 'Likely the same', 'check' => 'Worth a look', 'address' => 'Same street address',
        'homes' => 'Looks like homes of one program', 'dismissed' => 'Marked not the same');
    $keep = (int) ($p['keep'] ?? $a['id']);
    $links = array();
    foreach (array($a, $b) as $s) {
        if (!empty($s['page'])) $links[] = array('label' => $s['name'] . ' (#' . $s['id'] . ')', 'url' => $s['page']);
    }
    if ($status === 'dismissed') {
        $why = 'Marked not the same' . (!empty($p['by']) ? ' by ' . $p['by'] : '') . (!empty($p['at']) ? ' on ' . substr($p['at'], 0, 10) : '');
        $actions = array(array('id' => 'undismiss', 'label' => 'Put back on the list', 'style' => 'undo',
            'help' => 'Nothing on the site changes; the pair is suggested again.'));
        if (!empty($p['homes'])) {
            $why .= ': tied as homes of program #' . (int) $p['homes']['program'];
            $actions = array(array('id' => 'undo_homes', 'label' => 'Undo: not homes of one program', 'style' => 'undo',
                'confirm' => 'Untie the two homes from the program and put the pair back on the list?',
                'help' => 'Unties both records from program #' . (int) $p['homes']['program'] . ' and puts the pair back on the list.'));
        }
    } else {
        $why = (string) $p['reason'];
        $kept = (int) $keep === (int) $a['id'] ? $a : $b;
        $other = (int) $keep === (int) $a['id'] ? $b : $a;
        $actions = array(
            array('id' => 'merge', 'label' => 'Approve: merge into one', 'style' => 'approve',
                'help' => 'Folds ' . $other['name'] . ' into ' . $kept['name'] . ' (or the other way round, as picked below): its names, articles, lawsuits, documents and staff move to the kept record, and its page forwards there.',
                'confirm' => 'Merge these two records? The one you do not keep becomes another name of the kept one. You can undo it from the Merged tab.',
                'params' => array(array('name' => 'keep', 'label' => 'Record to keep', 'type' => 'select', 'value' => (string) $keep,
                    'options' => array(
                        (string) $a['id'] => 'Keep ' . $a['name'] . ' (#' . $a['id'] . (!empty($a['place']) ? ', ' . $a['place'] : '') . ')',
                        (string) $b['id'] => 'Keep ' . $b['name'] . ' (#' . $b['id'] . (!empty($b['place']) ? ', ' . $b['place'] : '') . ')',
                    )))),
            array('id' => 'dismiss', 'label' => 'Reject: not the same place', 'style' => 'reject',
                'help' => 'Both records stay as they are; this pair is not suggested again.'),
        );
        if ($h = kop_rinbox_fmerge_homes_action($a, $b)) $actions[] = $h;
    }
    return array(
        'key'          => (string) $p['key'],
        'title'        => $a['name'] . '  /  ' . $b['name'],
        'subtitle'     => $why . (!empty($a['place']) ? ' · ' . $a['place'] : ''),
        'text'         => $status !== 'dismissed'
            ? 'Suggested to keep: record #' . $keep . ' (the one with more on it). The other becomes another name of it, and its page forwards there.'
            : 'These two were marked as different places, so they are not suggested.',
        'compare'      => kop_rinbox_fmerge_compare($a, $b, $status !== 'dismissed' ? $keep : 0),
        'search_text'  => kop_rinbox_fmerge_side_text($a) . ' ' . kop_rinbox_fmerge_side_text($b),
        'status'       => $status,
        'status_label' => $labels[$status] ?? $status,
        'facility'     => kop_rinbox_facility($keep),
        'links'        => $links,
        'actions'      => $actions,
    );
}

/**
 * "Homes of one program": the program (the one either record is in already,
 * a new record, or another found with the finder) and each home's name.
 */
function kop_rinbox_fmerge_homes_action(array $a, array $b) {
    if (!function_exists('kop_fmerge_homes_plan')) return null;
    $plan = kop_fmerge_homes_plan($a['id'], $a['name'], $b['id'], $b['name']);
    if (!$plan) return null;
    $choices = array();
    if ($plan['program_id']) $choices[(string) $plan['program_id']] = 'Add to ' . ($plan['program_name'] !== '' ? $plan['program_name'] : 'record') . ' (#' . $plan['program_id'] . ')';
    $choices['new'] = 'A new program record';
    $choices['other'] = 'Another existing record (find it below)';
    return array('id' => 'homes', 'label' => 'Homes of one program', 'style' => 'neutral',
        'help' => 'Keeps both records separate and ties them to one program record as its homes; the program page lists both.',
        'confirm' => 'Tie both records to the program as its homes? They stay separate records, and this pair is not offered again. Undo is on the Not the same tab.',
        'params' => array(
            array('name' => 'program', 'label' => 'Program', 'type' => 'select', 'value' => $plan['program_id'] ? (string) $plan['program_id'] : 'new', 'options' => $choices),
            array('name' => 'program_name', 'label' => 'New program\'s name', 'type' => 'text', 'value' => $plan['program_id'] ? '' : $plan['program_name'], 'optional' => true),
            array('name' => 'program_id', 'label' => 'Other record', 'type' => 'facility', 'value' => '', 'optional' => true),
            array('name' => 'home_a', 'label' => 'Home name for ' . $a['name'], 'type' => 'text', 'value' => (string) $plan['homes'][(int) $a['id']], 'optional' => true),
            array('name' => 'home_b', 'label' => 'Home name for ' . $b['name'], 'type' => 'text', 'value' => (string) $plan['homes'][(int) $b['id']], 'optional' => true),
        ));
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
            'help' => 'Splits ' . $m['drop']['name'] . ' back out into its own record, with its page and everything it had.',
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
            case 'homes':
                $program = (string) ($params['program'] ?? 'new');
                if ($program === 'other') {
                    $pid = (int) ($params['program_id'] ?? 0);
                    if ($pid <= 0) throw new RuntimeException('Find the program record first.');
                } else {
                    $pid = ctype_digit($program) ? (int) $program : 0;
                }
                $name = trim((string) ($params['program_name'] ?? ''));
                $res = kop_fmerge_make_homes($a, $b, $pid, $name, array($a => (string) ($params['home_a'] ?? ''), $b => (string) ($params['home_b'] ?? '')), $login);
                return array('message' => $res['message'] . ' Undo is on the Not the same tab.');
            case 'undo_homes':
                $log = null;
                foreach (kop_fmerge_homes_log() as $e) {
                    if (empty($e['undone']) && min((int) $e['a'], (int) $e['b']) === min($a, $b) && max((int) $e['a'], (int) $e['b']) === max($a, $b)) { $log = $e['id']; break; }
                }
                if ($log === null) throw new RuntimeException('These two are not tied as homes from here.');
                return array('message' => kop_fmerge_undo_homes($log));
        }
        throw new RuntimeException('Unknown action.');
    } finally {
        kop_fmerge_flush();
    }
}
