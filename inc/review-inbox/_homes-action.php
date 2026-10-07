<?php
/**
 * "Home of a program" on every review inbox card about one facility.
 *
 * kop_rinbox_finish_items() adds the action to any item whose 'facility' is
 * set (except the queues that group homes themselves: Program homes, and
 * Merge Duplicates' own "Homes of one program"). It ties that record to a
 * program record through Program Homes (inc/program-homes.php): an existing
 * record picked with the finder, or a new one by name. A record that is a
 * home already gets "Not a home of <program>" instead. Both log an exact Undo
 * (the record's program as it was; a program record the action made goes too
 * when nothing else uses it, kop_program_homes_undo()).
 *
 * The actions are answered here, before the queue's own 'act'
 * (kop_rinbox_homes_act()), so no source has to know about them.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_HOMES_ACTIONS = array('home_of_program', 'not_home_of_program', 'undo_home_of_program');

/** Queues that already group homes their own way. */
function kop_rinbox_homes_skip_sources() {
    // Conflicts: its cards settle one value; the queue the item came from offers the rest.
    return array('program-homes', 'facility-merge', 'conflicts');
}

/** The action for an item's facility, or null (no facility, a program record itself, Program Homes not here). */
function kop_rinbox_homes_action($source, array $item) {
    if (!function_exists('kop_program_homes_group') || in_array($source, kop_rinbox_homes_skip_sources(), true)) return null;
    $fid = (int) ($item['facility']['id'] ?? 0);
    if ($fid <= 0 || !function_exists('kop_program_homes_ready') || !kop_program_homes_ready()) return null;
    foreach ((array) ($item['actions'] ?? array()) as $a) {
        if (in_array($a['id'] ?? '', array('homes', 'home_of_program'), true)) return null;
    }
    if (kop_program_homes_homes_of($fid)) return null; // a program record, not a home
    $name = kop_rinbox_homes_name($fid);
    if ($in = kop_program_homes_program_of($fid)) {
        $pname = kop_rinbox_homes_name((int) $in[0]);
        return array('id' => 'not_home_of_program', 'label' => 'Not a home of ' . ($pname !== '' ? $pname : 'program #' . (int) $in[0]), 'style' => 'neutral',
            'confirm' => 'Take ' . $name . ' out of ' . ($pname !== '' ? $pname : 'its program') . '? It stays its own record.',
            'help' => $name . ' is listed as a home of ' . ($pname !== '' ? $pname : 'program #' . (int) $in[0]) . '; this takes it off the program\'s page. Undo is in Recently done.');
    }
    $split = kop_program_homes_split_name($name);
    return array('id' => 'home_of_program', 'label' => 'Home of a program', 'style' => 'neutral',
        'help' => 'Keeps ' . ($name !== '' ? $name : 'this record') . ' as its own record and lists it as one home or cottage of a program: pick the program\'s record, or name a new one. Undo is in Recently done.',
        'params' => array(
            array('name' => 'home_program', 'label' => 'Program record', 'type' => 'facility', 'value' => '', 'optional' => true),
            array('name' => 'home_program_name', 'label' => 'or new program named', 'type' => 'text', 'value' => (string) $split[0], 'optional' => true),
        ));
}

function kop_rinbox_homes_name($fid) {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', (int) $fid));
}

/** The home's name on the program page: the part after "Program – " when the names match, else its whole name. */
function kop_rinbox_homes_home_name($name, $program_name) {
    $split = kop_program_homes_split_name($name);
    if ($split[1] !== '' && kop_program_homes_key($split[0]) === kop_program_homes_key($program_name)) return $split[1];
    return trim((string) $name);
}

/**
 * Answer one of KOP_RINBOX_HOMES_ACTIONS for an item of $source; null when
 * $action is not one of them (the queue's own 'act' runs).
 */
