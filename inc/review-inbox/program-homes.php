<?php
/**
 * Review inbox source: records named "Program – Home" in one state that look
 * like the licensed homes of one program (KOP Tools > Program Homes,
 * inc/program-homes.php). Group runs kop_program_homes_group(), Undo
 * kop_program_homes_undo(), "Not one program" kop_program_homes_set_dismissed(),
 * "Take a home out" kop_program_homes_remove_home(). "Group under this record"
 * groups them under any record found with the facility finder.
 *
 * The program's name and each home's name can be edited before grouping:
 * save keeps the edits in the option kop_rinbox_program_homes_edits, keyed by
 * the suggestion's key, and Group uses them (a home whose name is cleared is
 * left out). The edits are dropped once the group is made.
 *
 * Keys: the suggestion's key ("newport academy|CA", also on the Not one
 * program tab), "program:<id>" for a program on the Grouped tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_PH_EDITS = 'kop_rinbox_program_homes_edits';

kop_rinbox_register('program-homes', function () {
    if (!function_exists('kop_program_homes_suggestions')) return null;
    return array(
        'label'    => 'Program homes',
        'group'    => 'Suggestions to check',
        'help'     => 'Records named like "Program – Home" in one state, which may be the separate licensed homes of one program. Group puts them under one program record: its page then lists every home with their news and findings. Clear a home\'s name to leave it out. Undo is on the Grouped tab.',
        'views'    => array('suggested' => 'Suggested', 'grouped' => 'Grouped', 'dismissed' => 'Not one program'),
        'tool_url' => admin_url('admin.php?page=kop-program-homes'),
        'count'    => function () {
            return count(kop_program_homes_suggestions());
        },
        'view_counts' => function () {
            return array('suggested' => count(kop_program_homes_suggestions()), 'grouped' => count(kop_program_homes_map(true)['programs']),
                'dismissed' => count(kop_program_homes_dismissed()));
        },
        'list'     => 'kop_rinbox_ph_list',
        'get'      => 'kop_rinbox_ph_get',
        'act'      => 'kop_rinbox_ph_act',
        'save'     => 'kop_rinbox_ph_save',
        // The names come from the records; there is nothing for AI to look up.
        'ai_fill'  => function ($key) {
            return array('filled' => array(), 'message' => 'Nothing here for AI to fill: the names come from the records themselves.');
        },
    );
});

function kop_rinbox_ph_edits() {
    $v = get_option(KOP_RINBOX_PH_EDITS, array());
    return is_array($v) ? $v : array();
}

function kop_rinbox_ph_list(array $q) {
    if (function_exists('kop_program_homes_install')) kop_program_homes_install();
    $items = array();
    if ($q['view'] === 'grouped') {
        $map = kop_program_homes_map(true);
        foreach (array_keys($map['programs']) as $pid) {
            if ($it = kop_rinbox_ph_group_item((int) $pid)) $items[] = $it;
        }
        usort($items, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
    } elseif ($q['view'] === 'dismissed') {
        foreach (kop_program_homes_dismissed() as $key => $when) $items[] = kop_rinbox_ph_dismissed_item((string) $key, $when);
    } else {
        foreach (kop_program_homes_suggestions() as $s) $items[] = kop_rinbox_ph_item($s);
    }
    if ($q['search'] !== '') {
        $needle = mb_strtolower($q['search']);
        $items = array_values(array_filter($items, function ($it) use ($needle) {
            return mb_strpos(mb_strtolower($it['title'] . ' ' . $it['subtitle'] . ' ' . $it['text'] . ' ' . ($it['search_text'] ?? '')), $needle) !== false;
        }));
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_ph_suggestion($key) {
    foreach (kop_program_homes_suggestions() as $s) if ($s['key'] === $key) return $s;
    return null;
}

function kop_rinbox_ph_get($key) {
    if (preg_match('/^program:(\d+)$/', $key, $m)) return kop_rinbox_ph_group_item((int) $m[1]);
    $s = kop_rinbox_ph_suggestion($key);
    if ($s) return kop_rinbox_ph_item($s);
    $dis = kop_program_homes_dismissed();
    return isset($dis[$key]) ? kop_rinbox_ph_dismissed_item($key, $dis[$key]) : null;
}

/** A suggestion with the owner's saved edits over it: [program name, home id => home name]. */
function kop_rinbox_ph_names(array $s) {
    $edit = kop_rinbox_ph_edits()[$s['key']] ?? array();
    $name = isset($edit['program_name']) && trim($edit['program_name']) !== '' ? trim($edit['program_name']) : $s['program_name'];
    $homes = array();
    foreach ($s['homes'] as $h) {
        $homes[(int) $h['id']] = array_key_exists((string) $h['id'], (array) ($edit['homes'] ?? array()))
            ? trim((string) $edit['homes'][(string) $h['id']]) : (string) $h['home_name'];
    }
    return array($name, $homes);
}

