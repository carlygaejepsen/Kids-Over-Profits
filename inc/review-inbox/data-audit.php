<?php
/**
 * Review inbox source: corrections from the data audit (inc/data-audit.php,
 * docs/PLAN.md 3.13). Each card is one thing outside sources show KOP has
 * wrong, with what KOP shows now, the changes proposed, and the quotes and
 * links behind them. Approve makes every change at once (refused, with
 * nothing changed, when the record was edited since the research); Reject
 * keeps KOP as it is; Undo on the Approved tab puts back exactly what
 * Approve changed.
 *
 * Keys: the proposal's key (the flag id, or transferred-<facility id>).
 * Tool: approve every high-confidence proposal that needs nothing by hand.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('data-audit', function () {
    if (!function_exists('kop_daudit_proposals')) return null;
    return array(
        'label'    => 'Data audit',
        'group'    => 'Suggestions to check',
        'help'     => 'Facility data that news, court and SEC filings and licensing records show is wrong: closing years, status, operators, map years and people. Each card shows what KOP has now, the change, and the quotes it rests on. Approve makes the change on the site at once; Reject keeps KOP as it is. Undo on the Approved tab.',
        'views'    => array('high' => 'High confidence', 'review' => 'Medium and low', 'applied' => 'Approved', 'rejected' => 'Rejected'),
        'count'    => function () {
            $c = kop_rinbox_daudit_view_counts();
            return $c['high'] + $c['review'];
        },
        'view_counts' => 'kop_rinbox_daudit_view_counts',
        'tools'    => array(
            array('id' => 'approve_high', 'label' => 'Approve every high-confidence correction', 'style' => 'approve',
                'help' => 'Approves, as proposed, every card on High confidence that needs nothing done by hand. Any whose record changed since are left for you.',
                'confirm' => 'Approve every high-confidence correction as proposed? Each can be undone on the Approved tab.'),
        ),
        'tool'     => 'kop_rinbox_daudit_tool',
        'list'     => 'kop_rinbox_daudit_list',
        // A proposal whose record moved since the research is settled in the Conflicts section.
        'conflicts' => 'kop_rinbox_daudit_conflicts',
        'resolve'  => function ($key, $how, $typed) {
            if ($how === 'keep') return kop_daudit_reject((string) $key, kop_rinbox_reviewer());
            if ($how === 'use') return kop_daudit_apply((string) $key, kop_rinbox_reviewer(), true);
            throw new RuntimeException('A data audit proposal is approved as researched or kept as KOP has it.');
        },
        'on_file_in_list' => true,
        'get'      => function ($key) {
            $p = kop_daudit_proposals()[$key] ?? null;
            return $p ? kop_rinbox_daudit_item((string) $key, $p) : null;
        },
        'act'      => 'kop_rinbox_daudit_act',
    );
});

function kop_rinbox_daudit_view($p, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    if ($decision !== '') return $decision;
    return ($p['confidence'] ?? '') === 'high' ? 'high' : 'review';
}

function kop_rinbox_daudit_view_counts() {
    $decisions = kop_daudit_decisions();
    $counts = array('high' => 0, 'review' => 0, 'applied' => 0, 'rejected' => 0);
    $conflicts = kop_rinbox_conflict_keys('data-audit');
    foreach (kop_daudit_proposals() as $key => $p) {
        $v = kop_rinbox_daudit_view($p, $decisions[$key] ?? array());
        if (isset($conflicts[(string) $key]) && ($v === 'high' || $v === 'review')) continue;
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

function kop_rinbox_daudit_has_manual(array $p) {
    foreach ($p['ops'] as $op) if (($op['type'] ?? '') === 'manual') return true;
    return false;
}

function kop_rinbox_daudit_tool($id, array $params) {
    if ($id !== 'approve_high') throw new RuntimeException('Unknown tool.');
    $decisions = kop_daudit_decisions();
    $done = 0;
    $left = array();
    foreach (kop_daudit_proposals() as $key => $p) {
        if (kop_rinbox_daudit_view($p, $decisions[$key] ?? array()) !== 'high' || kop_rinbox_daudit_has_manual($p)) continue;
        try {
            kop_daudit_apply($key, kop_rinbox_reviewer());
            $done++;
        } catch (RuntimeException $e) {
            $left[] = (string) ($p['title'] ?? $key);
        }
    }
    if (!$done && !$left) return array('message' => 'No high-confidence corrections are waiting.');
    return array('message' => 'Approved ' . $done . ' correction' . ($done === 1 ? '' : 's') . '.'
        . ($left ? ' Left for you (changed since, or needs a look): ' . implode(', ', array_slice($left, 0, 8)) . (count($left) > 8 ? ' and ' . (count($left) - 8) . ' more' : '') . '.' : ''));
}

function kop_rinbox_daudit_list(array $q) {
    $decisions = kop_daudit_decisions();
    $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
    $rows = array();
    foreach (kop_daudit_proposals() as $key => $p) {
        if (kop_rinbox_daudit_view($p, $decisions[$key] ?? array()) !== $q['view']) continue;
        if (($q['view'] === 'high' || $q['view'] === 'review') && isset(kop_rinbox_conflict_keys('data-audit')[(string) $key])) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower(($p['title'] ?? '') . ' ' . ($p['place'] ?? '') . ' ' . ($p['finding'] ?? ''));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $key] = $p;
    }
    uksort($rows, function ($x, $y) use ($rows, $rank) {
        return ($rank[$rows[$x]['confidence'] ?? 'low'] ?? 3) <=> ($rank[$rows[$y]['confidence'] ?? 'low'] ?? 3)
            ?: strcasecmp((string) ($rows[$x]['title'] ?? $x), (string) ($rows[$y]['title'] ?? $y));
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $key => $p) $items[] = kop_rinbox_daudit_item((string) $key, $p);
    return array('items' => $items, 'total' => count($rows));
}

/** Names the change lines use: map names, companies, people. */
function kop_rinbox_daudit_names(array $p) {
    $names = array();
    $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
    $nodes = array();
    foreach ((array) ($graph['nodes'] ?? array()) as $n) $nodes[(string) $n['id']] = (string) ($n['name'] ?? $n['id']);
    try {
        $pdo = kop_rinbox_pdo();
        global $wpdb;
        foreach ($p['ops'] as $op) {
            if ($op['type'] === 'map_years' && isset($nodes[$op['node']])) $names['node:' . $op['node']] = $nodes[$op['node']];
            if ($op['type'] === 'operator_link') {
                $st = $pdo->prepare("SELECT name FROM `{$wpdb->prefix}kop_operators` WHERE id = ?");
                $st->execute(array((int) $op['operator_id']));
                $names['op:' . $op['operator_id']] = (string) $st->fetchColumn();
            }
            if ($op['type'] === 'person_merge') {
                foreach (array('keep', 'drop') as $k) {
                    $st = $pdo->prepare("SELECT name FROM `{$wpdb->prefix}kop_people` WHERE id = ?");
                    $st->execute(array((int) $op[$k]));
                    $names['person:' . $op[$k]] = (string) $st->fetchColumn();
                }
            }
        }
    } catch (Throwable $e) {
        // Names are a help only; the ids still show.
    }
    return array_filter($names, 'strlen');
}

