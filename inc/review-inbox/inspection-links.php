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
 * Keys: "<facility id>-<inspection row id>", on both tabs.
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
        'views'    => array('suggested' => 'Suggested', 'linked' => 'Linked'),
        'tool_url' => admin_url('admin.php?page=kop-inspection-links'),
        'count'    => function () {
            return count(kop_rinbox_ilinks_pairs());
        },
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
                        'city' => (string) $rec['city'], 'status' => (string) $rec['status']),
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
        $out[$key] = $p;
    }
    uasort($out, function ($a, $b) {
        return ($b['same_town'] <=> $a['same_town']) ?: strcmp($a['state'], $b['state']) ?: strcasecmp($a['record']['name'], $b['record']['name']);
    });
    return $out;
}

/** Approved links as pairs, with the record and the licensing row read fresh. */
function kop_rinbox_ilinks_linked() {
    global $wpdb;
    $stored = kop_inspection_links_get();
    $want = array();
    foreach ($stored['links'] as $fid => $ids) foreach ((array) $ids as $rid) $want[] = array((int) $fid, (int) $rid);
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
            'record'    => array('id' => $fid, 'name' => (string) $rec['name'], 'names' => array(), 'city' => (string) $rec['city'], 'status' => (string) $rec['status']),
            'row'       => array('id' => $rid, 'facility_name' => (string) $rows[$rid]['facility_name'], 'full_address' => (string) $rows[$rid]['full_address'],
                'program_name' => (string) $rows[$rid]['program_name'], 'reports' => null),
            'same_town' => false,
            'linked'    => true,
        );
    }
    uasort($out, function ($a, $b) { return strcmp($a['state'], $b['state']) ?: strcasecmp($a['record']['name'], $b['record']['name']); });
    return $out;
}

function kop_rinbox_ilinks_list(array $q) {
    $pairs = $q['view'] === 'linked' ? kop_rinbox_ilinks_linked() : kop_rinbox_ilinks_pairs();
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
    $linked = kop_rinbox_ilinks_linked();
    if (isset($linked[$key])) return kop_rinbox_ilinks_item($linked[$key]);
    $pairs = kop_rinbox_ilinks_pairs();
    return isset($pairs[$key]) ? kop_rinbox_ilinks_item($pairs[$key]) : null;
}

function kop_rinbox_ilinks_item(array $p) {
    $rec = $p['record'];
    $row = $p['row'];
    $linked = !empty($p['linked']);
    $text = 'Facility record: ' . $rec['name'] . ' (#' . $rec['id'] . ')' . ($rec['city'] !== '' ? ', ' . $rec['city'] : '') . ($rec['status'] !== '' ? ' · ' . $rec['status'] : '');
    $others = array_diff($rec['names'], array($rec['name']));
    if ($others) $text .= "\n  Also known as: " . implode('; ', $others);
    $text .= "\n\nState licensing entry: " . $row['facility_name'] . ' (' . $p['state'] . ' row #' . $row['id'] . ')'
        . ($row['full_address'] !== '' ? "\n  " . $row['full_address'] : '')
        . ($row['program_name'] !== '' && $row['program_name'] !== $row['facility_name'] ? "\n  Program: " . $row['program_name'] : '')
        . ($row['reports'] !== null ? "\n  " . $row['reports'] . ' inspection report' . ($row['reports'] === 1 ? '' : 's') : '');
    $links = array();
    $state_name = function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($p['state']) : $p['state'];
    $tracker = function_exists('kop_state_inspection_page_map') ? kop_state_inspection_page_map() : array();
    if (isset($tracker[$state_name])) $links[] = array('label' => $state_name . ' inspection reports', 'url' => home_url('/' . $tracker[$state_name] . '/'));
    return array(
        'key'          => $p['key'],
        'title'        => $rec['name'] . '  /  ' . $row['facility_name'],
        'subtitle'     => $p['state'] . ($linked ? ' · linked' : ($p['same_town'] ? ' · same town' : ' · different or unknown town')),
        'text'         => $text,
        'status'       => $linked ? 'linked' : 'suggested',
        'status_label' => $linked ? 'Linked' : ($p['same_town'] ? 'Suggested, same town' : 'Suggested'),
        'facility'     => kop_rinbox_facility($rec['id']),
        'links'        => $links,
        'actions'      => $linked
            ? array(array('id' => 'unlink', 'label' => 'Remove the link', 'style' => 'undo'))
            : array(
                array('id' => 'link', 'label' => 'Same facility', 'style' => 'approve'),
                array('id' => 'reject', 'label' => 'Not this one', 'style' => 'reject',
                    'confirm' => 'Never suggest this pair again? (This cannot be undone here.)'),
            ),
    );
}

function kop_rinbox_ilinks_act($key, $action, array $params) {
    if (!preg_match('/^(\d+)-(\d+)$/', $key)) throw new RuntimeException('That is not a record and a licensing entry.');
    switch ($action) {
        case 'link':
            kop_inspection_links_save(array('decide' => array($key => 'link')));
            return array('message' => 'Linked. The facility\'s page shows that entry\'s inspection reports now. Remove is on the Linked tab.');
        case 'reject':
            kop_inspection_links_save(array('decide' => array($key => 'reject')));
            return array('message' => 'Marked not the same. This pair will not be suggested again.');
        case 'unlink':
            kop_inspection_links_save(array('unlink' => array($key)));
            // The pair may be suggested again: the list is worked out afresh.
            delete_transient('kop_rinbox_inspection_links');
            return array('message' => 'Link removed. The facility\'s page no longer shows that entry\'s reports.');
    }
    throw new RuntimeException('Unknown action.');
}
