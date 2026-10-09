<?php
/**
 * Review inbox source: facility records and state inspection rows whose names
 * differ but look like one place (KOP Tools > Inspection Links,
 * inc/inspection-links.php). "Same facility", "Not this one" and Remove (the
 * Undo of a link) go through kop_inspection_links_save().
 *
 * The suggestions are computed state by state with
 * kop_inspection_link_suggestions(), which reads every record and licensing
 * row of each state: the list of every state's pairs is cached an hour here
 * (transient kop_rinbox_inspection_links), and pairs decided since are left
 * out as it is read. Removing a link clears it, so the pair can come back.
 *
 * As on the Inspection Links screen: a State filter, pairs in the same town
 * start ticked (select them all and press "Same facility"), and a tool links
 * every same-town pair of a state at once. "Not this one" can be taken back
 * from its own tab (kop_inspection_links_save()'s 'unreject').
 *
 * A program with homes under it (inc/program-homes.php) comes as one
 * record: "Same program" links the entry to the program as a unit; an entry
 * first suggested for one of its homes can go to that home instead.
 *
 * Keys: "<facility id>-<inspection row id>", on every tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('inspection-links', function () {
    if (!function_exists('kop_inspection_links_save')) return null;
    return array(
        'label'    => 'Inspection links',
        'group'    => 'Suggestions to check',
        'help'     => 'A facility record and a state licensing entry whose names differ but may be the same place (states often license each building under its own name). "Same facility" puts that entry\'s inspection reports on the facility\'s page at once; Remove on the Linked tab takes them off again.',
        'views'    => array('suggested' => 'Suggested', 'linked' => 'Linked', 'rejected' => 'Not this one'),
        'tool_url' => admin_url('admin.php?page=kop-inspection-links'),
        'count'    => function () {
            return count(kop_rinbox_ilinks_pairs());
        },
        'view_counts' => function () {
            return array('suggested' => count(kop_rinbox_ilinks_pairs()), 'linked' => count(kop_rinbox_ilinks_decided('links')),
                'rejected' => count(kop_rinbox_ilinks_decided('rejected')));
        },
        'filters'  => array(array('name' => 'state', 'label' => 'State', 'options' => kop_rinbox_ilinks_state_options())),
        'tools'    => array(array('id' => 'link_same_town', 'label' => 'Link every same-town pair', 'style' => 'approve',
            'help' => 'Links every suggested pair whose licensing entry is in the facility record\'s town (the ones the Inspection Links screen ticks in advance). Each can be removed on the Linked tab.',
            'confirm' => 'Link every suggested pair in the same town? Each can be removed on the Linked tab.',
            'params' => array(array('name' => 'state', 'label' => 'In', 'type' => 'select', 'value' => 'all',
                'options' => array('all' => 'every state') + kop_rinbox_ilinks_state_options())))),
        'tool'     => 'kop_rinbox_ilinks_tool',
        'list'     => 'kop_rinbox_ilinks_list',
        'get'      => 'kop_rinbox_ilinks_get',
        'act'      => 'kop_rinbox_ilinks_act',
    );
});

/** Every state's suggested pairs, computed once an hour. */
function kop_rinbox_ilinks_all($fresh = false) {
    $cached = $fresh ? false : get_transient('kop_rinbox_inspection_links');
    if (is_array($cached)) return $cached;
    global $wpdb;
    $stored = kop_inspection_links_get();
    $pairs = array();
    foreach ((array) $wpdb->get_col('SELECT DISTINCT state FROM inspection_facilities ORDER BY state') as $state) {
        $state = (string) $state;
        if ($state === '') continue;
        foreach (kop_inspection_link_suggestions(kop_inspection_link_records($state), kop_inspection_link_rows($state), $stored) as $s) {
            $rec = $s['record'];
            foreach ($s['candidates'] as $c) {
                $row = $c['row'];
                $key = (int) $rec['id'] . '-' . (int) $row['id'];
                $pairs[$key] = array(
                    'key'       => $key,
                    'state'     => $state,
                    'record'    => array('id' => (int) $rec['id'], 'name' => (string) $rec['name'], 'names' => array_values((array) $rec['names']),
                        'city' => (string) $rec['city'], 'status' => (string) $rec['status'], 'homes' => array_values((array) ($rec['homes'] ?? array()))),
                    'home'      => $c['home'] ?? null,
                    'row'       => array('id' => (int) $row['id'], 'facility_name' => (string) $row['facility_name'],
                        'full_address' => (string) $row['full_address'], 'program_name' => (string) ($row['program_name'] ?? ''), 'reports' => (int) $row['reports']),
                    'same_town' => (bool) $c['same_town'],
                );
            }
        }
    }
    set_transient('kop_rinbox_inspection_links', $pairs, HOUR_IN_SECONDS);
    return $pairs;
}