/** A state code as its name ("CA" -> "California"). */
function kop_rinbox_ph_state_name($code) {
    $name = $code !== '' && function_exists('kop_state_canonical_name') ? (string) kop_state_canonical_name($code) : '';
    return $name !== '' ? $name : (string) $code;
}

function kop_rinbox_ph_item(array $s) {
    list($name, $names) = kop_rinbox_ph_names($s);
    $state = kop_rinbox_ph_state_name($s['state']);
    // Every home in a table: the name it gets on the program's page, its town, status and company.
    $rows = array();
    $search = array();
    foreach ($s['homes'] as $h) {
        $left = $names[(int) $h['id']] === '';
        $rows[] = array('label' => $h['name'] . ' (#' . $h['id'] . ')', 'differs' => $left,
            'values' => array($left ? 'left out' : $names[(int) $h['id']], $h['city'], $h['status'], $h['operator']));
        $search[] = $h['name'];
    }
    $compare = array('heads' => array('Name on the program page', 'Town', 'Status', 'Company'), 'rows' => $rows);
    $text = count($s['homes']) . ' records in ' . $state . ' named "' . $s['program_name'] . ' - ...". Group ties each one to one program record; each keeps its own record, licence and inspection reports.';
    $details = array();
    if ($s['existing']) $details[] = array('label' => 'Already on file', 'value' => 'A record named just "' . $s['existing']['name'] . '" (#' . $s['existing']['id'] . ') is in this state: it can be the program.');
    if ($s['operator'] !== '') $details[] = array('label' => 'Company', 'value' => $s['operator']);
    foreach ($s['warnings'] as $w) $details[] = array('label' => 'Check', 'value' => $w);

    $fields = array(array('name' => 'program_name', 'label' => $s['existing'] ? 'Program name (for a new record; the existing one keeps its name)' : 'Program name', 'type' => 'text', 'value' => $name));
    foreach ($s['homes'] as $h) {
        $fields[] = array('name' => 'home_' . (int) $h['id'], 'label' => 'Home name for ' . $h['name'] . ' (#' . $h['id'] . '); empty leaves it out',
            'type' => 'text', 'value' => $names[(int) $h['id']]);
    }
    $choices = array();
    if ($s['existing']) $choices[(string) $s['existing']['id']] = 'Use the existing record #' . $s['existing']['id'] . ' ' . $s['existing']['name'];
    $choices['new'] = 'Make a new program record named "' . $name . '"';
    $links = array();
    foreach ($s['homes'] as $h) {
        if (count($links) >= 8) break;
        $f = kop_rinbox_facility($h['id']);
        if ($f && $f['url'] !== '') $links[] = array('label' => $h['name'], 'url' => $f['url']);
    }
    return array(
        'key'          => (string) $s['key'],
        'title'        => $name . ', ' . $state,
        'subtitle'     => count($s['homes']) . ' homes' . ($s['operator'] !== '' ? ' · ' . $s['operator'] : '') . ($s['warnings'] ? ' · check the note' : ''),
        'text'         => $text,
        'details'      => $details,
        'compare'      => $compare,
        'search_text'  => implode(' ', $search),
        'status'       => 'suggested',
        'status_label' => 'Suggested',
        'facility'     => $s['existing'] ? kop_rinbox_facility($s['existing']['id']) : kop_rinbox_facility($s['homes'][0]['id']),
        'fields'       => $fields,
        'links'        => $links,
        'actions'      => array(
            array('id' => 'group', 'label' => 'Group under one program', 'style' => 'approve',
                'params' => array(array('name' => 'program', 'label' => 'Program record', 'type' => 'select',
                    'value' => $s['existing'] ? (string) $s['existing']['id'] : 'new', 'options' => $choices))),
            array('id' => 'group_other', 'label' => 'Group under this record', 'style' => 'neutral',
                'params' => array(array('name' => 'program_id', 'label' => 'Or another program record', 'type' => 'facility', 'value' => ''))),
            array('id' => 'dismiss', 'label' => 'Not one program', 'style' => 'reject'),
        ),
    );
}

