<?php
/**
 * Review inbox source: researched years for names on the network map that
 * had none (KOP Tools > Map Years, inc/network-years.php). Accept, Reject and
 * Undo store the decision through kop_network_years_decide(); accepted years
 * show on the map and the facility pages at once.
 *
 * Keys: the candidate's id (the map name's id). The years are edited on
 * Accept (its two number boxes start from the researched years, "Still
 * operating" leaves the closing year off). Tools: accept every
 * high-confidence proposal at once (the Map Years screen's bulk button), and
 * the map rebuild of KOP Tools > Map Rebuild.
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
        'view_counts' => 'kop_rinbox_myears_view_counts',
        'tools'    => array_values(array_filter(array(
            array('id' => 'accept_high', 'label' => 'Accept every high-confidence year', 'style' => 'approve',
                'help' => 'Accepts, as proposed, every year on To review that the research rated high confidence.',
                'confirm' => 'Accept every high-confidence year as proposed? Each can be undone on the Accepted tab.'),
            function_exists('kop_rinbox_map_rebuild_tool') ? kop_rinbox_map_rebuild_tool('Accepted years show on the map at once, without a rebuild.') : null,
        ))),
        'tool'     => 'kop_rinbox_myears_tool',
        'list'     => 'kop_rinbox_myears_list',
        'get'      => function ($key) {
            $c = kop_network_years_candidates()[$key] ?? null;
            return $c ? kop_rinbox_myears_item((string) $key, $c) : null;
        },
        'act'      => 'kop_rinbox_myears_act',
    );
});

function kop_rinbox_myears_view_counts() {
    $decisions = kop_network_years_decisions();
    $counts = array('review' => 0, 'none' => 0, 'accepted' => 0, 'rejected' => 0);
    foreach (kop_network_years_candidates() as $id => $c) {
        $v = kop_rinbox_myears_view($c, $decisions[$id] ?? array());
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

/** The proposed years of a candidate: [start, end, still operating]. */
function kop_rinbox_myears_proposed(array $c) {
    $still = !empty($c['stillOperating']);
    return array(!empty($c['start']) ? (int) $c['start'] : 0, !empty($c['end']) && !$still ? (int) $c['end'] : 0, $still);
}

function kop_rinbox_myears_tool($id, array $params) {
    if ($id === 'map_rebuild') return kop_rinbox_map_rebuild_run();
    if ($id !== 'accept_high') throw new RuntimeException('Unknown tool.');
    $decisions = kop_network_years_decisions();
    $items = array();
    foreach (kop_network_years_candidates() as $cid => $c) {
        if (kop_rinbox_myears_view($c, $decisions[$cid] ?? array()) !== 'review' || ($c['confidence'] ?? '') !== 'high') continue;
        list($start, $end) = kop_rinbox_myears_proposed($c);
        $items[] = array('id' => (string) $cid, 'decision' => 'accept', 'start' => $start, 'end' => $end);
    }
    if (!$items) return array('message' => 'No high-confidence years are waiting.');
    $done = 0;
    $failed = 0;
    foreach (kop_network_years_decide($items, kop_rinbox_reviewer()) as $r) {
        if (!empty($r['error'])) $failed++;
        else $done++;
    }
    return array('message' => 'Accepted ' . $done . ' year' . ($done === 1 ? '' : 's') . ($failed ? '; ' . $failed . ' could not be (open them to fix the years)' : '') . '. They are on the map now.');
}

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
    list($start, $end, $still) = kop_rinbox_myears_proposed($c);
    $proposed = kop_network_years_format($start, $end);
    $text = $proposed !== ''
        ? 'Proposed: ' . str_replace('-', ' to ', $proposed) . ($still ? ' (still operating)' : '') . '. Check the quotes below; some were read through a summary of the page, so open the source to confirm the wording.'
        : 'No year was found for this name. If you know the years, type them in and save them; otherwise leave it off the timeline.';
    $details = array();
    if ($proposed !== '') $details[] = array('label' => 'Confidence', 'value' => (string) ($c['confidence'] ?? 'low'));
    if (!empty($c['note'])) $details[] = array('label' => 'Note', 'value' => (string) $c['note']);
    if ($view === 'accepted') $details[] = array('label' => 'On the map', 'value' => str_replace('-', ' to ', (string) ($d['years'] ?? '')) . (!empty($d['by']) ? ' (accepted by ' . $d['by'] . ')' : ''));
    $details = array_merge($details, kop_rinbox_quote_details((array) ($c['sources'] ?? array())));
    $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
    $links = array(array('label' => 'See it on the network map', 'url' => $map . '#open=' . rawurlencode($id)));
    $labels = array('review' => 'To review', 'none' => 'No year found', 'accepted' => 'Accepted', 'rejected' => 'Rejected');
    if ($view === 'accepted' || $view === 'rejected') {
        $nm = (string) ($c['name'] ?? $id);
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'accepted'
                ? 'Takes these years off ' . $nm . ' on the network map; the name waits for review again.'
                : 'Nothing on the site changes; the name waits for review again.'));
    } else {
        $found = $view === 'review';
        $nm = (string) ($c['name'] ?? $id);
        $actions = array(
            array('id' => 'accept', 'label' => $found ? 'Accept these years' : 'Save these years', 'style' => 'approve',
                'help' => 'Shows ' . $nm . ' with the years below on the network map timeline, live at once.',
                'params' => array(
                array('name' => 'start', 'label' => 'Opened (year)', 'type' => 'number', 'value' => $start ? (string) $start : ''),
                array('name' => 'end', 'label' => 'Closed (year; empty if unknown)', 'type' => 'number', 'value' => $end ? (string) $end : '', 'optional' => true),
                array('name' => 'still', 'label' => 'Still operating', 'type' => 'checkbox', 'value' => $still, 'optional' => true),
            )),
            array('id' => 'reject', 'label' => $found ? 'Reject these years' : 'Leave off the timeline', 'style' => 'reject',
                'help' => 'The map keeps ' . $nm . ' without these years; the name moves to Rejected.'),
        );
    }
    $fid = kop_rinbox_myears_facility_ids()[$id] ?? 0;
    return array(
        'key'          => $id,
        'title'        => (string) ($c['name'] ?? $id),
        'subtitle'     => implode(' · ', array_filter(array($kinds[$c['kind'] ?? ''] ?? '', (string) ($c['place'] ?? ''),
            'confidence: ' . ($c['confidence'] ?? 'low')))),
        'text'         => $text,
        'details'      => $details,
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
            // "Still operating" has no closing year.
            $still = !empty($params['still']) && $params['still'] !== '0';
            $item += array('decision' => 'accept', 'start' => (int) ($params['start'] ?? 0), 'end' => $still ? 0 : (int) ($params['end'] ?? 0));
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