/** The suggested pairs nobody has decided yet: same town first, then by state and name. */
function kop_rinbox_ilinks_pairs() {
    $stored = kop_inspection_links_get();
    $out = array();
    foreach (kop_rinbox_ilinks_all() as $key => $p) {
        $fid = $p['record']['id'];
        $rid = $p['row']['id'];
        if (in_array($rid, array_map('intval', (array) ($stored['links'][$fid] ?? array())), true)) continue;
        if (in_array($rid, array_map('intval', (array) ($stored['rejected'][$fid] ?? array())), true)) continue;
        // Given to the home it was suggested for.
        $hid = (int) ($p['home']['id'] ?? 0);
        if ($hid && in_array($rid, array_map('intval', (array) ($stored['links'][$hid] ?? array())), true)) continue;
        $out[$key] = $p;
    }
    uasort($out, function ($a, $b) {
        return ($b['same_town'] <=> $a['same_town']) ?: strcmp($a['state'], $b['state']) ?: strcasecmp($a['record']['name'], $b['record']['name']);
    });
    return $out;
}

/** State code => "Name (n waiting)", every state with suggestions or links. */
function kop_rinbox_ilinks_state_options() {
    $n = array();
    foreach (kop_rinbox_ilinks_pairs() as $p) $n[$p['state']] = ($n[$p['state']] ?? 0) + 1;
    foreach (kop_rinbox_ilinks_decided('links') as $p) $n += array($p['state'] => 0);
    ksort($n);
    $out = array();
    foreach ($n as $code => $count) {
        $name = function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($code) : '';
        $out[(string) $code] = ($name !== '' ? $name : $code) . ' (' . $count . ' waiting)';
    }
    return $out;
}

function kop_rinbox_ilinks_tool($id, array $params) {
    if ($id !== 'link_same_town') throw new RuntimeException('Unknown tool.');
    $state = strtoupper((string) ($params['state'] ?? 'all'));
    $decide = array();
    foreach (kop_rinbox_ilinks_pairs() as $key => $p) {
        if ($p['same_town'] && ($state === 'ALL' || $state === '' || $p['state'] === $state)) $decide[$key] = 'link';
    }
    if (!$decide) return array('message' => 'No same-town pairs are waiting' . ($state !== 'ALL' && $state !== '' ? ' in ' . $state : '') . '.');
    kop_inspection_links_save(array('decide' => $decide));
    $n = count($decide);
    return array('message' => 'Linked ' . $n . ' pair' . ($n === 1 ? '' : 's') . '. The facility pages show those reports now; each can be removed on the Linked tab.');
}

/** Approved links as pairs, with the record and the licensing row read fresh. */
function kop_rinbox_ilinks_linked() {
    return kop_rinbox_ilinks_decided('links');
}

/** Decided pairs ('links' or 'rejected'), with the record and the licensing row read fresh. */
function kop_rinbox_ilinks_decided($which) {
    global $wpdb;
    $stored = kop_inspection_links_get();
    $want = array();
    foreach ((array) ($stored[$which] ?? array()) as $fid => $ids) foreach ((array) $ids as $rid) $want[] = array((int) $fid, (int) $rid);
    if (!$want) return array();
    $fids = implode(',', array_unique(array_map(function ($w) { return $w[0]; }, $want)));
    $rids = implode(',', array_unique(array_map(function ($w) { return $w[1]; }, $want)));
    $recs = array();
    foreach ((array) $wpdb->get_results("SELECT id, name, city, status FROM facilities_v2 WHERE id IN ($fids)", ARRAY_A) as $r) $recs[(int) $r['id']] = $r;
    $rows = array();
    foreach ((array) $wpdb->get_results("SELECT id, state, facility_name, full_address, program_name FROM inspection_facilities WHERE id IN ($rids)", ARRAY_A) as $r) $rows[(int) $r['id']] = $r;
    $out = array();
    foreach ($want as $w) {
        list($fid, $rid) = $w;
        if (!isset($rows[$rid])) continue;
        $rec = $recs[$fid] ?? array('name' => 'Record #' . $fid, 'city' => '', 'status' => '');
        $out[$fid . '-' . $rid] = array(
            'key'       => $fid . '-' . $rid,
            'state'     => (string) $rows[$rid]['state'],
            'record'    => array('id' => $fid, 'name' => (string) $rec['name'], 'names' => array(), 'city' => (string) $rec['city'], 'status' => (string) $rec['status'],
                'homes' => kop_rinbox_ilinks_homes($fid)),
            'row'       => array('id' => $rid, 'facility_name' => (string) $rows[$rid]['facility_name'], 'full_address' => (string) $rows[$rid]['full_address'],
                'program_name' => (string) $rows[$rid]['program_name'], 'reports' => null),
            'same_town' => false,
            'linked'    => $which === 'links',
            'rejected'  => $which === 'rejected',
        );
    }
    uasort($out, function ($a, $b) { return strcmp($a['state'], $b['state']) ?: strcasecmp($a['record']['name'], $b['record']['name']); });
    return $out;
}

