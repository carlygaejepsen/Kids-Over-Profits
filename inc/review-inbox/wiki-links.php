<?php
/**
 * Review inbox source: which facility record each r/troubledteens wiki entry
 * is about (docs/PLAN.md 3.12 step 1, inc/wiki-updates.php). Only entries with
 * no link are listed; the record they may be is found by name, past and other
 * names, and the words of the name inside the entry's states.
 *
 * Tabs: One clear match (Link, or the tool links them all), Pick the record
 * (one button per candidate, or the finder), No record found (the finder),
 * Linked here and Set aside (each with Undo, which restores what the entry
 * had). A link is saved as 'suggested' in wiki_submissions
 * .facility_unique_name, as the wiki editor's own link step saves it; the
 * entry's text and updated_at do not change.
 *
 * Keys: the wiki_submissions id of the entry's newest row.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/wiki-updates.php';

kop_rinbox_register('wiki-links', function () {
    return array(
        'label'    => 'Wiki links',
        'group'    => 'Suggestions to check',
        'help'     => 'Each r/troubledteens wiki entry tied to the KOP record it is about, so the entry can be brought up to date from the record (closures, news, lawsuits, deaths, findings). Link the record it describes; Set aside a page that is not about one program. Undo on the Linked here and Set aside tabs.',
        'views'    => array('clear' => 'One clear match', 'choose' => 'Pick the record', 'none' => 'No record found', 'linked' => 'Linked here', 'skip' => 'Set aside'),
        'count'    => function () {
            $c = kop_rinbox_wlinks_view_counts();
            return $c['clear'] + $c['choose'] + $c['none'];
        },
        'view_counts' => 'kop_rinbox_wlinks_view_counts',
        'tools'    => array(array('id' => 'link_clear', 'label' => 'Link every clear match', 'style' => 'approve',
            'help' => 'Links every entry on One clear match to the record shown on its card. Each can be undone on the Linked here tab.',
            'confirm' => 'Link every entry on One clear match to the record on its card? Each can be undone on the Linked here tab.')),
        'tool'     => 'kop_rinbox_wlinks_tool',
        'list'     => 'kop_rinbox_wlinks_list',
        'get'      => function ($key) {
            $rows = kop_rinbox_wlinks_rows();
            return isset($rows[(int) $key]) ? kop_rinbox_wlinks_item($rows[(int) $key]) : null;
        },
        'act'      => 'kop_rinbox_wlinks_act',
    );
});

/**
 * Every current entry with its candidates and its tab: id => entry + cands,
 * view. Candidates are kept an hour (they read every facility record); the
 * link columns and the log are read fresh.
 */
function kop_rinbox_wlinks_rows($reset = false) {
    static $rows = null;
    if ($reset) {
        $rows = null;
        return array();
    }
    if ($rows !== null) return $rows;
    $pdo = kop_wiki_upd_pdo();
    $entries = kop_wiki_upd_entries($pdo);
    $cached = get_transient('kop_rinbox_wiki_links_v2');
    if (!is_array($cached)) {
        $cached = array();
        foreach ($entries as $id => $e) {
            if ($e['kind'] !== 'list') $cached[$id] = array_slice(kop_wiki_upd_candidates($e, $pdo), 0, 6);
        }
        set_transient('kop_rinbox_wiki_links_v2', $cached, HOUR_IN_SECONDS);
    }
    $log = kop_wiki_upd_link_log();
    $rows = array();
    foreach ($entries as $id => $e) {
        $decision = (string) ($log[$id]['decision'] ?? '');
        $e['cands'] = $cached[$id] ?? array();
        if ($decision === 'link') $e['view'] = 'linked';
        elseif ($decision === 'skip') $e['view'] = 'skip';
        else {
            $state = kop_wiki_upd_link_state($e, $e['cands']);
            if ($state === 'linked' || $state === 'skip') continue;
            $e['view'] = $state;
        }
        $e['excerpt'] = kop_wiki_upd_excerpt($e);
        unset($e['original_markdown'], $e['generated_markdown'], $e['json_data']);
        $rows[$id] = $e;
    }
    return $rows;
}

function kop_rinbox_wlinks_view_counts() {
    $counts = array('clear' => 0, 'choose' => 0, 'none' => 0, 'linked' => 0, 'skip' => 0);
    foreach (kop_rinbox_wlinks_rows() as $e) $counts[$e['view']]++;
    return $counts;
}