function kop_rinbox_daudit_item($key, array $p) {
    $d = kop_daudit_decisions()[$key] ?? array();
    $view = kop_rinbox_daudit_view($p, $d);
    $kinds = array(
        'rename_end_year' => 'Renamed program', 'end_before_items' => 'Closing year', 'status_years' => 'Status',
        'closure_ordered' => 'Closure', 'operator_profile' => 'Operator', 'operator_inherited' => 'Operator after a sale',
        'person_spelling' => 'Same person?', 'person_common' => 'One person or several?', 'memorial' => 'Memorial',
        'transferred' => 'Transferred to a new company',
    );
    $names = kop_rinbox_daudit_names($p);
    $details = array();
    if (!empty($p['kop_now'])) $details[] = array('label' => 'KOP now', 'value' => (string) $p['kop_now']);
    $n = 0;
    foreach ($p['ops'] as $op) {
        $words = kop_daudit_op_words($op, $names);
        if ($words !== '') $details[] = array('label' => 'Change ' . (++$n), 'value' => $words);
    }
    if (!empty($p['notes'])) $details[] = array('label' => 'Note', 'value' => (string) $p['notes']);
    $details[] = array('label' => 'Confidence', 'value' => (string) ($p['confidence'] ?? 'low'));
    if ($view === 'applied' || $view === 'rejected') {
        $details[] = array('label' => $view === 'applied' ? 'Approved' : 'Rejected', 'value' => trim(($d['at'] ?? '') . ' by ' . ($d['by'] ?? '')));
    }
    $details = array_merge($details, kop_rinbox_quote_details((array) ($p['sources'] ?? array())));
    $title = (string) ($p['title'] ?? $key);
    if ($view === 'applied' || $view === 'rejected') {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'applied'
                ? 'Puts back what KOP had before for ' . $title . ' (anything edited since stays); the card waits for review again.'
                : 'Nothing on the site changes; the card waits for review again.'));
    } else {
        $manual = kop_rinbox_daudit_has_manual($p);
        $actions = array(
            array('id' => 'approve', 'label' => $manual ? 'Approve (and do the by-hand step)' : 'Approve these changes', 'style' => 'approve',
                'help' => 'Makes every change listed on ' . $title . ' on the site at once' . ($manual ? '; the by-hand step is yours to do' : '') . '.'),
            array('id' => 'reject', 'label' => 'Keep KOP as it is', 'style' => 'reject',
                'help' => 'Nothing on the site changes; the card moves to Rejected.'),
        );
    }
    $labels = array('high' => 'High confidence', 'review' => 'To review', 'applied' => 'Approved', 'rejected' => 'Rejected');
    return array(
        'key'          => $key,
        'title'        => $title,
        'subtitle'     => implode(' · ', array_filter(array($kinds[$p['kind'] ?? ''] ?? '', (string) ($p['place'] ?? ''), 'confidence: ' . ($p['confidence'] ?? 'low')))),
        'text'         => (string) ($p['finding'] ?? ''),
        'details'      => $details,
        // A 'from' the record no longer holds: Approve would change nothing, so the card says so up front.
        'conflict'     => ($view === 'applied' || $view === 'rejected') ? '' : kop_rinbox_daudit_conflict($p),
        'status'       => $view,
        'status_label' => $labels[$view] ?? $view,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => !empty($p['facility_id']) ? kop_rinbox_facility((int) $p['facility_id']) : null,
        'actions'      => $actions,
    );
}

