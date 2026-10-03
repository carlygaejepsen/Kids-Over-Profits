<?php
/**
 * Review inbox source: programs that changed their name, drawn on the network
 * map as two names joined by a line (KOP Tools > Map Renames,
 * inc/network-renames.php). Save the rename year (and, if the line points
 * the wrong way, swap it), "Not a rename" and Undo all store the decision
 * through kop_network_renames_decide(); the map shows it at once.
 *
 * Keys: "earlier>later", the line as the board drew it. The year and the
 * direction are set on Save (its params start from the research or the
 * names' own years).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('map-renames', function () {
    if (!function_exists('kop_network_renames_decide')) return null;
    return array(
        'label'    => 'Map renames',
        'group'    => 'Suggestions to check',
        'help'     => 'Programs that changed their name. Each name belongs to its own years, so give the year of the rename: the earlier name then ends that year and the later one starts it on the map\'s timeline. If the arrow points the wrong way, choose "the other way round". Undo on the Saved and Not a rename tabs.',
        'views'    => array('review' => 'To review', 'saved' => 'Saved', 'skipped' => 'Not a rename'),
        'tool_url' => admin_url('admin.php?page=kop-network-renames'),
        'count'    => function () {
            $decisions = kop_network_renames_decisions();
            $n = 0;
            foreach (kop_rinbox_mren_rows() as $r) if (!isset($decisions[$r['key']])) $n++;
            return $n;
        },
        'list'     => 'kop_rinbox_mren_list',
        'get'      => function ($key) {
            foreach (kop_rinbox_mren_rows() as $r) if ($r['key'] === $key) return kop_rinbox_mren_item($r);
            return null;
        },
        'act'      => 'kop_rinbox_mren_act',
    );
});

/** Every rebrand line, with the names' years before any rename (as the Map Renames screen reads them). */
function kop_rinbox_mren_rows() {
    $graph = kop_network_map_graph();
    return kop_network_renames_list($graph ?: array(), kop_network_renames_base_years($graph ?: array()));
}

