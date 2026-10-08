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
 *
 * Conflicts about the same program and the same kind of value (four issues
 * giving four closing years, eleven staff roles at one school) roll up into
 * one card, "<queue>|@<facility id or p+program hash>.<kind>": each item is a
 * ticked row, and one choice settles every ticked row through the same
 * 'resolve' (Edit writes the typed value through the first and keeps the
 * record for the rest). Its Undo takes back every row it settled.
 *
 * An item about a program with no record (or a record merged away) can also
 * go elsewhere: the queue's own "Create the record and add this" (a program,
 * parent company, consultant, provider or transporter) and "Add to another
 * record" stay on its card, and a roll-up offers both for all ticked rows
 * (the queue's 'create_many'; Undo puts the rows back, the record stays).
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
        'view_counts' => function (array $q) { return array('pending' => count(kop_rinbox_conflicts_units($q))); },
        'count'    => function () { return count(kop_rinbox_conflicts_units(array())); },
        // The queues' own lists leave conflicts out already; nothing more to take away here.
        'on_file_in_list' => true,
        'list'     => function (array $q) {
            $units = kop_rinbox_conflicts_units($q);
            $items = array();
            foreach (array_slice($units, (int) $q['offset'], (int) $q['limit'], true) as $key => $members) {
                $it = count($members) > 1 ? kop_rinbox_conflicts_group_item($key, $members) : kop_rinbox_conflicts_item($key, reset($members));
                if ($it) $items[] = $it;
            }
            return array('items' => $items, 'total' => count($units));
        },
        'get'      => function ($key) {
            list($queue, $k) = kop_rinbox_conflicts_split($key);
            if ($k[0] === '@') return kop_rinbox_conflicts_group_item($key, kop_rinbox_conflicts_group_members($queue, $k));
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

/** The record's name, else the program as the item names it (Woodbury items with no record yet). */
function kop_rinbox_conflicts_facility_name(array $c) {
    static $names = array();
    $fid = (int) ($c['facility_id'] ?? 0);
    if ($fid <= 0) return (string) ($c['program'] ?? '');
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
    $program = $fac ? (string) $fac['name'] : (string) ($c['program'] ?? '');
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
    $it['actions'] = array_merge($actions, kop_rinbox_conflicts_elsewhere($it, (int) ($c['facility_id'] ?? 0)));
    $it['selected'] = false;
    return $it;
}

/**
 * The queue's own ways to put an item somewhere else, kept on a conflict card:
 * create a record for it, add it to another record (picked in the finder),
 * the build's other close names, a young adult program.
 */
function kop_rinbox_conflicts_elsewhere(array $it, $fid) {
    $out = array();
    foreach ((array) ($it['actions'] ?? array()) as $a) {
        $id = (string) ($a['id'] ?? '');
        if (!in_array($id, array('create', 'apply', 'ya_create', 'ya_apply', 'ya_file'), true) && strpos($id, 'apply_to_') !== 0) continue;
        if ($id === 'apply') {
            $a['label'] = 'Add to another record';
            $a['help'] = 'Adds this item to the record you pick instead (the one it names may be the wrong program, or merged away), citing its source.';
            foreach ($a['params'] as $i => $prm) {
                if (($prm['type'] ?? '') === 'facility') $a['params'][$i]['value'] = '';
            }
            $a['ask'] = true;
            $a['submit'] = 'Add it there';
        }
        if ($id === 'create') {
            $a['ask'] = true;
            $a['submit'] = 'Create and add';
        }
        $a['style'] = 'neutral';
        $out[] = $a;
    }
    return $out;
}

function kop_rinbox_conflicts_act($key, $action, array $params) {
    list($queue, $k) = kop_rinbox_conflicts_split($key);
    if ($k[0] === '@') return kop_rinbox_conflicts_group_act($queue, $k, $action, $params);
    $src = kop_rinbox_source($queue);
    $hows = array('resolve_use' => 'use', 'resolve_keep' => 'keep', 'resolve_edit' => 'edit');
    if (isset($hows[$action])) {
        if (empty($src['resolve'])) throw new RuntimeException('That queue cannot settle conflicts.');
        $msg = call_user_func($src['resolve'], $k, $hows[$action], (string) ($params['value'] ?? ''));
        kop_rinbox_flush_counts();
        foreach (kop_rinbox_conflicts_caches() as $t) delete_transient('kop_on_file_' . $t);
        return array('message' => (string) $msg);
    }
    // Undo and anything else: the queue's own action on its own key.
    $res = call_user_func($src['act'], $k, $action, $params);
    kop_rinbox_flush_counts();
    foreach (kop_rinbox_conflicts_caches() as $t) delete_transient('kop_on_file_' . $t);
    return $res;
}

/** The cached lists a settled conflict changes (and the request's own copy, emptied here). */
function kop_rinbox_conflicts_caches() {
    $GLOBALS['kop_rinbox_conflict_memo'] = array();
    return array('wbf_conflicts', 'wbf_conflicts_v2', 'fornits_conflicts', 'wbf', 'fornits');
}

/** Kinds where a record holds one value: several different ones cannot all be used. */
function kop_rinbox_conflicts_single_kinds() {
    return array('dates', 'size', 'type', 'closure');
}

/** Which roll-up a conflict belongs to: "@<facility id>.<kind>", "@p<program hash>.<kind>", or '' (stands alone). */
function kop_rinbox_conflicts_gid(array $c) {
    $fid = (int) ($c['facility_id'] ?? 0);
    $program = mb_strtolower(trim((string) ($c['program'] ?? '')));
    $who = $fid > 0 ? (string) $fid : ($program !== '' ? 'p' . substr(md5($program), 0, 10) : '');
    return $who === '' ? '' : '@' . $who . '.' . ($c['what'] ?? 'other');
}

/**
 * The cards in list order: [card key => [member key => conflict]]. A conflict
 * alone keeps its own key; two or more about one program and kind share a roll-up key.
 */
function kop_rinbox_conflicts_units(array $q) {
    $units = array();
    foreach (kop_rinbox_conflicts_all($q) as $key => $c) {
        list($queue, $k) = explode('|', $key, 2);
        $gid = kop_rinbox_conflicts_gid($c);
        $units[$gid !== '' ? $queue . '|' . $gid : $key][(string) $k] = $c;
    }
    $out = array();
    foreach ($units as $ukey => $members) {
        if (count($members) === 1) {
            $out[explode('|', $ukey, 2)[0] . '|' . (string) array_keys($members)[0]] = $members;
        } else {
            $out[$ukey] = $members;
        }
    }
    return $out;
}

/** The waiting conflicts of one roll-up, [member key => conflict]. */
function kop_rinbox_conflicts_group_members($queue, $gid) {
    $out = array();
    foreach (kop_rinbox_conflict_keys($queue) as $k => $c) {
        if (kop_rinbox_conflicts_gid($c) === $gid) $out[(string) $k] = $c + array('queue' => $queue);
    }
    return $out;
}

/** One card for every conflict about one program and kind: a ticked row each, one choice for all ticked rows. */
function kop_rinbox_conflicts_group_item($key, array $members) {
    list($queue) = kop_rinbox_conflicts_split($key);
    $src = kop_rinbox_sources()[$queue] ?? null;
    if (!$src || empty($src['get']) || !$members) return null;
    $first = reset($members);
    $what = (string) ($first['what'] ?? 'other');
    $kind = kop_rinbox_conflicts_kinds()[$what] ?? 'Other';
    $fid = (int) ($first['facility_id'] ?? 0);
    $fac = $fid > 0 ? kop_rinbox_facility($fid) : null;
    $program = $fac ? (string) $fac['name'] : (string) ($first['program'] ?? '');
    $on = $program !== '' ? $program : 'the record';

    $checklist = array();
    $records = array();
    $editable = true;
    $created = '';
    foreach ($members as $k => $c) {
        $it = call_user_func($src['get'], (string) $k);
        if (!$it || in_array($it['status'] ?? '', array('applied', 'rejected', 'gone'), true)) continue;
        $sub = array();
        if ((string) ($c['record'] ?? '') !== '') $sub[] = 'On the record: ' . $c['record'];
        if ((string) ($c['item'] ?? '') !== '') $sub[] = 'This one says: ' . $c['item'];
        if ((string) ($it['subtitle'] ?? '') !== '') $sub[] = $it['subtitle'];
        $text = (string) ($c['text'] ?? '');
        $checklist[] = array(
            'keys'    => array((string) $k),
            'label'   => (string) (($it['title'] ?? '') ?: ($c['title'] ?? $k)),
            'url'     => (string) ($it['url'] ?? ''),
            'sub'     => implode(' · ', $sub),
            // The text often only repeats "On the record now: ...", already in the line above.
            'note'    => strpos($text, 'On the record now:') === 0 ? '' : $text,
            'checked' => true,
        );
        $records[(string) ($c['record'] ?? '')] = true;
        if (empty($c['editable'])) $editable = false;
        $created = max($created, (string) ($it['created'] ?? ''));
    }
    if (!$checklist) return null;
    $n = count($checklist);
    $shared = count($records) === 1 ? (string) array_keys($records)[0] : '';

    $actions = array(
        array('id' => 'resolve_use', 'label' => 'Use ticked values', 'style' => 'approve',
            'help' => 'Writes each ticked item\'s value over what ' . $on . ' says now, citing its source. Undo in Recently done puts every old value back.'),
        array('id' => 'resolve_keep', 'label' => 'Keep the record', 'style' => 'reject',
            'help' => 'Leaves ' . $on . ' as it is and files every ticked item as rejected. Undo in Recently done brings them all back.'),
    );
    if ($editable && in_array($what, kop_rinbox_conflicts_single_kinds(), true)) {
        $actions[] = array('id' => 'resolve_edit', 'label' => 'Edit…', 'style' => 'neutral', 'ask' => true, 'submit' => 'Write this value',
            'help' => 'When none of them is right: type the value to write to ' . $on . ' (a range as "12 to 18", a year). It cites the first ticked item; '
                . 'the other ticked items are filed as rejected. Undo puts the old value back.',
            'params' => array(array('name' => 'value', 'label' => 'The right value', 'type' => 'text', 'value' => (string) ($first['item'] ?? ''))));
    }

    $actions = array_merge($actions, kop_rinbox_conflicts_group_elsewhere($src, (string) array_keys($members)[0], $on));

    return array(
        'key'            => (string) $key,
        'title'          => ($program !== '' ? $program . ': ' : '') . $kind,
        'subtitle'       => $n . ' items',
        'source_label'   => kop_rinbox_conflicts_queues()[$queue],
        'url'            => '',
        'text'           => 'Untick any row the choice should not apply to; it stays here for later.',
        'created'        => $created,
        'status'         => 'pending',
        'status_label'   => 'Waiting',
        'facility'       => $fac,
        'conflict'       => $n . ' items disagree with what ' . $on . ' says' . ($shared !== '' ? ' (on the record now: ' . $shared . ')' : '') . '.',
        'checklist'      => $checklist,
        'checklist_noun' => 'items',
        'fields'         => array(),
        'moves'          => array(),
        'links'          => array(),
        'details'        => array(),
        'actions'        => $actions,
        'selected'       => false,
    );
}

/** A roll-up card's choice on every ticked row, or its Undo (every row it settled). */
function kop_rinbox_conflicts_group_act($queue, $gid, $action, array $params) {
    $src = kop_rinbox_source($queue);
    $clear = function () {
        kop_rinbox_flush_counts();
        foreach (kop_rinbox_conflicts_caches() as $t) delete_transient('kop_on_file_' . $t);
    };
    if ($action === 'undo_group') {
        $ok = $bad = 0;
        $why = '';
        foreach ((array) ($params['keys'] ?? array()) as $k) {
            try {
                call_user_func($src['act'], (string) $k, 'undo', array());
                $ok++;
            } catch (Throwable $e) {
                $bad++;
                $why = $why ?: $e->getMessage();
            }
        }
        $clear();
        if (!$ok && $bad) throw new RuntimeException('Could not undo: ' . $why);
        return array('message' => 'Undone: ' . $ok . ' item' . ($ok === 1 ? '' : 's') . ' waiting again' . ($bad ? '; ' . $bad . ' could not be taken back (' . $why . ')' : '') . '.');
    }
    $members = kop_rinbox_conflicts_group_members($queue, $gid);
    if (!$members) throw new RuntimeException('Nothing waits on this card any more. Reload the list.');
    $picked = array();
    foreach ((array) ($params['picked'] ?? array()) as $k) {
        if (isset($members[(string) $k])) $picked[] = (string) $k;
    }
    $picked = array_values(array_unique($picked));
    if (!$picked) throw new RuntimeException('Tick at least one item first.');
    $undo_all = function (array $done) { return array('action' => 'undo_group', 'params' => array('keys' => array_values($done))); };

    if ($action === 'create_ticked') {
        if (empty($src['create_many'])) throw new RuntimeException('That queue cannot create records.');
        $made = call_user_func($src['create_many'], $picked, $params);
        $clear();
        $out = array('message' => (string) $made['message']);
        if (!empty($made['done'])) $out['undo'] = $undo_all($made['done']);
        return $out;
    }
    if ($action === 'apply_ticked') {
        $fid = (int) ($params['facility'] ?? 0);
        if ($fid <= 0) throw new RuntimeException('Pick the record first (the Record box beside the button).');
        $done = array();
        $bad = 0;
        $why = '';
        foreach ($picked as $k) {
            try {
                call_user_func($src['act'], $k, 'apply', array('facility' => $fid));
                $done[] = $k;
            } catch (Throwable $e) {
                $bad++;
                $why = $why ?: $e->getMessage();
            }
        }
        $clear();
        if (!$done) throw new RuntimeException('None could be added: ' . $why);
        return array('message' => 'Added ' . count($done) . ' item' . (count($done) === 1 ? '' : 's') . ' to ' . kop_rinbox_facility($fid)['name']
            . ($bad ? '; ' . $bad . ' could not be (' . $why . ')' : '') . '. Undo in Recently done takes ' . (count($done) === 1 ? 'it' : 'them all') . ' back.',
            'undo' => $undo_all($done));
    }

    $hows = array('resolve_use' => 'use', 'resolve_keep' => 'keep', 'resolve_edit' => 'edit');
    if (!isset($hows[$action])) throw new RuntimeException('Unknown action.');
    if (empty($src['resolve'])) throw new RuntimeException('That queue cannot settle conflicts.');
    $how = $hows[$action];

    $what = (string) (reset($members)['what'] ?? 'other');
    if ($how === 'use' && in_array($what, kop_rinbox_conflicts_single_kinds(), true)) {
        $values = array_unique(array_map(function ($k) use ($members) { return (string) ($members[$k]['item'] ?? ''); }, $picked));
        if (count($values) > 1) {
            throw new RuntimeException('The ticked items say different things (' . implode('; ', $values) . ') and the record holds one: tick only the one to use, or Edit to type the right value.');
        }
    }

    $done = array();
    $bad = 0;
    $why = '';
    foreach ($picked as $i => $k) {
        // Edit writes the typed value once, through the first ticked item; the record stands against the rest.
        $h = ($how === 'edit' && $i > 0) ? 'keep' : $how;
        try {
            call_user_func($src['resolve'], $k, $h, $h === 'edit' ? (string) ($params['value'] ?? '') : '');
            $done[] = $k;
        } catch (Throwable $e) {
            if ($how === 'edit' && $i === 0) throw $e;
            $bad++;
            $why = $why ?: $e->getMessage();
        }
    }
    $clear();
    $n = count($done);
    if (!$n) throw new RuntimeException('None could be settled: ' . $why);
    $words = array('use' => 'Wrote ' . $n . ' value' . ($n === 1 ? '' : 's') . ' over the record\'s, each citing its source',
        'keep' => 'Kept the record; ' . $n . ' item' . ($n === 1 ? '' : 's') . ' filed as rejected',
        'edit' => 'Wrote the typed value' . ($n > 1 ? '; the other ' . ($n - 1) . ' filed as rejected' : ''));
    return array(
        'message' => $words[$how] . ($bad ? '; ' . $bad . ' could not be (' . $why . ')' : '') . '. Undo in Recently done takes ' . ($n === 1 ? 'it' : 'them all') . ' back.',
        'undo'    => $undo_all($done),
    );
}

/**
 * A roll-up's "Create the record and add ticked" and "Add ticked to another
 * record", built from the first row's own create and add actions (its name and
 * place start the form). Only queues that can create for several items at once.
 */
function kop_rinbox_conflicts_group_elsewhere(array $src, $first_key, $on) {
    $it = call_user_func($src['get'], $first_key);
    $out = array();
    foreach ((array) ($it['actions'] ?? array()) as $a) {
        if (($a['id'] ?? '') === 'create' && !empty($src['create_many'])) {
            $out[] = array('id' => 'create_ticked', 'label' => 'Create a record for ticked', 'style' => 'neutral', 'ask' => true, 'submit' => 'Create and add',
                'confirm' => 'Create a new record with this name and add every ticked item to it?',
                'help' => 'When ' . $on . ' is not the right record, or there is none: makes a new record (a program, parent company, consultant, provider or transporter) '
                    . 'with the name and place below and adds every ticked item to it. Undo in Recently done puts the items back; the record stays.',
                'params' => (array) ($a['params'] ?? array()));
        }
        if (($a['id'] ?? '') === 'apply') {
            $out[] = array('id' => 'apply_ticked', 'label' => 'Add ticked to another record', 'style' => 'neutral', 'ask' => true, 'submit' => 'Add them there',
                'help' => 'Adds every ticked item to the record you pick instead of ' . $on . ', citing each one\'s source. Undo in Recently done takes them all back.',
                'params' => array(array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => '')));
        }
    }
    return $out;
}
