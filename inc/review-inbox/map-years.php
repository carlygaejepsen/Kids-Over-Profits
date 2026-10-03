<?php
/**
 * Review inbox source: researched years for names on the network map that
 * had none (KOP Tools > Map Years, inc/network-years.php). Accept, Reject and
 * Undo store the decision through kop_network_years_decide(); accepted years
 * show on the map and the facility pages at once.
 *
 * Keys: the candidate's id (the map name's id). The years are edited on
 * Accept (its two number boxes start from the researched years).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('map-years', function () {
    if (!function_exists('kop_network_years_decide')) return null;
    return array(
        'label'    => 'Map years',
        'group'    => 'Suggestions to check',
        'help'     => 'Opening and closing years found for places and companies on the network map, each with the sentence and link it came from. Accept (change the years first if needed) and the map\'s timeline shows them at once; Reject if the source is wrong. Undo on the Accepted and Rejected tabs.',
        'views'    => array('review' => 'To review', 'none' => 'No year found', 'accepted' => 'Accepted', 'rejected' => 'Rejected'),
        'tool_url' => admin_url('admin.php?page=kop-network-years'),
        'count'    => function () {
            $decisions = kop_network_years_decisions();
            $n = 0;
            foreach (kop_network_years_candidates() as $id => $c) {
                if (!isset($decisions[$id]) && (!empty($c['start']) || !empty($c['end']))) $n++;
            }
            return $n;
        },
        'list'     => 'kop_rinbox_myears_list',
        'get'      => function ($key) {
            $c = kop_network_years_candidates()[$key] ?? null;
            return $c ? kop_rinbox_myears_item((string) $key, $c) : null;
        },
        'act'      => 'kop_rinbox_myears_act',
    );
});

/** Which tab a candidate is on. */
function kop_rinbox_myears_view(array $c, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    if ($decision !== '') return $decision;
    return !empty($c['start']) || !empty($c['end']) ? 'review' : 'none';
}

function kop_rinbox_myears_list(array $q) {
    $decisions = kop_network_years_decisions();
    $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
    $rows = array();
    foreach (kop_network_years_candidates() as $id => $c) {
        if (kop_rinbox_myears_view($c, $decisions[$id] ?? array()) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower(($c['name'] ?? '') . ' ' . ($c['place'] ?? '') . ' ' . ($c['note'] ?? ''));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $id] = $c;
    }
    uksort($rows, function ($x, $y) use ($rows, $rank) {
        return ($rank[$rows[$x]['confidence'] ?? 'low'] ?? 3) <=> ($rank[$rows[$y]['confidence'] ?? 'low'] ?? 3)
            ?: strcasecmp((string) ($rows[$x]['name'] ?? $x), (string) ($rows[$y]['name'] ?? $y));
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $id => $c) $items[] = kop_rinbox_myears_item((string) $id, $c);
    return array('items' => $items, 'total' => count($rows));
}

/** Map name id => facility record id, from graph.json. */
function kop_rinbox_myears_facility_ids() {
    static $ids = null;
    if ($ids === null) {
        $ids = array();
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        foreach ((array) ($graph['nodes'] ?? array()) as $n) {
            if (!empty($n['facilityId'])) $ids[(string) $n['id']] = (int) $n['facilityId'];
        }
    }
    return $ids;
}

function kop_rinbox_myears_item($id, array $c) {
    $d = kop_network_years_decisions()[$id] ?? array();
    $view = kop_rinbox_myears_view($c, $d);
    $kinds = array('facility' => 'Program', 'parent' => 'Company', 'association' => 'Trade group',
        'church' => 'Church', 'government' => 'Government body', 'other' => 'Other');
    $still = !empty($c['stillOperating']);
    $start = !empty($c['start']) ? (int) $c['start'] : 0;
    $end = !empty($c['end']) && !$still ? (int) $c['end'] : 0;
    $proposed = kop_network_years_format($start, $end);
    $text = $proposed !== '' ? 'Proposed: ' . $proposed . ($still ? ' (still operating)' : '') . '.' : 'No year was found for this name.';
    if (!empty($c['note'])) $text .= "\n\n" . $c['note'];
    $links = array();
    foreach ((array) ($c['sources'] ?? array()) as $s) {
        if (empty($s['url'])) continue;
        if (!empty($s['quote'])) $text .= "\n\n\"" . $s['quote'] . '"' . "\n(" . $s['url'] . ')';
        $host = (string) wp_parse_url($s['url'], PHP_URL_HOST);
        $links[] = array('label' => $host !== '' ? preg_replace('/^www\./', '', $host) : 'Source', 'url' => $s['url']);
    }
    $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
    $links[] = array('label' => 'On the network map', 'url' => $map . '#open=' . rawurlencode($id));
    $labels = array('review' => 'To review', 'none' => 'No year found', 'accepted' => 'Accepted', 'rejected' => 'Rejected');
    if ($view === 'accepted' || $view === 'rejected') {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo'));
    } else {
        $actions = array(
            array('id' => 'accept', 'label' => 'Accept these years', 'style' => 'approve', 'params' => array(
                array('name' => 'start', 'label' => 'Opened (year)', 'type' => 'number', 'value' => $start ? (string) $start : ''),
                array('name' => 'end', 'label' => 'Closed (year; empty if still open or unknown)', 'type' => 'number', 'value' => $end ? (string) $end : ''),
            )),
            array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject'),
        );
    }
    $fid = kop_rinbox_myears_facility_ids()[$id] ?? 0;
    return array(
        'key'          => $id,
        'title'        => (string) ($c['name'] ?? $id),
        'subtitle'     => implode(' · ', array_filter(array($kinds[$c['kind'] ?? ''] ?? '', (string) ($c['place'] ?? ''),
            'confidence: ' . ($c['confidence'] ?? 'low')))),
        'text'         => $text,
        'status'       => $view,
        'status_label' => $view === 'accepted' ? 'Accepted: ' . ($d['years'] ?? '') : $labels[$view],
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => $fid ? kop_rinbox_facility($fid) : null,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_myears_act($key, $action, array $params) {
    $c = kop_network_years_candidates()[$key] ?? null;
    if (!$c) throw new RuntimeException('That name is not in the researched list any more.');
    $name = (string) ($c['name'] ?? $key);
    $item = array('id' => $key);
    switch ($action) {
        case 'accept':
            $item += array('decision' => 'accept', 'start' => (int) ($params['start'] ?? 0), 'end' => (int) ($params['end'] ?? 0));
            break;
        case 'reject':
            $item['decision'] = 'reject';
            break;
        case 'undo':
            $item['decision'] = 'undo';
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
    $out = kop_network_years_decide(array($item), kop_rinbox_reviewer());
    $r = $out[$key] ?? null;
    if (!$r) throw new RuntimeException('Not saved.');
    if (!empty($r['error'])) throw new RuntimeException($r['error']);
    if ($action === 'accept') return array('message' => 'Accepted. ' . $name . ' shows ' . $r['years'] . ' on the map now. Undo is on the Accepted tab.');
    if ($action === 'reject') return array('message' => 'Rejected. The map keeps ' . $name . ' without these years.');
    return array('message' => 'Undone. ' . $name . ' is waiting for review again.');
}