function kop_rinbox_ph_group_item($pid) {
    global $wpdb;
    $homes = kop_program_homes_home_rows(kop_program_homes_homes_of($pid));
    if (!$homes) return null;
    $name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $pid));
    $made = (int) $wpdb->get_var($wpdb->prepare('SELECT created_record FROM ' . kop_program_homes_table('groups') . ' WHERE program_id = %d', $pid));
    $rows = array();
    $options = array();
    $links = array();
    foreach ($homes as $h) {
        $rows[] = array('label' => $h['home_name'] . ' (#' . $h['id'] . ')', 'differs' => false, 'values' => array($h['place'], $h['status']));
        $options[(string) $h['id']] = $h['home_name'] . ' (#' . $h['id'] . ')';
        $hf = count($links) < 8 ? kop_rinbox_facility($h['id']) : null;
        if ($hf && $hf['url'] !== '') $links[] = array('label' => $h['home_name'], 'url' => $hf['url']);
    }
    $f = kop_rinbox_facility($pid);
    return array(
        'key'          => 'program:' . $pid,
        'title'        => ($name !== '' ? $name : 'Program #' . $pid),
        'subtitle'     => count($homes) . ' homes · program record #' . $pid . ($made ? ' (made by this review)' : ''),
        'text'         => 'The program\'s page lists these homes with their news, lawsuits and serious findings; each home\'s page names the program.',
        'compare'      => array('heads' => array('Place', 'Status'), 'rows' => $rows),
        'search_text'  => implode(' ', array_column($rows, 'label')),
        'links'        => $links,
        'status'       => 'grouped',
        'status_label' => 'Grouped',
        'facility'     => $f,
        'url'          => $f ? $f['url'] : '',
        'actions'      => array(
            array('id' => 'undo', 'label' => 'Undo the grouping', 'style' => 'undo',
                'confirm' => 'Untie every home from this program?' . ($made ? ' The program record this review made is deleted if nothing has been linked to it or changed on it since.' : '')),
            array('id' => 'remove_home', 'label' => 'Take one home out', 'style' => 'neutral',
                'params' => array(array('name' => 'home_id', 'label' => 'Home', 'type' => 'select', 'value' => (string) $homes[0]['id'], 'options' => $options))),
        ),
    );
}

function kop_rinbox_ph_dismissed_item($key, $when) {
    list($base, $state) = array_pad(explode('|', $key, 2), 2, '');
    return array(
        'key'          => $key,
        'title'        => ucwords($base) . ($state !== '' ? ' (' . $state . ')' : ''),
        'subtitle'     => 'Marked not one program' . (is_numeric($when) ? ' on ' . gmdate('Y-m-d', (int) $when) : ''),
        'text'         => 'Records named "' . ucwords($base) . ' – ..." in ' . ($state !== '' ? $state : 'this state') . ' are not offered as one program.',
        'status'       => 'dismissed',
        'status_label' => 'Not one program',
        'actions'      => array(array('id' => 'undismiss', 'label' => 'Back in the suggestions', 'style' => 'neutral')),
    );
}