function kop_rinbox_mren_list(array $q) {
    $decisions = kop_network_renames_decisions();
    $research = kop_network_renames_candidates();
    $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
    $rows = array();
    foreach (kop_rinbox_mren_rows() as $r) {
        $view = (string) ($decisions[$r['key']]['decision'] ?? '') ?: 'review';
        if ($view !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower($r['earlier']['name'] . ' ' . $r['later']['name'] . ' ' . implode(' ', $r['earlier']['operators']) . ' ' . implode(' ', $r['later']['operators']));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[] = $r;
    }
    // Sourced years first (strongest first), then by name, as the Map Renames screen.
    $score = function ($r) use ($research, $rank) {
        $c = $research[$r['key']] ?? null;
        return $c && !empty($c['year']) ? ($rank[$c['confidence'] ?? 'low'] ?? 2) : 3;
    };
    usort($rows, function ($x, $y) use ($score) {
        return ($score($x) <=> $score($y)) ?: strcasecmp($x['earlier']['name'], $y['earlier']['name']);
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit']) as $r) $items[] = kop_rinbox_mren_item($r);
    return array('items' => $items, 'total' => count($rows));
}

function kop_rinbox_mren_side_text(array $s, $label) {
    $lines = array($label . ': ' . $s['name'] . ($s['years'] !== '' ? ' (' . $s['years'] . ')' : ' (no years on the map)'));
    if ($s['operators']) $lines[] = '  Run by: ' . implode('; ', $s['operators']);
    if ($s['status'] !== '') $lines[] = '  Status on the map: ' . $s['status'];
    return implode("\n", $lines);
}

function kop_rinbox_mren_item(array $r) {
    $d = kop_network_renames_decisions()[$r['key']] ?? array();
    $c = kop_network_renames_candidates()[$r['key']] ?? null;
    $a = kop_network_renames_assess($r);
    if ($c && !empty($c['year'])) {
        $a['suggest'] = (int) $c['year'];
        $a['why'] = 'the research below';
    }
    $view = (string) ($d['decision'] ?? '') ?: 'review';
    $flags = array('missing' => 'one of the names has no years', 'same' => 'both names have the same years',
        'overlap' => 'the two names\' years overlap', 'reversed' => 'the line may point the wrong way');
    $problems = array();
    foreach ($a['flags'] as $f) $problems[] = $flags[$f] ?? $f;

    $text = kop_rinbox_mren_side_text($r['earlier'], 'Earlier name') . "\n" . kop_rinbox_mren_side_text($r['later'], 'Later name');
    if ($problems) $text .= "\n\nLooks wrong: " . implode('; ', $problems) . '.';
    if ($a['suggest']) $text .= "\nSuggested rename year: " . $a['suggest'] . ' (from ' . $a['why'] . ').';
    $links = array();
    if ($c) {
        if (!empty($c['note'])) $text .= "\n\n" . $c['note'];
        if (!empty($c['swapped'])) $text .= "\nThe research says the names went the other way round.";
        foreach ((array) ($c['sources'] ?? array()) as $s) {
            if (empty($s['url'])) continue;
            if (!empty($s['quote'])) $text .= "\n\n\"" . $s['quote'] . '"' . "\n(" . $s['url'] . ')';
            $host = (string) wp_parse_url($s['url'], PHP_URL_HOST);
            $links[] = array('label' => $host !== '' ? preg_replace('/^www\./', '', $host) : 'Source', 'url' => $s['url']);
        }
    }
    $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
    foreach (array($r['earlier'], $r['later']) as $s) $links[] = array('label' => $s['name'] . ' on the map', 'url' => $map . '#open=' . rawurlencode($s['id']));

    if ($view === 'review') {
        // Undecided: start the way the research says the names go.
        $swapped = $c && !empty($c['swapped']);
        $actions = array(
            array('id' => 'save', 'label' => 'Save the rename year', 'style' => 'approve', 'params' => array(
                array('name' => 'year', 'label' => 'Year of the rename', 'type' => 'number', 'value' => $a['suggest'] ? (string) $a['suggest'] : ''),
                array('name' => 'swapped', 'label' => 'Which name came first', 'type' => 'select', 'value' => $swapped ? '1' : '0', 'options' => array(
                    '0' => $r['earlier']['name'] . ' first, then ' . $r['later']['name'],
                    '1' => 'The other way round: ' . $r['later']['name'] . ' first, then ' . $r['earlier']['name'],
                )),
            )),
            array('id' => 'skip', 'label' => 'Not a rename', 'style' => 'reject'),
        );
        $label = 'To review';
    } else {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo'));
        $label = $view === 'saved'
            ? 'Saved: renamed in ' . (int) $d['year'] . (!empty($d['swapped']) ? ', the other way round' : '')
            : 'Not a rename';
    }
    $fid = $r['later']['facilityId'] ?: $r['earlier']['facilityId'];
    return array(
        'key'          => $r['key'],
        'title'        => $r['earlier']['name'] . ' → ' . $r['later']['name'],
        'subtitle'     => $problems ? 'Looks wrong: ' . implode('; ', $problems) : ($a['suggest'] ? 'Suggested year: ' . $a['suggest'] : ''),
        'text'         => $text,
        'status'       => $view,
        'status_label' => $label,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => $fid ? kop_rinbox_facility($fid) : null,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_mren_act($key, $action, array $params) {
    $graph = kop_network_map_graph() ?: array();
    $item = array('key' => $key);
    switch ($action) {
        case 'save':
            $item += array('decision' => 'save', 'year' => (int) ($params['year'] ?? 0), 'swapped' => !empty($params['swapped']) && $params['swapped'] !== '0');
            break;
        case 'skip':
            $item['decision'] = 'skip';
            break;
        case 'undo':
            $item['decision'] = 'undo';
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
    $out = kop_network_renames_decide(array($item), kop_rinbox_reviewer(), $graph);
    $r = $out[$key] ?? null;
    if (!$r) throw new RuntimeException('That rename is no longer on the map.');
    if (!empty($r['error'])) throw new RuntimeException($r['error']);
    if ($action === 'save') {
        return array('message' => 'Saved. On the map the earlier name now ends in ' . $r['year'] . ' and the later one starts then'
            . (!empty($r['swapped']) ? ', with the line turned round' : '') . '. Undo is on the Saved tab.');
    }
    if ($action === 'skip') return array('message' => 'Marked not a rename. The map is unchanged.');
    return array('message' => 'Undone. The rename is waiting for review again.');
}
