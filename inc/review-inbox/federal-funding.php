<?php
/**
 * Review inbox source: federal funding found for programs (inc/federal-funding.php,
 * docs/PLAN.md 3.15). Each card is one program: the federal money the research
 * found (Medicaid, foster care funds, ORR, TRICARE, PPP, Education), with what
 * each source says and its links, and the record it goes on (the best match
 * filled in, any other candidates listed). Add puts the entries on that record
 * and its facility page; "New record" makes one for a program KOP has none for;
 * Leave it off changes nothing. Undo on the Added tab takes off exactly what
 * Add put on.
 *
 * Keys: the proposal's key (name and state). Tool: add every high-confidence
 * program matched to one record.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('federal-funding', function () {
    if (!function_exists('kop_ffund_proposals')) return null;
    return array(
        'label'    => 'Federal funding',
        'group'    => 'Suggestions to check',
        'help'     => 'Federal money programs received since October 2021: Medicaid, federal foster care funds, refugee resettlement grants for migrant children, TRICARE, PPP loans and Education aid. Each card shows what the sources say and the record it goes on. Add puts it on the record and its facility page at once; Leave it off changes nothing. Undo on the Added tab.',
        'views'    => array('matched' => 'Matched to a record', 'check' => 'Pick the record', 'new' => 'No record yet',
            'applied' => 'Added', 'rejected' => 'Left off'),
        'count'    => function () {
            $c = kop_rinbox_ffund_view_counts();
            return $c['matched'] + $c['check'] + $c['new'];
        },
        'view_counts' => 'kop_rinbox_ffund_view_counts',
        'tools'    => array(
            array('id' => 'add_matched_high', 'label' => 'Add every high-confidence match', 'style' => 'approve',
                'help' => 'Adds the funding of every card on "Matched to a record" with high confidence to the record shown on it. Each can be undone on the Added tab.',
                'confirm' => 'Add the funding of every high-confidence match to its record? Each can be undone on the Added tab.'),
        ),
        'tool'     => 'kop_rinbox_ffund_tool',
        'list'     => 'kop_rinbox_ffund_list',
        'get'      => function ($key) {
            $p = kop_ffund_proposals()[$key] ?? null;
            return $p ? kop_rinbox_ffund_item((string) $key, $p) : null;
        },
        'save'     => function ($key, array $fields) {
            return array('message' => kop_ffund_save_edits((string) $key, $fields));
        },
        'act'      => 'kop_rinbox_ffund_act',
    );
});

function kop_rinbox_ffund_view(array $p, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    if ($decision !== '') return $decision;
    $m = (string) ($p['match'] ?? '');
    return $m === 'matched' ? 'matched' : ($m === 'check' ? 'check' : 'new');
}

function kop_rinbox_ffund_view_counts() {
    $decisions = kop_ffund_decisions();
    $counts = array('matched' => 0, 'check' => 0, 'new' => 0, 'applied' => 0, 'rejected' => 0);
    foreach (kop_ffund_proposals() as $key => $p) {
        $v = kop_rinbox_ffund_view($p, $decisions[$key] ?? array());
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

/** The record a card's Add goes on: the one it was added to, else the best candidate. */
function kop_rinbox_ffund_fid(array $p, array $d) {
    if (!empty($d['facility_id'])) return (int) $d['facility_id'];
    return (int) ($p['candidates'][0]['id'] ?? 0);
}

function kop_rinbox_ffund_tool($id, array $params) {
    if ($id !== 'add_matched_high') throw new RuntimeException('Unknown tool.');
    $decisions = kop_ffund_decisions();
    $done = 0;
    $left = array();
    foreach (kop_ffund_proposals() as $key => $p) {
        if (kop_rinbox_ffund_view($p, $decisions[$key] ?? array()) !== 'matched' || ($p['confidence'] ?? '') !== 'high') continue;
        try {
            kop_ffund_apply($key, kop_rinbox_ffund_fid($p, array()), kop_rinbox_reviewer());
            $done++;
        } catch (RuntimeException $e) {
            $left[] = (string) $p['name'];
        }
    }
    if (!$done && !$left) return array('message' => 'No high-confidence matches are waiting.');
    return array('message' => 'Added the funding of ' . $done . ' program' . ($done === 1 ? '' : 's') . '.'
        . ($left ? ' Left for you: ' . implode(', ', array_slice($left, 0, 8)) . (count($left) > 8 ? ' and ' . (count($left) - 8) . ' more' : '') . '.' : ''));
}

