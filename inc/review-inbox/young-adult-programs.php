<?php
/**
 * Review inbox source: young adult programs (18+, inc/young-adult-programs.php,
 * screen in inc/young-adult-programs-admin.php). Programs kept off the public
 * page (review = 'pending', the old screen's "Hide it" box) wait here; Show
 * lists one on /young-adult-programs/. Everything the old screen does is here
 * too: edit every field (kop_ya_save()), take a fact off (kop_ya_drop_fact(),
 * its Woodbury item goes back for review through kop_ya_admin_release_items()),
 * delete a program (kop_ya_delete(), its items go back too) and add one by hand.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('young-adult-programs', function () {
    if (!function_exists('kop_ya_save') || !function_exists('kop_ya_admin_release_items')) return null;
    return array(
        'label'    => 'Young adult programs',
        'group'    => 'Imports to review',
        'help'     => 'Programs for people 18 and older, kept apart from the troubled teen programs: never facility records, and on no facility page, map, hub or search. Hidden ones wait here; Show lists one on the Young Adult Programs page. Most are added from the Young adult programs (18+) tab of Woodbury Facts.',
        'views'    => array('hidden' => 'Hidden from the page', 'listed' => 'On the page'),
        'view_counts' => function (array $q = array()) {
            $out = array('hidden' => 0, 'listed' => 0);
            foreach (kop_rinbox_ya_pdo()->query('SELECT review, COUNT(*) AS n FROM young_adult_programs GROUP BY review') as $r) {
                $out[$r['review'] === 'pending' ? 'hidden' : 'listed'] += (int) $r['n'];
            }
            return $out;
        },
        'tool_url' => admin_url('admin.php?page=kop-young-adult-programs'),
        'tools'    => array(array('id' => 'add', 'label' => 'Add a program', 'style' => 'neutral',
            'help' => 'Makes a new program record, listed on the page. Fill in the rest with Edit details on its card.',
            'params' => array(
                array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => ''),
                array('name' => 'city', 'label' => 'Town or city', 'type' => 'text', 'value' => '', 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => '', 'optional' => true),
                array('name' => 'hidden', 'label' => 'Keep it off the page for now', 'type' => 'checkbox', 'value' => '', 'optional' => true),
            ))),
        'tool'     => 'kop_rinbox_ya_tool',
        'count'    => function () {
            $pdo = kop_ya_pdo();
            if (!$pdo || !kop_ya_ready($pdo)) return 0;
            return (int) $pdo->query("SELECT COUNT(*) FROM young_adult_programs WHERE review = 'pending'")->fetchColumn();
        },
        'list'     => 'kop_rinbox_ya_list',
        'get'      => function ($key) {
            $p = kop_ya_get(kop_rinbox_ya_pdo(), (int) $key);
            return $p ? kop_rinbox_ya_item($p) : null;
        },
        'act'      => 'kop_rinbox_ya_act',
        'save'     => 'kop_rinbox_ya_save',
    );
});

function kop_rinbox_ya_pdo() {
    $pdo = kop_ya_pdo();
    if (!$pdo) throw new RuntimeException('The records database is not reachable.');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    kop_ya_install($pdo);
    return $pdo;
}

/** The columns kop_ya_save() writes. */
function kop_rinbox_ya_columns() {
    return array('name', 'other_names', 'city', 'state', 'country', 'ages', 'program_type', 'run_by', 'opened', 'closed', 'status', 'notes', 'links');
}