function kop_rinbox_homes_act($source, $key, $action, array $params) {
    if (!in_array($action, KOP_RINBOX_HOMES_ACTIONS, true)) return null;
    if (!function_exists('kop_program_homes_group')) throw new RuntimeException('Program Homes is not installed here.');
    kop_program_homes_install();
    global $wpdb;
    if ($action === 'undo_home_of_program') {
        $fid = (int) ($params['facility'] ?? 0);
        $pid = (int) ($params['program'] ?? 0);
        if ($fid <= 0 || $pid <= 0) throw new RuntimeException('Nothing to undo.');
        $now = kop_program_homes_program_of($fid);
        $before = (int) ($params['before'] ?? 0);
        if ($before > 0 && kop_rinbox_homes_name($before) !== '') {
            kop_program_homes_group(array($fid => (string) ($params['before_name'] ?? '')), $before, '', kop_program_homes_opts());
        } elseif ($now && (int) $now[0] === $pid) {
            kop_program_homes_remove_home($fid);
        }
        // A program record this action made, with no homes left: kop_program_homes_undo() deletes it if untouched.
        $gone = '';
        if (!empty($params['new_group']) && !kop_program_homes_homes_of($pid)) {
            $gone = kop_program_homes_undo($pid) === 'removed' ? ' The program record it made is deleted.' : '';
        }
        return array('message' => 'Undone. ' . kop_rinbox_homes_name($fid) . ($before > 0 ? ' is back under its earlier program.' : ' is on its own again.') . $gone);
    }
    $item = kop_rinbox_get_item($source, $key);
    $fid = (int) ($item['facility']['id'] ?? 0);
    if ($fid <= 0) throw new RuntimeException('This card is not about one facility.');
    $name = kop_rinbox_homes_name($fid);
    if ($name === '') throw new RuntimeException('That facility record is gone.');
    $was = kop_program_homes_program_of($fid);
    if ($action === 'not_home_of_program') {
        if (!$was) throw new RuntimeException($name . ' is not a home of any program.');
        kop_program_homes_remove_home($fid);
        $pname = kop_rinbox_homes_name((int) $was[0]);
        return array('message' => $name . ' is no longer listed as a home of ' . $pname . '.',
            'undo' => array('action' => 'undo_home_of_program', 'params' => array('facility' => $fid, 'program' => (int) $was[0], 'before' => (int) $was[0], 'before_name' => (string) $was[1])));
    }
    $pid = (int) ($params['home_program'] ?? 0);
    $pname = trim(sanitize_text_field((string) ($params['home_program_name'] ?? '')));
    if ($pid === $fid) throw new RuntimeException('Pick the program\'s own record, not this one.');
    if ($pid > 0) {
        $pname = kop_rinbox_homes_name($pid);
        if ($pname === '') throw new RuntimeException('Program record #' . $pid . ' is not on file.');
        if (kop_program_homes_program_of($pid)) throw new RuntimeException($pname . ' is itself a home of a program. Pick that program instead.');
    } elseif ($pname === '') {
        throw new RuntimeException('Pick the program\'s record, or type a name for a new one.');
    }
    // A new name can still find an existing record (kop_facility_resolve_identity), so look at every group first.
    $groups = array_map('intval', (array) $wpdb->get_col('SELECT program_id FROM ' . kop_program_homes_table('groups')));
    $pid = (int) kop_program_homes_group(array($fid => kop_rinbox_homes_home_name($name, $pname)), $pid, $pname, kop_program_homes_opts());
    $pname = kop_rinbox_homes_name($pid);
    return array('message' => $name . ' is now listed as a home of ' . $pname . ' (record #' . $pid . '). Undo is in Recently done.',
        'undo' => array('action' => 'undo_home_of_program', 'params' => array('facility' => $fid, 'program' => $pid,
            'before' => $was ? (int) $was[0] : 0, 'before_name' => $was ? (string) $was[1] : '', 'new_group' => !in_array($pid, $groups, true))));
}
