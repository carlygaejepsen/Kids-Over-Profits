<?php
/**
 * Review inbox source: every waiting item that disagrees with its record, in
 * one place to settle. Woodbury Facts, Fornits and Data audit name theirs
 * through 'conflicts' (and leave them out of their own waiting lists); each
 * card shows what the record says beside what the item says and offers:
 *
 *   Keep the record   the item is filed as rejected, the record unchanged
 *   Use this value    the item's value is written over the record's, citing
 *                     its source (Woodbury/Fornits: kop_wbf_doc_overwrite();
 *                     Data audit: approved over the changed 'from')
 *   Edit              a value the reviewer types is written instead
 *
 * Each goes through the queue's own 'resolve', and Undo is the queue's own
 * (the card's 'undo' after a choice), so Recently done takes it back exactly.
 * Keys are "<queue>|<key>". Filters: which queue, what kind of value.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('conflicts', function () {
    return array(
        'label'    => 'Conflicts',
        'group'    => 'Conflicts to settle',
        'help'     => 'Items that disagree with what the record already says: another year, size or type, another role for the same person, a closure on a record marked open, '
            . 'or a correction researched before someone changed the record. Each card shows both. "Keep the record" leaves the record as it is; '
            . '"Use this value" writes the item\'s value over it, citing the item\'s source; "Edit" writes a value you type. Undo takes any of them back exactly.',
        'views'    => array('pending' => 'To settle'),
        'filters'  => array(
            array('name' => 'from', 'label' => 'From', 'options' => kop_rinbox_conflicts_queues()),
            array('name' => 'what', 'label' => 'Kind', 'options' => kop_rinbox_conflicts_kinds()),
        ),
        'view_counts' => function (array $q) { return array('pending' => count(kop_rinbox_conflicts_all($q))); },
        'count'    => function () { return count(kop_rinbox_conflicts_all(array())); },
        // The queues' own lists leave conflicts out already; nothing more to take away here.
        'on_file_in_list' => true,
        'list'     => function (array $q) {
            $all = kop_rinbox_conflicts_all($q);
            $items = array();
            foreach (array_slice($all, (int) $q['offset'], (int) $q['limit'], true) as $key => $c) {
                $it = kop_rinbox_conflicts_item($key, $c);
                if ($it) $items[] = $it;
            }
            return array('items' => $items, 'total' => count($all));
        },
        'get'      => function ($key) {
            list($queue, $k) = kop_rinbox_conflicts_split($key);
            return kop_rinbox_conflicts_item($key, kop_rinbox_conflict_keys($queue)[$k] ?? null);
        },
        'act'      => 'kop_rinbox_conflicts_act',
    );
});

/** The queues that name conflicts, [key => label]. */
function kop_rinbox_conflicts_queues() {
    $out = array();
    foreach (array('woodbury-facts' => 'Woodbury Facts', 'fornits' => 'Fornits', 'data-audit' => 'Data audit') as $k => $label) {
        if (isset($GLOBALS['kop_rinbox_builders'][$k])) $out[$k] = $label;
    }
    return $out;
}

function kop_rinbox_conflicts_kinds() {
    return array('dates' => 'Years and dates', 'size' => 'Size and ages', 'type' => 'Type', 'staff' => 'Staff roles', 'closure' => 'Closures', 'other' => 'Other');
}

/** "woodbury-facts|abc" -> ['woodbury-facts', 'abc'], only a queue that names conflicts. */
function kop_rinbox_conflicts_split($key) {
    $parts = explode('|', (string) $key, 2);
    if (count($parts) !== 2 || !isset(kop_rinbox_conflicts_queues()[$parts[0]])) throw new RuntimeException('Unknown conflict.');
    return $parts;
}