function kop_rinbox_wlinks_list(array $q) {
    $rows = array_filter(kop_rinbox_wlinks_rows(), function ($e) use ($q) {
        if ($e['view'] !== $q['view']) return false;
        if ($q['search'] === '') return true;
        $hay = mb_strtolower($e['program_name'] . ' ' . $e['city_state'] . ' ' . implode(' ', array_column($e['cands'], 'name')));
        return mb_strpos($hay, mb_strtolower($q['search'])) !== false;
    });
    uasort($rows, function ($a, $b) { return strcasecmp($a['program_name'], $b['program_name']); });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $e) $items[] = kop_rinbox_wlinks_item($e);
    return array('items' => $items, 'total' => count($rows));
}

/** "Name (Town, ST)" for a candidate. */
function kop_rinbox_wlinks_label(array $c) {
    return $c['name'] . ($c['place'] !== '' ? ' (' . $c['place'] . ')' : '') . (!empty($c['operator']) ? ' (company)' : '');
}

/**
 * Where a candidate can be looked at: a company's page, a facility's page,
 * else the admin data form opened on the record (most records have no page).
 */
function kop_rinbox_wlinks_url(array $c) {
    if (!empty($c['operator'])) {
        $url = function_exists('kop_operator_page_url_for_name') ? (string) kop_operator_page_url_for_name($c['name']) : '';
        return $url !== '' ? $url : home_url('/operator/');
    }
    $url = !empty($c['id']) && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $c['id']) : '';
    return $url !== '' ? $url : home_url('/admin-data/?find=' . rawurlencode((string) $c['name']));
}