function kop_rinbox_ya_list(array $q) {
    $pdo = kop_rinbox_ya_pdo();
    $where = 'review ' . ($q['view'] === 'listed' ? "<> 'pending'" : "= 'pending'");
    $params = array();
    if ($q['search'] !== '') {
        $where .= ' AND (name LIKE ? OR other_names LIKE ? OR state LIKE ? OR city LIKE ? OR run_by LIKE ?)';
        $like = '%' . $q['search'] . '%';
        $params = array($like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM young_adult_programs WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM young_adult_programs WHERE $where ORDER BY name LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($params);
    return array('items' => array_map('kop_rinbox_ya_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

function kop_rinbox_ya_item(array $p) {
    $hidden = $p['review'] === 'pending';
    $groups = kop_ya_fact_groups();
    $facts = kop_ya_facts($p);
    $lines = array();
    $options = array();
    $links = array();
    foreach ($facts as $f) {
        $text = kop_ya_fact_text($f['label'] ?? '');
        $lines[] = ($groups[$f['group'] ?? ''] ?? ($f['group'] ?? '')) . ': ' . $text;
        if (($f['key'] ?? '') !== '') $options[(string) $f['key']] = mb_substr($text, 0, 90);
        foreach (array_slice((array) ($f['cites'] ?? array()), 0, 1) as $c) {
            if (!empty($c['url']) && count($links) < 8) $links[] = array('label' => 'Woodbury Reports, ' . $c['label'] . ', p. ' . (int) $c['page'], 'url' => $c['url']);
        }
    }
    $actions = array($hidden
        ? array('id' => 'show', 'label' => 'Show on the page', 'style' => 'approve')
        : array('id' => 'hide', 'label' => 'Hide from the page', 'style' => 'undo'));
    if ($options) {
        $actions[] = array('id' => 'drop_fact', 'label' => 'Take this fact off', 'style' => 'undo',
            'params' => array(array('name' => 'key', 'label' => 'Fact', 'type' => 'select', 'options' => $options, 'value' => '')));
    }
    $actions[] = array('id' => 'delete', 'label' => 'Delete', 'style' => 'reject',
        'confirm' => 'Delete this program? Its Woodbury items go back for review.');
    $nil = function ($v) { return $v === null ? '' : (string) $v; };
    return array(
        'key'          => (string) $p['id'],
        'title'        => (string) $p['name'],
        'subtitle'     => kop_ya_meta_line($p),
        'text'         => trim(($p['source'] !== '' ? 'From: ' . $p['source'] . "\n" : '') . kop_rinbox_excerpt((string) $p['notes'], 400)),
        'created'      => (string) $p['created_at'],
        'status'       => $hidden ? 'hidden' : 'listed',
        'status_label' => $hidden ? 'Hidden' : 'On the page',
        'details'      => array(array('label' => 'Facts (' . count($facts) . ')', 'value' => $lines ? implode('; ', $lines) : 'None yet. Add them from the Young adult programs (18+) tab of Woodbury Facts.')),
        'fields'       => array(
            array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => (string) $p['name']),
            array('name' => 'other_names', 'label' => 'Other names (earlier names, other spellings)', 'type' => 'list', 'value' => kop_ya_lines($p['other_names'])),
            array('name' => 'city', 'label' => 'Town or city', 'type' => 'text', 'value' => $nil($p['city'])),
            array('name' => 'state', 'label' => 'State (e.g. UT or Utah)', 'type' => 'text', 'value' => $nil($p['state'])),
            array('name' => 'country', 'label' => 'Country (only outside the US)', 'type' => 'text', 'value' => $nil($p['country'])),
            array('name' => 'ages', 'label' => 'Ages (e.g. 18-26)', 'type' => 'text', 'value' => $nil($p['ages'])),
            array('name' => 'program_type', 'label' => 'Described as', 'type' => 'text', 'value' => $nil($p['program_type'])),
            array('name' => 'run_by', 'label' => 'Run by', 'type' => 'text', 'value' => $nil($p['run_by'])),
            array('name' => 'opened', 'label' => 'Opened (year)', 'type' => 'number', 'value' => $nil($p['opened'])),
            array('name' => 'closed', 'label' => 'Closed (year)', 'type' => 'number', 'value' => $nil($p['closed'])),
            array('name' => 'status', 'label' => 'Still open?', 'type' => 'select', 'options' => array('Unknown' => 'Not known', 'Open' => 'Still open', 'Closed' => 'Closed'),
                'value' => (string) ($p['status'] ?: 'Unknown'), 'category' => true),
            array('name' => 'notes', 'label' => 'Notes (a blank line starts a new paragraph; **bold**, *italic* and [link text](https://...) work)', 'type' => 'textarea', 'value' => $nil($p['notes'])),
            array('name' => 'links', 'label' => 'Other links (What it is | https://address)', 'type' => 'list', 'value' => kop_ya_lines($p['links'])),
        ),
        'actions'      => $actions,
        'links'        => array_merge($links, array(array('label' => 'Woodbury Facts: Young adult programs tab', 'url' => admin_url('admin.php?page=kop-woodbury-facts&wbf_tab=youngadult')))),
    );
}

/** kop_ya_save() writes every column, so start from what is stored. */
function kop_rinbox_ya_write(PDO $pdo, array $p, array $fields, $review = null) {
    $f = array();
    foreach (kop_rinbox_ya_columns() as $k) {
        $v = array_key_exists($k, $fields) ? $fields[$k] : $p[$k];
        $f[$k] = is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v;
    }
    $f['review'] = $review ?? $p['review'];
    return kop_ya_save($pdo, $f, (int) $p['id'], kop_rinbox_reviewer());
}

function kop_rinbox_ya_act($key, $action, array $params) {
    $pdo = kop_rinbox_ya_pdo();
    $id = (int) $key;
    $p = kop_ya_get($pdo, $id);
    if (!$p) throw new RuntimeException('That program is gone (someone may have deleted it).');
    switch ($action) {
        case 'show':
        case 'hide':
            kop_rinbox_ya_write($pdo, $p, array(), $action === 'show' ? 'approved' : 'pending');
            kop_rinbox_flush_counts();
            return array('message' => $action === 'show'
                ? '"' . $p['name'] . '" is on the Young Adult Programs page now. Hide it again from the On the page tab.'
                : '"' . $p['name'] . '" is off the page and waits on the Hidden tab.');
        case 'drop_fact':
            $fkey = (string) ($params['key'] ?? '');
            if ($fkey === '') throw new RuntimeException('Pick the fact to take off.');
            $gone = kop_ya_drop_fact($pdo, $id, $fkey);
            $back = $gone ? kop_ya_admin_release_items($id, $fkey) : 0;
            return array('message' => $gone ? 'Taken off.' . ($back ? ' Its item is back for review on Woodbury Facts.' : '') : 'That fact was not there.');
        case 'delete':
            $back = kop_ya_admin_release_items($id);
            kop_ya_delete($pdo, $id);
            kop_rinbox_flush_counts();
            return array('message' => 'Deleted "' . $p['name'] . '".' . ($back ? ' ' . $back . ' Woodbury items are back for review.' : ''));
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_ya_save($key, array $fields) {
    $pdo = kop_rinbox_ya_pdo();
    $p = kop_ya_get($pdo, (int) $key);
    if (!$p) throw new RuntimeException('That program is gone.');
    foreach (array('opened', 'closed') as $k) {
        $y = array_key_exists($k, $fields) ? trim((string) $fields[$k]) : '';
        if ($y !== '' && (!ctype_digit($y) || (int) $y < 1850 || (int) $y > (int) gmdate('Y') + 1)) {
            throw new RuntimeException(ucfirst($k) . ': write a year between 1850 and ' . ((int) gmdate('Y') + 1) . ', or leave it empty.');
        }
    }
    if (array_key_exists('status', $fields) && !in_array($fields['status'], array('Open', 'Closed', 'Unknown'), true)) {
        throw new RuntimeException('Still open?: pick Not known, Still open or Closed.');
    }
    kop_rinbox_ya_write($pdo, $p, $fields);
    return array('message' => $p['review'] === 'pending' ? 'Saved.' : 'Saved. The page shows the change now.');
}

/** The old screen's "Add a program". */
function kop_rinbox_ya_tool($id, array $params) {
    if ($id !== 'add') throw new RuntimeException('Unknown tool.');
    $pdo = kop_rinbox_ya_pdo();
    $f = array('review' => !empty($params['hidden']) ? 'pending' : 'approved');
    foreach (array('name', 'city', 'state') as $k) $f[$k] = (string) ($params[$k] ?? '');
    $yid = kop_ya_save($pdo, $f, 0, kop_rinbox_reviewer());
    return array('message' => 'Program added' . ($f['review'] === 'pending' ? ' and kept off the page (Hidden tab).' : ' and listed on the page (On the page tab).'), 'key' => (string) $yid);
}
