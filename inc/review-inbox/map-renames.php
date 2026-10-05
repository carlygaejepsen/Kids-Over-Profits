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
 * names' own years). Tools: save every rename whose year the research quotes
 * with high confidence (the Map Renames screen's bulk button), and the map
 * rebuild of KOP Tools > Map Rebuild.
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
        'view_counts' => function () {
            $decisions = kop_network_renames_decisions();
            $counts = array('review' => 0, 'saved' => 0, 'skipped' => 0);
            foreach (kop_rinbox_mren_rows() as $r) {
                $v = (string) ($decisions[$r['key']]['decision'] ?? '') ?: 'review';
                if (isset($counts[$v])) $counts[$v]++;
            }
            return $counts;
        },
        'tools'    => array_values(array_filter(array(
            array('id' => 'save_sure', 'label' => 'Save every high-confidence year', 'style' => 'approve',
                'help' => 'Saves, as the research has them, every rename on To review whose year a source states and the research rated high confidence (turned round where the sources say so).',
                'confirm' => 'Save every high-confidence rename year? Each can be undone on the Saved tab.'),
            function_exists('kop_rinbox_map_rebuild_tool') ? kop_rinbox_map_rebuild_tool('Saved renames show on the map at once, without a rebuild.') : null,
        ))),
        'tool'     => 'kop_rinbox_mren_tool',
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

/** Node id => the years the map shows now (accepted Map Years, then saved renames). */
function kop_rinbox_mren_years_now() {
    // Worked out again once a decision changes (an item is drawn again right after its save).
    static $memo = array();
    $decisions = kop_network_renames_decisions();
    $k = md5(serialize($decisions));
    if (!isset($memo[$k])) {
        $graph = kop_network_map_graph() ?: array();
        $base = kop_network_renames_base_years($graph);
        $memo = array($k => array_merge($base, kop_network_renames_apply_years($base, $decisions)));
    }
    return $memo[$k];
}

/** True when the research states the year with high confidence: the bulk save takes these. */
function kop_rinbox_mren_sure(array $c = null) {
    return $c && !empty($c['year']) && (!isset($c['yearQuoted']) || !empty($c['yearQuoted'])) && ($c['confidence'] ?? '') === 'high';
}

function kop_rinbox_mren_tool($id, array $params) {
    if ($id === 'map_rebuild') return kop_rinbox_map_rebuild_run();
    if ($id !== 'save_sure') throw new RuntimeException('Unknown tool.');
    $decisions = kop_network_renames_decisions();
    $research = kop_network_renames_candidates();
    $items = array();
    foreach (kop_rinbox_mren_rows() as $r) {
        $c = $research[$r['key']] ?? null;
        if (isset($decisions[$r['key']]) || !kop_rinbox_mren_sure($c)) continue;
        $items[] = array('key' => $r['key'], 'decision' => 'save', 'year' => (int) $c['year'], 'swapped' => !empty($c['swapped']));
    }
    if (!$items) return array('message' => 'No high-confidence rename years are waiting.');
    $done = 0;
    $failed = 0;
    foreach (kop_network_renames_decide($items, kop_rinbox_reviewer(), kop_network_map_graph() ?: array()) as $r) {
        if (!empty($r['error'])) $failed++;
        else $done++;
    }
    return array('message' => 'Saved ' . $done . ' rename' . ($done === 1 ? '' : 's') . ($failed ? '; ' . $failed . ' could not be (open them to fix the year)' : '') . '. They are on the map now.');
}

/** "1971-2004" as "1971 to 2004"; '' as "no years". */
function kop_rinbox_mren_years_text($years) {
    $years = (string) $years;
    return $years === '' ? 'no years' : preg_replace('/^(\d{4})-(\d{4})$/', '$1 to $2', $years);
}

/** The two names side by side, in the order they went (earlier first), differences marked. */
function kop_rinbox_mren_compare(array $first, array $second) {
    $now = kop_rinbox_mren_years_now();
    $page = function ($s) {
        return $s['facilityId'] ? 'record #' . $s['facilityId'] : 'no record';
    };
    return kop_rinbox_compare_rows(array('Earlier name', 'Later name'), array(
        'Name'                => array($first['name'], $second['name']),
        'Years on the board'  => array(kop_rinbox_mren_years_text($first['years']), kop_rinbox_mren_years_text($second['years'])),
        'On the map now'      => array(kop_rinbox_mren_years_text($now[$first['id']] ?? $first['years']), kop_rinbox_mren_years_text($now[$second['id']] ?? $second['years'])),
        'Run by'              => array($first['operators'] ?: ($first['chain'] !== '' ? $first['chain'] : 'none recorded'), $second['operators'] ?: ($second['chain'] !== '' ? $second['chain'] : 'none recorded')),
        'Status on the map'   => array($first['status'], $second['status']),
        'Facility record'     => array($page($first), $page($second)),
    ));
}

function kop_rinbox_mren_item(array $r) {
    $d = kop_network_renames_decisions()[$r['key']] ?? array();
    $c = kop_network_renames_candidates()[$r['key']] ?? null;
    $a = kop_network_renames_assess($r);
    $from_research = false;
    if ($c && !empty($c['year'])) {
        $a['suggest'] = (int) $c['year'];
        $a['why'] = 'the research';
        $from_research = true;
    }
    $view = (string) ($d['decision'] ?? '') ?: 'review';
    // Undecided: the way the research says the names go; decided: as saved.
    $swapped = $d ? !empty($d['swapped']) : ($c && !empty($c['swapped']));
    list($first, $second) = $swapped ? array($r['later'], $r['earlier']) : array($r['earlier'], $r['later']);
    $flags = array('missing' => 'Years missing on one side', 'same' => 'Both names have the same years',
        'overlap' => 'The later name starts before the earlier one ended',
        'reversed' => 'Looks reversed: the "later" name ended before the "earlier" one began');
    $problems = array();
    foreach ($a['flags'] as $f) $problems[] = $flags[$f] ?? $f;

    $details = array();
    if ($view === 'review') {
        foreach ($problems as $p) $details[] = array('label' => 'Looks wrong', 'value' => $p);
        if ($a['suggest']) $details[] = array('label' => 'Suggested year', 'value' => $a['suggest'] . ' (from ' . $a['why'] . '; check it)');
        if (in_array('reversed', $a['flags'], true) && !$swapped) $details[] = array('label' => 'Hint', 'value' => 'Probably drawn backwards: choose "the other way round".');
    }
    if ($c) {
        $details[] = array('label' => 'Research', 'value' => !empty($c['year'])
            ? 'renamed in ' . (int) $c['year'] . ' (' . ($c['confidence'] ?? 'low') . ' confidence' . (isset($c['yearQuoted']) && empty($c['yearQuoted']) ? '; the year is the source\'s date, not in its words' : '') . ')'
            : 'no year found');
        if (!empty($c['swapped'])) $details[] = array('label' => 'Order', 'value' => 'The sources say the board had these the wrong way round, so the order is already turned round.');
        if (!empty($c['note'])) $details[] = array('label' => 'Note', 'value' => (string) $c['note']);
        $details = array_merge($details, kop_rinbox_quote_details((array) ($c['sources'] ?? array())));
    }

    $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
    $links = array();
    foreach (array($first, $second) as $s) {
        $links[] = array('label' => $s['name'] . ' on the map', 'url' => $map . '#open=' . rawurlencode($s['id']));
        if ($s['facilityId'] && function_exists('kop_facility_page_url') && ($u = (string) kop_facility_page_url($s['facilityId'])) !== '') {
            $links[] = array('label' => $s['name'] . ' page', 'url' => $u);
        }
    }

    if ($view === 'review') {
        $actions = array(
            array('id' => 'save', 'label' => 'Save the rename year', 'style' => 'approve',
                'help' => 'On the network map timeline, the first name (picked below) ends in the year you give and the other starts then; the change is live at once.',
                'params' => array(
                array('name' => 'year', 'label' => 'Year of the rename', 'type' => 'number', 'value' => $a['suggest'] ? (string) $a['suggest'] : ''),
                array('name' => 'swapped', 'label' => 'Which name came first', 'type' => 'select', 'value' => $swapped ? '1' : '0', 'options' => array(
                    '0' => $r['earlier']['name'] . ' first, then ' . $r['later']['name'],
                    '1' => 'The other way round: ' . $r['later']['name'] . ' first, then ' . $r['earlier']['name'],
                )),
            )),
            array('id' => 'skip', 'label' => 'Reject: not a rename', 'style' => 'reject',
                'help' => 'The map stays as it is; the pair moves to "Not a rename".'),
        );
        $label = 'To review';
    } else {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'saved'
                ? 'Takes the saved rename year off the map; the rename waits for review again.'
                : 'Nothing on the site changes; the rename waits for review again.'));
        $label = $view === 'saved'
            ? 'Saved: renamed in ' . (int) $d['year'] . (!empty($d['swapped']) ? ', the other way round' : '')
            : 'Not a rename';
    }
    $fid = $r['later']['facilityId'] ?: $r['earlier']['facilityId'];
    return array(
        'key'          => $r['key'],
        'title'        => $first['name'] . ' → ' . $second['name'],
        'subtitle'     => $view === 'review'
            ? ($problems ? 'Looks wrong: ' . implode('; ', $problems) : ($a['suggest'] ? 'Suggested year: ' . $a['suggest'] . ($from_research ? ' (research)' : '') : ''))
            : (!empty($d['by']) ? 'by ' . $d['by'] : ''),
        'text'         => $view === 'saved'
            ? 'On the map the earlier name ends in ' . (int) $d['year'] . ' and the later one starts then.'
            : ($view === 'skipped' ? 'Marked not a rename. The map is unchanged.'
                : 'Each name belongs to its own years. Give the year of the rename: the earlier name then ends that year and the later one starts it on the map\'s timeline.'),
        'compare'      => kop_rinbox_mren_compare($first, $second),
        'details'      => $details,
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