function kop_rinbox_daudit_act($key, $action, array $params) {
    switch ($action) {
        case 'approve':
            return array('message' => kop_daudit_apply($key, kop_rinbox_reviewer()));
        case 'reject':
            return array('message' => kop_daudit_reject($key, kop_rinbox_reviewer()));
        case 'undo':
            return array('message' => kop_daudit_undo($key));
    }
    throw new RuntimeException('Unknown action.');
}

/** What on the record no longer matches the proposal's 'from' (kop_daudit_check()), as one line, or ''. */
function kop_rinbox_daudit_conflict(array $p) {
    try {
        $why = kop_daudit_check($p, kop_daudit_opts());
    } catch (Throwable $e) {
        return '';
    }
    return $why ? 'The record has changed since this was proposed: ' . implode(' ', $why) : '';
}

/** Waiting proposals whose record moved since the research (and nothing else blocks them), for the Conflicts section. */
function kop_rinbox_daudit_conflicts() {
    try {
        $opts = kop_daudit_opts();
    } catch (Throwable $e) {
        return array();
    }
    $decisions = kop_daudit_decisions();
    $out = array();
    foreach (kop_daudit_proposals() as $key => $p) {
        $v = kop_rinbox_daudit_view($p, $decisions[$key] ?? array());
        if ($v !== 'high' && $v !== 'review') continue;
        try {
            $split = kop_daudit_check_split($p, $opts);
        } catch (Throwable $e) {
            continue;
        }
        if (!$split['changed'] || $split['blocked']) continue;
        $out[(string) $key] = array('text' => 'The record has changed since this was proposed: ' . implode(' ', $split['changed']),
            'record' => '', 'item' => '', 'what' => 'other', 'title' => (string) ($p['title'] ?? $key),
            'facility_id' => (int) ($p['facility_id'] ?? 0), 'editable' => false);
    }
    return $out;
}