function kop_rinbox_ffund_list(array $q) {
    $decisions = kop_ffund_decisions();
    $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
    $rows = array();
    foreach (kop_ffund_proposals() as $key => $p) {
        if (kop_rinbox_ffund_view($p, $decisions[$key] ?? array()) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower($p['name'] . ' ' . ($p['state'] ?? '') . ' ' . ($p['operator'] ?? '') . ' ' . implode(' ', array_column($p['entries'], 'detail')));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $key] = $p;
    }
    uksort($rows, function ($x, $y) use ($rows, $rank) {
        return ($rank[$rows[$x]['confidence'] ?? 'low'] ?? 3) <=> ($rank[$rows[$y]['confidence'] ?? 'low'] ?? 3)
            ?: strcmp((string) $rows[$x]['state'], (string) $rows[$y]['state'])
            ?: strcasecmp((string) $rows[$x]['name'], (string) $rows[$y]['name']);
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $key => $p) $items[] = kop_rinbox_ffund_item((string) $key, $p);
    return array('items' => $items, 'total' => count($rows));
}

/** A candidate record as one line: name, place, status, years, and how it matched. */
function kop_rinbox_ffund_candidate_line(array $c) {
    static $hows = array('exact name' => 'same name', 'other/past name' => 'one of its other or past names',
        'close name' => 'a close name', 'picked by hand' => 'picked by hand from a search');
    $line = '';
    try {
        $st = kop_rinbox_pdo()->prepare('SELECT name, city, state, status, start_year, end_year FROM facilities_v2 WHERE id = ?');
        $st->execute(array((int) $c['id']));
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $r = null;
    }
    if (!$r) return '#' . (int) $c['id'] . ' (not on file any more)';
    $years = trim(($r['start_year'] ?: '?') . '-' . ($r['end_year'] ?: ''), '-');
    $line = $r['name'] . ' (' . trim(implode(', ', array_filter(array($r['city'], $r['state']))) . ($years !== '?' ? ', ' . $years : '')) . ')'
        . ', ' . ($r['status'] ?: 'Unknown') . ', #' . (int) $c['id'] . ': ' . ($hows[$c['how'] ?? ''] ?? (string) ($c['how'] ?? ''));
    return $line;
}

function kop_rinbox_ffund_item($key, array $p) {
    $d = kop_ffund_decisions()[$key] ?? array();
    $view = kop_rinbox_ffund_view($p, $d);
    $streams = kop_facility_federal_funding_streams();
    $entries = kop_ffund_entries($key, $p);
    $open = !in_array($view, array('applied', 'rejected'), true);

    $details = array();
    foreach ($entries as $n => $e) {
        $details[] = array('label' => $streams[$e['stream']] . ($e['years'] !== '' ? ' (' . $e['years'] . ')' : ''),
            'value' => $e['detail'] . ($e['sources'] ? ' Sources: ' . implode('; ', array_map(function ($s) { return $s['label'] ?: $s['url']; }, $e['sources'])) . '.' : ''));
    }
    if (($p['operating'] ?? '') !== '') $details[] = array('label' => 'Operating since Oct 2021', 'value' => (string) $p['operating']);
    foreach ((array) ($p['flags'] ?? array()) as $f) $details[] = array('label' => 'Check', 'value' => (string) $f);
    $cands = (array) ($p['candidates'] ?? array());
    if ($view === 'applied') {
        $details[] = array('label' => !empty($d['created']) ? 'Made the record' : 'Added to',
            'value' => kop_rinbox_ffund_candidate_line(array('id' => (int) $d['facility_id'], 'how' => '')) . ', ' . ($d['at'] ?? '') . ' by ' . ($d['by'] ?? ''));
    } elseif ($view === 'rejected') {
        $details[] = array('label' => 'Left off', 'value' => trim(($d['at'] ?? '') . ' by ' . ($d['by'] ?? '')));
    } else {
        foreach ($cands as $i => $c) {
            $details[] = array('label' => $i === 0 ? 'Best match' : 'Also possible', 'value' => kop_rinbox_ffund_candidate_line($c));
        }
        if (!$cands) $details[] = array('label' => 'Record', 'value' => 'No record found. Search for one in "Record" below, or make a new one.');
    }
    $details[] = array('label' => 'Confidence', 'value' => (string) ($p['confidence'] ?? 'medium'));

    $links = array();
    foreach ($entries as $e) {
        foreach ($e['sources'] as $s) {
            if (!isset($links[$s['url']])) $links[$s['url']] = array('label' => $s['label'] ?: $s['url'], 'url' => $s['url']);
        }
    }

    $fields = array();
    $actions = array();
    if ($open) {
        foreach ($entries as $n => $e) {
            $fields[] = array('name' => 'detail_' . $n, 'label' => 'Wording on the page: ' . $streams[$e['stream']], 'type' => 'textarea', 'value' => $e['detail']);
            $fields[] = array('name' => 'years_' . $n, 'label' => 'Years (2022 or 2020-2024)', 'type' => 'text', 'value' => $e['years']);
        }
        $fid = kop_rinbox_ffund_fid($p, $d);
        $actions[] = array('id' => 'add', 'label' => 'Add to this record', 'style' => $view === 'matched' ? 'approve' : 'neutral',
            'params' => array(array('name' => 'facility_id', 'label' => 'Record', 'type' => 'facility', 'value' => $fid ?: '')),
            'help' => 'Puts ' . count($entries) . ' federal funding entr' . (count($entries) === 1 ? 'y' : 'ies') . ' on the record picked in "Record", listed on its facility page at once. Undo on the Added tab takes them off.');
        $types = kop_rinbox_options(array_combine(kop_facdisc_types(), kop_facdisc_types()));
        $actions[] = array('id' => 'create', 'label' => 'New record…', 'style' => $view === 'new' ? 'approve' : 'neutral', 'ask' => true,
            'submit' => 'Create the record and add the funding',
            'help' => 'Makes a facility record for ' . $p['name'] . ' with its own page and puts the funding on it. If that name and place already have a record, the funding goes on it instead. Undo deletes the new record while nobody has edited it.',
            'params' => array(
                array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $p['name'])),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($p['city'] ?? ''), 'optional' => true),
                array('name' => 'state', 'label' => 'State (two letters)', 'type' => 'text', 'value' => (string) ($p['state'] ?? '')),
                array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => '', 'optional' => true),
                array('name' => 'operator', 'label' => 'Operator', 'type' => 'text', 'value' => (string) ($p['operator'] ?? ''), 'optional' => true),
            ));
        $actions[] = array('id' => 'reject', 'label' => 'Leave it off', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Left off.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $view === 'applied' ? 'Undo' : 'Back to review', 'style' => 'undo',
            'help' => $view === 'applied'
                ? 'Takes the entries Add put on the record off it again' . (!empty($d['created']) ? ', and deletes the record it made while nobody has edited it' : '') . '; the card waits for review again.'
                : 'Nothing on the site changes; the card waits for review again.');
    }

    $labels = array('matched' => 'Matched to a record', 'check' => 'Pick the record', 'new' => 'No record yet', 'applied' => 'Added', 'rejected' => 'Left off');
    $kinds = array_values(array_unique(array_map(function ($e) use ($streams) { return $streams[$e['stream']]; }, $entries)));
    return array(
        'key'          => $key,
        'title'        => (string) $p['name'],
        'subtitle'     => implode(' · ', array_filter(array(
            trim(implode(', ', array_filter(array((string) ($p['city'] ?? ''), (string) ($p['state'] ?? ''))))),
            (string) ($p['operator'] ?? ''), implode(', ', $kinds), 'confidence: ' . ($p['confidence'] ?? 'medium')))),
        'text'         => '',
        'details'      => $details,
        'links'        => array_values($links),
        'fields'       => $fields,
        'status'       => $view,
        'status_label' => $labels[$view] ?? $view,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => ($fid = kop_rinbox_ffund_fid($p, $d)) ? kop_rinbox_facility($fid) : null,
        'selected'     => $view === 'matched' && ($p['confidence'] ?? '') === 'high',
        'actions'      => $actions,
    );
}

function kop_rinbox_ffund_act($key, $action, array $params) {
    switch ($action) {
        case 'add':
            return array('message' => kop_ffund_apply($key, (int) ($params['facility_id'] ?? 0), kop_rinbox_reviewer()));
        case 'create':
            return array('message' => kop_ffund_create($key, $params, kop_rinbox_reviewer()));
        case 'reject':
            return array('message' => kop_ffund_reject($key, kop_rinbox_reviewer()));
        case 'undo':
            return array('message' => kop_ffund_undo($key));
    }
    throw new RuntimeException('Unknown action.');
}