/** [{id, name}] of a program record's homes (empty when it is not a program). */
function kop_rinbox_ilinks_homes($fid) {
    if (!function_exists('kop_program_homes_homes_of') || !function_exists('kop_program_homes_home_rows')) return array();
    $ids = kop_program_homes_homes_of($fid);
    if (!$ids) return array();
    $out = array();
    foreach (kop_program_homes_home_rows($ids) as $h) $out[] = array('id' => (int) $h['id'], 'name' => (string) $h['name']);
    return $out;
}

function kop_rinbox_ilinks_list(array $q) {
    if ($q['view'] === 'linked') $pairs = kop_rinbox_ilinks_decided('links');
    elseif ($q['view'] === 'rejected') $pairs = kop_rinbox_ilinks_decided('rejected');
    else $pairs = kop_rinbox_ilinks_pairs();
    $state = (string) ($q['filters']['state'] ?? '');
    if ($state !== '') $pairs = array_filter($pairs, function ($p) use ($state) { return $p['state'] === $state; });
    if ($q['search'] !== '') {
        $needle = mb_strtolower($q['search']);
        $pairs = array_filter($pairs, function ($p) use ($needle) {
            $hay = mb_strtolower($p['state'] . ' ' . $p['record']['name'] . ' ' . $p['record']['city'] . ' ' . $p['row']['facility_name'] . ' ' . $p['row']['full_address']);
            return mb_strpos($hay, $needle) !== false;
        });
    }
    $items = array();
    foreach (array_slice($pairs, (int) $q['offset'], (int) $q['limit']) as $p) $items[] = kop_rinbox_ilinks_item($p);
    return array('items' => $items, 'total' => count($pairs));
}

function kop_rinbox_ilinks_get($key) {
    foreach (array('links', 'rejected') as $which) {
        $decided = kop_rinbox_ilinks_decided($which);
        if (isset($decided[$key])) return kop_rinbox_ilinks_item($decided[$key]);
    }
    $pairs = kop_rinbox_ilinks_pairs();
    return isset($pairs[$key]) ? kop_rinbox_ilinks_item($pairs[$key]) : null;
}