function kop_rinbox_ph_save($key, array $fields) {
    $s = kop_rinbox_ph_suggestion($key);
    if (!$s) throw new RuntimeException('Only a suggestion that is not grouped yet can be edited.');
    $all = kop_rinbox_ph_edits();
    $edit = $all[$key] ?? array('program_name' => '', 'homes' => array());
    if (array_key_exists('program_name', $fields)) {
        $name = trim(sanitize_text_field((string) $fields['program_name']));
        if ($name === '') throw new RuntimeException('The program needs a name.');
        $edit['program_name'] = $name;
    }
    $ids = array();
    foreach ($s['homes'] as $h) $ids[(int) $h['id']] = true;
    foreach ($fields as $name => $value) {
        if (!preg_match('/^home_(\d+)$/', (string) $name, $m) || !isset($ids[(int) $m[1]])) continue;
        $edit['homes'][(string) $m[1]] = mb_substr(trim(sanitize_text_field((string) $value)), 0, 250);
    }
    $all[$key] = $edit;
    update_option(KOP_RINBOX_PH_EDITS, $all, false);
    return array('message' => 'Saved. Group uses these names.');
}

function kop_rinbox_ph_act($key, $action, array $params) {
    if (function_exists('kop_program_homes_install')) kop_program_homes_install();
    if (preg_match('/^program:(\d+)$/', $key, $m)) {
        $pid = (int) $m[1];
        if ($action === 'undo') {
            $r = kop_program_homes_undo($pid);
            return array('message' => $r === 'removed'
                ? 'Undone. The homes are on their own again and the program record this review made is deleted.'
                : 'Undone. The homes are on their own again; the program record stays (it was there before, or has been linked or edited since).');
        }
        if ($action === 'remove_home') {
            $hid = (int) ($params['home_id'] ?? 0);
            if (!in_array($hid, array_map('intval', kop_program_homes_homes_of($pid)), true)) throw new RuntimeException('Pick one of this program\'s homes.');
            kop_program_homes_remove_home($hid);
            return array('message' => 'Home #' . $hid . ' is taken out of the program and stands on its own.');
        }
        throw new RuntimeException('Unknown action.');
    }
    switch ($action) {
        case 'group':
        case 'group_other':
            $s = kop_rinbox_ph_suggestion($key);
            if (!$s) throw new RuntimeException('That suggestion is gone (it may be grouped already).');
            list($name, $names) = kop_rinbox_ph_names($s);
            $homes = array_filter($names, function ($n) { return $n !== ''; });
            if ($action === 'group_other') {
                // Any record can be the program: one found with the finder.
                $pid = (int) ($params['program_id'] ?? 0);
                if ($pid <= 0) throw new RuntimeException('Find the program record first.');
                if (isset($homes[$pid])) throw new RuntimeException('That record is one of the homes. Pick the program\'s own record, or clear its name to leave it out.');
            } else {
                $program = (string) ($params['program'] ?? 'new');
                $pid = ctype_digit($program) ? (int) $program : 0;
                if ($pid > 0 && (!$s['existing'] || (int) $s['existing']['id'] !== $pid)) throw new RuntimeException('Pick the existing record or a new one.');
            }
            $pid = kop_program_homes_group($homes, $pid, $name, kop_program_homes_opts());
            $all = kop_rinbox_ph_edits();
            if (isset($all[$key])) {
                unset($all[$key]);
                update_option(KOP_RINBOX_PH_EDITS, $all, false);
            }
            global $wpdb;
            $now = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $pid));
            return array('key' => 'program:' . $pid,
                'message' => 'Grouped ' . count($homes) . ' homes under ' . ($now !== '' ? $now : $name) . ' (record #' . $pid . '). Undo is on the Grouped tab.');
        case 'dismiss':
            kop_program_homes_set_dismissed($key, true);
            return array('message' => 'Marked not one program. It will not be suggested again.');
        case 'undismiss':
            kop_program_homes_set_dismissed($key, false);
            return array('message' => 'Back in the suggestions.');
    }
    throw new RuntimeException('Unknown action.');
}