function kop_rinbox_wlinks_item(array $e) {
    $view = $e['view'];
    $name = trim((string) $e['program_name']);
    $log = kop_wiki_upd_link_log()[$e['id']] ?? array();
    $kinds = array('program' => 'Program', 'operator' => 'Company', 'other' => 'Topic page');
    $details = array();
    if (($e['excerpt'] ?? '') !== '') $details[] = array('label' => 'The entry says', 'value' => $e['excerpt']);
    foreach ($e['cands'] as $i => $c) {
        $details[] = array('label' => !empty($c['operator']) ? 'Company ' . ($i + 1) : 'Record ' . ($i + 1),
            'value' => kop_rinbox_wlinks_label($c) . ': ' . $c['reason'] . ($c['status'] !== '' ? '; ' . $c['status'] : ''),
            'url' => kop_rinbox_wlinks_url($c));
    }
    if (!empty($log['name'])) $details[] = array('label' => 'Linked to', 'value' => $log['name'] . (!empty($log['by']) ? ' (by ' . $log['by'] . ')' : ''));
    $texts = array(
        'clear'  => 'One record matches this entry. Link it if the record is the program the entry describes.',
        'choose' => 'Several records may be this entry. Link the one it describes, or find another with the finder.',
        'none'   => 'No record has this name in the entry\'s state. Find the record with the finder, or set the entry aside if KOP has none.',
        'linked' => 'Linked on this screen.',
        'skip'   => 'Set aside: not linked to a record.',
    );
    $links = array();
    if ($e['page'] !== '') $links[] = array('label' => 'The entry on the Reddit wiki', 'url' => 'https://www.reddit.com/r/troubledteens/wiki/' . $e['page'] . '/');
    $links[] = array('label' => 'The entry on KOP', 'url' => home_url('/wiki-feed/?search=' . rawurlencode($name)));

    if ($view === 'linked' || $view === 'skip') {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'linked' ? 'Takes the link off ' . $name . ' (it gets back the link it had before, if any); it waits here again.'
                : $name . ' waits here again.'));
    } else {
        $actions = array();
        foreach (array_slice($e['cands'], 0, $view === 'clear' ? 1 : 4) as $i => $c) {
            $actions[] = array('id' => 'link_' . $i, 'label' => 'Link to ' . kop_rinbox_wlinks_label($c), 'style' => 'approve',
                'help' => 'Ties the wiki entry ' . $name . ' to the record ' . $c['name'] . ', so updates for the entry are drawn from that record.');
        }
        $actions[] = array('id' => 'link_other', 'label' => $actions ? 'Link to another record' : 'Link to a record', 'style' => $actions ? 'neutral' : 'approve',
            'help' => 'Ties the wiki entry ' . $name . ' to the record picked in the finder.',
            'params' => array(array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => '')));
        $actions[] = array('id' => 'link_company', 'label' => 'Link to a company', 'style' => 'neutral',
            'help' => 'Ties the wiki entry ' . $name . ' to a company record (for a page about a company, not one program).',
            'params' => array(array('name' => 'company', 'label' => 'Company', 'type' => 'select', 'value' => '',
                'options' => kop_rinbox_wlinks_companies())));
        $actions[] = array('id' => 'skip', 'label' => 'Set aside', 'style' => 'reject',
            'help' => 'Leaves ' . $name . ' unlinked (a topic page, or a program KOP has no record of); it moves to Set aside.');
    }
    $first = $e['cands'][0] ?? null;
    return array(
        'key'          => (string) $e['id'],
        'title'        => $name,
        'subtitle'     => implode(' · ', array_filter(array($kinds[$e['kind']] ?? '', trim((string) $e['city_state'], " -"), (string) $e['years_active']))),
        'text'         => $texts[$view],
        'details'      => $details,
        'status'       => $view,
        'status_label' => $view === 'linked' ? 'Linked: ' . ($log['name'] ?? '') : '',
        'created'      => (string) ($log['at'] ?? $e['updated_at']),
        'facility'     => $first && !empty($first['id']) ? kop_rinbox_facility((int) $first['id']) : null,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_wlinks_act($key, $action, array $params) {
    $rows = kop_rinbox_wlinks_rows();
    $e = $rows[(int) $key] ?? null;
    if (!$e) throw new RuntimeException('That wiki entry is not waiting here any more.');
    $pdo = kop_wiki_upd_pdo();
    $name = trim((string) $e['program_name']);
    $by = kop_rinbox_reviewer();
    if ($action === 'undo') {
        kop_wiki_upd_unlink($pdo, $e['id']);
        kop_rinbox_wlinks_rows(true);
        return array('message' => 'Undone. ' . $name . ' is waiting for a link again.');
    }
    if ($e['view'] === 'linked' || $e['view'] === 'skip') throw new RuntimeException('Undo this entry first.');
    if ($action === 'skip') {
        kop_wiki_upd_link($pdo, $e['id'], '', $by, 'skip');
        kop_rinbox_wlinks_rows(true);
        return array('message' => 'Set aside. ' . $name . ' stays unlinked; Undo is on the Set aside tab.');
    }
    if ($action === 'link_company') {
        $target = trim((string) ($params['company'] ?? ''));
        if ($target === '' || !isset(kop_rinbox_wlinks_companies()[$target])) throw new RuntimeException('Pick a company first.');
        $label = $target;
    } elseif ($action === 'link_other') {
        $fid = (int) ($params['facility'] ?? 0);
        $st = $pdo->prepare('SELECT unique_name, name FROM facilities_v2 WHERE id = ?');
        $st->execute(array($fid));
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) throw new RuntimeException('Pick a record in the finder first.');
        $target = (string) $rec['unique_name'];
        $label = (string) ($rec['name'] ?: $rec['unique_name']);
    } elseif (preg_match('/^link_(\d+)$/', $action, $m) && isset($e['cands'][(int) $m[1]])) {
        $target = (string) $e['cands'][(int) $m[1]]['unique_name'];
        $label = (string) $e['cands'][(int) $m[1]]['name'];
    } else {
        throw new RuntimeException('Unknown action.');
    }
    kop_wiki_upd_link($pdo, $e['id'], $target, $by);
    kop_rinbox_wlinks_rows(true);
    return array('message' => 'Linked. ' . $name . ' is tied to ' . $label . '; Undo is on the Linked here tab.');
}

/** Every company record, for the "Link to a company" list. */
function kop_rinbox_wlinks_companies() {
    static $list = null;
    if ($list === null) $list = kop_wiki_upd_operator_names(kop_wiki_upd_pdo());
    return $list;
}

function kop_rinbox_wlinks_tool($id, array $params) {
    if ($id !== 'link_clear') throw new RuntimeException('Unknown tool.');
    $pdo = kop_wiki_upd_pdo();
    $by = kop_rinbox_reviewer();
    $done = 0;
    foreach (kop_rinbox_wlinks_rows() as $e) {
        if ($e['view'] !== 'clear' || empty($e['cands'][0]['unique_name'])) continue;
        kop_wiki_upd_link($pdo, $e['id'], $e['cands'][0]['unique_name'], $by);
        $done++;
    }
    kop_rinbox_wlinks_rows(true);
    return array('message' => $done ? 'Linked ' . $done . ' entr' . ($done === 1 ? 'y' : 'ies') . ' to their clear match. Each can be undone on the Linked here tab.'
        : 'No clear matches are waiting.');
}