function kop_rinbox_ilinks_item(array $p) {
    $rec = $p['record'];
    $row = $p['row'];
    $linked = !empty($p['linked']);
    $rejected = !empty($p['rejected']);
    $others = array_values(array_diff($rec['names'], array($rec['name'])));
    $homes = (array) ($rec['homes'] ?? array());
    $home = $p['home'] ?? null;
    if ($homes) $others[] = 'Program with ' . count($homes) . ' home' . (count($homes) === 1 ? '' : 's') . ': ' . implode(', ', array_column($homes, 'name'));
    $program = $row['program_name'] !== '' && $row['program_name'] !== $row['facility_name'] ? $row['program_name'] : '';
    $compare = kop_rinbox_compare_rows(
        array('Facility record (#' . $rec['id'] . ')', 'State licensing entry (' . $p['state'] . ' #' . $row['id'] . ')'),
        array(
            'Name'               => array($rec['name'], $row['facility_name']),
            'Other names'        => array($others, $program !== '' ? 'Program: ' . $program : ''),
            'Town or address'    => array($rec['city'], $row['full_address']),
            'Status'             => array($rec['status'], ''),
            'Inspection reports' => array('', $row['reports'] !== null ? (string) (int) $row['reports'] : ''),
        )
    );
    // Only the name and the place are compared; the other rows say what one side has.
    foreach ($compare['rows'] as &$r) {
        if ($r['label'] === 'Town or address') $r['differs'] = $rec['city'] === '' || stripos($row['full_address'], $rec['city']) === false;
        elseif ($r['label'] !== 'Name') $r['differs'] = false;
    }
    unset($r);
    $links = array();
    $state_name = function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($p['state']) : $p['state'];
    $tracker = function_exists('kop_state_inspection_page_map') ? kop_state_inspection_page_map() : array();
    if (isset($tracker[$state_name])) $links[] = array('label' => $state_name . ' inspection reports', 'url' => home_url('/' . $tracker[$state_name] . '/'));
    if ($linked) {
        $actions = array(array('id' => 'unlink', 'label' => 'Remove the link', 'style' => 'undo',
            'help' => 'The facility page of ' . $rec['name'] . ' stops showing the inspection reports of ' . $row['facility_name'] . '.'));
        $text = 'Linked: the facility\'s page shows this entry\'s inspection reports.';
    } elseif ($rejected) {
        $actions = array(array('id' => 'unreject', 'label' => 'Suggest it again', 'style' => 'undo',
            'help' => 'Nothing on the site changes; the pair goes back to the suggestions.'));
        $text = 'Marked not the same place, so it is not suggested.';
    } else {
        $actions = array(
            array('id' => 'link', 'label' => $homes ? 'Approve: same program' : 'Approve: same facility', 'style' => 'approve',
                'help' => $homes
                    ? 'The program page of ' . $rec['name'] . ' shows the ' . $p['state'] . ' inspection reports filed under ' . $row['facility_name'] . ' as the program\'s own, beside its homes\' reports.'
                    : 'The facility page of ' . $rec['name'] . ' shows the ' . $p['state'] . ' inspection reports filed under ' . $row['facility_name'] . '.'),
        );
        if ($home) {
            $actions[] = array('id' => 'link_home', 'label' => 'Approve: only ' . $home['name'], 'style' => 'approve',
                'help' => 'The page of the home ' . $home['name'] . ' shows these reports; the program page shows them with its homes\' reports.');
        }
        $actions[] = array('id' => 'reject', 'label' => 'Reject: not this one', 'style' => 'reject',
            'help' => 'Nothing on the site changes; this pair is not suggested again.');
        $text = ($homes ? 'A program with homes under it, taken as one unit' . ($home ? ' (suggested from its home ' . $home['name'] . ')' : '') . '. ' : '')
            . ($p['same_town']
                ? 'The licensing entry is in ' . ($homes ? 'the town of the program or one of its homes' : 'the record\'s town') . ', so this one starts ticked. Leave a pair for later by doing nothing.'
                : 'Every distinguishing word of the ' . ($homes ? 'program\'s or a home\'s' : 'record\'s') . ' name is in the entry\'s name, but the town does not match (or is not known). Leave a pair for later by doing nothing.');
    }
    return array(
        'key'          => $p['key'],
        'title'        => $rec['name'] . '  /  ' . $row['facility_name'],
        'subtitle'     => $state_name . ($linked ? ' · linked' : ($rejected ? ' · not this one' : ($p['same_town'] ? ' · same town' : ' · different or unknown town'))),
        'text'         => $text,
        'compare'      => $compare,
        'selected'     => !$linked && !$rejected && $p['same_town'],
        'status'       => $linked ? 'linked' : ($rejected ? 'rejected' : 'suggested'),
        'status_label' => $linked ? 'Linked' : ($rejected ? 'Not this one' : ($p['same_town'] ? 'Suggested, same town' : 'Suggested')),
        'facility'     => kop_rinbox_facility($rec['id']),
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_ilinks_act($key, $action, array $params) {
    if (!preg_match('/^(\d+)-(\d+)$/', $key)) throw new RuntimeException('That is not a record and a licensing entry.');
    switch ($action) {
        case 'link':
            kop_inspection_links_save(array('decide' => array($key => 'link')));
            return array('message' => 'Linked. The facility\'s page shows that entry\'s inspection reports now. Remove is on the Linked tab.');
        case 'link_home':
            $p = kop_rinbox_ilinks_pairs()[$key] ?? null;
            $hid = (int) ($p['home']['id'] ?? 0);
            if (!$hid) throw new RuntimeException('That entry was not suggested for one of the program\'s homes.');
            kop_inspection_links_save(array('decide' => array($key => 'home:' . $hid)));
            delete_transient('kop_rinbox_inspection_links');
            return array('message' => 'Linked to ' . $p['home']['name'] . '. Its page and the program\'s page show that entry\'s reports now. Remove is on the Linked tab.');
        case 'reject':
            kop_inspection_links_save(array('decide' => array($key => 'reject')));
            return array('message' => 'Marked not the same. This pair will not be suggested again; "Suggest it again" is on the Not this one tab.');
        case 'unreject':
            kop_inspection_links_save(array('unreject' => array($key)));
            delete_transient('kop_rinbox_inspection_links');
            return array('message' => 'Back in the suggestions.');
        case 'unlink':
            kop_inspection_links_save(array('unlink' => array($key)));
            // The pair may be suggested again: the list is worked out afresh.
            delete_transient('kop_rinbox_inspection_links');
            return array('message' => 'Link removed. The facility\'s page no longer shows that entry\'s reports.');
    }
    throw new RuntimeException('Unknown action.');
}