/** Every waiting conflict under the filters and search, [queue|key => info + queue], one program's together. */
function kop_rinbox_conflicts_all(array $q) {
    $from = (string) ($q['filters']['from'] ?? '');
    $what = (string) ($q['filters']['what'] ?? '');
    $search = mb_strtolower(trim((string) ($q['search'] ?? '')));
    $all = array();
    foreach (array_keys(kop_rinbox_conflicts_queues()) as $queue) {
        if ($from !== '' && $from !== $queue) continue;
        foreach (kop_rinbox_conflict_keys($queue) as $k => $c) {
            if ($what !== '' && ($c['what'] ?? 'other') !== $what) continue;
            if ($search !== '' && mb_strpos(mb_strtolower(($c['title'] ?? '') . ' ' . ($c['text'] ?? '') . ' ' . kop_rinbox_conflicts_facility_name($c)), $search) === false) continue;
            $all[$queue . '|' . $k] = $c + array('queue' => $queue);
        }
    }
    uasort($all, function ($a, $b) {
        return strcasecmp(kop_rinbox_conflicts_facility_name($a), kop_rinbox_conflicts_facility_name($b))
            ?: strcmp((string) $a['queue'], (string) $b['queue']) ?: strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    return $all;
}

function kop_rinbox_conflicts_facility_name(array $c) {
    static $names = array();
    $fid = (int) ($c['facility_id'] ?? 0);
    if ($fid <= 0) return '';
    if (!isset($names[$fid])) {
        $f = function_exists('kop_on_file_doc') ? kop_on_file_doc($fid) : null;
        $names[$fid] = $f ? (string) $f['name'] : '';
    }
    return $names[$fid];
}

/**
 * The queue's own card with both sides and the three choices; once settled,
 * the queue's card as it is (with its Undo), so Recently done can take it back.
 */
function kop_rinbox_conflicts_item($key, $c) {
    list($queue, $k) = kop_rinbox_conflicts_split($key);
    $src = kop_rinbox_sources()[$queue] ?? null;
    if (!$src || empty($src['get'])) return null;
    $it = call_user_func($src['get'], $k);
    if (!$it) return null;
    $it['key'] = (string) $key;
    $it['source_label'] = kop_rinbox_conflicts_queues()[$queue];
    $it['fields'] = array();
    $it['moves'] = array();
    // The program leads the card: the queues' own titles ("Opened 1998", "Staff: ...") never name it.
    $fac = $it['facility'] ?? (($c['facility_id'] ?? 0) > 0 ? kop_rinbox_facility((int) $c['facility_id']) : null);
    $program = $fac ? (string) $fac['name'] : '';
    if ($program !== '' && mb_stripos((string) ($it['title'] ?? ''), kop_rinbox_conflicts_facility_name((array) $c) ?: $program) === false) {
        $it['title'] = $program . ': ' . ($it['title'] ?? '');
    }
    if (!$c || in_array($it['status'] ?? '', array('applied', 'rejected', 'gone'), true)) {
        // Settled (or no longer in conflict): only the queue's Undo is offered.
        $it['actions'] = array_values(array_filter((array) ($it['actions'] ?? array()), function ($a) { return ($a['style'] ?? '') === 'undo'; }));
        $it['conflict'] = '';
        return $it;
    }
    $it['conflict'] = (string) $c['text'];
    if (($c['record'] ?? '') !== '' || ($c['item'] ?? '') !== '') {
        $it['compare'] = array('heads' => array($program !== '' ? 'On ' . $program . ' now' : 'On the record now', 'This item says'),
            'rows' => array(array('label' => kop_rinbox_conflicts_kinds()[$c['what'] ?? 'other'] ?? 'Value', 'values' => array((string) $c['record'], (string) $c['item']), 'differs' => true)));
    }
    $fname = kop_rinbox_conflicts_facility_name($c);
    $on = $fname !== '' ? $fname : 'the record';
    $actions = array(
        array('id' => 'resolve_use', 'label' => 'Use this value', 'style' => 'approve',
            'help' => 'Writes this item\'s value over what ' . $on . ' says now, citing the item\'s source. Undo puts the old value back.'),
        array('id' => 'resolve_keep', 'label' => 'Keep the record', 'style' => 'reject',
            'help' => 'Leaves ' . $on . ' as it is and files this item as rejected. Undo brings the card back.'),
    );
    if (!empty($c['editable'])) {
        $actions[] = array('id' => 'resolve_edit', 'label' => 'Edit…', 'style' => 'neutral', 'ask' => true, 'submit' => 'Write this value',
            'help' => 'When neither side is right: type the value to write to ' . $on . ' (a range as "12 to 18", a year, a role). Undo puts the old value back.',
            'params' => array(array('name' => 'value', 'label' => 'The right value', 'type' => 'text', 'value' => (string) ($c['item'] ?? ''))));
    }
    $it['actions'] = $actions;
    $it['selected'] = false;
    return $it;
}

function kop_rinbox_conflicts_act($key, $action, array $params) {
    list($queue, $k) = kop_rinbox_conflicts_split($key);
    $src = kop_rinbox_source($queue);
    $hows = array('resolve_use' => 'use', 'resolve_keep' => 'keep', 'resolve_edit' => 'edit');
    if (isset($hows[$action])) {
        if (empty($src['resolve'])) throw new RuntimeException('That queue cannot settle conflicts.');
        $msg = call_user_func($src['resolve'], $k, $hows[$action], (string) ($params['value'] ?? ''));
        kop_rinbox_flush_counts();
        foreach (array('wbf_conflicts', 'fornits_conflicts', 'wbf', 'fornits') as $t) delete_transient('kop_on_file_' . $t);
        return array('message' => (string) $msg);
    }
    // Undo and anything else: the queue's own action on its own key.
    $res = call_user_func($src['act'], $k, $action, $params);
    kop_rinbox_flush_counts();
    return $res;
}
